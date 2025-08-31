<?php
/**
 * Okvir Image Safe Migrator Converter Class
 * 
 * Handles all image conversion operations with advanced options
 */

if (!defined('ABSPATH')) exit;

class Okvir_Image_Migrator_Converter {
    
    /** @var array Conversion settings */
    private $settings;
    
    /** @var Okvir_Image_Migrator_Logger */
    private $logger;
    
    public function __construct($settings = [], $logger = null) {
        $this->settings = wp_parse_args($settings, [
            'quality' => 75,
            'max_width' => 0,           // 0 = no limit
            'max_height' => 0,          // 0 = no limit
            'preserve_dimensions' => true,
            'conversion_mode' => 'quality_only', // quality_only, resize_only, both
            'skip_animated_gif' => true,
            'backup_originals' => true,
            'target_format' => 'webp',  // webp, avif, jxl
        ]);
        
        $this->logger = $logger ?: new Okvir_Image_Migrator_Logger();
    }
    
    /**
     * Convert attachment to target format with advanced options
     */
    public function convert_attachment($att_id, $options = []) {
        $options = wp_parse_args($options, $this->settings);
        $target_format = $options['target_format'] ?? 'webp';
        
        $this->logger->info("Starting conversion for attachment #{$att_id} to {$target_format}");
        
        // Validate attachment
        $file = get_attached_file($att_id);
        if (!$file || !file_exists($file)) {
            $this->logger->error("File not found for attachment #{$att_id}");
            return new WP_Error('file_not_found', 'Original file not found');
        }
        
        $mime = get_post_mime_type($att_id);
        $target_mime = $this->get_target_mime($target_format);
        if ($mime === $target_mime) {
            $this->logger->info("Attachment #{$att_id} already {$target_format}, skipping");
            return true; // Already target format
        }
        
        // Skip animated GIFs if configured
        if ($options['skip_animated_gif'] && $mime === 'image/gif' && $this->is_animated_gif($file)) {
            $this->logger->info("Skipping animated GIF #{$att_id}");
            update_post_meta($att_id, '_okvir_image_migrator_status', 'skipped_animated_gif');
            return new WP_Error('animated_gif_skipped', 'Animated GIF skipped');
        }
        
        // Get current metadata
        $old_meta = wp_get_attachment_metadata($att_id);
        if (!$old_meta || empty($old_meta['file'])) {
            $old_meta = $this->build_metadata_fallback($file, $att_id);
        }
        
        // Prepare new paths
        $uploads = wp_get_upload_dir();
        $old_rel = $old_meta['file'];
        $old_dir_rel = trailingslashit(dirname($old_rel));
        $old_basename = wp_basename($old_rel);
        $new_extension = $this->get_target_extension($target_format);
        $new_rel = $old_dir_rel . preg_replace('/\.\w+$/', ".{$new_extension}", $old_basename);
        $new_path = trailingslashit($uploads['basedir']) . $new_rel;
        
        // Ensure destination directory exists
        if (!wp_mkdir_p(dirname($new_path))) {
            $this->logger->error("Could not create directory for #{$att_id}");
            return new WP_Error('mkdir_failed', 'Could not create destination directory');
        }
        
        // Convert main image
        $conversion_result = $this->convert_image_with_options($file, $new_path, $options);
        if (is_wp_error($conversion_result)) {
            $this->logger->error("Conversion failed for #{$att_id}: " . $conversion_result->get_error_message());
            update_post_meta($att_id, '_okvir_image_migrator_status', 'convert_failed');
            return $conversion_result;
        }
        
        // Generate new metadata with all sizes
        $new_meta = wp_generate_attachment_metadata($att_id, $new_path);
        if (!$new_meta || empty($new_meta['file'])) {
            $this->logger->error("Metadata generation failed for #{$att_id}");
            update_post_meta($att_id, '_okvir_image_migrator_status', 'metadata_failed');
            return new WP_Error('metadata_failed', 'Failed to generate attachment metadata');
        }
        
        $this->logger->info("Successfully converted attachment #{$att_id} to {$target_format}");
        
        return [
            'old_meta' => $old_meta,
            'new_meta' => $new_meta,
            'new_path' => $new_path,
            'target_format' => $target_format
        ];
    }
    
    /**
     * Get target MIME type for format
     */
    private function get_target_mime($format) {
        $mimes = [
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'jxl' => 'image/jxl'
        ];
        return $mimes[$format] ?? 'image/webp';
    }
    
    /**
     * Get target file extension for format
     */
    private function get_target_extension($format) {
        $extensions = [
            'webp' => 'webp',
            'avif' => 'avif',
            'jxl' => 'jxl'
        ];
        return $extensions[$format] ?? 'webp';
    }
    
    /**
     * Convert image with advanced options
     */
    private function convert_image_with_options($source_path, $dest_path, $options) {
        try {
            $target_format = $options['target_format'] ?? 'webp';
            $quality = (int)$options['quality'];
            
            // Load source image
            $image_info = getimagesize($source_path);
            if (!$image_info) {
                return new WP_Error('invalid_image', 'Invalid image file');
            }
            
            $source_image = $this->create_image_resource($source_path, $image_info[2]);
            if (!$source_image) {
                return new WP_Error('create_failed', 'Failed to create image resource');
            }
            
            $width = imagesx($source_image);
            $height = imagesy($source_image);
            
            // Apply resizing if needed
            if ($options['conversion_mode'] === 'resize_only' || $options['conversion_mode'] === 'both') {
                $new_dimensions = $this->calculate_new_dimensions($width, $height, $options);
                if ($new_dimensions['width'] !== $width || $new_dimensions['height'] !== $height) {
                    $resized_image = imagecreatetruecolor($new_dimensions['width'], $new_dimensions['height']);
                    
                    // Preserve transparency
                    imagealphablending($resized_image, false);
                    imagesavealpha($resized_image, true);
                    
                    if (!imagecopyresampled($resized_image, $source_image, 0, 0, 0, 0, 
                                          $new_dimensions['width'], $new_dimensions['height'], $width, $height)) {
                        imagedestroy($source_image);
                        return new WP_Error('resize_failed', 'Failed to resize image');
                    }
                    
                    imagedestroy($source_image);
                    $source_image = $resized_image;
                }
            }
            
            // Convert to target format
            $success = $this->save_image_as_format($source_image, $dest_path, $target_format, $quality);
            imagedestroy($source_image);
            
            if (!$success) {
                return new WP_Error('conversion_failed', "Failed to convert to {$target_format}");
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->logger->error("Conversion exception: " . $e->getMessage());
            return new WP_Error('conversion_exception', $e->getMessage());
        }
    }
    
    /**
     * Save image in target format
     */
    private function save_image_as_format($image, $path, $format, $quality) {
        switch ($format) {
            case 'webp':
                return imagewebp($image, $path, $quality);
                
            case 'avif':
                if (function_exists('imageavif')) {
                    return imageavif($image, $path, $quality);
                }
                break;
                
            case 'jxl':
                // JPEG XL would require Imagick or other library
                if (class_exists('Imagick')) {
                    try {
                        $imagick = new Imagick();
                        $imagick->readImageBlob(ob_get_contents());
                        ob_start();
                        imagepng($image);
                        $imagick->readImageBlob(ob_get_contents());
                        ob_end_clean();
                        $imagick->setImageFormat('JXL');
                        $imagick->setImageCompressionQuality($quality);
                        return $imagick->writeImage($path);
                    } catch (Exception $e) {
                        $this->logger->error("Imagick JXL conversion failed: " . $e->getMessage());
                        return false;
                    }
                }
                break;
        }
        
        return false;
    }
    
    /**
     * Create image resource from file
     */
    private function create_image_resource($path, $type) {
        switch ($type) {
            case IMAGETYPE_JPEG:
                return imagecreatefromjpeg($path);
            case IMAGETYPE_PNG:
                return imagecreatefrompng($path);
            case IMAGETYPE_GIF:
                return imagecreatefromgif($path);
            case IMAGETYPE_WEBP:
                if (function_exists('imagecreatefromwebp')) {
                    return imagecreatefromwebp($path);
                }
                break;
        }
        return false;
    }
    
    /**
     * Calculate new dimensions based on options
     */
    private function calculate_new_dimensions($width, $height, $options) {
        $max_width = (int)($options['max_width'] ?? 0);
        $max_height = (int)($options['max_height'] ?? 0);
        
        if ($max_width <= 0 && $max_height <= 0) {
            return ['width' => $width, 'height' => $height];
        }
        
        $ratio = $width / $height;
        
        if ($max_width > 0 && ($max_height <= 0 || $width / $max_width > $height / $max_height)) {
            return [
                'width' => $max_width,
                'height' => (int)($max_width / $ratio)
            ];
        } elseif ($max_height > 0) {
            return [
                'width' => (int)($max_height * $ratio),
                'height' => $max_height
            ];
        }
        
        return ['width' => $width, 'height' => $height];
    }
    
    /**
     * Check if GIF is animated
     */
    private function is_animated_gif($path) {
        if (!is_file($path)) {
            return false;
        }
        
        $raw = file_get_contents($path);
        return preg_match('#\x00\x21\xF9\x04.{4}\x00(\x2C|\x21)#s', $raw) ? true : false;
    }
    
    /**
     * Build metadata fallback for files without proper metadata
     */
    private function build_metadata_fallback($file, $att_id) {
        $info = getimagesize($file);
        if (!$info) {
            return false;
        }
        
        $uploads = wp_get_upload_dir();
        $relative_path = str_replace(trailingslashit($uploads['basedir']), '', $file);
        
        return [
            'width' => $info[0],
            'height' => $info[1],
            'file' => $relative_path,
            'filesize' => filesize($file),
            'sizes' => []
        ];
    }
    
    /**
     * Get conversion statistics
     */
    public function get_conversion_stats() {
        global $wpdb;
        
        $total_attachments = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->posts} p 
            WHERE p.post_type = 'attachment' 
            AND p.post_mime_type LIKE 'image/%'
        ");
        
        $converted = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = %s 
            AND meta_value IN ('converted', 'relinked', 'committed')
        ", '_okvir_image_migrator_status'));
        
        $failed = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = %s 
            AND meta_value LIKE '%_failed'
        ", '_okvir_image_migrator_status'));
        
        return [
            'total' => (int)$total_attachments,
            'converted' => (int)$converted,
            'failed' => (int)$failed,
            'pending' => (int)$total_attachments - (int)$converted - (int)$failed
        ];
    }
}
