<?php
/**
 * Test WebP Migrator Converter Class
 */

class Test_WebP_Migrator_Converter extends WP_UnitTestCase {
    
    private $converter;
    private $test_image_path;
    private $test_attachment_id;
    
    public function set_up(): void {
        parent::set_up();

        // Initialize converter
        $this->converter = new WebP_Migrator_Converter([
            'quality' => 75,
            'max_width' => 0,
            'max_height' => 0,
            'preserve_dimensions' => true,
            'conversion_mode' => 'quality_only'
        ]);
        
        // Create test image
        $this->test_image_path = WebP_Test_Helper::create_test_image();
        $this->test_attachment_id = WebP_Test_Helper::create_test_attachment($this->test_image_path);
    }
    
    public function tear_down(): void {
        // Clean up test files
        WebP_Test_Helper::cleanup_test_files();
        parent::tear_down();
    }
    
    /**
     * Test converter initialization
     */
    public function test_converter_initialization() {
        $this->assertInstanceOf('WebP_Migrator_Converter', $this->converter);
    }
    
    /**
     * Test successful image conversion
     */
    public function test_successful_conversion() {
        $result = $this->converter->convert_attachment($this->test_attachment_id);
        
        $this->assertNotInstanceOf('WP_Error', $result);
        $this->assertArrayHasKey('old_meta', $result);
        $this->assertArrayHasKey('new_meta', $result);
        $this->assertArrayHasKey('conversion_stats', $result);

        // convert_attachment() generates new WebP metadata but does NOT relink
        // the attachment, so verify the generated metadata points to a .webp file.
        $this->assertArrayHasKey('file', $result['new_meta']);
        $this->assertStringEndsWith('.webp', $result['new_meta']['file']);

        // The generated WebP file should exist on disk. Resolve its absolute
        // path from the uploads basedir + the relative path in new_meta.
        $uploads = wp_get_upload_dir();
        $new_file = trailingslashit($uploads['basedir']) . $result['new_meta']['file'];
        $this->assertTrue(file_exists($new_file));
    }
    
    /**
     * Test conversion with quality setting
     */
    public function test_conversion_with_quality() {
        $converter = new WebP_Migrator_Converter(['quality' => 50]);
        $result = $converter->convert_attachment($this->test_attachment_id);
        
        $this->assertNotInstanceOf('WP_Error', $result);
        $this->assertEquals(50, $result['conversion_stats']['quality_applied']);
    }
    
    /**
     * Test conversion with resize options
     */
    public function test_conversion_with_resize() {
        $converter = new WebP_Migrator_Converter([
            'quality' => 75,
            'max_width' => 100,
            'max_height' => 100,
            'preserve_dimensions' => false,
            'conversion_mode' => 'both'
        ]);
        
        $result = $converter->convert_attachment($this->test_attachment_id);
        
        $this->assertNotInstanceOf('WP_Error', $result);
        
        if (isset($result['conversion_stats']['resized']) && $result['conversion_stats']['resized']) {
            $new_dimensions = $result['conversion_stats']['new_dimensions'];
            $this->assertLessThanOrEqual(100, $new_dimensions['width']);
            $this->assertLessThanOrEqual(100, $new_dimensions['height']);
        }
    }
    
    /**
     * Test skipping already converted WebP images
     */
    public function test_skip_already_webp() {
        // convert_attachment() never relinks the attachment, so converting a
        // JPEG twice would simply convert it again. To genuinely exercise the
        // early-return (`$mime === 'image/webp'`) branch we need a real WebP
        // attachment from the start.
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('WebP creation is not available in this environment.');
        }

        try {
            $webp_path = WebP_Test_Helper::create_test_image(100, 100, 'webp');
        } catch (Exception $e) {
            $this->markTestSkipped('WebP creation is not available: ' . $e->getMessage());
        }

        $webp_attachment_id = WebP_Test_Helper::create_test_attachment($webp_path);

        // Sanity check: the attachment really is a WebP.
        $this->assertEquals('image/webp', get_post_mime_type($webp_attachment_id));

        // Converting an already-WebP attachment should short-circuit and return true.
        $result = $this->converter->convert_attachment($webp_attachment_id);
        $this->assertTrue($result);
    }
    
    /**
     * Test animated GIF handling
     */
    public function test_animated_gif_handling() {
        $gif_path = WebP_Test_Helper::create_animated_gif();
        $gif_attachment_id = WebP_Test_Helper::create_test_attachment($gif_path);
        
        $result = $this->converter->convert_attachment($gif_attachment_id);
        
        $this->assertInstanceOf('WP_Error', $result);
        $this->assertEquals('animated_gif_skipped', $result->get_error_code());
        
        // Check status meta was set
        $status = get_post_meta($gif_attachment_id, '_webp_migrator_status', true);
        $this->assertEquals('skipped_animated_gif', $status);
    }
    
    /**
     * Test conversion validation
     */
    public function test_conversion_validation() {
        $result = $this->converter->convert_attachment($this->test_attachment_id);
        $this->assertNotInstanceOf('WP_Error', $result);
        
        $validation_result = $this->converter->validate_conversion(
            $this->test_attachment_id, 
            $result['new_meta']
        );
        
        $this->assertTrue($validation_result);
    }
    
    /**
     * Test validation with missing files
     */
    public function test_validation_with_missing_files() {
        $fake_meta = [
            'file' => '2023/01/nonexistent.webp',
            'sizes' => [
                'thumbnail' => ['file' => 'nonexistent-150x150.webp']
            ]
        ];
        
        $validation_result = $this->converter->validate_conversion(999, $fake_meta);
        
        $this->assertInstanceOf('WP_Error', $validation_result);
        $this->assertEquals('validation_failed', $validation_result->get_error_code());
    }
    
    /**
     * Test conversion statistics
     */
    public function test_conversion_statistics() {
        $result = $this->converter->convert_attachment($this->test_attachment_id);
        $stats = $result['conversion_stats'];
        
        $this->assertArrayHasKey('original_size', $stats);
        $this->assertArrayHasKey('new_size', $stats);
        $this->assertArrayHasKey('compression_ratio', $stats);
        $this->assertArrayHasKey('quality_applied', $stats);
        $this->assertArrayHasKey('original_dimensions', $stats);
        
        $this->assertGreaterThan(0, $stats['original_size']);
        $this->assertGreaterThan(0, $stats['new_size']);
        $this->assertIsNumeric($stats['compression_ratio']);
    }
    
    /**
     * Test error handling for invalid attachment
     */
    public function test_invalid_attachment_error() {
        $result = $this->converter->convert_attachment(999999);
        
        $this->assertInstanceOf('WP_Error', $result);
        $this->assertEquals('file_not_found', $result->get_error_code());
    }
    
    /**
     * Test fallback metadata creation
     */
    public function test_metadata_fallback() {
        // Create attachment without proper metadata
        $attachment_id = wp_insert_attachment([
            'post_title' => 'Test Image',
            'post_mime_type' => 'image/jpeg',
            'guid' => 'http://example.org/test.jpg'
        ]);
        
        // Set attached file without metadata
        update_post_meta($attachment_id, '_wp_attached_file', 'test.jpg');
        
        // This should trigger fallback metadata creation
        $result = $this->converter->convert_attachment($attachment_id);
        
        // Should handle gracefully (may fail due to missing actual file, but shouldn't crash)
        $this->assertTrue(is_array($result) || is_wp_error($result));
    }
}
