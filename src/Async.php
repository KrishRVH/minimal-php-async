<?php

/**
 * @phan-file-suppress PhanTemplateTypeNotDeclaredInFunctionParams
 * @phan-file-suppress PhanPluginNumericalComparison
 * @phan-file-suppress PhanUnreferencedClosure
 * @phan-file-suppress PhanTypeMismatchDeclaredReturnNullable
 */

declare(strict_types=1);

namespace Krvh\MinimalPhpAsync;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Static facade for the fiber runtime.
 *
 * This provides a small "structured concurrency" API:
 * - {@see Async::spawn()} / {@see Async::run()} for starting work
 * - {@see Async::all()} / {@see Async::race()} for coordination
 * - {@see Async::timeout()} for deadline-style control
 *
 * It also contains a minimal HTTP helper to demonstrate asynchronous I/O.
 *
 * @psalm-type HeaderMap = array<string, string>
 * @phpstan-type HeaderMap = array<string, string>
 * @phan-type HeaderMap = array<string, string>
 * @psalm-type FetchOptions = array{
 *   method?: non-empty-string,
 *   headers?: HeaderMap,
 *   body?: string,
 *   verify?: bool,
 *   connect_timeout?: float|int,
 *   max_bytes?: positive-int
 * }
 * @phpstan-type FetchOptions = array{
 *   method?: non-empty-string,
 *   headers?: HeaderMap,
 *   body?: string,
 *   verify?: bool,
 *   connect_timeout?: float|int,
 *   max_bytes?: positive-int
 * }
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.TooManyMethods")
 * @psalm-suppress UnusedClass
 */
final class Async
{
    private static ?Runtime $instance = null;

    /**
     * Temporarily swap the global runtime instance for the duration of $fn.
     *
     * Useful for tests or isolating multiple runtimes in the same process.
     */
    public static function withRuntime(Runtime $runtime, Closure $fn): mixed
    {
        $prev = self::$instance;
        self::$instance = $runtime;

        try {
            return $fn();
        } finally {
            self::$instance = $prev;
        }
    }

    /**
     * Spawn a Task on the current runtime.
     *
     * @template T
     * @param Closure():T $fn
     * @return Task<T>
     */
    public static function spawn(Closure $fn): Task
    {
        return self::runtime()->queue($fn);
    }

    /**
     * Run a closure and await its result.
     *
     * @template T
     * @param Closure():T $fn
     * @return T
     */
    public static function run(Closure $fn): mixed
    {
        return self::spawn($fn)->await();
    }

    /**
     * Sleep inside a runtime-managed Fiber.
     *
     * Calling this from the root context throws (the runtime requires a Fiber to suspend).
     */
    public static function sleep(float $seconds): void
    {
        self::runtime()->delay($seconds);
    }

    /**
     * Await all tasks and return results preserving input keys.
     *
     * @template TKey of array-key
     * @param array<TKey, (Task<mixed>|Closure)> $tasks
     * @return array<TKey, mixed>
     */
    public static function all(array $tasks): array
    {
        $rt = self::runtime();
        $handles = self::normalizeTasks($tasks, $rt);

        $rt->drive(static fn(): bool => self::allDone($handles));

        /** @var array<TKey, mixed> $results */
        $results = [];
        foreach ($handles as $k => $task) {
            /** @psalm-suppress MixedAssignment */
            $results[$k] = $task->result();
        }

        return $results;
    }

    /**
     * Await the first task to complete, cancel the rest, and return its result.
     *
     * @param array<array-key, (Task<mixed>|Closure)> $tasks
     */
    public static function race(array $tasks): mixed
    {
        $rt = self::runtime();
        $handles = array_values(self::normalizeTasks($tasks, $rt));

        if ($handles === []) {
            throw new InvalidArgumentException('race() requires at least one task');
        }

        $rt->drive(static fn(): bool => self::anyDone($handles));

        $winner = self::firstDone($handles);
        if (!$winner instanceof Task) {
            throw new RuntimeException('race() did not produce a winner');
        }

        foreach ($handles as $t) {
            if ($t !== $winner) {
                $t->cancel();
            }
        }

        return $winner->await();
    }

    /**
     * Run $fn with a hard timeout in seconds.
     *
     * Implementation detail:
     * - This is implemented as a {@see Async::race()} between work and a timer task.
     */
    public static function timeout(Closure $fn, float $sec): mixed
    {
        return self::race([
            'work' => $fn,
            'timer' => static function () use ($sec): never {
                self::sleep($sec);
                throw new RuntimeException("Timeout {$sec}s");
            },
        ]);
    }

    // -------------------------------------------------------------------------
    // HTTP Helpers (minimal, for demonstration)
    // -------------------------------------------------------------------------

    /**
     * Fetch the response body for an HTTP/HTTPS URL.
     *
     * IMPORTANT LIMITATION (by design in this PoC):
     * - The TCP/TLS connect step uses stream_socket_client() in blocking mode.
     *   Once connected, read/write are scheduled via the runtime.
     *
     * @param array<string, mixed> $opts
     * @return string Response body (decoded if Transfer-Encoding: chunked)
     *
     * @throws InvalidArgumentException for invalid URLs/options
     * @throws HttpException for HTTP status >= 400
     * @throws RuntimeException for socket/protocol errors
     */
    public static function fetch(string $url, array $opts = []): string
    {
        $parts = self::parseUrlParts($url);
        $method = self::resolveMethod($opts);
        $body = self::resolveBody($opts);
        $headers = self::resolveHeaders($parts['host'], self::resolveHeaderOption($opts['headers'] ?? null), $body);

        $stream = self::openStream(
            $parts['scheme'],
            $parts['host'],
            $parts['port'],
            self::resolveConnectTimeout($opts),
            self::resolveVerify($opts),
        );

        $req = self::buildRequest($method, $parts['path'], $headers, $body);

        $rt = self::runtime();
        $rt->write($stream, $req);

        $raw = $rt->readAll($stream, self::resolveMaxBytes($opts));

        return self::parseResponse($raw, $url);
    }

    /**
     * Fetch JSON and decode into PHP values.
     *
     * @param array<string, mixed> $opts
     */
    public static function fetchJson(string $url, array $opts = []): mixed
    {
        $opts['headers'] = self::withJsonHeader(self::resolveHeaderOption($opts['headers'] ?? null));
        return json_decode(self::fetch($url, $opts), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function runtime(): Runtime
    {
        return self::$instance ??= new Runtime();
    }

    /**
     * @template TKey of array-key
     * @param array<TKey, (Task<mixed>|Closure)> $tasks
     * @return array<TKey, Task<mixed>>
     */
    private static function normalizeTasks(array $tasks, Runtime $rt): array
    {
        $handles = [];

        foreach ($tasks as $k => $t) {
            if ($t instanceof Task) {
                $handles[$k] = $t;
                continue;
            }

            // @phpstan-ignore-next-line
            if (!$t instanceof Closure) {
                throw new InvalidArgumentException('Invalid task');
            }

            $handles[$k] = $rt->queue(static fn(): mixed => $t());
        }

        return $handles;
    }

    /**
     * @param array<array-key, Task<mixed>> $tasks
     */
    private static function allDone(array $tasks): bool
    {
        return array_all($tasks, static fn(Task $task): bool => $task->isDone());
    }

    /**
     * @param array<array-key, Task<mixed>> $tasks
     */
    private static function anyDone(array $tasks): bool
    {
        return array_any($tasks, static fn(Task $task): bool => $task->isDone());
    }

    /**
     * @param array<array-key, Task<mixed>> $tasks
     * @return Task<mixed>|null
     */
    private static function firstDone(array $tasks): ?Task
    {
        foreach ($tasks as $task) {
            if ($task->isDone()) {
                return $task;
            }
        }

        return null;
    }

    /**
     * @return array{scheme: 'http'|'https', host: non-empty-string, port: int, path: non-empty-string}
     */
    private static function parseUrlParts(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false) {
            throw new InvalidArgumentException("Invalid URL: {$url}");
        }

        $host = self::requireNonEmptyString($parts['host'] ?? null, "Invalid URL (missing host): {$url}");
        $scheme = $parts['scheme'] ?? 'http';
        $scheme = self::normalizeScheme($scheme, $url);
        $port = self::normalizePort($parts['port'] ?? ($scheme === 'https' ? 443 : 80), $url);
        $path = self::normalizePath($parts['path'] ?? '/');
        $path = self::appendQuery($path, $parts['query'] ?? null);

        return [
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'path' => $path,
        ];
    }

    /**
     * @return non-empty-string
     */
    private static function requireNonEmptyString(string|null $value, string $message): string
    {
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }

    /**
     * @return 'http'|'https'
     */
    private static function normalizeScheme(string $scheme, string $url): string
    {
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidArgumentException("Unsupported scheme '{$scheme}' for URL: {$url}");
        }

        return $scheme;
    }

    private static function normalizePort(int $port, string $url): int
    {
        if ($port <= 0 || $port > 65535) {
            throw new InvalidArgumentException("Invalid port for URL: {$url}");
        }

        return $port;
    }

    /**
     * @return non-empty-string
     */
    private static function normalizePath(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        return $path;
    }

    /**
     * @param non-empty-string $path
     *
     * @return non-empty-string
     */
    private static function appendQuery(string $path, string|null $query): string
    {
        if (!is_string($query) || $query === '') {
            return $path;
        }

        return $path . '?' . $query;
    }

    /**
     * @param array<string, mixed> $opts
     */
    private static function resolveMethod(array $opts): string
    {
        $method = $opts['method'] ?? 'GET';
        if (!is_string($method) || $method === '') {
            throw new InvalidArgumentException('opts["method"] must be a non-empty string');
        }

        return $method;
    }

    /**
     * @param array<string, mixed> $opts
     */
    private static function resolveBody(array $opts): string
    {
        if (!array_key_exists('body', $opts) || $opts['body'] === null) {
            return '';
        }

        $body = $opts['body'];
        if (!is_string($body)) {
            throw new InvalidArgumentException('opts["body"] must be a string');
        }

        return $body;
    }

    /**
     * @return array<string, string>
     */
    private static function resolveHeaderOption(mixed $headers): array
    {
        if ($headers === null) {
            return [];
        }

        if (!is_array($headers)) {
            throw new InvalidArgumentException('opts["headers"] must be an array of string pairs');
        }

        $normalized = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || $name === '' || !is_string($value)) {
                throw new InvalidArgumentException('opts["headers"] must be an array of string pairs');
            }
            $normalized[$name] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $opts
     */
    private static function resolveConnectTimeout(array $opts): float
    {
        $timeout = $opts['connect_timeout'] ?? 30.0;
        if (!is_int($timeout) && !is_float($timeout)) {
            throw new InvalidArgumentException('opts["connect_timeout"] must be a number');
        }
        if ($timeout < 0) {
            throw new InvalidArgumentException('opts["connect_timeout"] must be >= 0');
        }

        return (float) $timeout;
    }

    /**
     * @param array<string, mixed> $opts
     */
    private static function resolveVerify(array $opts): bool
    {
        $verify = $opts['verify'] ?? true;
        if (!is_bool($verify)) {
            throw new InvalidArgumentException('opts["verify"] must be a boolean');
        }

        return $verify;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private static function resolveHeaders(string $host, array $headers, string $body): array
    {
        $headers = array_merge(
            ['Host' => $host, 'Connection' => 'close'],
            $headers,
        );

        // Add Content-Length if there is a body and caller didn't set it (case-insensitive).
        if ($body !== '') {
            $lower = array_change_key_case($headers, CASE_LOWER);
            if (!isset($lower['content-length'])) {
                $headers['Content-Length'] = (string) strlen($body);
            }
        }

        return $headers;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private static function withJsonHeader(array $headers): array
    {
        $headers['Accept'] = 'application/json';
        return $headers;
    }

    /**
     * @param array<string, string> $headers
     */
    private static function buildRequest(string $method, string $path, array $headers, string $body): string
    {
        $req = "{$method} {$path} HTTP/1.1\r\n";
        foreach ($headers as $k => $v) {
            $req .= "{$k}: {$v}\r\n";
        }
        return $req . "\r\n{$body}";
    }

    /**
     * @param array<string, mixed> $opts
     */
    private static function resolveMaxBytes(array $opts): int
    {
        $maxBytes = $opts['max_bytes'] ?? 8_000_000;
        if (!is_int($maxBytes)) {
            throw new InvalidArgumentException('opts["max_bytes"] must be an int');
        }
        if ($maxBytes <= 0) {
            throw new InvalidArgumentException('maxBytes must be > 0');
        }

        return $maxBytes;
    }

    /**
     * @psalm-return resource
     * @phpstan-return resource
     */
    private static function openStream(
        string $scheme,
        string $host,
        int $port,
        float $timeout,
        bool $verify,
    ): mixed {
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer' => $verify,
                'verify_peer_name' => $verify,
                'allow_self_signed' => !$verify,
            ],
        ]);

        $addr = ($scheme === 'https' ? 'ssl' : 'tcp') . "://{$host}:{$port}";

        // NOTE: connect is blocking in this PoC.
        $errno = 0;
        $errstr = '';
        set_error_handler(self::ignoreError(...));
        try {
            $stream = stream_socket_client($addr, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        } finally {
            restore_error_handler();
        }
        if (!is_resource($stream)) {
            $message = $errstr !== '' ? $errstr : 'Unknown error';
            throw new RuntimeException("Connect failed: {$message}", $errno);
        }

        return $stream;
    }

    /**
     * @psalm-suppress UnusedParam
     * @phan-suppress PhanUnusedPrivateFinalMethodParameter
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    private static function ignoreError(int $errno, string $errstr): bool
    {
        return true;
    }

    /**
     * Parse a raw HTTP/1.x response into a body string.
     *
     * - Throws on malformed response framing.
     * - Throws {@see HttpException} for status >= 400.
     * - Decodes chunked bodies when needed.
     */
    private static function parseResponse(string $raw, string $originalUrl): string
    {
        $pos = strpos($raw, "\r\n\r\n");
        if ($pos === false) {
            throw new RuntimeException('Malformed HTTP response (missing header/body separator)');
        }

        $head = substr($raw, 0, $pos);
        $body = substr($raw, $pos + 4);

        if (preg_match('/^HTTP\/1\.[01]\s+(\d{3})/i', $head, $m) === 1) {
            $status = (int) $m[1];
            if ($status >= 400) {
                throw new HttpException($status, $originalUrl);
            }
        }

        // Cheap, case-insensitive header check.
        if (stripos($head, "Transfer-Encoding: chunked") !== false) {
            return self::decodeChunked($body);
        }

        return $body;
    }

    /**
     * Decode an HTTP/1.1 "Transfer-Encoding: chunked" body.
     *
     * Supports chunk extensions (ignored) and validates CRLF framing.
     */
    private static function decodeChunked(string $buffer): string
    {
        $out = '';

        while (true) {
            [$line, $buffer] = self::readLine($buffer, 'Malformed chunked body (missing size line)');
            $len = self::parseChunkSize($line);

            if ($len === 0) {
                // Trailers may follow; validate framing but ignore contents.
                self::consumeTrailer($buffer);
                return $out;
            }

            [$chunk, $buffer] = self::readChunk($buffer, $len);
            $out .= $chunk;
        }
    }

    /**
     * @return array{0: string, 1: string} [line, rest]
     */
    private static function readLine(string $buffer, string $error): array
    {
        $lineEnd = strpos($buffer, "\r\n");
        if ($lineEnd === false) {
            throw new RuntimeException($error);
        }

        $line = substr($buffer, 0, $lineEnd);
        $rest = substr($buffer, $lineEnd + 2);

        return [$line, $rest];
    }

    private static function parseChunkSize(string $line): int
    {
        // Ignore chunk extensions: "<hex>;<ext>"
        $sizeHex = trim(explode(';', $line, 2)[0]);
        if ($sizeHex === '' || preg_match('/\A[0-9a-fA-F]+\z/', $sizeHex) !== 1) {
            throw new RuntimeException('Malformed chunked body (invalid chunk size)');
        }

        $len = hexdec($sizeHex);
        if (!is_int($len)) {
            throw new RuntimeException('Malformed chunked body (invalid chunk size)');
        }

        return $len;
    }

    /**
     * @return array{0: string, 1: string} [chunk, rest]
     */
    private static function readChunk(string $buffer, int $len): array
    {
        if (strlen($buffer) < $len + 2) {
            throw new RuntimeException('Malformed chunked body (incomplete chunk)');
        }

        $chunk = substr($buffer, 0, $len);
        $buffer = substr($buffer, $len);

        if (!str_starts_with($buffer, "\r\n")) {
            throw new RuntimeException('Malformed chunked body (missing CRLF after chunk)');
        }

        return [$chunk, substr($buffer, 2)];
    }

    private static function consumeTrailer(string $buffer): void
    {
        while (true) {
            [$line, $buffer] = self::readLine($buffer, 'Malformed chunked body (invalid trailer)');
            if ($line !== '') {
                continue;
            }

            if ($buffer !== '') {
                throw new RuntimeException('Malformed chunked body (invalid trailer)');
            }

            return;
        }
    }
}
