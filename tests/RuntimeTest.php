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
use Krvh\MinimalPhpAsync\Tests\Support\FailingReadStream;
use Krvh\MinimalPhpAsync\Tests\Support\SelectStub;
use Krvh\MinimalPhpAsync\Tests\Support\TestHelper;
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

        $this->expectException(RuntimeException::class);
        TestHelper::withTimeout(1, static function () use ($runtime): void {
            $runtime->drive(static fn(): bool => false);
        });
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

    public function testTaskForFiberReturnsTask(): void
    {
        $runtime = new Runtime();
        $task = $runtime->queue(static fn(): int => 1);

        $fiber = $task->getFiber();
        $this->assertInstanceOf(Fiber::class, $fiber);

        $found = TestHelper::callPrivate($runtime, 'taskForFiber', [$fiber]);
        $this->assertSame($task, $found);
    }

    public function testTaskForFiberReturnsNullForUnknown(): void
    {
        $runtime = new Runtime();
        $unknown = TestHelper::newSuspendedFiber();

        $this->assertNull(TestHelper::callPrivate($runtime, 'taskForFiber', [$unknown]));
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

    public function testWriteEmptyIsNoop(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();

        $runtime->write($stream, '');
        $this->assertSame([], TestHelper::getProperty($runtime, 'write'));
        fclose($stream);
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

    public function testProcessWritesHandlesMissingWatcher(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();

        TestHelper::callPrivate($runtime, 'processWrites', [[$stream]]);
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

    public function testProcessWritesHandlesZeroProgress(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();

        $fiber = TestHelper::newSuspendedFiber();
        $watcher = new IoWatcher($stream, $fiber, '', 0);

        TestHelper::setProperty($runtime, 'write', [
            (int) $stream => $watcher,
        ]);

        TestHelper::callPrivate($runtime, 'processWrites', [[$stream]]);

        /** @var array<int, IoWatcher> $writeMap */
        $writeMap = TestHelper::getProperty($runtime, 'write');
        $this->assertArrayHasKey((int) $stream, $writeMap);

        TestHelper::setProperty($runtime, 'write', []);
        fclose($stream);
    }

    public function testProcessWritesContinuesAfterZeroProgress(): void
    {
        $runtime = new Runtime();
        $stalled = TestHelper::openTempStream();
        $stream = TestHelper::openTempStream();

        $stalledFiber = TestHelper::newSuspendedFiber();
        $stalledWatcher = new IoWatcher($stalled, $stalledFiber, '', 0);

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

        TestHelper::setProperty($runtime, 'write', []);
        fclose($stalled);
        fclose($stream);
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

    public function testProcessReadsHandlesMissingWatcher(): void
    {
        $runtime = new Runtime();
        $stream = TestHelper::openTempStream();

        TestHelper::callPrivate($runtime, 'processReads', [[$stream]]);
        $this->assertSame([], TestHelper::getProperty($runtime, 'read'));
        fclose($stream);
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

        TestHelper::setProperty($runtime, 'timers', [
            new Timer(microtime(true) + 0.01, $future),
        ]);

        TestHelper::callPrivate($runtime, 'tick');
        /** @var array<int, Timer> $timers */
        $timers = TestHelper::getProperty($runtime, 'timers');
        $this->assertCount(1, $timers);
    }

    public function testTickWaitsForIo(): void
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
        $watcher = new IoWatcher($stream, $fiber, '', 10);
        TestHelper::setProperty($runtime, 'read', [
            (int) $stream => $watcher,
        ]);

        TestHelper::callPrivate($runtime, 'tick');
        $this->assertSame('ping', $state->received);
    }

    public function testWaitForIoTimeoutAndReady(): void
    {
        $runtime = new Runtime();
        $readyStream = TestHelper::openTempStream();
        fwrite($readyStream, 'ready');
        rewind($readyStream);

        $state = new class {
            public ?string $received = null;
        };
        $readFiber = TestHelper::newSuspendedFiber(static function (mixed $value) use ($state): void {
            $state->received = is_string($value) ? $value : null;
        });

        TestHelper::setProperty($runtime, 'read', [
            (int) $readyStream => new IoWatcher($readyStream, $readFiber, '', 100),
        ]);
        TestHelper::setProperty($runtime, 'write', []);

        TestHelper::callPrivate($runtime, 'waitForIo', [null]);
        $this->assertSame('ready', $state->received);

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

    public function testSelectStreamsComputesShortTimeout(): void
    {
        SelectStub::forceResult(0);
        $nextTimerAt = microtime(true) + 0.25;

        $runtime = new Runtime();
        TestHelper::callPrivate($runtime, 'selectStreams', [[], [], $nextTimerAt]);

        $timeout = SelectStub::lastTimeout();
        if ($timeout['microseconds'] === null) {
            $this->fail('Expected microseconds');
        }
        $this->assertSame(0, $timeout['seconds']);
        $this->assertLessThan(1_000_000, $timeout['microseconds']);
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

    public function testCancelFiberReturnsEarlyForTerminatedFiber(): void
    {
        $runtime = new Runtime();
        $fiber = TestHelper::newTerminatedFiber();

        $runtime->cancelFiber($fiber);
        $this->assertTrue($fiber->isTerminated());
    }
}
