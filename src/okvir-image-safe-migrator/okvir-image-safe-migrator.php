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

    public function menu() {
        add_media_page(
            'Okvir Image Safe Migrator', 
            'Image Migrator', 
            'manage_options',
            'okvir-image-safe-migrator', 
            [$this, 'render_tabbed_interface']
        );
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

        // Add more action handlers as we expand functionality
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

    // I need to continue building the functionality gradually...
    // Let me create the rest of the file in a follow-up write operation
}
