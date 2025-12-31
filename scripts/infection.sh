#!/usr/bin/env bash
set -euo pipefail

INFECTION="vendor/bin/infection"
PLUGIN="vendor/bin/roave-infection-static-analysis-plugin"
EXTRA_ARGS=()

if [[ -x "$PLUGIN" ]]; then
  INFECTION="$PLUGIN"
  EXTRA_ARGS+=(--psalm-config=psalm.xml)
fi

if php -m | grep -qi '^xdebug$'; then
  XDEBUG_MODE=coverage php -d memory_limit=512M "$INFECTION" --threads=max "${EXTRA_ARGS[@]}"
  exit $?
fi

if php -m | grep -qi '^pcov$'; then
  php -d pcov.enabled=1 -d pcov.directory=src "$INFECTION" --threads=max "${EXTRA_ARGS[@]}"
  exit $?
fi

echo "No coverage driver available (xdebug or pcov); skipping infection." >&2
exit 0
