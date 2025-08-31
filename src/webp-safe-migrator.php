<?php
/**
 * Plugin Name: WebP Safe Migrator
 * Description: Convert non-WebP media to WebP at a fixed quality, update all usages & metadata safely, then (optionally) remove originals after validation. Includes WP-CLI, skip rules, and change reports.
 * Version:     0.2.0
 * Author:      Okvir Platforma
 * Author URI:  mailto:okvir.platforma@gmail.com
 * License:     GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

class WebP_Safe_Migrator {
    const OPTION = 'webp_safe_migrator_settings';
    const NONCE  = 'webp_safe_migrator_nonce';
    const STATUS_META = '_webp_migrator_status';         // converted|relinked|committed|skipped_animated_gif|convert_failed|metadata_failed
    const BACKUP_META = '_webp_migrator_backup_dir';
    const REPORT_META = '_webp_migrator_report';         // JSON-encoded per-attachment report
    const ERROR_META = '_webp_migrator_error';           // JSON-encoded error information
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
        $this->settings = wp_parse_args(get_option(self::OPTION, []), [
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
        ]);

        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'handle_actions']);
        add_action('wp_ajax_webp_migrator_process_batch', [$this, 'ajax_process_batch']);
        add_action('wp_ajax_webp_migrator_get_queue_count', [$this, 'ajax_get_queue_count']);
        add_action('wp_ajax_webp_migrator_reprocess_single', [$this, 'ajax_reprocess_single']);
        register_activation_hook(__FILE__, [$this, 'on_activate']);

        // WP-CLI
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('webp-migrator', ['WebP_Safe_Migrator_CLI', 'dispatch']);
        }
    }

    /** Singleton-ish accessor for CLI */
    public static function instance() {
        return $GLOBALS['webp_safe_migrator'] ?? null;
    }

    public function set_runtime_validation($validate_mode_bool) {
        $this->runtime_validation_override = (bool)$validate_mode_bool;
    }

    public function on_activate() {
        // Check for format support
        $supported_formats = $this->get_supported_formats();
        if (empty($supported_formats)) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die('WebP Safe Migrator requires support for at least one modern image format (WebP, AVIF, or JPEG XL).<br>Available extensions: ' . 
                   (function_exists('imagewebp') ? 'GD-WebP ' : '') .
                   (class_exists('Imagick') ? 'Imagick ' : '') .
                   '<br>Please ensure your server has GD or Imagick with modern format support.');
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
            'WebP Safe Migrator', 
            'WebP Migrator', 
            'manage_options', 
            'webp-safe-migrator', 
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
        add_settings_error('webp_safe_migrator', 'saved', 'Settings saved.', 'updated');
    }

    public function handle_actions() {
        if (!current_user_can('manage_options')) return;

        // Save settings
        if (isset($_POST['webp_migrator_save_settings'])) {
            $this->update_settings_from_request();
        }

        // Run batch conversion
        if (isset($_POST['webp_migrator_run']) && wp_verify_nonce($_POST[self::NONCE] ?? '', 'run_batch')) {
            $batch = $this->get_non_target_format_attachments($this->settings['batch_size']);
            $processed = 0;
            foreach ($batch as $att_id) {
                if ($this->process_attachment((int)$att_id, $this->settings['quality'], $this->current_validation_mode())) {
                    $processed++;
                }
            }
            add_settings_error('webp_safe_migrator', 'batch', "Batch processed ({$processed}/".count($batch).").", 'updated');
        }

        // Commit one
        if (isset($_POST['webp_migrator_commit_one']) && wp_verify_nonce($_POST[self::NONCE] ?? '', 'commit_one')) {
            $att_id = (int)($_POST['attachment_id'] ?? 0);
            $ok = $this->commit_deletions($att_id);
            if ($ok) {
                add_settings_error('webp_safe_migrator', 'commit', "Committed deletions for attachment #{$att_id}.", 'updated');
            } else {
                add_settings_error('webp_safe_migrator', 'commit_err', "Nothing to delete or commit failed for #{$att_id}.", 'error');
            }
        }

        // Commit all
        if (isset($_POST['webp_migrator_commit_all']) && wp_verify_nonce($_POST[self::NONCE] ?? '', 'commit_all')) {
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
            add_settings_error('webp_safe_migrator', 'commit_all', "Committed deletions for {$count} attachments.", 'updated');
        }

        // Handle dimension inconsistency actions
        $this->handle_dimension_actions();
        
        // Handle conversion error actions
        $this->handle_error_actions();
    }

    private function handle_dimension_actions() {
        if (!current_user_can('manage_options')) return;

        // Clear all dimension inconsistencies
        if (isset($_POST['clear_all_dimensions']) && wp_verify_nonce($_POST[self::NONCE] ?? '', 'clear_dimensions')) {
            $uploads = wp_get_upload_dir();
            $log_file = trailingslashit($uploads['basedir']) . 'webp-migrator-dimension-inconsistencies.json';
            if (file_exists($log_file)) {
                @unlink($log_file);
                add_settings_error('webp_safe_migrator', 'cleared_all', 'All dimension inconsistencies cleared.', 'updated');
            }
        }

        // Remove specific dimension inconsistency
        if (isset($_POST['remove_dimension']) && wp_verify_nonce($_POST[self::NONCE] ?? '', 'remove_dimension')) {
            $att_id = (int)($_POST['attachment_id'] ?? 0);
            if ($att_id > 0) {
                $removed = $this->remove_dimension_inconsistency($att_id);
                if ($removed) {
                    add_settings_error('webp_safe_migrator', 'removed', "Removed dimension inconsistency for attachment #{$att_id}.", 'updated');
                } else {
                    add_settings_error('webp_safe_migrator', 'not_found', "Dimension inconsistency for attachment #{$att_id} not found.", 'error');
                }
            }
        }
    }

    private function handle_error_actions() {
        if (!current_user_can('manage_options')) return;

        // Clear all conversion errors
        if (isset($_POST['clear_all_errors']) && wp_verify_nonce($_POST[self::NONCE] ?? '', 'clear_errors')) {
            $uploads = wp_get_upload_dir();
            $log_file = trailingslashit($uploads['basedir']) . 'webp-migrator-conversion-errors.json';
            if (file_exists($log_file)) {
                @unlink($log_file);
                add_settings_error('webp_safe_migrator', 'cleared_errors', 'All conversion errors cleared.', 'updated');
            }
        }

        // Remove specific conversion error
        if (isset($_POST['remove_error']) && wp_verify_nonce($_POST[self::NONCE] ?? '', 'remove_error')) {
            $att_id = (int)($_POST['attachment_id'] ?? 0);
            if ($att_id > 0) {
                $removed = $this->remove_conversion_error($att_id);
                if ($removed) {
                    delete_post_meta($att_id, self::ERROR_META);
                    add_settings_error('webp_safe_migrator', 'removed_error', "Removed conversion error for attachment #{$att_id}.", 'updated');
                } else {
                    add_settings_error('webp_safe_migrator', 'error_not_found', "Conversion error for attachment #{$att_id} not found.", 'error');
                }
            }
        }

        // Retry failed conversion
        if (isset($_POST['retry_conversion']) && wp_verify_nonce($_POST[self::NONCE] ?? '', 'retry_conversion')) {
            $att_id = (int)($_POST['attachment_id'] ?? 0);
            if ($att_id > 0) {
                $retried = $this->process_attachment($att_id, $this->settings['quality'], $this->current_validation_mode());
                if ($retried) {
                    add_settings_error('webp_safe_migrator', 'retried_ok', "Successfully retried conversion for attachment #{$att_id}.", 'updated');
                } else {
                    add_settings_error('webp_safe_migrator', 'retry_failed', "Retry failed for attachment #{$att_id}.", 'error');
                }
            }
        }

        // Rollback single conversion
        if (isset($_POST['rollback_single']) && wp_verify_nonce($_POST[self::NONCE] ?? '', 'rollback_single')) {
            $att_id = (int)($_POST['attachment_id'] ?? 0);
            if ($att_id > 0) {
                $rolled_back = $this->rollback_conversion($att_id);
                if ($rolled_back) {
                    add_settings_error('webp_safe_migrator', 'rolled_back', "Successfully rolled back conversion for attachment #{$att_id}.", 'updated');
                } else {
                    add_settings_error('webp_safe_migrator', 'rollback_failed', "Rollback failed for attachment #{$att_id} - backup may not exist.", 'error');
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
            add_settings_error('webp_safe_migrator', 'rollback_all', "Rolled back conversions for {$count} attachments.", 'updated');
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
        $valid_tabs = ['settings', 'batch', 'reports', 'errors', 'reprocess', 'dimensions'];
        if (!in_array($active_tab, $valid_tabs)) {
            $active_tab = 'settings';
        }
        
        ?>
        <div class="wrap">
            <h1>WebP Safe Migrator</h1>
            
            <!-- Tab Navigation -->
            <h2 class="nav-tab-wrapper">
                <a href="?page=webp-safe-migrator&tab=settings" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">Settings & Queue</a>
                <a href="?page=webp-safe-migrator&tab=batch" class="nav-tab <?php echo $active_tab == 'batch' ? 'nav-tab-active' : ''; ?>">Batch Processor</a>
                <a href="?page=webp-safe-migrator&tab=reports" class="nav-tab <?php echo $active_tab == 'reports' ? 'nav-tab-active' : ''; ?>">Reports</a>
                <a href="?page=webp-safe-migrator&tab=errors" class="nav-tab <?php echo $active_tab == 'errors' ? 'nav-tab-active' : ''; ?>">Error Manager</a>
                <a href="?page=webp-safe-migrator&tab=reprocess" class="nav-tab <?php echo $active_tab == 'reprocess' ? 'nav-tab-active' : ''; ?>">Error Reprocessor</a>
                <?php if (!empty($this->settings['check_filename_dimensions'])): ?>
                <a href="?page=webp-safe-migrator&tab=dimensions" class="nav-tab <?php echo $active_tab == 'dimensions' ? 'nav-tab-active' : ''; ?>">Dimension Issues</a>
                <?php endif; ?>
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
        settings_errors('webp_safe_migrator');
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
        $target_format_info = self::SUPPORTED_TARGET_FORMATS[$target_format] ?? self::SUPPORTED_TARGET_FORMATS['webp'];
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
                            <p class="description">
                                <strong>Maximum:</strong> Images larger than the bounding box will be scaled down to fit.<br>
                                <strong>Minimum:</strong> Images smaller than the bounding box will be scaled up to meet the minimum requirements.
                            </p>
                        </td>
                    </tr>
                    
                    <tr class="bounding-box-settings" style="<?php echo !$enable_bounding_box ? 'display:none;' : ''; ?>">
                        <th scope="row"><label for="bounding_box_width">Bounding Box Width</label></th>
                        <td>
                            <input type="number" name="bounding_box_width" id="bounding_box_width" min="50" max="10000" value="<?php echo esc_attr($bounding_box_width); ?>"> pixels
                            <p class="description">Width constraint for bounding box resizing (50-10000 pixels).</p>
                        </td>
                    </tr>
                    
                    <tr class="bounding-box-settings" style="<?php echo !$enable_bounding_box ? 'display:none;' : ''; ?>">
                        <th scope="row"><label for="bounding_box_height">Bounding Box Height</label></th>
                        <td>
                            <input type="number" name="bounding_box_height" id="bounding_box_height" min="50" max="10000" value="<?php echo esc_attr($bounding_box_height); ?>"> pixels
                            <p class="description">Height constraint for bounding box resizing (50-10000 pixels).</p>
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
                    // Hide all format-specific settings
                    var allSettings = document.querySelectorAll('.format-settings');
                    allSettings.forEach(function(el) { el.style.display = 'none'; });
                    
                    // Show settings for selected format
                    var formatSettings = document.querySelectorAll('.' + format + '-settings');
                    formatSettings.forEach(function(el) { el.style.display = 'table-row'; });
                }
                
                function toggleBoundingBoxOptions(enabled) {
                    var boundingBoxSettings = document.querySelectorAll('.bounding-box-settings');
                    boundingBoxSettings.forEach(function(el) {
                        el.style.display = enabled ? 'table-row' : 'none';
                    });
                }
                
                // Initialize on page load
                document.addEventListener('DOMContentLoaded', function() {
                    var format = document.getElementById('target_format').value;
                    toggleFormatOptions(format);
                    
                    var boundingBoxEnabled = document.getElementById('enable_bounding_box').checked;
                    toggleBoundingBoxOptions(boundingBoxEnabled);
                });
                </script>
                
                <p>
                    <button class="button button-primary" name="webp_migrator_save_settings" value="1">Save settings</button>
                </p>
            </form>

            <hr/>
            <h2>Run batch</h2>
            <p><strong>Tip:</strong> back up your database and uploads before large migrations.</p>
            <p><strong>Current target:</strong> Converting to <strong><?php echo esc_html(strtoupper($target_format)); ?></strong> format</p>
            <form method="post" style="margin-bottom: 10px;">
                <?php wp_nonce_field('run_batch', self::NONCE); ?>
                <button class="button button-secondary" name="webp_migrator_run" value="1">Process next batch</button>
                <a class="button button-primary" href="?page=webp-safe-migrator&tab=batch">AJAX Batch Processor</a>
            </form>
            
            <p>
                <a class="button" href="?page=webp-safe-migrator&tab=reports">View Reports</a>
                <a class="button" href="?page=webp-safe-migrator&tab=errors">Manage Errors</a>
                <a class="button" href="?page=webp-safe-migrator&tab=reprocess">Reprocess Errors</a>
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

    public function render_reports_tab() {
        $att_id = isset($_GET['attachment_id']) ? (int)$_GET['attachment_id'] : 0;

        echo '<h2>Conversion Reports</h2>';
        echo '<p>View detailed reports of converted images and the changes made across your site.</p>';

        if ($att_id) {
            $this->render_single_report($att_id);
            echo '<p><a class="button" href="?page=webp-safe-migrator&tab=reports">&larr; Back to list</a></p>';
            return;
        }

        // List recent reports
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, pm.meta_value AS report
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id
             WHERE p.post_type='attachment' AND pm.meta_key=%s
             ORDER BY p.ID DESC
             LIMIT 200",
            self::REPORT_META
        ), ARRAY_A);

        if (!$rows) {
            echo '<p>No reports yet.</p>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Title</th><th>When</th><th>Posts</th><th>Postmeta</th><th>Options</th><th>Users</th><th>Terms</th><th>Comments</th><th>Custom</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $id = (int)$r['ID'];
            $report = json_decode($r['report'], true) ?: [];
            $when = !empty($report['ts']) ? esc_html($report['ts']) : '—';
            $cntP = isset($report['posts']) ? count($report['posts']) : 0;
            $cntM = isset($report['postmeta']) ? count($report['postmeta']) : 0;
            $cntO = isset($report['options']) ? count($report['options']) : 0;
            $cntU = isset($report['usermeta']) ? count($report['usermeta']) : 0;
            $cntT = isset($report['termmeta']) ? count($report['termmeta']) : 0;
            $cntC = isset($report['comments']) ? count($report['comments']) : 0;
            $cntX = isset($report['custom_tables']) ? count($report['custom_tables']) : 0;
            echo '<tr>';
            echo '<td>'.esc_html($id).'</td>';
            echo '<td>'.esc_html(get_the_title($id)).'</td>';
            echo '<td>'.$when.'</td>';
            echo '<td>'.$cntP.'</td>';
            echo '<td>'.$cntM.'</td>';
            echo '<td>'.$cntO.'</td>';
            echo '<td>'.$cntU.'</td>';
            echo '<td>'.$cntT.'</td>';
            echo '<td>'.$cntC.'</td>';
            echo '<td>'.$cntX.'</td>';
            echo '<td><a class="button" href="?page=webp-safe-migrator&tab=reports&attachment_id='.$id.'">View</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    public function render_dimensions_tab() {
        settings_errors('webp_safe_migrator');
        
        $uploads = wp_get_upload_dir();
        $log_file = trailingslashit($uploads['basedir']) . 'webp-migrator-dimension-inconsistencies.json';
        
        echo '<h2>Dimension Inconsistencies Manager</h2>';
        echo '<p>Manage filename dimension inconsistencies detected during image processing.</p>';
        
        if (!file_exists($log_file)) {
            echo '<div class="notice notice-info"><p><strong>No dimension inconsistencies found.</strong></p>';
            echo '<p>When enabled, the plugin will automatically detect and log cases where filenames contain dimensions (like "image-1920x1080.jpg") that don\'t match the actual image dimensions (±5px tolerance).</p></div>';
            return;
        }
        
        $log_data = json_decode(@file_get_contents($log_file), true);
        if (!$log_data || !is_array($log_data)) {
            echo '<div class="notice notice-error"><p>Could not read dimension inconsistencies log file.</p></div>';
            return;
        }
        
        $count = count($log_data);
        echo '<div class="notice notice-warning">';
        echo '<p><strong>' . $count . '</strong> dimension inconsistenc' . ($count === 1 ? 'y' : 'ies') . ' found.</p>';
        echo '</div>';
        
        // Clear all button
        echo '<div style="margin-bottom: 20px;">';
        echo '<form method="post" style="display: inline-block;" onsubmit="return confirm(\'Are you sure you want to clear ALL dimension inconsistencies? This action cannot be undone.\');">';
        wp_nonce_field('clear_dimensions', self::NONCE);
        echo '<button type="submit" name="clear_all_dimensions" class="button button-secondary" value="1">Clear All Inconsistencies</button>';
        echo '</form>';
        $log_url = $uploads['baseurl'] . '/webp-migrator-dimension-inconsistencies.json';
        echo ' <a href="' . esc_url($log_url) . '" target="_blank" class="button">Download JSON File</a>';
        echo '</div>';
        
        // Table of inconsistencies
        echo '<div class="tablenav top">';
        echo '<div class="alignleft actions">';
        echo '<span class="displaying-num">' . $count . ' item' . ($count === 1 ? '' : 's') . '</span>';
        echo '</div>';
        echo '</div>';
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th scope="col" class="manage-column column-cb check-column"><input type="checkbox" id="cb-select-all-1"></th>';
        echo '<th scope="col" class="manage-column">Attachment</th>';
        echo '<th scope="col" class="manage-column">Filename</th>';
        echo '<th scope="col" class="manage-column">Expected Dimensions</th>';
        echo '<th scope="col" class="manage-column">Actual Dimensions</th>';
        echo '<th scope="col" class="manage-column">Difference</th>';
        echo '<th scope="col" class="manage-column">Date</th>';
        echo '<th scope="col" class="manage-column">Actions</th>';
        echo '</tr></thead>';
        
        echo '<tbody>';
        // Sort by timestamp (newest first)
        uasort($log_data, function($a, $b) {
            return ($b['timestamp_unix'] ?? 0) - ($a['timestamp_unix'] ?? 0);
        });
        
        foreach ($log_data as $att_id => $entry) {
            $attachment = get_post($att_id);
            $attachment_title = $attachment ? $attachment->post_title : "Deleted attachment";
            $attachment_url = $attachment ? get_edit_post_link($att_id) : null;
            
            $expected = $entry['parsed_dimensions']['width'] . ' × ' . $entry['parsed_dimensions']['height'];
            $actual = $entry['actual_dimensions']['width'] . ' × ' . $entry['actual_dimensions']['height'];
            
            $diff_w = $entry['difference']['width_diff'];
            $diff_h = $entry['difference']['height_diff'];
            $diff_display = '';
            if ($diff_w != 0 || $diff_h != 0) {
                $diff_display = ($diff_w >= 0 ? '+' : '') . $diff_w . 'w, ' . ($diff_h >= 0 ? '+' : '') . $diff_h . 'h';
            }
            
            $date = date('Y/m/d g:i a', $entry['timestamp_unix']);
            
            echo '<tr>';
            echo '<th scope="row" class="check-column"><input type="checkbox" name="attachment[]" value="' . esc_attr($att_id) . '"></th>';
            
            // Attachment column
            echo '<td>';
            if ($attachment_url) {
                echo '<strong><a href="' . esc_url($attachment_url) . '">#' . esc_html($att_id) . '</a></strong><br>';
            } else {
                echo '<strong>#' . esc_html($att_id) . '</strong><br>';
            }
            echo '<span style="color: #666;">' . esc_html($attachment_title) . '</span>';
            echo '</td>';
            
            // Filename column
            echo '<td><code>' . esc_html($entry['filename']) . '</code></td>';
            
            // Expected dimensions
            echo '<td><strong>' . esc_html($expected) . '</strong></td>';
            
            // Actual dimensions
            echo '<td><strong>' . esc_html($actual) . '</strong></td>';
            
            // Difference
            echo '<td>';
            if ($diff_display) {
                echo '<code style="color: #d63638;">' . esc_html($diff_display) . '</code>';
            } else {
                echo '—';
            }
            echo '</td>';
            
            // Date
            echo '<td>' . esc_html($date) . '</td>';
            
            // Actions
            echo '<td>';
            echo '<form method="post" style="display: inline-block;" onsubmit="return confirm(\'Remove this dimension inconsistency?\');">';
            wp_nonce_field('remove_dimension', self::NONCE);
            echo '<input type="hidden" name="attachment_id" value="' . esc_attr($att_id) . '">';
            echo '<button type="submit" name="remove_dimension" class="button button-small" value="1">Remove</button>';
            echo '</form>';
            echo '</td>';
            
            echo '</tr>';
        }
        echo '</tbody></table>';
        
        echo '<div class="tablenav bottom">';
        echo '<div class="alignleft actions">';
        echo '<span class="displaying-num">' . $count . ' item' . ($count === 1 ? '' : 's') . '</span>';
        echo '</div>';
        echo '</div>';
        
        echo '<div style="margin-top: 20px; padding: 15px; background: #f9f9f9; border-left: 4px solid #0073aa;">';
        echo '<h3>About Dimension Inconsistencies</h3>';
        echo '<p>These inconsistencies occur when filenames contain dimension information (like "image-1920x1080.jpg") that doesn\'t match the actual image dimensions within a ±5px tolerance.</p>';
        echo '<p><strong>Common causes:</strong></p>';
        echo '<ul>';
        echo '<li>Images were resized after upload without updating the filename</li>';
        echo '<li>Incorrectly named files during bulk operations</li>';
        echo '<li>WordPress automatic resizing that changed dimensions</li>';
        echo '<li>Manual filename changes that don\'t reflect actual content</li>';
        echo '</ul>';
        echo '<p><strong>Log location:</strong> <code>' . esc_html(str_replace(ABSPATH, '', $log_file)) . '</code></p>';
        echo '</div>';
    }

    public function render_errors_tab() {
        settings_errors('webp_safe_migrator');
        
        $uploads = wp_get_upload_dir();
        $log_file = trailingslashit($uploads['basedir']) . 'webp-migrator-conversion-errors.json';
        
        echo '<h2>Conversion Errors Manager</h2>';
        echo '<p>View and manage conversion errors encountered during image processing.</p>';
        
        if (!file_exists($log_file)) {
            echo '<div class="notice notice-success"><p><strong>No conversion errors found.</strong></p>';
            echo '<p>All image conversions have been successful so far. Errors will appear here if any conversions fail in the future.</p></div>';
            return;
        }
        
        $log_data = json_decode(@file_get_contents($log_file), true);
        if (!$log_data || !is_array($log_data)) {
            echo '<div class="notice notice-error"><p>Could not read conversion errors log file.</p></div>';
            return;
        }
        
        $count = count($log_data);
        echo '<div class="notice notice-error">';
        echo '<p><strong>' . $count . '</strong> conversion error' . ($count === 1 ? '' : 's') . ' found.</p>';
        echo '</div>';
        
        // Action buttons
        echo '<div style="margin-bottom: 20px;">';
        echo '<form method="post" style="display: inline-block;" onsubmit="return confirm(\'Are you sure you want to clear ALL conversion errors? This action cannot be undone.\');">';
        wp_nonce_field('clear_errors', self::NONCE);
        echo '<button type="submit" name="clear_all_errors" class="button button-secondary" value="1">Clear All Errors</button>';
        echo '</form>';
        $log_url = $uploads['baseurl'] . '/webp-migrator-conversion-errors.json';
        echo ' <a href="' . esc_url($log_url) . '" target="_blank" class="button">Download JSON File</a>';
        echo '</div>';
        
        // Summary stats
        $steps = [];
        foreach ($log_data as $entry) {
            $step = $entry['step'] ?? 'unknown';
            $steps[$step] = ($steps[$step] ?? 0) + 1;
        }
        
        if (!empty($steps)) {
            echo '<div style="margin-bottom: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd;">';
            echo '<h3>Error Summary by Step</h3>';
            foreach ($steps as $step => $count) {
                echo '<span class="button button-small" style="margin: 2px; cursor: default;">' . esc_html($step ?: 'unknown') . ': ' . $count . '</span>';
            }
            echo '</div>';
        }
        
        // Table of errors
        echo '<div class="tablenav top">';
        echo '<div class="alignleft actions">';
        echo '<span class="displaying-num">' . $count . ' item' . ($count === 1 ? '' : 's') . '</span>';
        echo '</div>';
        echo '</div>';
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th scope="col" class="manage-column column-cb check-column"><input type="checkbox" id="cb-select-all-1"></th>';
        echo '<th scope="col" class="manage-column">Attachment</th>';
        echo '<th scope="col" class="manage-column">Error</th>';
        echo '<th scope="col" class="manage-column">Step</th>';
        echo '<th scope="col" class="manage-column">Count</th>';
        echo '<th scope="col" class="manage-column">First / Last Error</th>';
        echo '<th scope="col" class="manage-column">Actions</th>';
        echo '</tr></thead>';
        
        echo '<tbody>';
        // Sort by timestamp (newest first)
        uasort($log_data, function($a, $b) {
            return ($b['timestamp_unix'] ?? 0) - ($a['timestamp_unix'] ?? 0);
        });
        
        foreach ($log_data as $att_id => $entry) {
            $attachment = get_post($att_id);
            $attachment_title = $attachment ? $attachment->post_title : "Deleted attachment";
            $attachment_url = $attachment ? get_edit_post_link($att_id) : null;
            
            $error_message = $entry['error'] ?? 'Unknown error';
            $step = $entry['step'] ?? 'unknown';
            $target_format = strtoupper($entry['target_format'] ?? 'unknown');
            $mime_type = $entry['mime_type'] ?? '';
            $error_count = $entry['error_count'] ?? 1;
            
            $first_error = isset($entry['first_error_timestamp_unix']) ? 
                date('M j, H:i', $entry['first_error_timestamp_unix']) : 
                (isset($entry['timestamp_unix']) ? date('M j, H:i', $entry['timestamp_unix']) : '—');
            
            $last_error = isset($entry['last_error_timestamp_unix']) ? 
                date('M j, H:i', $entry['last_error_timestamp_unix']) : 
                (isset($entry['timestamp_unix']) ? date('M j, H:i', $entry['timestamp_unix']) : '—');
            
            echo '<tr>';
            echo '<th scope="row" class="check-column"><input type="checkbox" name="attachment[]" value="' . esc_attr($att_id) . '"></th>';
            
            // Attachment column
            echo '<td>';
            if ($attachment_url) {
                echo '<strong><a href="' . esc_url($attachment_url) . '">#' . esc_html($att_id) . '</a></strong><br>';
            } else {
                echo '<strong>#' . esc_html($att_id) . '</strong><br>';
            }
            echo '<span style="color: #666;">' . esc_html($attachment_title) . '</span>';
            if ($mime_type) {
                echo '<br><code style="font-size: 11px; color: #999;">' . esc_html($mime_type) . '</code>';
            }
            echo '</td>';
            
            // Error message column
            echo '<td style="max-width: 300px;">';
            echo '<div style="word-wrap: break-word; max-height: 60px; overflow: auto;">';
            echo '<strong style="color: #d63638;">' . esc_html($error_message) . '</strong>';
            echo '</div>';
            echo '</td>';
            
            // Step column
            echo '<td><code>' . esc_html($step) . '</code></td>';
            
            // Error count column
            echo '<td>';
            if ($error_count > 1) {
                echo '<span style="background: #d63638; color: white; padding: 2px 6px; border-radius: 3px; font-weight: bold;">' . esc_html($error_count) . '</span>';
            } else {
                echo '<span style="background: #ddd; color: #666; padding: 2px 6px; border-radius: 3px;">' . esc_html($error_count) . '</span>';
            }
            echo '</td>';
            
            // First / Last error column
            echo '<td style="font-size: 11px;">';
            echo '<strong>First:</strong> ' . esc_html($first_error) . '<br>';
            if ($first_error !== $last_error) {
                echo '<strong>Last:</strong> ' . esc_html($last_error);
            } else {
                echo '<em>Single occurrence</em>';
            }
            echo '</td>';
            
            // Actions column
            echo '<td>';
            echo '<form method="post" style="display: inline-block; margin-right: 5px;" onsubmit="return confirm(\'Remove this conversion error?\');">';
            wp_nonce_field('remove_error', self::NONCE);
            echo '<input type="hidden" name="attachment_id" value="' . esc_attr($att_id) . '">';
            echo '<button type="submit" name="remove_error" class="button button-small" value="1" title="Remove Error">Remove</button>';
            echo '</form>';
            
            if ($attachment) {
                echo '<form method="post" style="display: inline-block;" onsubmit="return confirm(\'Retry conversion for this attachment?\');">';
                wp_nonce_field('retry_conversion', self::NONCE);
                echo '<input type="hidden" name="attachment_id" value="' . esc_attr($att_id) . '">';
                echo '<button type="submit" name="retry_conversion" class="button button-small button-primary" value="1" title="Retry Conversion">Retry</button>';
                echo '</form>';
            }
            echo '</td>';
            
            echo '</tr>';
            
            // Additional data row (collapsed by default)
            if (!empty($entry['additional_data'])) {
                echo '<tr class="additional-data" style="display: none;">';
                echo '<td></td>';
                echo '<td colspan="6">';
                echo '<div style="background: #f0f0f0; padding: 10px; margin: 5px 0; border-left: 3px solid #ddd;">';
                echo '<strong>Additional Data:</strong><br>';
                echo '<pre style="font-size: 11px; max-height: 200px; overflow: auto;">' . esc_html(wp_json_encode($entry['additional_data'], JSON_PRETTY_PRINT)) . '</pre>';
                echo '</div>';
                echo '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        
        echo '<div class="tablenav bottom">';
        echo '<div class="alignleft actions">';
        echo '<span class="displaying-num">' . $count . ' item' . ($count === 1 ? '' : 's') . '</span>';
        echo '</div>';
        echo '</div>';
        
        echo '<div style="margin-top: 20px; padding: 15px; background: #f9f9f9; border-left: 4px solid #d63638;">';
        echo '<h3>Understanding Conversion Errors</h3>';
        echo '<p>Conversion errors occur when the plugin cannot successfully convert an image from one format to another.</p>';
        echo '<p><strong>Common error steps:</strong></p>';
        echo '<ul>';
        echo '<li><strong>file_validation</strong>: Image file not found or inaccessible</li>';
        echo '<li><strong>format_conversion</strong>: Unable to convert to target format (missing libraries, corrupted image)</li>';
        echo '<li><strong>metadata_generation</strong>: Failed to create WordPress image metadata</li>';
        echo '<li><strong>bounding_box_resize</strong>: Image resizing failed (non-critical)</li>';
        echo '<li><strong>database_update</strong>: Failed to update references in database</li>';
        echo '<li><strong>attachment_update</strong>: Failed to update attachment post metadata</li>';
        echo '</ul>';
        echo '<p><strong>Error handling:</strong> When critical errors occur, original files are preserved automatically. You can retry conversions after fixing the underlying issues.</p>';
        echo '<p><strong>Log location:</strong> <code>' . esc_html(str_replace(ABSPATH, '', $log_file)) . '</code></p>';
        echo '</div>';
    }

    private function render_single_report($att_id) {
        $report = json_decode(get_post_meta($att_id, self::REPORT_META, true) ?: '[]', true);
        echo '<h2>Attachment #'.esc_html($att_id).' — '.esc_html(get_the_title($att_id)).'</h2>';
        $thumb = wp_get_attachment_image($att_id, [120,120], true);
        echo '<p>'.$thumb.'</p>';

        if (!$report) { echo '<p>No report stored.</p>'; return; }

        echo '<p><strong>Migrated:</strong> '.esc_html($report['ts'] ?? '—').'</p>';
        echo '<h3>URL Map Count</h3><p>'.intval($report['map_count'] ?? 0).'</p>';

        echo '<h3>Posts updated</h3>';
        if (!empty($report['posts'])) {
            echo '<ul>';
            foreach ($report['posts'] as $pid) {
                $edit = get_edit_post_link($pid);
                echo '<li>#'.intval($pid).' — '.esc_html(get_the_title($pid)).' '.($edit ? '<a href="'.esc_url($edit).'">Edit</a>' : '').'</li>';
            }
            echo '</ul>';
        } else echo '<p>—</p>';

        echo '<h3>Postmeta updated</h3>';
        if (!empty($report['postmeta'])) {
            echo '<table class="widefat striped"><thead><tr><th>Post ID</th><th>Meta key</th></tr></thead><tbody>';
            foreach ($report['postmeta'] as $row) {
                echo '<tr><td>'.intval($row['post_id']).'</td><td>'.esc_html($row['meta_key']).'</td></tr>';
            }
            echo '</tbody></table>';
        } else echo '<p>—</p>';

        echo '<h3>Options updated</h3>';
        if (!empty($report['options'])) {
            echo '<ul>';
            foreach ($report['options'] as $opt) {
                echo '<li><code>'.esc_html($opt).'</code></li>';
            }
            echo '</ul>';
        } else echo '<p>—</p>';

        echo '<h3>User metadata updated</h3>';
        if (!empty($report['usermeta'])) {
            echo '<table class="widefat striped"><thead><tr><th>User ID</th><th>Meta key</th><th>User</th></tr></thead><tbody>';
            foreach ($report['usermeta'] as $row) {
                $user = get_user_by('id', $row['user_id']);
                $username = $user ? $user->user_login : 'Unknown';
                echo '<tr><td>'.intval($row['user_id']).'</td><td>'.esc_html($row['meta_key']).'</td><td>'.esc_html($username).'</td></tr>';
            }
            echo '</tbody></table>';
        } else echo '<p>—</p>';

        echo '<h3>Term metadata updated</h3>';
        if (!empty($report['termmeta'])) {
            echo '<table class="widefat striped"><thead><tr><th>Term ID</th><th>Meta key</th><th>Term</th></tr></thead><tbody>';
            foreach ($report['termmeta'] as $row) {
                $term = get_term($row['term_id']);
                $term_name = $term && !is_wp_error($term) ? $term->name : 'Unknown';
                echo '<tr><td>'.intval($row['term_id']).'</td><td>'.esc_html($row['meta_key']).'</td><td>'.esc_html($term_name).'</td></tr>';
            }
            echo '</tbody></table>';
        } else echo '<p>—</p>';

        echo '<h3>Comments updated</h3>';
        if (!empty($report['comments'])) {
            echo '<ul>';
            foreach ($report['comments'] as $cid) {
                $comment = get_comment($cid);
                $author = $comment ? $comment->comment_author : 'Unknown';
                $post_title = get_the_title($comment->comment_post_ID ?? 0);
                echo '<li>#'.intval($cid).' — Comment by '.esc_html($author).' on "'.esc_html($post_title).'"</li>';
            }
            echo '</ul>';
        } else echo '<p>—</p>';

        echo '<h3>Custom tables updated</h3>';
        if (!empty($report['custom_tables'])) {
            echo '<table class="widefat striped"><thead><tr><th>Table</th><th>Column</th><th>Row ID</th></tr></thead><tbody>';
            foreach ($report['custom_tables'] as $row) {
                echo '<tr><td>'.esc_html($row['table']).'</td><td>'.esc_html($row['column']).'</td><td>'.esc_html($row['row_id']).'</td></tr>';
            }
            echo '</tbody></table>';
            echo '<p><em>Note: Custom tables updated include e-commerce products, plugin galleries, and other JSON/serialized data.</em></p>';
        } else echo '<p>—</p>';
    }

    private function render_pending_commits() {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id
             WHERE p.post_type='attachment' AND pm.meta_key=%s AND pm.meta_value='relinked'
             ORDER BY p.ID DESC LIMIT 50",
            self::STATUS_META
        ), ARRAY_A);

        if (!$rows) { echo '<p>No items awaiting commit.</p>'; return; }

        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Title</th><th>Preview</th><th>Actions</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $id = (int)$r['ID'];
            $thumb = wp_get_attachment_image($id, [80,80], true);
            echo '<tr>';
            echo '<td>'.esc_html($id).'</td>';
            echo '<td>'.esc_html($r['post_title']).'</td>';
            echo '<td>'.$thumb.'</td>';
            echo '<td>';
            // Commit button
            echo '<form method="post" style="display:inline-block;margin-right:6px;">';
            wp_nonce_field('commit_one', self::NONCE);
            echo '<input type="hidden" name="attachment_id" value="'.esc_attr($id).'">';
            echo '<button class="button button-primary" name="webp_migrator_commit_one" value="1" title="Permanently delete original files">Commit Delete</button>';
            echo '</form>';
            // Rollback button
            echo '<form method="post" style="display:inline-block;" onsubmit="return confirm(\'Rollback conversion? This will restore the original image and undo the conversion.\');">';
            wp_nonce_field('rollback_single', self::NONCE);
            echo '<input type="hidden" name="attachment_id" value="'.esc_attr($id).'">';
            echo '<button class="button button-secondary" name="rollback_single" value="1" title="Restore original image and undo conversion">Rollback</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<div style="margin-top:10px;">';
        echo '<form method="post" style="display:inline-block;margin-right:10px;">';
        wp_nonce_field('commit_all', self::NONCE);
        echo '<button class="button button-primary" name="webp_migrator_commit_all" value="1" title="Permanently delete all original files above">Commit ALL Above</button>';
        echo '</form>';
        echo '<form method="post" style="display:inline-block;" onsubmit="return confirm(\'Rollback ALL conversions? This will restore all original images and undo all conversions above.\');">';
        wp_nonce_field('rollback_all', self::NONCE);
        echo '<button class="button button-secondary" name="rollback_all_pending" value="1" title="Restore all original images and undo all conversions above">Rollback ALL Above</button>';
        echo '</form>';
        echo '</div>';
    }

    private function render_queue_preview() {
        $ids = $this->get_non_target_format_attachments(20);
        if (!$ids) { echo '<p>None found (or all skipped by filters).</p>'; return; }
        echo '<ul>';
        foreach ($ids as $id) {
            $file = get_attached_file($id);
            $type = get_post_mime_type($id);
            echo '<li>#'.esc_html($id).' — '.esc_html(basename($file)).' ('.esc_html($type).')</li>';
        }
        echo '</ul>';
    }

    private function render_dimension_inconsistencies_summary() {
        $uploads = wp_get_upload_dir();
        $log_file = trailingslashit($uploads['basedir']) . 'webp-migrator-dimension-inconsistencies.json';
        
        if (!file_exists($log_file)) {
            echo '<p>No dimension inconsistencies found yet.</p>';
            return;
        }
        
        $log_data = json_decode(@file_get_contents($log_file), true);
        if (!$log_data || !is_array($log_data)) {
            echo '<p>Could not read dimension inconsistencies log.</p>';
            return;
        }
        
        $count = count($log_data);
        echo '<p><strong>' . $count . '</strong> dimension inconsistenc' . ($count === 1 ? 'y' : 'ies') . ' found in filenames. ';
        echo '<a href="?page=webp-safe-migrator&tab=dimensions" class="button button-small">Manage All</a></p>';
        
        if ($count > 0) {
            echo '<div style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;">';
            echo '<table class="widefat striped" style="margin: 0;"><thead><tr><th>Filename</th><th>Expected</th><th>Actual</th><th>Difference</th><th>When</th></tr></thead><tbody>';
            
            // Show most recent entries first
            $recent_entries = array_slice(array_reverse($log_data, true), 0, 20, true);
            
            foreach ($recent_entries as $att_id => $entry) {
                $expected = $entry['parsed_dimensions']['width'] . '×' . $entry['parsed_dimensions']['height'];
                $actual = $entry['actual_dimensions']['width'] . '×' . $entry['actual_dimensions']['height'];
                $diff_w = $entry['difference']['width_diff'];
                $diff_h = $entry['difference']['height_diff'];
                $diff = ($diff_w >= 0 ? '+' : '') . $diff_w . 'w, ' . ($diff_h >= 0 ? '+' : '') . $diff_h . 'h';
                $when = date('M j, H:i', $entry['timestamp_unix']);
                
                echo '<tr>';
                echo '<td>' . esc_html($entry['filename']) . '</td>';
                echo '<td>' . esc_html($expected) . '</td>';
                echo '<td>' . esc_html($actual) . '</td>';
                echo '<td><code style="font-size: 11px;">' . esc_html($diff) . '</code></td>';
                echo '<td>' . esc_html($when) . '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
            if ($count > 20) {
                echo '<p style="margin: 10px 0 0 0; font-style: italic;">Showing 20 most recent entries of ' . $count . ' total.</p>';
            }
            echo '</div>';
            
            $log_url = $uploads['baseurl'] . '/webp-migrator-dimension-inconsistencies.json';
            echo '<p><a href="' . esc_url($log_url) . '" target="_blank">View full JSON log</a> | ';
            echo '<small>Log file: <code>' . esc_html(str_replace(ABSPATH, '', $log_file)) . '</code></small></p>';
        }
    }

    private function render_conversion_errors_summary() {
        $uploads = wp_get_upload_dir();
        $log_file = trailingslashit($uploads['basedir']) . 'webp-migrator-conversion-errors.json';
        
        if (!file_exists($log_file)) {
            echo '<p><strong>No conversion errors.</strong> All image conversions have been successful. 
            <a href="?page=webp-safe-migrator&tab=errors" class="button button-small">View Error Manager</a></p>';
            return;
        }
        
        $log_data = json_decode(@file_get_contents($log_file), true);
        if (!$log_data || !is_array($log_data)) {
            echo '<p>Could not read conversion errors log.</p>';
            return;
        }
        
        $count = count($log_data);
        echo '<p><strong style="color: #d63638;">' . $count . '</strong> conversion error' . ($count === 1 ? '' : 's') . ' found. ';
        echo '<a href="?page=webp-safe-migrator&tab=errors" class="button button-small">Manage Errors</a></p>';
        
        if ($count > 0) {
            // Group errors by step for summary
            $steps = [];
            foreach ($log_data as $entry) {
                $step = $entry['step'] ?? 'unknown';
                $steps[$step] = ($steps[$step] ?? 0) + 1;
            }
            
            echo '<div style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;">';
            echo '<table class="widefat striped" style="margin: 0;"><thead><tr><th>Attachment</th><th>Error</th><th>Step</th><th>When</th></tr></thead><tbody>';
            
            // Show most recent errors first
            $recent_entries = array_slice(array_reverse($log_data, true), 0, 10, true);
            
            foreach ($recent_entries as $att_id => $entry) {
                $attachment_title = get_the_title($att_id) ?: 'Deleted attachment';
                $error_message = $entry['error'] ?? 'Unknown error';
                $step = $entry['step'] ?? 'unknown';
                $error_count = $entry['error_count'] ?? 1;
                
                // Use last error timestamp if available, otherwise timestamp
                $when_unix = $entry['last_error_timestamp_unix'] ?? $entry['timestamp_unix'] ?? time();
                $when = date('M j, H:i', $when_unix);
                
                // Truncate long error messages
                if (strlen($error_message) > 80) {
                    $error_message = substr($error_message, 0, 80) . '...';
                }
                
                echo '<tr>';
                echo '<td>#' . esc_html($att_id) . ' — ' . esc_html($attachment_title);
                if ($error_count > 1) {
                    echo ' <span style="background: #d63638; color: white; padding: 1px 4px; border-radius: 2px; font-size: 10px; font-weight: bold;">×' . $error_count . '</span>';
                }
                echo '</td>';
                echo '<td><span style="color: #d63638;">' . esc_html($error_message) . '</span></td>';
                echo '<td><code style="font-size: 11px;">' . esc_html($step) . '</code></td>';
                echo '<td>' . esc_html($when) . '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
            if ($count > 10) {
                echo '<p style="margin: 10px 0 0 0; font-style: italic;">Showing 10 most recent errors of ' . $count . ' total.</p>';
            }
            
            // Step summary
            if (!empty($steps)) {
                echo '<div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #ddd;">';
                echo '<strong>Error types:</strong> ';
                foreach ($steps as $step => $step_count) {
                    echo '<span class="button button-small" style="margin: 2px; cursor: default; font-size: 10px;">' . esc_html($step) . ': ' . $step_count . '</span>';
                }
                echo '</div>';
            }
            
            echo '</div>';
            
            $log_url = $uploads['baseurl'] . '/webp-migrator-conversion-errors.json';
            echo '<p><a href="' . esc_url($log_url) . '" target="_blank">View full JSON log</a> | ';
            echo '<small>Log file: <code>' . esc_html(str_replace(ABSPATH, '', $log_file)) . '</code></small></p>';
        }
    }

    /** Parse skip settings */
    private function get_skip_rules(): array {
        $folders = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$this->settings['skip_folders'])));
        $mimes   = preg_split('/[\s,]+/', (string)$this->settings['skip_mimes'], -1, PREG_SPLIT_NO_EMPTY);
        $mimes   = array_map('trim', $mimes);
        return [ $folders, $mimes ];
    }

    /** Return $limit attachment IDs that should be processed, excluding those with logged errors */
    public function get_non_target_format_attachments($limit = 10, $exclude_errors = true): array {
        $target_format = $this->settings['target_format'] ?? 'webp';
        $target_mime = self::SUPPORTED_TARGET_FORMATS[$target_format]['mime'] ?? 'image/webp';
        global $wpdb;
        [$skip_folders, $skip_mimes] = $this->get_skip_rules();

        // Target mimes are default base mimes minus skip_mimes  
        $target_mimes = array_values(array_diff(self::DEFAULT_BASE_MIMES, $skip_mimes));
        if (!$target_mimes) return [];

        // Get IDs with logged errors to exclude them
        $error_ids = [];
        if ($exclude_errors) {
            $error_ids = $this->get_attachment_ids_with_errors();
        }

        // Over-fetch to allow for folder-based skipping and error exclusion
        $fetch = max($limit * 10, 100);
        $in = implode(',', array_fill(0, count($target_mimes), '%s'));
        
        // Don't exclude target format anymore - we may need to reprocess for quality/size
        $sql = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type='attachment'
               AND post_mime_type IN ($in)
             ORDER BY ID ASC
             LIMIT %d",
            ...array_merge($target_mimes, [(int)$fetch])
        );
        $candidates = $wpdb->get_col($sql);

        $result = [];
        $uploads = wp_get_upload_dir();

        foreach ($candidates as $id) {
            $id = (int)$id;
            
            // Skip if this attachment has logged errors
            if ($exclude_errors && in_array($id, $error_ids)) {
                continue;
            }
            
            // CRITICAL: Skip if already processed (check conversion status)
            $current_status = get_post_meta($id, self::STATUS_META, true);
            if (in_array($current_status, ['relinked', 'committed'])) {
                continue; // Already converted - don't reprocess
            }
            
            $mime = get_post_mime_type($id);
            
            // Check if we should process this attachment
            if ($mime === $target_mime && !$this->should_reprocess_same_format($mime, $target_mime)) {
                continue; // Same format and no reprocessing needed
            }

            $file = get_attached_file($id);
            if (!$file) continue;

            // skip folders (substring match against relative path)
            $rel = ltrim(str_replace(trailingslashit($uploads['basedir']), '', $file), '/');
            $skip = false;
            foreach ($skip_folders as $frag) {
                if ($frag !== '' && stripos($rel, $frag) !== false) { $skip = true; break; }
            }
            if ($skip) continue;

            $result[] = $id;
            if (count($result) >= $limit) break;
        }
        return $result;
    }
    
    /**
     * Get attachment IDs that have logged conversion errors
     */
    private function get_attachment_ids_with_errors(): array {
        $uploads = wp_get_upload_dir();
        $log_file = trailingslashit($uploads['basedir']) . 'webp-migrator-conversion-errors.json';
        
        if (!file_exists($log_file)) {
            return [];
        }
        
        $log_data = json_decode(@file_get_contents($log_file), true);
        if (!$log_data || !is_array($log_data)) {
            return [];
        }
        
        return array_keys($log_data);
    }

    private function is_animated_gif($path) {
        if (function_exists('imagecreatefromgif')) {
            $contents = @file_get_contents($path, false, null, 0, 1024 * 128); // first chunk
            return $contents && strpos($contents, 'NETSCAPE2.0') !== false; // simple/fast indicator
        }
        return false;
    }

    public function process_attachment($att_id, $quality, $validation_mode) {
        // Clear any previous error state
        delete_post_meta($att_id, self::ERROR_META);
        
        $file = get_attached_file($att_id);
        if (!$file || !file_exists($file)) {
            $this->log_conversion_error($att_id, 'Attachment file not found or does not exist', 'file_validation', [
                'file_path' => $file ?: 'null'
            ]);
            return false;
        }

        $mime = get_post_mime_type($att_id);
        $target_format = $this->settings['target_format'] ?? 'webp';
        $target_mime = self::SUPPORTED_TARGET_FORMATS[$target_format]['mime'] ?? 'image/webp';
        
        // Check if we should still process same-format images for quality/size changes
        $should_reprocess_same_format = $this->should_reprocess_same_format($mime, $target_mime);
        if ($mime === $target_mime && !$should_reprocess_same_format) {
            return true; // Already in target format and no reprocessing needed
        }

        // Skip animated GIF unless animated WebP is implemented
        if ($mime === 'image/gif' && $this->is_animated_gif($file)) {
            update_post_meta($att_id, self::STATUS_META, 'skipped_animated_gif');
            return false; // This is intentional skipping, not an error
        }

        // Validate filename dimensions against actual dimensions (before processing)
        try {
            $this->validate_filename_dimensions($file, $att_id);
        } catch (Exception $e) {
            $this->log_conversion_error($att_id, 'Filename dimension validation failed: ' . $e->getMessage(), 'dimension_validation');
            // Don't return false here - dimension validation errors shouldn't stop conversion
        }

        $uploads = wp_get_upload_dir();
        $old_meta = wp_get_attachment_metadata($att_id);
        if (!$old_meta || empty($old_meta['file'])) {
            $old_meta = $this->build_metadata_fallback($file, $att_id);
        }
        
        if (!$old_meta || empty($old_meta['file'])) {
            $this->log_conversion_error($att_id, 'Could not generate attachment metadata', 'metadata_preparation', [
                'file_path' => $file,
                'existing_meta' => $old_meta
            ]);
            return false;
        }

        $target_format = $this->settings['target_format'] ?? 'webp';
        $target_ext = self::SUPPORTED_TARGET_FORMATS[$target_format]['ext'] ?? 'webp';
        
        $old_rel      = $old_meta['file'];                                   // '2025/08/image.jpg'
        $old_dir_rel  = trailingslashit(dirname($old_rel));
        $old_basename = wp_basename($old_rel);
        $new_rel      = $old_dir_rel . preg_replace('/\.\w+$/', '.' . $target_ext, $old_basename);
        $new_path     = trailingslashit($uploads['basedir']) . $new_rel;

        if (!wp_mkdir_p(dirname($new_path))) {
            $this->log_conversion_error($att_id, 'Failed to create target directory', 'directory_creation', [
                'target_dir' => dirname($new_path),
                'new_path' => $new_path
            ]);
            return false;
        }

        // Convert original to target format
        $format_options = $this->get_format_options($target_format, $quality);
        try {
            $converted = $this->convert_to_format($file, $new_path, $target_format, $format_options);
            if (!$converted) {
                $this->log_conversion_error($att_id, 'Format conversion failed - unable to convert image to ' . $target_format, 'format_conversion', [
                    'source_format' => $mime,
                    'target_format' => $target_format,
                    'target_path' => $new_path,
                    'format_options' => $format_options
                ]);
                update_post_meta($att_id, self::STATUS_META, 'convert_failed');
                return false;
            }
        } catch (Exception $e) {
            $this->log_conversion_error($att_id, 'Format conversion exception: ' . $e->getMessage(), 'format_conversion', [
                'source_format' => $mime,
                'target_format' => $target_format,
                'target_path' => $new_path,
                'exception_class' => get_class($e)
            ]);
            update_post_meta($att_id, self::STATUS_META, 'convert_failed');
            return false;
        }

        // Apply bounding box resizing if enabled
        if (!empty($this->settings['enable_bounding_box'])) {
            try {
                $current_size = getimagesize($new_path);
                if ($current_size) {
                    $current_width = $current_size[0];
                    $current_height = $current_size[1];
                    $box_width = (int)($this->settings['bounding_box_width'] ?? 1920);
                    $box_height = (int)($this->settings['bounding_box_height'] ?? 1080);
                    $box_mode = $this->settings['bounding_box_mode'] ?? 'max';
                    
                    list($new_width, $new_height) = $this->calculate_bounding_box_dimensions(
                        $current_width, $current_height, $box_width, $box_height, $box_mode
                    );
                    
                    if ($new_width != $current_width || $new_height != $current_height) {
                        $resized = $this->resize_image($new_path, $new_width, $new_height);
                        if (!$resized) {
                            $this->log_conversion_error($att_id, "Failed to resize image to {$new_width}x{$new_height}", 'bounding_box_resize', [
                                'original_dimensions' => [$current_width, $current_height],
                                'target_dimensions' => [$new_width, $new_height],
                                'bounding_box_mode' => $box_mode,
                                'bounding_box_size' => [$box_width, $box_height]
                            ]);
                            // Don't return false here - continue with original size
                        }
                    }
                } else {
                    $this->log_conversion_error($att_id, 'Could not get image dimensions for bounding box resize', 'bounding_box_resize', [
                        'converted_path' => $new_path
                    ]);
                    // Don't return false here - continue without resize
                }
            } catch (Exception $e) {
                $this->log_conversion_error($att_id, 'Bounding box resize exception: ' . $e->getMessage(), 'bounding_box_resize', [
                    'exception_class' => get_class($e)
                ]);
                // Don't return false here - continue without resize
            }
        }

        // Generate fresh metadata/sizes from converted original
        try {
            $new_meta = wp_generate_attachment_metadata($att_id, $new_path);
            if (!$new_meta || empty($new_meta['file'])) {
                $this->log_conversion_error($att_id, 'Failed to generate attachment metadata for converted image', 'metadata_generation', [
                    'converted_path' => $new_path,
                    'generated_meta' => $new_meta
                ]);
                update_post_meta($att_id, self::STATUS_META, 'metadata_failed');
                // Keep the original file and return false - this is a critical failure
                return false;
            }
        } catch (Exception $e) {
            $this->log_conversion_error($att_id, 'Metadata generation exception: ' . $e->getMessage(), 'metadata_generation', [
                'converted_path' => $new_path,
                'exception_class' => get_class($e)
            ]);
            update_post_meta($att_id, self::STATUS_META, 'metadata_failed');
            return false;
        }

        // Build URL mapping (old → new) for original and sizes
        try {
            $map = $this->build_url_map($uploads, $old_meta, $new_meta);
        } catch (Exception $e) {
            $this->log_conversion_error($att_id, 'Failed to build URL mapping: ' . $e->getMessage(), 'url_mapping', [
                'old_meta' => $old_meta,
                'new_meta' => $new_meta
            ]);
            // Clean up converted file and preserve original
            @unlink($new_path);
            return false;
        }

        // Update usages across DB and collect a report of changes
        try {
            $report = $this->replace_everywhere($map);
        } catch (Exception $e) {
            $this->log_conversion_error($att_id, 'Failed to update database references: ' . $e->getMessage(), 'database_update', [
                'map_count' => count($map ?? [])
            ]);
            // Clean up converted file and preserve original
            @unlink($new_path);
            return false;
        }

        // Update attachment post + metas
        try {
            $target_mime = self::SUPPORTED_TARGET_FORMATS[$target_format]['mime'] ?? 'image/webp';
            $post_update_result = wp_update_post([
                'ID'             => $att_id,
                'post_mime_type' => $target_mime,
                'guid'           => $uploads['baseurl'] . '/' . $new_meta['file'],
            ]);
            
            if (is_wp_error($post_update_result) || $post_update_result === 0) {
                throw new Exception('wp_update_post failed: ' . (is_wp_error($post_update_result) ? $post_update_result->get_error_message() : 'Unknown error'));
            }
            
            update_post_meta($att_id, '_wp_attached_file', $new_meta['file']);
            wp_update_attachment_metadata($att_id, $new_meta);
        } catch (Exception $e) {
            $this->log_conversion_error($att_id, 'Failed to update attachment metadata: ' . $e->getMessage(), 'attachment_update', [
                'target_mime' => $target_mime ?? '',
                'new_file' => $new_meta['file'] ?? ''
            ]);
            // Clean up converted file and preserve original
            @unlink($new_path);
            return false;
        }

        // Store report
        try {
            $report_payload = [
                'ts'            => current_time('mysql'),
                'map_count'     => count($map),
                'posts'         => array_values(array_unique($report['posts'] ?? [])),
                'postmeta'      => array_values($report['postmeta'] ?? []),
                'options'       => array_values(array_unique($report['options'] ?? [])),
                'usermeta'      => array_values($report['usermeta'] ?? []),
                'termmeta'      => array_values($report['termmeta'] ?? []),
                'comments'      => array_values(array_unique($report['comments'] ?? [])),
                'custom_tables' => array_values($report['custom_tables'] ?? []),
            ];
            update_post_meta($att_id, self::REPORT_META, wp_json_encode($report_payload));
        } catch (Exception $e) {
            // Report storage failure is not critical - log but continue
            error_log('WebP Migrator: Failed to store conversion report for attachment #' . $att_id . ': ' . $e->getMessage());
        }

        // Backup originals to a safe folder (deleted on commit)
        $backup_dir = trailingslashit($uploads['basedir']) . 'webp-migrator-backup/' . date('Ymd-His') . "/att-{$att_id}/";
        if (!wp_mkdir_p($backup_dir)) {
            $backup_dir = null;
            error_log('WebP Migrator: Failed to create backup directory for attachment #' . $att_id);
        }

        // Move/delete old files (always preserve in validation mode or on any previous error)
        try {
            $this->collect_and_remove_old_files($uploads, $old_meta, $validation_mode, $backup_dir);
        } catch (Exception $e) {
            $this->log_conversion_error($att_id, 'Failed to handle original files: ' . $e->getMessage(), 'file_cleanup', [
                'backup_dir' => $backup_dir,
                'validation_mode' => $validation_mode
            ]);
            // Don't return false here - conversion was successful, just cleanup failed
            error_log('WebP Migrator: File cleanup failed for attachment #' . $att_id . ', but conversion completed successfully');
        }

        // Mark status for UI
        update_post_meta($att_id, self::STATUS_META, $validation_mode ? 'relinked' : 'committed');
        if ($backup_dir) update_post_meta($att_id, self::BACKUP_META, $backup_dir);

        return true;
    }

    private function build_metadata_fallback($path, $att_id) {
        $uploads = wp_get_upload_dir();
        $rel = ltrim(str_replace(trailingslashit($uploads['basedir']), '', $path), '/');
        return ['file' => $rel, 'sizes' => []];
    }

    /**
     * Convert image to specified format with format-specific options
     */
    private function convert_to_format($src, $dest, $target_format, $options = []) {
        if (!array_key_exists($target_format, self::SUPPORTED_TARGET_FORMATS)) {
            return false;
        }
        
        $format_info = self::SUPPORTED_TARGET_FORMATS[$target_format];
        $quality = $options['quality'] ?? $format_info['default_quality'];
        
        // Try WordPress image editor first
        $editor = wp_get_image_editor($src);
        if (!is_wp_error($editor)) {
            if (method_exists($editor, 'set_quality')) {
                $editor->set_quality((int)$quality);
            }
            $saved = $editor->save($dest, $format_info['mime']);
            if (!is_wp_error($saved)) {
                return true;
            }
        }
        
        // Fallback to direct library handling for formats WordPress doesn't support
        return $this->convert_with_direct_library($src, $dest, $target_format, $options);
    }
    
    /**
     * Direct library conversion for advanced formats
     */
    private function convert_with_direct_library($src, $dest, $target_format, $options = []) {
        $format_info = self::SUPPORTED_TARGET_FORMATS[$target_format];
        $quality = $options['quality'] ?? $format_info['default_quality'];
        
        switch ($target_format) {
            case 'webp':
                return $this->convert_webp_direct($src, $dest, $quality);
            
            case 'avif':
                $speed = $options['speed'] ?? ($this->settings['avif_speed'] ?? 6);
                return $this->convert_avif_direct($src, $dest, $quality, $speed);
                
            case 'jxl':
                $effort = $options['effort'] ?? ($this->settings['jxl_effort'] ?? 7);
                return $this->convert_jxl_direct($src, $dest, $quality, $effort);
                
            default:
                return false;
        }
    }
    
    private function convert_webp_direct($src, $dest, $quality) {
        // GD WebP conversion
        if (function_exists('imagewebp')) {
            $image = $this->load_image_gd($src);
            if ($image) {
                $result = imagewebp($image, $dest, $quality);
                imagedestroy($image);
                return $result;
            }
        }
        
        // Imagick WebP conversion
        if (class_exists('Imagick')) {
            try {
                $imagick = new Imagick($src);
                $imagick->setImageFormat('webp');
                $imagick->setImageCompressionQuality($quality);
                return $imagick->writeImage($dest);
            } catch (Exception $e) {
                return false;
            }
        }
        
        return false;
    }
    
    private function convert_avif_direct($src, $dest, $quality, $speed) {
        // GD AVIF conversion (PHP 8.1+)
        if (function_exists('imageavif')) {
            $image = $this->load_image_gd($src);
            if ($image) {
                $result = imageavif($image, $dest, $quality, $speed);
                imagedestroy($image);
                return $result;
            }
        }
        
        // Imagick AVIF conversion
        if (class_exists('Imagick')) {
            try {
                $imagick = new Imagick($src);
                $imagick->setImageFormat('avif');
                $imagick->setImageCompressionQuality($quality);
                // AVIF-specific options
                $imagick->setOption('avif:compression-speed', $speed);
                return $imagick->writeImage($dest);
            } catch (Exception $e) {
                return false;
            }
        }
        
        return false;
    }
    
    private function convert_jxl_direct($src, $dest, $quality, $effort) {
        // JPEG XL via Imagick (if supported)
        if (class_exists('Imagick')) {
            try {
                $imagick = new Imagick($src);
                $imagick->setImageFormat('jxl');
                $imagick->setImageCompressionQuality($quality);
                $imagick->setOption('jxl:effort', $effort);
                return $imagick->writeImage($dest);
            } catch (Exception $e) {
                return false;
            }
        }
        
        return false;
    }
    
    /**
     * Calculate new dimensions based on bounding box constraints
     */
    private function calculate_bounding_box_dimensions($current_width, $current_height, $box_width, $box_height, $mode = 'max') {
        if ($mode === 'max') {
            // Maximum bounding box - scale down if larger
            if ($current_width <= $box_width && $current_height <= $box_height) {
                return [$current_width, $current_height]; // No resize needed
            }
            
            $scale_x = $box_width / $current_width;
            $scale_y = $box_height / $current_height;
            $scale = min($scale_x, $scale_y); // Use smallest scale to fit within bounds
            
        } else {
            // Minimum bounding box - scale up if smaller
            if ($current_width >= $box_width && $current_height >= $box_height) {
                return [$current_width, $current_height]; // No resize needed
            }
            
            $scale_x = $box_width / $current_width;
            $scale_y = $box_height / $current_height;
            $scale = max($scale_x, $scale_y); // Use largest scale to meet minimum requirements
        }
        
        $new_width = round($current_width * $scale);
        $new_height = round($current_height * $scale);
        
        return [$new_width, $new_height];
    }
    
    /**
     * Resize image using WordPress image editor
     */
    private function resize_image($src_path, $new_width, $new_height) {
        $editor = wp_get_image_editor($src_path);
        if (is_wp_error($editor)) {
            return false;
        }
        
        $resized = $editor->resize($new_width, $new_height, false); // false = don't crop, just resize
        if (is_wp_error($resized)) {
            return false;
        }
        
        $saved = $editor->save($src_path); // Overwrite the source file
        return !is_wp_error($saved);
    }
    
    /**
     * Parse dimensions from filename using various patterns
     * Examples: "image-1920x1080.jpg", "file_150x150.webp", "photo-783x450.png"
     */
    private function parse_filename_dimensions($filename) {
        $basename = basename($filename, '.' . pathinfo($filename, PATHINFO_EXTENSION));
        
        // Common patterns for dimensions in filenames
        $patterns = [
            '/(\d{2,5})[x×](\d{2,5})(?:\D|$)/i',        // 1920x1080, 150×150
            '/-(\d{2,5})[x×](\d{2,5})(?:\D|$)/i',       // -1920x1080
            '/_(\d{2,5})[x×](\d{2,5})(?:\D|$)/i',       // _1920x1080
            '/[\s-_](\d{2,5})[x×](\d{2,5})$/i',         // ending with -1920x1080
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $basename, $matches)) {
                $width = (int)$matches[1];
                $height = (int)$matches[2];
                
                // Basic sanity check - dimensions should be reasonable
                if ($width >= 10 && $height >= 10 && $width <= 15000 && $height <= 15000) {
                    return [$width, $height];
                }
            }
        }
        
        return null;
    }
    
    /**
     * Validate filename dimensions against actual image dimensions
     */
    private function validate_filename_dimensions($file_path, $att_id) {
        if (empty($this->settings['check_filename_dimensions'])) {
            return; // Feature disabled
        }
        
        $filename = basename($file_path);
        $parsed_dims = $this->parse_filename_dimensions($filename);
        
        if (!$parsed_dims) {
            return; // No dimensions found in filename
        }
        
        $actual_size = getimagesize($file_path);
        if (!$actual_size) {
            return; // Could not get actual dimensions
        }
        
        list($parsed_width, $parsed_height) = $parsed_dims;
        list($actual_width, $actual_height) = $actual_size;
        
        // Check if dimensions match within ±5px tolerance
        $tolerance = 5;
        $width_match = abs($parsed_width - $actual_width) <= $tolerance;
        $height_match = abs($parsed_height - $actual_height) <= $tolerance;
        
        if (!$width_match || !$height_match) {
            // Dimensions don't match - log this inconsistency
            $this->log_dimension_inconsistency($att_id, $filename, $parsed_dims, [$actual_width, $actual_height]);
        }
    }
    
    /**
     * Determine if same-format images should be reprocessed for quality/size changes
     */
    private function should_reprocess_same_format($current_mime, $target_mime) {
        if ($current_mime !== $target_mime) {
            return false; // Different formats, normal conversion applies
        }
        
        // Same format - check if bounding box or quality changes are needed
        $bounding_box_enabled = !empty($this->settings['enable_bounding_box']);
        
        // For quality reprocessing, we could add metadata checks here
        // For now, we'll reprocess if bounding box is enabled (size changes needed)
        return $bounding_box_enabled;
    }
    
    /**
     * Log conversion error to JSON file and post meta with error count tracking
     */
    private function log_conversion_error($att_id, $error_message, $step = '', $additional_data = []) {
        $current_time = current_time('mysql');
        $current_unix = time();
        
        // Store error in post meta for quick access
        $error_data = [
            'error' => $error_message,
            'step' => $step,
            'timestamp' => $current_time,
            'timestamp_unix' => $current_unix,
            'additional_data' => $additional_data
        ];
        update_post_meta($att_id, self::ERROR_META, wp_json_encode($error_data));
        
        // Also log to central JSON file
        $uploads = wp_get_upload_dir();
        $log_file = trailingslashit($uploads['basedir']) . 'webp-migrator-conversion-errors.json';
        
        // Load existing log data
        $log_data = [];
        if (file_exists($log_file)) {
            $existing_data = @file_get_contents($log_file);
            if ($existing_data) {
                $log_data = json_decode($existing_data, true) ?: [];
            }
        }
        
        // Check if this attachment already has an error logged
        $existing_entry = $log_data[$att_id] ?? null;
        
        if ($existing_entry) {
            // Update existing entry - increment count and update last error
            $log_data[$att_id] = array_merge($existing_entry, [
                'error' => $error_message,  // Update to latest error message
                'step' => $step,           // Update to latest step
                'last_error_timestamp' => $current_time,
                'last_error_timestamp_unix' => $current_unix,
                'error_count' => ($existing_entry['error_count'] ?? 1) + 1,
                'target_format' => $this->settings['target_format'] ?? 'webp',
                'quality' => $this->settings['quality'] ?? 75,
                'additional_data' => $additional_data
            ]);
        } else {
            // Create new comprehensive log entry
            $log_data[$att_id] = [
                'attachment_id' => $att_id,
                'filename' => basename(get_attached_file($att_id) ?: ''),
                'full_path' => get_attached_file($att_id),
                'mime_type' => get_post_mime_type($att_id),
                'error' => $error_message,
                'step' => $step,
                'target_format' => $this->settings['target_format'] ?? 'webp',
                'quality' => $this->settings['quality'] ?? 75,
                'first_error_timestamp' => $current_time,
                'first_error_timestamp_unix' => $current_unix,
                'last_error_timestamp' => $current_time,
                'last_error_timestamp_unix' => $current_unix,
                'error_count' => 1,
                'additional_data' => $additional_data
            ];
        }
        
        // Save updated log
        $json_data = wp_json_encode($log_data, JSON_PRETTY_PRINT);
        @file_put_contents($log_file, $json_data, LOCK_EX);
        
        // Also log to WordPress error log for immediate visibility
        error_log(sprintf(
            'WebP Migrator Error [%s]: %s (Attachment #%d: %s) [Count: %d]',
            $step ?: 'unknown step',
            $error_message,
            $att_id,
            basename(get_attached_file($att_id) ?: ''),
            $log_data[$att_id]['error_count']
        ));
    }
    
    /**
     * Remove a specific conversion error from the log
     */
    private function remove_conversion_error($att_id) {
        $uploads = wp_get_upload_dir();
        $log_file = trailingslashit($uploads['basedir']) . 'webp-migrator-conversion-errors.json';
        
        if (!file_exists($log_file)) {
            return false;
        }
        
        $log_data = json_decode(@file_get_contents($log_file), true);
        if (!$log_data || !is_array($log_data)) {
            return false;
        }
        
        if (!isset($log_data[$att_id])) {
            return false;
        }
        
        unset($log_data[$att_id]);
        
        // Save updated log
        $json_data = wp_json_encode($log_data, JSON_PRETTY_PRINT);
        $result = @file_put_contents($log_file, $json_data, LOCK_EX);
        
        return $result !== false;
    }
    
    /**
     * Remove a specific dimension inconsistency from the log
     */
    private function remove_dimension_inconsistency($att_id) {
        $uploads = wp_get_upload_dir();
        $log_file = trailingslashit($uploads['basedir']) . 'webp-migrator-dimension-inconsistencies.json';
        
        if (!file_exists($log_file)) {
            return false;
        }
        
        $log_data = json_decode(@file_get_contents($log_file), true);
        if (!$log_data || !is_array($log_data)) {
            return false;
        }
        
        if (!isset($log_data[$att_id])) {
            return false;
        }
        
        unset($log_data[$att_id]);
        
        // Save updated log
        $json_data = wp_json_encode($log_data, JSON_PRETTY_PRINT);
        $result = @file_put_contents($log_file, $json_data, LOCK_EX);
        
        return $result !== false;
    }
    
    /**
     * Log dimension inconsistency to JSON file
     */
    private function log_dimension_inconsistency($att_id, $filename, $parsed_dims, $actual_dims) {
        $uploads = wp_get_upload_dir();
        $log_file = trailingslashit($uploads['basedir']) . 'webp-migrator-dimension-inconsistencies.json';
        
        // Load existing log data
        $log_data = [];
        if (file_exists($log_file)) {
            $existing_data = @file_get_contents($log_file);
            if ($existing_data) {
                $log_data = json_decode($existing_data, true) ?: [];
            }
        }
        
        // Create log entry
        $entry = [
            'attachment_id' => $att_id,
            'filename' => $filename,
            'full_path' => get_attached_file($att_id),
            'parsed_dimensions' => [
                'width' => $parsed_dims[0],
                'height' => $parsed_dims[1]
            ],
            'actual_dimensions' => [
                'width' => $actual_dims[0],
                'height' => $actual_dims[1]
            ],
            'difference' => [
                'width_diff' => $actual_dims[0] - $parsed_dims[0],
                'height_diff' => $actual_dims[1] - $parsed_dims[1]
            ],
            'timestamp' => current_time('mysql'),
            'timestamp_unix' => time()
        ];
        
        // Add to log data (keyed by attachment ID to avoid duplicates)
        $log_data[$att_id] = $entry;
        
        // Save updated log
        $json_data = wp_json_encode($log_data, JSON_PRETTY_PRINT);
        @file_put_contents($log_file, $json_data, LOCK_EX);
        
        // Also log to WordPress error log for immediate visibility
        error_log(sprintf(
            'WebP Migrator: Dimension mismatch in %s - Filename suggests %dx%d but actual is %dx%d',
            $filename,
            $parsed_dims[0], $parsed_dims[1],
            $actual_dims[0], $actual_dims[1]
        ));
    }
    
    private function load_image_gd($src) {
        $info = getimagesize($src);
        if (!$info) return false;
        
        switch ($info['mime']) {
            case 'image/jpeg':
                return imagecreatefromjpeg($src);
            case 'image/png':
                return imagecreatefrompng($src);
            case 'image/gif':
                return imagecreatefromgif($src);
            default:
                return false;
        }
    }

    private function build_url_map($uploads, $old_meta, $new_meta) {
        $map = [];

        $old_orig_rel = $old_meta['file'];
        $new_orig_rel = $new_meta['file'];
        $map[$uploads['baseurl'].'/'.$old_orig_rel] = $uploads['baseurl'].'/'.$new_orig_rel;

        $old_dir_rel = trailingslashit(dirname($old_orig_rel));
        $new_dir_rel = trailingslashit(dirname($new_orig_rel));

        $old_sizes = isset($old_meta['sizes']) && is_array($old_meta['sizes']) ? $old_meta['sizes'] : [];
        $new_sizes = isset($new_meta['sizes']) && is_array($new_meta['sizes']) ? $new_meta['sizes'] : [];

        foreach ($new_sizes as $size => $n) {
            if (!isset($old_sizes[$size]['file'])) continue; // only map names we had before
            $old_file = $uploads['baseurl'].'/'.$old_dir_rel.$old_sizes[$size]['file'];
            $new_file = $uploads['baseurl'].'/'.$new_dir_rel.$n['file'];
            $map[$old_file] = $new_file;
        }

        // Filesystem path mappings (rare but helps)
        $map[trailingslashit($uploads['basedir']).$old_orig_rel] = trailingslashit($uploads['basedir']).$new_orig_rel;
        foreach ($new_sizes as $size => $n) {
            if (!isset($old_sizes[$size]['file'])) continue;
            $map[trailingslashit($uploads['basedir']).$old_dir_rel.$old_sizes[$size]['file']]
                = trailingslashit($uploads['basedir']).$new_dir_rel.$n['file'];
        }

        // Extension swap helpers - now supports multiple target formats
        $target_format = $this->settings['target_format'] ?? 'webp';
        $target_ext = self::SUPPORTED_TARGET_FORMATS[$target_format]['ext'] ?? 'webp';
        $exts = ['jpg','jpeg','png','gif'];
        foreach ($exts as $ext) {
            $map_ext = function($url) use ($target_ext) { 
                return preg_replace('/\.(jpg|jpeg|png|gif)\b/i', '.' . $target_ext, $url); 
            };
            foreach (array_keys($map) as $k) {
                $map[$map_ext($k)] = $map[$k];
            }
        }

        return $map;
    }

    /** Replace everywhere and collect a report of what changed */
    private function replace_everywhere(array $url_map): array {
        $report = [
            'posts'         => [],
            'postmeta'      => [],
            'options'       => [],
            'usermeta'      => [],
            'termmeta'      => [],
            'comments'      => [],
            'custom_tables' => [],
        ];

        // POSTS
        global $wpdb;
        foreach ($url_map as $old => $new) {
            if ($old === $new) continue;
            $like = '%' . $wpdb->esc_like($old) . '%';
            $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s", $like));
            foreach ($ids as $pid) {
                $post = get_post($pid);
                if (!$post) continue;
                $content = $post->post_content;
                $new_content = str_replace($old, $new, $content);
                if ($new_content !== $content) {
                    wp_update_post(['ID' => $pid, 'post_content' => $new_content]);
                    $report['posts'][] = (int)$pid;
                }
            }
        }

        // POSTMETA
        $changed_meta = $this->replace_in_table_serialized_with_report($url_map, 'postmeta', 'meta_value', function($row){
            return function($new_value) use ($row){
                return update_post_meta((int)$row->post_id, $row->meta_key, $new_value);
            };
        }, function($row) use (&$report){
            $report['postmeta'][] = ['post_id' => (int)$row->post_id, 'meta_key' => (string)$row->meta_key];
        });

        // OPTIONS
        $this->replace_in_table_serialized_with_report($url_map, 'options', 'option_value', function($row){
            return function($new_value) use ($row){
                return update_option($row->option_name, $new_value);
            };
        }, function($row) use (&$report){
            $report['options'][] = (string)$row->option_name;
        });

        // USERMETA (user profile images, avatars, etc.)
        $this->replace_in_table_serialized_with_report($url_map, 'usermeta', 'meta_value', function($row){
            return function($new_value) use ($row){
                return update_user_meta((int)$row->user_id, $row->meta_key, $new_value);
            };
        }, function($row) use (&$report){
            $report['usermeta'][] = ['user_id' => (int)$row->user_id, 'meta_key' => (string)$row->meta_key];
        });

        // TERMMETA (category/tag images, taxonomy metadata)
        $this->replace_in_table_serialized_with_report($url_map, 'termmeta', 'meta_value', function($row){
            return function($new_value) use ($row){
                return update_term_meta((int)$row->term_id, $row->meta_key, $new_value);
            };
        }, function($row) use (&$report){
            $report['termmeta'][] = ['term_id' => (int)$row->term_id, 'meta_key' => (string)$row->meta_key];
        });

        // COMMENTS (image references in comment content)
        foreach ($url_map as $old => $new) {
            if ($old === $new) continue;
            $like = '%' . $wpdb->esc_like($old) . '%';
            $comment_ids = $wpdb->get_col($wpdb->prepare("SELECT comment_ID FROM {$wpdb->comments} WHERE comment_content LIKE %s", $like));
            foreach ($comment_ids as $cid) {
                $comment = get_comment($cid);
                if (!$comment) continue;
                $content = $comment->comment_content;
                $new_content = str_replace($old, $new, $content);
                if ($new_content !== $content) {
                    wp_update_comment([
                        'comment_ID' => $cid,
                        'comment_content' => $new_content
                    ]);
                    $report['comments'][] = (int)$cid;
                }
            }
        }

        // ENHANCED: Search for JSON-encoded image references in any column
        // This helps with modern plugins and e-commerce that store data as JSON
        $this->replace_in_json_columns($url_map, $report);

        return $report;
    }

    private function replace_in_table_serialized_with_report(array $url_map, $table, $value_col, $update_closure_factory, $on_changed_row) {
        global $wpdb;

        // Build WHERE with OR of LIKEs (cap # of probes for perf)
        $likes = [];
        $map_keys = array_slice(array_keys($url_map), 0, 10);
        foreach ($map_keys as $k) {
            $likes[] = $wpdb->prepare("$value_col LIKE %s", '%'.$wpdb->esc_like($k).'%');
        }
        if (!$likes) return;

        $table_name = $table === 'postmeta' ? $wpdb->postmeta : 
                      ($table === 'options' ? $wpdb->options :
                      ($table === 'usermeta' ? $wpdb->usermeta :
                      ($table === 'termmeta' ? $wpdb->termmeta : null)));
        
        if (!$table_name) return; // Unknown table type
        
        $pk = $table === 'postmeta' ? 'meta_id' : 
              ($table === 'options' ? 'option_id' :
              ($table === 'usermeta' ? 'umeta_id' :
              ($table === 'termmeta' ? 'meta_id' : 'id')));
        $sql = "SELECT * FROM {$table_name} WHERE " . implode(' OR ', $likes) . " LIMIT 5000";
        $rows = $wpdb->get_results($sql);

        foreach ($rows as $row) {
            $raw = $row->{$value_col};
            $value = maybe_unserialize($raw);
            $new_value = $this->deep_replace($value, $url_map);
            if ($new_value !== $value) {
                $update = $update_closure_factory($row);
                $update(maybe_serialize($new_value));
                $on_changed_row($row);
            }
        }
    }

    private function deep_replace($data, array $url_map) {
        if (is_string($data)) {
            foreach ($url_map as $old => $new) {
                if ($old !== $new) $data = str_replace($old, $new, $data);
            }
            return $data;
        } elseif (is_array($data)) {
            foreach ($data as $k => $v) $data[$k] = $this->deep_replace($v, $url_map);
            return $data;
        } elseif (is_object($data)) {
            foreach ($data as $k => $v) $data->$k = $this->deep_replace($v, $url_map);
            return $data;
        }
        return $data;
    }

    private function collect_and_remove_old_files($uploads, $old_meta, $validation_mode, $backup_dir = null) {
        $paths = [];
        $old_rel = $old_meta['file'];
        $dir_rel = trailingslashit(dirname($old_rel));
        $paths[] = trailingslashit($uploads['basedir']).$old_rel;

        if (!empty($old_meta['sizes']) && is_array($old_meta['sizes'])) {
            foreach ($old_meta['sizes'] as $size) {
                if (empty($size['file'])) continue;
                $paths[] = trailingslashit($uploads['basedir']).$dir_rel.$size['file'];
            }
        }

        foreach ($paths as $p) {
            if (!file_exists($p)) continue;
            if ($validation_mode) {
                if ($backup_dir) {
                    @wp_mkdir_p($backup_dir);
                    @rename($p, trailingslashit($backup_dir).basename($p));
                }
            } else {
                @unlink($p);
            }
        }
    }

    private function commit_deletions($att_id) {
        $status = get_post_meta($att_id, self::STATUS_META, true);
        if ($status !== 'relinked') return false;

        $backup_dir = get_post_meta($att_id, self::BACKUP_META, true);
        if ($backup_dir && is_dir($backup_dir)) {
            $this->rrmdir($backup_dir);
        }
        update_post_meta($att_id, self::STATUS_META, 'committed');
        delete_post_meta($att_id, self::BACKUP_META);
        return true;
    }

    /**
     * Rollback a conversion by restoring original files from backup
     */
    private function rollback_conversion($att_id) {
        $status = get_post_meta($att_id, self::STATUS_META, true);
        if ($status !== 'relinked') return false; // Can only rollback pending conversions

        $backup_dir = get_post_meta($att_id, self::BACKUP_META, true);
        if (!$backup_dir || !is_dir($backup_dir)) {
            return false; // No backup directory exists
        }

        // Get original metadata before conversion
        $uploads = wp_get_upload_dir();
        $current_file = get_attached_file($att_id);
        $current_meta = wp_get_attachment_metadata($att_id);
        
        if (!$current_file || !$current_meta) {
            return false; // Cannot determine current file structure
        }

        try {
            // Build paths for restoration
            $current_dir = dirname($current_file);
            $backup_files = scandir($backup_dir);
            
            // Find and restore original files from backup
            $restored_files = [];
            foreach ($backup_files as $backup_file) {
                if ($backup_file === '.' || $backup_file === '..') continue;
                
                $backup_path = trailingslashit($backup_dir) . $backup_file;
                if (is_file($backup_path)) {
                    // Determine if this is the main file or a size variant
                    $restore_path = trailingslashit($current_dir) . $backup_file;
                    
                    // Copy back from backup (don't move - keep backup until committed)
                    if (@copy($backup_path, $restore_path)) {
                        $restored_files[] = $restore_path;
                    }
                }
            }
            
            if (empty($restored_files)) {
                return false; // No files were restored
            }
            
            // Find the main original file (largest or by naming convention)
            $main_original = null;
            foreach ($restored_files as $file) {
                if (preg_match('/\.(jpg|jpeg|png|gif)$/i', $file)) {
                    $main_original = $file;
                    break;
                }
            }
            
            if (!$main_original) {
                // Fallback - use first restored file
                $main_original = $restored_files[0];
            }
            
            // Generate metadata for restored original
            $original_meta = wp_generate_attachment_metadata($att_id, $main_original);
            if (!$original_meta) {
                // Create basic metadata
                $rel_path = ltrim(str_replace(trailingslashit($uploads['basedir']), '', $main_original), '/');
                $original_meta = ['file' => $rel_path, 'sizes' => []];
            }
            
            // Restore original MIME type
            $original_mime = wp_check_filetype($main_original)['type'];
            if (!$original_mime) {
                $image_info = getimagesize($main_original);
                $original_mime = $image_info['mime'] ?? 'image/jpeg';
            }
            
            // Update attachment to original state
            wp_update_post([
                'ID' => $att_id,
                'post_mime_type' => $original_mime,
                'guid' => $uploads['baseurl'] . '/' . $original_meta['file']
            ]);
            
            update_post_meta($att_id, '_wp_attached_file', $original_meta['file']);
            wp_update_attachment_metadata($att_id, $original_meta);
            
            // Remove converted files
            $converted_file = $current_file;
            if (file_exists($converted_file)) {
                @unlink($converted_file);
            }
            
            // Remove converted sizes
            if (!empty($current_meta['sizes'])) {
                $current_dir_rel = trailingslashit(dirname($current_meta['file']));
                foreach ($current_meta['sizes'] as $size) {
                    if (!empty($size['file'])) {
                        $size_path = trailingslashit($uploads['basedir']) . $current_dir_rel . $size['file'];
                        if (file_exists($size_path)) {
                            @unlink($size_path);
                        }
                    }
                }
            }
            
            // Reset status and clear metadata
            delete_post_meta($att_id, self::STATUS_META);
            delete_post_meta($att_id, self::BACKUP_META);
            delete_post_meta($att_id, self::REPORT_META);
            delete_post_meta($att_id, self::ERROR_META);
            
            // Keep backup directory for potential future reference
            // Could optionally delete it: $this->rrmdir($backup_dir);
            
            return true;
            
        } catch (Exception $e) {
            error_log('WebP Migrator Rollback Error: ' . $e->getMessage() . ' (Attachment #' . $att_id . ')');
            return false;
        }
    }

    private function rrmdir($dir) {
        if (!is_dir($dir)) return false;
        $files = scandir($dir);
        foreach ($files as $f) {
            if ($f === '.' || $f === '..') continue;
            $path = $dir.DIRECTORY_SEPARATOR.$f;
            if (is_dir($path)) $this->rrmdir($path); else @unlink($path);
        }
        return @rmdir($dir);
    }

    /**
     * Enhanced JSON column search for modern plugins and e-commerce
     * Searches for image URLs in JSON-encoded columns across custom tables
     */
    private function replace_in_json_columns(array $url_map, array &$report) {
        global $wpdb;

        // Get list of all custom tables (non-WordPress core)
        $wp_tables = [
            $wpdb->posts, $wpdb->postmeta, $wpdb->options, $wpdb->users, $wpdb->usermeta,
            $wpdb->comments, $wpdb->commentmeta, $wpdb->terms, $wpdb->termmeta, $wpdb->term_relationships,
            $wpdb->term_taxonomy, $wpdb->links
        ];
        
        $all_tables = $wpdb->get_col("SHOW TABLES");
        $custom_tables = array_diff($all_tables, $wp_tables);
        
        if (!$custom_tables) return; // No custom tables to check

        // Initialize custom tables tracking in report
        if (!isset($report['custom_tables'])) {
            $report['custom_tables'] = [];
        }

        // Check each custom table for potential JSON columns containing image URLs
        foreach (array_slice($custom_tables, 0, 10) as $table) { // Limit to 10 tables for performance
            try {
                // Get columns that might contain JSON data
                $columns = $wpdb->get_results("DESCRIBE `$table`");
                $json_candidates = [];
                
                foreach ($columns as $col) {
                    $type = strtolower($col->Type);
                    // Look for JSON, TEXT, LONGTEXT columns that might contain serialized/JSON data
                    if (strpos($type, 'json') !== false || 
                        strpos($type, 'text') !== false || 
                        strpos($type, 'longtext') !== false) {
                        $json_candidates[] = $col->Field;
                    }
                }
                
                if (!$json_candidates) continue;
                
                // Search for image URLs in these columns
                foreach ($json_candidates as $column) {
                    foreach (array_slice(array_keys($url_map), 0, 5) as $old_url) { // Limit URL checks
                        $like = '%' . $wpdb->esc_like($old_url) . '%';
                        $rows = $wpdb->get_results($wpdb->prepare(
                            "SELECT * FROM `$table` WHERE `$column` LIKE %s LIMIT 100", 
                            $like
                        ));
                        
                        foreach ($rows as $row) {
                            $raw_value = $row->{$column};
                            if (!$raw_value) continue;
                            
                            // Try to decode as JSON first
                            $decoded = json_decode($raw_value, true);
                            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                // Handle JSON data
                                $new_decoded = $this->deep_replace($decoded, $url_map);
                                if ($new_decoded !== $decoded) {
                                    $new_json = wp_json_encode($new_decoded);
                                    $wpdb->update($table, [$column => $new_json], ['id' => $row->id ?? $row->ID ?? null]);
                                    $report['custom_tables'][] = ['table' => $table, 'column' => $column, 'row_id' => $row->id ?? $row->ID ?? 'unknown'];
                                }
                            } else {
                                // Handle as serialized WordPress data
                                $unserialized = maybe_unserialize($raw_value);
                                $new_unserialized = $this->deep_replace($unserialized, $url_map);
                                if ($new_unserialized !== $unserialized) {
                                    $new_serialized = maybe_serialize($new_unserialized);
                                    $wpdb->update($table, [$column => $new_serialized], ['id' => $row->id ?? $row->ID ?? null]);
                                    $report['custom_tables'][] = ['table' => $table, 'column' => $column, 'row_id' => $row->id ?? $row->ID ?? 'unknown'];
                                }
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                // Skip tables that cause errors (permissions, structure issues, etc.)
                continue;
            }
        }
    }

    /**
     * AJAX handler for processing batch
     */
    public function ajax_process_batch() {
        check_ajax_referer('webp_migrator_batch', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $batch_size = min(10, max(1, (int)($_POST['batch_size'] ?? 5)));
        $quality = max(1, min(100, (int)($_POST['quality'] ?? $this->settings['quality'])));
        $validation_mode = (bool)($_POST['validation'] ?? $this->current_validation_mode());
        
        $ids = $this->get_non_target_format_attachments($batch_size, true);
        
        $results = [];
        $processed = 0;
        $errors = 0;
        
        foreach ($ids as $att_id) {
            $result = $this->process_attachment($att_id, $quality, $validation_mode);
            $attachment_title = get_the_title($att_id) ?: 'Unknown';
            
            if ($result) {
                $processed++;
                $results[] = [
                    'id' => $att_id,
                    'title' => $attachment_title,
                    'status' => 'success',
                    'message' => 'Converted successfully'
                ];
            } else {
                $errors++;
                $error_data = json_decode(get_post_meta($att_id, self::ERROR_META, true) ?: '{}', true);
                $results[] = [
                    'id' => $att_id,
                    'title' => $attachment_title,
                    'status' => 'error',
                    'message' => $error_data['error'] ?? 'Unknown error'
                ];
            }
        }
        
        wp_send_json_success([
            'processed' => $processed,
            'errors' => $errors,
            'results' => $results,
            'remaining' => count($this->get_non_target_format_attachments(1000, true)) // Get estimate
        ]);
    }
    
    /**
     * AJAX handler for getting queue count
     */
    public function ajax_get_queue_count() {
        check_ajax_referer('webp_migrator_batch', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $count = count($this->get_non_target_format_attachments(1000, true));
        wp_send_json_success(['count' => $count]);
    }
    
    /**
     * AJAX handler for reprocessing single attachment with errors
     */
    public function ajax_reprocess_single() {
        check_ajax_referer('webp_migrator_batch', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $att_id = (int)($_POST['attachment_id'] ?? 0);
        if ($att_id <= 0) {
            wp_send_json_error('Invalid attachment ID');
        }
        
        $quality = max(1, min(100, (int)($_POST['quality'] ?? $this->settings['quality'])));
        $validation_mode = (bool)($_POST['validation'] ?? $this->current_validation_mode());
        
        // Process this specific attachment (don't exclude errors for reprocessing)
        $result = $this->process_attachment($att_id, $quality, $validation_mode);
        $attachment_title = get_the_title($att_id) ?: 'Unknown';
        
        if ($result) {
            wp_send_json_success([
                'id' => $att_id,
                'title' => $attachment_title,
                'message' => 'Fixed successfully'
            ]);
        } else {
            $error_data = json_decode(get_post_meta($att_id, self::ERROR_META, true) ?: '{}', true);
            wp_send_json_error([
                'id' => $att_id,
                'title' => $attachment_title,
                'message' => $error_data['error'] ?? 'Unknown error'
            ]);
        }
    }
    
    /**
     * Render batch processor tab
     */
    public function render_batch_tab() {
        $target_format = strtoupper($this->settings['target_format'] ?? 'webp');
        $quality = $this->settings['quality'] ?? 75;
        $validation = $this->settings['validation'] ?? 1;
        
        ?>
        <h2>Batch Processor</h2>
        <p>Process multiple images with real-time progress tracking. Images with previous errors will be skipped automatically.</p>
            
            <div id="batch-status" style="margin: 20px 0; padding: 15px; border: 1px solid #ddd; background: #f9f9f9;">
                <p><strong>Current settings:</strong> Converting to <strong><?php echo esc_html($target_format); ?></strong> format at <strong><?php echo esc_attr($quality); ?>%</strong> quality</p>
                <p><strong>Validation mode:</strong> <?php echo $validation ? 'Enabled (originals preserved)' : 'Disabled (originals deleted immediately)'; ?></p>
                <p id="queue-count">Loading queue count...</p>
            </div>
            
            <div id="batch-controls" style="margin: 20px 0;">
                <label for="batch-size">Batch Size: </label>
                <select id="batch-size">
                    <option value="1">1 image</option>
                    <option value="5" selected>5 images</option>
                    <option value="10">10 images</option>
                    <option value="20">20 images</option>
                </select>
                
                <button id="start-batch" class="button button-primary" style="margin-left: 10px;">Start Batch Processing</button>
                <button id="stop-batch" class="button" style="margin-left: 10px; display: none;">Stop Processing</button>
            </div>
            
            <div id="progress-container" style="display: none; margin: 20px 0;">
                <div style="background: #f0f0f0; border: 1px solid #ddd; border-radius: 3px; overflow: hidden;">
                    <div id="progress-bar" style="background: #0073aa; height: 20px; width: 0%; transition: width 0.3s;"></div>
                </div>
                <p id="progress-text">Processing...</p>
            </div>
            
            <div id="results-container" style="margin: 20px 0;">
                <div id="results-summary" style="display: none; padding: 10px; border: 1px solid #ddd; margin-bottom: 10px;"></div>
                <div id="results-log" style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: white; font-family: monospace; font-size: 12px; display: none;"></div>
            </div>
            
            <script>
            jQuery(document).ready(function($) {
                var processing = false;
                var totalProcessed = 0;
                var totalErrors = 0;
                var initialQueueCount = 0;
                
                // Load initial queue count
                function loadQueueCount() {
                    $.post(ajaxurl, {
                        action: 'webp_migrator_get_queue_count',
                        nonce: '<?php echo wp_create_nonce('webp_migrator_batch'); ?>'
                    }, function(response) {
                        if (response.success) {
                            initialQueueCount = response.data.count;
                            $('#queue-count').html('<strong>' + response.data.count + '</strong> images in processing queue');
                        }
                    });
                }
                
                // Process a single batch
                function processBatch() {
                    if (!processing) return;
                    
                    var batchSize = parseInt($('#batch-size').val());
                    
                    $.post(ajaxurl, {
                        action: 'webp_migrator_process_batch',
                        nonce: '<?php echo wp_create_nonce('webp_migrator_batch'); ?>',
                        batch_size: batchSize,
                        quality: <?php echo esc_js($quality); ?>,
                        validation: <?php echo esc_js($validation); ?>
                    }, function(response) {
                        if (response.success) {
                            var data = response.data;
                            totalProcessed += data.processed;
                            totalErrors += data.errors;
                            
                            // Update progress
                            var currentProcessed = initialQueueCount - data.remaining;
                            var progressPercent = initialQueueCount > 0 ? (currentProcessed / initialQueueCount) * 100 : 100;
                            $('#progress-bar').css('width', progressPercent + '%');
                            $('#progress-text').text('Processed: ' + currentProcessed + ' / ' + initialQueueCount + ' (' + Math.round(progressPercent) + '%)');
                            
                            // Update summary
                            $('#results-summary').show().html(
                                '<strong>Total Processed:</strong> ' + totalProcessed + ' | ' +
                                '<strong>Errors:</strong> ' + totalErrors + ' | ' +
                                '<strong>Remaining:</strong> ' + data.remaining
                            );
                            
                            // Add results to log
                            var logDiv = $('#results-log');
                            logDiv.show();
                            
                            data.results.forEach(function(result) {
                                var timestamp = new Date().toLocaleTimeString();
                                var statusColor = result.status === 'success' ? '#0073aa' : '#d63638';
                                logDiv.append(
                                    '<div style="margin-bottom: 5px;">' +
                                    '<span style="color: #666;">[' + timestamp + ']</span> ' +
                                    '<strong>#' + result.id + '</strong> ' + result.title + ': ' +
                                    '<span style="color: ' + statusColor + ';">' + result.message + '</span>' +
                                    '</div>'
                                );
                            });
                            logDiv.scrollTop(logDiv[0].scrollHeight);
                            
                            // Continue processing if there are remaining items
                            if (data.remaining > 0 && processing) {
                                setTimeout(processBatch, 1000); // 1 second delay between batches
                            } else {
                                // Processing complete
                                processing = false;
                                $('#start-batch').show().text('Start Batch Processing');
                                $('#stop-batch').hide();
                                $('#progress-text').text('Processing complete!');
                                loadQueueCount(); // Refresh queue count
                            }
                        } else {
                            // Error occurred
                            processing = false;
                            $('#start-batch').show();
                            $('#stop-batch').hide();
                            alert('Error: ' + (response.data || 'Unknown error occurred'));
                        }
                    }).fail(function() {
                        processing = false;
                        $('#start-batch').show();
                        $('#stop-batch').hide();
                        alert('Network error occurred. Please try again.');
                    });
                }
                
                // Start button click
                $('#start-batch').click(function() {
                    if (processing) return;
                    
                    processing = true;
                    totalProcessed = 0;
                    totalErrors = 0;
                    
                    $(this).hide();
                    $('#stop-batch').show();
                    $('#progress-container').show();
                    $('#results-log').empty();
                    
                    processBatch();
                });
                
                // Stop button click
                $('#stop-batch').click(function() {
                    processing = false;
                    $('#start-batch').show();
                    $(this).hide();
                    loadQueueCount(); // Refresh queue count
                });
                
                // Load initial queue count
                loadQueueCount();
            });
            </script>
        <?php
    }
    
    /**
     * Render error reprocessor tab
     */
    public function render_reprocess_tab() {
        $uploads = wp_get_upload_dir();
        $log_file = trailingslashit($uploads['basedir']) . 'webp-migrator-conversion-errors.json';
        
        ?>
        <h2>Error Reprocessor</h2>
        <p>Retry processing images that previously failed. This page only processes images with logged errors.</p>
            
            <?php
            if (!file_exists($log_file)) {
                echo '<div class="notice notice-success"><p><strong>No errors to reprocess!</strong> All conversions have been successful.</p></div>';
                return;
            }
            
            $log_data = json_decode(@file_get_contents($log_file), true);
            if (!$log_data || !is_array($log_data)) {
                echo '<div class="notice notice-error"><p>Could not read error log file.</p></div>';
                return;
            }
            
            $error_count = count($log_data);
            ?>
            
            <div style="margin: 20px 0; padding: 15px; border: 1px solid #d63638; background: #fff2f2;">
                <p><strong><?php echo $error_count; ?></strong> images with conversion errors available for reprocessing.</p>
                <p><strong>Note:</strong> Reprocessing errors will not create duplicate log entries - existing error counts and timestamps will be preserved if errors occur again.</p>
            </div>
            
            <div id="reprocess-controls" style="margin: 20px 0;">
                <label for="reprocess-batch-size">Batch Size: </label>
                <select id="reprocess-batch-size">
                    <option value="1">1 image</option>
                    <option value="3" selected>3 images</option>
                    <option value="5">5 images</option>
                    <option value="10">10 images</option>
                </select>
                
                <button id="start-reprocess" class="button button-primary" style="margin-left: 10px;">Start Reprocessing</button>
                <button id="stop-reprocess" class="button" style="margin-left: 10px; display: none;">Stop</button>
            </div>
            
            <div id="reprocess-progress" style="display: none; margin: 20px 0;">
                <div style="background: #f0f0f0; border: 1px solid #ddd; border-radius: 3px; overflow: hidden;">
                    <div id="reprocess-progress-bar" style="background: #00a32a; height: 20px; width: 0%; transition: width 0.3s;"></div>
                </div>
                <p id="reprocess-progress-text">Reprocessing...</p>
            </div>
            
            <div id="reprocess-results" style="margin: 20px 0;">
                <div id="reprocess-summary" style="display: none; padding: 10px; border: 1px solid #ddd; margin-bottom: 10px;"></div>
                <div id="reprocess-log" style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: white; font-family: monospace; font-size: 12px; display: none;"></div>
            </div>
            
            <script>
            jQuery(document).ready(function($) {
                var reprocessing = false;
                var totalReprocessed = 0;
                var totalFixed = 0;
                var totalStillErrors = 0;
                var errorIds = <?php echo wp_json_encode(array_keys($log_data)); ?>;
                var currentIndex = 0;
                
                function reprocessBatch() {
                    if (!reprocessing || currentIndex >= errorIds.length) {
                        // Reprocessing complete
                        reprocessing = false;
                        $('#start-reprocess').show().text('Start Reprocessing');
                        $('#stop-reprocess').hide();
                        $('#reprocess-progress-text').text('Reprocessing complete!');
                        return;
                    }
                    
                    var batchSize = parseInt($('#reprocess-batch-size').val());
                    var batchIds = errorIds.slice(currentIndex, currentIndex + batchSize);
                    
                    // Process each ID in the batch sequentially
                    var batchPromises = batchIds.map(function(attId) {
                        return $.post(ajaxurl, {
                            action: 'webp_migrator_process_batch',
                            nonce: '<?php echo wp_create_nonce('webp_migrator_batch'); ?>',
                            batch_size: 1,
                            attachment_ids: [attId], // Custom parameter for specific IDs
                            quality: <?php echo esc_js($this->settings['quality'] ?? 75); ?>,
                            validation: <?php echo esc_js($this->settings['validation'] ?? 1); ?>
                        });
                    });
                    
                    // Process each ID in the batch
                    var processed = 0;
                    batchIds.forEach(function(attId) {
                        $.post(ajaxurl, {
                            action: 'webp_migrator_reprocess_single',
                            nonce: '<?php echo wp_create_nonce('webp_migrator_batch'); ?>',
                            attachment_id: attId,
                            quality: <?php echo esc_js($this->settings['quality'] ?? 75); ?>,
                            validation: <?php echo esc_js($this->settings['validation'] ?? 1); ?>
                        }, function(response) {
                            totalReprocessed++;
                            processed++;
                            var timestamp = new Date().toLocaleTimeString();
                            var logDiv = $('#reprocess-log');
                            logDiv.show();
                            
                            if (response && response.success) {
                                totalFixed++;
                                logDiv.append(
                                    '<div style="margin-bottom: 5px; color: #00a32a;">' +
                                    '<span style="color: #666;">[' + timestamp + ']</span> ' +
                                    '<strong>#' + attId + '</strong>: ' + (response.data.message || 'Fixed successfully!') +
                                    '</div>'
                                );
                            } else {
                                totalStillErrors++;
                                var errorMsg = (response && response.data && response.data.message) || 'Still has errors';
                                logDiv.append(
                                    '<div style="margin-bottom: 5px; color: #d63638;">' +
                                    '<span style="color: #666;">[' + timestamp + ']</span> ' +
                                    '<strong>#' + attId + '</strong>: ' + errorMsg +
                                    '</div>'
                                );
                            }
                            logDiv.scrollTop(logDiv[0].scrollHeight);
                            
                            // Update summary after each completion
                            $('#reprocess-summary').show().html(
                                '<strong>Reprocessed:</strong> ' + totalReprocessed + ' | ' +
                                '<strong>Fixed:</strong> ' + totalFixed + ' | ' +
                                '<strong>Still Errors:</strong> ' + totalStillErrors
                            );
                        }).fail(function() {
                            totalReprocessed++;
                            totalStillErrors++;
                            var timestamp = new Date().toLocaleTimeString();
                            var logDiv = $('#reprocess-log');
                            logDiv.append(
                                '<div style="margin-bottom: 5px; color: #d63638;">' +
                                '<span style="color: #666;">[' + timestamp + ']</span> ' +
                                '<strong>#' + attId + '</strong>: Network error' +
                                '</div>'
                            );
                            logDiv.scrollTop(logDiv[0].scrollHeight);
                        });
                    });
                    
                    currentIndex += batchSize;
                    
                    // Update progress
                    var progressPercent = (currentIndex / errorIds.length) * 100;
                    $('#reprocess-progress-bar').css('width', Math.min(100, progressPercent) + '%');
                    $('#reprocess-progress-text').text('Reprocessed: ' + totalReprocessed + ' / ' + errorIds.length);
                    
                    // Update summary
                    $('#reprocess-summary').show().html(
                        '<strong>Reprocessed:</strong> ' + totalReprocessed + ' | ' +
                        '<strong>Fixed:</strong> ' + totalFixed + ' | ' +
                        '<strong>Still Errors:</strong> ' + totalStillErrors
                    );
                    
                    // Continue with next batch after delay
                    if (reprocessing) {
                        setTimeout(reprocessBatch, 2000); // 2 second delay for reprocessing
                    }
                }
                
                $('#start-reprocess').click(function() {
                    if (reprocessing) return;
                    
                    reprocessing = true;
                    totalReprocessed = 0;
                    totalFixed = 0;
                    totalStillErrors = 0;
                    currentIndex = 0;
                    
                    $(this).hide();
                    $('#stop-reprocess').show();
                    $('#reprocess-progress').show();
                    $('#reprocess-log').empty();
                    
                    reprocessBatch();
                });
                
                $('#stop-reprocess').click(function() {
                    reprocessing = false;
                    $('#start-reprocess').show();
                    $(this).hide();
                });
            });
            </script>
        <?php
    }
}

$GLOBALS['webp_safe_migrator'] = new WebP_Safe_Migrator();

/**
 * WP-CLI handler
 *
 * Usage examples:
 *   wp webp-migrator run --batch=100 --no-validate
 *   wp webp-migrator run --batch=25 --format=avif --quality=60
 *   wp webp-migrator run --format=jxl --quality=80 --effort=8
 */
if (defined('WP_CLI') && WP_CLI) {
    class WebP_Safe_Migrator_CLI {
        public static function dispatch($args, $assoc_args) {
            $sub = array_shift($args) ?: 'run';
            if ($sub === 'run') self::run($assoc_args);
            else WP_CLI::error("Unknown subcommand '$sub'. Try: wp webp-migrator run");
        }

        public static function run($assoc_args) {
            $plugin = WebP_Safe_Migrator::instance();
            if (!$plugin) WP_CLI::error('Plugin not loaded.');

            $settings = get_option(WebP_Safe_Migrator::OPTION, []);
            
            // Format selection with CLI override
            $target_format = isset($assoc_args['format']) ? $assoc_args['format'] : ($settings['target_format'] ?? 'webp');
            if (!array_key_exists($target_format, WebP_Safe_Migrator::SUPPORTED_TARGET_FORMATS)) {
                WP_CLI::error("Unsupported format: $target_format. Supported: " . implode(', ', array_keys(WebP_Safe_Migrator::SUPPORTED_TARGET_FORMATS)));
            }
            
            // Quality with CLI override and format-specific defaults
            $format_info = WebP_Safe_Migrator::SUPPORTED_TARGET_FORMATS[$target_format];
            $default_quality = $settings[$target_format . '_quality'] ?? $format_info['default_quality'];
            $quality = isset($assoc_args['quality']) ? max(1, min(100, (int)$assoc_args['quality'])) : $default_quality;
            
            // Additional format-specific options
            $format_options = [];
            if ($target_format === 'avif') {
                $format_options['speed'] = isset($assoc_args['speed']) ? max(0, min(10, (int)$assoc_args['speed'])) : ($settings['avif_speed'] ?? 6);
            } elseif ($target_format === 'jxl') {
                $format_options['effort'] = isset($assoc_args['effort']) ? max(1, min(9, (int)$assoc_args['effort'])) : ($settings['jxl_effort'] ?? 7);
            }
            
            $batch = isset($assoc_args['batch']) ? max(1, (int)$assoc_args['batch']) : (int)($settings['batch_size'] ?? 10);
            $no_validate = array_key_exists('no-validate', $assoc_args);
            $validate = !$no_validate;

            // Override plugin target format for this run
            $original_format = $settings['target_format'] ?? 'webp';
            $plugin->settings['target_format'] = $target_format;
            $plugin->settings['quality'] = $quality;
            
            // Apply format-specific settings
            foreach ($format_options as $key => $value) {
                $plugin->settings[$target_format . '_' . $key] = $value;
            }

            $plugin->set_runtime_validation($validate);

            $ids = $plugin->get_non_target_format_attachments($batch);
            if (!$ids) {
                WP_CLI::success('No eligible attachments found (maybe all converted or skipped by filters).');
                return;
            }

            $processed = 0;
            foreach ($ids as $id) {
                $ok = $plugin->process_attachment((int)$id, $quality, $validate);
                $processed += $ok ? 1 : 0;
                WP_CLI::log(($ok ? 'OK   ' : 'SKIP')." #$id");
            }
            // Restore original format setting
            $plugin->settings['target_format'] = $original_format;
            
            $format_details = '';
            if (!empty($format_options)) {
                $format_details = ' (' . implode(', ', array_map(function($k, $v) { return "$k=$v"; }, array_keys($format_options), $format_options)) . ')';
            }
            
            WP_CLI::success("Processed {$processed}/".count($ids)." attachments to " . strtoupper($target_format) . " format (Quality: {$quality}{$format_details}). Validation: ".($validate ? 'ON' : 'OFF'));
        }
    }
}
