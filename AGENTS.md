# Repository Guidelines

## Project Structure & Module Organization
- `src/` contains the library code under the `Krvh\MinimalPhpAsync\` namespace (core runtime, tasks, timers, IO helpers).
- `tests/` holds PHPUnit tests; shared fixtures and helpers live in `tests/Support/`.
- `benchmarks/` includes phpbench scenarios for performance checks.
- `fuzz/` contains fuzzing harnesses and the input corpus.
- `scripts/` provides helper scripts for coverage and mutation testing.
- Root configs like `phpunit.xml`, `phpcs.xml`, `phpstan.neon`, `psalm.xml`, and `deptrac.yaml` define quality gates.

## Build, Test, and Development Commands
This is a Composer-managed library (PHP >= 8.5). Common workflows:
- `composer test` runs PHPUnit.
- `composer lint` runs style checks plus static analysis and dependency boundaries.
- `composer check` runs `lint` and `test` as a quick gate.
- `composer ci` runs checks and mutation testing.
- `composer coverage` generates coverage via `scripts/coverage.sh`.
- `composer bench` runs phpbench; `composer fuzz:smoke` runs a short fuzz pass.

## Coding Style & Naming Conventions
- PSR-12 with 4-space indentation and `declare(strict_types=1)`.
- Require type hints for parameters, properties, and return values.
- Keep line length under 120 characters (hard cap 150).
- Use trailing commas in multi-line calls/definitions and avoid Yoda comparisons.
- Avoid superglobals and debug/exit helpers (`var_dump`, `print_r`, `die`, `exit`).
- Classes in `src/` follow PSR-4 naming; tests are `*Test.php`.

## Testing Guidelines
- PHPUnit is configured to fail on warnings, risky tests, and global-state leaks.
- Keep tests deterministic and isolated; prefer helpers in `tests/Support/`.
- For mutation testing use `composer infection` (runs via `scripts/infection.sh`).

## Commit & Pull Request Guidelines
- Keep commit messages short and descriptive (current history uses brief sentence-case summaries).
- PRs should include a concise summary, rationale, and the commands you ran (for example `composer test` and `composer lint`).
- Link relevant issues and add tests/benchmarks when changing runtime behavior.
