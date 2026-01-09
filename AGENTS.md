# Repository Guidelines

This repository contains a minimal async runtime implementation for PHP 8.5. Use Composer scripts for all tooling; there is no separate build step beyond dependency installation.

## Project Structure & Module Organization
- `src/`: core runtime classes under the `Krvh\MinimalPhpAsync` namespace.
- `tests/`: PHPUnit tests; `tests/Support/` holds stubs, fixtures, and helper utilities.
- `benchmarks/`: phpbench scenarios for performance checks.
- `fuzz/`: fuzz targets plus corpus and coverage artifacts.
- `scripts/`: Composer-driven helpers (coverage, infection, backward-compatibility).
- `vendor/`: Composer-managed dependencies (generated; do not edit).

## Build, Test, and Development Commands
- `composer install`: install dependencies for local development.
- `composer test`: run the PHPUnit suite.
- `composer lint`: run style checks plus static analysis and deptrac.
- `composer analyze`: run phpstan, psalm, and phan.
- `composer check`: lint + unit tests (use for quick verification).
- `composer ci`: check + mutation testing (infection).
- `composer bench`: run phpbench benchmarks.
- `composer fuzz:smoke`: short fuzz run for regression detection.

## Coding Style & Naming Conventions
- PSR-12 baseline with Slevomat rules enforced by `phpcs` (`composer phpcs`, auto-fix with `composer phpcbf`).
- 4-space indentation, `declare(strict_types=1);` at the top of PHP files.
- Class names match file names; namespaces follow `Krvh\MinimalPhpAsync` and `Krvh\MinimalPhpAsync\Tests`.
- Test files use the `*Test.php` suffix.

## Testing Guidelines
- PHPUnit config lives in `phpunit.xml`; tests are random order and fail on warnings/skips, so avoid shared global state.
- Keep fixtures and fakes in `tests/Support/` rather than inline in tests.
- Use `composer coverage` and `composer infection` for deeper verification when changing core runtime behavior.

## Commit & Pull Request Guidelines
- Recent commits use short, lowercase, imperative summaries (e.g., "tooling update"); follow that style unless a new convention is agreed.
- PRs should state intent, impact, and list commands run (e.g., `composer check` or `composer ci`).
- Link related issues and update tests/docs when changing public APIs.

## Configuration Notes
- PHP >=8.5 is required; some dev tools expect `ext-pcntl` (see `composer.json`).
