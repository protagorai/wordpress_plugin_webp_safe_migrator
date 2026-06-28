<?php
/**
 * Integration: the real conversion pipeline + its filesystem outputs, driven
 * through the live plugin instance against the WordPress test database.
 */
class Test_Conversion_And_FS extends WP_UnitTestCase {

    /** @var WebP_Safe_Migrator */
    private $plugin;

    public function set_up(): void {
        parent::set_up();
        $this->plugin = $GLOBALS['webp_safe_migrator'];
        WebP_Reflect::set($this->plugin, 'settings', array_merge(
            WebP_Reflect::get($this->plugin, 'settings'),
            ['target_format' => 'webp', 'quality' => 75, 'enable_bounding_box' => 0,
             'check_filename_dimensions' => 0, 'skip_folders' => '', 'skip_mimes' => '']
        ));
    }

    public function tear_down(): void {
        WebP_Image_Factory::cleanup();
        parent::tear_down();
    }

    public function test_jpeg_converts_with_sizes_backup_and_report(): void {
        $att  = WebP_Image_Factory::attachment('jpeg', 1200, 900);
        $orig = get_attached_file($att);

        $ok = $this->plugin->process_attachment($att, 75, true); // validation: keep originals
        $this->assertTrue($ok, 'process_attachment should succeed');

        $new = get_attached_file($att);
        $this->assertStringEndsWith('.webp', $new);
        $this->assertFileExists($new);
        $this->assertSame('image/webp', get_post_mime_type($att));

        // All regenerated size variants exist on disk and are WebP.
        $meta = wp_get_attachment_metadata($att);
        $this->assertNotEmpty($meta['sizes']);
        $uploads = wp_get_upload_dir();
        $dir = trailingslashit($uploads['basedir']) . trailingslashit(dirname($meta['file']));
        foreach ($meta['sizes'] as $size) {
            $this->assertStringEndsWith('.webp', $size['file']);
            $this->assertFileExists($dir . $size['file']);
        }

        // Status + backup of the original.
        $this->assertSame('relinked', get_post_meta($att, WebP_Safe_Migrator::STATUS_META, true));
        $backup = get_post_meta($att, WebP_Safe_Migrator::BACKUP_META, true);
        $this->assertNotEmpty($backup);
        $this->assertDirectoryExists($backup);
        $this->assertFileExists(trailingslashit($backup) . basename($orig));
        $this->assertFileDoesNotExist($orig, 'original should be moved into backup in validation mode');

        // Report carries the URL map needed for rollback.
        $report = json_decode(get_post_meta($att, WebP_Safe_Migrator::REPORT_META, true), true);
        $this->assertIsArray($report);
        $this->assertArrayHasKey('url_map', $report);
        $this->assertGreaterThan(0, $report['map_count']);
    }

    public function test_png_immediate_commit_deletes_original(): void {
        $att  = WebP_Image_Factory::attachment('png', 500, 400);
        $orig = get_attached_file($att);

        $ok = $this->plugin->process_attachment($att, 80, false); // no validation
        $this->assertTrue($ok);
        $this->assertSame('committed', get_post_meta($att, WebP_Safe_Migrator::STATUS_META, true));
        $this->assertFileDoesNotExist($orig);
        $this->assertStringEndsWith('.webp', get_attached_file($att));
    }

    public function test_already_webp_is_left_untouched(): void {
        $att = WebP_Image_Factory::attachment('webp', 320, 240);
        $ok  = $this->plugin->process_attachment($att, 75, true);
        $this->assertTrue($ok); // same format, no reprocessing needed
        $this->assertSame('image/webp', get_post_mime_type($att));
    }
}
