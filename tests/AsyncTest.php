<?php

/**
 * @phan-file-suppress PhanPluginNoAssert
 * @phan-file-suppress PhanPluginUnknownArrayClosureReturnType
 * @phan-file-suppress PhanTypeMismatchArgument
 * @phan-file-suppress PhanUnreferencedClass
 * @phan-file-suppress PhanUnreferencedClosure
 */

declare(strict_types=1);

namespace Krvh\MinimalPhpAsync\Tests;

use Fiber;
use InvalidArgumentException;
use JsonException;
use Krvh\MinimalPhpAsync\Async;
use Krvh\MinimalPhpAsync\HttpException;
use Krvh\MinimalPhpAsync\Runtime;
use Krvh\MinimalPhpAsync\Task;
use Krvh\MinimalPhpAsync\Tests\Support\AsyncTestCase;
use Krvh\MinimalPhpAsync\Tests\Support\SocketStub;
use Krvh\MinimalPhpAsync\Tests\Support\TestHelper;
use LogicException;
use RuntimeException;

/** @psalm-suppress UnusedClass */
final class AsyncTest extends AsyncTestCase
{
    public function testWithRuntimeSwapsAndRestores(): void
    {
        $rt1 = new Runtime();
        $rt2 = new Runtime();

        $task1 = Async::withRuntime($rt1, static fn(): Task => Async::spawn(static fn(): int => 1));
        \assert($task1 instanceof Task);
        $this->assertSame($rt1, TestHelper::getProperty($task1, 'runtime'));

        $task2 = Async::withRuntime($rt2, static fn(): Task => Async::spawn(static fn(): int => 2));
        \assert($task2 instanceof Task);
        $this->assertSame($rt2, TestHelper::getProperty($task2, 'runtime'));

        $task3 = Async::spawn(static fn(): int => 3);
        $this->assertNotSame($rt1, TestHelper::getProperty($task3, 'runtime'));
        $this->assertNotSame($rt2, TestHelper::getProperty($task3, 'runtime'));
    }

    public function testSpawnRunAndSleep(): void
    {
        $this->assertSame(5, TestHelper::withTimeout(1, static fn(): mixed => Async::run(static fn(): int => 5)));

        $task = Async::spawn(static fn(): int => 7);
        $this->assertSame(7, TestHelper::withTimeout(1, static fn(): mixed => $task->await()));

        $this->expectException(LogicException::class);
        Async::sleep(0.01);
    }

    public function testAllAndRace(): void
    {
        $result = TestHelper::withTimeout(1, static fn(): array => Async::run(static function (): array {
            /** @var Task<mixed> $task */
            $task = Async::spawn(static fn(): mixed => 'a');
            return Async::all([
                'first' => $task,
                'second' => static fn(): mixed => 'b',
            ]);
        }));

        $this->assertSame(['first' => 'a', 'second' => 'b'], $result);

        $winner = TestHelper::withTimeout(1, static fn(): string => Async::run(static function (): string {
            $result = Async::race([
                static function (): string {
                    Async::sleep(0.01);
                    return 'slow';
                },
                static function (): string {
                    Async::sleep(0.0);
                    return 'fast';
                },
            ]);
            \assert(is_string($result));
            return $result;
        }));

        $this->assertSame('fast', $winner);
    }

    public function testAllDrivesDelayedTasks(): void
    {
        $result = TestHelper::withTimeout(1, static fn(): array => Async::run(static fn(): array => Async::all([
            'slow' => static function (): string {
                Async::sleep(0.01);
                return 'slow';
            },
            'fast' => static function (): string {
                Async::sleep(0.0);
                return 'fast';
            },
        ])));

        $this->assertSame(['slow' => 'slow', 'fast' => 'fast'], $result);
    }

    public function testRaceCancelsLosers(): void
    {
        $state = new class {
            public bool $cancelled = false;
        };

        $slow = Async::spawn(static function () use ($state): string {
            try {
                Async::sleep(0.05);
                return 'slow';
            } catch (RuntimeException $e) {
                $state->cancelled = true;
                throw $e;
            }
        });

        $fast = Async::spawn(static fn(): string => 'fast');

        /** @var Task<mixed> $slowTask */
        $slowTask = $slow;
        /** @var Task<mixed> $fastTask */
        $fastTask = $fast;

        $winner = TestHelper::withTimeout(1, static fn(): mixed => Async::race([$slowTask, $fastTask]));
        \assert(is_string($winner));
        $this->assertSame('fast', $winner);

        try {
            TestHelper::withTimeout(1, static fn(): mixed => $slow->await());
            $this->fail('Expected slow task to be cancelled');
        } catch (RuntimeException) {
            $this->assertTrue($state->cancelled);
        }
    }

    public function testDoneHelpersReflectTaskState(): void
    {
        $runtime = new Runtime();
        $doneTask = $runtime->queue(static fn(): string => 'ok');
        TestHelper::withTimeout(1, static fn(): mixed => $doneTask->await());

        $pendingTask = $runtime->queue(static function (): mixed {
            Fiber::suspend();
            return null;
        });

        $this->assertTrue(TestHelper::callPrivateStatic(Async::class, 'allDone', [[$doneTask]]));
        $this->assertFalse(TestHelper::callPrivateStatic(Async::class, 'allDone', [[$doneTask, $pendingTask]]));

        $this->assertTrue(TestHelper::callPrivateStatic(Async::class, 'anyDone', [[$pendingTask, $doneTask]]));
        $this->assertFalse(TestHelper::callPrivateStatic(Async::class, 'anyDone', [[$pendingTask]]));

        $this->assertSame(
            $doneTask,
            TestHelper::callPrivateStatic(Async::class, 'firstDone', [[$pendingTask, $doneTask]]),
        );
        $this->assertNull(TestHelper::callPrivateStatic(Async::class, 'firstDone', [[$pendingTask]]));
    }

    public function testAllRejectsInvalidTask(): void
    {
        $this->expectException(InvalidArgumentException::class);
        /** @psalm-suppress InvalidArgument */
        /** @phpstan-ignore-next-line */
        Async::all(['bad' => 123]);
    }

    public function testRaceRejectsInvalidTasks(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Async::race([]);
    }

    public function testRaceRejectsNonTaskInputs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        /** @psalm-suppress InvalidArgument */
        /** @phpstan-ignore-next-line */
        Async::race(['bad']);
    }

    public function testTimeoutSuccessAndFailure(): void
    {
        $this->assertSame(
            'ok',
            TestHelper::withTimeout(1, static fn(): mixed => Async::timeout(static fn(): string => 'ok', 0.05)),
        );

        $this->expectException(RuntimeException::class);
        TestHelper::withTimeout(1, static fn(): mixed => Async::timeout(static function (): string {
            Async::sleep(0.05);
            return 'late';
        }, 0.001));
    }

    public function testTimeoutAllowsWorkBeforeDeadline(): void
    {
        $result = TestHelper::withTimeout(1, static fn(): mixed => Async::timeout(
            static function (): string {
                Async::sleep(0.01);
                return 'ok';
            },
            0.1,
        ));

        \assert(is_string($result));
        $this->assertSame('ok', $result);
    }

    public function testFetchAndFetchJson(): void
    {
        $response = "HTTP/1.1 200 OK\r\nContent-Length: 5\r\nConnection: close\r\n\r\nhello";
        SocketStub::queueResponse($response);
        $body = TestHelper::withTimeout(
            1,
            static fn(): string => Async::run(static fn(): string => Async::fetch('http://example.test/')),
        );

        $this->assertSame('hello', $body);
        $request = SocketStub::lastRequest();
        if ($request === null) {
            $this->fail('Expected request to be a string');
        }
        $this->assertTrue(str_starts_with($request, "GET / HTTP/1.1\r\n"));

        $jsonResponse = "HTTP/1.1 200 OK\r\nContent-Length: 11\r\nConnection: close\r\n\r\n{\"ok\":true}";
        SocketStub::queueResponse($jsonResponse);
        $payload = TestHelper::withTimeout(1, static fn(): array => Async::run(static function (): array {
            $result = Async::fetchJson('http://example.test/');
            \assert(is_array($result));
            return $result;
        }));

        $this->assertSame(['ok' => true], $payload);
    }

    public function testFetchJsonThrowsOnInvalidJson(): void
    {
        $response = "HTTP/1.1 200 OK\r\nContent-Length: 7\r\nConnection: close\r\n\r\nnotjson";

        $this->expectException(JsonException::class);
        SocketStub::queueResponse($response);
        TestHelper::withTimeout(1, static fn(): array => Async::run(static function (): array {
            $result = Async::fetchJson('http://example.test/');
            \assert(is_array($result));
            return $result;
        }));
    }

    public function testFetchJsonHonorsDefaultDepth(): void
    {
        $levels = 511;
        $payload = $this->buildNestedJson($levels);

        $response = "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($payload)
            . "\r\nConnection: close\r\n\r\n{$payload}";
        SocketStub::queueResponse($response);

        $result = TestHelper::withTimeout(1, static fn(): array => Async::run(static function (): array {
            $value = Async::fetchJson('http://example.test/');
            \assert(is_array($value));
            return $value;
        }));

        $this->assertNestedLeaf($result, $levels);
    }

    public function testFetchJsonRejectsTooDeepJson(): void
    {
        $levels = 512;
        $payload = $this->buildNestedJson($levels);

        try {
            $resultType = get_debug_type(json_decode($payload, true, 512, JSON_THROW_ON_ERROR));
            $this->fail('Expected JsonException for depth 512, got ' . $resultType);
        } catch (JsonException) {
        }

        $response = "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($payload)
            . "\r\nConnection: close\r\n\r\n{$payload}";
        SocketStub::queueResponse($response);

        $this->expectException(JsonException::class);
        TestHelper::withTimeout(1, static fn(): array => Async::run(static function (): array {
            $value = Async::fetchJson('http://example.test/');
            \assert(is_array($value));
            return $value;
        }));
    }

    public function testFetchRejectsInvalidOptions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        /** @psalm-suppress InvalidArgument */
        Async::fetch('http://example.com', ['method' => '']);
    }

    public function testParseUrlPartsAndHelpers(): void
    {
        $parts = TestHelper::callPrivateStatic(Async::class, 'parseUrlParts', ['https://example.com:8443/path?x=1']);

        $this->assertSame([
            'scheme' => 'https',
            'host' => 'example.com',
            'port' => 8443,
            'path' => '/path?x=1',
        ], $parts);
    }

    public function testParseUrlPartsUsesDefaultPorts(): void
    {
        /** @var array{port: int} $http */
        $http = TestHelper::callPrivateStatic(Async::class, 'parseUrlParts', ['http://example.com/path']);
        $this->assertSame(80, $http['port']);

        /** @var array{port: int} $https */
        $https = TestHelper::callPrivateStatic(Async::class, 'parseUrlParts', ['https://example.com/path']);
        $this->assertSame(443, $https['port']);
    }

    public function testNormalizePathAndAppendQueryDefaults(): void
    {
        $path = TestHelper::callPrivateStatic(Async::class, 'normalizePath', ['']);
        $this->assertSame('/', $path);

        $withQuery = TestHelper::callPrivateStatic(Async::class, 'appendQuery', ['/path', null]);
        $this->assertSame('/path', $withQuery);
    }

    public function testParseUrlPartsRejectsMissingHost(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid URL (missing host): /path');
        TestHelper::callPrivateStatic(Async::class, 'parseUrlParts', ['/path']);
    }

    public function testParseUrlPartsRejectsInvalidParse(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid URL: http://:');
        TestHelper::callPrivateStatic(Async::class, 'parseUrlParts', ['http://:']);
    }

    public function testParseUrlPartsRejectsUnsupportedScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TestHelper::callPrivateStatic(Async::class, 'parseUrlParts', ['ftp://example.com']);
    }

    public function testParseUrlPartsRejectsInvalidPort(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TestHelper::callPrivateStatic(Async::class, 'parseUrlParts', ['http://example.com:0']);
    }

    public function testParseUrlPartsAcceptsMaxPort(): void
    {
        /** @var array{port: int} $parts */
        $parts = TestHelper::callPrivateStatic(Async::class, 'parseUrlParts', ['http://example.com:65535']);
        $this->assertSame(65535, $parts['port']);
    }

    public function testResolveMethodAndMaxBytes(): void
    {
        $this->assertSame('POST', TestHelper::callPrivateStatic(Async::class, 'resolveMethod', [['method' => 'POST']]));
        $this->assertSame(8_000_000, TestHelper::callPrivateStatic(Async::class, 'resolveMaxBytes', [[]]));

        $this->expectException(InvalidArgumentException::class);
        TestHelper::callPrivateStatic(Async::class, 'resolveMethod', [['method' => '']]);
    }

    public function testResolveMaxBytesRejectsInvalidValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TestHelper::callPrivateStatic(Async::class, 'resolveMaxBytes', [['max_bytes' => 0]]);
    }

    public function testResolveBodyAndHeaderOptionsValidation(): void
    {
        $this->assertSame('', TestHelper::callPrivateStatic(Async::class, 'resolveBody', [[]]));
        $this->assertSame('', TestHelper::callPrivateStatic(Async::class, 'resolveBody', [['body' => null]]));
        $this->assertSame('data', TestHelper::callPrivateStatic(Async::class, 'resolveBody', [['body' => 'data']]));

        try {
            TestHelper::callPrivateStatic(Async::class, 'resolveBody', [['body' => 123]]);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('opts["body"] must be a string', $e->getMessage());
        }

        $this->assertSame([], TestHelper::callPrivateStatic(Async::class, 'resolveHeaderOption', [null]));
        $this->assertSame(
            ['X-Test' => '1'],
            TestHelper::callPrivateStatic(Async::class, 'resolveHeaderOption', [['X-Test' => '1']]),
        );
        $this->assertSame(
            ['X-Test' => '1', 'X-Other' => '2'],
            TestHelper::callPrivateStatic(Async::class, 'resolveHeaderOption', [['X-Test' => '1', 'X-Other' => '2']]),
        );

        try {
            TestHelper::callPrivateStatic(Async::class, 'resolveHeaderOption', ['bad']);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('opts["headers"] must be an array of string pairs', $e->getMessage());
        }

        try {
            TestHelper::callPrivateStatic(Async::class, 'resolveHeaderOption', [[123 => 'ok']]);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('opts["headers"] must be an array of string pairs', $e->getMessage());
        }

        try {
            TestHelper::callPrivateStatic(Async::class, 'resolveHeaderOption', [['X-Test' => 5]]);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('opts["headers"] must be an array of string pairs', $e->getMessage());
        }
    }

    public function testResolveConnectTimeoutAndVerifyValidation(): void
    {
        $this->assertSame(30.0, TestHelper::callPrivateStatic(Async::class, 'resolveConnectTimeout', [[]]));
        $this->assertSame(0.5, TestHelper::callPrivateStatic(Async::class, 'resolveConnectTimeout', [
            ['connect_timeout' => 0.5],
        ]));
        $this->assertSame(0.0, TestHelper::callPrivateStatic(Async::class, 'resolveConnectTimeout', [
            ['connect_timeout' => 0],
        ]));

        try {
            TestHelper::callPrivateStatic(Async::class, 'resolveConnectTimeout', [['connect_timeout' => -1]]);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('opts["connect_timeout"] must be >= 0', $e->getMessage());
        }

        try {
            TestHelper::callPrivateStatic(Async::class, 'resolveConnectTimeout', [['connect_timeout' => 'bad']]);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('opts["connect_timeout"] must be a number', $e->getMessage());
        }

        $this->assertTrue(TestHelper::callPrivateStatic(Async::class, 'resolveVerify', [[]]));
        $this->assertFalse(TestHelper::callPrivateStatic(Async::class, 'resolveVerify', [['verify' => false]]));

        try {
            TestHelper::callPrivateStatic(Async::class, 'resolveVerify', [['verify' => 'yes']]);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('opts["verify"] must be a boolean', $e->getMessage());
        }
    }

    public function testResolveHeadersAndBuildRequest(): void
    {
        /** @var array<string, string> $headers */
        $headers = TestHelper::callPrivateStatic(Async::class, 'resolveHeaders', [
            'example.com',
            ['X-Test' => '1'],
            'body',
        ]);

        $this->assertSame('example.com', $headers['Host']);
        $this->assertSame('close', $headers['Connection']);
        $this->assertSame((string) strlen('body'), $headers['Content-Length']);

        /** @var array<string, string> $headers2 */
        $headers2 = TestHelper::callPrivateStatic(Async::class, 'resolveHeaders', [
            'example.com',
            ['content-length' => '99'],
            'body',
        ]);
        $this->assertSame('99', $headers2['content-length']);
        $this->assertFalse(isset($headers2['Content-Length']));

        /** @var array<string, string> $headers3 */
        $headers3 = TestHelper::callPrivateStatic(Async::class, 'resolveHeaders', [
            'example.com',
            [],
            '',
        ]);
        $this->assertFalse(isset($headers3['Content-Length']));

        /** @var array<string, string> $headers4 */
        $headers4 = TestHelper::callPrivateStatic(Async::class, 'resolveHeaders', [
            'example.com',
            ['Content-Length' => '99'],
            'body',
        ]);
        $this->assertSame('99', $headers4['Content-Length']);

        $request = TestHelper::callPrivateStatic(Async::class, 'buildRequest', [
            'POST',
            '/hello',
            ['Host' => 'example.com'],
            'data',
        ]);
        $this->assertSame("POST /hello HTTP/1.1\r\nHost: example.com\r\n\r\ndata", $request);
    }

    public function testWithJsonHeaderAddsAccept(): void
    {
        /** @var array<string, string> $headers */
        $headers = TestHelper::callPrivateStatic(Async::class, 'withJsonHeader', [['X-Test' => '1']]);
        $this->assertSame('application/json', $headers['Accept']);
    }

    public function testOpenStreamSuccessAndFailure(): void
    {
        $response = "HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
        SocketStub::queueResponse($response);
        /** @psalm-suppress MixedAssignment */
        $stream = TestHelper::callPrivateStatic(Async::class, 'openStream', [
            'http',
            'example.test',
            80,
            1.0,
            true,
        ]);

        $this->assertIsResource($stream);
        fwrite($stream, "GET / HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n");
        fclose($stream);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connect failed: Connect failed');
        SocketStub::queueFailure();
        TestHelper::callPrivateStatic(Async::class, 'openStream', [
            'http',
            'example.test',
            80,
            0.1,
            true,
        ]);
    }

    public function testOpenStreamUsesSslAndVerifyOptions(): void
    {
        $response = "HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";

        SocketStub::queueResponse($response);
        /** @psalm-suppress MixedAssignment */
        $stream = TestHelper::callPrivateStatic(Async::class, 'openStream', [
            'https',
            'example.test',
            443,
            1.0,
            false,
        ]);

        $this->assertIsResource($stream);
        $this->assertSame('ssl://example.test:443', SocketStub::lastAddress());

        $options = SocketStub::lastContextOptions();
        $this->assertIsArray($options);
        $ssl = $options['ssl'] ?? null;
        $this->assertIsArray($ssl);
        $this->assertFalse($ssl['verify_peer']);
        $this->assertFalse($ssl['verify_peer_name']);
        $this->assertTrue($ssl['allow_self_signed']);

        fclose($stream);

        SocketStub::queueResponse($response);
        /** @psalm-suppress MixedAssignment */
        $stream = TestHelper::callPrivateStatic(Async::class, 'openStream', [
            'http',
            'example.test',
            80,
            1.0,
            true,
        ]);

        $this->assertIsResource($stream);
        $this->assertSame('tcp://example.test:80', SocketStub::lastAddress());
        fclose($stream);
    }

    public function testParseResponseReturnsBody(): void
    {
        $raw = "HTTP/1.1 200 OK\r\nX-Test: 1\r\n\r\nbody";
        $body = TestHelper::callPrivateStatic(Async::class, 'parseResponse', [$raw, 'http://example.com']);
        $this->assertSame('body', $body);
    }

    public function testParseResponseSkipsNonHttpStatusLine(): void
    {
        $raw = "STATUS 200 OK\r\nX-Test: 1\r\n\r\nbody";
        $body = TestHelper::callPrivateStatic(Async::class, 'parseResponse', [$raw, 'http://example.com']);
        $this->assertSame('body', $body);
    }

    public function testParseResponseIgnoresHttpMarkerInHeader(): void
    {
        $raw = "STATUS 200 OK\r\nX-Info: HTTP/1.1 404\r\n\r\nbody";
        $body = TestHelper::callPrivateStatic(Async::class, 'parseResponse', [$raw, 'http://example.com']);
        $this->assertSame('body', $body);
    }

    public function testParseResponseMatchesLowercaseHttpStatus(): void
    {
        $this->expectException(HttpException::class);
        TestHelper::callPrivateStatic(Async::class, 'parseResponse', [
            "http/1.1 404 Not Found\r\n\r\nnope",
            'http://example.com',
        ]);
    }

    public function testParseResponseThrowsOnStatus400(): void
    {
        $this->expectException(HttpException::class);
        TestHelper::callPrivateStatic(Async::class, 'parseResponse', [
            "HTTP/1.1 400 Bad Request\r\n\r\nnope",
            'http://example.com',
        ]);
    }

    public function testParseResponseHandlesChunked(): void
    {
        $raw = "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n3\r\nabc\r\n0\r\n\r\n";
        $body = TestHelper::callPrivateStatic(Async::class, 'parseResponse', [$raw, 'http://example.com']);
        $this->assertSame('abc', $body);
    }

    public function testParseResponseDoesNotTreatBodyAsHeader(): void
    {
        $raw = "HTTP/1.1 200 OK\r\nX-Test: 1\r\n\r\n"
            . "Transfer-Encoding: chunked\r\n\r\n3\r\nabc\r\n0\r\n\r\n";
        $body = TestHelper::callPrivateStatic(Async::class, 'parseResponse', [$raw, 'http://example.com']);
        $this->assertSame("Transfer-Encoding: chunked\r\n\r\n3\r\nabc\r\n0\r\n\r\n", $body);
    }

    public function testParseResponseRejectsMissingSeparator(): void
    {
        $this->expectException(RuntimeException::class);
        TestHelper::callPrivateStatic(Async::class, 'parseResponse', [
            "HTTP/1.1 200 OK\r\nX-Test: 1\r\n",
            'http://example.com',
        ]);
    }

    public function testParseResponseThrowsHttpException(): void
    {
        $this->expectException(HttpException::class);
        TestHelper::callPrivateStatic(Async::class, 'parseResponse', [
            "HTTP/1.1 404 Not Found\r\n\r\nnope",
            'http://example.com',
        ]);
    }

    public function testDecodeChunkedSupportsExtensions(): void
    {
        $body = TestHelper::callPrivateStatic(Async::class, 'decodeChunked', ["3;ext=1\r\nabc\r\n0\r\n\r\n"]);
        $this->assertSame('abc', $body);
    }

    public function testDecodeChunkedTrimsSizeWhitespace(): void
    {
        $body = TestHelper::callPrivateStatic(Async::class, 'decodeChunked', [" 1 \r\nA\r\n0\r\n\r\n"]);
        $this->assertSame('A', $body);
    }

    public function testDecodeChunkedCombinesMultipleChunks(): void
    {
        $body = TestHelper::callPrivateStatic(Async::class, 'decodeChunked', ["1\r\nA\r\n2\r\nBC\r\n0\r\n\r\n"]);
        $this->assertSame('ABC', $body);
    }

    public function testDecodeChunkedAcceptsEmptyTrailer(): void
    {
        $body = TestHelper::callPrivateStatic(Async::class, 'decodeChunked', ["0\r\n\r\n"]);
        $this->assertSame('', $body);
    }

    public function testDecodeChunkedAcceptsTrailerHeaders(): void
    {
        $body = TestHelper::callPrivateStatic(Async::class, 'decodeChunked', [
            "1\r\nA\r\n0\r\nX-Test: 1\r\n\r\n",
        ]);
        $this->assertSame('A', $body);
    }

    public function testDecodeChunkedRejectsInvalidTrailer(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Malformed chunked body (invalid trailer)');
        TestHelper::callPrivateStatic(Async::class, 'decodeChunked', ["0\r\nX-Test: 1\r\n"]);
    }

    public function testDecodeChunkedRejectsTrailingDataAfterTrailer(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Malformed chunked body (invalid trailer)');
        TestHelper::callPrivateStatic(Async::class, 'decodeChunked', ["0\r\n\r\nextra"]);
    }

    public function testDecodeChunkedRejectsMissingSizeLine(): void
    {
        $this->expectException(RuntimeException::class);
        TestHelper::callPrivateStatic(Async::class, 'decodeChunked', ['abc']);
    }

    public function testDecodeChunkedRejectsInvalidSize(): void
    {
        $this->expectException(RuntimeException::class);
        TestHelper::callPrivateStatic(Async::class, 'decodeChunked', ["zz\r\n"]);
    }

    public function testDecodeChunkedRejectsIncompleteChunk(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Malformed chunked body (incomplete chunk)');
        TestHelper::callPrivateStatic(Async::class, 'decodeChunked', ["5\r\nabc"]);
    }

    public function testDecodeChunkedRejectsMissingTrailingLf(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Malformed chunked body (incomplete chunk)');
        TestHelper::callPrivateStatic(Async::class, 'decodeChunked', ["1\r\nA\r"]);
    }

    public function testDecodeChunkedRejectsMissingCrlfAfterChunk(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Malformed chunked body (missing CRLF after chunk)');
        TestHelper::callPrivateStatic(Async::class, 'decodeChunked', ["1\r\nAxy"]);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function assertNestedLeaf(array $data, int $levels): void
    {
        $cursor = $data;
        for ($i = 1; $i < $levels; $i++) {
            $this->assertArrayHasKey('a', $cursor);
            $next = $cursor['a'];
            $this->assertIsArray($next);
            $cursor = $next;
        }

        $this->assertArrayHasKey('a', $cursor);
        $this->assertSame('leaf', $cursor['a']);
    }

    /**
     * @phan-suppress PhanPluginPossiblyStaticPrivateMethod
     */
    private function buildNestedJson(int $levels): string
    {
        if ($levels < 1) {
            throw new InvalidArgumentException('levels must be >= 1');
        }
        if ($levels > 2_147_483_645) {
            throw new InvalidArgumentException('levels must be <= 2147483645');
        }

        $data = ['a' => 'leaf'];
        for ($i = 1; $i < $levels; $i++) {
            $data = ['a' => $data];
        }

        return json_encode($data, JSON_THROW_ON_ERROR, $levels + 2);
    }
}
