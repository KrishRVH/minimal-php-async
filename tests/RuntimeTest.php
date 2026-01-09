<?php

/**
 * @phan-file-suppress PhanAccessMethodInternal
 * @phan-file-suppress PhanUnreferencedClosure
 * @phan-file-suppress PhanUnreferencedClass
 */

declare(strict_types=1);

namespace Krvh\MinimalPhpAsync\Tests;

use Fiber;
use InvalidArgumentException;
use Krvh\MinimalPhpAsync\IoWatcher;
use Krvh\MinimalPhpAsync\Runtime;
use Krvh\MinimalPhpAsync\Task;
use Krvh\MinimalPhpAsync\Tests\Support\AsyncTestCase;
use Krvh\MinimalPhpAsync\Tests\Support\EmptyReadStream;
use Krvh\MinimalPhpAsync\Tests\Support\FailingReadStream;
use Krvh\MinimalPhpAsync\Tests\Support\FixedWriteStream;
use Krvh\MinimalPhpAsync\Tests\Support\SelectStub;
use Krvh\MinimalPhpAsync\Tests\Support\SleepStub;
use Krvh\MinimalPhpAsync\Tests\Support\TestHelper;
use Krvh\MinimalPhpAsync\Tests\Support\ThrowingWriteStream;
use Krvh\MinimalPhpAsync\Tests\Support\TimeStub;
use Krvh\MinimalPhpAsync\Timer;
use LogicException;
use RuntimeException;
use WeakMap;

/** @psalm-suppress UnusedClass */
final class RuntimeTest extends AsyncTestCase
{
    public function testDriveThrowsOnDeadlock(): void
    {
        $runtime = new Runtime();
        $state = new class {
            public int $calls = 0;
        };

        $this->expectException(RuntimeException::class);
        $runtime->drive(static function () use ($state): bool {
            $state->calls++;
            if ($state->calls > 1) {
                throw new LogicException('drive guard');
            }
            return false;
        });
    }

    public function testDriveReturnsImmediatelyWhenConditionTrue(): void
    {
        $runtime = new Runtime();

        $runtime->drive(static fn(): bool => true);
        $this->assertSame([], TestHelper::getProperty($runtime, 'read'));
    }

    public function testDriveContinuesWhenPendingWorkExists(): void
    {
        $runtime = new Runtime();
        $fiber = TestHelper::newTerminatedFiber();
        TestHelper::setProperty($runtime, 'timers', [new Timer(microtime(true) - 1.0, $fiber)]);

        $state = new class {
            public int $iterations = 0;
        };
        $runtime->drive(static function () use ($state): bool {
            $state->iterations++;
            return $state->iterations > 1;
        });

        $this->assertSame(2, $state->iterations);
    }

    public function testDriveContinuesWhenReadWatcherExists(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();
        $fiber = TestHelper::newSuspendedFiber();

        TestHelper::setProperty($runtime, 'read', [
            (int) $stream => new IoWatcher($stream, $fiber, '', 10),
        ]);

        SelectStub::forceResult(0);
        $state = new class {
            public int $calls = 0;
        };
        $runtime->drive(static function () use ($state): bool {
            $state->calls++;
            return $state->calls >= 2;
        });

        $this->assertSame(2, $state->calls);
        TestHelper::setProperty($runtime, 'read', []);
        TestHelper::closeResource($stream);
    }

    public function testDriveContinuesWhenWriteWatcherExists(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();
        $fiber = TestHelper::newSuspendedFiber();

        TestHelper::setProperty($runtime, 'write', [
            (int) $stream => new IoWatcher($stream, $fiber, 'data', 0),
        ]);

        SelectStub::forceResult(0);
        $state = new class {
            public int $calls = 0;
        };
        $runtime->drive(static function () use ($state): bool {
            $state->calls++;
            return $state->calls >= 2;
        });

        $this->assertSame(2, $state->calls);
        TestHelper::setProperty($runtime, 'write', []);
        TestHelper::closeResource($stream);
    }

    public function testDriveThrowsAfterWatchersCleared(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();
        fwrite($stream, 'ping');
        rewind($stream);
        $writeStream = TestHelper::openTempStream();
        $writeFiber = TestHelper::newSuspendedFiber();
        $state = new class {
            public int $calls = 0;
        };

        $fiber = TestHelper::newSuspendedFiber();
        TestHelper::setProperty($runtime, 'read', [
            (int) $stream => new IoWatcher($stream, $fiber, '', 10),
        ]);
        TestHelper::setProperty($runtime, 'write', [
            (int) $writeStream => new IoWatcher($writeStream, $writeFiber, 'pong', 0),
        ]);

        SelectStub::forceResult(2, [$stream], [$writeStream]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Deadlock: no pending I/O or timers, but condition not met');

        try {
            $runtime->drive(static function () use ($state): bool {
                $state->calls++;
                if ($state->calls > 2) {
                    throw new LogicException('drive guard');
                }
                return false;
            });
        } finally {
            TestHelper::setProperty($runtime, 'read', []);
            TestHelper::setProperty($runtime, 'write', []);
            TestHelper::closeResource($stream);
            TestHelper::closeResource($writeStream);
        }
    }

    public function testAssertHasPendingWorkCoversCombinations(): void
    {
        $runtime = new Runtime();
        $readStream = TestHelper::openTempStream();
        $writeStream = TestHelper::openTempStream();
        $fiber = TestHelper::newSuspendedFiber();

        $readWatcher = new IoWatcher($readStream, $fiber, '', 10);
        $writeWatcher = new IoWatcher($writeStream, $fiber, 'data', 0);
        $timer = new Timer(microtime(true) + 1.0, $fiber);

        $cases = [
            ['read' => [], 'write' => [], 'timers' => [], 'throws' => true],
            ['read' => [(int) $readStream => $readWatcher], 'write' => [], 'timers' => [], 'throws' => false],
            ['read' => [], 'write' => [(int) $writeStream => $writeWatcher], 'timers' => [], 'throws' => false],
            ['read' => [], 'write' => [], 'timers' => [$timer], 'throws' => false],
            [
                'read' => [(int) $readStream => $readWatcher],
                'write' => [(int) $writeStream => $writeWatcher],
                'timers' => [],
                'throws' => false,
            ],
            ['read' => [(int) $readStream => $readWatcher], 'write' => [], 'timers' => [$timer], 'throws' => false],
            ['read' => [], 'write' => [(int) $writeStream => $writeWatcher], 'timers' => [$timer], 'throws' => false],
            [
                'read' => [(int) $readStream => $readWatcher],
                'write' => [(int) $writeStream => $writeWatcher],
                'timers' => [$timer],
                'throws' => false,
            ],
        ];

        foreach ($cases as $case) {
            TestHelper::setProperty($runtime, 'read', $case['read']);
            TestHelper::setProperty($runtime, 'write', $case['write']);
            TestHelper::setProperty($runtime, 'timers', $case['timers']);

            try {
                TestHelper::callPrivate($runtime, 'assertHasPendingWork');
                $this->assertFalse($case['throws']);
            } catch (RuntimeException) {
                $this->assertTrue($case['throws']);
            }
        }

        TestHelper::setProperty($runtime, 'read', []);
        TestHelper::setProperty($runtime, 'write', []);
        TestHelper::setProperty($runtime, 'timers', []);
        fclose($readStream);
        fclose($writeStream);
    }

    public function testQueueCapturesException(): void
    {
        $runtime = new Runtime();
        $task = $runtime->queue(static function (): mixed {
            throw new RuntimeException('boom');
        });

        $this->expectException(RuntimeException::class);
        TestHelper::withTimeout(1, static function () use ($task): void {
            $task->await();
        });
    }

    public function testQueueNotifiesWaitersOnReject(): void
    {
        $runtime = new Runtime();
        $state = new class {
            public ?string $message = null;
            public int $loops = 0;
        };

        $parent = $runtime->queue(static function () use ($runtime, $state): void {
            $child = $runtime->queue(static function () use ($runtime): never {
                $runtime->delay(0.0);
                throw new RuntimeException('boom');
            });

            $state->message = 'missed';
            try {
                $child->await();
            } catch (RuntimeException $e) {
                $state->message = $e->getMessage();
            }
        });

        $runtime->drive(static function () use ($state, $parent): bool {
            $state->loops++;
            if ($state->loops > 10) {
                throw new LogicException('drive guard');
            }
            return $parent->isDone();
        });
        $actual = $state->message;
        $this->assertSame('boom', $actual);
    }

    public function testQueueTracksChildTasks(): void
    {
        $runtime = new Runtime();
        $state = new class {
            /** @var Task<string>|null */
            public ?Task $child = null;
        };

        $parent = $runtime->queue(static function () use ($runtime, $state): mixed {
            $state->child = $runtime->queue(static fn(): string => 'child');
            return null;
        });

        TestHelper::withTimeout(1, static fn(): mixed => $parent->await());

        $child = $state->child;
        if (!$child instanceof Task) {
            $this->fail('Expected child task');
        }

        $children = $parent->getChildren();
        $this->assertCount(1, $children);
        $this->assertSame($child, $children[0]);
    }

    public function testQueueSkipsUnknownParentFiber(): void
    {
        $runtime = new Runtime();
        $state = new class {
            /** @var Task<int>|null */
            public ?Task $task = null;
        };

        $fiber = new Fiber(static function () use ($runtime, $state): void {
            $state->task = $runtime->queue(static fn(): int => 1);
        });
        $fiber->start();

        $task = $state->task;
        if (!$task instanceof Task) {
            $this->fail('Expected task');
        }

        $this->assertSame([], $task->getChildren());
    }

    public function testRequireFiberThrowsFromRoot(): void
    {
        $runtime = new Runtime();

        $this->expectException(LogicException::class);
        TestHelper::callPrivate($runtime, 'requireFiber');
    }

    public function testDelayResumesTask(): void
    {
        $runtime = new Runtime();
        $task = $runtime->queue(static function () use ($runtime): string {
            $runtime->delay(0.0);
            return 'ok';
        });

        $this->assertSame('ok', TestHelper::withTimeout(1, static fn(): mixed => $task->await()));
    }

    public function testDelayAcceptsNegativeSeconds(): void
    {
        $runtime = new Runtime();
        $task = $runtime->queue(static function () use ($runtime): string {
            $runtime->delay(-0.01);
            return 'ok';
        });

        $this->assertSame('ok', TestHelper::withTimeout(1, static fn(): mixed => $task->await()));
    }

    public function testDelayAcceptsPositiveSeconds(): void
    {
        $runtime = new Runtime();
        $task = $runtime->queue(static function () use ($runtime): string {
            $runtime->delay(0.01);
            return 'ok';
        });

        $this->assertSame('ok', TestHelper::withTimeout(1, static fn(): mixed => $task->await()));
    }

    public function testDelayThrowsFromRoot(): void
    {
        $runtime = new Runtime();

        $this->expectException(LogicException::class);
        $runtime->delay(0.0);
    }

    public function testDelayThrowsFromRootWithPositiveSeconds(): void
    {
        $runtime = new Runtime();

        $this->expectException(LogicException::class);
        $runtime->delay(0.5);
    }

    public function testDelayThrowsFromRootWithNegativeSeconds(): void
    {
        $runtime = new Runtime();

        $this->expectException(LogicException::class);
        $runtime->delay(-0.5);
    }

    public function testWriteEmptyIsNoop(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();

        $runtime->write($stream, '');
        $this->assertSame([], TestHelper::getProperty($runtime, 'write'));
        TestHelper::closeResource($stream);
    }

    public function testWriteRejectsInvalidStream(): void
    {
        $runtime = new Runtime();

        $this->expectException(InvalidArgumentException::class);
        $runtime->write('not-a-stream', 'data');
    }

    public function testWriteSetsStreamNonBlocking(): void
    {
        if (!function_exists('stream_socket_pair') || !defined('STREAM_PF_UNIX')) {
            $this->markTestSkipped('stream_socket_pair is unavailable in this environment.');
        }

        $runtime = new Runtime();
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false) {
            $this->markTestSkipped('Unable to create socket pair.');
        }
        [$stream, $peer] = $pair;

        $fiber = new Fiber(static function () use ($runtime, $stream): void {
            $runtime->write($stream, 'data');
        });
        $fiber->start();

        $meta = stream_get_meta_data($stream);
        $this->assertArrayHasKey('blocked', $meta);
        $this->assertFalse($meta['blocked']);

        $runtime->cancelFiber($fiber);
        fclose($peer);
    }

    public function testReadAllAndWriteUsingTempStream(): void
    {
        $runtime = new Runtime();

        $readStream = TestHelper::openTempStream();
        fwrite($readStream, 'hello');
        rewind($readStream);

        $reader = $runtime->queue(static fn(): string => $runtime->readAll($readStream, 100));

        $this->assertSame('hello', TestHelper::withTimeout(1, static fn(): mixed => $reader->await()));

        $writeStream = TestHelper::openTempStream();

        $writer = $runtime->queue(static function () use ($runtime, $writeStream): mixed {
            $runtime->write($writeStream, 'hello');
            return null;
        });
        TestHelper::withTimeout(1, static fn(): mixed => $writer->await());

        rewind($writeStream);
        $this->assertSame('hello', stream_get_contents($writeStream));
        fclose($writeStream);
    }

    public function testReadAllSetsStreamNonBlocking(): void
    {
        if (!function_exists('stream_socket_pair') || !defined('STREAM_PF_UNIX')) {
            $this->markTestSkipped('stream_socket_pair is unavailable in this environment.');
        }

        $runtime = new Runtime();
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false) {
            $this->markTestSkipped('Unable to create socket pair.');
        }
        [$stream, $peer] = $pair;

        $fiber = new Fiber(static function () use ($runtime, $stream): void {
            $runtime->readAll($stream, 10);
        });
        $fiber->start();

        $meta = stream_get_meta_data($stream);
        $this->assertArrayHasKey('blocked', $meta);
        $this->assertFalse($meta['blocked']);

        $runtime->cancelFiber($fiber);
        fclose($peer);
    }

    public function testReadAllRejectsInvalidMaxBytes(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();

        $this->expectException(InvalidArgumentException::class);
        try {
            $runtime->readAll($stream, 0);
        } finally {
            fclose($stream);
        }
    }

    public function testReadAllRejectsInvalidStream(): void
    {
        $runtime = new Runtime();

        $this->expectException(InvalidArgumentException::class);
        $runtime->readAll('not-a-stream', 10);
    }

    public function testReadAllThrowsOnNonStringResume(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();

        $fiber = new Fiber(static function () use ($runtime, $stream): void {
            $runtime->readAll($stream, 10);
        });
        $fiber->start();

        try {
            /** @phan-suppress-next-line PhanParamTooManyInternal */
            $fiber->resume(123);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('Read failed: non-string payload', $e->getMessage());
        } finally {
            TestHelper::setProperty($runtime, 'read', []);
            fclose($stream);
        }
    }

    public function testProcessWritesHandlesMissingWatcher(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();

        TestHelper::callPrivate($runtime, 'processWrites', [[$stream]]);
        $this->assertSame([], TestHelper::getProperty($runtime, 'write'));
        fclose($stream);
    }

    public function testProcessWritesSkipsNonResourceStreams(): void
    {
        $runtime = new Runtime();

        TestHelper::callPrivate($runtime, 'processWrites', [[null]]);
        $this->assertSame([], TestHelper::getProperty($runtime, 'write'));
    }

    public function testProcessWritesHandlesNonListStreams(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();

        $fiber = TestHelper::newSuspendedFiber();
        $watcher = new IoWatcher($stream, $fiber, 'ok', 0);

        TestHelper::setProperty($runtime, 'write', [
            (int) $stream => $watcher,
        ]);

        TestHelper::callPrivate($runtime, 'processWrites', [[5 => $stream]]);

        $this->assertTrue($fiber->isTerminated());
        $this->assertSame([], TestHelper::getProperty($runtime, 'write'));

        fclose($stream);
    }

    public function testProcessWritesContinuesAfterMissingWatcher(): void
    {
        $runtime = new Runtime();
        $missing = TestHelper::openTempStream();
        $stream = TestHelper::openTempStream();

        $fiber = TestHelper::newSuspendedFiber();
        $watcher = new IoWatcher($stream, $fiber, 'ok', 0);

        TestHelper::setProperty($runtime, 'write', [
            (int) $stream => $watcher,
        ]);

        TestHelper::callPrivate($runtime, 'processWrites', [[$missing, $stream]]);

        $this->assertTrue($fiber->isTerminated());
        $this->assertSame([], TestHelper::getProperty($runtime, 'write'));

        fclose($missing);
        fclose($stream);
    }

    public function testProcessWritesHandlesWriteFailure(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream('r');

        $fiber = TestHelper::newSuspendedFiber();
        $watcher = new IoWatcher($stream, $fiber, 'data', 0);

        TestHelper::setProperty($runtime, 'write', [
            (int) $stream => $watcher,
        ]);

        TestHelper::callPrivate($runtime, 'processWrites', [[$stream]]);

        $writeMap = TestHelper::getProperty($runtime, 'write');
        $this->assertSame([], $writeMap);
        TestHelper::closeResource($stream);
    }

    public function testProcessWritesContinuesAfterWriteFailure(): void
    {
        $runtime = new Runtime();
        $failed = TestHelper::openTempStream('r');
        $stream = TestHelper::openTempStream();

        $failedFiber = TestHelper::newSuspendedFiber();
        $failedWatcher = new IoWatcher($failed, $failedFiber, 'data', 0);

        $fiber = TestHelper::newSuspendedFiber();
        $watcher = new IoWatcher($stream, $fiber, 'ok', 0);

        TestHelper::setProperty($runtime, 'write', [
            (int) $failed => $failedWatcher,
            (int) $stream => $watcher,
        ]);

        TestHelper::callPrivate($runtime, 'processWrites', [[$failed, $stream]]);

        $this->assertTrue($fiber->isTerminated());
        $this->assertSame([], TestHelper::getProperty($runtime, 'write'));

        TestHelper::closeResource($failed);
        fclose($stream);
    }

    public function testProcessWritesHandlesWriteFailureAfterClamp(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream('r');

        $fiber = TestHelper::newSuspendedFiber();
        $payload = str_repeat('a', 9000);
        $watcher = new IoWatcher($stream, $fiber, $payload, 0);

        TestHelper::setProperty($runtime, 'write', [
            (int) $stream => $watcher,
        ]);

        TestHelper::callPrivate($runtime, 'processWrites', [[$stream]]);

        $this->assertSame([], TestHelper::getProperty($runtime, 'write'));
        $this->assertTrue($fiber->isTerminated());

        TestHelper::closeResource($stream);
    }

    public function testProcessWritesHandlesZeroProgress(): void
    {
        $runtime = new Runtime();
        FixedWriteStream::register();

        $stream = TestHelper::openStream(FixedWriteStream::uriFor(0), 'w');

        try {
            $fiber = TestHelper::newSuspendedFiber();
            $watcher = new IoWatcher($stream, $fiber, 'data', 0);

            TestHelper::setProperty($runtime, 'write', [
                (int) $stream => $watcher,
            ]);

            TestHelper::callPrivate($runtime, 'processWrites', [[$stream]]);

            /** @var array<int, IoWatcher> $writeMap */
            $writeMap = TestHelper::getProperty($runtime, 'write');
            $this->assertArrayHasKey((int) $stream, $writeMap);
            $this->assertSame($watcher, $writeMap[(int) $stream]);
        } finally {
            TestHelper::setProperty($runtime, 'write', []);
            TestHelper::closeResource($stream);
            FixedWriteStream::unregister();
        }
    }

    public function testProcessWritesContinuesAfterZeroProgress(): void
    {
        $runtime = new Runtime();
        FixedWriteStream::register();

        $stalled = TestHelper::openStream(FixedWriteStream::uriFor(0), 'w');
        $stream = TestHelper::openTempStream();

        try {
            $stalledFiber = TestHelper::newSuspendedFiber();
            $stalledWatcher = new IoWatcher($stalled, $stalledFiber, 'data', 0);

            $fiber = TestHelper::newSuspendedFiber();
            $watcher = new IoWatcher($stream, $fiber, 'ok', 0);

            TestHelper::setProperty($runtime, 'write', [
                (int) $stalled => $stalledWatcher,
                (int) $stream => $watcher,
            ]);

            TestHelper::callPrivate($runtime, 'processWrites', [[$stalled, $stream]]);

            /** @var array<int, IoWatcher> $writeMap */
            $writeMap = TestHelper::getProperty($runtime, 'write');
            $this->assertArrayHasKey((int) $stalled, $writeMap);
            $this->assertArrayNotHasKey((int) $stream, $writeMap);
            $this->assertTrue($fiber->isTerminated());
        } finally {
            TestHelper::setProperty($runtime, 'write', []);
            TestHelper::closeResource($stalled);
            fclose($stream);
            FixedWriteStream::unregister();
        }
    }

    public function testProcessWritesHandlesZeroProgressWithLargeBuffer(): void
    {
        $runtime = new Runtime();
        FixedWriteStream::register();

        $stream = TestHelper::openStream(FixedWriteStream::uriFor(0), 'w');

        try {
            $fiber = TestHelper::newSuspendedFiber();
            $payload = str_repeat('a', 9000);
            $watcher = new IoWatcher($stream, $fiber, $payload, 0);

            TestHelper::setProperty($runtime, 'write', [
                (int) $stream => $watcher,
            ]);

            TestHelper::callPrivate($runtime, 'processWrites', [[$stream]]);

            /** @var array<int, IoWatcher> $writeMap */
            $writeMap = TestHelper::getProperty($runtime, 'write');
            $this->assertArrayHasKey((int) $stream, $writeMap);
        } finally {
            TestHelper::setProperty($runtime, 'write', []);
            TestHelper::closeResource($stream);
            FixedWriteStream::unregister();
        }
    }

    public function testProcessWritesHandlesPartialAndComplete(): void
    {
        $runtime = new Runtime();

        $stream = TestHelper::openTempStream();

        $fiber = TestHelper::newSuspendedFiber();
        $payload = str_repeat('a', 9000);
        $watcher = new IoWatcher($stream, $fiber, $payload, 0);
        TestHelper::setProperty($runtime, 'write', [
            (int) $stream => $watcher,
        ]);

        TestHelper::callPrivate($runtime, 'processWrites', [[$stream]]);
        /** @var array<int, IoWatcher> $writeMap */
        $writeMap = TestHelper::getProperty($runtime, 'write');
        $this->assertSame(8192, $writeMap[(int) $stream]->offsetOrMaxBytes);

        TestHelper::setProperty($runtime, 'write', []);
        fclose($stream);

        $stream = TestHelper::openTempStream();

        $fiber = TestHelper::newSuspendedFiber();
        $watcher = new IoWatcher($stream, $fiber, 'ok', 0);
        TestHelper::setProperty($runtime, 'write', [
            (int) $stream => $watcher,
        ]);

        TestHelper::callPrivate($runtime, 'processWrites', [[$stream]]);
        $this->assertTrue($fiber->isTerminated());
        $this->assertSame([], TestHelper::getProperty($runtime, 'write'));
        fclose($stream);
    }

    public function testProcessWritesContinuesAfterPartialWrite(): void
    {
        $runtime = new Runtime();
        $partialStream = TestHelper::openTempStream();
        $stream = TestHelper::openTempStream();

        $partialFiber = TestHelper::newSuspendedFiber();
        $payload = str_repeat('a', 9000);
        $partialWatcher = new IoWatcher($partialStream, $partialFiber, $payload, 0);

        $fiber = TestHelper::newSuspendedFiber();
        $watcher = new IoWatcher($stream, $fiber, 'ok', 0);

        TestHelper::setProperty($runtime, 'write', [
            (int) $partialStream => $partialWatcher,
            (int) $stream => $watcher,
        ]);

        TestHelper::callPrivate($runtime, 'processWrites', [[$partialStream, $stream]]);

        /** @var array<int, IoWatcher> $writeMap */
        $writeMap = TestHelper::getProperty($runtime, 'write');
        $this->assertSame(8192, $writeMap[(int) $partialStream]->offsetOrMaxBytes);
        $this->assertArrayNotHasKey((int) $stream, $writeMap);
        $this->assertTrue($fiber->isTerminated());

        TestHelper::setProperty($runtime, 'write', []);
        fclose($partialStream);
        fclose($stream);
    }

    public function testProcessWritesSkipsResumeWhenFiberTerminated(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();

        $fiber = TestHelper::newTerminatedFiber();
        $watcher = new IoWatcher($stream, $fiber, 'done', 0);
        TestHelper::setProperty($runtime, 'write', [
            (int) $stream => $watcher,
        ]);

        TestHelper::callPrivate($runtime, 'processWrites', [[$stream]]);
        $this->assertSame([], TestHelper::getProperty($runtime, 'write'));

        fclose($stream);
    }

    public function testProcessWriteStreamFailsWhenOffsetOutOfRange(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();

        $fiber = TestHelper::newSuspendedFiber();
        $watcher = new IoWatcher($stream, $fiber, 'data', 10);
        TestHelper::setProperty($runtime, 'write', [
            (int) $stream => $watcher,
        ]);

        TestHelper::callPrivate($runtime, 'processWriteStream', [$stream]);

        $this->assertSame([], TestHelper::getProperty($runtime, 'write'));
        $this->assertTrue($fiber->isTerminated());

        TestHelper::closeResource($stream);
    }

    public function testProcessWriteStreamFailsWhenOffsetEqualsLength(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();

        $fiber = TestHelper::newSuspendedFiber();
        $watcher = new IoWatcher($stream, $fiber, 'data', 4);
        TestHelper::setProperty($runtime, 'write', [
            (int) $stream => $watcher,
        ]);

        TestHelper::callPrivate($runtime, 'processWriteStream', [$stream]);

        $this->assertSame([], TestHelper::getProperty($runtime, 'write'));
        $this->assertTrue($fiber->isTerminated());

        TestHelper::closeResource($stream);
    }

    public function testSliceBufferHandlesZeroLength(): void
    {
        $runtime = new Runtime();

        $chunk = TestHelper::callPrivate($runtime, 'sliceBuffer', ['data', 0, 0]);

        $this->assertSame('', $chunk);
    }

    public function testSliceBufferHandlesOffsetBeyondBuffer(): void
    {
        $runtime = new Runtime();

        $chunk = TestHelper::callPrivate($runtime, 'sliceBuffer', ['data', 10, 1]);

        $this->assertSame('', $chunk);
    }

    public function testSliceBufferClampsLengthPastBuffer(): void
    {
        $runtime = new Runtime();

        $chunk = TestHelper::callPrivate($runtime, 'sliceBuffer', ['data', 0, 10]);

        $this->assertSame('data', $chunk);
    }

    public function testProcessWriteStreamThrowsOnStreamWriteException(): void
    {
        $runtime = new Runtime();
        ThrowingWriteStream::register();

        $stream = TestHelper::openStream(ThrowingWriteStream::uriFor('fail'), 'w');
        $watcher = new IoWatcher($stream, TestHelper::newSuspendedFiber(), 'data', 0);
        TestHelper::setProperty($runtime, 'write', [
            (int) $stream => $watcher,
        ]);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('stream_write failed');
            TestHelper::callPrivate($runtime, 'processWriteStream', [$stream]);
        } finally {
            TestHelper::closeResource($stream);
            ThrowingWriteStream::unregister();
        }
    }

    public function testProcessWriteStreamSkipsNonResource(): void
    {
        $runtime = new Runtime();

        TestHelper::callPrivate($runtime, 'processWriteStream', [null]);

        $this->assertSame([], TestHelper::getProperty($runtime, 'write'));
    }

    public function testProcessReadsHandlesMissingWatcher(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();

        TestHelper::callPrivate($runtime, 'processReads', [[$stream]]);
        $this->assertSame([], TestHelper::getProperty($runtime, 'read'));
        fclose($stream);
    }

    public function testProcessReadsHandlesNonListStreams(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();
        fwrite($stream, 'ping');
        rewind($stream);

        $state = new class {
            public ?string $received = null;
        };
        $fiber = TestHelper::newSuspendedFiber(static function (mixed $value) use ($state): void {
            $state->received = is_string($value) ? $value : null;
        });

        TestHelper::setProperty($runtime, 'read', [
            (int) $stream => new IoWatcher($stream, $fiber, '', 10),
        ]);

        TestHelper::callPrivate($runtime, 'processReads', [[5 => $stream]]);

        $this->assertSame('ping', $state->received);
        $this->assertSame([], TestHelper::getProperty($runtime, 'read'));

        TestHelper::closeResource($stream);
    }

    public function testProcessReadStreamSkipsNonResource(): void
    {
        $runtime = new Runtime();

        TestHelper::callPrivate($runtime, 'processReadStream', [null]);

        $this->assertSame([], TestHelper::getProperty($runtime, 'read'));
    }

    public function testProcessReadsHandlesEmptyStream(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();

        $state = new class {
            public ?string $received = null;
        };
        $fiber = TestHelper::newSuspendedFiber(static function (mixed $value) use ($state): void {
            $state->received = is_string($value) ? $value : null;
        });

        TestHelper::setProperty($runtime, 'read', [
            (int) $stream => new IoWatcher($stream, $fiber, '', 10),
        ]);

        TestHelper::callPrivate($runtime, 'processReads', [[$stream]]);

        $this->assertSame('', $state->received);
    }

    public function testProcessReadsHandlesEmptyChunkWithoutEof(): void
    {
        $runtime = new Runtime();
        EmptyReadStream::register();

        $stream = TestHelper::openStream('emptyread://stream', 'r');
        $fiber = TestHelper::newSuspendedFiber();

        TestHelper::setProperty($runtime, 'read', [
            (int) $stream => new IoWatcher($stream, $fiber, 'seed', 10),
        ]);

        TestHelper::callPrivate($runtime, 'processReads', [[$stream]]);

        /** @var array<int, IoWatcher> $readMap */
        $readMap = TestHelper::getProperty($runtime, 'read');
        $this->assertArrayHasKey((int) $stream, $readMap);
        $this->assertSame('seed', $readMap[(int) $stream]->buffer);
        $this->assertFalse($fiber->isTerminated());

        TestHelper::setProperty($runtime, 'read', []);
        TestHelper::closeResource($stream);
        EmptyReadStream::unregister();
    }

    public function testProcessReadsContinuesAfterMissingWatcher(): void
    {
        $runtime = new Runtime();
        $missing = TestHelper::openTempStream();
        $stream = TestHelper::openTempStream();

        fwrite($stream, 'ping');
        rewind($stream);

        $state = new class {
            public ?string $received = null;
        };
        $fiber = TestHelper::newSuspendedFiber(static function (mixed $value) use ($state): void {
            $state->received = is_string($value) ? $value : null;
        });

        $watcher = new IoWatcher($stream, $fiber, '', 10);
        TestHelper::setProperty($runtime, 'read', [
            (int) $stream => $watcher,
        ]);

        TestHelper::callPrivate($runtime, 'processReads', [[$missing, $stream]]);
        $this->assertSame('ping', $state->received);

        fclose($missing);
    }

    public function testProcessReadsHandlesReadFailure(): void
    {
        $runtime = new Runtime();
        FailingReadStream::register();
        $stream = TestHelper::openStream('failread://stream', 'r');

        $fiber = TestHelper::newSuspendedFiber();
        $watcher = new IoWatcher($stream, $fiber, '', 10);
        TestHelper::setProperty($runtime, 'read', [
            (int) $stream => $watcher,
        ]);

        TestHelper::callPrivate($runtime, 'processReads', [[$stream]]);
        $this->assertSame([], TestHelper::getProperty($runtime, 'read'));

        TestHelper::closeResource($stream);
        FailingReadStream::unregister();
    }

    public function testProcessReadsContinuesAfterReadFailure(): void
    {
        $runtime = new Runtime();
        FailingReadStream::register();

        $failed = TestHelper::openStream('failread://stream', 'r');
        $stream = TestHelper::openTempStream();
        fwrite($stream, 'ok');
        rewind($stream);

        $failedFiber = TestHelper::newSuspendedFiber();
        $failedWatcher = new IoWatcher($failed, $failedFiber, '', 10);

        $state = new class {
            public ?string $received = null;
        };
        $fiber = TestHelper::newSuspendedFiber(static function (mixed $value) use ($state): void {
            $state->received = is_string($value) ? $value : null;
        });
        $watcher = new IoWatcher($stream, $fiber, '', 10);

        TestHelper::setProperty($runtime, 'read', [
            (int) $failed => $failedWatcher,
            (int) $stream => $watcher,
        ]);

        TestHelper::callPrivate($runtime, 'processReads', [[$failed, $stream]]);
        $this->assertSame('ok', $state->received);

        TestHelper::closeResource($failed);
        FailingReadStream::unregister();
    }

    public function testProcessReadsHandlesResponseTooLarge(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();

        fwrite($stream, 'hello');
        rewind($stream);

        $fiber = TestHelper::newSuspendedFiber();
        $watcher = new IoWatcher($stream, $fiber, '', 3);
        TestHelper::setProperty($runtime, 'read', [
            (int) $stream => $watcher,
        ]);

        TestHelper::callPrivate($runtime, 'processReads', [[$stream]]);
        $this->assertSame([], TestHelper::getProperty($runtime, 'read'));
    }

    public function testProcessReadsContinuesAfterResponseTooLarge(): void
    {
        $runtime = new Runtime();
        $tooLarge = TestHelper::openTempStream();
        fwrite($tooLarge, 'hello');
        rewind($tooLarge);

        $okStream = TestHelper::openTempStream();
        fwrite($okStream, 'ok');
        rewind($okStream);

        $tooLargeFiber = TestHelper::newSuspendedFiber();
        $tooLargeWatcher = new IoWatcher($tooLarge, $tooLargeFiber, '', 3);

        $state = new class {
            public ?string $received = null;
        };
        $fiber = TestHelper::newSuspendedFiber(static function (mixed $value) use ($state): void {
            $state->received = is_string($value) ? $value : null;
        });
        $watcher = new IoWatcher($okStream, $fiber, '', 10);

        TestHelper::setProperty($runtime, 'read', [
            (int) $tooLarge => $tooLargeWatcher,
            (int) $okStream => $watcher,
        ]);

        TestHelper::callPrivate($runtime, 'processReads', [[$tooLarge, $okStream]]);
        $this->assertSame('ok', $state->received);
    }

    public function testProcessReadsAllowsExactMaxBytes(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();

        fwrite($stream, 'abc');
        rewind($stream);

        $state = new class {
            public ?string $received = null;
        };
        $fiber = TestHelper::newSuspendedFiber(static function (mixed $value) use ($state): void {
            $state->received = is_string($value) ? $value : null;
        });

        $watcher = new IoWatcher($stream, $fiber, '', 3);
        TestHelper::setProperty($runtime, 'read', [
            (int) $stream => $watcher,
        ]);

        TestHelper::callPrivate($runtime, 'processReads', [[$stream]]);
        $this->assertSame('abc', $state->received);
    }

    public function testProcessReadsResumesOnEof(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();

        fwrite($stream, 'data');
        rewind($stream);

        $state = new class {
            public ?string $received = null;
        };
        $fiber = TestHelper::newSuspendedFiber(static function (mixed $value) use ($state): void {
            $state->received = is_string($value) ? $value : null;
        });

        $watcher = new IoWatcher($stream, $fiber, '', 100);
        TestHelper::setProperty($runtime, 'read', [
            (int) $stream => $watcher,
        ]);

        TestHelper::callPrivate($runtime, 'processReads', [[$stream]]);
        $this->assertSame('data', $state->received);
    }

    public function testProcessReadsConcatsBufferInOrder(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();

        fwrite($stream, 'world');
        rewind($stream);

        $state = new class {
            public ?string $received = null;
        };
        $fiber = TestHelper::newSuspendedFiber(static function (mixed $value) use ($state): void {
            $state->received = is_string($value) ? $value : null;
        });

        $watcher = new IoWatcher($stream, $fiber, 'hello', 100);
        TestHelper::setProperty($runtime, 'read', [
            (int) $stream => $watcher,
        ]);

        TestHelper::callPrivate($runtime, 'processReads', [[$stream]]);
        $this->assertSame('helloworld', $state->received);
    }

    public function testProcessReadsContinuesAfterResumeOnEof(): void
    {
        $runtime = new Runtime();
        $first = TestHelper::openTempStream();
        fwrite($first, 'one');
        rewind($first);

        $second = TestHelper::openTempStream();
        fwrite($second, 'two');
        rewind($second);

        $state = new class {
            public ?string $first = null;
            public ?string $second = null;
        };
        $firstFiber = TestHelper::newSuspendedFiber(static function (mixed $value) use ($state): void {
            $state->first = is_string($value) ? $value : null;
        });
        $secondFiber = TestHelper::newSuspendedFiber(static function (mixed $value) use ($state): void {
            $state->second = is_string($value) ? $value : null;
        });

        TestHelper::setProperty($runtime, 'read', [
            (int) $first => new IoWatcher($first, $firstFiber, '', 10),
            (int) $second => new IoWatcher($second, $secondFiber, '', 10),
        ]);

        TestHelper::callPrivate($runtime, 'processReads', [[$first, $second]]);
        $this->assertSame('one', $state->first);
        $this->assertSame('two', $state->second);
    }

    public function testProcessReadsSkipsResumeWhenFiberTerminated(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();

        fwrite($stream, 'data');
        rewind($stream);

        $fiber = TestHelper::newTerminatedFiber();
        $watcher = new IoWatcher($stream, $fiber, '', 100);
        TestHelper::setProperty($runtime, 'read', [
            (int) $stream => $watcher,
        ]);

        TestHelper::callPrivate($runtime, 'processReads', [[$stream]]);
        $this->assertSame([], TestHelper::getProperty($runtime, 'read'));
        /** @psalm-suppress RedundantCondition */
        $this->assertFalse(is_resource($stream));
    }

    public function testProcessReadsUpdatesWatcherWhenNotEof(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();

        fwrite($stream, str_repeat('a', 9000));
        rewind($stream);

        $fiber = TestHelper::newSuspendedFiber();
        $watcher = new IoWatcher($stream, $fiber, '', 20000);
        TestHelper::setProperty($runtime, 'read', [
            (int) $stream => $watcher,
        ]);

        TestHelper::callPrivate($runtime, 'processReads', [[$stream]]);
        /** @var array<int, IoWatcher> $readMap */
        $readMap = TestHelper::getProperty($runtime, 'read');
        $this->assertSame(8192, strlen($readMap[(int) $stream]->buffer));

        TestHelper::setProperty($runtime, 'read', []);
        fclose($stream);
    }

    public function testFailWatcherThrowsIntoFiber(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();
        $state = new class {
            public ?string $message = null;
        };

        $fiber = new Fiber(static function () use ($state): void {
            try {
                Fiber::suspend();
            } catch (RuntimeException $e) {
                $state->message = $e->getMessage();
            }
        });
        $fiber->start();

        $watcher = new IoWatcher($stream, $fiber, '', 0);
        TestHelper::callPrivate($runtime, 'failWatcher', [$watcher, 'boom']);

        if (!is_string($state->message)) {
            $this->fail('Expected failure message');
        }
        /** @phan-suppress-next-line PhanPluginSuspiciousParamPosition */
        $this->assertSame('boom', $state->message);
        /** @psalm-suppress RedundantConditionGivenDocblockType */
        $this->assertFalse(is_resource($stream));
    }

    public function testFailReadThrowsIntoFiber(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();
        $state = new class {
            public ?string $message = null;
        };

        $fiber = new Fiber(static function () use ($state): void {
            try {
                Fiber::suspend();
            } catch (RuntimeException $e) {
                $state->message = $e->getMessage();
            }
        });
        $fiber->start();

        TestHelper::setProperty($runtime, 'read', [
            (int) $stream => new IoWatcher($stream, $fiber, '', 10),
        ]);

        TestHelper::callPrivate($runtime, 'failRead', [(int) $stream, 'read failed']);

        if (!is_string($state->message)) {
            $this->fail('Expected failure message');
        }
        /** @phan-suppress-next-line PhanPluginSuspiciousParamPosition */
        $this->assertSame('read failed', $state->message);
        $this->assertSame([], TestHelper::getProperty($runtime, 'read'));
        /** @psalm-suppress RedundantConditionGivenDocblockType */
        $this->assertFalse(is_resource($stream));
    }

    public function testFailWriteThrowsIntoFiber(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();
        $state = new class {
            public ?string $message = null;
        };

        $fiber = new Fiber(static function () use ($state): void {
            try {
                Fiber::suspend();
            } catch (RuntimeException $e) {
                $state->message = $e->getMessage();
            }
        });
        $fiber->start();

        TestHelper::setProperty($runtime, 'write', [
            (int) $stream => new IoWatcher($stream, $fiber, 'data', 0),
        ]);

        TestHelper::callPrivate($runtime, 'failWrite', [(int) $stream, 'write failed']);

        if (!is_string($state->message)) {
            $this->fail('Expected failure message');
        }
        /** @phan-suppress-next-line PhanPluginSuspiciousParamPosition */
        $this->assertSame('write failed', $state->message);
        $this->assertSame([], TestHelper::getProperty($runtime, 'write'));
        /** @psalm-suppress RedundantConditionGivenDocblockType */
        $this->assertFalse(is_resource($stream));
    }

    public function testFailReadWriteEarlyReturn(): void
    {
        $runtime = new Runtime();

        TestHelper::callPrivate($runtime, 'failRead', [123, 'nope']);
        TestHelper::callPrivate($runtime, 'failWrite', [456, 'nope']);
        $this->assertSame([], TestHelper::getProperty($runtime, 'read'));
        $this->assertSame([], TestHelper::getProperty($runtime, 'write'));
    }

    public function testCleanupWatchersRemovesMatchingFiber(): void
    {
        $runtime = new Runtime();
        $targetFiber = TestHelper::newSuspendedFiber();
        $otherFiber = TestHelper::newSuspendedFiber();

        $targetStream = TestHelper::openTempStream();
        $otherStream = TestHelper::openTempStream();

        $watchers = [
            (int) $otherStream => new IoWatcher($otherStream, $otherFiber, '', 0),
            (int) $targetStream => new IoWatcher($targetStream, $targetFiber, '', 0),
        ];

        /** @var array<int, IoWatcher> $cleaned */
        $cleaned = TestHelper::callPrivate($runtime, 'cleanupWatchers', [$watchers, $targetFiber]);
        $this->assertArrayHasKey((int) $otherStream, $cleaned);
        $this->assertArrayNotHasKey((int) $targetStream, $cleaned);
        /** @psalm-suppress RedundantConditionGivenDocblockType */
        $this->assertFalse(is_resource($targetStream));

        TestHelper::closeResource($otherStream);
    }

    public function testCleanupWatchersSkipsWhenNoMatch(): void
    {
        $runtime = new Runtime();
        $targetFiber = TestHelper::newSuspendedFiber();
        $otherFiber = TestHelper::newSuspendedFiber();

        $stream = TestHelper::openTempStream();
        $watchers = [
            (int) $stream => new IoWatcher($stream, $otherFiber, '', 0),
        ];

        /** @var array<int, IoWatcher> $cleaned */
        $cleaned = TestHelper::callPrivate($runtime, 'cleanupWatchers', [$watchers, $targetFiber]);
        $this->assertArrayHasKey((int) $stream, $cleaned);

        TestHelper::closeResource($stream);
    }

    public function testCollectStreamsReturnsAllResources(): void
    {
        $runtime = new Runtime();
        $streamA = TestHelper::openTempStream();
        $streamB = TestHelper::openTempStream();

        $fiber = TestHelper::newSuspendedFiber();
        $watchers = [
            (int) $streamA => new IoWatcher($streamA, $fiber, '', 0),
            (int) $streamB => new IoWatcher($streamB, $fiber, '', 0),
        ];

        /** @var list<resource> $streams */
        $streams = TestHelper::callPrivate($runtime, 'collectStreams', [$watchers]);
        $this->assertCount(2, $streams);
        $this->assertTrue(in_array($streamA, $streams, true));
        $this->assertTrue(in_array($streamB, $streams, true));

        fclose($streamA);
        fclose($streamB);
    }

    public function testCollectStreamsSkipsClosedResources(): void
    {
        $runtime = new Runtime();
        $streamA = TestHelper::openTempStream();
        $streamB = TestHelper::openTempStream();
        fclose($streamB);

        $fiber = TestHelper::newSuspendedFiber();
        $watchers = [
            (int) $streamA => new IoWatcher($streamA, $fiber, '', 0),
            (int) $streamB => new IoWatcher($streamB, $fiber, '', 0),
        ];

        /** @var list<resource> $streams */
        $streams = TestHelper::callPrivate($runtime, 'collectStreams', [$watchers]);
        $this->assertCount(1, $streams);
        $this->assertSame($streamA, $streams[0]);

        fclose($streamA);
    }

    public function testCollectStreamsHandlesEmptyWatchers(): void
    {
        $runtime = new Runtime();

        /** @var list<resource> $streams */
        $streams = TestHelper::callPrivate($runtime, 'collectStreams', [[]]);
        $this->assertSame([], $streams);
    }

    public function testFailWatcherWithTerminatedFiber(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();

        $fiber = TestHelper::newTerminatedFiber();
        $watcher = new IoWatcher($stream, $fiber, '', 0);

        TestHelper::callPrivate($runtime, 'failWatcher', [$watcher, 'failed']);
        /** @psalm-suppress RedundantConditionGivenDocblockType */
        $this->assertFalse(is_resource($stream));
    }

    public function testProcessTimersResumesDueTimers(): void
    {
        $runtime = new Runtime();
        $suspended = TestHelper::newSuspendedFiber();
        $terminated = TestHelper::newTerminatedFiber();
        $future = TestHelper::newSuspendedFiber();

        $now = microtime(true);
        $timers = [
            new Timer($now - 1.0, $terminated),
            new Timer($now - 1.0, $suspended),
            new Timer($now + 1.0, $future),
        ];

        TestHelper::setProperty($runtime, 'timers', $timers);

        $next = TestHelper::callPrivate($runtime, 'processTimers');

        $this->assertTrue($suspended->isTerminated());
        $this->assertIsFloat($next);
    }

    public function testProcessTimersReturnsNearestFutureTimer(): void
    {
        $runtime = new Runtime();
        $futureA = TestHelper::newSuspendedFiber();
        $futureB = TestHelper::newSuspendedFiber();

        $now = microtime(true);
        TestHelper::setProperty($runtime, 'timers', [
            new Timer($now + 5.0, $futureA),
            new Timer($now + 1.0, $futureB),
        ]);

        $next = TestHelper::callPrivate($runtime, 'processTimers');
        $this->assertIsFloat($next);
        $this->assertLessThanOrEqual($now + 1.0, $next);
    }

    public function testProcessTimersHandlesTwoFutureTimers(): void
    {
        $runtime = new Runtime();
        $futureA = TestHelper::newSuspendedFiber();
        $futureB = TestHelper::newSuspendedFiber();
        $now = 1000.0;

        TimeStub::freeze($now);

        TestHelper::setProperty($runtime, 'timers', [
            new Timer($now + 2.0, $futureA),
            new Timer($now + 1.0, $futureB),
        ]);

        $next = TestHelper::callPrivate($runtime, 'processTimers');
        $this->assertSame($now + 1.0, $next);
        /** @var array<int, Timer> $timers */
        $timers = TestHelper::getProperty($runtime, 'timers');
        $this->assertCount(2, $timers);
    }

    public function testProcessTimersHandlesThreeFutureTimers(): void
    {
        $runtime = new Runtime();
        $futureA = TestHelper::newSuspendedFiber();
        $futureB = TestHelper::newSuspendedFiber();
        $futureC = TestHelper::newSuspendedFiber();
        $now = 1000.0;

        TimeStub::freeze($now);

        TestHelper::setProperty($runtime, 'timers', [
            new Timer($now + 3.0, $futureA),
            new Timer($now + 2.0, $futureB),
            new Timer($now + 1.0, $futureC),
        ]);

        $next = TestHelper::callPrivate($runtime, 'processTimers');
        $this->assertSame($now + 1.0, $next);
        /** @var array<int, Timer> $timers */
        $timers = TestHelper::getProperty($runtime, 'timers');
        $this->assertCount(3, $timers);
    }

    public function testProcessTimersHandlesSingleFutureTimer(): void
    {
        $runtime = new Runtime();
        $future = TestHelper::newSuspendedFiber();
        $now = 1000.0;

        TimeStub::freeze($now);

        TestHelper::setProperty($runtime, 'timers', [
            new Timer($now + 5.0, $future),
        ]);

        $next = TestHelper::callPrivate($runtime, 'processTimers');
        $this->assertSame($now + 5.0, $next);
        /** @var array<int, Timer> $timers */
        $timers = TestHelper::getProperty($runtime, 'timers');
        $this->assertCount(1, $timers);
    }

    public function testProcessTimersReturnsNullWhenEmpty(): void
    {
        $runtime = new Runtime();

        $next = TestHelper::callPrivate($runtime, 'processTimers');
        $this->assertNull($next);
    }

    public function testProcessTimersResumesTimersAtExactNow(): void
    {
        $runtime = new Runtime();
        $fiber = TestHelper::newSuspendedFiber();
        $now = 1000.0;

        TimeStub::freeze($now);

        TestHelper::setProperty($runtime, 'timers', [
            new Timer($now, $fiber),
        ]);

        TestHelper::callPrivate($runtime, 'processTimers');

        $this->assertTrue($fiber->isTerminated());
        $this->assertSame([], TestHelper::getProperty($runtime, 'timers'));
    }

    public function testProcessTimersHandlesDueThenFuture(): void
    {
        $runtime = new Runtime();
        $due = TestHelper::newSuspendedFiber();
        $future = TestHelper::newSuspendedFiber();
        $now = 1000.0;

        TimeStub::freeze($now);

        TestHelper::setProperty($runtime, 'timers', [
            new Timer($now - 1.0, $due),
            new Timer($now + 1.0, $future),
        ]);

        $next = TestHelper::callPrivate($runtime, 'processTimers');

        $this->assertTrue($due->isTerminated());
        $this->assertSame($now + 1.0, $next);
    }

    public function testProcessTimersHandlesFutureBeforeDue(): void
    {
        $runtime = new Runtime();
        $due = TestHelper::newSuspendedFiber();
        $future = TestHelper::newSuspendedFiber();
        $now = 1000.0;

        TimeStub::freeze($now);

        TestHelper::setProperty($runtime, 'timers', [
            new Timer($now + 2.0, $future),
            new Timer($now - 1.0, $due),
        ]);

        $next = TestHelper::callPrivate($runtime, 'processTimers');

        $this->assertTrue($due->isTerminated());
        $this->assertSame($now + 2.0, $next);
    }

    public function testProcessTimersHandlesMixedOrder(): void
    {
        $runtime = new Runtime();
        $now = 1000.0;

        TimeStub::freeze($now);

        $dueA = TestHelper::newSuspendedFiber();
        $dueB = TestHelper::newTerminatedFiber();
        $dueC = TestHelper::newSuspendedFiber();

        $futureA = TestHelper::newSuspendedFiber();
        $futureB = TestHelper::newSuspendedFiber();
        $futureC = TestHelper::newSuspendedFiber();
        $futureD = TestHelper::newSuspendedFiber();
        $futureE = TestHelper::newSuspendedFiber();
        $futureF = TestHelper::newSuspendedFiber();

        TestHelper::setProperty($runtime, 'timers', [
            new Timer($now - 1.0, $dueA),
            new Timer($now + 5.0, $futureA),
            new Timer($now + 4.0, $futureB),
            new Timer($now + 3.0, $futureC),
            new Timer($now + 2.0, $futureD),
            new Timer($now - 2.0, $dueB),
            new Timer($now + 6.0, $futureE),
            new Timer($now - 3.0, $dueC),
            new Timer($now + 1.0, $futureF),
        ]);

        $next = TestHelper::callPrivate($runtime, 'processTimers');

        $this->assertTrue($dueA->isTerminated());
        $this->assertTrue($dueC->isTerminated());
        $this->assertSame($now + 1.0, $next);
    }

    public function testProcessTimerKeepsNearestNext(): void
    {
        $runtime = new Runtime();
        $fiber = TestHelper::newSuspendedFiber();
        $now = 1000.0;

        $timer = new Timer($now + 5.0, $fiber);

        $this->assertSame(
            $now + 5.0,
            TestHelper::callPrivate($runtime, 'processTimer', [0, $timer, $now, null]),
        );

        $this->assertSame(
            $now + 1.0,
            TestHelper::callPrivate($runtime, 'processTimer', [0, $timer, $now, $now + 1.0]),
        );

        $this->assertSame(
            $now + 5.0,
            TestHelper::callPrivate($runtime, 'processTimer', [0, $timer, $now, $now + 5.0]),
        );

        $this->assertSame(
            $now + 5.0,
            TestHelper::callPrivate($runtime, 'processTimer', [0, $timer, $now, $now + 10.0]),
        );
    }

    public function testRemoveTimersForFiberSkipsWhenNoMatch(): void
    {
        $runtime = new Runtime();
        $target = TestHelper::newSuspendedFiber();
        $other = TestHelper::newSuspendedFiber();

        TestHelper::setProperty($runtime, 'timers', [
            new Timer(microtime(true) + 1.0, $other),
        ]);

        TestHelper::callPrivate($runtime, 'removeTimersForFiber', [$target]);

        /** @var array<int, Timer> $timers */
        $timers = TestHelper::getProperty($runtime, 'timers');
        $this->assertCount(1, $timers);
        $key = array_key_first($timers);
        $this->assertNotNull($key);
        $this->assertSame($other, $timers[$key]->fiber);
    }

    public function testTickReturnsWhenNoIoOrTimers(): void
    {
        $runtime = new Runtime();

        TestHelper::callPrivate($runtime, 'tick');

        $this->assertSame([], TestHelper::getProperty($runtime, 'read'));
        $this->assertSame([], TestHelper::getProperty($runtime, 'write'));
        $this->assertSame([], TestHelper::getProperty($runtime, 'timers'));
    }

    public function testTickSleepsUntilTimer(): void
    {
        $runtime = new Runtime();
        $future = TestHelper::newSuspendedFiber();
        SleepStub::force();

        TestHelper::setProperty($runtime, 'timers', [
            new Timer(microtime(true) + 0.05, $future),
        ]);

        TestHelper::callPrivate($runtime, 'tick');
        $this->assertGreaterThan(0, SleepStub::callCount());
        $last = SleepStub::lastMicroseconds();
        $this->assertNotNull($last);
        $this->assertGreaterThan(0, $last);
        /** @var array<int, Timer> $timers */
        $timers = TestHelper::getProperty($runtime, 'timers');
        $this->assertCount(1, $timers);
    }

    public function testTickSkipsSleepWhenTimeCatchesUp(): void
    {
        $runtime = new Runtime();
        $future = TestHelper::newSuspendedFiber();

        TimeStub::queue(1000.0, 1000.5);
        SleepStub::force();

        TestHelper::setProperty($runtime, 'timers', [
            new Timer(1000.5, $future),
        ]);

        TestHelper::callPrivate($runtime, 'tick');

        $this->assertSame(0, SleepStub::callCount());
        /** @var array<int, Timer> $timers */
        $timers = TestHelper::getProperty($runtime, 'timers');
        $this->assertCount(1, $timers);
    }

    public function testTickSleepsWhenDurationPositive(): void
    {
        $runtime = new Runtime();
        $future = TestHelper::newSuspendedFiber();

        TimeStub::freeze(1000.0);
        SleepStub::force();

        TestHelper::setProperty($runtime, 'timers', [
            new Timer(1000.1, $future),
        ]);

        TestHelper::callPrivate($runtime, 'tick');

        $this->assertGreaterThan(0, SleepStub::callCount());
    }

    public function testTickWaitsForIo(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();
        fwrite($stream, 'ping');
        rewind($stream);
        $writeStream = TestHelper::openTempStream();
        $writeFiber = TestHelper::newSuspendedFiber();

        $state = new class {
            public ?string $received = null;
        };
        $fiber = TestHelper::newSuspendedFiber(static function (mixed $value) use ($state): void {
            $state->received = is_string($value) ? $value : null;
        });
        $watcher = new IoWatcher($stream, $fiber, '', 10);
        TestHelper::setProperty($runtime, 'read', [
            (int) $stream => $watcher,
        ]);
        TestHelper::setProperty($runtime, 'write', [
            (int) $writeStream => new IoWatcher($writeStream, $writeFiber, 'pong', 0),
        ]);
        SelectStub::forceResult(2, [$stream], [$writeStream]);

        TestHelper::callPrivate($runtime, 'tick');
        $this->assertSame('ping', $state->received);
        $this->assertTrue($writeFiber->isTerminated());
        TestHelper::setProperty($runtime, 'read', []);
        TestHelper::setProperty($runtime, 'write', []);
        TestHelper::closeResource($stream);
        TestHelper::closeResource($writeStream);
    }

    public function testTickSkipsSleepWhenReadWatcherPresent(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();
        $fiber = TestHelper::newSuspendedFiber();

        TestHelper::setProperty($runtime, 'read', [
            (int) $stream => new IoWatcher($stream, $fiber, '', 10),
        ]);
        TestHelper::setProperty($runtime, 'timers', [
            new Timer(microtime(true) + 1.0, $fiber),
        ]);

        SleepStub::reset();
        SleepStub::force();
        SelectStub::forceResult(0);

        TestHelper::callPrivate($runtime, 'tick');

        $this->assertSame(0, SleepStub::callCount());
        TestHelper::setProperty($runtime, 'read', []);
        TestHelper::setProperty($runtime, 'timers', []);
        fclose($stream);
    }

    public function testTickSkipsSleepWhenWriteWatcherPresent(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();
        $fiber = TestHelper::newSuspendedFiber();

        TestHelper::setProperty($runtime, 'write', [
            (int) $stream => new IoWatcher($stream, $fiber, 'data', 0),
        ]);
        TestHelper::setProperty($runtime, 'timers', [
            new Timer(microtime(true) + 1.0, $fiber),
        ]);

        SleepStub::reset();
        SleepStub::force();
        SelectStub::forceResult(0);

        TestHelper::callPrivate($runtime, 'tick');

        $this->assertSame(0, SleepStub::callCount());
        TestHelper::setProperty($runtime, 'write', []);
        TestHelper::setProperty($runtime, 'timers', []);
        fclose($stream);
    }

    public function testWaitForIoTimeoutAndReady(): void
    {
        $runtime = new Runtime();
        $readyStream = TestHelper::openTempStream();
        fwrite($readyStream, 'ready');
        rewind($readyStream);
        $writeStream = TestHelper::openTempStream();
        $writeFiber = TestHelper::newSuspendedFiber();

        $state = new class {
            public ?string $received = null;
        };
        $readFiber = TestHelper::newSuspendedFiber(static function (mixed $value) use ($state): void {
            $state->received = is_string($value) ? $value : null;
        });

        TestHelper::setProperty($runtime, 'read', [
            (int) $readyStream => new IoWatcher($readyStream, $readFiber, '', 100),
        ]);
        TestHelper::setProperty($runtime, 'write', [
            (int) $writeStream => new IoWatcher($writeStream, $writeFiber, 'pong', 0),
        ]);

        SelectStub::forceResult(2, [$readyStream], [$writeStream]);
        TestHelper::callPrivate($runtime, 'waitForIo', [null]);
        $this->assertSame('ready', $state->received);
        $this->assertTrue($writeFiber->isTerminated());
        TestHelper::setProperty($runtime, 'write', []);
        TestHelper::closeResource($writeStream);

        $timeoutStream = TestHelper::openTempStream();
        $timeoutFiber = TestHelper::newSuspendedFiber();
        TestHelper::setProperty($runtime, 'read', [
            (int) $timeoutStream => new IoWatcher($timeoutStream, $timeoutFiber, '', 100),
        ]);

        SelectStub::forceResult(0);
        TestHelper::callPrivate($runtime, 'waitForIo', [microtime(true)]);

        TestHelper::setProperty($runtime, 'read', []);
        fclose($timeoutStream);
    }

    public function testWaitForIoReturnsWhenSelectFails(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();
        stream_set_blocking($stream, false);
        $fiber = TestHelper::newSuspendedFiber();

        TestHelper::setProperty($runtime, 'read', [
            (int) $stream => new IoWatcher($stream, $fiber, '', 10),
        ]);

        SelectStub::forceResult(false);
        TestHelper::callPrivate($runtime, 'waitForIo', [null]);

        $this->assertFalse($fiber->isTerminated());
        TestHelper::setProperty($runtime, 'read', []);
        fclose($stream);
    }

    public function testSelectStreamsComputesShortTimeout(): void
    {
        SelectStub::forceResult(0);
        TimeStub::freeze(1000.0);
        $nextTimerAt = 1000.25;

        $runtime = new Runtime();
        TestHelper::callPrivate($runtime, 'selectStreams', [[], [], $nextTimerAt]);

        $timeout = SelectStub::lastTimeout();
        if ($timeout['microseconds'] === null) {
            $this->fail('Expected microseconds');
        }
        $this->assertSame(0, $timeout['seconds']);
        $this->assertSame(250_000, $timeout['microseconds']);
    }

    public function testSelectStreamsComputesLongTimeout(): void
    {
        SelectStub::forceResult(0);
        $nextTimerAt = microtime(true) + 2.0;

        $runtime = new Runtime();
        TestHelper::callPrivate($runtime, 'selectStreams', [[], [], $nextTimerAt]);

        $timeout = SelectStub::lastTimeout();
        if ($timeout['seconds'] === null || $timeout['microseconds'] === null) {
            $this->fail('Expected timeout values');
        }
        $this->assertGreaterThanOrEqual(1, $timeout['seconds']);
        $this->assertLessThan(1_000_000, $timeout['microseconds']);
    }

    public function testSelectStreamsComputesWholeSecondTimeout(): void
    {
        SelectStub::forceResult(0);
        TimeStub::freeze(1000.0);
        $nextTimerAt = 1002.0;

        $runtime = new Runtime();
        TestHelper::callPrivate($runtime, 'selectStreams', [[], [], $nextTimerAt]);

        $timeout = SelectStub::lastTimeout();
        $this->assertSame(2, $timeout['seconds']);
        $this->assertSame(0, $timeout['microseconds']);
    }

    public function testSelectStreamsWithoutTimerUsesNullTimeout(): void
    {
        SelectStub::forceResult(0);

        $runtime = new Runtime();
        TestHelper::callPrivate($runtime, 'selectStreams', [[], [], null]);

        $timeout = SelectStub::lastTimeout();
        $this->assertNull($timeout['seconds']);
        $this->assertNull($timeout['microseconds']);
    }

    public function testSelectStreamsClampsPastTimeout(): void
    {
        SelectStub::forceResult(0);
        TimeStub::freeze(1000.0);

        $runtime = new Runtime();
        TestHelper::callPrivate($runtime, 'selectStreams', [[], [], 999.0]);

        $timeout = SelectStub::lastTimeout();
        $this->assertSame(0, $timeout['seconds']);
        $this->assertSame(0, $timeout['microseconds']);
    }

    public function testSelectStreamsComputesZeroTimeoutAtExactNow(): void
    {
        SelectStub::forceResult(0);
        TimeStub::freeze(1000.0);

        $runtime = new Runtime();
        TestHelper::callPrivate($runtime, 'selectStreams', [[], [], 1000.0]);

        $timeout = SelectStub::lastTimeout();
        $this->assertSame(0, $timeout['seconds']);
        $this->assertSame(0, $timeout['microseconds']);
    }

    public function testCancelFiberCleansUpWatchersAndTimers(): void
    {
        $runtime = new Runtime();

        $targetFiber = TestHelper::newSuspendedFiber();
        $otherFiber = TestHelper::newSuspendedFiber();

        $parentTask = new Task($runtime);
        $parentTask->setFiber($targetFiber);

        $childTask = new Task($runtime);
        $childFiber = TestHelper::newSuspendedFiber();
        $childTask->setFiber($childFiber);
        $parentTask->addChild($childTask);

        $map = TestHelper::getProperty($runtime, 'fiberToTask');
        if (!$map instanceof WeakMap) {
            $this->fail('Expected WeakMap');
        }
        $map[$targetFiber] = $parentTask;
        $map[$childFiber] = $childTask;

        $readTarget = TestHelper::openTempStream();
        $readOther = TestHelper::openTempStream();
        $writeTarget = TestHelper::openTempStream();

        TestHelper::setProperty($runtime, 'read', [
            (int) $readTarget => new IoWatcher($readTarget, $targetFiber, '', 10),
            (int) $readOther => new IoWatcher($readOther, $otherFiber, '', 10),
        ]);
        TestHelper::setProperty($runtime, 'write', [
            (int) $writeTarget => new IoWatcher($writeTarget, $targetFiber, 'data', 0),
        ]);
        TestHelper::setProperty($runtime, 'timers', [
            new Timer(microtime(true) + 10.0, $targetFiber),
            new Timer(microtime(true) + 10.0, $otherFiber),
        ]);

        $runtime->cancelFiber($targetFiber);

        /** @var array<int, IoWatcher> $readMap */
        $readMap = TestHelper::getProperty($runtime, 'read');
        $this->assertArrayHasKey((int) $readOther, $readMap);
        $this->assertArrayNotHasKey((int) $readTarget, $readMap);
        /** @psalm-suppress RedundantConditionGivenDocblockType */
        $this->assertTrue(is_resource($readOther));
        /** @psalm-suppress RedundantConditionGivenDocblockType */
        $this->assertFalse(is_resource($readTarget));

        /** @var array<int, IoWatcher> $writeMap */
        $writeMap = TestHelper::getProperty($runtime, 'write');
        $this->assertSame([], $writeMap);
        /** @psalm-suppress RedundantConditionGivenDocblockType */
        $this->assertFalse(is_resource($writeTarget));

        /** @var array<int, Timer> $timersRaw */
        $timersRaw = TestHelper::getProperty($runtime, 'timers');
        $timers = array_values($timersRaw);
        $this->assertCount(1, $timers);
        $this->assertSame($otherFiber, $timers[0]->fiber);
        $this->assertTrue($childFiber->isTerminated());

        $runtime->cancelFiber($otherFiber);
    }

    public function testCancelFiberHandlesParentWithoutChildren(): void
    {
        $runtime = new Runtime();
        $fiber = TestHelper::newSuspendedFiber();

        $parentTask = new Task($runtime);
        $parentTask->setFiber($fiber);

        $map = TestHelper::getProperty($runtime, 'fiberToTask');
        if (!$map instanceof WeakMap) {
            $this->fail('Expected WeakMap');
        }
        $map[$fiber] = $parentTask;

        $runtime->cancelFiber($fiber);
        $this->assertTrue($fiber->isTerminated());
    }

    public function testCancelFiberReturnsEarlyForTerminatedFiber(): void
    {
        $runtime = new Runtime();
        $fiber = TestHelper::newTerminatedFiber();

        $runtime->cancelFiber($fiber);
        $this->assertTrue($fiber->isTerminated());
    }

    public function testCancelFiberThrowsIntoHandledFiber(): void
    {
        $runtime = new Runtime();
        $state = new class {
            public string $message = '';
        };

        $fiber = new Fiber(static function () use ($state): void {
            try {
                Fiber::suspend();
            } catch (RuntimeException $e) {
                $state->message = $e->getMessage();
            }
        });
        $fiber->start();

        $runtime->cancelFiber($fiber);

        /** @phan-suppress-next-line PhanPluginSuspiciousParamPosition */
        $this->assertSame('Task cancelled', $state->message);
    }

    public function testCloseStreamNoopsWhenNotResource(): void
    {
        $runtime = new Runtime();

        $result = TestHelper::callPrivate($runtime, 'closeStream', ['nope']);
        $this->assertNull($result);
    }

    public function testIgnoreErrorAlwaysReturnsTrue(): void
    {
        $runtime = new Runtime();

        $this->assertTrue(TestHelper::callPrivate($runtime, 'ignoreError', [E_WARNING, 'oops']));
    }

    public function testSuppressWarningsHandlesExceptions(): void
    {
        $runtime = new Runtime();

        $result = TestHelper::callPrivate($runtime, 'suppressWarnings', [static fn(): int => 7]);
        $this->assertSame(7, $result);

        try {
            TestHelper::callPrivate($runtime, 'suppressWarnings', [static function (): never {
                throw new RuntimeException('boom');
            }]);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
    }
}
