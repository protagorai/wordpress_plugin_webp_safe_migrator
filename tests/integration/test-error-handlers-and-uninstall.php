<?php
/**
 * Integration: per-item error-manager handlers, plugin activation, and uninstall.
 *
 * Covers handle_actions() -> handle_error_actions() branches:
 *   - 'remove_error'     (nonce 'remove_error')
 *   - 'retry_conversion' (nonce 'retry_conversion')
 *   - 'rollback_single'  (nonce 'rollback_single')
 * plus on_activate() option creation and the include-time cleanup performed by
 * src/uninstall.php.
 *
 * These all run against the global plugin instance the same way admin_init would
 * invoke them on a real POST request.
 *
 * Caching/ordering notes handled below:
 *   - Several handlers (and the uninstaller) mutate postmeta via paths that leave
 *     the object cache stale, so reads are preceded by wp_cache_delete()/
 *     wp_cache_flush() and confirmed with direct $wpdb counts where it matters.
 *   - test_uninstall_cleanup MUST define WP_UNINSTALL_PLUGIN before requiring
 *     src/uninstall.php; otherwise the file's `exit` guard would kill the whole
 *     PHPUnit run. require_once is used so the cleanup (which executes at include
 *     time) runs exactly once even if another test were to pull it in.
 */
class Test_Error_Handlers_And_Uninstall extends WP_UnitTestCase {

    /** @var WebP_Safe_Migrator */
    private $plugin;

    public function set_up(): void {
        parent::set_up();

        $this->plugin = $GLOBALS['webp_safe_migrator'];

        // An administrator is required: every handler bails on !current_user_can('manage_options').
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        // Merge-reset the in-memory settings to known webp defaults so handlers
        // (which read $this->settings['quality'], target_format, validation, ...)
        // behave deterministically regardless of prior tests.
        $defaults = [
            'target_format'             => 'webp',
            'quality'                   => 75,
            'webp_quality'              => 75,
            'avif_quality'              => 60,
            'avif_speed'                => 6,
            'jxl_quality'               => 80,
            'jxl_effort'                => 7,
            'batch_size'                => 10,
            'validation'                => 1,
            'skip_folders'              => '',
            'skip_mimes'                => '',
            'enable_bounding_box'       => 0,
            'bounding_box_mode'         => 'max',
            'bounding_box_width'        => 1920,
            'bounding_box_height'       => 1080,
            'check_filename_dimensions' => 0,
        ];
        $current = WebP_Reflect::get($this->plugin, 'settings');
        WebP_Reflect::set($this->plugin, 'settings', array_merge($defaults, (array) $current, $defaults));
    }

    public function tear_down(): void {
        $_POST    = [];
        $_REQUEST = [];

        WebP_Image_Factory::cleanup();
        parent::tear_down();
    }

    /**
     * The 'remove_error' branch deletes ERROR_META and drops the id from the
     * central conversion-errors JSON.
     */
    public function test_remove_error_handler(): void {
        $att = WebP_Image_Factory::attachment('jpeg', 80, 80);

        // Log an error so there is something to remove.
        WebP_Reflect::call($this->plugin, 'log_conversion_error', [$att, 'boom', 'format_conversion', []]);
        $this->assertNotEmpty(
            get_post_meta($att, WebP_Safe_Migrator::ERROR_META, true),
            'ERROR_META should be populated by log_conversion_error()'
        );

        $_POST = [
            WebP_Safe_Migrator::NONCE => wp_create_nonce('remove_error'),
            'remove_error'            => '1',
            'attachment_id'           => (string) $att,
        ];

        $this->plugin->handle_actions();

        // The handler deletes the meta directly; drop the stale cache before reading.
        wp_cache_delete($att, 'post_meta');
        $this->assertSame(
            '',
            get_post_meta($att, WebP_Safe_Migrator::ERROR_META, true),
            'ERROR_META should be deleted by the remove_error handler'
        );

        // The central JSON log should no longer list this attachment id.
        $uploads  = wp_get_upload_dir();
        $log_file = trailingslashit($uploads['basedir']) . 'webp-migrator-conversion-errors.json';
        if (file_exists($log_file)) {
            $data = json_decode((string) file_get_contents($log_file), true) ?: [];
            $this->assertArrayNotHasKey(
                (string) $att,
                $data,
                'The attachment id must be removed from the central error JSON'
            );
            $this->assertArrayNotHasKey(
                $att,
                $data,
                'The attachment id must be removed from the central error JSON (int key)'
            );
        }
    }

    /**
     * The 'retry_conversion' branch re-runs process_attachment(), converting the
     * image to the target format (webp).
     */
    public function test_retry_conversion_handler(): void {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('imagewebp() not available - cannot exercise conversion.');
        }

        $att = WebP_Image_Factory::attachment('jpeg', 120, 90);
        $this->assertSame('image/jpeg', get_post_mime_type($att));

        $_POST = [
            WebP_Safe_Migrator::NONCE => wp_create_nonce('retry_conversion'),
            'retry_conversion'        => '1',
            'attachment_id'           => (string) $att,
        ];

        $this->plugin->handle_actions();

        $this->assertSame(
            'image/webp',
            get_post_mime_type($att),
            'retry_conversion should run process_attachment() and convert to webp'
        );
    }

    /**
     * The 'rollback_single' branch restores the original (non-webp) attachment
     * from the backup created by a validation-mode conversion.
     */
    public function test_rollback_single_handler(): void {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('imagewebp() not available - cannot exercise conversion.');
        }

        $att = WebP_Image_Factory::attachment('jpeg', 120, 90);

        // Convert in validation mode (true) so a backup is kept for rollback.
        $this->assertTrue(
            $this->plugin->process_attachment($att, 75, true),
            'process_attachment() should succeed in validation mode'
        );
        $this->assertSame('image/webp', get_post_mime_type($att), 'Attachment should now be webp');

        $_POST = [
            WebP_Safe_Migrator::NONCE => wp_create_nonce('rollback_single'),
            'rollback_single'         => '1',
            'attachment_id'           => (string) $att,
        ];

        $this->plugin->handle_actions();

        $this->assertNotSame(
            'image/webp',
            get_post_mime_type($att),
            'rollback_single should revert the attachment off webp'
        );
    }

    /**
     * on_activate() creates the settings OPTION when absent. GD/Imagick is present
     * in the test environment (we generate real images), so it will not
     * deactivate/wp_die().
     */
    public function test_on_activate_creates_option(): void {
        delete_option(WebP_Safe_Migrator::OPTION);
        $this->assertFalse(get_option(WebP_Safe_Migrator::OPTION));

        $this->plugin->on_activate();

        $opt = get_option(WebP_Safe_Migrator::OPTION);
        $this->assertIsArray($opt, 'on_activate() should create the settings option as an array');
        $this->assertNotEmpty($opt, 'The created settings option should be non-empty');
    }

    /**
     * src/uninstall.php deletes the plugin option and all _webp_% postmeta at
     * include time.
     *
     * IMPORTANT: WP_UNINSTALL_PLUGIN must be defined BEFORE the require, otherwise
     * uninstall.php's `if (!defined('WP_UNINSTALL_PLUGIN')) exit;` guard would
     * terminate the whole test process.
     */
    public function test_uninstall_cleanup(): void {
        global $wpdb;

        // Seed data the uninstaller is expected to remove.
        update_option(WebP_Safe_Migrator::OPTION, ['x' => 1]);
        $this->assertNotFalse(get_option(WebP_Safe_Migrator::OPTION));

        $att = WebP_Image_Factory::attachment('jpeg', 60, 60);
        update_post_meta($att, WebP_Safe_Migrator::STATUS_META, 'committed');
        $this->assertSame('committed', get_post_meta($att, WebP_Safe_Migrator::STATUS_META, true));

        // Run the uninstaller exactly once. The guard MUST be satisfied first.
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', true);
        }
        require_once dirname(__DIR__, 2) . '/src/uninstall.php';

        // The uninstaller uses delete_option() + direct SQL deletes; flush the
        // object cache so subsequent reads do not return stale values.
        wp_cache_flush();

        $this->assertFalse(
            get_option(WebP_Safe_Migrator::OPTION),
            'Plugin settings option must be deleted by uninstall'
        );

        // Confirm with a direct DB count (cache-independent) that no _webp_% postmeta survives.
        $remaining = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE '_webp_%'"
        );
        $this->assertSame(0, $remaining, 'All _webp_% postmeta rows must be deleted by uninstall');

        $this->assertSame(
            '',
            get_post_meta($att, WebP_Safe_Migrator::STATUS_META, true),
            'STATUS_META should be gone after uninstall cleanup'
        );
    }
}
