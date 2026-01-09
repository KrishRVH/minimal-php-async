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
 * @SuppressWarnings("PHPMD.TooManyMethods")
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
            $this->assertHasPendingWork();
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
                $task->notifyWaiters();
                return $result;
            } catch (Throwable $e) {
                // Important: do NOT let the exception escape the Fiber start/resume call;
                // store it and rethrow on await().
                $task->reject($e);
                $task->notifyWaiters();
                return null;
            }
        });

        $task->setFiber($fiber);
        $this->fiberToTask[$fiber] = $task;

        // Parent-child tracking for structured concurrency.
        $parent = Fiber::getCurrent();
        if ($parent instanceof Fiber) {
            $parentTask = $this->fiberToTask[$parent] ?? null;
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
        // @infection-ignore-all
        if ($seconds < 0.0) {
            $seconds = 0.0;
        }

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
        $this->cancelChildren($fiber);
        $this->read = $this->cleanupWatchers($this->read, $fiber);
        $this->write = $this->cleanupWatchers($this->write, $fiber);
        $this->removeTimersForFiber($fiber);
        $this->throwCancellation($fiber);
    }

    private function cancelChildren(Fiber $fiber): void
    {
        $parentTask = $this->fiberToTask[$fiber] ?? null;
        if (!$parentTask instanceof Task) {
            return;
        }

        $children = $parentTask->getChildren();
        array_walk(
            $children,
            static function (Task $child): void {
                $child->cancel();
            },
        );
    }

    private function removeTimersForFiber(Fiber $fiber): void
    {
        $this->timers = array_filter(
            $this->timers,
            static fn(Timer $timer): bool => $timer->fiber !== $fiber,
        );
    }

    private function assertHasPendingWork(): void
    {
        if (count($this->read) + count($this->write) + count($this->timers) === 0) {
            throw new RuntimeException('Deadlock: no pending I/O or timers, but condition not met');
        }
    }

    private function tick(): void
    {
        $nextTimerAt = $this->processTimers();

        if ($this->read !== []) {
            $this->waitForIo($nextTimerAt);
            return;
        }
        if ($this->write !== []) {
            $this->waitForIo($nextTimerAt);
            return;
        }

        // No I/O watchers: sleep until the next timer (if any).
        if ($nextTimerAt === null) {
            return;
        }

        $sleep = $nextTimerAt - microtime(true);
        if ($sleep <= 0) {
            return;
        }

        usleep((int) ($sleep * 1_000_000.0));
    }

    /**
     * Resume any timers that are due and return the soonest future timer time.
     *
     * @return float|null Next timer timestamp, or null if no pending timers.
     */
    private function processTimers(): ?float
    {
        if ($this->timers === []) {
            // @infection-ignore-all
            return null;
        }

        $now = floatval(microtime(true));
        $timers = $this->timers;
        return array_reduce(
            array_keys($timers),
            fn(?float $next, int $key): ?float => $this->processTimer($key, $timers[$key], $now, $next),
        );
    }

    private function processTimer(int $key, Timer $timer, float $now, ?float $next): ?float
    {
        if ($timer->at <= $now) {
            unset($this->timers[$key]);

            if (!$timer->fiber->isTerminated()) {
                $timer->fiber->resume();
            }
            return $next;
        }

        if ($next === null) {
            return $timer->at;
        }

        // @infection-ignore-all
        if ($next <= $timer->at) {
            return $next;
        }

        return $timer->at;
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
        if ($ready === false) {
            return;
        }
        if ($ready === 0) {
            return;
        }

        $this->processWrites($writeStreams);
        $this->processReads($readStreams);
    }

    /** @param array<array-key, resource> $streams */
    private function processWrites(array $streams): void
    {
        $values = array_values($streams);
        $count = count($values);
        for ($i = 0; $i < $count; $i++) {
            $this->processWriteStream($values[$i]);
        }
    }

    /**
     * @param resource|null $stream
     */
    private function processWriteStream(mixed $stream): void
    {
        if (!is_resource($stream)) {
            return;
        }
        $id = (int) $stream;
        $watcher = $this->write[$id] ?? null;
        if ($watcher === null) {
            return;
        }

        $offset = $watcher->offsetOrMaxBytes;
        $len = strlen($watcher->buffer);
        if ($offset >= $len) {
            $this->failWrite($id, 'Write failed');
            return;
        }

        $chunk = $this->sliceBuffer($watcher->buffer, $offset, self::IO_CHUNK);
        $written = $this->suppressWarnings(static fn(): int|false => fwrite($stream, $chunk));
        if ($written === false) {
            $this->failWrite($id, 'Write failed');
            return;
        }
        if ($written === 0) {
            // No progress; keep watcher and try again on a future tick.
            return;
        }

        $newOffset = $offset + $written;

        if ($newOffset < $len) {
            $this->write[$id] = $watcher->with($watcher->buffer, $newOffset);
            return;
        }

        unset($this->write[$id]);
        if (!$watcher->fiber->isTerminated()) {
            $watcher->fiber->resume();
        }
    }

    /** @param array<array-key, resource> $streams */
    private function processReads(array $streams): void
    {
        $values = array_values($streams);
        $count = count($values);
        for ($i = 0; $i < $count; $i++) {
            $this->processReadStream($values[$i]);
        }
    }

    /**
     * @param resource|null $stream
     */
    private function processReadStream(mixed $stream): void
    {
        if (!is_resource($stream)) {
            return;
        }
        $id = (int) $stream;
        $watcher = $this->read[$id] ?? null;
        if ($watcher === null) {
            return;
        }

        $chunk = $this->suppressWarnings(static fn(): string|false => fread($stream, self::IO_CHUNK));
        if ($chunk === false) {
            $this->failRead($id, 'Read failed');
            return;
        }

        $buffer = $watcher->buffer . $chunk;

        if (strlen($buffer) > $watcher->offsetOrMaxBytes) {
            $this->failRead($id, 'Response too large');
            return;
        }

        if (feof($stream)) {
            unset($this->read[$id]);
            $this->closeStream($stream);

            if (!$watcher->fiber->isTerminated()) {
                $watcher->fiber->resume($buffer);
            }
            return;
        }

        $this->read[$id] = $watcher->with($buffer, $watcher->offsetOrMaxBytes);
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
        $toClose = array_filter(
            $watchers,
            static fn(IoWatcher $watcher): bool => $watcher->fiber === $fiber,
        );

        array_walk($toClose, function (IoWatcher $watcher): void {
            $this->closeStream($watcher->stream);
        });

        return array_filter(
            $watchers,
            static fn(IoWatcher $watcher): bool => $watcher->fiber !== $fiber,
        );
    }

    /**
     * @param array<int, IoWatcher> $watchers
     * @return list<resource>
     * @phan-suppress PhanPluginPossiblyStaticPrivateMethod
     */
    private function collectStreams(array $watchers): array
    {
        $streams = array_map(
            static fn(IoWatcher $watcher): mixed => $watcher->stream,
            $watchers,
        );
        $streams = array_filter($streams, is_resource(...));
        $streams = array_values($streams);

        /** @var list<resource> $streams */
        return $streams;
    }

    private function failWatcher(IoWatcher $watcher, string $msg): void
    {
        $this->closeStream($watcher->stream);

        if ($watcher->fiber->isTerminated()) {
            // @infection-ignore-all
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

        $sec = null;
        $usec = null;
        if ($nextTimerAt !== null) {
            $timeout = $nextTimerAt - microtime(true);
            // @infection-ignore-all
            if ($timeout < 0.0) {
                $timeout = 0.0;
            }
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
            $result = $fn();
        } catch (Throwable $e) {
            restore_error_handler();
            throw $e;
        }

        restore_error_handler();
        return $result;
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

    /**
     * @phan-suppress PhanPluginPossiblyStaticPrivateMethod
     */
    private function throwCancellation(Fiber $fiber): void
    {
        if ($fiber->isTerminated()) {
            // @infection-ignore-all
            return;
        }

        try {
            $fiber->throw(new RuntimeException('Task cancelled'));
        } catch (Throwable) {
            // Best-effort cancellation.
        }
    }

    /**
     * @phan-suppress PhanPluginPossiblyStaticPrivateMethod
     */
    private function sliceBuffer(string $buffer, int $offset, int $length): string
    {
        $bufferLen = strlen($buffer);
        $end = $offset + $length;
        // @infection-ignore-all
        if ($end > $bufferLen) {
            $end = $bufferLen;
        }

        $chunk = '';
        for ($i = $offset; $i < $end; $i++) {
            $chunk .= $buffer[$i];
        }

        return $chunk;
    }
}
