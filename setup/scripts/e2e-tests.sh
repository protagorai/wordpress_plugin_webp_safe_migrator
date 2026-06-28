#!/usr/bin/env bash
# Runs INSIDE the e2e WordPress container (see setup/docker/docker-compose.e2e.yml).
#
# Full integration test against a LIVE install:
#   wait for WP + DB -> verify encoder -> install WordPress -> activate plugin
#   -> seed media + a referencing post -> run a real conversion -> assert results.
set -uo pipefail

WP="wp --allow-root --path=/var/www/html"
WP_URL="${WP_URL:-http://localhost:8090}"

echo "==> Waiting for WordPress files..."
for _ in $(seq 1 60); do [ -f /var/www/html/wp-load.php ] && break; sleep 2; done
[ -f /var/www/html/wp-load.php ] || { echo "ERROR: wp-load.php never appeared"; exit 1; }

echo "==> Waiting for database..."
for _ in $(seq 1 60); do $WP db check >/dev/null 2>&1 && break; sleep 2; done

echo "==> Installing WordPress (if needed)..."
if ! $WP core is-installed >/dev/null 2>&1; then
  $WP core install --url="$WP_URL" --title="WebP E2E" \
      --admin_user=admin --admin_password=admin123 --admin_email=admin@example.com \
      --skip-email || { echo "ERROR: core install failed"; exit 1; }
fi

# Encoder preflight must run AFTER install (wp eval bootstraps a full, installed site).
echo "==> Verifying an image encoder is present..."
$WP eval 'if (!function_exists("imagewebp") && !(class_exists("Imagick") && (new Imagick())->queryFormats("WEBP"))) { WP_CLI::error("No WebP encoder (GD/Imagick) available in this container"); }' || exit 1

echo "==> Activating the plugin..."
$WP plugin activate webp-safe-migrator || { echo "ERROR: plugin activation failed"; exit 1; }

echo "==> Seeding test media + content..."
$WP eval-file /opt/e2e/seed-media.php || { echo "ERROR: seeding failed"; exit 1; }

echo "==> Running conversion: wp webp-migrator run --batch=10 ..."
$WP webp-migrator run --batch=10 || { echo "ERROR: conversion run failed"; exit 1; }

echo "==> Asserting conversion + filesystem + database state..."
$WP eval-file /opt/e2e/assert.php || exit $?

echo "==> Verifying rollback + commit lifecycle on the live install..."
$WP eval-file /opt/e2e/verify-lifecycle.php
exit $?
