<?php
/**
 * Batch Processor Class
 * 
 * Manages batch processing of images for duplicate detection.
 * Handles queue management, background processing, and progress tracking.
 */

if (!defined('ABSPATH')) {
    exit;
}

class OkvirDupDetector_BatchProcessor {
    
    private $settings;
    private $image_analyzer;
    private $duplicate_detector;
    
    public function __construct() {
        $this->settings = OkvirDuplicateImageDetector::get_instance()->get_settings();
        $this->image_analyzer = new OkvirDupDetector_ImageAnalyzer();
        $this->duplicate_detector = new OkvirDupDetector_DuplicateDetector();
    }
    
    /**
     * Process a batch of images
     */
    public function process_batch($batch_size = null, $image_types = null) {
        $batch_size = min($batch_size ?? $this->settings['batch_size'], OkvirDuplicateImageDetector::MAX_BATCH_SIZE);
        $image_types = $image_types ?? $this->settings['image_types'];
        
        $start_time = microtime(true);
        $start_memory = memory_get_usage(true);
        
        // Get unprocessed images
        $images = $this->get_unprocessed_images($batch_size, $image_types);
        
        if (empty($images)) {
            return [
                'success' => true,
                'message' => 'No images to process',
                'processed_count' => 0,
                'total_time' => 0,
                'memory_used' => 0
            ];
        }
        
        $results = [
            'success' => true,
            'processed_count' => 0,
            'successful_count' => 0,
            'failed_count' => 0,
            'duplicates_found' => 0,
            'processing_details' => [],
            'errors' => []
        ];
        
        // Process each image
        foreach ($images as $image) {
            try {
                $analysis_result = $this->image_analyzer->analyze_image($image['id']);
                
                if ($analysis_result['success']) {
                    $results['successful_count']++;
                    
                    // Check for duplicates and create groups
                    if ($analysis_result['is_duplicate']) {
                        $group_result = $this->duplicate_detector->process_duplicate($analysis_result);
                        if ($group_result['duplicates_found'] > 0) {
                            $results['duplicates_found'] += $group_result['duplicates_found'];
                        }
                    }
                    
                    $results['processing_details'][] = [
                        'attachment_id' => $image['id'],
                        'file_name' => basename($image['file_path']),
                        'status' => 'success',
                        'is_duplicate' => $analysis_result['is_duplicate'],
                        'analysis_score' => $analysis_result['analysis_score'],
                        'methods_used' => $analysis_result['methods_processed'],
                        'processing_time' => $analysis_result['execution_time']
                    ];
                    
                } else {
                    $results['failed_count']++;
                    $results['errors'][] = [
                        'attachment_id' => $image['id'],
                        'file_name' => basename($image['file_path']),
                        'error' => $analysis_result['error']
                    ];
                    
                    $results['processing_details'][] = [
                        'attachment_id' => $image['id'],
                        'file_name' => basename($image['file_path']),
                        'status' => 'failed',
                        'error' => $analysis_result['error']
                    ];
                }
                
                $results['processed_count']++;
                
                // Memory cleanup
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
                
            } catch (Exception $e) {
                $results['failed_count']++;
                $results['errors'][] = [
                    'attachment_id' => $image['id'],
                    'file_name' => basename($image['file_path']),
                    'error' => 'Exception: ' . $e->getMessage()
                ];
            }
        }
        
        $results['total_time'] = microtime(true) - $start_time;
        $results['memory_used'] = memory_get_usage(true) - $start_memory;
        $results['memory_peak'] = memory_get_peak_usage(true);
        
        return $results;
    }
    
    /**
     * Get unprocessed images from database
     */
    private function get_unprocessed_images($limit, $image_types) {
        global $wpdb;
        
        // Build MIME type filter
        $mime_types = array_map(function($type) {
            return "image/{$type}";
        }, $image_types);
        
        $mime_placeholders = implode(',', array_fill(0, count($mime_types), '%s'));
        
        // Get attachments that haven't been analyzed yet
        $query = $wpdb->prepare("
            SELECT p.ID as id, p.post_title, m.meta_value as file_path, p.post_mime_type
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = '_wp_attached_file'
            LEFT JOIN {$wpdb->prefix}" . OkvirDuplicateImageDetector::TABLE_IMAGE_ANALYSIS . " a ON p.ID = a.attachment_id
            WHERE p.post_type = 'attachment'
            AND p.post_mime_type IN ($mime_placeholders)
            AND p.post_status = 'inherit'
            AND a.attachment_id IS NULL
            ORDER BY p.ID ASC
            LIMIT %d
        ", array_merge($mime_types, [$limit]));
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        // Convert relative paths to absolute
        $upload_dir = wp_get_upload_dir();
        foreach ($results as &$result) {
            $result['file_path'] = trailingslashit($upload_dir['basedir']) . $result['file_path'];
            
            // Verify file exists and get additional info
            if (file_exists($result['file_path'])) {
                $image_info = getimagesize($result['file_path']);
                if ($image_info) {
                    $result['width'] = $image_info[0];
                    $result['height'] = $image_info[1];
                    $result['file_size'] = filesize($result['file_path']);
                }
            }
        }
        
        // Filter out missing files and files that don't meet criteria
        return array_filter($results, [$this, 'validate_image_for_processing']);
    }
    
    /**
     * Validate image meets processing criteria
     */
    private function validate_image_for_processing($image) {
        if (!file_exists($image['file_path'])) {
            return false;
        }
        
        $file_size = $image['file_size'] ?? filesize($image['file_path']);
        
        // Check file size limits
        if ($file_size < $this->settings['min_file_size'] || $file_size > $this->settings['max_file_size']) {
            return false;
        }
        
        // Check image dimensions if available
        if (isset($image['width']) && isset($image['height'])) {
            if ($image['width'] < 10 || $image['height'] < 10) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Add images to processing queue
     */
    public function add_to_queue($attachment_ids = null, $priority = 10) {
        global $wpdb;
        
        $queue_table = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_PROCESSING_QUEUE;
        
        if ($attachment_ids === null) {
            // Add all unprocessed images
            $images = $this->get_all_unprocessed_images();
            $attachment_ids = array_column($images, 'id');
        }
        
        if (empty($attachment_ids)) {
            return ['added' => 0, 'skipped' => 0];
        }
        
        $added = 0;
        $skipped = 0;
        
        foreach ($attachment_ids as $attachment_id) {
            // Check if already in queue
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$queue_table} WHERE attachment_id = %d",
                $attachment_id
            ));
            
            if ($exists) {
                $skipped++;
                continue;
            }
            
            // Add to queue
            $result = $wpdb->insert($queue_table, [
                'attachment_id' => $attachment_id,
                'priority' => $priority,
                'status' => 'pending'
            ], ['%d', '%d', '%s']);
            
            if ($result) {
                $added++;
            } else {
                $skipped++;
            }
        }
        
        return ['added' => $added, 'skipped' => $skipped];
    }
    
    /**
     * Process background queue
     */
    public function process_background_queue() {
        global $wpdb;
        
        $queue_table = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_PROCESSING_QUEUE;
        
        // Get pending items from queue
        $queue_items = $wpdb->get_results(
            "SELECT * FROM {$queue_table} 
             WHERE status = 'pending' 
             AND attempts < max_attempts
             ORDER BY priority DESC, scheduled_at ASC
             LIMIT " . $this->settings['batch_size'],
            ARRAY_A
        );
        
        if (empty($queue_items)) {
            return ['processed' => 0, 'message' => 'Queue is empty'];
        }
        
        $processed = 0;
        $start_time = microtime(true);
        
        foreach ($queue_items as $item) {
            // Update status to processing
            $wpdb->update($queue_table, [
                'status' => 'processing',
                'started_at' => current_time('mysql'),
                'attempts' => $item['attempts'] + 1
            ], ['id' => $item['id']], ['%s', '%s', '%d'], ['%d']);
            
            try {
                // Process the image
                $analysis_result = $this->image_analyzer->analyze_image($item['attachment_id']);
                
                if ($analysis_result['success']) {
                    // Mark as completed
                    $wpdb->update($queue_table, [
                        'status' => 'completed',
                        'completed_at' => current_time('mysql')
                    ], ['id' => $item['id']], ['%s', '%s'], ['%d']);
                    
                    // Check for duplicates
                    if ($analysis_result['is_duplicate']) {
                        $this->duplicate_detector->process_duplicate($analysis_result);
                    }
                    
                    $processed++;
                    
                } else {
                    // Mark as failed
                    $wpdb->update($queue_table, [
                        'status' => 'failed',
                        'error_message' => $analysis_result['error'],
                        'completed_at' => current_time('mysql')
                    ], ['id' => $item['id']], ['%s', '%s', '%s'], ['%d']);
                }
                
            } catch (Exception $e) {
                // Mark as failed with exception
                $wpdb->update($queue_table, [
                    'status' => 'failed',
                    'error_message' => 'Exception: ' . $e->getMessage(),
                    'completed_at' => current_time('mysql')
                ], ['id' => $item['id']], ['%s', '%s', '%s'], ['%d']);
            }
            
            // Check time limit (prevent long-running processes)
            if (microtime(true) - $start_time > 300) { // 5 minutes max
                break;
            }
        }
        
        return ['processed' => $processed];
    }
    
    /**
     * Get all unprocessed images
     */
    private function get_all_unprocessed_images() {
        global $wpdb;
        
        $mime_types = array_map(function($type) {
            return "image/{$type}";
        }, $this->settings['image_types']);
        
        $mime_placeholders = implode(',', array_fill(0, count($mime_types), '%s'));
        
        $query = $wpdb->prepare("
            SELECT p.ID as id, p.post_title, m.meta_value as file_path, p.post_mime_type
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = '_wp_attached_file'
            LEFT JOIN {$wpdb->prefix}" . OkvirDuplicateImageDetector::TABLE_IMAGE_ANALYSIS . " a ON p.ID = a.attachment_id
            WHERE p.post_type = 'attachment'
            AND p.post_mime_type IN ($mime_placeholders)
            AND p.post_status = 'inherit'
            AND a.attachment_id IS NULL
            ORDER BY p.ID ASC
        ", $mime_types);
        
        return $wpdb->get_results($query, ARRAY_A);
    }
    
    /**
     * Get queue status
     */
    public function get_queue_status() {
        global $wpdb;
        
        $queue_table = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_PROCESSING_QUEUE;
        
        $status_counts = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$queue_table} GROUP BY status",
            ARRAY_A
        );
        
        $status = [
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
            'total' => 0
        ];
        
        foreach ($status_counts as $row) {
            $status[$row['status']] = (int) $row['count'];
            $status['total'] += (int) $row['count'];
        }
        
        // Get total unprocessed images
        $total_unprocessed = count($this->get_all_unprocessed_images());
        $status['unqueued'] = $total_unprocessed;
        
        return $status;
    }
    
    /**
     * Clear completed queue items
     */
    public function clear_completed_queue() {
        global $wpdb;
        
        $queue_table = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_PROCESSING_QUEUE;
        
        $result = $wpdb->delete($queue_table, ['status' => 'completed'], ['%s']);
        
        return $result !== false ? $result : 0;
    }
    
    /**
     * Retry failed queue items
     */
    public function retry_failed_items() {
        global $wpdb;
        
        $queue_table = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_PROCESSING_QUEUE;
        
        $result = $wpdb->update(
            $queue_table,
            [
                'status' => 'pending',
                'attempts' => 0,
                'error_message' => null,
                'started_at' => null,
                'completed_at' => null,
                'scheduled_at' => current_time('mysql')
            ],
            ['status' => 'failed'],
            ['%s', '%d', '%s', '%s', '%s', '%s'],
            ['%s']
        );
        
        return $result !== false ? $result : 0;
    }
    
    /**
     * Get processing statistics
     */
    public function get_processing_statistics() {
        global $wpdb;
        
        $analysis_table = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_IMAGE_ANALYSIS;
        $groups_table = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_DUPLICATE_GROUPS;
        $log_table = $wpdb->prefix . 'okvir_processing_log';
        
        // Basic counts
        $stats = [];
        
        $stats['total_analyzed'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$analysis_table}");
        $stats['unique_images'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$analysis_table} WHERE processing_status = 'unique'");
        $stats['duplicate_images'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$analysis_table} WHERE processing_status = 'duplicate'");
        $stats['duplicate_groups'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$groups_table} WHERE status = 'active'");
        
        // Processing method usage
        $method_stats = $wpdb->get_results(
            "SELECT method, status, COUNT(*) as count 
             FROM {$log_table} 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY method, status",
            ARRAY_A
        );
        
        $stats['method_performance'] = [];
        foreach ($method_stats as $row) {
            $method = $row['method'];
            if (!isset($stats['method_performance'][$method])) {
                $stats['method_performance'][$method] = ['success' => 0, 'failed' => 0];
            }
            $stats['method_performance'][$method][$row['status']] = (int) $row['count'];
        }
        
        // Average processing times
        $avg_times = $wpdb->get_results(
            "SELECT method, AVG(execution_time) as avg_time, MAX(execution_time) as max_time
             FROM {$log_table}
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY method",
            ARRAY_A
        );
        
        $stats['processing_times'] = [];
        foreach ($avg_times as $row) {
            $stats['processing_times'][$row['method']] = [
                'average' => round($row['avg_time'], 3),
                'maximum' => round($row['max_time'], 3)
            ];
        }
        
        // Potential space savings
        $savings = $wpdb->get_row(
            "SELECT 
                COUNT(*) as duplicate_count,
                SUM(total_file_size) as total_duplicate_size,
                AVG(confidence_score) as avg_confidence
             FROM {$groups_table}
             WHERE status = 'active'",
            ARRAY_A
        );
        
        if ($savings) {
            $stats['potential_savings'] = [
                'duplicate_files' => (int) $savings['duplicate_count'],
                'total_size_bytes' => (int) $savings['total_duplicate_size'],
                'total_size_formatted' => size_format($savings['total_duplicate_size']),
                'average_confidence' => round($savings['avg_confidence'], 1)
            ];
        }
        
        // Queue statistics
        $stats['queue'] = $this->get_queue_status();
        
        return $stats;
    }
    
    /**
     * Estimate processing time for remaining images
     */
    public function estimate_remaining_time() {
        $unprocessed_count = count($this->get_all_unprocessed_images());
        
        if ($unprocessed_count === 0) {
            return ['remaining_images' => 0, 'estimated_time' => 0];
        }
        
        // Get average processing time from recent logs
        global $wpdb;
        $log_table = $wpdb->prefix . 'okvir_processing_log';
        
        $avg_time = $wpdb->get_var(
            "SELECT AVG(execution_time) 
             FROM {$log_table} 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)
             AND status = 'success'"
        );
        
        $avg_time = $avg_time ?: 2.0; // Default 2 seconds per image
        $batch_size = $this->settings['batch_size'];
        
        return [
            'remaining_images' => $unprocessed_count,
            'estimated_time_seconds' => (int) ($unprocessed_count * $avg_time),
            'estimated_batches' => (int) ceil($unprocessed_count / $batch_size),
            'estimated_time_formatted' => $this->format_time($unprocessed_count * $avg_time)
        ];
    }
    
    /**
     * Format time in human readable format
     */
    private function format_time($seconds) {
        if ($seconds < 60) {
            return round($seconds) . ' seconds';
        } elseif ($seconds < 3600) {
            return round($seconds / 60) . ' minutes';
        } else {
            $hours = floor($seconds / 3600);
            $minutes = round(($seconds % 3600) / 60);
            return $hours . ' hours' . ($minutes > 0 ? ', ' . $minutes . ' minutes' : '');
        }
    }
}
