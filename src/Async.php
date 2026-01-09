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
use Throwable;
use TypeError;

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
     *
     * @template T
     * @param Closure():T $fn
     * @return T
     */
    public static function withRuntime(Runtime $runtime, Closure $fn): mixed
    {
        $prev = self::$instance;
        self::$instance = $runtime;

        try {
            $result = $fn();
        } catch (Throwable $e) {
            self::$instance = $prev;
            throw $e;
        }

        self::$instance = $prev;
        return $result;
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
     * @template T
     * @param array<TKey, (Task<T>|Closure():T)> $tasks
     * @return array<TKey, T>
     */
    public static function all(array $tasks): array
    {
        $rt = self::runtime();
        $handles = self::normalizeTasks($tasks, $rt);

        $rt->drive(static fn(): bool => self::allDone($handles));

        $results = array_map(
            static fn(Task $task): mixed => $task->result(),
            $handles,
        );

        /** @var array<TKey, T> $results */
        return $results;
    }

    /**
     * Await the first task to complete, cancel the rest, and return its result.
     *
     * @template T
     * @param array<array-key, (Task<T>|Closure():T)> $tasks
     * @return T
     */
    public static function race(array $tasks): mixed
    {
        $rt = self::runtime();
        // @infection-ignore-all
        $handles = array_values(self::normalizeTasks($tasks, $rt));

        if ($handles === []) {
            throw new InvalidArgumentException('race() requires at least one task');
        }
        $rt->drive(static fn(): bool => self::anyDone($handles));

        $winner = self::firstDone($handles);

        self::cancelLosers($handles, $winner);

        return $winner->await();
    }

    /**
     * Run $fn with a hard timeout in seconds.
     *
     * Implementation detail:
     * - This is implemented as a {@see Async::race()} between work and a timer task.
     *
     * @template T
     * @param Closure():T $fn
     * @return T
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
     * @template T
     * @param array<TKey, (Task<T>|Closure():T)> $tasks
     * @phpstan-param array<TKey, (Task<T>|Closure():T|int|string)> $tasks
     * @return array<TKey, Task<T>>
     */
    private static function normalizeTasks(array $tasks, Runtime $rt): array
    {
        return array_map(
            static function (mixed $task) use ($rt): Task {
                if ($task instanceof Task) {
                    return $task;
                }
                if ($task instanceof Closure) {
                    return $rt->queue($task);
                }

                throw new InvalidArgumentException('Invalid task');
            },
            $tasks,
        );
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
     * @template T
     * @param array<array-key, Task<T>> $tasks
     * @return Task<T>
     */
    private static function firstDone(array $tasks): Task
    {
        /** @var Task<T>|null $winner */
        $winner = array_find($tasks, static fn(Task $task): bool => $task->isDone());
        if ($winner instanceof Task) {
            return $winner;
        }

        throw new RuntimeException('race() failed to resolve a winner');
    }

    /**
     * @param array<array-key, Task<mixed>> $tasks
     * @param Task<mixed> $winner
     */
    private static function cancelLosers(array $tasks, Task $winner): void
    {
        array_walk(
            $tasks,
            static function (Task $task) use ($winner): void {
                if ($task !== $winner) {
                    $task->cancel();
                }
            },
        );
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
        if (!is_string($value)) {
            throw new InvalidArgumentException($message);
        }
        if ($value === '') {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }

    /**
     * @return 'http'|'https'
     */
    private static function normalizeScheme(string $scheme, string $url): string
    {
        if ($scheme === 'http') {
            return $scheme;
        }
        if ($scheme === 'https') {
            return $scheme;
        }

        throw new InvalidArgumentException("Unsupported scheme '{$scheme}' for URL: {$url}");
    }

    private static function normalizePort(int $port, string $url): int
    {
        if ($port <= 0) {
            throw new InvalidArgumentException("Invalid port for URL: {$url}");
        }
        if ($port > 65535) {
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
        if ($query === null) {
            return $path;
        }
        if ($query === '') {
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
        if (!is_string($method)) {
            throw new InvalidArgumentException('opts["method"] must be a non-empty string');
        }
        if ($method === '') {
            throw new InvalidArgumentException('opts["method"] must be a non-empty string');
        }

        return $method;
    }

    /**
     * @param array<string, mixed> $opts
     */
    private static function resolveBody(array $opts): string
    {
        if (!array_key_exists('body', $opts)) {
            return '';
        }
        if ($opts['body'] === null) {
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

        array_walk(
            $headers,
            static function (mixed $value, mixed $name): void {
                if (!is_string($name)) {
                    throw new InvalidArgumentException('opts["headers"] must be an array of string pairs');
                }
                if ($name === '') {
                    throw new InvalidArgumentException('opts["headers"] must be an array of string pairs');
                }
                if (!is_string($value)) {
                    throw new InvalidArgumentException('opts["headers"] must be an array of string pairs');
                }
            },
        );

        /** @var array<string, string> $headers */
        return $headers;
    }

    /**
     * @param array<string, mixed> $opts
     */
    private static function resolveConnectTimeout(array $opts): float
    {
        /** @psalm-suppress MixedAssignment */
        $timeout = $opts['connect_timeout'] ?? null;
        if ($timeout === null) {
            return 30.0;
        }
        if (is_int($timeout)) {
            if ($timeout < 0) {
                throw new InvalidArgumentException('opts["connect_timeout"] must be >= 0');
            }

            return $timeout;
        }

        if (is_float($timeout)) {
            if ($timeout < 0) {
                throw new InvalidArgumentException('opts["connect_timeout"] must be >= 0');
            }

            return $timeout;
        }

        throw new InvalidArgumentException('opts["connect_timeout"] must be a number');
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
     * @param array<array-key, mixed> $headers
     */
    private static function buildRequest(string $method, string $path, array $headers, string $body): string
    {
        $req = "{$method} {$path} HTTP/1.1\r\n";
        $keys = array_keys($headers);
        $values = array_values($headers);
        $count = count($headers);
        $headerBlock = '';
        for ($i = 0; $i < $count; $i++) {
            $value = $values[$i];
            if (!is_scalar($value)) {
                throw new TypeError('Header values must be scalar');
            }
            if (is_bool($value)) {
                $value = (int) $value;
            }
            $value = sprintf('%s', $value);
            $headerBlock .= $keys[$i] . ': ' . $value . "\r\n";
        }

        return $req . $headerBlock . "\r\n{$body}";
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
        // @infection-ignore-all
        try {
            $stream = stream_socket_client($addr, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        } catch (Throwable $e) {
            restore_error_handler();
            throw $e;
        }
        restore_error_handler();
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
        ['head' => $head, 'body' => $body] = self::splitResponse($raw);

        self::throwIfHttpError($head, $originalUrl);

        return self::isChunked($head) ? self::decodeChunked($body) : $body;
    }

    /**
     * @return array{head: string, body: string}
     */
    private static function splitResponse(string $raw): array
    {
        $parts = explode("\r\n\r\n", $raw, 2);
        if (!isset($parts[1])) {
            throw new RuntimeException('Malformed HTTP response (missing header/body separator)');
        }

        return [
            'head' => $parts[0],
            'body' => $parts[1],
        ];
    }

    private static function throwIfHttpError(string $head, string $originalUrl): void
    {
        if (preg_match('/^HTTP\/1\.[01]\s+(\d{3})/i', $head, $m) !== 1) {
            return;
        }

        $status = (int) $m[1];
        if ($status < 400) {
            return;
        }

        throw new HttpException($status, $originalUrl);
    }

    private static function isChunked(string $head): bool
    {
        return stripos($head, 'Transfer-Encoding: chunked') !== false;
    }

    /**
     * Decode an HTTP/1.1 "Transfer-Encoding: chunked" body.
     *
     * Supports chunk extensions (ignored) and validates CRLF framing.
     */
    private static function decodeChunked(string $buffer): string
    {
        $out = '';
        [$line, $buffer] = self::readLine($buffer, 'Malformed chunked body (missing size line)');
        $len = self::parseChunkSize($line);

        while ($len > 0) {
            [$chunk, $buffer] = self::readChunk($buffer, $len);
            $out .= $chunk;

            [$line, $buffer] = self::readLine($buffer, 'Malformed chunked body (missing size line)');
            $len = self::parseChunkSize($line);
        }

        // Trailers may follow; validate framing but ignore contents.
        self::consumeTrailer($buffer);
        return $out;
    }

    /**
     * @return array{0: string, 1: string} [line, rest]
     */
    private static function readLine(string $buffer, string $error): array
    {
        $parts = explode("\r\n", $buffer, 2);
        if (!isset($parts[1])) {
            throw new RuntimeException($error);
        }

        return [$parts[0], $parts[1]];
    }

    private static function parseChunkSize(string $line): int
    {
        if (preg_match('/\A\s*([0-9a-fA-F]+)\s*(?:;.*)?\z/', $line, $matches) !== 1) {
            throw new RuntimeException('Malformed chunked body (invalid chunk size)');
        }

        $len = hexdec($matches[1]);
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
        $expected = $len + 2;
        if (strlen($buffer) < $expected) {
            throw new RuntimeException('Malformed chunked body (incomplete chunk)');
        }
        [$chunk, $rest] = self::readLine($buffer, 'Malformed chunked body (missing CRLF after chunk)');
        if (strlen($chunk) !== $len) {
            throw new RuntimeException('Malformed chunked body (missing CRLF after chunk)');
        }

        return [$chunk, $rest];
    }

    private static function consumeTrailer(string $buffer): void
    {
        do {
            [$line, $buffer] = self::readLine($buffer, 'Malformed chunked body (invalid trailer)');
        } while ($line !== '');

        if ($buffer !== '') {
            throw new RuntimeException('Malformed chunked body (invalid trailer)');
        }
    }
}
