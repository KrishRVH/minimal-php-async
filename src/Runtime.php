<?php

/**
 * @phan-file-suppress PhanParamTooManyInternal
 * @phan-file-suppress PhanPluginNumericalComparison
 * @phan-file-suppress PhanUnreferencedClosure
 */

declare(strict_types=1);

namespace Krvh\MinimalPhpAsync;

use Closure;
use Fiber;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;
use WeakMap;

/**
 * A lightweight, fiber-based async runtime.
 *
 * Core responsibilities:
 * - Task scheduling: start Fibers and track parent→child relationships.
 * - I/O scheduling: suspend Fibers and resume them when streams become readable/writable
 *   using {@see stream_select()}.
 * - Time scheduling: suspend Fibers and resume them when a timer expires.
 * - Cancellation: best-effort cancellation that cascades to children and cleans up
 *   I/O watchers to avoid deadlocks.
 *
 * Model:
 * - Single-threaded, cooperative concurrency. Fibers must yield back to the runtime
 *   via {@see Runtime::delay()}, {@see Runtime::write()}, or {@see Runtime::readAll()}.
 * - This runtime does not attempt to be "async everywhere"; it’s a minimal scheduler
 *   and a good substrate for demos and controlled environments.
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
final class Runtime
{
    private const int IO_CHUNK = 8192;

    /** @var array<int, IoWatcher> streamId => watcher */
    private array $read = [];

    /** @var array<int, IoWatcher> streamId => watcher */
    private array $write = [];

    /** @var array<int, Timer> */
    private array $timers = [];

    /** @var WeakMap<Fiber, Task<mixed>> */
    private WeakMap $fiberToTask;

    public function __construct()
    {
        /** @psalm-suppress PropertyTypeCoercion */
        $this->fiberToTask = new WeakMap();
    }

    /**
     * Drive the event loop until $condition returns true.
     *
     * This is what makes {@see Task::await()} work from the root (non-fiber) context.
     *
     * @param Closure():bool $condition
     *
     * @throws RuntimeException If the condition is not met but the runtime has no
     *                          pending I/O or timers to drive progress (deadlock).
     */
    public function drive(Closure $condition): void
    {
        while (!$condition()) {
            if ($this->read === [] && $this->write === [] && $this->timers === []) {
                throw new RuntimeException('Deadlock: no pending I/O or timers, but condition not met');
            }
            $this->tick();
        }
    }

    /**
     * Schedule a new Task to run in its own Fiber.
     *
     * Structured concurrency:
     * - If called from within a runtime-managed Fiber, the new Task is recorded as a child.
     * - Cancelling a parent Fiber cascades cancellation to its children.
     *
     * @template T
     * @param Closure():T $fn
     * @return Task<T>
     */
    public function queue(Closure $fn): Task
    {
        /** @var Task<T> $task */
        $task = new Task($this);

        $fiber = new Fiber(static function () use ($fn, $task): mixed {
            try {
                // Return value is captured as Fiber return value.
                $result = $fn();
                $task->resolve($result);
                return $result;
            } catch (Throwable $e) {
                // Important: do NOT let the exception escape the Fiber start/resume call;
                // store it and rethrow on await().
                $task->reject($e);
                return null;
            } finally {
                // Always resume awaiters.
                $task->notifyWaiters();
            }
        });

        $task->setFiber($fiber);
        $this->fiberToTask[$fiber] = $task;

        // Parent-child tracking for structured concurrency.
        $parent = Fiber::getCurrent();
        if ($parent instanceof Fiber) {
            $parentTask = $this->taskForFiber($parent);
            if ($parentTask instanceof Task) {
                $parentTask->addChild($task);
            }
        }

        $fiber->start();
        return $task;
    }

    /**
     * Suspend the current Fiber for at least $seconds.
     *
     * This must be called inside a Fiber that is managed by this runtime.
     *
     * Design note:
     * - Passing 0 is treated as "yield": the fiber is resumed on the next tick.
     */
    public function delay(float $seconds): void
    {
        $seconds = max(0.0, $seconds);

        $this->timers[] = new Timer(microtime(true) + $seconds, $this->requireFiber());
        Fiber::suspend();
    }

    /**
     * Write the full $data to a stream without blocking the runtime.
     *
     * This call:
     * - switches the stream to non-blocking
     * - suspends the current Fiber
     * - resumes it when the write fully completes
     */
    public function write(mixed $stream, string $data): void
    {
        if ($data === '') {
            return;
        }

        if (!is_resource($stream)) {
            throw new InvalidArgumentException('Stream must be a resource');
        }

        stream_set_blocking($stream, false);

        $this->write[(int) $stream] = new IoWatcher(
            stream: $stream,
            fiber: $this->requireFiber(),
            buffer: $data,
            offsetOrMaxBytes: 0,
        );

        Fiber::suspend();
    }

    /**
     * Read until EOF (Connection: close style) with a maximum size guard.
     *
     * This call:
     * - switches the stream to non-blocking
     * - suspends the current Fiber
     * - resumes it with the accumulated string once EOF is reached
     * @param int $maxBytes Maximum allowed bytes before failing (must be > 0).
     */
    public function readAll(mixed $stream, int $maxBytes): string
    {
        if ($maxBytes <= 0) {
            throw new InvalidArgumentException('maxBytes must be > 0');
        }

        if (!is_resource($stream)) {
            throw new InvalidArgumentException('Stream must be a resource');
        }

        stream_set_blocking($stream, false);

        $this->read[(int) $stream] = new IoWatcher(
            stream: $stream,
            fiber: $this->requireFiber(),
            buffer: '',
            offsetOrMaxBytes: $maxBytes,
        );

        $data = Fiber::suspend();
        if (!\is_string($data)) {
            throw new RuntimeException('Read failed: non-string payload');
        }
        return $data;
    }

    /**
     * Best-effort cancellation of a Fiber and all its known descendants.
     *
     * Cancellation behavior:
     * 1) Cancels children (recursive via Task::cancel()).
     * 2) Cleans up I/O watchers (and closes their streams) tied to the target Fiber.
     * 3) Removes pending timers tied to the target Fiber.
     * 4) Throws a RuntimeException("Task cancelled") into the Fiber if possible.
     *
     * Notes:
     * - Cancellation is best-effort; secondary exceptions are intentionally suppressed.
     * - Closing streams on cancellation avoids orphaned watchers and deadlocks.
     *
     */
    public function cancelFiber(Fiber $fiber): void
    {
        $parentTask = $this->taskForFiber($fiber);
        if ($parentTask instanceof Task) {
            foreach ($parentTask->getChildren() as $child) {
                $child->cancel();
            }
        }

        $this->cleanupReadWatchers($fiber);
        $this->cleanupWriteWatchers($fiber);

        foreach ($this->timers as $k => $timer) {
            if ($timer->fiber === $fiber) {
                unset($this->timers[$k]);
            }
        }

        if ($fiber->isTerminated()) {
            return;
        }

        try {
            $fiber->throw(new RuntimeException('Task cancelled'));
        } catch (Throwable) {
            // Best-effort cancellation.
        }
    }

    /**
     * @return Task<mixed>|null
     */
    private function taskForFiber(Fiber $fiber): ?Task
    {
        $task = $this->fiberToTask[$fiber] ?? null;
        return $task instanceof Task ? $task : null;
    }

    private function cleanupReadWatchers(Fiber $fiber): void
    {
        $this->read = $this->cleanupWatchers($this->read, $fiber);
    }

    private function cleanupWriteWatchers(Fiber $fiber): void
    {
        $this->write = $this->cleanupWatchers($this->write, $fiber);
    }

    private function tick(): void
    {
        $nextTimerAt = $this->processTimers();

        // If no I/O watchers, just sleep until the next timer (if any).
        if ($this->read === [] && $this->write === []) {
            if ($nextTimerAt !== null) {
                $sleep = max(0.0, $nextTimerAt - microtime(true));
                if ($sleep > 0) {
                    usleep((int) ($sleep * 1_000_000.0));
                }
            }
            return;
        }

        $this->waitForIo($nextTimerAt);
    }

    /**
     * Resume any timers that are due and return the soonest future timer time.
     *
     * @return float|null Next timer timestamp, or null if no pending timers.
     */
    private function processTimers(): ?float
    {
        $now = microtime(true);
        $next = null;

        foreach ($this->timers as $k => $timer) {
            if ($timer->at <= $now) {
                unset($this->timers[$k]);

                if (!$timer->fiber->isTerminated()) {
                    $timer->fiber->resume();
                }
                continue;
            }

            $next = $next === null ? $timer->at : min($next, $timer->at);
        }

        return $next;
    }

    private function waitForIo(?float $nextTimerAt): void
    {
        $readStreams = $this->collectStreams($this->read);
        $writeStreams = $this->collectStreams($this->write);

        ['ready' => $ready, 'read' => $readStreams, 'write' => $writeStreams] = $this->selectStreams(
            $readStreams,
            $writeStreams,
            $nextTimerAt,
        );
        if ($ready === false || $ready === 0) {
            return;
        }

        $this->processWrites($writeStreams);
        $this->processReads($readStreams);
    }

    /** @param array<array-key, resource> $streams */
    private function processWrites(array $streams): void
    {
        foreach ($streams as $stream) {
            $id = (int) $stream;
            $watcher = $this->write[$id] ?? null;
            if ($watcher === null) {
                continue;
            }

            $offset = $watcher->offsetOrMaxBytes;
            $len = strlen($watcher->buffer);

            $chunk = substr($watcher->buffer, $offset, self::IO_CHUNK);
            $written = $this->suppressWarnings(static fn(): int|false => fwrite($stream, $chunk));

            if ($written === false) {
                $this->failWrite($id, 'Write failed');
                continue;
            }

            // No progress; keep watcher and try again on a future tick.
            if ($written === 0) {
                continue;
            }

            $newOffset = $offset + $written;

            if ($newOffset < $len) {
                $this->write[$id] = $watcher->with($watcher->buffer, $newOffset);
                continue;
            }

            unset($this->write[$id]);
            if (!$watcher->fiber->isTerminated()) {
                $watcher->fiber->resume();
            }
        }
    }

    /** @param array<array-key, resource> $streams */
    private function processReads(array $streams): void
    {
        foreach ($streams as $stream) {
            $id = (int) $stream;
            $watcher = $this->read[$id] ?? null;
            if ($watcher === null) {
                continue;
            }

            $chunk = $this->suppressWarnings(static fn(): string|false => fread($stream, self::IO_CHUNK));
            if ($chunk === false) {
                $this->failRead($id, 'Read failed');
                continue;
            }

            $buffer = $watcher->buffer . $chunk;

            if (strlen($buffer) > $watcher->offsetOrMaxBytes) {
                $this->failRead($id, 'Response too large');
                continue;
            }

            if (feof($stream)) {
                unset($this->read[$id]);
                $this->closeStream($stream);

                if (!$watcher->fiber->isTerminated()) {
                    $watcher->fiber->resume($buffer);
                }
                continue;
            }

            $this->read[$id] = $watcher->with($buffer, $watcher->offsetOrMaxBytes);
        }
    }

    /**
     * Fail a watcher: remove it, close stream, and throw into its Fiber.
     */
    private function failRead(int $id, string $msg): void
    {
        $watcher = $this->read[$id] ?? null;
        if ($watcher === null) {
            return;
        }

        unset($this->read[$id]);
        $this->failWatcher($watcher, $msg);
    }

    /**
     * Fail a watcher: remove it, close stream, and throw into its Fiber.
     */
    private function failWrite(int $id, string $msg): void
    {
        $watcher = $this->write[$id] ?? null;
        if ($watcher === null) {
            return;
        }

        unset($this->write[$id]);
        $this->failWatcher($watcher, $msg);
    }

    /**
     * @param array<int, IoWatcher> $watchers
     * @return array<int, IoWatcher>
     */
    private function cleanupWatchers(array $watchers, Fiber $fiber): array
    {
        foreach ($watchers as $id => $w) {
            if ($w->fiber !== $fiber) {
                continue;
            }

            $this->closeStream($w->stream);

            unset($watchers[$id]);
        }

        return $watchers;
    }

    /**
     * @param array<int, IoWatcher> $watchers
     * @return list<resource>
     * @phan-suppress PhanPluginPossiblyStaticPrivateMethod
     */
    private function collectStreams(array $watchers): array
    {
        $streams = [];
        foreach ($watchers as $watcher) {
            if (is_resource($watcher->stream)) {
                $streams[] = $watcher->stream;
            }
        }

        return $streams;
    }

    private function failWatcher(IoWatcher $watcher, string $msg): void
    {
        $this->closeStream($watcher->stream);

        if ($watcher->fiber->isTerminated()) {
            return;
        }

        try {
            $watcher->fiber->throw(new RuntimeException($msg));
        } catch (Throwable) {
            // Best effort: fiber may not accept a throw at this moment.
        }
    }

    /**
     * @param list<resource> $read
     * @param list<resource> $write
     * @return array{ready: int|false, read: array<int, resource>, write: array<int, resource>}
     */
    private function selectStreams(array $read, array $write, ?float $nextTimerAt): array
    {
        $except = [];

        $timeout = $nextTimerAt === null ? null : max(0.0, $nextTimerAt - microtime(true));

        $sec = null;
        $usec = null;
        if ($timeout !== null) {
            $sec = (int) $timeout;
            $usec = (int) (($timeout - (float) $sec) * 1_000_000.0);
        }

        // Suppress warnings: select can emit warnings on EINTR/invalid streams.
        $ready = $this->suppressWarnings(static fn(): int|false => stream_select($read, $write, $except, $sec, $usec));

        return ['ready' => $ready, 'read' => $read, 'write' => $write];
    }

    /**
     * @return Fiber The current Fiber, or throws if called from the root context.
     * @phan-suppress PhanPluginPossiblyStaticPrivateMethod
     */
    private function requireFiber(): Fiber
    {
        return Fiber::getCurrent() ?? throw new LogicException('Async operation must run inside a Fiber');
    }

    private function closeStream(mixed $stream): void
    {
        if (!is_resource($stream)) {
            return;
        }

        $this->suppressWarnings(static fn(): bool => fclose($stream));
    }

    /**
     * @template T
     * @param Closure(): T $fn
     * @return T
     */
    private function suppressWarnings(Closure $fn): mixed
    {
        set_error_handler($this->ignoreError(...));

        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @psalm-suppress UnusedParam
     * @phan-suppress PhanUnusedPrivateFinalMethodParameter
     * @phan-suppress PhanPluginPossiblyStaticPrivateMethod
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    private function ignoreError(int $errno, string $errstr): bool
    {
        return true;
    }
}
