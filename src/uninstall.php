<?php
/**
 * WebP Safe Migrator Uninstall Script
 * 
 * This file is executed when the plugin is uninstalled via WordPress admin.
 * It cleans up all plugin data, options, and files.
 */

// Prevent direct access
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Clean up all plugin data
 */
function webp_migrator_cleanup_all_data() {
    global $wpdb;
    
    // Remove plugin options
    delete_option('webp_safe_migrator_settings');
    delete_option('webp_migrator_queue');
    delete_option('webp_migrator_progress');
    
    // Remove all plugin-related postmeta
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_webp_%'");
    
    // Remove backup directories
    $upload_dir = wp_get_upload_dir();
    $backup_dir = trailingslashit($upload_dir['basedir']) . 'webp-migrator-backup';
    
    if (is_dir($backup_dir)) {
        webp_migrator_remove_directory($backup_dir);
    }
    
    // Remove log files if they exist
    $log_dir = trailingslashit($upload_dir['basedir']) . 'webp-migrator-logs';
    if (is_dir($log_dir)) {
        webp_migrator_remove_directory($log_dir);
    }
    
    // Clear any scheduled cron jobs
    wp_clear_scheduled_hook('webp_migrator_process_queue');
    
    // Remove any transients
    delete_transient('webp_migrator_status');
    delete_transient('webp_migrator_progress');
}

/**
 * Recursively remove directory and all contents
 */
function webp_migrator_remove_directory($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    
    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        if (is_dir($path)) {
            webp_migrator_remove_directory($path);
        } else {
            @unlink($path);
        }
    }
    
    return @rmdir($dir);
}

// Execute cleanup
webp_migrator_cleanup_all_data();

// Log uninstall (if debug logging is enabled)
if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
    error_log('[WebP Safe Migrator] Plugin uninstalled and all data cleaned up.');
}
