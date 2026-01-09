<?php

/**
 * @phan-file-suppress PhanPluginNoAssert
 * @phan-file-suppress PhanPluginUnknownArrayClosureReturnType
 * @phan-file-suppress PhanUnreferencedClass
 * @phan-file-suppress PhanUnreferencedClosure
 */

declare(strict_types=1);

namespace Krvh\MinimalPhpAsync\Tests;

use Closure;
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
use stdClass;
use TypeError;

/** @psalm-suppress UnusedClass */
final class AsyncTest extends AsyncTestCase
{
    public function testWithRuntimeSwapsAndRestores(): void
    {
        $rt1 = new Runtime();
        $rt2 = new Runtime();

        $task1 = Async::withRuntime($rt1, static fn(): Task => Async::spawn(static fn(): int => 1));
        $this->assertSame($rt1, TestHelper::getProperty($task1, 'runtime'));

        $task2 = Async::withRuntime($rt2, static fn(): Task => Async::spawn(static fn(): int => 2));
        $this->assertSame($rt2, TestHelper::getProperty($task2, 'runtime'));

        $task3 = Async::spawn(static fn(): int => 3);
        $this->assertNotSame($rt1, TestHelper::getProperty($task3, 'runtime'));
        $this->assertNotSame($rt2, TestHelper::getProperty($task3, 'runtime'));
    }

    public function testWithRuntimeRestoresOnException(): void
    {
        $runtime = new Runtime();
        $state = new class {
            public bool $shouldThrow = true;
        };

        try {
            Async::withRuntime($runtime, static function () use ($state): void {
                if ($state->shouldThrow) {
                    throw new RuntimeException('boom');
                }
            });
            $this->fail('Expected exception');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $state->shouldThrow = false;

        $task = Async::spawn(static fn(): int => 1);
        $this->assertNotSame($runtime, TestHelper::getProperty($task, 'runtime'));
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

        $winner = TestHelper::withTimeout(1, static fn(): string => Async::run(static fn(): string => Async::race([
            static function (): string {
                Async::sleep(0.01);
                return 'slow';
            },
            static function (): string {
                Async::sleep(0.0);
                return 'fast';
            },
        ])));

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

    public function testAllHandlesEmptyAndCompletedTasks(): void
    {
        /** @var array<array-key, Task<mixed>|Closure> $empty */
        $empty = [];
        $closure = static function (): void {
        };
        $this->assertInstanceOf(Closure::class, $closure);
        /** @psalm-suppress MixedArgumentTypeCoercion */
        $this->assertSame([], Async::all($empty));

        $runtime = new Runtime();
        $done = $runtime->queue(static fn(): string => 'done');
        TestHelper::withTimeout(1, static fn(): mixed => $done->await());

        $result = Async::withRuntime($runtime, static fn(): array => Async::all(['done' => $done]));
        $this->assertSame(['done' => 'done'], $result);

        /** @psalm-suppress MixedArgumentTypeCoercion */
        $viaFiber = TestHelper::withTimeout(
            1,
            static fn(): array => Async::run(static fn(): array => Async::all($empty)),
        );
        $this->assertSame([], $viaFiber);
    }

    public function testAllHandlesSingleTask(): void
    {
        $result = TestHelper::withTimeout(1, static fn(): array => Async::run(static fn(): array => Async::all([
            'only' => static fn(): string => 'one',
        ])));

        $this->assertSame(['only' => 'one'], $result);
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
        $this->assertSame('fast', $winner);

        try {
            TestHelper::withTimeout(1, static fn(): mixed => $slow->await());
            $this->fail('Expected slow task to be cancelled');
        } catch (RuntimeException) {
            $this->assertTrue($state->cancelled);
        }
    }

    public function testRaceWithSingleTask(): void
    {
        $payload = 'only';
        $winner = TestHelper::withTimeout(
            1,
            static fn(): string => Async::run(static fn(): string => Async::race([static fn(): string => $payload])),
        );

        /** @psalm-suppress RedundantConditionGivenDocblockType */
        $this->assertSame($payload, $winner);
    }

    public function testRaceWithSingleTaskFromRoot(): void
    {
        $winner = TestHelper::withTimeout(1, static fn(): string => Async::race([
            static fn(): string => 'only',
        ]));

        /** @psalm-suppress RedundantConditionGivenDocblockType */
        $this->assertSame('only', $winner);
    }

    public function testRaceHandlesCompletedTasks(): void
    {
        $runtime = new Runtime();
        $first = $runtime->queue(static fn(): string => 'first');
        $second = $runtime->queue(static fn(): string => 'second');

        TestHelper::withTimeout(1, static fn(): mixed => $first->await());
        TestHelper::withTimeout(1, static fn(): mixed => $second->await());

        $winner = Async::withRuntime($runtime, static fn(): string => Async::race([$first, $second]));
        $this->assertSame('first', $winner);
    }

    public function testRaceHandlesMultipleCompletedTasks(): void
    {
        $runtime = new Runtime();
        $first = $runtime->queue(static fn(): string => 'first');
        $second = $runtime->queue(static fn(): string => 'second');
        $third = $runtime->queue(static fn(): string => 'third');

        TestHelper::withTimeout(1, static fn(): mixed => $first->await());
        TestHelper::withTimeout(1, static fn(): mixed => $second->await());
        TestHelper::withTimeout(1, static fn(): mixed => $third->await());

        $winner = Async::withRuntime($runtime, static fn(): string => Async::race([$first, $second, $third]));
        $this->assertSame('first', $winner);
    }

    public function testRaceReturnsFirstWinner(): void
    {
        $winner = TestHelper::withTimeout(1, static fn(): string => Async::run(static fn(): string => Async::race([
            static fn(): string => 'fast',
            static function (): string {
                Async::sleep(0.01);
                return 'slow';
            },
        ])));

        $this->assertSame('fast', $winner);
    }

    public function testRaceReturnsWinnerFromRoot(): void
    {
        $winner = TestHelper::withTimeout(1, static fn(): string => Async::race([
            static fn(): string => 'fast',
            static function (): string {
                Async::sleep(0.01);
                return 'slow';
            },
        ]));

        $this->assertSame('fast', $winner);
    }

    public function testRaceCancelsMultipleLosers(): void
    {
        $winner = TestHelper::withTimeout(1, static fn(): string => Async::race([
            static function (): string {
                Async::sleep(0.01);
                return 'slow-a';
            },
            static function (): string {
                Async::sleep(0.01);
                return 'slow-b';
            },
            static fn(): string => 'fast',
        ]));

        $this->assertSame('fast', $winner);
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
        try {
            TestHelper::callPrivateStatic(Async::class, 'firstDone', [[$pendingTask]]);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('race() failed to resolve a winner', $e->getMessage());
        }
    }

    public function testNormalizeTasksHandlesEmptyInput(): void
    {
        $runtime = new Runtime();

        /** @var array<array-key, Task<mixed>> $normalized */
        $normalized = TestHelper::callPrivateStatic(Async::class, 'normalizeTasks', [[], $runtime]);
        $this->assertSame([], $normalized);
    }

    public function testNormalizeTasksThrowsAfterValidEntry(): void
    {
        $runtime = new Runtime();
        $task = new Task($runtime);

        $this->expectException(InvalidArgumentException::class);
        /** @psalm-suppress InvalidArgument */
        TestHelper::callPrivateStatic(Async::class, 'normalizeTasks', [[
            'task' => $task,
            'bad' => 123,
        ], $runtime]);
    }

    public function testNormalizeTasksHandlesTaskAndClosure(): void
    {
        $runtime = new Runtime();
        $task = $runtime->queue(static fn(): string => 'task');
        $closure = static fn(): string => 'closure';

        /** @var array<string, Task<mixed>> $normalized */
        $normalized = TestHelper::callPrivateStatic(Async::class, 'normalizeTasks', [[
            'task' => $task,
            'closure' => $closure,
        ], $runtime]);

        $this->assertSame($task, $normalized['task']);
        $this->assertInstanceOf(Task::class, $normalized['closure']);
    }

    public function testNormalizeTasksThrowsAfterClosureThenTaskThenInvalid(): void
    {
        $runtime = new Runtime();
        $task = $runtime->queue(static fn(): string => 'task');
        $closure = static fn(): string => 'closure';

        $this->expectException(InvalidArgumentException::class);
        /** @psalm-suppress InvalidArgument */
        TestHelper::callPrivateStatic(Async::class, 'normalizeTasks', [[
            'closure' => $closure,
            'task' => $task,
            'bad' => 123,
        ], $runtime]);
    }

    public function testNormalizeTasksThrowsAfterTaskThenClosureThenInvalid(): void
    {
        $runtime = new Runtime();
        $task = $runtime->queue(static fn(): string => 'task');
        $closure = static fn(): string => 'closure';

        $this->expectException(InvalidArgumentException::class);
        /** @psalm-suppress InvalidArgument */
        TestHelper::callPrivateStatic(Async::class, 'normalizeTasks', [[
            'task' => $task,
            'closure' => $closure,
            'bad' => 123,
        ], $runtime]);
    }

    public function testFirstDoneReturnsImmediateMatch(): void
    {
        $runtime = new Runtime();
        $doneTask = $runtime->queue(static fn(): string => 'ok');
        TestHelper::withTimeout(1, static fn(): mixed => $doneTask->await());

        $pendingTask = $runtime->queue(static function (): mixed {
            Fiber::suspend();
            return null;
        });

        $this->assertSame(
            $doneTask,
            TestHelper::callPrivateStatic(Async::class, 'firstDone', [[$doneTask, $pendingTask]]),
        );
    }

    public function testFirstDoneReturnsAfterMultiplePendingTasks(): void
    {
        $runtime = new Runtime();
        $pendingA = $runtime->queue(static function (): mixed {
            Fiber::suspend();
            return null;
        });
        $pendingB = $runtime->queue(static function (): mixed {
            Fiber::suspend();
            return null;
        });
        $doneTask = $runtime->queue(static fn(): string => 'ok');
        TestHelper::withTimeout(1, static fn(): mixed => $doneTask->await());

        $winner = TestHelper::callPrivateStatic(Async::class, 'firstDone', [[$pendingA, $pendingB, $doneTask]]);
        $this->assertSame($doneTask, $winner);
    }

    public function testFirstDoneThrowsWhenNoWinner(): void
    {
        $runtime = new Runtime();
        $pending = new Task($runtime);

        try {
            TestHelper::callPrivateStatic(Async::class, 'firstDone', [[$pending]]);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('race() failed to resolve a winner', $e->getMessage());
        }

        try {
            TestHelper::callPrivateStatic(Async::class, 'firstDone', [[]]);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('race() failed to resolve a winner', $e->getMessage());
        }
    }

    public function testFirstDoneThrowsWithMultiplePendingTasks(): void
    {
        $runtime = new Runtime();
        $pendingA = new Task($runtime);
        $pendingB = new Task($runtime);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('race() failed to resolve a winner');
        TestHelper::callPrivateStatic(Async::class, 'firstDone', [[$pendingA, $pendingB]]);
    }

    public function testAllRejectsInvalidTask(): void
    {
        $this->expectException(InvalidArgumentException::class);
        /** @psalm-suppress InvalidArgument */
        /** @phpstan-ignore-next-line */
        Async::all(['bad' => 123]);
    }

    public function testAllPropagatesFailure(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        TestHelper::withTimeout(1, static fn(): mixed => Async::run(static fn(): array => Async::all([
            static function (): string {
                throw new RuntimeException('boom');
            },
            static fn(): string => 'ok',
        ])));
    }

    public function testRaceRejectsInvalidTasks(): void
    {
        $this->expectException(InvalidArgumentException::class);
        /** @var array<array-key, Task<mixed>|Closure> $empty */
        $empty = [];
        /** @psalm-suppress MixedArgumentTypeCoercion */
        Async::race($empty);
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
        $payload = 'ok';
        $result = TestHelper::withTimeout(1, static fn(): string => Async::timeout(
            static function () use ($payload): string {
                Async::sleep(0.01);
                return $payload;
            },
            0.1,
        ));

        /** @psalm-suppress RedundantConditionGivenDocblockType */
        $this->assertSame($payload, $result);
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

        $withNullQuery = TestHelper::callPrivateStatic(Async::class, 'appendQuery', ['/path', null]);
        $this->assertSame('/path', $withNullQuery);

        $withEmptyQuery = TestHelper::callPrivateStatic(Async::class, 'appendQuery', ['/path', '']);
        $this->assertSame('/path', $withEmptyQuery);

        $withQuery = TestHelper::callPrivateStatic(Async::class, 'appendQuery', ['/path', 'a=1']);
        $this->assertSame('/path?a=1', $withQuery);
    }

    public function testParseUrlPartsRejectsMissingHost(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid URL (missing host): /path');
        TestHelper::callPrivateStatic(Async::class, 'parseUrlParts', ['/path']);
    }

    public function testRequireNonEmptyStringRejectsEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('empty');
        TestHelper::callPrivateStatic(Async::class, 'requireNonEmptyString', ['', 'empty']);
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

    public function testNormalizeSchemeAcceptsHttpAndHttps(): void
    {
        $this->assertSame('http', TestHelper::callPrivateStatic(Async::class, 'normalizeScheme', ['http', 'url']));
        $this->assertSame('https', TestHelper::callPrivateStatic(Async::class, 'normalizeScheme', ['https', 'url']));
    }

    public function testParseUrlPartsRejectsInvalidPort(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TestHelper::callPrivateStatic(Async::class, 'parseUrlParts', ['http://example.com:0']);
    }

    public function testParseUrlPartsRejectsTooLargePort(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TestHelper::callPrivateStatic(Async::class, 'parseUrlParts', ['http://example.com:70000']);
    }

    public function testNormalizePortRejectsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TestHelper::callPrivateStatic(Async::class, 'normalizePort', [-1, 'http://example.com']);
    }

    public function testNormalizePortRejectsZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TestHelper::callPrivateStatic(Async::class, 'normalizePort', [0, 'http://example.com']);
    }

    public function testNormalizePortRejectsTooLarge(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TestHelper::callPrivateStatic(Async::class, 'normalizePort', [70000, 'http://example.com']);
    }

    public function testNormalizePortAcceptsPositive(): void
    {
        $this->assertSame(8080, TestHelper::callPrivateStatic(Async::class, 'normalizePort', [8080, 'http://example']));
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
        $this->assertSame('GET', TestHelper::callPrivateStatic(Async::class, 'resolveMethod', [[]]));
        $this->assertSame(8_000_000, TestHelper::callPrivateStatic(Async::class, 'resolveMaxBytes', [[]]));

        $this->expectException(InvalidArgumentException::class);
        TestHelper::callPrivateStatic(Async::class, 'resolveMethod', [['method' => '']]);
    }

    public function testResolveMethodRejectsNonString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        /** @psalm-suppress InvalidArgument */
        TestHelper::callPrivateStatic(Async::class, 'resolveMethod', [['method' => 123]]);
    }

    public function testResolveMaxBytesRejectsInvalidValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TestHelper::callPrivateStatic(Async::class, 'resolveMaxBytes', [['max_bytes' => 0]]);
    }

    public function testResolveMaxBytesRejectsNonInt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        /** @psalm-suppress InvalidArgument */
        TestHelper::callPrivateStatic(Async::class, 'resolveMaxBytes', [['max_bytes' => '5']]);
    }

    public function testResolveBodyAndHeaderOptionsValidation(): void
    {
        $this->assertSame('', TestHelper::callPrivateStatic(Async::class, 'resolveBody', [[]]));
        $this->assertSame('', TestHelper::callPrivateStatic(Async::class, 'resolveBody', [['body' => null]]));
        $this->assertSame('', TestHelper::callPrivateStatic(Async::class, 'resolveBody', [['body' => '']]));
        $this->assertSame('data', TestHelper::callPrivateStatic(Async::class, 'resolveBody', [['body' => 'data']]));

        try {
            TestHelper::callPrivateStatic(Async::class, 'resolveBody', [['body' => 123]]);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('opts["body"] must be a string', $e->getMessage());
        }

        $this->assertSame([], TestHelper::callPrivateStatic(Async::class, 'resolveHeaderOption', [null]));
        $this->assertSame([], TestHelper::callPrivateStatic(Async::class, 'resolveHeaderOption', [[]]));
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

        try {
            TestHelper::callPrivateStatic(Async::class, 'resolveHeaderOption', [['' => 'ok']]);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('opts["headers"] must be an array of string pairs', $e->getMessage());
        }

        try {
            TestHelper::callPrivateStatic(Async::class, 'resolveHeaderOption', [['X-Test' => '1', 'X-Other' => 2]]);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('opts["headers"] must be an array of string pairs', $e->getMessage());
        }

        try {
            TestHelper::callPrivateStatic(Async::class, 'resolveHeaderOption', [['' => 'bad', 'X-Test' => '1']]);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('opts["headers"] must be an array of string pairs', $e->getMessage());
        }
    }

    public function testResolveConnectTimeoutAndVerifyValidation(): void
    {
        $this->assertSame(30.0, TestHelper::callPrivateStatic(Async::class, 'resolveConnectTimeout', [[]]));
        $this->assertSame(30.0, TestHelper::callPrivateStatic(Async::class, 'resolveConnectTimeout', [
            ['connect_timeout' => null],
        ]));
        $this->assertSame(0.5, TestHelper::callPrivateStatic(Async::class, 'resolveConnectTimeout', [
            ['connect_timeout' => 0.5],
        ]));
        $this->assertSame(2.0, TestHelper::callPrivateStatic(Async::class, 'resolveConnectTimeout', [
            ['connect_timeout' => 2],
        ]));
        $this->assertSame(0.0, TestHelper::callPrivateStatic(Async::class, 'resolveConnectTimeout', [
            ['connect_timeout' => 0],
        ]));
        $this->assertSame(0.0, TestHelper::callPrivateStatic(Async::class, 'resolveConnectTimeout', [
            ['connect_timeout' => 0.0],
        ]));

        try {
            TestHelper::callPrivateStatic(Async::class, 'resolveConnectTimeout', [['connect_timeout' => -1]]);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('opts["connect_timeout"] must be >= 0', $e->getMessage());
        }

        try {
            TestHelper::callPrivateStatic(Async::class, 'resolveConnectTimeout', [['connect_timeout' => -0.5]]);
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

        try {
            TestHelper::callPrivateStatic(Async::class, 'resolveConnectTimeout', [['connect_timeout' => true]]);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('opts["connect_timeout"] must be a number', $e->getMessage());
        }

        try {
            TestHelper::callPrivateStatic(Async::class, 'resolveConnectTimeout', [['connect_timeout' => []]]);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('opts["connect_timeout"] must be a number', $e->getMessage());
        }

        try {
            $value = new stdClass();
            TestHelper::callPrivateStatic(Async::class, 'resolveConnectTimeout', [['connect_timeout' => $value]]);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('opts["connect_timeout"] must be a number', $e->getMessage());
        }

        $resource = TestHelper::openTempStream();
        try {
            TestHelper::callPrivateStatic(Async::class, 'resolveConnectTimeout', [['connect_timeout' => $resource]]);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('opts["connect_timeout"] must be a number', $e->getMessage());
        } finally {
            TestHelper::closeResource($resource);
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

        $multiHeader = TestHelper::callPrivateStatic(Async::class, 'buildRequest', [
            'GET',
            '/',
            ['Host' => 'example.com', 'X-Test' => '1'],
            '',
        ]);
        $this->assertSame("GET / HTTP/1.1\r\nHost: example.com\r\nX-Test: 1\r\n\r\n", $multiHeader);

        $tripleHeader = TestHelper::callPrivateStatic(Async::class, 'buildRequest', [
            'GET',
            '/',
            ['Host' => 'example.com', 'X-Test' => '1', 'X-Other' => '2'],
            '',
        ]);
        $this->assertSame("GET / HTTP/1.1\r\nHost: example.com\r\nX-Test: 1\r\nX-Other: 2\r\n\r\n", $tripleHeader);

        $quadHeader = TestHelper::callPrivateStatic(Async::class, 'buildRequest', [
            'GET',
            '/',
            ['Host' => 'example.com', 'X-Test' => '1', 'X-Other' => '2', 'X-More' => '3'],
            '',
        ]);
        $this->assertSame(
            "GET / HTTP/1.1\r\nHost: example.com\r\nX-Test: 1\r\nX-Other: 2\r\nX-More: 3\r\n\r\n",
            $quadHeader,
        );

        $multiWithBody = TestHelper::callPrivateStatic(Async::class, 'buildRequest', [
            'POST',
            '/submit',
            ['Host' => 'example.com', 'X-Test' => '1'],
            'payload',
        ]);
        $this->assertSame(
            "POST /submit HTTP/1.1\r\nHost: example.com\r\nX-Test: 1\r\n\r\npayload",
            $multiWithBody,
        );

        $emptyRequest = TestHelper::callPrivateStatic(Async::class, 'buildRequest', [
            'GET',
            '/',
            [],
            '',
        ]);
        $this->assertSame("GET / HTTP/1.1\r\n\r\n", $emptyRequest);

        $bodyOnly = TestHelper::callPrivateStatic(Async::class, 'buildRequest', [
            'POST',
            '/',
            [],
            'data',
        ]);
        $this->assertSame("POST / HTTP/1.1\r\n\r\ndata", $bodyOnly);
    }

    public function testBuildRequestSingleHeaderOnly(): void
    {
        $request = TestHelper::callPrivateStatic(Async::class, 'buildRequest', [
            'GET',
            '/',
            ['Host' => 'example.com'],
            '',
        ]);

        $this->assertSame("GET / HTTP/1.1\r\nHost: example.com\r\n\r\n", $request);
    }

    public function testBuildRequestNumericHeaderKey(): void
    {
        $request = TestHelper::callPrivateStatic(Async::class, 'buildRequest', [
            'GET',
            '/',
            [0 => 'value'],
            '',
        ]);

        $this->assertSame("GET / HTTP/1.1\r\n0: value\r\n\r\n", $request);
    }

    public function testBuildRequestMixedHeaderKeyAndValueTypes(): void
    {
        $request = TestHelper::callPrivateStatic(Async::class, 'buildRequest', [
            'GET',
            '/',
            ['Host' => 'example.com', 0 => 123, 'X-Flag' => true, 'X-Off' => false],
            '',
        ]);

        $this->assertSame(
            "GET / HTTP/1.1\r\nHost: example.com\r\n0: 123\r\nX-Flag: 1\r\nX-Off: 0\r\n\r\n",
            $request,
        );
    }

    public function testBuildRequestBooleanHeadersOnly(): void
    {
        $request = TestHelper::callPrivateStatic(Async::class, 'buildRequest', [
            'GET',
            '/',
            ['X-Flag' => true, 'X-Off' => false],
            '',
        ]);

        $this->assertSame("GET / HTTP/1.1\r\nX-Flag: 1\r\nX-Off: 0\r\n\r\n", $request);
    }

    public function testBuildRequestEmptyHeadersOnly(): void
    {
        $request = TestHelper::callPrivateStatic(Async::class, 'buildRequest', [
            'GET',
            '/',
            [],
            '',
        ]);

        $this->assertSame("GET / HTTP/1.1\r\n\r\n", $request);
    }

    public function testBuildRequestMultipleHeadersOnly(): void
    {
        $request = TestHelper::callPrivateStatic(Async::class, 'buildRequest', [
            'GET',
            '/',
            ['Host' => 'example.com', 'X-Test' => '1'],
            '',
        ]);

        $this->assertSame("GET / HTTP/1.1\r\nHost: example.com\r\nX-Test: 1\r\n\r\n", $request);
    }

    public function testBuildRequestRejectsNonArrayHeaders(): void
    {
        $this->expectException(TypeError::class);
        TestHelper::callPrivateStatic(Async::class, 'buildRequest', [
            'GET',
            '/',
            'not-an-array',
            '',
        ]);
    }

    public function testBuildRequestWarnsOnNonScalarHeaderValue(): void
    {
        $this->expectException(TypeError::class);
        TestHelper::callPrivateStatic(Async::class, 'buildRequest', [
            'GET',
            '/',
            ['X-Test' => []],
            '',
        ]);
    }

    public function testBuildRequestRejectsNonScalarHeaderAfterScalar(): void
    {
        $this->expectException(TypeError::class);
        TestHelper::callPrivateStatic(Async::class, 'buildRequest', [
            'GET',
            '/',
            ['X-Good' => '1', 'X-Bad' => []],
            '',
        ]);
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

    public function testOpenStreamFailureUsesDefaultErrorCode(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connect failed: Unknown error');
        $this->expectExceptionCode(0);
        SocketStub::queueSilentFailure();
        TestHelper::callPrivateStatic(Async::class, 'openStream', [
            'http',
            'example.test',
            80,
            0.1,
            true,
        ]);
    }

    public function testOpenStreamRethrowsThrownExceptions(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        SocketStub::queueException(new RuntimeException('boom'));
        TestHelper::callPrivateStatic(Async::class, 'openStream', [
            'http',
            'example.test',
            80,
            0.1,
            true,
        ]);
    }

    public function testOpenStreamHttpsFailuresUseErrorMessages(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connect failed: Connect failed');
        SocketStub::queueFailure();
        TestHelper::callPrivateStatic(Async::class, 'openStream', [
            'https',
            'example.test',
            443,
            0.1,
            true,
        ]);
    }

    public function testOpenStreamHttpsSilentFailureUsesDefaultError(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connect failed: Unknown error');
        SocketStub::queueSilentFailure();
        TestHelper::callPrivateStatic(Async::class, 'openStream', [
            'https',
            'example.test',
            443,
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

    public function testIgnoreErrorAlwaysReturnsTrue(): void
    {
        $this->assertTrue(TestHelper::callPrivateStatic(Async::class, 'ignoreError', [E_WARNING, 'oops']));
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

    public function testSplitResponseHandlesEmptySegments(): void
    {
        /** @var array{head: string, body: string} $parts */
        $parts = TestHelper::callPrivateStatic(Async::class, 'splitResponse', ["\r\n\r\nbody"]);
        $this->assertSame('', $parts['head']);
        $this->assertSame('body', $parts['body']);

        /** @var array{head: string, body: string} $tail */
        $tail = TestHelper::callPrivateStatic(Async::class, 'splitResponse', ["HTTP/1.1 200 OK\r\n\r\n"]);
        $this->assertSame('HTTP/1.1 200 OK', $tail['head']);
        $this->assertSame('', $tail['body']);
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

    public function testReadChunkReturnsRemainderWithoutCrlf(): void
    {
        /** @var array{0: string, 1: string} $parts */
        $parts = TestHelper::callPrivateStatic(Async::class, 'readChunk', ["A\r\nNEXT", 1]);
        [$chunk, $rest] = $parts;
        $this->assertSame('A', $chunk);
        $this->assertSame('NEXT', $rest);
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

    public function testDecodeChunkedRejectsTrailingDataAfterTrailerHeaders(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Malformed chunked body (invalid trailer)');
        TestHelper::callPrivateStatic(Async::class, 'decodeChunked', ["0\r\nX-Test: 1\r\n\r\nextra"]);
    }

    public function testConsumeTrailerRejectsExtraBytes(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Malformed chunked body (invalid trailer)');
        TestHelper::callPrivateStatic(Async::class, 'consumeTrailer', ["\r\nextra"]);
    }

    public function testConsumeTrailerHandlesHeaderAndInvalidVariants(): void
    {
        TestHelper::callPrivateStatic(Async::class, 'consumeTrailer', ["X-Test: 1\r\n\r\n"]);
        TestHelper::callPrivateStatic(Async::class, 'consumeTrailer', ["X-Test: 1\r\nX-Other: 2\r\n\r\n"]);

        try {
            TestHelper::callPrivateStatic(Async::class, 'consumeTrailer', ["X-Test: 1\r\n\r\nextra"]);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('Malformed chunked body (invalid trailer)', $e->getMessage());
        }

        try {
            TestHelper::callPrivateStatic(Async::class, 'consumeTrailer', ["X-Test: 1\r\nX-Other: 2\r\n\r\nextra"]);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('Malformed chunked body (invalid trailer)', $e->getMessage());
        }

        try {
            TestHelper::callPrivateStatic(Async::class, 'consumeTrailer', ["X-Test: 1\r\nbroken"]);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('Malformed chunked body (invalid trailer)', $e->getMessage());
        }

        try {
            TestHelper::callPrivateStatic(Async::class, 'consumeTrailer', ['broken']);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('Malformed chunked body (invalid trailer)', $e->getMessage());
        }
    }

    public function testConsumeTrailerRejectsEmptyBuffer(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Malformed chunked body (invalid trailer)');
        TestHelper::callPrivateStatic(Async::class, 'consumeTrailer', ['']);
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

    public function testParseChunkSizeVariants(): void
    {
        $this->assertSame(0, TestHelper::callPrivateStatic(Async::class, 'parseChunkSize', ['0']));
        $this->assertSame(10, TestHelper::callPrivateStatic(Async::class, 'parseChunkSize', ['A']));
        $this->assertSame(1, TestHelper::callPrivateStatic(Async::class, 'parseChunkSize', ['1;ext=foo']));
        $this->assertSame(1, TestHelper::callPrivateStatic(Async::class, 'parseChunkSize', [' 1 ;ext=bar']));

        $invalid = ['', ';ext', 'zz', 'g', str_repeat('F', 32)];
        foreach ($invalid as $value) {
            try {
                TestHelper::callPrivateStatic(Async::class, 'parseChunkSize', [$value]);
                $this->fail('Expected RuntimeException for chunk size: ' . $value);
            } catch (RuntimeException $e) {
                $this->assertSame('Malformed chunked body (invalid chunk size)', $e->getMessage());
            }
        }
    }

    public function testReadLineVariants(): void
    {
        /** @var array{0: string, 1: string} $parts */
        $parts = TestHelper::callPrivateStatic(Async::class, 'readLine', ["hello\r\nrest", 'error']);
        $this->assertSame('hello', $parts[0]);
        $this->assertSame('rest', $parts[1]);

        /** @var array{0: string, 1: string} $emptyLine */
        $emptyLine = TestHelper::callPrivateStatic(Async::class, 'readLine', ["\r\nrest", 'error']);
        $this->assertSame('', $emptyLine[0]);
        $this->assertSame('rest', $emptyLine[1]);

        /** @var array{0: string, 1: string} $terminal */
        $terminal = TestHelper::callPrivateStatic(Async::class, 'readLine', ["x\r\n", 'error']);
        $this->assertSame('x', $terminal[0]);
        $this->assertSame('', $terminal[1]);

        /** @var array{0: string, 1: string} $emptyTerminal */
        $emptyTerminal = TestHelper::callPrivateStatic(Async::class, 'readLine', ["\r\n", 'error']);
        $this->assertSame('', $emptyTerminal[0]);
        $this->assertSame('', $emptyTerminal[1]);

        try {
            TestHelper::callPrivateStatic(Async::class, 'readLine', ['nope', 'nope']);
            $this->fail('Expected RuntimeException for missing line ending');
        } catch (RuntimeException $e) {
            $this->assertSame('nope', $e->getMessage());
        }

        try {
            TestHelper::callPrivateStatic(Async::class, 'readLine', ['', 'empty']);
            $this->fail('Expected RuntimeException for empty buffer');
        } catch (RuntimeException $e) {
            $this->assertSame('empty', $e->getMessage());
        }
    }

    public function testReadChunkVariants(): void
    {
        /** @var array{0: string, 1: string} $parts */
        $parts = TestHelper::callPrivateStatic(Async::class, 'readChunk', ["A\r\n", 1]);
        $this->assertSame('A', $parts[0]);
        $this->assertSame('', $parts[1]);

        /** @var array{0: string, 1: string} $longer */
        $longer = TestHelper::callPrivateStatic(Async::class, 'readChunk', ["AB\r\n", 2]);
        $this->assertSame('AB', $longer[0]);
        $this->assertSame('', $longer[1]);

        /** @var array{0: string, 1: string} $longerWithRest */
        $longerWithRest = TestHelper::callPrivateStatic(Async::class, 'readChunk', ["AB\r\nTAIL", 2]);
        $this->assertSame('AB', $longerWithRest[0]);
        $this->assertSame('TAIL', $longerWithRest[1]);

        /** @var array{0: string, 1: string} $zeroLen */
        $zeroLen = TestHelper::callPrivateStatic(Async::class, 'readChunk', ["\r\n", 0]);
        $this->assertSame('', $zeroLen[0]);
        $this->assertSame('', $zeroLen[1]);

        /** @var array{0: string, 1: string} $zeroWithRest */
        $zeroWithRest = TestHelper::callPrivateStatic(Async::class, 'readChunk', ["\r\nNEXT", 0]);
        $this->assertSame('', $zeroWithRest[0]);
        $this->assertSame('NEXT', $zeroWithRest[1]);

        /** @var array{0: string, 1: string} $three */
        $three = TestHelper::callPrivateStatic(Async::class, 'readChunk', ["ABC\r\n", 3]);
        $this->assertSame('ABC', $three[0]);
        $this->assertSame('', $three[1]);

        /** @var array{0: string, 1: string} $threeWithRest */
        $threeWithRest = TestHelper::callPrivateStatic(Async::class, 'readChunk', ["ABC\r\nTAIL", 3]);
        $this->assertSame('ABC', $threeWithRest[0]);
        $this->assertSame('TAIL', $threeWithRest[1]);

        /** @var array{0: string, 1: string} $oneWithRest */
        $oneWithRest = TestHelper::callPrivateStatic(Async::class, 'readChunk', ["A\r\nREST", 1]);
        $this->assertSame('A', $oneWithRest[0]);
        $this->assertSame('REST', $oneWithRest[1]);

        /** @var array{0: string, 1: string} $four */
        $four = TestHelper::callPrivateStatic(Async::class, 'readChunk', ["ABCD\r\n", 4]);
        $this->assertSame('ABCD', $four[0]);
        $this->assertSame('', $four[1]);

        /** @var array{0: string, 1: string} $fourWithRest */
        $fourWithRest = TestHelper::callPrivateStatic(Async::class, 'readChunk', ["ABCD\r\nTAIL", 4]);
        $this->assertSame('ABCD', $fourWithRest[0]);
        $this->assertSame('TAIL', $fourWithRest[1]);

        try {
            TestHelper::callPrivateStatic(Async::class, 'readChunk', ["Axy", 1]);
            $this->fail('Expected RuntimeException for missing CRLF');
        } catch (RuntimeException $e) {
            $this->assertSame('Malformed chunked body (missing CRLF after chunk)', $e->getMessage());
        }

        try {
            TestHelper::callPrivateStatic(Async::class, 'readChunk', ["A\rx", 1]);
            $this->fail('Expected RuntimeException for malformed CRLF');
        } catch (RuntimeException $e) {
            $this->assertSame('Malformed chunked body (missing CRLF after chunk)', $e->getMessage());
        }

        try {
            TestHelper::callPrivateStatic(Async::class, 'readChunk', ["A\r\nX", 2]);
            $this->fail('Expected RuntimeException for premature CRLF');
        } catch (RuntimeException $e) {
            $this->assertSame('Malformed chunked body (missing CRLF after chunk)', $e->getMessage());
        }

        try {
            TestHelper::callPrivateStatic(Async::class, 'readChunk', ["A\r", 1]);
            $this->fail('Expected RuntimeException for incomplete chunk');
        } catch (RuntimeException $e) {
            $this->assertSame('Malformed chunked body (incomplete chunk)', $e->getMessage());
        }

        try {
            TestHelper::callPrivateStatic(Async::class, 'readChunk', ['', 0]);
            $this->fail('Expected RuntimeException for empty buffer');
        } catch (RuntimeException $e) {
            $this->assertSame('Malformed chunked body (incomplete chunk)', $e->getMessage());
        }

        try {
            TestHelper::callPrivateStatic(Async::class, 'readChunk', ["\rX", 0]);
            $this->fail('Expected RuntimeException for malformed CRLF with zero length');
        } catch (RuntimeException $e) {
            $this->assertSame('Malformed chunked body (missing CRLF after chunk)', $e->getMessage());
        }

        try {
            TestHelper::callPrivateStatic(Async::class, 'readChunk', ["xx", 0]);
            $this->fail('Expected RuntimeException for missing CRLF with zero length');
        } catch (RuntimeException $e) {
            $this->assertSame('Malformed chunked body (missing CRLF after chunk)', $e->getMessage());
        }

        try {
            TestHelper::callPrivateStatic(Async::class, 'readChunk', ["A\n\r", 1]);
            $this->fail('Expected RuntimeException for missing CRLF with reversed line ending');
        } catch (RuntimeException $e) {
            $this->assertSame('Malformed chunked body (missing CRLF after chunk)', $e->getMessage());
        }

        try {
            TestHelper::callPrivateStatic(Async::class, 'readChunk', ["\n\r", 0]);
            $this->fail('Expected RuntimeException for missing CRLF with reversed zero length');
        } catch (RuntimeException $e) {
            $this->assertSame('Malformed chunked body (missing CRLF after chunk)', $e->getMessage());
        }
    }

    public function testReadChunkRejectsNonStringBuffer(): void
    {
        $this->expectException(TypeError::class);

        TestHelper::callPrivateStatic(Async::class, 'readChunk', [[], 1]);
    }

    public function testReadChunkRejectsNonIntLength(): void
    {
        $this->expectException(TypeError::class);

        TestHelper::callPrivateStatic(Async::class, 'readChunk', ["\r\n", []]);
    }

    public function testReadChunkRejectsNonStringBufferObject(): void
    {
        $this->expectException(TypeError::class);

        TestHelper::callPrivateStatic(Async::class, 'readChunk', [new stdClass(), 1]);
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
