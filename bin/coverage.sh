#!/usr/bin/env bash
# Measure test coverage (lines + branches) for WebP Safe Migrator.
#
#   PHP : runs the PHPUnit suite in a container with Xdebug coverage on, writing
#         build/coverage/clover.xml + an HTML report under build/coverage/html.
#   JS  : if Node is available, runs c8 over admin/js with the JS tests, writing
#         coverage/lcov.info.
#
# Finally prints a combined per-language line% / branch% summary and the distance
# to the 95% target. Requires Docker or Podman (for PHP) and optionally Node (JS).
set -uo pipefail
cd "$(dirname "$0")/.."

CF="setup/docker/docker-compose.test.yml"

engine="docker"
if ! command -v docker >/dev/null 2>&1; then
  if command -v podman >/dev/null 2>&1; then engine="podman"; else
    echo "Error: docker or podman required for PHP coverage." >&2; exit 1; fi
fi

echo "==> PHP coverage ($engine, Xdebug)"
export XDEBUG_MODE=coverage
"$engine" compose -f "$CF" up --build --abort-on-container-exit --exit-code-from tests
php_code=$?
"$engine" compose -f "$CF" down -v >/dev/null 2>&1 || true

if command -v node >/dev/null 2>&1 && command -v npm >/dev/null 2>&1; then
  echo "==> JS coverage (c8)"
  npm ci --silent 2>/dev/null || npm install --silent
  npm run --silent coverage || echo "JS coverage run reported failures (continuing)"
else
  echo "==> Skipping JS coverage (Node/npm not found)"
fi

echo ""
echo "==> Combined coverage summary"
python_bin="python"
command -v python >/dev/null 2>&1 || python_bin="python3"
"$python_bin" bin/coverage-summary.py \
  --clover build/coverage/clover.xml \
  --lcov coverage/lcov.info \
  --target 95 || true

exit $php_code
