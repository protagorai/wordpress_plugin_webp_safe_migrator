<?php
/**
 * WebP Test Helper Class
 * 
 * Provides utility methods for testing the WebP Safe Migrator plugin
 */

class WebP_Test_Helper {
    
    private static $created_files = [];
    private static $created_attachments = [];
    
    /**
     * Create a test image file
     */
    public static function create_test_image($width = 100, $height = 100, $type = 'jpeg') {
        $upload_dir = wp_get_upload_dir();
        $filename = 'test-image-' . uniqid() . '.' . ($type === 'jpeg' ? 'jpg' : $type);
        $filepath = trailingslashit($upload_dir['basedir']) . $filename;
        
        // Ensure directory exists
        wp_mkdir_p(dirname($filepath));
        
        // Create image using GD
        $image = imagecreatetruecolor($width, $height);
        
        // Fill with a colored background
        $bg_color = imagecolorallocate($image, 100, 150, 200);
        imagefill($image, 0, 0, $bg_color);
        
        // Add some content (text)
        $text_color = imagecolorallocate($image, 255, 255, 255);
        imagestring($image, 5, 10, 10, 'TEST', $text_color);
        
        // Save image
        switch ($type) {
            case 'jpeg':
                imagejpeg($image, $filepath, 90);
                break;
            case 'png':
                imagepng($image, $filepath);
                break;
            case 'gif':
                imagegif($image, $filepath);
                break;
            case 'webp':
                if (function_exists('imagewebp')) {
                    imagewebp($image, $filepath, 80);
                } else {
                    throw new Exception('WebP support not available');
                }
                break;
        }
        
        imagedestroy($image);
        
        self::$created_files[] = $filepath;
        return $filepath;
    }
    
    /**
     * Create an animated GIF for testing
     */
    public static function create_animated_gif($width = 50, $height = 50) {
        $upload_dir = wp_get_upload_dir();
        $filename = 'animated-test-' . uniqid() . '.gif';
        $filepath = trailingslashit($upload_dir['basedir']) . $filename;
        
        // Create a simple "animated" GIF by adding NETSCAPE2.0 extension
        $image = imagecreatetruecolor($width, $height);
        $bg_color = imagecolorallocate($image, 255, 0, 0);
        imagefill($image, 0, 0, $bg_color);
        
        // Start output buffering to capture GIF data
        ob_start();
        imagegif($image);
        $gif_data = ob_get_contents();
        ob_end_clean();
        
        // Add NETSCAPE2.0 extension to make it appear animated
        $animated_marker = "NETSCAPE2.0\x03\x01\x00\x00\x00";
        $gif_data = str_replace("GIF89a", "GIF89a" . $animated_marker, $gif_data);
        
        file_put_contents($filepath, $gif_data);
        imagedestroy($image);
        
        self::$created_files[] = $filepath;
        return $filepath;
    }
    
    /**
     * Create a test attachment from an image file
     */
    public static function create_test_attachment($filepath, $parent_post_id = 0) {
        $filename = basename($filepath);
        $upload_dir = wp_get_upload_dir();
        
        // Get MIME type
        $wp_filetype = wp_check_filetype($filename, null);
        $mime_type = $wp_filetype['type'];
        
        // Create attachment post
        $attachment = [
            'post_mime_type' => $mime_type,
            'post_title' => sanitize_file_name($filename),
            'post_content' => '',
            'post_status' => 'inherit'
        ];
        
        if ($parent_post_id) {
            $attachment['post_parent'] = $parent_post_id;
        }
        
        $attachment_id = wp_insert_attachment($attachment, $filepath);
        
        if (is_wp_error($attachment_id)) {
            throw new Exception('Failed to create attachment: ' . $attachment_id->get_error_message());
        }
        
        // Generate attachment metadata
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata($attachment_id, $filepath);
        wp_update_attachment_metadata($attachment_id, $metadata);
        
        self::$created_attachments[] = $attachment_id;
        return $attachment_id;
    }
    
    /**
     * Create a test post with images
     */
    public static function create_test_post_with_images($image_count = 2) {
        $post_id = wp_insert_post([
            'post_title' => 'Test Post with Images',
            'post_content' => 'This is a test post.',
            'post_status' => 'publish',
            'post_type' => 'post'
        ]);
        
        $image_urls = [];
        
        for ($i = 0; $i < $image_count; $i++) {
            $image_path = self::create_test_image(200 + ($i * 50), 150 + ($i * 30));
            $attachment_id = self::create_test_attachment($image_path, $post_id);
            $image_urls[] = wp_get_attachment_url($attachment_id);
        }
        
        // Add images to post content
        $content = get_post($post_id)->post_content;
        foreach ($image_urls as $url) {
            $content .= "\n<img src=\"$url\" alt=\"Test Image\" />";
        }
        
        wp_update_post([
            'ID' => $post_id,
            'post_content' => $content
        ]);
        
        return [
            'post_id' => $post_id,
            'image_urls' => $image_urls
        ];
    }
    
    /**
     * Create test metadata entries
     */
    public static function create_test_metadata($post_id, $meta_key, $meta_value) {
        add_post_meta($post_id, $meta_key, $meta_value);
        return true;
    }
    
    /**
     * Create test options with image URLs
     */
    public static function create_test_options_with_images() {
        $image_path = self::create_test_image();
        $attachment_id = self::create_test_attachment($image_path);
        $image_url = wp_get_attachment_url($attachment_id);
        
        $options = [
            'test_single_image' => $image_url,
            'test_array_with_images' => [
                'header_image' => $image_url,
                'logo' => $image_url,
                'gallery' => [$image_url, $image_url]
            ],
            'test_serialized_data' => serialize([
                'theme_options' => [
                    'background' => $image_url
                ]
            ])
        ];
        
        foreach ($options as $key => $value) {
            add_option($key, $value);
        }
        
        return [
            'options' => $options,
            'image_url' => $image_url,
            'attachment_id' => $attachment_id
        ];
    }
    
    /**
     * Assert that a file is a valid WebP image
     */
    public static function assert_valid_webp($filepath) {
        if (!file_exists($filepath)) {
            throw new Exception("File does not exist: $filepath");
        }
        
        $image_info = getimagesize($filepath);
        if ($image_info === false) {
            throw new Exception("Invalid image file: $filepath");
        }
        
        if ($image_info['mime'] !== 'image/webp') {
            throw new Exception("File is not WebP format: $filepath (got {$image_info['mime']})");
        }
        
        return true;
    }
    
    /**
     * Get file size in a human-readable format
     */
    public static function format_file_size($size) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $size > 0 ? floor(log($size, 1024)) : 0;
        return number_format($size / pow(1024, $power), 2) . ' ' . $units[$power];
    }
    
    /**
     * Compare two images and return similarity percentage
     */
    public static function compare_images($image1_path, $image2_path) {
        if (!file_exists($image1_path) || !file_exists($image2_path)) {
            return 0;
        }
        
        $img1 = imagecreatefromstring(file_get_contents($image1_path));
        $img2 = imagecreatefromstring(file_get_contents($image2_path));
        
        if (!$img1 || !$img2) {
            return 0;
        }
        
        $width1 = imagesx($img1);
        $height1 = imagesy($img1);
        $width2 = imagesx($img2);
        $height2 = imagesy($img2);
        
        // Images must have same dimensions for comparison
        if ($width1 !== $width2 || $height1 !== $height2) {
            imagedestroy($img1);
            imagedestroy($img2);
            return 0;
        }
        
        $total_pixels = $width1 * $height1;
        $different_pixels = 0;
        
        for ($x = 0; $x < $width1; $x++) {
            for ($y = 0; $y < $height1; $y++) {
                $color1 = imagecolorat($img1, $x, $y);
                $color2 = imagecolorat($img2, $x, $y);
                
                if ($color1 !== $color2) {
                    $different_pixels++;
                }
            }
        }
        
        imagedestroy($img1);
        imagedestroy($img2);
        
        return (1 - ($different_pixels / $total_pixels)) * 100;
    }
    
    /**
     * Clean up all created test files and attachments
     */
    public static function cleanup_test_files() {
        // Delete created attachments
        foreach (self::$created_attachments as $attachment_id) {
            wp_delete_attachment($attachment_id, true);
        }
        
        // Delete created files
        foreach (self::$created_files as $filepath) {
            if (file_exists($filepath)) {
                unlink($filepath);
            }
        }
        
        // Clean up any remaining WebP files in test directory
        $upload_dir = wp_get_upload_dir();
        $test_files = glob(trailingslashit($upload_dir['basedir']) . '*test*');
        foreach ($test_files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        
        // Reset arrays
        self::$created_files = [];
        self::$created_attachments = [];
    }
    
    /**
     * Create a mock WordPress environment for testing
     */
    public static function setup_mock_wordpress() {
        // Mock WordPress functions if not in WordPress environment
        if (!function_exists('wp_get_upload_dir')) {
            function wp_get_upload_dir() {
                return [
                    'basedir' => WEBP_MIGRATOR_TEST_UPLOADS,
                    'baseurl' => 'http://example.org/wp-content/uploads/test/'
                ];
            }
        }
        
        if (!function_exists('wp_mkdir_p')) {
            function wp_mkdir_p($target) {
                return wp_mkdir_p($target);
            }
        }
    }
    
    /**
     * Generate test data for performance testing
     */
    public static function generate_performance_test_data($attachment_count = 100) {
        $attachments = [];
        
        for ($i = 0; $i < $attachment_count; $i++) {
            $width = rand(100, 1000);
            $height = rand(100, 1000);
            $type = ['jpeg', 'png', 'gif'][array_rand(['jpeg', 'png', 'gif'])];
            
            $image_path = self::create_test_image($width, $height, $type);
            $attachment_id = self::create_test_attachment($image_path);
            
            $attachments[] = [
                'id' => $attachment_id,
                'path' => $image_path,
                'type' => $type,
                'dimensions' => [$width, $height]
            ];
        }
        
        return $attachments;
    }
}
