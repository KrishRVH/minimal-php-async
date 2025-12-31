# Minimal PHP Async

A minimal, fiber-based async runtime for PHP 8.5. This is a learning-oriented
library that demonstrates structured concurrency, cooperative scheduling, and a
very small async I/O layer built on `stream_select`.

## Status
Experimental and intentionally small. It is not a general-purpose event loop
and does not aim to replace production async runtimes.

## Goals
- Provide a tiny, readable async runtime built on Fibers.
- Demonstrate structured concurrency (parent/child task tracking).
- Offer basic async I/O primitives (`readAll`, `write`) and timers.
- Include a minimal HTTP fetch helper for examples and tests.

## Non-goals
- Full-featured HTTP client (no keep-alive, no streaming, no pipelining).
- High scalability or advanced event loop integrations (epoll/kqueue).
- Preemptive scheduling or multi-threading.

## Architecture Overview
The public API is intentionally small, with a few internal helpers.

| Component | Role | Notes |
| --- | --- | --- |
| `Async` | Static facade | Entry point; owns the default `Runtime`. |
| `Runtime` | Scheduler | Manages Fibers, I/O watchers, timers, cancellation. |
| `Task` | Handle | Awaitable result of a queued Fiber. |
| `HttpException` | Error | Thrown by `Async::fetch()` on HTTP status >= 400. |
| `IoWatcher` | Internal | Immutable DTO for read/write watchers. |
| `Timer` | Internal | Immutable DTO for wakeup times. |

The dependency boundary is enforced by `deptrac.yaml`: public classes may
depend on internal ones, but not vice versa.

## Execution Model
The runtime is single-threaded and cooperative. Fibers yield only when they
explicitly call runtime primitives.

```text
Async::spawn(fn) -> Runtime::queue -> Fiber::start
Task::await (root) -> Runtime::drive -> tick -> select -> resume Fiber
Task::await (fiber) -> suspend current Fiber until target completes
```

### Core scheduling rules
- `Runtime::drive()` runs the event loop until a condition is true, or throws
  `RuntimeException` if no I/O or timers remain (deadlock detection).
- `Runtime::tick()` advances timers, then waits on I/O via `stream_select`.
- `Runtime::delay(0)` is a cooperative "yield": resume on the next tick.

## Public API (RFC-level semantics)

### Tasks and structured concurrency
```php
use Krvh\MinimalPhpAsync\Async;

$task = Async::spawn(fn() => 42);
$value = $task->await();

$results = Async::all([
    'a' => fn() => 1,
    'b' => fn() => 2,
]);

$winner = Async::race([
    fn() => 'fast',
    fn() => (Async::sleep(0.01) || 'slow'),
]);
```

- `Async::spawn(Closure): Task` queues work on the current runtime.
- `Async::run(Closure): mixed` spawns then awaits (root-safe).
- `Async::all(array<Task|Closure>): array` waits for all; preserves input keys.
- `Async::race(array<Task|Closure>): mixed` returns first; cancels others.
- `Async::timeout(Closure, float $sec): mixed` races work vs a timer task.
- `Async::sleep(float $sec)` suspends the current Fiber; throws if called from
  the root context (no Fiber to suspend).

### Error propagation
- Exceptions inside Fibers are captured and rethrown on `Task::await()`.
- `Task::result()` returns a resolved value without driving the runtime and
  throws if the task is incomplete (internal use).
- Circular `await()` is rejected with `LogicException`.

### Cancellation
`Task::cancel()` performs best-effort cancellation:
1. Recursively cancels child tasks spawned by the current task.
2. Cleans up I/O watchers and closes their streams.
3. Removes pending timers for the canceled Fiber.
4. Throws `RuntimeException("Task cancelled")` into the Fiber when possible.

Cancellation errors are intentionally suppressed to avoid cascading failures.

## I/O and Timers (Runtime primitives)

### Timers
```php
Async::run(function () {
    Async::sleep(0.05);
    return 'ok';
});
```

`Runtime::delay(float $seconds)` records a `Timer` and suspends the Fiber. The
Fiber is resumed once the deadline is reached.

### Non-blocking I/O
```php
$runtime = new Runtime();
$task = $runtime->queue(fn() => $runtime->readAll($stream, 1024));
$data = $task->await();
```

`Runtime::write($stream, $data)` and `Runtime::readAll($stream, $maxBytes)`
switch streams to non-blocking mode and suspend the Fiber until completion.

Key details:
- `IO_CHUNK` is 8192 bytes.
- `readAll()` reads until EOF and enforces a max byte limit.
- On read/write failure, the runtime closes the stream and throws into the Fiber.

## HTTP Helper (Minimal RFC)
`Async::fetch()` is a minimal HTTP/HTTPS helper built on the runtime I/O.

```php
$body = Async::fetch('https://example.test/', [
    'method' => 'GET',
    'headers' => ['User-Agent' => 'minimal-php-async'],
    'body' => '',
    'verify' => true,
    'connect_timeout' => 5.0,
    'max_bytes' => 1_000_000,
]);
```

Semantics and limitations:
- Connect uses `stream_socket_client()` in blocking mode (by design).
- Once connected, the request/response uses non-blocking runtime I/O.
- The request always sets `Connection: close` and reads until EOF.
- If `body` is non-empty and `Content-Length` is not provided, it is added.
- Response status >= 400 throws `HttpException` with the status code.
- `Transfer-Encoding: chunked` is decoded; trailer headers are ignored.
- `fetchJson()` adds `Accept: application/json` and decodes JSON into arrays
  using `JSON_THROW_ON_ERROR` (default depth 512).

This helper is intentionally small and not a full HTTP client.

## Tooling and Quality Gates
The project uses strict static analysis and testing in CI-style workflows:

| Tool | Purpose | Command |
| --- | --- | --- |
| PHPUnit | Unit tests | `composer test` |
| PHP_CodeSniffer | Style | `composer phpcs` |
| PHPMD | Code smell checks | `composer phpmd` |
| PHPStan | Static analysis | `composer phpstan` |
| Psalm | Static analysis | `composer psalm` |
| Phan | Static analysis | `composer phan` |
| Deptrac | Layer rules | `composer deptrac` |
| Infection | Mutation testing | `composer infection` |
| PHPBench | Benchmarks | `composer bench` |
| php-fuzzer | Fuzzing | `composer fuzz:smoke` |

Additional convenience scripts:
- `composer lint` runs style + static analysis + deptrac.
- `composer check` runs `lint` and `test`.
- `composer ci` runs `check` and `infection`.
- `composer coverage` runs `scripts/coverage.php` (Xdebug/pcov if available).
- `composer infection` runs `scripts/infection.php` (skips if no coverage driver).
- `composer deep-check` runs an extended suite (lint, coverage, infection,
  benchmarks, fuzz, dependency checks, rector dry-run).

Mutation testing thresholds are high by design (min MSI 90, covered MSI 95).

## Compatibility
- PHP >= 8.5 (uses Fibers, clone-with syntax, and strict typing).
- No runtime dependencies; dev tooling is managed via Composer.

## Extensibility Notes
This codebase is intentionally small, so extensions should stay modest:
- Add new async primitives as thin wrappers on `Runtime`.
- Keep internal DTOs (`IoWatcher`, `Timer`) immutable.
- Favor explicit cancellation semantics over implicit timeouts.

## License
MIT (see `composer.json`).
