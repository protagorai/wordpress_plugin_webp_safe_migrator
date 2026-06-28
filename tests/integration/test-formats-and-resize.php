<?php
/**
 * Integration: AVIF / JPEG-XL conversion and the bounding-box resize path,
 * driven through the live plugin instance.
 *
 * The plugin instance ($GLOBALS['webp_safe_migrator']) is shared/global, so the
 * full settings array is snapshotted in set_up() and restored in tear_down() to
 * avoid leaking settings into other test files.
 *
 * Encoder-dependent tests are guarded and skipped when the relevant encoder is
 * unavailable (AVIF via GD imageavif() or Imagick; JPEG XL via Imagick only).
 */
class Test_Formats_And_Resize extends WP_UnitTestCase {

    /** @var WebP_Safe_Migrator */
    private $plugin;

    /** @var array Snapshot of the plugin's settings taken in set_up(). */
    private $settings_snapshot;

    public function set_up(): void {
        parent::set_up();
        $this->plugin = $GLOBALS['webp_safe_migrator'];
        // Snapshot the full settings array so tear_down() can restore it exactly.
        $this->settings_snapshot = WebP_Reflect::get($this->plugin, 'settings');
    }

    public function tear_down(): void {
        // Restore the original settings on the shared/global plugin instance.
        WebP_Reflect::set($this->plugin, 'settings', $this->settings_snapshot);
        WebP_Image_Factory::cleanup();
        parent::tear_down();
    }

    /** @return bool Whether an AVIF encoder is available (GD or Imagick). */
    private function avif_available(): bool {
        if (function_exists('imageavif')) {
            return true;
        }
        if (class_exists('Imagick')) {
            try {
                return (bool) @(new Imagick())->queryFormats('AVIF');
            } catch (Throwable $e) {
                return false;
            }
        }
        return false;
    }

    /** @return bool Whether a JPEG XL encoder is available (Imagick only). */
    private function jxl_available(): bool {
        if (class_exists('Imagick')) {
            try {
                return (bool) @(new Imagick())->queryFormats('JXL');
            } catch (Throwable $e) {
                return false;
            }
        }
        return false;
    }

    public function test_convert_avif_direct(): void {
        if (!$this->avif_available()) {
            $this->markTestSkipped('No AVIF encoder available (imageavif/Imagick AVIF).');
        }

        $src  = WebP_Image_Factory::image('jpeg', 120, 90);
        $dest = $src . '.avif';

        $ok = WebP_Reflect::call($this->plugin, 'convert_avif_direct', [$src, $dest, 60, 6]);
        $this->assertTrue((bool) $ok, 'convert_avif_direct should report success');
        $this->assertFileExists($dest);

        $info = @getimagesize($dest);
        if ($info !== false && isset($info['mime'])) {
            $this->assertSame('image/avif', $info['mime']);
        }

        @unlink($dest);
    }

    public function test_process_attachment_to_avif(): void {
        if (!$this->avif_available()) {
            $this->markTestSkipped('No AVIF encoder available (imageavif/Imagick AVIF).');
        }

        WebP_Reflect::set($this->plugin, 'settings', array_merge(
            WebP_Reflect::get($this->plugin, 'settings'),
            ['target_format' => 'avif', 'quality' => 60, 'enable_bounding_box' => 0,
             'check_filename_dimensions' => 0, 'skip_folders' => '', 'skip_mimes' => '']
        ));

        $att = WebP_Image_Factory::attachment('jpeg', 300, 200);

        $ok = $this->plugin->process_attachment($att, 60, true);
        $this->assertTrue($ok, 'process_attachment should succeed converting to AVIF');

        $this->assertStringEndsWith('.avif', get_attached_file($att));
        $this->assertSame('image/avif', get_post_mime_type($att));
    }

    public function test_convert_jxl_direct(): void {
        if (!$this->jxl_available()) {
            $this->markTestSkipped('No JPEG XL encoder available (Imagick JXL).');
        }

        $src  = WebP_Image_Factory::image('jpeg', 120, 90);
        $dest = $src . '.jxl';

        $ok = WebP_Reflect::call($this->plugin, 'convert_jxl_direct', [$src, $dest, 80, 7]);
        $this->assertTrue((bool) $ok, 'convert_jxl_direct should report success');
        $this->assertFileExists($dest);

        $info = @getimagesize($dest);
        if ($info !== false && isset($info['mime'])) {
            $this->assertSame('image/jxl', $info['mime']);
        }

        @unlink($dest);
    }

    public function test_bounding_box_max_shrinks_image(): void {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('WebP encoding (imagewebp) not available.');
        }

        WebP_Reflect::set($this->plugin, 'settings', array_merge(
            WebP_Reflect::get($this->plugin, 'settings'),
            ['target_format' => 'webp', 'quality' => 75, 'enable_bounding_box' => 1,
             'bounding_box_mode' => 'max', 'bounding_box_width' => 100, 'bounding_box_height' => 100,
             'check_filename_dimensions' => 0, 'skip_folders' => '', 'skip_mimes' => '']
        ));

        $att = WebP_Image_Factory::attachment('jpeg', 400, 300);

        $ok = $this->plugin->process_attachment($att, 75, true);
        $this->assertTrue($ok, 'process_attachment should succeed with bounding box resize');

        // 400x300 fit into a 100x100 max box -> ~100x75.
        $size = getimagesize(get_attached_file($att));
        $this->assertIsArray($size);
        $this->assertLessThanOrEqual(100, $size[0], 'resized width should fit within the bounding box');
        $this->assertLessThanOrEqual(100, $size[1], 'resized height should fit within the bounding box');
    }
}
