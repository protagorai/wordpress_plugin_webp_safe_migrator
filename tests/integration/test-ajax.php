<?php
/**
 * Integration: admin-ajax handlers for the batch processor.
 *
 * Exercises ajax_get_queue_count(), ajax_process_batch() and
 * ajax_reprocess_single() through the WP_Ajax_UnitTestCase harness. All three
 * verify check_ajax_referer('webp_migrator_batch','nonce') + manage_options.
 *
 * The callbacks are registered in the plugin instance constructor (loaded at
 * bootstrap), so the wp_ajax_* hooks are already in place. As a safety net we
 * re-register them in set_up() if the harness reports no handler.
 */
class Test_Ajax extends WP_Ajax_UnitTestCase {

    /** @var WebP_Safe_Migrator */
    private $plugin;

    public function set_up(): void {
        parent::set_up();

        $this->plugin = $GLOBALS['webp_safe_migrator'];

        // WP_Ajax_UnitTestCase sets an admin user, but be explicit so the
        // manage_options checks inside the handlers pass.
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        // Defensive re-registration: if the global instance's callbacks were not
        // wired (e.g. constructor ran before the global was assigned), add them.
        if (!has_action('wp_ajax_webp_migrator_get_queue_count')) {
            add_action('wp_ajax_webp_migrator_get_queue_count', [$this->plugin, 'ajax_get_queue_count']);
        }
        if (!has_action('wp_ajax_webp_migrator_process_batch')) {
            add_action('wp_ajax_webp_migrator_process_batch', [$this->plugin, 'ajax_process_batch']);
        }
        if (!has_action('wp_ajax_webp_migrator_reprocess_single')) {
            add_action('wp_ajax_webp_migrator_reprocess_single', [$this->plugin, 'ajax_reprocess_single']);
        }
    }

    public function tear_down(): void {
        $_POST = [];
        WebP_Image_Factory::cleanup();
        parent::tear_down();
    }

    /** Decode the last AJAX response captured by the harness. */
    private function last_json(): array {
        $decoded = json_decode($this->_last_response, true);
        $this->assertIsArray($decoded, 'AJAX response should be valid JSON: ' . $this->_last_response);
        return $decoded;
    }

    public function test_ajax_get_queue_count(): void {
        $_POST['nonce'] = wp_create_nonce('webp_migrator_batch');

        try {
            $this->_handleAjax('webp_migrator_get_queue_count');
        } catch (WPAjaxDieContinueException $e) {
            // wp_send_json_success() terminates via wp_die(); expected.
        }

        $resp = $this->last_json();
        $this->assertTrue($resp['success']);
        $this->assertArrayHasKey('count', $resp['data']);
        $this->assertIsInt($resp['data']['count']);
        $this->assertGreaterThanOrEqual(0, $resp['data']['count']);
    }

    public function test_ajax_process_batch(): void {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('imagewebp not available; cannot convert to WebP.');
        }

        // Ensure the plugin targets WebP at a sane quality for this run.
        WebP_Reflect::set($this->plugin, 'settings', array_merge(
            WebP_Reflect::get($this->plugin, 'settings'),
            ['target_format' => 'webp', 'quality' => 75, 'enable_bounding_box' => 0,
             'check_filename_dimensions' => 0, 'skip_folders' => '', 'skip_mimes' => '']
        ));

        // A couple of convertible (non-WebP) attachments.
        WebP_Image_Factory::attachment('jpeg', 320, 240);
        WebP_Image_Factory::attachment('jpeg', 200, 150);

        $_POST['nonce']      = wp_create_nonce('webp_migrator_batch');
        $_POST['batch_size'] = 5;

        try {
            $this->_handleAjax('webp_migrator_process_batch');
        } catch (WPAjaxDieContinueException $e) {
            // expected
        }

        $resp = $this->last_json();
        $this->assertTrue($resp['success'], 'Batch processing should report success');
        $this->assertArrayHasKey('processed', $resp['data']);
        $this->assertGreaterThanOrEqual(1, (int) $resp['data']['processed'],
            'At least one attachment should have been processed');
    }

    public function test_ajax_reprocess_single(): void {
        $att_id = WebP_Image_Factory::attachment('jpeg', 256, 256);

        $_POST['nonce']         = wp_create_nonce('webp_migrator_batch');
        $_POST['attachment_id'] = $att_id;

        try {
            $this->_handleAjax('webp_migrator_reprocess_single');
        } catch (WPAjaxDieContinueException $e) {
            // wp_send_json_success() or wp_send_json_error() both wp_die().
        }

        // Either outcome is acceptable; assert we got a well-formed JSON
        // envelope carrying a 'success' key.
        $resp = $this->last_json();
        $this->assertArrayHasKey('success', $resp);
        $this->assertIsBool($resp['success']);
    }
}
