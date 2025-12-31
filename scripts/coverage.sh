#!/usr/bin/env bash
set -euo pipefail

PHPUNIT="vendor/bin/phpunit"

if php -m | grep -qi '^xdebug$'; then
  XDEBUG_MODE=coverage php -d memory_limit=512M "$PHPUNIT" \
    --coverage-text \
    --path-coverage \
    --show-uncovered-for-coverage-text \
    --coverage-filter src
  exit $?
fi

if php -m | grep -qi '^pcov$'; then
  php -d pcov.enabled=1 -d pcov.directory=src "$PHPUNIT" \
    --coverage-text \
    --show-uncovered-for-coverage-text \
    --coverage-filter src
  exit $?
fi

echo "No coverage driver available (xdebug or pcov); running tests without coverage." >&2
php "$PHPUNIT"
