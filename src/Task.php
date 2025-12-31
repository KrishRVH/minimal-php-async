<?php

/**
 * @phan-file-suppress PhanGenericConstructorTypes
 * @phan-file-suppress PhanUnreferencedClosure
 */

declare(strict_types=1);

namespace Krvh\MinimalPhpAsync;

use Fiber;
use LogicException;
use Throwable;

/**
 * Handle for a Fiber managed by {@see Runtime}.
 *
 * Awaiting:
 * - From the root (non-fiber) context, {@see Task::await()} drives the runtime loop.
 * - From inside another runtime-managed Fiber, awaiting suspends the current Fiber
 *   until the target Task completes.
 *
 * Cancellation:
 * - {@see Task::cancel()} attempts best-effort cancellation, cascading to children.
 *
 * @template T
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
final class Task
{
    /**
     * The underlying Fiber; exposed via {@see Task::getFiber()}.
     */
    private ?Fiber $fiber = null;

    /**
     * Child tasks spawned while this Task's Fiber was the current Fiber.
     *
     * @var list<Task<mixed>>
     */
    private array $children = [];

    /**
     * @var list<Fiber> Fibers blocked on await()
     */
    private array $waiters = [];

    private ?Throwable $error = null;
    private bool $hasResult = false;
    private mixed $result = null;

    public function __construct(
        private readonly Runtime $runtime,
    ) {
    }

    public function getFiber(): ?Fiber
    {
        return $this->fiber;
    }

    /**
     * @return list<Task<mixed>>
     */
    public function getChildren(): array
    {
        return $this->children;
    }

    public function isDone(): bool
    {
        return $this->fiber?->isTerminated() ?? false;
    }


    /**
     * @internal
     */
    public function setFiber(Fiber $fiber): void
    {
        if ($this->fiber instanceof Fiber) {
            throw new LogicException('Fiber already set');
        }
        $this->fiber = $fiber;
    }

    /**
     * Record the failure of this Task.
     *
     * The error is rethrown on {@see Task::await()}.
     *
     * @internal
     */
    public function reject(Throwable $e): void
    {
        $this->error = $e;
    }

    /**
     * Record the successful result of this Task.
     *
     * @internal
     *
     * @psalm-param T $value
     */
    public function resolve(mixed $value): void
    {
        $this->hasResult = true;
        $this->result = $value;
    }

    /**
     * Await completion and return the Task result.
     *
     * @return T
     *
     * @throws Throwable Rethrows the Task failure if the underlying Fiber raised.
     */
    public function await(): mixed
    {
        $fiber = $this->fiber ?? throw new LogicException('Task uninitialized');

        if (!$this->isDone()) {
            $current = Fiber::getCurrent();

            if (!$current instanceof Fiber) {
                // Root: drive event loop until we're done.
                $this->runtime->drive(fn(): bool => $this->isDone());
            } elseif ($current === $fiber) {
                throw new LogicException('Circular await detected');
            }

            if ($current instanceof Fiber && $current !== $fiber) {
                // Inside another fiber: suspend current until completion.
                $this->waiters[] = $current;
                Fiber::suspend();
            }
        }

        return $this->result();
    }

    /**
     * Return the Task result if already resolved, without driving the runtime.
     *
     * @return T
     *
     * @throws Throwable Rethrows the Task failure if the underlying Fiber raised.
     * @throws LogicException If the Task has not completed yet.
     *
     * @internal
     */
    public function result(): mixed
    {
        $fiber = $this->fiber ?? throw new LogicException('Task uninitialized');

        if ($this->error instanceof Throwable) {
            throw $this->error;
        }

        if ($this->hasResult) {
            $this->assertResolvedResult($this->result);
            return $this->result;
        }

        if ($fiber->isTerminated()) {
            /** @var T */
            return $fiber->getReturn();
        }

        throw new LogicException('Task not completed');
    }

    /**
     * Best-effort cancellation of this Task (and its descendants).
     */
    public function cancel(): void
    {
        if ($this->fiber instanceof Fiber && !$this->isDone()) {
            $this->runtime->cancelFiber($this->fiber);
        }
    }

    /**
     * @param Task<mixed> $child
     * @internal
     */
    public function addChild(Task $child): void
    {
        $this->children[] = $child;
    }

    /**
     * Resume all Fibers currently suspended waiting on this Task.
     *
     * @internal
     */
    public function notifyWaiters(): void
    {
        foreach ($this->waiters as $waiter) {
            if (!$waiter->isTerminated()) {
                $waiter->resume();
            }
        }
        $this->waiters = [];
    }

    /**
     * @psalm-assert T $value
     */
    private function assertResolvedResult(mixed $value): void
    {
        if (!$this->hasResult) {
            $type = get_debug_type($value);
            throw new LogicException("Task result not available ({$type})");
        }

        if (
            $this->fiber instanceof Fiber
            && $this->fiber->isTerminated()
            && $this->fiber->getReturn() !== $value
        ) {
            $valueType = get_debug_type($value);
            $returnType = get_debug_type($this->fiber->getReturn());
            throw new LogicException("Task result mismatch ({$valueType} vs {$returnType})");
        }
    }
}
