# Repository Guidelines

## Project Structure & Module Organization
- `src/`: runtime, Async API, and internal helpers (namespace `Krvh\MinimalPhpAsync\`).
- `tests/`: PHPUnit tests; shared helpers live in `tests/Support/`.
- `benchmarks/`: PHPBench cases; `fuzz/`: php-fuzzer harness and corpus.
- `scripts/`: helper runners for coverage, infection, and BC checks.
- Config: `phpunit.xml`, `phpcs.xml`, `phpstan.neon`, `psalm.xml`, `deptrac.yaml`, `infection.json5`, `rector.php`.

## Build, Test, and Development Commands
- `composer test`: run PHPUnit.
- `composer lint`: run `phpcs`, `phpmd`, `phpstan`, `psalm`, `phan`, and `deptrac`.
- `composer check`: lint + tests.
- `composer ci`: check + mutation tests.
- `composer coverage`: generate coverage (requires Xdebug or pcov).
- `composer infection`: mutation testing via `scripts/infection.php`.
- `composer bench`: run PHPBench benchmarks.
- `composer fuzz:smoke`: quick fuzz pass.
- `composer deep-check`: full suite including dependency and BC checks.

## Coding Style & Naming Conventions
- PSR-12 baseline with strict typing; keep `declare(strict_types=1);`.
- 4-space indentation, line length 120 soft / 150 hard (`phpcs.xml`).
- Prefer typed properties/params/returns; avoid forbidden debug functions (`var_dump`, `die`, etc.).
- Classes in `src/` use StudlyCase; tests named `*Test.php`.

## Testing Guidelines
- Framework: PHPUnit (see `phpunit.xml`).
- Place tests in `tests/` mirroring `src/` structure; helpers belong in `tests/Support/`.
- Mutation targets are strict (MSI >= 90, covered MSI >= 95); update tests when changing behavior.

## Architecture & Constraints
- `Async` is the public facade; `Runtime`, `Task`, and DTOs are internal.
- Layering is enforced by `deptrac.yaml`; keep internal dependencies one-way.

## Commit & Pull Request Guidelines
- Commit history shows short, descriptive messages without prefixes; keep them concise and action-oriented (e.g., "fix timer cancellation").
- PRs should include a clear summary, rationale, and the checks run (at least `composer test`; add `composer lint` for non-trivial changes).
- Mention any API changes and update docs/tests accordingly.
