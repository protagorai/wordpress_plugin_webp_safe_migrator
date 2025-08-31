<?php
/**
 * Plugin Name: Okvir Image Safe Migrator
 * Description: Convert non-WebP media to WebP at a fixed quality, update all usages & metadata safely, then (optionally) remove originals after validation. Includes WP-CLI, skip rules, and change reports.
 * Version:     1.0.0
 * Author:      Okvir Platforma
 * Author URI:  mailto:okvir.platforma@gmail.com
 * License:     GPLv2 or later
 * Text Domain: okvir-image-safe-migrator
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

class Okvir_Image_Safe_Migrator {
    const OPTION = 'okvir_image_safe_migrator_settings';
    const NONCE  = 'okvir_image_safe_migrator_nonce';
    const STATUS_META = '_okvir_image_migrator_status';         // converted|relinked|committed|skipped_animated_gif|convert_failed|metadata_failed
    const BACKUP_META = '_okvir_image_migrator_backup_dir';
    const REPORT_META = '_okvir_image_migrator_report';         // JSON-encoded per-attachment report
    const ERROR_META = '_okvir_image_migrator_error';           // JSON-encoded error information
    const DEFAULT_BASE_MIMES = ['image/jpeg','image/jpg','image/png','image/gif','image/webp','image/avif'];
    const SUPPORTED_TARGET_FORMATS = [
        'webp' => ['mime' => 'image/webp', 'ext' => 'webp', 'quality_range' => [1, 100], 'default_quality' => 75],
        'avif' => ['mime' => 'image/avif', 'ext' => 'avif', 'quality_range' => [1, 100], 'default_quality' => 60],
        'jxl'  => ['mime' => 'image/jxl',  'ext' => 'jxl',  'quality_range' => [1, 100], 'default_quality' => 80],
    ];

    /** @var array */
    private $settings;

    /** Allow CLI to tweak validation at runtime */
    private $runtime_validation_override = null;

    public function __construct() {
        try {
            // Initialize settings with error handling
            $default_settings = [
            'target_format'     => 'webp',      // webp, avif, jxl
            'quality'           => 75,          // General quality setting
            'webp_quality'      => 75,          // WebP-specific quality
            'avif_quality'      => 60,          // AVIF-specific quality  
            'avif_speed'        => 6,           // AVIF compression speed (0-10)
            'jxl_quality'       => 80,          // JPEG XL quality
            'jxl_effort'        => 7,           // JPEG XL compression effort (1-9)
            'batch_size'        => 10,
            'validation'        => 1,           // 1 = validate (keep originals), 0 = delete originals immediately
            'skip_folders'      => "",          // textarea, one per line (relative to uploads), substring match
            'skip_mimes'        => "",          // comma/space separated MIME types to skip (e.g. "image/gif")
                'enable_bounding_box' => 0,        // Enable bounding box resizing
                'bounding_box_mode' => 'max',      // 'max' = maximum bounding box, 'min' = minimum bounding box
                'bounding_box_width' => 1920,     // Bounding box width
                'bounding_box_height' => 1080,    // Bounding box height
                'check_filename_dimensions' => 0, // Check filename dimensions against actual dimensions
            ];
            
            $this->settings = wp_parse_args(get_option(self::OPTION, []), $default_settings);

            // Register hooks with error handling
            add_action('admin_menu', [$this, 'menu']);
            add_action('admin_init', [$this, 'handle_actions']);
            add_action('wp_ajax_okvir_image_migrator_process_batch', [$this, 'ajax_process_batch']);
            add_action('wp_ajax_okvir_image_migrator_get_queue_count', [$this, 'ajax_get_queue_count']);
            add_action('wp_ajax_okvir_image_migrator_reprocess_single', [$this, 'ajax_reprocess_single']);
            register_activation_hook(__FILE__, [$this, 'on_activate']);

            // WP-CLI registration moved to after class definition
        } catch (Throwable $e) {
            // Log the error but don't crash WordPress
            error_log('Okvir Image Safe Migrator initialization error: ' . $e->getMessage());
            
            // Set minimal safe settings
            $this->settings = [
                'target_format' => 'webp',
                'quality' => 75,
                'validation' => 1,
                'batch_size' => 10
            ];
        }
    }

    /** Singleton-ish accessor for CLI */
    public static function instance() {
        return $GLOBALS['okvir_image_safe_migrator'] ?? null;
    }

    public function set_runtime_validation($validate_mode_bool) {
        $this->runtime_validation_override = (bool)$validate_mode_bool;
    }

    public function on_activate() {
        // Simplified activation check - avoid complex format detection during activation
        if (!function_exists('imagewebp') && !class_exists('Imagick')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die('Okvir Image Safe Migrator requires GD or Imagick support for image processing.');
        }
        
        // Create plugin options if they don't exist
        if (!get_option(self::OPTION)) {
            add_option(self::OPTION, [
                'target_format' => 'webp',
                'quality' => 75,
                'validation' => 1
            ]);
        }
    }

    /**
     * Get list of supported target formats based on available PHP extensions
     */
    private function get_supported_formats(): array {
        $supported = [];
        
        // Check WebP support
        if (function_exists('imagewebp')) {
            $supported['webp'] = self::SUPPORTED_TARGET_FORMATS['webp'];
        } elseif (class_exists('Imagick')) {
            try {
                $i = new Imagick();
                if (in_array('WEBP', $i->queryFormats('WEBP'), true)) {
                    $supported['webp'] = self::SUPPORTED_TARGET_FORMATS['webp'];
                }
            } catch (Throwable $e) {}
        }
        
        // Check AVIF support
        if (function_exists('imageavif')) {
            $supported['avif'] = self::SUPPORTED_TARGET_FORMATS['avif'];
        } elseif (class_exists('Imagick')) {
            try {
                $i = new Imagick();
                if (in_array('AVIF', $i->queryFormats('AVIF'), true)) {
                    $supported['avif'] = self::SUPPORTED_TARGET_FORMATS['avif'];
                }
            } catch (Throwable $e) {}
        }
        
        // Check JPEG XL support (primarily Imagick)
        if (class_exists('Imagick')) {
            try {
                $i = new Imagick();
                if (in_array('JXL', $i->queryFormats('JXL'), true)) {
                    $supported['jxl'] = self::SUPPORTED_TARGET_FORMATS['jxl'];
                }
            } catch (Throwable $e) {}
        }
        
        return $supported;
    }

    /**
     * Get format-specific options for conversion
     */
    private function get_format_options($target_format, $override_quality = null): array {
        $options = [];
        $format_key = $target_format . '_quality';
        
        // Use override quality if provided, otherwise use format-specific setting
        $options['quality'] = $override_quality ?? ($this->settings[$format_key] ?? 
                             self::SUPPORTED_TARGET_FORMATS[$target_format]['default_quality']);
        
        // Add format-specific options
        switch ($target_format) {
            case 'avif':
                $options['speed'] = $this->settings['avif_speed'] ?? 6;
                break;
            case 'jxl':
                $options['effort'] = $this->settings['jxl_effort'] ?? 7;
                break;
        }
        
        return $options;
    }

    public function menu() {
        add_media_page(
            'Okvir Image Safe Migrator', 
            'Image Migrator', 
            'manage_options',
            'okvir-image-safe-migrator', 
            [$this, 'render_tabbed_interface']
        );
    }

    private function update_settings_from_request() {
        if (!current_user_can('manage_options')) return;
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce($_POST[self::NONCE], 'save_settings')) return;

        $target_format  = isset($_POST['target_format']) && array_key_exists($_POST['target_format'], self::SUPPORTED_TARGET_FORMATS) 
                          ? (string)$_POST['target_format'] : 'webp';
        $quality        = isset($_POST['quality']) ? max(1, min(100, (int)$_POST['quality'])) : 75;
        $webp_quality   = isset($_POST['webp_quality']) ? max(1, min(100, (int)$_POST['webp_quality'])) : 75;
        $avif_quality   = isset($_POST['avif_quality']) ? max(1, min(100, (int)$_POST['avif_quality'])) : 60;
        $avif_speed     = isset($_POST['avif_speed']) ? max(0, min(10, (int)$_POST['avif_speed'])) : 6;
        $jxl_quality    = isset($_POST['jxl_quality']) ? max(1, min(100, (int)$_POST['jxl_quality'])) : 80;
        $jxl_effort     = isset($_POST['jxl_effort']) ? max(1, min(9, (int)$_POST['jxl_effort'])) : 7;
        $batch_size     = isset($_POST['batch_size']) ? max(1, min(1000, (int)$_POST['batch_size'])) : 10;
        $validation     = isset($_POST['validation']) ? 1 : 0;

        $skip_folders_raw = isset($_POST['skip_folders']) ? (string)$_POST['skip_folders'] : '';
        $skip_mimes_raw   = isset($_POST['skip_mimes']) ? (string)$_POST['skip_mimes'] : '';
        
        $enable_bounding_box = isset($_POST['enable_bounding_box']) ? 1 : 0;
        $bounding_box_mode = isset($_POST['bounding_box_mode']) && in_array($_POST['bounding_box_mode'], ['max', 'min']) 
                           ? (string)$_POST['bounding_box_mode'] : 'max';
        $bounding_box_width = isset($_POST['bounding_box_width']) ? max(50, min(10000, (int)$_POST['bounding_box_width'])) : 1920;
        $bounding_box_height = isset($_POST['bounding_box_height']) ? max(50, min(10000, (int)$_POST['bounding_box_height'])) : 1080;
        $check_filename_dimensions = isset($_POST['check_filename_dimensions']) ? 1 : 0;

        $this->settings = [
            'target_format'     => $target_format,
            'quality'           => $quality,
            'webp_quality'      => $webp_quality,
            'avif_quality'      => $avif_quality,
            'avif_speed'        => $avif_speed,
            'jxl_quality'       => $jxl_quality,
            'jxl_effort'        => $jxl_effort,
            'batch_size'        => $batch_size,
            'validation'        => $validation,
            'skip_folders'      => $skip_folders_raw,
            'skip_mimes'        => $skip_mimes_raw,
            'enable_bounding_box' => $enable_bounding_box,
            'bounding_box_mode' => $bounding_box_mode,
            'bounding_box_width' => $bounding_box_width,
            'bounding_box_height' => $bounding_box_height,
            'check_filename_dimensions' => $check_filename_dimensions,
        ];
        update_option(self::OPTION, $this->settings);
        add_settings_error('okvir_image_safe_migrator', 'saved', 'Settings saved.', 'updated');
    }

    public function handle_actions() {
        if (!current_user_can('manage_options')) return;

        // Save settings
        if (isset($_POST['okvir_migrator_save_settings'])) {
            $this->update_settings_from_request();
        }

        // Run batch conversion
        if (isset($_POST['okvir_migrator_run']) && wp_verify_nonce($_POST[self::NONCE] ?? '', 'run_batch')) {
            $batch = $this->get_non_target_format_attachments($this->settings['batch_size']);
            $processed = 0;
            foreach ($batch as $att_id) {
                if ($this->process_attachment((int)$att_id, $this->settings['quality'], $this->current_validation_mode())) {
                    $processed++;
                }
            }
            add_settings_error('okvir_image_safe_migrator', 'batch', "Batch processed ({$processed}/".count($batch).").", 'updated');
        }

        // Commit one
        if (isset($_POST['okvir_migrator_commit_one']) && wp_verify_nonce($_POST[self::NONCE] ?? '', 'commit_one')) {
            $att_id = (int)($_POST['attachment_id'] ?? 0);
            $ok = $this->commit_deletions($att_id);
            if ($ok) {
                add_settings_error('okvir_image_safe_migrator', 'commit', "Committed deletions for attachment #{$att_id}.", 'updated');
            } else {
                add_settings_error('okvir_image_safe_migrator', 'commit_err', "Nothing to delete or commit failed for #{$att_id}.", 'error');
            }
        }

        // Commit all
        if (isset($_POST['okvir_migrator_commit_all']) && wp_verify_nonce($_POST[self::NONCE] ?? '', 'commit_all')) {
            global $wpdb;
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id
                 WHERE p.post_type='attachment' AND pm.meta_key=%s AND pm.meta_value='relinked'",
                 self::STATUS_META
            ));
            $count = 0;
            foreach ($ids as $att_id) {
                if ($this->commit_deletions((int)$att_id)) $count++;
            }
            add_settings_error('okvir_image_safe_migrator', 'commit_all', "Committed deletions for {$count} attachments.", 'updated');
        }

        // Handle dimension inconsistency actions
        $this->handle_dimension_actions();
        
        // Handle conversion error actions
        $this->handle_error_actions();
        
        // Handle maintenance actions
        $this->handle_maintenance_actions();
    }

    private function handle_dimension_actions() {
        if (!current_user_can('manage_options')) return;

        // Clear all dimension inconsistencies
        if (isset($_POST['clear_all_dimensions']) && wp_verify_nonce($_POST[self::NONCE] ?? '', 'clear_dimensions')) {
            $uploads = wp_get_upload_dir();
            $log_file = trailingslashit($uploads['basedir']) . 'okvir-image-migrator-dimension-inconsistencies.json';
            if (file_exists($log_file)) {
                @unlink($log_file);
                add_settings_error('okvir_image_safe_migrator', 'cleared_all', 'All dimension inconsistencies cleared.', 'updated');
            }
        }

        // Remove specific dimension inconsistency
        if (isset($_POST['remove_dimension']) && wp_verify_nonce($_POST[self::NONCE] ?? '', 'remove_dimension')) {
            $att_id = (int)($_POST['attachment_id'] ?? 0);
            if ($att_id > 0) {
                $removed = $this->remove_dimension_inconsistency($att_id);
                if ($removed) {
                    add_settings_error('okvir_image_safe_migrator', 'removed', "Removed dimension inconsistency for attachment #{$att_id}.", 'updated');
                } else {
                    add_settings_error('okvir_image_safe_migrator', 'not_found', "Dimension inconsistency for attachment #{$att_id} not found.", 'error');
                }
            }
        }
    }

    private function handle_error_actions() {
        if (!current_user_can('manage_options')) return;

        // Clear all conversion errors
        if (isset($_POST['clear_all_errors']) && wp_verify_nonce($_POST[self::NONCE] ?? '', 'clear_errors')) {
            $uploads = wp_get_upload_dir();
            $log_file = trailingslashit($uploads['basedir']) . 'okvir-image-migrator-conversion-errors.json';
            if (file_exists($log_file)) {
                @unlink($log_file);
                add_settings_error('okvir_image_safe_migrator', 'cleared_errors', 'All conversion errors cleared.', 'updated');
            }
        }

        // Remove specific conversion error
        if (isset($_POST['remove_error']) && wp_verify_nonce($_POST[self::NONCE] ?? '', 'remove_error')) {
            $att_id = (int)($_POST['attachment_id'] ?? 0);
            if ($att_id > 0) {
                $removed = $this->remove_conversion_error($att_id);
                if ($removed) {
                    delete_post_meta($att_id, self::ERROR_META);
                    add_settings_error('okvir_image_safe_migrator', 'removed_error', "Removed conversion error for attachment #{$att_id}.", 'updated');
                } else {
                    add_settings_error('okvir_image_safe_migrator', 'error_not_found', "Conversion error for attachment #{$att_id} not found.", 'error');
                }
            }
        }

        // Retry failed conversion
        if (isset($_POST['retry_conversion']) && wp_verify_nonce($_POST[self::NONCE] ?? '', 'retry_conversion')) {
            $att_id = (int)($_POST['attachment_id'] ?? 0);
            if ($att_id > 0) {
                $retried = $this->process_attachment($att_id, $this->settings['quality'], $this->current_validation_mode());
                if ($retried) {
                    add_settings_error('okvir_image_safe_migrator', 'retried_ok', "Successfully retried conversion for attachment #{$att_id}.", 'updated');
                } else {
                    add_settings_error('okvir_image_safe_migrator', 'retry_failed', "Retry failed for attachment #{$att_id}.", 'error');
                }
            }
        }

        // Rollback single conversion
        if (isset($_POST['rollback_single']) && wp_verify_nonce($_POST[self::NONCE] ?? '', 'rollback_single')) {
            $att_id = (int)($_POST['attachment_id'] ?? 0);
            if ($att_id > 0) {
                $rolled_back = $this->rollback_conversion($att_id);
                if ($rolled_back) {
                    add_settings_error('okvir_image_safe_migrator', 'rolled_back', "Successfully rolled back conversion for attachment #{$att_id}.", 'updated');
                } else {
                    add_settings_error('okvir_image_safe_migrator', 'rollback_failed', "Rollback failed for attachment #{$att_id} - backup may not exist.", 'error');
                }
            }
        }

        // Rollback all pending conversions
        if (isset($_POST['rollback_all_pending']) && wp_verify_nonce($_POST[self::NONCE] ?? '', 'rollback_all')) {
            global $wpdb;
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id
                 WHERE p.post_type='attachment' AND pm.meta_key=%s AND pm.meta_value='relinked'",
                 self::STATUS_META
            ));
            $count = 0;
            foreach ($ids as $att_id) {
                if ($this->rollback_conversion((int)$att_id)) $count++;
            }
            add_settings_error('okvir_image_safe_migrator', 'rollback_all', "Rolled back conversions for {$count} attachments.", 'updated');
        }
    }
    
    private function handle_maintenance_actions() {
        if (!current_user_can('manage_options')) return;

        // Clean up orphaned metadata
        if (isset($_POST['cleanup_metadata']) && wp_verify_nonce($_POST[self::NONCE] ?? '', 'cleanup_metadata')) {
            $cleaned = $this->cleanup_orphaned_metadata();
            if ($cleaned > 0) {
                add_settings_error('okvir_image_safe_migrator', 'cleanup_done', "Cleaned up {$cleaned} orphaned metadata entries and directories.", 'updated');
            } else {
                add_settings_error('okvir_image_safe_migrator', 'cleanup_nothing', "No orphaned metadata found to clean up.", 'updated');
            }
        }

        // Reset all statistics  
        if (isset($_POST['reset_statistics']) && wp_verify_nonce($_POST[self::NONCE] ?? '', 'reset_statistics')) {
            delete_option('okvir_image_migrator_statistics');
            add_settings_error('okvir_image_safe_migrator', 'stats_reset', "All conversion statistics have been reset.", 'updated');
        }

        // Clear all completed conversion data
        if (isset($_POST['clear_completed_data']) && wp_verify_nonce($_POST[self::NONCE] ?? '', 'clear_completed')) {
            global $wpdb;
            $cleared = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = 'committed'",
                self::STATUS_META
            ));
            if ($cleared > 0) {
                add_settings_error('okvir_image_safe_migrator', 'completed_cleared', "Cleared {$cleared} completed conversion records.", 'updated');
            } else {
                add_settings_error('okvir_image_safe_migrator', 'no_completed', "No completed conversion records found to clear.", 'updated');
            }
        }
    }

    private function current_validation_mode(): bool {
        if ($this->runtime_validation_override !== null) return (bool)$this->runtime_validation_override;
        return (bool)$this->settings['validation'];
    }

    public function render_tabbed_interface() {
        if (!current_user_can('manage_options')) return;
        
        // Determine active tab
        $active_tab = $_GET['tab'] ?? 'settings';
        $valid_tabs = ['settings', 'batch', 'reports', 'errors', 'reprocess', 'dimensions', 'maintenance'];
        if (!in_array($active_tab, $valid_tabs)) {
            $active_tab = 'settings';
        }
        
        ?>
        <div class="wrap">
            <h1>Okvir Image Safe Migrator</h1>
            
            <!-- Tab Navigation -->
            <h2 class="nav-tab-wrapper">
                <a href="?page=okvir-image-safe-migrator&tab=settings" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">Settings & Queue</a>
                <a href="?page=okvir-image-safe-migrator&tab=batch" class="nav-tab <?php echo $active_tab == 'batch' ? 'nav-tab-active' : ''; ?>">Batch Processor</a>
                <a href="?page=okvir-image-safe-migrator&tab=reports" class="nav-tab <?php echo $active_tab == 'reports' ? 'nav-tab-active' : ''; ?>">Reports</a>
                <a href="?page=okvir-image-safe-migrator&tab=errors" class="nav-tab <?php echo $active_tab == 'errors' ? 'nav-tab-active' : ''; ?>">Error Manager</a>
                <a href="?page=okvir-image-safe-migrator&tab=reprocess" class="nav-tab <?php echo $active_tab == 'reprocess' ? 'nav-tab-active' : ''; ?>">Error Reprocessor</a>
                <?php if (!empty($this->settings['check_filename_dimensions'])): ?>
                <a href="?page=okvir-image-safe-migrator&tab=dimensions" class="nav-tab <?php echo $active_tab == 'dimensions' ? 'nav-tab-active' : ''; ?>">Dimension Issues</a>
                <?php endif; ?>
                <a href="?page=okvir-image-safe-migrator&tab=maintenance" class="nav-tab <?php echo $active_tab == 'maintenance' ? 'nav-tab-active' : ''; ?>">Maintenance</a>
            </h2>
            
            <!-- Tab Content -->
            <div class="tab-content">
                <?php
                switch ($active_tab) {
                    case 'settings':
                        $this->render_settings_tab();
                        break;
                    case 'batch':
                        $this->render_batch_tab();
                        break;
                    case 'reports':
                        $this->render_reports_tab();
                        break;
                    case 'errors':
                        $this->render_errors_tab();
                        break;
                    case 'reprocess':
                        $this->render_reprocess_tab();
                        break;
                    case 'dimensions':
                        $this->render_dimensions_tab();
                        break;
                    case 'maintenance':
                        $this->render_maintenance_tab();
                        break;
                    default:
                        $this->render_settings_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    public function render_settings_tab() {
        settings_errors('okvir_image_safe_migrator');
        $target_format = (string)($this->settings['target_format'] ?? 'webp');
        $quality       = (int)$this->settings['quality'];
        $webp_quality  = (int)($this->settings['webp_quality'] ?? 75);
        $avif_quality  = (int)($this->settings['avif_quality'] ?? 60);
        $avif_speed    = (int)($this->settings['avif_speed'] ?? 6);
        $jxl_quality   = (int)($this->settings['jxl_quality'] ?? 80);
        $jxl_effort    = (int)($this->settings['jxl_effort'] ?? 7);
        $batch_size    = (int)$this->settings['batch_size'];
        $validation    = (int)$this->settings['validation'];
        $skip_folders  = (string)$this->settings['skip_folders'];
        $skip_mimes    = (string)$this->settings['skip_mimes'];
        $enable_bounding_box = (int)($this->settings['enable_bounding_box'] ?? 0);
        $bounding_box_mode = (string)($this->settings['bounding_box_mode'] ?? 'max');
        $bounding_box_width = (int)($this->settings['bounding_box_width'] ?? 1920);
        $bounding_box_height = (int)($this->settings['bounding_box_height'] ?? 1080);
        $check_filename_dimensions = (int)($this->settings['check_filename_dimensions'] ?? 0);
        
        $supported_formats = $this->get_supported_formats();
        ?>
        <h2>Settings & Configuration</h2>
        <p>Configure format conversion settings and manage your image processing queue.</p>

            <form method="post">
                <?php wp_nonce_field('save_settings', self::NONCE); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="target_format">Target Format</label></th>
                        <td>
                            <select name="target_format" id="target_format" onchange="toggleFormatOptions(this.value)">
                                <?php foreach ($supported_formats as $format => $info): ?>
                                    <option value="<?php echo esc_attr($format); ?>" <?php selected($target_format, $format); ?>>
                                        <?php echo esc_html(strtoupper($format)); ?> 
                                        (<?php echo esc_html($info['mime']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Modern format to convert images to. Server support detected automatically.</p>
                        </td>
                    </tr>
                    
                    <tr><th scope="row"><label for="quality">Default Quality (1–100)</label></th>
                        <td><input type="number" name="quality" id="quality" min="1" max="100" value="<?php echo esc_attr($quality); ?>">
                        <p class="description">General quality setting used when format-specific quality isn't set.</p></td>
                    </tr>
                    
                    <!-- WebP Settings -->
                    <tr class="format-settings webp-settings" style="<?php echo $target_format !== 'webp' ? 'display:none;' : ''; ?>">
                        <th scope="row"><label for="webp_quality">WebP Quality (1–100)</label></th>
                        <td><input type="number" name="webp_quality" id="webp_quality" min="1" max="100" value="<?php echo esc_attr($webp_quality); ?>">
                        <p class="description">WebP compression quality. Higher = better quality, larger files.</p></td>
                    </tr>
                    
                    <!-- AVIF Settings -->
                    <tr class="format-settings avif-settings" style="<?php echo $target_format !== 'avif' ? 'display:none;' : ''; ?>">
                        <th scope="row"><label for="avif_quality">AVIF Quality (1–100)</label></th>
                        <td><input type="number" name="avif_quality" id="avif_quality" min="1" max="100" value="<?php echo esc_attr($avif_quality); ?>">
                        <p class="description">AVIF compression quality. AVIF typically achieves better quality at lower values than WebP.</p></td>
                    </tr>
                    <tr class="format-settings avif-settings" style="<?php echo $target_format !== 'avif' ? 'display:none;' : ''; ?>">
                        <th scope="row"><label for="avif_speed">AVIF Speed (0–10)</label></th>
                        <td><input type="number" name="avif_speed" id="avif_speed" min="0" max="10" value="<?php echo esc_attr($avif_speed); ?>">
                        <p class="description">Compression speed vs efficiency. 0=slowest/best, 10=fastest/lower quality. 6 is balanced.</p></td>
                    </tr>
                    
                    <!-- JPEG XL Settings -->
                    <tr class="format-settings jxl-settings" style="<?php echo $target_format !== 'jxl' ? 'display:none;' : ''; ?>">
                        <th scope="row"><label for="jxl_quality">JPEG XL Quality (1–100)</label></th>
                        <td><input type="number" name="jxl_quality" id="jxl_quality" min="1" max="100" value="<?php echo esc_attr($jxl_quality); ?>">
                        <p class="description">JPEG XL compression quality. Higher values provide better quality.</p></td>
                    </tr>
                    <tr class="format-settings jxl-settings" style="<?php echo $target_format !== 'jxl' ? 'display:none;' : ''; ?>">
                        <th scope="row"><label for="jxl_effort">JPEG XL Effort (1–9)</label></th>
                        <td><input type="number" name="jxl_effort" id="jxl_effort" min="1" max="9" value="<?php echo esc_attr($jxl_effort); ?>">
                        <p class="description">Compression effort. Higher values take longer but achieve better compression.</p></td>
                    </tr>
                    
                    <tr><th scope="row"><label for="batch_size">Batch size</label></th>
                        <td><input type="number" name="batch_size" id="batch_size" min="1" max="1000" value="<?php echo esc_attr($batch_size); ?>"></td>
                    </tr>
                    <tr><th scope="row"><label for="validation">Validation mode</label></th>
                        <td><label><input type="checkbox" name="validation" <?php checked($validation, 1); ?>> Keep originals until you press "Commit"</label></td>
                    </tr>
                    <tr><th scope="row"><label for="skip_folders">Skip folders</label></th>
                        <td>
                            <textarea name="skip_folders" id="skip_folders" rows="4" cols="50" placeholder="e.g. cache
private-uploads"><?php echo esc_textarea($skip_folders); ?></textarea>
                            <p class="description">One per line, relative to <code>wp-content/uploads</code>. Substring match, case-insensitive.</p>
                        </td>
                    </tr>
                    <tr><th scope="row"><label for="skip_mimes">Skip MIME types</label></th>
                        <td>
                            <input type="text" name="skip_mimes" id="skip_mimes" value="<?php echo esc_attr($skip_mimes); ?>" placeholder="e.g. image/gif">
                            <p class="description">Comma/space separated list (e.g. <code>image/gif, image/png</code>)</p>
                        </td>
                    </tr>
                </table>

                <h3>Image Resizing Options</h3>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="enable_bounding_box">Enable Bounding Box Resizing</label></th>
                        <td>
                            <label><input type="checkbox" name="enable_bounding_box" id="enable_bounding_box" <?php checked($enable_bounding_box, 1); ?> onchange="toggleBoundingBoxOptions(this.checked)"> Enable automatic resizing based on bounding box constraints</label>
                            <p class="description">When enabled, images will be resized according to the bounding box settings below.</p>
                        </td>
                    </tr>
                    
                    <tr class="bounding-box-settings" style="<?php echo !$enable_bounding_box ? 'display:none;' : ''; ?>">
                        <th scope="row"><label for="bounding_box_mode">Bounding Box Mode</label></th>
                        <td>
                            <select name="bounding_box_mode" id="bounding_box_mode">
                                <option value="max" <?php selected($bounding_box_mode, 'max'); ?>>Maximum Bounding Box (scale down if larger)</option>
                                <option value="min" <?php selected($bounding_box_mode, 'min'); ?>>Minimum Bounding Box (scale up if smaller)</option>
                            </select>
                        </td>
                    </tr>
                    
                    <tr class="bounding-box-settings" style="<?php echo !$enable_bounding_box ? 'display:none;' : ''; ?>">
                        <th scope="row"><label for="bounding_box_width">Bounding Box Width</label></th>
                        <td>
                            <input type="number" name="bounding_box_width" id="bounding_box_width" min="50" max="10000" value="<?php echo esc_attr($bounding_box_width); ?>"> pixels
                        </td>
                    </tr>
                    
                    <tr class="bounding-box-settings" style="<?php echo !$enable_bounding_box ? 'display:none;' : ''; ?>">
                        <th scope="row"><label for="bounding_box_height">Bounding Box Height</label></th>
                        <td>
                            <input type="number" name="bounding_box_height" id="bounding_box_height" min="50" max="10000" value="<?php echo esc_attr($bounding_box_height); ?>"> pixels
                        </td>
                    </tr>
                    
                    <tr><th scope="row"><label for="check_filename_dimensions">Check Filename Dimensions</label></th>
                        <td>
                            <label><input type="checkbox" name="check_filename_dimensions" <?php checked($check_filename_dimensions, 1); ?>> Detect and validate dimensions in filenames</label>
                            <p class="description">Parse dimensions from filenames (e.g., "image-1920x1080.jpg") and log inconsistencies with actual image dimensions (±5px tolerance).</p>
                        </td>
                    </tr>
                </table>
                
                <script type="text/javascript">
                function toggleFormatOptions(format) {
                    var allSettings = document.querySelectorAll('.format-settings');
                    allSettings.forEach(function(el) { el.style.display = 'none'; });
                    
                    var formatSettings = document.querySelectorAll('.' + format + '-settings');
                    formatSettings.forEach(function(el) { el.style.display = 'table-row'; });
                }
                
                function toggleBoundingBoxOptions(enabled) {
                    var boundingBoxSettings = document.querySelectorAll('.bounding-box-settings');
                    boundingBoxSettings.forEach(function(el) {
                        el.style.display = enabled ? 'table-row' : 'none';
                    });
                }
                
                document.addEventListener('DOMContentLoaded', function() {
                    var format = document.getElementById('target_format').value;
                    toggleFormatOptions(format);
                    
                    var boundingBoxEnabled = document.getElementById('enable_bounding_box').checked;
                    toggleBoundingBoxOptions(boundingBoxEnabled);
                });
                </script>
                
                <p>
                    <button class="button button-primary" name="okvir_migrator_save_settings" value="1">Save settings</button>
                </p>
            </form>

            <hr/>
            <h2>Run batch</h2>
            <p><strong>Tip:</strong> back up your database and uploads before large migrations.</p>
            <p><strong>Current target:</strong> Converting to <strong><?php echo esc_html(strtoupper($target_format)); ?></strong> format</p>
            <form method="post" style="margin-bottom: 10px;">
                <?php wp_nonce_field('run_batch', self::NONCE); ?>
                <button class="button button-secondary" name="okvir_migrator_run" value="1">Process next batch</button>
                <a class="button button-primary" href="?page=okvir-image-safe-migrator&tab=batch">AJAX Batch Processor</a>
            </form>
            
            <p>
                <a class="button" href="?page=okvir-image-safe-migrator&tab=reports">View Reports</a>
                <a class="button" href="?page=okvir-image-safe-migrator&tab=errors">Manage Errors</a>
                <a class="button" href="?page=okvir-image-safe-migrator&tab=reprocess">Reprocess Errors</a>
            </p>

            <hr/>
            <h2>Pending commits</h2>
            <?php $this->render_pending_commits(); ?>

            <hr/>
            <h2>Images to convert (preview)</h2>
            <p>Showing images that will be converted to <strong><?php echo esc_html(strtoupper($target_format)); ?></strong> format:</p>
            <?php $this->render_queue_preview(); ?>
            
            <?php if (!empty($this->settings['check_filename_dimensions'])): ?>
                <hr/>
                <h2>Dimension Inconsistencies</h2>
                <?php $this->render_dimension_inconsistencies_summary(); ?>
            <?php endif; ?>
            
            <hr/>
            <h2>Conversion Errors</h2>
            <?php $this->render_conversion_errors_summary(); ?>
        <?php
    }
