<?php
/**
 * Plugin Name: Okvir Duplicate Image Detector
 * Plugin URI: https://okvir.com/plugins/duplicate-image-detector
 * Description: Advanced duplicate image detection and management system with 5 different algorithms for comprehensive duplicate analysis.
 * Version: 1.0.0
 * Author: Okvir Development Team
 * Author URI: https://okvir.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: okvir-duplicate-detector
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Network: false
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('OKVIR_DUP_DETECTOR_VERSION', '1.0.0');
define('OKVIR_DUP_DETECTOR_PLUGIN_FILE', __FILE__);
define('OKVIR_DUP_DETECTOR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OKVIR_DUP_DETECTOR_PLUGIN_URL', plugin_dir_url(__FILE__));
define('OKVIR_DUP_DETECTOR_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main Plugin Class
 */
class OkvirDuplicateImageDetector {
    
    // Database table names (without prefix)
    const TABLE_IMAGE_SIGNATURES = 'okvir_image_signatures';
    const TABLE_IMAGE_ANALYSIS = 'okvir_image_analysis';
    const TABLE_DUPLICATE_GROUPS = 'okvir_duplicate_groups';
    const TABLE_PROCESSING_QUEUE = 'okvir_processing_queue';
    
    // Detection method constants
    const METHOD_FILE_HASH = 'file_hash';
    const METHOD_PERCEPTUAL_HASH = 'perceptual_hash';
    const METHOD_COLOR_HISTOGRAM = 'color_histogram';
    const METHOD_TEMPLATE_MATCH = 'template_match';
    const METHOD_KEYPOINT_MATCH = 'keypoint_match';
    
    // Processing constants
    const MAX_BATCH_SIZE = 100; // Hard-coded limit
    const MIN_MATCH_METHODS = 2; // Minimum methods that must agree for duplicate detection
    
    private static $instance = null;
    private $settings = [];
    private $db_version = '1.0.0';
    
    /**
     * Singleton pattern
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init();
    }
    
    /**
     * Initialize plugin
     */
    private function init() {
        // Load settings
        $this->settings = get_option('okvir_duplicate_detector_settings', $this->get_default_settings());
        
        // Hook into WordPress
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        // Admin hooks
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'admin_init']);
        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_scripts']);
        
        // AJAX hooks
        add_action('wp_ajax_okvir_dup_process_batch', [$this, 'ajax_process_batch']);
        add_action('wp_ajax_okvir_dup_get_status', [$this, 'ajax_get_status']);
        add_action('wp_ajax_okvir_dup_delete_duplicates', [$this, 'ajax_delete_duplicates']);
        
        // Background processing hook
        if ($this->settings['enable_background_processing']) {
            add_action('wp_ajax_nopriv_okvir_dup_background_process', [$this, 'background_process']);
            add_action('okvir_dup_process_queue', [$this, 'process_queue_cron']);
        }
        
        // Load required files
        $this->load_dependencies();
    }
    
    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        require_once OKVIR_DUP_DETECTOR_PLUGIN_DIR . 'includes/class-database-manager.php';
        require_once OKVIR_DUP_DETECTOR_PLUGIN_DIR . 'includes/class-image-analyzer.php';
        require_once OKVIR_DUP_DETECTOR_PLUGIN_DIR . 'includes/class-duplicate-detector.php';
        require_once OKVIR_DUP_DETECTOR_PLUGIN_DIR . 'includes/class-content-scanner.php';
        require_once OKVIR_DUP_DETECTOR_PLUGIN_DIR . 'includes/class-batch-processor.php';
        require_once OKVIR_DUP_DETECTOR_PLUGIN_DIR . 'includes/algorithms/class-file-hash.php';
        require_once OKVIR_DUP_DETECTOR_PLUGIN_DIR . 'includes/algorithms/class-perceptual-hash.php';
        require_once OKVIR_DUP_DETECTOR_PLUGIN_DIR . 'includes/algorithms/class-color-histogram.php';
        require_once OKVIR_DUP_DETECTOR_PLUGIN_DIR . 'includes/algorithms/class-template-match.php';
        require_once OKVIR_DUP_DETECTOR_PLUGIN_DIR . 'includes/algorithms/class-keypoint-match.php';
    }
    
    /**
     * Get default settings
     */
    private function get_default_settings() {
        return [
            'enabled_methods' => [
                self::METHOD_FILE_HASH => true,
                self::METHOD_PERCEPTUAL_HASH => true,
                self::METHOD_COLOR_HISTOGRAM => true,
                self::METHOD_TEMPLATE_MATCH => false, // Disabled by default due to computational cost
                self::METHOD_KEYPOINT_MATCH => false  // Disabled by default due to computational cost
            ],
            'batch_size' => 20,
            'enable_background_processing' => true,
            'similarity_threshold' => [
                self::METHOD_PERCEPTUAL_HASH => 95, // Percentage similarity
                self::METHOD_COLOR_HISTOGRAM => 85,
                self::METHOD_TEMPLATE_MATCH => 90,
                self::METHOD_KEYPOINT_MATCH => 80
            ],
            'image_types' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'min_file_size' => 1024, // Bytes - ignore very small images
            'max_file_size' => 50 * 1024 * 1024, // 50MB limit
            'auto_delete_confirmed_duplicates' => false,
            'backup_before_delete' => true
        ];
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create database tables
        $db_manager = new OkvirDupDetector_DatabaseManager();
        $db_manager->create_tables();
        
        // Set default options
        if (!get_option('okvir_duplicate_detector_settings')) {
            update_option('okvir_duplicate_detector_settings', $this->get_default_settings());
        }
        
        // Schedule background processing if enabled
        if ($this->settings['enable_background_processing']) {
            if (!wp_next_scheduled('okvir_dup_process_queue')) {
                wp_schedule_event(time(), 'hourly', 'okvir_dup_process_queue');
            }
        }
        
        // Set database version
        update_option('okvir_duplicate_detector_db_version', $this->db_version);
        
        // Create upload directory for backups
        $upload_dir = wp_get_upload_dir();
        $backup_dir = trailingslashit($upload_dir['basedir']) . 'okvir-duplicate-detector-backups';
        if (!file_exists($backup_dir)) {
            wp_mkdir_p($backup_dir);
            // Create .htaccess to protect backups
            file_put_contents($backup_dir . '/.htaccess', "deny from all\n");
        }
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('okvir_dup_process_queue');
        
        // Note: We don't delete data on deactivation, only on uninstall
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_management_page(
            'Duplicate Image Detector',
            'Duplicate Images',
            'manage_options',
            'okvir-duplicate-detector',
            [$this, 'admin_page']
        );
    }
    
    /**
     * Admin init
     */
    public function admin_init() {
        // Register settings
        register_setting('okvir_duplicate_detector_settings', 'okvir_duplicate_detector_settings');
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function admin_enqueue_scripts($hook) {
        if ('tools_page_okvir-duplicate-detector' !== $hook) {
            return;
        }
        
        wp_enqueue_script(
            'okvir-duplicate-detector-admin',
            OKVIR_DUP_DETECTOR_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            OKVIR_DUP_DETECTOR_VERSION,
            true
        );
        
        wp_enqueue_style(
            'okvir-duplicate-detector-admin',
            OKVIR_DUP_DETECTOR_PLUGIN_URL . 'assets/css/admin.css',
            [],
            OKVIR_DUP_DETECTOR_VERSION
        );
        
        wp_localize_script('okvir-duplicate-detector-admin', 'okvirDupDetector', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('okvir_duplicate_detector_nonce'),
            'maxBatchSize' => self::MAX_BATCH_SIZE,
            'strings' => [
                'processing' => __('Processing...', 'okvir-duplicate-detector'),
                'completed' => __('Completed', 'okvir-duplicate-detector'),
                'error' => __('Error occurred', 'okvir-duplicate-detector'),
                'confirmDelete' => __('Are you sure you want to delete these duplicate images?', 'okvir-duplicate-detector')
            ]
        ]);
    }
    
    /**
     * Render admin page
     */
    public function admin_page() {
        require_once OKVIR_DUP_DETECTOR_PLUGIN_DIR . 'includes/admin/admin-page.php';
    }
    
    /**
     * AJAX: Process batch
     */
    public function ajax_process_batch() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'okvir_duplicate_detector_nonce')) {
            wp_die('Security check failed');
        }
        
        $batch_size = min(intval($_POST['batch_size'] ?? 20), self::MAX_BATCH_SIZE);
        $image_types = $_POST['image_types'] ?? $this->settings['image_types'];
        
        $batch_processor = new OkvirDupDetector_BatchProcessor();
        $result = $batch_processor->process_batch($batch_size, $image_types);
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Get processing status
     */
    public function ajax_get_status() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        global $wpdb;
        
        $status = [
            'total_images' => $this->get_total_images_count(),
            'processed_images' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}" . self::TABLE_IMAGE_ANALYSIS),
            'duplicate_groups' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}" . self::TABLE_DUPLICATE_GROUPS),
            'queue_remaining' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}" . self::TABLE_PROCESSING_QUEUE . " WHERE status = 'pending'"),
            'processing_active' => get_transient('okvir_dup_processing_active') ? true : false
        ];
        
        wp_send_json_success($status);
    }
    
    /**
     * AJAX: Delete duplicates
     */
    public function ajax_delete_duplicates() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'okvir_duplicate_detector_nonce')) {
            wp_die('Security check failed');
        }
        
        $duplicate_ids = array_map('intval', $_POST['duplicate_ids'] ?? []);
        
        if (empty($duplicate_ids)) {
            wp_send_json_error('No duplicates selected');
        }
        
        $content_scanner = new OkvirDupDetector_ContentScanner();
        $result = $content_scanner->delete_duplicates_safely($duplicate_ids);
        
        wp_send_json_success($result);
    }
    
    /**
     * Background processing
     */
    public function background_process() {
        // Set processing flag
        set_transient('okvir_dup_processing_active', true, 3600);
        
        $batch_processor = new OkvirDupDetector_BatchProcessor();
        $batch_processor->process_background_queue();
        
        // Clear processing flag
        delete_transient('okvir_dup_processing_active');
    }
    
    /**
     * Cron job for processing queue
     */
    public function process_queue_cron() {
        if (!$this->settings['enable_background_processing']) {
            return;
        }
        
        // Check if already processing
        if (get_transient('okvir_dup_processing_active')) {
            return;
        }
        
        $this->background_process();
    }
    
    /**
     * Get total images count
     */
    private function get_total_images_count() {
        $args = [
            'post_type' => 'attachment',
            'post_mime_type' => array_map(function($type) {
                return "image/{$type}";
            }, $this->settings['image_types']),
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ];
        
        $query = new WP_Query($args);
        return $query->found_posts;
    }
    
    /**
     * Get plugin settings
     */
    public function get_settings() {
        return $this->settings;
    }
    
    /**
     * Update plugin settings
     */
    public function update_settings($new_settings) {
        $this->settings = wp_parse_args($new_settings, $this->get_default_settings());
        update_option('okvir_duplicate_detector_settings', $this->settings);
    }
}

// Initialize plugin
OkvirDuplicateImageDetector::get_instance();

// Register uninstall hook
register_uninstall_hook(__FILE__, 'okvir_duplicate_detector_uninstall');

/**
 * Uninstall function
 */
function okvir_duplicate_detector_uninstall() {
    global $wpdb;
    
    // Drop plugin tables
    $tables = [
        $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_IMAGE_SIGNATURES,
        $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_IMAGE_ANALYSIS,
        $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_DUPLICATE_GROUPS,
        $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_PROCESSING_QUEUE
    ];
    
    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
    }
    
    // Delete plugin options
    delete_option('okvir_duplicate_detector_settings');
    delete_option('okvir_duplicate_detector_db_version');
    
    // Clear scheduled events
    wp_clear_scheduled_hook('okvir_dup_process_queue');
    
    // Remove backup directory
    $upload_dir = wp_get_upload_dir();
    $backup_dir = trailingslashit($upload_dir['basedir']) . 'okvir-duplicate-detector-backups';
    if (file_exists($backup_dir)) {
        // Recursively delete backup directory
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($backup_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($backup_dir);
    }
    
    // Clean up any transients
    delete_transient('okvir_dup_processing_active');
}
