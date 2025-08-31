<?php
/**
 * Okvir Image Safe Migrator Uninstall Script
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
function okvir_image_migrator_cleanup_all_data() {
    global $wpdb;
    
    // Remove plugin options
    delete_option('okvir_image_safe_migrator_settings');
    delete_option('okvir_image_migrator_queue');
    delete_option('okvir_image_migrator_progress');
    delete_option('okvir_image_migrator_statistics');     // Statistics tracking data
    
    // Remove all plugin-related postmeta
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_okvir_image_%'");
    
    // Remove backup directories
    $upload_dir = wp_get_upload_dir();
    $backup_dir = trailingslashit($upload_dir['basedir']) . 'okvir-image-migrator-backup';
    
    if (is_dir($backup_dir)) {
        okvir_image_migrator_remove_directory($backup_dir);
    }
    
    // Remove log files if they exist
    $log_dir = trailingslashit($upload_dir['basedir']) . 'okvir-image-migrator-logs';
    if (is_dir($log_dir)) {
        okvir_image_migrator_remove_directory($log_dir);
    }
    
    // Remove individual log files that might exist in uploads root
    $error_log_file = trailingslashit($upload_dir['basedir']) . 'okvir-image-migrator-conversion-errors.json';
    if (file_exists($error_log_file)) {
        @unlink($error_log_file);
    }
    
    $dimension_log_file = trailingslashit($upload_dir['basedir']) . 'okvir-image-migrator-dimension-inconsistencies.json';
    if (file_exists($dimension_log_file)) {
        @unlink($dimension_log_file);
    }
    
    // Clear any scheduled cron jobs
    wp_clear_scheduled_hook('okvir_image_migrator_process_queue');
    
    // Remove any transients
    delete_transient('okvir_image_migrator_status');
    delete_transient('okvir_image_migrator_progress');
    
    // Remove user meta (if any plugin-specific user preferences were stored)
    $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'okvir_image_migrator_%'");
}

/**
 * Recursively remove directory and all contents
 */
function okvir_image_migrator_remove_directory($dir) {
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
            okvir_image_migrator_remove_directory($path);
        } else {
            @unlink($path);
        }
    }
    
    return @rmdir($dir);
}

// Execute cleanup
okvir_image_migrator_cleanup_all_data();

// Log uninstall (if debug logging is enabled)
if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
    error_log('[Okvir Image Safe Migrator] Plugin uninstalled and all data cleaned up.');
}
