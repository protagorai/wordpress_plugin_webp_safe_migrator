<?php
/**
 * WebP Migrator Converter Class
 * 
 * Handles all image conversion operations with advanced options
 */

if (!defined('ABSPATH')) exit;

class WebP_Migrator_Converter {
    
    /** @var array Conversion settings */
    private $settings;
    
    /** @var WebP_Migrator_Logger */
    private $logger;
    
    public function __construct($settings = [], $logger = null) {
        $this->settings = wp_parse_args($settings, [
            'quality' => 59,
            'max_width' => 0,           // 0 = no limit
            'max_height' => 0,          // 0 = no limit
            'preserve_dimensions' => true,
            'conversion_mode' => 'quality_only', // quality_only, resize_only, both
            'skip_animated_gif' => true,
            'backup_originals' => true,
        ]);
        
        $this->logger = $logger ?: new WebP_Migrator_Logger();
    }
    
    /**
     * Convert attachment to WebP with advanced options
     */
    public function convert_attachment($att_id, $options = []) {
        $options = wp_parse_args($options, $this->settings);
        
        $this->logger->info("Starting conversion for attachment #{$att_id}");
        
        // Validate attachment
        $file = get_attached_file($att_id);
        if (!$file || !file_exists($file)) {
            $this->logger->error("File not found for attachment #{$att_id}");
            return new WP_Error('file_not_found', 'Original file not found');
        }
        
        $mime = get_post_mime_type($att_id);
        if ($mime === 'image/webp') {
            $this->logger->info("Attachment #{$att_id} already WebP, skipping");
            return true; // Already WebP
        }
        
        // Skip animated GIFs if configured
        if ($options['skip_animated_gif'] && $mime === 'image/gif' && $this->is_animated_gif($file)) {
            $this->logger->info("Skipping animated GIF #{$att_id}");
            update_post_meta($att_id, '_webp_migrator_status', 'skipped_animated_gif');
            return new WP_Error('animated_gif_skipped', 'Animated GIF skipped');
        }
        
        // Get current metadata
        $old_meta = wp_get_attachment_metadata($att_id);
        if (!$old_meta || empty($old_meta['file'])) {
            $old_meta = $this->build_metadata_fallback($file, $att_id);
        }
        
        // Prepare new WebP paths
        $uploads = wp_get_upload_dir();
        $old_rel = $old_meta['file'];
        $old_dir_rel = trailingslashit(dirname($old_rel));
        $old_basename = wp_basename($old_rel);
        $new_rel = $old_dir_rel . preg_replace('/\.\w+$/', '.webp', $old_basename);
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
            update_post_meta($att_id, '_webp_migrator_status', 'convert_failed');
            return $conversion_result;
        }
        
        // Generate new metadata with all sizes
        $new_meta = wp_generate_attachment_metadata($att_id, $new_path);
        if (!$new_meta || empty($new_meta['file'])) {
            $this->logger->error("Metadata generation failed for #{$att_id}");
            update_post_meta($att_id, '_webp_migrator_status', 'metadata_failed');
            return new WP_Error('metadata_failed', 'Failed to generate attachment metadata');
        }
        
        $this->logger->info("Successfully converted attachment #{$att_id} to WebP");
        
        return [
            'old_meta' => $old_meta,
            'new_meta' => $new_meta,
            'conversion_stats' => $conversion_result
        ];
    }
    
    /**
     * Convert single image with advanced options
     */
    private function convert_image_with_options($src_path, $dest_path, $options) {
        $editor = wp_get_image_editor($src_path);
        if (is_wp_error($editor)) {
            return $editor;
        }
        
        $original_size = $editor->get_size();
        $stats = [
            'original_size' => filesize($src_path),
            'original_dimensions' => $original_size,
            'quality_applied' => $options['quality']
        ];
        
        // Apply quality setting
        if (method_exists($editor, 'set_quality')) {
            $editor->set_quality((int)$options['quality']);
        }
        
        // Apply resizing if needed
        if (!$options['preserve_dimensions'] && 
            ($options['max_width'] > 0 || $options['max_height'] > 0)) {
            
            $new_width = $options['max_width'] ?: $original_size['width'];
            $new_height = $options['max_height'] ?: $original_size['height'];
            
            if ($original_size['width'] > $new_width || $original_size['height'] > $new_height) {
                $resize_result = $editor->resize($new_width, $new_height, false);
                if (is_wp_error($resize_result)) {
                    return $resize_result;
                }
                $stats['resized'] = true;
                $stats['new_dimensions'] = $editor->get_size();
            }
        }
        
        // Save as WebP
        $saved = $editor->save($dest_path, 'image/webp');
        if (is_wp_error($saved)) {
            return $saved;
        }
        
        $stats['new_size'] = filesize($dest_path);
        $stats['compression_ratio'] = round((1 - $stats['new_size'] / $stats['original_size']) * 100, 2);
        
        return $stats;
    }
    
    /**
     * Validate that all required WebP variants exist
     */
    public function validate_conversion($att_id, $new_meta) {
        $uploads = wp_get_upload_dir();
        $missing_files = [];
        
        // Check main file
        $main_file = trailingslashit($uploads['basedir']) . $new_meta['file'];
        if (!file_exists($main_file)) {
            $missing_files[] = $main_file;
        }
        
        // Check all size variants
        if (!empty($new_meta['sizes']) && is_array($new_meta['sizes'])) {
            $dir_rel = trailingslashit(dirname($new_meta['file']));
            foreach ($new_meta['sizes'] as $size => $data) {
                if (!empty($data['file'])) {
                    $size_file = trailingslashit($uploads['basedir']) . $dir_rel . $data['file'];
                    if (!file_exists($size_file)) {
                        $missing_files[] = $size_file;
                    }
                }
            }
        }
        
        if (!empty($missing_files)) {
            $this->logger->error("Missing WebP files for attachment #{$att_id}: " . implode(', ', $missing_files));
            return new WP_Error('validation_failed', 'Missing WebP files', $missing_files);
        }
        
        return true;
    }
    
    /**
     * Check if GIF is animated
     */
    private function is_animated_gif($path) {
        if (!function_exists('imagecreatefromgif')) {
            return false;
        }
        
        $contents = @file_get_contents($path, false, null, 0, 1024 * 128);
        return $contents && strpos($contents, 'NETSCAPE2.0') !== false;
    }
    
    /**
     * Build fallback metadata for files without proper metadata
     */
    private function build_metadata_fallback($path, $att_id) {
        $uploads = wp_get_upload_dir();
        $rel = ltrim(str_replace(trailingslashit($uploads['basedir']), '', $path), '/');
        
        // Get basic image info
        $image_info = @getimagesize($path);
        $meta = [
            'file' => $rel,
            'sizes' => []
        ];
        
        if ($image_info) {
            $meta['width'] = $image_info[0];
            $meta['height'] = $image_info[1];
        }
        
        return $meta;
    }
    
    /**
     * Get conversion statistics for an attachment
     */
    public function get_conversion_stats($att_id) {
        return get_post_meta($att_id, '_webp_migrator_stats', true);
    }
    
    /**
     * Save conversion statistics
     */
    public function save_conversion_stats($att_id, $stats) {
        return update_post_meta($att_id, '_webp_migrator_stats', $stats);
    }
}
