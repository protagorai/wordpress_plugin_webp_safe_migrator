#!/usr/bin/env bash
# One-command containerised test run for WebP Safe Migrator.
#
# Spins up an ephemeral MariaDB + a PHP-CLI test container, installs the
# WordPress PHPUnit library, and runs the unit + integration suites. No local
# PHP/Composer/MySQL required - just Docker (or Podman).
set -uo pipefail

cd "$(dirname "$0")/.."

COMPOSE_FILE="setup/docker/docker-compose.test.yml"

engine="docker"
if ! command -v docker >/dev/null 2>&1; then
  if command -v podman >/dev/null 2>&1; then
    engine="podman"
  else
    echo "Error: neither docker nor podman found on PATH." >&2
    exit 1
  fi
fi

echo "==> Running tests with: $engine compose"
"$engine" compose -f "$COMPOSE_FILE" up --build --abort-on-container-exit --exit-code-from tests
code=$?

echo "==> Tearing down test stack"
"$engine" compose -f "$COMPOSE_FILE" down -v >/dev/null 2>&1 || true

exit $code
