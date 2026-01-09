# minimal-php-async

Minimal, educational async runtime for PHP 8.5 built on Fibers. It provides a
structured concurrency facade, a tiny event loop, and a minimal HTTP/1.1 client
used purely to demonstrate async I/O.

## Overview
- Package name: `krvh/minimal-php-async`
- Namespace: `Krvh\MinimalPhpAsync`
- License: MIT
- Runtime dependencies: none (only `php >= 8.5`)
- Dev tooling is extensive (static analysis, mutation testing, fuzzing, and
  benchmarks) and is driven through Composer scripts.

## Requirements
- PHP 8.5+ (uses clone-with, `array_all`, `array_any`, `array_find`, and
  readonly classes).
- `ext-pcntl` is optional but enables test timeouts.
- Xdebug or pcov is required for coverage and mutation testing; otherwise
  coverage runs fall back to tests only and Infection is skipped.

## Project Layout
- `src/`: core runtime (`Async`, `Runtime`, `Task`, `HttpException`,
  internal `IoWatcher` and `Timer`).
- `tests/`: PHPUnit suite plus deterministic stream/time stubs in
  `tests/Support/`.
- `benchmarks/`: phpbench microbenchmarks.
- `fuzz/`: php-fuzzer target and dictionary.
- `scripts/`: helper wrappers for coverage, infection, and BC checks.
- `phpunit.xml`, `phpstan.neon`, `psalm.xml`, `phpcs.xml`, `phpmd.xml`,
  `rector.php`, `deptrac.yaml`: quality gate configs.
- `.phan/phan.phar`: bundled Phan PHAR used by `composer phan`.

## Public API Specification

### Async (static facade)
Core usage is via `Async` which owns a runtime instance:

```php
use Krvh\MinimalPhpAsync\Async;

$result = Async::run(static function (): array {
    $a = Async::spawn(static fn(): int => 1);
    $b = Async::spawn(static fn(): int => 2);
    return Async::all(['a' => $a, 'b' => $b]);
});
```

Methods and behavior:
- `withRuntime(Runtime $runtime, Closure $fn): mixed`
  swaps the global runtime for the duration of `$fn` and always restores it.
- `spawn(Closure $fn): Task`
  queues work on the current runtime and returns a `Task`.
- `run(Closure $fn): mixed`
  spawns and awaits the task (drives the loop if called from root).
- `sleep(float $seconds): void`
  suspends the current Fiber for at least `$seconds`; throws `LogicException`
  when called outside a runtime-managed Fiber.
- `all(array $tasks): array`
  accepts `Task` instances or closures and returns results preserving keys.
- `race(array $tasks): mixed`
  awaits the first completion, cancels the rest, and returns the winner.
  Requires at least one task.
- `timeout(Closure $fn, float $sec): mixed`
  races `$fn` against a timer; throws `RuntimeException("Timeout {$sec}s")`
  if the timer wins.
- `fetch(string $url, array $opts = []): string`
  minimal HTTP/1.1 client that reads until EOF and decodes chunked bodies.
- `fetchJson(string $url, array $opts = []): mixed`
  adds `Accept: application/json` and decodes with `JSON_THROW_ON_ERROR`.

### Runtime (event loop)
Direct use is supported for advanced control:

```php
use Krvh\MinimalPhpAsync\Runtime;

$rt = new Runtime();
$task = $rt->queue(static function () use ($rt): string {
    $rt->delay(0.01);
    return 'done';
});
$result = $task->await();
```

Key behaviors:
- `queue(Closure $fn): Task` starts a Fiber immediately and tracks parent-child
  relationships for structured concurrency.
- `drive(Closure $condition): void` runs the loop until the condition is true,
  throwing `RuntimeException` on deadlock (no timers and no I/O).
- `delay(float $seconds): void` suspends the current Fiber; negative values are
  treated as `0.0` (yield on next tick).
- `write(resource $stream, string $data): void` writes the full payload
  asynchronously; switches stream to non-blocking and resumes on completion.
- `readAll(resource $stream, int $maxBytes): string` reads until EOF with a
  size guard; throws when the buffer exceeds `maxBytes`.
- `cancelFiber(Fiber $fiber): void` best-effort cancellation that:
  cancels child tasks, closes streams, removes timers, and throws
  `RuntimeException("Task cancelled")` into the Fiber if possible.

### Task
Represents a Fiber plus its lifecycle:
- `await(): mixed` returns result or rethrows failure. From root it drives the
  runtime; from another Fiber it suspends until completion.
- `result(): mixed` returns the resolved value without driving; throws if the
  task is not complete.
- `cancel(): void` best-effort cancellation via the runtime.
- `isDone(): bool`, `getFiber(): ?Fiber`, `getChildren(): array`.
- Circular await is detected and throws `LogicException`.

### HttpException
Thrown by `Async::fetch()` when HTTP status is >= 400.
It exposes a readonly `status` property and sets the exception code to the
status value.

## HTTP Helper Specification
`Async::fetch()` is intentionally minimal:
- Connect is blocking (`stream_socket_client`) and then the stream is handed
  to the runtime for non-blocking I/O.
- Default headers: `Host`, `Connection: close`.
- If `body` is non-empty and `Content-Length` is absent, it is added.
- Responses must contain `\r\n\r\n` separating headers and body.
- `Transfer-Encoding: chunked` responses are decoded.

Options (`FetchOptions`) and defaults:
- `method` (string, default `GET`)
- `headers` (`array<string,string>`, default empty)
- `body` (string, default empty)
- `verify` (bool, default `true`) controls TLS peer verification
- `connect_timeout` (float|int, default `30.0` seconds)
- `max_bytes` (int, default `8_000_000`)

Example:
```php
$body = Async::fetch('https://example.test/path', [
    'method' => 'POST',
    'headers' => ['Content-Type' => 'text/plain'],
    'body' => 'payload',
    'connect_timeout' => 1.5,
    'verify' => true,
    'max_bytes' => 100_000,
]);
```

Chunked decoding is strict:
- Hex sizes with optional extensions are supported.
- Each chunk must end with CRLF; trailers are allowed but must end with a
  blank line and contain no extra bytes.

## Event Loop and Concurrency Model
- Single-threaded, cooperative concurrency based on Fibers.
- Fibers only yield via `delay()`, `write()`, or `readAll()`.
- I/O readiness is polled via `stream_select`.
- I/O chunk size is `8192` bytes (`Runtime::IO_CHUNK`).
- Timers are scheduled via `microtime(true)` and the loop sleeps with `usleep`
  when no I/O is pending.

## Error Behavior Summary
- `InvalidArgumentException` for invalid options, tasks, or streams.
- `LogicException` for invalid usage (root `sleep`/`delay`, circular await,
  awaiting uninitialized task, unresolved result mismatch).
- `RuntimeException` for I/O errors, deadlocks, cancellation, timeouts, and
  malformed HTTP chunk framing.
- `HttpException` for HTTP status >= 400.
- `JsonException` for invalid JSON in `fetchJson`.
- `TypeError` for non-scalar header values in `buildRequest`.

## Internal Types
These are internal by design (enforced by `deptrac.yaml`):
- `IoWatcher`: immutable readonly DTO for a pending I/O operation.
  `offsetOrMaxBytes` is either write offset or read limit.
- `Timer`: immutable readonly DTO for a wake-up timestamp and Fiber.

## Tooling and Quality Gates
Composer scripts define the toolchain:
- `composer test` runs PHPUnit.
- `composer lint` runs `phpcs`, `phpmd`, `phpstan`, `psalm`, `phan`, `deptrac`.
- `composer analyze` runs `phpstan`, `psalm`, `phan`.
- `composer check` = lint + tests.
- `composer ci` = check + mutation testing.
- `composer deep-check` runs normalization, lint, coverage, infection, benches,
  fuzz smoke, dependency checks, rector dry run, and BC check.

Static analysis and style:
- `phpcs` uses PSR-12 plus Slevomat rules, forbids `var_dump`, `print_r`,
  `die`, `exit`, `dd`, `dump`, and enforces line length limits.
- `phpmd` uses cleancode, codesize, design, naming (short/long vars allowed).
- `phpstan` level 10 across `src/` and `tests/`.
- `psalm` error level 1 with unused code detection and the PHPUnit plugin.
- `phan` is configured for PHP 8.5, maximum strictness, and is executed from
  `.phan/phan.phar`.
- `rector` targets PHP 8.5 and includes quality/dead-code/type/privatization
  sets with a dry-run script.
- `deptrac` layers:
  - Public: `Async`, `Runtime`, `Task`, `HttpException`
  - Internal: `IoWatcher`, `Timer`
  - Public may depend on Internal; Internal has no allowed dependencies.

Mutation testing:
- `infection.json5` sets `minMsi: 90` and `minCoveredMsi: 95`.
- Logs: `infection.log`, `infection-summary.log`.

## Testing Details
- PHPUnit config: random order, fail on warnings/skips/notices, strict about
  global state and output.
- `tests/Support/` provides stream wrappers and overrides for deterministic
  I/O (`stream_socket_client`, `stream_select`) and time (`microtime`, `usleep`).
- Test timeouts use `pcntl_alarm` when `ext-pcntl` is available.

## Fuzzing
- Target: `fuzz/async-parse.php` exercises HTTP response parsing.
- Dictionary: `fuzz/http.dict`.
- Corpus: `fuzz/corpus/`.
- `FUZZ_MAX_LEN` controls maximum input length.
- `composer fuzz:smoke` runs a short fuzz session.

## Benchmarks
- phpbench config in `phpbench.json` runs microbenchmarks in `benchmarks/`.
- `AsyncBench` measures core scheduling operations.
- `HttpParsingBench` measures HTTP parsing and chunk decoding.

## Backward Compatibility Check
`scripts/backward-compatibility.php` runs `roave/backward-compatibility-check`.
- Uses `BC_FROM`/`BC_TO` if set, otherwise the latest tag or `HEAD~1`.
- Skips when no git history or tag is available.

## Recorded Tooling Results (latest run)
Executed `composer deep-check` and `composer coverage` on PHP 8.5.1 with Xdebug
3.5.0:
- PHPUnit: 238 tests, 1513 assertions.
- Coverage: 100% classes, methods, paths, branches, and lines.
- Infection: 483 mutations, MSI 100%, covered MSI 100%.
- phpbench: completed without failures (values are machine-dependent).
- Dependency analyzers: no unused packages or missing symbols.
- Backward compatibility check: no breaking changes detected.

