<?php

/**
 * @phan-file-suppress PhanAccessMethodInternal
 * @phan-file-suppress PhanUnreferencedClass
 * @phan-file-suppress PhanUnreferencedClosure
 */

declare(strict_types=1);

namespace Krvh\MinimalPhpAsync\Tests;

use Fiber;
use Krvh\MinimalPhpAsync\IoWatcher;
use Krvh\MinimalPhpAsync\Runtime;
use Krvh\MinimalPhpAsync\Task;
use Krvh\MinimalPhpAsync\Tests\Support\AsyncTestCase;
use Krvh\MinimalPhpAsync\Tests\Support\TestHelper;
use Krvh\MinimalPhpAsync\Timer;
use LogicException;
use RuntimeException;

/** @psalm-suppress UnusedClass */
final class TaskTest extends AsyncTestCase
{
    public function testAwaitThrowsWhenUninitialized(): void
    {
        $runtime = new Runtime();
        $task = new Task($runtime);

        $this->expectException(LogicException::class);
        TestHelper::withTimeout(1, static fn(): mixed => $task->await());
    }

    public function testSetFiberTwiceThrows(): void
    {
        $runtime = new Runtime();
        $task = new Task($runtime);
        $fiber = new Fiber(static function (): void {
        });

        $task->setFiber($fiber);

        $this->expectException(LogicException::class);
        $task->setFiber($fiber);
    }

    public function testAwaitFromRootDrivesRuntime(): void
    {
        $runtime = new Runtime();
        $task = $runtime->queue(static function () use ($runtime): int {
            $runtime->delay(0.0);
            return 123;
        });

        $this->assertSame(123, TestHelper::withTimeout(1, static fn(): mixed => $task->await()));
    }

    public function testAwaitInsideFiberSuspendsAndResumes(): void
    {
        $runtime = new Runtime();
        $task = $runtime->queue(static function () use ($runtime): string {
            $child = $runtime->queue(static function () use ($runtime): string {
                $runtime->delay(0.0);
                return 'child';
            });

            return $child->await() . '-parent';
        });

        $this->assertSame('child-parent', TestHelper::withTimeout(1, static fn(): mixed => $task->await()));
    }

    public function testCircularAwaitDetected(): void
    {
        $runtime = new Runtime();
        $task = new Task($runtime);
        $fiber = new Fiber(static function () use ($task): void {
            $task->await();
        });
        $task->setFiber($fiber);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Circular await detected');
        $fiber->start();
    }

    public function testTaskRejectRethrows(): void
    {
        $runtime = new Runtime();
        $task = $runtime->queue(static function (): mixed {
            throw new RuntimeException('fail');
        });

        $this->expectException(RuntimeException::class);
        TestHelper::withTimeout(1, static function () use ($task): void {
            $task->await();
        });
    }

    public function testAwaitFallsBackToFiberReturnWhenUnresolved(): void
    {
        $runtime = new Runtime();
        $task = new Task($runtime);
        $fiber = new Fiber(static fn(): string => 'direct');
        $task->setFiber($fiber);

        $fiber->start();

        $this->assertSame('direct', TestHelper::withTimeout(1, static fn(): mixed => $task->await()));
    }

    public function testAwaitDetectsResolvedResultMismatch(): void
    {
        $runtime = new Runtime();
        $task = $runtime->queue(static fn(): string => 'ok');

        $this->assertSame('ok', TestHelper::withTimeout(1, static fn(): mixed => $task->await()));

        TestHelper::setPropertyValue($task, 'result', 'corrupt');

        $this->expectException(LogicException::class);
        TestHelper::withTimeout(1, static fn(): mixed => $task->await());
    }

    public function testCancelCancelsFiber(): void
    {
        $runtime = new Runtime();
        $task = $runtime->queue(static function () use ($runtime): mixed {
            $runtime->delay(0.1);
            return null;
        });

        $task->cancel();

        $this->expectException(RuntimeException::class);
        TestHelper::withTimeout(1, static fn(): mixed => $task->await());
    }

    public function testCancelNoopsWhenTaskIsNotRunning(): void
    {
        $runtime = new Runtime();
        $task = new Task($runtime);

        $task->cancel();
        $this->assertNull($task->getFiber());

        $completed = $runtime->queue(static fn(): int => 1);
        TestHelper::withTimeout(1, static fn(): mixed => $completed->await());

        $completed->cancel();
        $this->assertTrue($completed->isDone());
    }

    public function testCancelSkipsRuntimeWhenTaskDone(): void
    {
        $runtime = new Runtime();
        $fiber = TestHelper::newTerminatedFiber();
        $task = new Task($runtime);
        $task->setFiber($fiber);

        $read = TestHelper::openTempStream();
        $write = TestHelper::openTempStream();

        TestHelper::setProperty($runtime, 'read', [
            (int) $read => new IoWatcher($read, $fiber, '', 10),
        ]);
        TestHelper::setProperty($runtime, 'write', [
            (int) $write => new IoWatcher($write, $fiber, 'data', 0),
        ]);
        TestHelper::setProperty($runtime, 'timers', [
            new Timer(microtime(true) + 1.0, $fiber),
        ]);

        $task->cancel();

        /** @var array<int, IoWatcher> $readMap */
        $readMap = TestHelper::getProperty($runtime, 'read');
        $this->assertArrayHasKey((int) $read, $readMap);
        /** @var array<int, IoWatcher> $writeMap */
        $writeMap = TestHelper::getProperty($runtime, 'write');
        $this->assertArrayHasKey((int) $write, $writeMap);

        /** @var array<int, Timer> $timers */
        $timers = TestHelper::getProperty($runtime, 'timers');
        $this->assertCount(1, $timers);

        /** @psalm-suppress RedundantConditionGivenDocblockType */
        $this->assertTrue(is_resource($read));
        /** @psalm-suppress RedundantConditionGivenDocblockType */
        $this->assertTrue(is_resource($write));

        TestHelper::setProperty($runtime, 'read', []);
        TestHelper::setProperty($runtime, 'write', []);
        TestHelper::setProperty($runtime, 'timers', []);
        fclose($read);
        fclose($write);
    }

    public function testIsDoneFalseWhenUninitialized(): void
    {
        $runtime = new Runtime();
        $task = new Task($runtime);

        $this->assertFalse($task->isDone());
    }

    public function testNotifyWaitersResumesOnlyActiveFibers(): void
    {
        $runtime = new Runtime();
        $task = new Task($runtime);

        $terminated = TestHelper::newTerminatedFiber();
        $suspended = TestHelper::newSuspendedFiber();

        TestHelper::setProperty($task, 'waiters', [$terminated, $suspended]);

        $task->notifyWaiters();

        $this->assertTrue($suspended->isTerminated());
        $this->assertSame([], TestHelper::getProperty($task, 'waiters'));
    }

    public function testNotifyWaitersResumesAllSuspendedFibers(): void
    {
        $task = new Task(new Runtime());
        $first = TestHelper::newSuspendedFiber();
        $second = TestHelper::newSuspendedFiber();

        TestHelper::setProperty($task, 'waiters', [$first, $second]);
        $task->notifyWaiters();

        $this->assertTrue($first->isTerminated());
        $this->assertTrue($second->isTerminated());
        $this->assertSame([], TestHelper::getProperty($task, 'waiters'));
    }

    public function testNotifyWaitersResumesSingleSuspendedFiber(): void
    {
        $task = new Task(new Runtime());
        $waiter = TestHelper::newSuspendedFiber();

        TestHelper::setProperty($task, 'waiters', [$waiter]);
        $task->notifyWaiters();

        $this->assertTrue($waiter->isTerminated());
        $this->assertSame([], TestHelper::getProperty($task, 'waiters'));
    }

    public function testNotifyWaitersSkipsSingleTerminatedFiber(): void
    {
        $task = new Task(new Runtime());
        $waiter = TestHelper::newTerminatedFiber();

        TestHelper::setProperty($task, 'waiters', [$waiter]);
        $task->notifyWaiters();

        $this->assertSame([], TestHelper::getProperty($task, 'waiters'));
    }

    public function testNotifyWaitersNoopsWhenEmpty(): void
    {
        $task = new Task(new Runtime());

        TestHelper::setProperty($task, 'waiters', []);
        $task->notifyWaiters();

        $this->assertSame([], TestHelper::getProperty($task, 'waiters'));
    }

    public function testAssertResolvedResultThrowsWhenNoResult(): void
    {
        $task = new Task(new Runtime());

        $this->expectException(LogicException::class);
        TestHelper::callPrivate($task, 'assertResolvedResult', ['value']);
    }

    public function testAssertResolvedResultAcceptsValidStates(): void
    {
        $task = new Task(new Runtime());
        TestHelper::setPropertyValue($task, 'hasResult', true);
        TestHelper::setPropertyValue($task, 'result', 'ok');

        TestHelper::callPrivate($task, 'assertResolvedResult', ['ok']);
        $this->assertSame('ok', TestHelper::getProperty($task, 'result'));

        $suspended = TestHelper::newSuspendedFiber();
        TestHelper::setPropertyValue($task, 'fiber', $suspended);
        TestHelper::callPrivate($task, 'assertResolvedResult', ['ok']);
        $this->assertFalse($suspended->isTerminated());

        $doneFiber = new Fiber(static fn(): string => 'ok');
        $doneFiber->start();
        TestHelper::setPropertyValue($task, 'fiber', $doneFiber);
        TestHelper::callPrivate($task, 'assertResolvedResult', ['ok']);
        $this->assertTrue($doneFiber->isTerminated());
    }

    public function testNotifyWaitersSkipsTerminatedFibers(): void
    {
        $task = new Task(new Runtime());
        $terminated = TestHelper::newTerminatedFiber();
        $terminated2 = TestHelper::newTerminatedFiber();

        TestHelper::setProperty($task, 'waiters', [$terminated, $terminated2]);
        $task->notifyWaiters();

        $this->assertSame([], TestHelper::getProperty($task, 'waiters'));
    }
}
