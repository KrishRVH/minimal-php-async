<?php

/**
 * @phan-file-suppress PhanAccessMethodInternal
 * @phan-file-suppress PhanUnreferencedClass
 * @phan-file-suppress PhanUnreferencedClosure
 */

declare(strict_types=1);

namespace Krvh\MinimalPhpAsync\Tests;

use Fiber;
use Krvh\MinimalPhpAsync\Runtime;
use Krvh\MinimalPhpAsync\Task;
use Krvh\MinimalPhpAsync\Tests\Support\AsyncTestCase;
use Krvh\MinimalPhpAsync\Tests\Support\TestHelper;
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
}
