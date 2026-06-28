<?php
/**
 * PHPUnit bootstrap for WebP Safe Migrator.
 *
 * Database and WordPress paths come from the WordPress PHPUnit test library
 * (installed by bin/install-wp-tests.sh). Do NOT hardcode DB credentials here -
 * they live in that library's wp-tests-config.php.
 */

// Composer autoload (PHPUnit polyfills, etc.).
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

// Point the WP test suite at the Yoast PHPUnit polyfills (PHPUnit 9 + WP test lib).
if (!defined('WP_TESTS_PHPUNIT_POLYFILLS_PATH')) {
    $polyfills = dirname(__DIR__) . '/vendor/yoast/phpunit-polyfills';
    if (is_dir($polyfills)) {
        define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', $polyfills);
    }
}

define('WEBP_MIGRATOR_TESTING', true);

// Locate the WordPress test library.
$wp_tests_dir = getenv('WP_TESTS_DIR');
if (!$wp_tests_dir) {
    $wp_tests_dir = rtrim(sys_get_temp_dir(), '/\\') . '/wordpress-tests-lib';
}

if (!file_exists($wp_tests_dir . '/includes/functions.php')) {
    fwrite(STDERR, sprintf(
        "Could not find the WordPress test library at %s\n" .
        "Install it first:\n" .
        "  bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]\n",
        $wp_tests_dir
    ));
    exit(1);
}

require_once $wp_tests_dir . '/includes/functions.php';

/**
 * Load the plugin and its supporting classes into the test WordPress instance.
 *
 * Note: the main plugin file does NOT require the includes/ classes itself, so we
 * load them here to make the converter/logger/queue available to tests.
 */
function _manually_load_plugin() {
    $base = dirname(__DIR__);
    require_once $base . '/includes/class-webp-migrator-logger.php';
    require_once $base . '/includes/class-webp-migrator-converter.php';
    require_once $base . '/includes/class-webp-migrator-queue.php';
    require $base . '/src/webp-safe-migrator.php';
}
tests_add_filter('muplugins_loaded', '_manually_load_plugin');

// Block outbound HTTP during tests.
tests_add_filter('pre_http_request', function () {
    return new WP_Error('http_request_blocked', 'External HTTP disabled during tests');
});

// Boot the WordPress test environment.
require $wp_tests_dir . '/includes/bootstrap.php';

// Test helpers.
require_once __DIR__ . '/helpers/class-webp-test-helper.php';
require_once __DIR__ . '/helpers/class-image-factory.php';
require_once __DIR__ . '/helpers/class-reflect.php';
