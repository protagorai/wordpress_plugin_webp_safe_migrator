<?php
/**
 * WebP Migrator Logger Class
 * 
 * Comprehensive logging system for debugging and monitoring
 */

if (!defined('ABSPATH')) exit;

class WebP_Migrator_Logger {
    
    const LOG_OPTION = 'webp_migrator_logs';
    const MAX_LOG_ENTRIES = 1000;
    
    /** @var array Log levels */
    const LEVELS = [
        'debug' => 0,
        'info' => 1,
        'warning' => 2,
        'error' => 3,
        'critical' => 4
    ];
    
    /** @var int Current log level threshold */
    private $log_level;
    
    /** @var bool Whether to store logs in database */
    private $store_in_db;
    
    /** @var string Log file path */
    private $log_file;
    
    public function __construct($options = []) {
        $options = wp_parse_args($options, [
            'log_level' => 'info',
            'store_in_db' => true,
            'store_in_file' => true,
            'log_file' => null
        ]);
        
        $this->log_level = self::LEVELS[$options['log_level']] ?? self::LEVELS['info'];
        $this->store_in_db = $options['store_in_db'];
        
        if ($options['store_in_file']) {
            $upload_dir = wp_get_upload_dir();
            $this->log_file = $options['log_file'] ?: 
                trailingslashit($upload_dir['basedir']) . 'webp-migrator-logs/' . date('Y-m-d') . '.log';
            
            // Ensure log directory exists
            wp_mkdir_p(dirname($this->log_file));
        }
    }
    
    /**
     * Log debug message
     */
    public function debug($message, $context = []) {
        $this->log('debug', $message, $context);
    }
    
    /**
     * Log info message
     */
    public function info($message, $context = []) {
        $this->log('info', $message, $context);
    }
    
    /**
     * Log warning message
     */
    public function warning($message, $context = []) {
        $this->log('warning', $message, $context);
    }
    
    /**
     * Log error message
     */
    public function error($message, $context = []) {
        $this->log('error', $message, $context);
    }
    
    /**
     * Log critical message
     */
    public function critical($message, $context = []) {
        $this->log('critical', $message, $context);
    }
    
    /**
     * Main logging method
     */
    public function log($level, $message, $context = []) {
        $level_int = self::LEVELS[$level] ?? self::LEVELS['info'];
        
        // Check if we should log this level
        if ($level_int < $this->log_level) {
            return;
        }
        
        $timestamp = current_time('mysql');
        $formatted_message = $this->format_message($level, $message, $context, $timestamp);
        
        // Store in database
        if ($this->store_in_db) {
            $this->store_in_database($level, $message, $context, $timestamp);
        }
        
        // Store in file
        if ($this->log_file) {
            $this->store_in_file($formatted_message);
        }
        
        // Send to WordPress debug log if WP_DEBUG_LOG is enabled
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log("[WebP Migrator] {$formatted_message}");
        }
        
        // Trigger action for external integrations
        do_action('webp_migrator_log_entry', $level, $message, $context, $timestamp);
    }
    
    /**
     * Format log message
     */
    private function format_message($level, $message, $context, $timestamp) {
        $level_str = strtoupper($level);
        $context_str = !empty($context) ? ' ' . wp_json_encode($context) : '';
        
        return "[{$timestamp}] {$level_str}: {$message}{$context_str}";
    }
    
    /**
     * Store log entry in database
     */
    private function store_in_database($level, $message, $context, $timestamp) {
        $logs = get_option(self::LOG_OPTION, []);
        
        // Add new entry
        $logs[] = [
            'timestamp' => $timestamp,
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true)
        ];
        
        // Trim old entries if we exceed the limit
        if (count($logs) > self::MAX_LOG_ENTRIES) {
            $logs = array_slice($logs, -self::MAX_LOG_ENTRIES);
        }
        
        update_option(self::LOG_OPTION, $logs);
    }
    
    /**
     * Store log entry in file
     */
    private function store_in_file($formatted_message) {
        if (!$this->log_file) {
            return;
        }
        
        $result = @file_put_contents($this->log_file, $formatted_message . PHP_EOL, FILE_APPEND | LOCK_EX);
        
        if ($result === false) {
            // Fallback to WordPress debug log
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                error_log("[WebP Migrator] Failed to write to log file: {$this->log_file}");
            }
        }
    }
    
    /**
     * Get recent log entries
     */
    public function get_logs($limit = 100, $level_filter = null) {
        $logs = get_option(self::LOG_OPTION, []);
        
        // Filter by level if specified
        if ($level_filter) {
            $logs = array_filter($logs, function($entry) use ($level_filter) {
                return $entry['level'] === $level_filter;
            });
        }
        
        // Sort by timestamp (newest first)
        usort($logs, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });
        
        // Limit results
        if ($limit > 0) {
            $logs = array_slice($logs, 0, $limit);
        }
        
        return $logs;
    }
    
    /**
     * Clear all logs
     */
    public function clear_logs() {
        delete_option(self::LOG_OPTION);
        
        // Clear log file
        if ($this->log_file && file_exists($this->log_file)) {
            @unlink($this->log_file);
        }
        
        return true;
    }
    
    /**
     * Get log statistics
     */
    public function get_log_stats() {
        $logs = get_option(self::LOG_OPTION, []);
        
        $stats = [
            'total' => count($logs),
            'by_level' => [],
            'oldest' => null,
            'newest' => null
        ];
        
        if (!empty($logs)) {
            // Count by level
            foreach ($logs as $entry) {
                $level = $entry['level'];
                $stats['by_level'][$level] = ($stats['by_level'][$level] ?? 0) + 1;
            }
            
            // Find oldest and newest
            $timestamps = array_column($logs, 'timestamp');
            $stats['oldest'] = min($timestamps);
            $stats['newest'] = max($timestamps);
        }
        
        return $stats;
    }
    
    /**
     * Export logs to file
     */
    public function export_logs($format = 'json') {
        $logs = get_option(self::LOG_OPTION, []);
        
        $filename = 'webp-migrator-logs-' . date('Y-m-d-H-i-s');
        
        switch ($format) {
            case 'json':
                $content = wp_json_encode($logs, JSON_PRETTY_PRINT);
                $filename .= '.json';
                $mime_type = 'application/json';
                break;
                
            case 'csv':
                $content = $this->logs_to_csv($logs);
                $filename .= '.csv';
                $mime_type = 'text/csv';
                break;
                
            case 'txt':
            default:
                $content = $this->logs_to_text($logs);
                $filename .= '.txt';
                $mime_type = 'text/plain';
                break;
        }
        
        return [
            'filename' => $filename,
            'content' => $content,
            'mime_type' => $mime_type
        ];
    }
    
    /**
     * Convert logs to CSV format
     */
    private function logs_to_csv($logs) {
        if (empty($logs)) {
            return '';
        }
        
        $output = fopen('php://temp', 'w');
        
        // Header row
        fputcsv($output, ['Timestamp', 'Level', 'Message', 'Context', 'Memory Usage', 'Memory Peak']);
        
        // Data rows
        foreach ($logs as $entry) {
            fputcsv($output, [
                $entry['timestamp'],
                $entry['level'],
                $entry['message'],
                wp_json_encode($entry['context'] ?? []),
                $this->format_bytes($entry['memory_usage'] ?? 0),
                $this->format_bytes($entry['memory_peak'] ?? 0)
            ]);
        }
        
        rewind($output);
        $content = stream_get_contents($output);
        fclose($output);
        
        return $content;
    }
    
    /**
     * Convert logs to text format
     */
    private function logs_to_text($logs) {
        $content = "WebP Migrator Logs Export\n";
        $content .= "Generated: " . current_time('mysql') . "\n";
        $content .= str_repeat('=', 50) . "\n\n";
        
        foreach ($logs as $entry) {
            $content .= "[{$entry['timestamp']}] " . strtoupper($entry['level']) . ": {$entry['message']}\n";
            
            if (!empty($entry['context'])) {
                $content .= "Context: " . wp_json_encode($entry['context']) . "\n";
            }
            
            if (isset($entry['memory_usage'])) {
                $content .= "Memory: " . $this->format_bytes($entry['memory_usage']) . 
                           " (Peak: " . $this->format_bytes($entry['memory_peak']) . ")\n";
            }
            
            $content .= "\n";
        }
        
        return $content;
    }
    
    /**
     * Format bytes for human reading
     */
    private function format_bytes($size, $precision = 2) {
        if ($size === 0) {
            return '0 B';
        }
        
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $base = log($size, 1024);
        
        return round(pow(1024, $base - floor($base)), $precision) . ' ' . $units[floor($base)];
    }
}
