#!/usr/bin/env bash
# One-command END-TO-END test for WebP Safe Migrator.
#
# Stands up a real WordPress (Apache) + MariaDB in containers, with WP-CLI and the
# image libraries installed, then: installs WordPress, activates the plugin, seeds
# media, runs an actual conversion, and asserts the results against the live site.
#
# Requires Docker (or Podman). Nothing needs to be installed on the host.
set -uo pipefail

cd "$(dirname "$0")/.."

CF="setup/docker/docker-compose.e2e.yml"

engine="docker"
if ! command -v docker >/dev/null 2>&1; then
  if command -v podman >/dev/null 2>&1; then
    engine="podman"
  else
    echo "Error: docker or podman is required." >&2
    exit 1
  fi
fi

echo "==> Building & starting the e2e stack ($engine compose)"
if ! "$engine" compose -f "$CF" up -d --build; then
  echo "Error: failed to start the e2e stack." >&2
  exit 1
fi

echo "==> Waiting for the WordPress container to accept commands"
for _ in $(seq 1 30); do
  if "$engine" compose -f "$CF" exec -T wordpress true >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

echo "==> Running the E2E test inside the WordPress container"
# Feed the script via stdin rather than passing an in-container path: on Windows
# Git-Bash, MSYS rewrites '/opt/...' args into 'C:/Program Files/Git/opt/...'
# before they reach docker-compose.exe. stdin avoids that entirely. The
# MSYS_*  guards protect any remaining absolute-path args.
MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*' \
  "$engine" compose -f "$CF" exec -T wordpress bash -s < setup/scripts/e2e-tests.sh
code=$?

if [ "$code" -ne 0 ]; then
  echo "==> E2E failed (exit $code); recent WordPress logs:"
  "$engine" compose -f "$CF" logs --tail=60 wordpress || true
fi

echo "==> Tearing down the e2e stack"
"$engine" compose -f "$CF" down -v >/dev/null 2>&1 || true

exit $code
