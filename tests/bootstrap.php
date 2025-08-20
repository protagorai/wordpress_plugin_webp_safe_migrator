<?php
/**
 * PHPUnit Bootstrap for WebP Safe Migrator Plugin Tests
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/wordpress/');
}

// Define test environment constants
define('WP_TESTS_DOMAIN', 'example.org');
define('WP_TESTS_EMAIL', 'admin@example.org');
define('WP_TESTS_TITLE', 'Test Blog');
define('WP_PHP_BINARY', 'php');

// Database configuration for tests
define('DB_NAME', 'wordpress_webp_test');
define('DB_USER', 'root');
define('DB_PASSWORD', 'root123');
define('DB_HOST', '127.0.0.1');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

// Test-specific WordPress configuration
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
define('SCRIPT_DEBUG', true);

// Plugin-specific test constants
define('WEBP_MIGRATOR_TESTING', true);
define('WEBP_MIGRATOR_TEST_UPLOADS', dirname(__FILE__) . '/fixtures/uploads/');

// WordPress test library path
$wp_tests_dir = getenv('WP_TESTS_DIR');
if (!$wp_tests_dir) {
    $wp_tests_dir = dirname(__FILE__) . '/tmp/wordpress-tests-lib';
}

// Load WordPress test functions
require_once $wp_tests_dir . '/includes/functions.php';

/**
 * Load plugin for testing
 */
function _manually_load_plugin() {
    // Load the plugin
    require dirname(__FILE__) . '/../src/webp-safe-migrator.php';
    
    // Create test uploads directory
    if (!file_exists(WEBP_MIGRATOR_TEST_UPLOADS)) {
        wp_mkdir_p(WEBP_MIGRATOR_TEST_UPLOADS);
    }
}

// Hook plugin loading
tests_add_filter('muplugins_loaded', '_manually_load_plugin');

/**
 * Set up test environment
 */
function _setup_test_environment() {
    // Override upload directory for tests
    add_filter('upload_dir', function($uploads) {
        $uploads['basedir'] = WEBP_MIGRATOR_TEST_UPLOADS;
        $uploads['baseurl'] = 'http://example.org/wp-content/uploads/test/';
        return $uploads;
    });
    
    // Disable external HTTP requests during tests
    add_filter('pre_http_request', function() {
        return new WP_Error('http_request_not_executed', 'HTTP requests disabled for tests');
    });
}

tests_add_filter('init', '_setup_test_environment');

// Load WordPress test bootstrap
require $wp_tests_dir . '/includes/bootstrap.php';

// Load test helper classes
require_once dirname(__FILE__) . '/helpers/class-webp-test-helper.php';
require_once dirname(__FILE__) . '/helpers/class-image-factory.php';
