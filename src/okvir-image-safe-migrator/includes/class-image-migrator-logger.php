<?php
/**
 * Okvir Image Safe Migrator Logger Class
 * 
 * Comprehensive logging system for debugging and monitoring
 */

if (!defined('ABSPATH')) exit;

class Okvir_Image_Migrator_Logger {
    
    const LOG_OPTION = 'okvir_image_migrator_logs';
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
                trailingslashit($upload_dir['basedir']) . 'okvir-image-migrator-logs/' . date('Y-m-d') . '.log';
            
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
        $formatted_message = $this->format_message($message, $context);
        
        // Store in database
        if ($this->store_in_db) {
            $this->store_in_database($level, $formatted_message, $timestamp, $context);
        }
        
        // Store in file
        if ($this->log_file) {
            $this->store_in_file($level, $formatted_message, $timestamp);
        }
        
        // Also log to WordPress error log if it's an error or critical
        if (in_array($level, ['error', 'critical']) && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log("[Okvir Image Migrator - {$level}] {$formatted_message}");
        }
    }
    
    /**
     * Format log message with context
     */
    private function format_message($message, $context = []) {
        if (empty($context)) {
            return $message;
        }
        
        // Simple context interpolation
        foreach ($context as $key => $value) {
            if (is_scalar($value)) {
                $message = str_replace('{' . $key . '}', $value, $message);
            }
        }
        
        // Add context data as JSON if there are unprocessed values
        $remaining_context = array_filter($context, function($value) {
            return !is_scalar($value);
        });
        
        if (!empty($remaining_context)) {
            $message .= ' | Context: ' . wp_json_encode($remaining_context);
        }
        
        return $message;
    }
    
    /**
     * Store log entry in database
     */
    private function store_in_database($level, $message, $timestamp, $context = []) {
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
        
        // Limit log entries to prevent database bloat
        if (count($logs) > self::MAX_LOG_ENTRIES) {
            $logs = array_slice($logs, -self::MAX_LOG_ENTRIES);
        }
        
        update_option(self::LOG_OPTION, $logs, false); // Don't autoload logs
    }
    
    /**
     * Store log entry in file
     */
    private function store_in_file($level, $message, $timestamp) {
        $memory = $this->format_bytes(memory_get_usage(true));
        $log_line = "[{$timestamp}] {$level}: {$message} (Memory: {$memory})" . PHP_EOL;
        
        file_put_contents($this->log_file, $log_line, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Format bytes for display
     */
    private function format_bytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    /**
     * Get recent logs
     */
    public function get_recent_logs($limit = 50, $level_filter = null) {
        $logs = get_option(self::LOG_OPTION, []);
        
        if ($level_filter) {
            $logs = array_filter($logs, function($log) use ($level_filter) {
                return $log['level'] === $level_filter;
            });
        }
        
        // Sort by timestamp (newest first)
        usort($logs, function($a, $b) {
            return strcmp($b['timestamp'], $a['timestamp']);
        });
        
        return array_slice($logs, 0, $limit);
    }
    
    /**
     * Clear all logs
     */
    public function clear_logs() {
        delete_option(self::LOG_OPTION);
        
        if ($this->log_file && file_exists($this->log_file)) {
            unlink($this->log_file);
        }
        
        $this->info('Logs cleared');
    }
    
    /**
     * Get log statistics
     */
    public function get_log_stats() {
        $logs = get_option(self::LOG_OPTION, []);
        
        $stats = [
            'total' => count($logs),
            'levels' => []
        ];
        
        foreach ($logs as $log) {
            $level = $log['level'];
            if (!isset($stats['levels'][$level])) {
                $stats['levels'][$level] = 0;
            }
            $stats['levels'][$level]++;
        }
        
        return $stats;
    }
    
    /**
     * Export logs to file
     */
    public function export_logs($format = 'txt') {
        $logs = get_option(self::LOG_OPTION, []);
        
        if (empty($logs)) {
            return false;
        }
        
        $upload_dir = wp_get_upload_dir();
        $export_file = trailingslashit($upload_dir['basedir']) . 'okvir-image-migrator-export-' . date('Y-m-d-H-i-s') . '.' . $format;
        
        switch ($format) {
            case 'json':
                file_put_contents($export_file, wp_json_encode($logs, JSON_PRETTY_PRINT));
                break;
                
            case 'csv':
                $handle = fopen($export_file, 'w');
                fputcsv($handle, ['timestamp', 'level', 'message', 'memory_usage']);
                
                foreach ($logs as $log) {
                    fputcsv($handle, [
                        $log['timestamp'],
                        $log['level'],
                        $log['message'],
                        $log['memory_usage'] ?? ''
                    ]);
                }
                fclose($handle);
                break;
                
            default: // txt
                $content = '';
                foreach ($logs as $log) {
                    $memory = isset($log['memory_usage']) ? $this->format_bytes($log['memory_usage']) : 'N/A';
                    $content .= "[{$log['timestamp']}] {$log['level']}: {$log['message']} (Memory: {$memory})" . PHP_EOL;
                }
                file_put_contents($export_file, $content);
                break;
        }
        
        return $export_file;
    }
    
    /**
     * Log performance metrics
     */
    public function log_performance($operation, $start_time, $memory_start = null) {
        $duration = microtime(true) - $start_time;
        $memory_current = memory_get_usage(true);
        $memory_peak = memory_get_peak_usage(true);
        
        $context = [
            'operation' => $operation,
            'duration' => round($duration, 4),
            'memory_current' => $this->format_bytes($memory_current),
            'memory_peak' => $this->format_bytes($memory_peak)
        ];
        
        if ($memory_start !== null) {
            $context['memory_used'] = $this->format_bytes($memory_current - $memory_start);
        }
        
        $this->info("Performance: {operation} completed in {duration}s", $context);
    }
    
    /**
     * Log conversion result
     */
    public function log_conversion($attachment_id, $result, $format = 'webp') {
        if (is_wp_error($result)) {
            $this->error("Conversion failed for attachment #{attachment_id} to {format}: {error}", [
                'attachment_id' => $attachment_id,
                'format' => $format,
                'error' => $result->get_error_message()
            ]);
        } else {
            $this->info("Successfully converted attachment #{attachment_id} to {format}", [
                'attachment_id' => $attachment_id,
                'format' => $format
            ]);
        }
    }
}
