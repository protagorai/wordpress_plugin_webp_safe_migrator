<?php
/**
 * Integration: CORE edge-case coverage.
 *
 * Targets converter branches (resize path, fallback metadata, stats round-trip)
 * and main-pipeline error/branch paths (process_attachment file-validation error,
 * convert_to_format unknown-format rejection, and the handle_actions() commit_all /
 * rollback_all_pending bulk branches) that the existing suites do not exercise.
 *
 * Each test runs inside WP_UnitTestCase's per-test DB transaction, so relinked
 * rows written by one test are rolled back before the next.
 */
class Test_Core_Edges extends WP_UnitTestCase {

    /** @var WebP_Safe_Migrator */
    private $plugin;

    public function set_up(): void {
        parent::set_up();
        $this->plugin = $GLOBALS['webp_safe_migrator'];
        WebP_Reflect::set($this->plugin, 'settings', array_merge(
            WebP_Reflect::get($this->plugin, 'settings'),
            [
                'target_format'             => 'webp',
                'quality'                   => 75,
                'enable_bounding_box'       => 0,
                'check_filename_dimensions' => 0,
                'skip_folders'              => '',
                'skip_mimes'                => '',
            ]
        ));
    }

    public function tear_down(): void {
        $_POST    = [];
        $_REQUEST = [];
        WebP_Image_Factory::cleanup();
        parent::tear_down();
    }

    // ---------------------------------------------------------------------
    // Converter edge cases
    // ---------------------------------------------------------------------

    public function test_converter_resizes_large_image(): void {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('no webp');
        }

        $conv = new WebP_Migrator_Converter([
            'quality'             => 70,
            'max_width'           => 100,
            'max_height'          => 100,
            'preserve_dimensions' => false,
            'conversion_mode'     => 'both',
        ]);

        $att = WebP_Image_Factory::attachment('jpeg', 400, 300);
        $res = $conv->convert_attachment($att);

        $this->assertNotInstanceOf(WP_Error::class, $res);
        $this->assertIsArray($res);
        $this->assertArrayHasKey('conversion_stats', $res);
        $this->assertTrue($res['conversion_stats']['resized']);

        $dim = $res['conversion_stats']['new_dimensions'];
        $this->assertLessThanOrEqual(100, $dim['width']);
        $this->assertLessThanOrEqual(100, $dim['height']);
    }

    public function test_converter_build_metadata_fallback(): void {
        $conv = new WebP_Migrator_Converter();
        $path = WebP_Image_Factory::image('jpeg', 120, 80);

        $meta = WebP_Reflect::call($conv, 'build_metadata_fallback', [$path, 0]);

        $this->assertIsArray($meta);
        $this->assertArrayHasKey('file', $meta);
        $this->assertSame(120, $meta['width']);
        $this->assertSame(80, $meta['height']);
    }

    public function test_converter_stats_roundtrip(): void {
        $conv = new WebP_Migrator_Converter();
        $att  = WebP_Image_Factory::attachment('png', 60, 60);

        $conv->save_conversion_stats($att, ['compression' => 42]);

        $this->assertSame(['compression' => 42], $conv->get_conversion_stats($att));
    }

    // ---------------------------------------------------------------------
    // Main pipeline error/branch paths
    // ---------------------------------------------------------------------

    public function test_process_attachment_missing_file_logs_error(): void {
        $att  = WebP_Image_Factory::attachment('jpeg', 80, 80);
        $file = get_attached_file($att);
        @unlink($file);

        $ok = $this->plugin->process_attachment($att, 75, true);
        $this->assertFalse($ok);

        $err = json_decode(get_post_meta($att, WebP_Safe_Migrator::ERROR_META, true) ?: '{}', true);
        $this->assertSame('file_validation', $err['step'] ?? '');
    }

    public function test_convert_to_format_rejects_unknown_format(): void {
        $path = WebP_Image_Factory::image('jpeg', 50, 50);

        $ok = WebP_Reflect::call(
            $this->plugin,
            'convert_to_format',
            [$path, $path . '.out', 'not-a-format', []]
        );

        $this->assertFalse($ok);
    }

    public function test_commit_all_clears_relinked(): void {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('no webp');
        }
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $a = WebP_Image_Factory::attachment('jpeg', 100, 100);
        $b = WebP_Image_Factory::attachment('png', 100, 100);

        $this->assertTrue($this->plugin->process_attachment($a, 75, true));
        $this->assertTrue($this->plugin->process_attachment($b, 75, true));

        $_POST = [
            WebP_Safe_Migrator::NONCE       => wp_create_nonce('commit_all'),
            'webp_migrator_commit_all'      => '1',
        ];
        $this->plugin->handle_actions();
        wp_cache_flush();

        // commit removes the status meta entirely
        $this->assertSame('', get_post_meta($a, WebP_Safe_Migrator::STATUS_META, true));
        $this->assertSame('', get_post_meta($b, WebP_Safe_Migrator::STATUS_META, true));
    }

    public function test_rollback_all_reverts_relinked(): void {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('no webp');
        }
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $c = WebP_Image_Factory::attachment('jpeg', 100, 100);
        $this->assertTrue($this->plugin->process_attachment($c, 75, true));

        $_POST = [
            WebP_Safe_Migrator::NONCE  => wp_create_nonce('rollback_all'),
            'rollback_all_pending'     => '1',
        ];
        $this->plugin->handle_actions();
        wp_cache_flush();

        // reverted back to the original (non-webp) mime
        $this->assertNotSame('image/webp', get_post_mime_type($c));
    }
}
