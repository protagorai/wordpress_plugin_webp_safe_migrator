<?php
/**
 * Harness smoke test.
 *
 * Proves the full integration environment is wired correctly: the WordPress test
 * library boots, the plugin and its supporting classes load, and the image stack
 * the conversion code depends on is actually present. If this passes, the rest of
 * the (future) integration suite has a sound foundation.
 */

class Test_Plugin_Bootstrap extends WP_UnitTestCase {

    public function test_main_plugin_class_is_loaded() {
        $this->assertTrue(
            class_exists('WebP_Safe_Migrator'),
            'Main plugin class should be loaded by the test bootstrap.'
        );
    }

    public function test_supporting_classes_are_loaded() {
        $this->assertTrue(class_exists('WebP_Migrator_Converter'), 'Converter class should load.');
        $this->assertTrue(class_exists('WebP_Migrator_Logger'), 'Logger class should load.');
        $this->assertTrue(class_exists('WebP_Migrator_Queue'), 'Queue class should load.');
    }

    public function test_supported_formats_constant_exposes_webp() {
        $this->assertArrayHasKey(
            'webp',
            WebP_Safe_Migrator::SUPPORTED_TARGET_FORMATS,
            'WebP must be a supported target format.'
        );
    }

    public function test_webp_encoding_is_available_in_test_environment() {
        $imagick_webp = false;
        if (class_exists('Imagick')) {
            $imagick_webp = !empty((new Imagick())->queryFormats('WEBP'));
        }
        $this->assertTrue(
            function_exists('imagewebp') || $imagick_webp,
            'The test image must provide WebP encoding (GD imagewebp or Imagick WEBP).'
        );
    }

    public function test_image_factory_creates_a_real_attachment() {
        $att_id = WebP_Image_Factory::attachment('jpeg', 200, 150);
        $this->assertGreaterThan(0, $att_id);
        $this->assertSame('image/jpeg', get_post_mime_type($att_id));
        $this->assertFileExists(get_attached_file($att_id));

        wp_delete_attachment($att_id, true);
        WebP_Image_Factory::cleanup();
    }
}
