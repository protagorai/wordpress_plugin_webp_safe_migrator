<?php
/**
 * Okvir Image Safe Migrator Queue Class
 * 
 * Handles background processing and job queue management
 */

if (!defined('ABSPATH')) exit;

class Okvir_Image_Migrator_Queue {
    
    const QUEUE_OPTION = 'okvir_image_migrator_queue';
    const PROGRESS_OPTION = 'okvir_image_migrator_progress';
    
    /** @var Okvir_Image_Migrator_Logger */
    private $logger;
    
    /** @var array Queue settings */
    private $settings;
    
    public function __construct($logger = null) {
        $this->logger = $logger ?: new Okvir_Image_Migrator_Logger();
        $this->settings = get_option('okvir_image_safe_migrator_settings', []);
        
        // Hook into WordPress cron
        add_action('okvir_image_migrator_process_queue', [$this, 'process_queue_batch']);
        
        // Register custom cron interval
        add_filter('cron_schedules', [$this, 'add_cron_intervals']);
    }
    
    /**
     * Add custom cron intervals
     */
    public function add_cron_intervals($schedules) {
        $schedules['okvir_image_migrator_interval'] = [
            'interval' => 30, // 30 seconds
            'display' => __('Every 30 seconds (Okvir Image Migrator)', 'okvir-image-safe-migrator')
        ];
        return $schedules;
    }
    
    /**
     * Start background processing
     */
    public function start_background_processing($attachment_ids, $options = []) {
        $options = wp_parse_args($options, [
            'batch_size' => (int)($this->settings['batch_size'] ?? 10),
            'quality' => (int)($this->settings['quality'] ?? 75),
            'target_format' => $this->settings['target_format'] ?? 'webp',
            'validation_mode' => (bool)($this->settings['validation'] ?? true),
            'max_execution_time' => 25, // seconds
        ]);
        
        // Store queue data
        $queue_data = [
            'attachment_ids' => array_values(array_unique($attachment_ids)),
            'options' => $options,
            'started' => current_time('mysql'),
            'status' => 'pending',
            'processed' => [],
            'failed' => [],
            'current_index' => 0,
            'total_count' => count($attachment_ids)
        ];
        
        update_option(self::QUEUE_OPTION, $queue_data);
        
        // Initialize progress tracking
        $this->update_progress(0, count($attachment_ids), 'Starting background processing...');
        
        // Schedule first batch
        if (!wp_next_scheduled('okvir_image_migrator_process_queue')) {
            wp_schedule_event(time(), 'okvir_image_migrator_interval', 'okvir_image_migrator_process_queue');
        }
        
        $this->logger->info('Started background processing for ' . count($attachment_ids) . ' attachments');
        
        return true;
    }
    
    /**
     * Process a batch of items from the queue
     */
    public function process_queue_batch() {
        $queue_data = get_option(self::QUEUE_OPTION);
        if (!$queue_data || $queue_data['status'] !== 'pending') {
            return false;
        }
        
        $start_time = time();
        $max_execution_time = $queue_data['options']['max_execution_time'];
        $batch_size = $queue_data['options']['batch_size'];
        $processed_count = 0;
        
        $this->logger->info('Processing queue batch...');
        
        // Update status to processing
        $queue_data['status'] = 'processing';
        update_option(self::QUEUE_OPTION, $queue_data);
        
        // Create converter
        $converter = new Okvir_Image_Migrator_Converter($queue_data['options'], $this->logger);
        
        // Process items within time and batch limits
        while ($processed_count < $batch_size && 
               $queue_data['current_index'] < count($queue_data['attachment_ids']) &&
               (time() - $start_time) < $max_execution_time) {
            
            $att_id = $queue_data['attachment_ids'][$queue_data['current_index']];
            
            $this->update_progress(
                count($queue_data['processed']) + count($queue_data['failed']),
                $queue_data['total_count'],
                "Processing attachment #{$att_id}..."
            );
            
            // Process single attachment
            $result = $converter->convert_attachment($att_id, $queue_data['options']);
            
            if (is_wp_error($result)) {
                $queue_data['failed'][] = [
                    'id' => $att_id,
                    'error' => $result->get_error_message(),
                    'timestamp' => current_time('mysql')
                ];
                $this->logger->error("Failed to process attachment #{$att_id}: " . $result->get_error_message());
            } else {
                $queue_data['processed'][] = [
                    'id' => $att_id,
                    'timestamp' => current_time('mysql'),
                    'result' => $result
                ];
                $this->logger->info("Successfully processed attachment #{$att_id}");
            }
            
            $queue_data['current_index']++;
            $processed_count++;
        }
        
        // Update queue data
        update_option(self::QUEUE_OPTION, $queue_data);
        
        // Check if we're done
        if ($queue_data['current_index'] >= count($queue_data['attachment_ids'])) {
            $this->complete_processing();
        } else {
            // Reset status for next batch
            $queue_data['status'] = 'pending';
            update_option(self::QUEUE_OPTION, $queue_data);
            
            $this->logger->info("Batch completed. Processed {$processed_count} attachments. " .
                              (count($queue_data['attachment_ids']) - $queue_data['current_index']) . " remaining.");
        }
        
        return true;
    }
    
    /**
     * Complete processing
     */
    private function complete_processing() {
        $queue_data = get_option(self::QUEUE_OPTION);
        
        if (!$queue_data) {
            return;
        }
        
        $queue_data['status'] = 'completed';
        $queue_data['completed'] = current_time('mysql');
        update_option(self::QUEUE_OPTION, $queue_data);
        
        // Clear scheduled event
        wp_clear_scheduled_hook('okvir_image_migrator_process_queue');
        
        $processed = count($queue_data['processed']);
        $failed = count($queue_data['failed']);
        
        $this->update_progress($processed + $failed, $queue_data['total_count'], 
                             "Processing completed! {$processed} converted, {$failed} failed.");
        
        $this->logger->info("Background processing completed. {$processed} attachments processed, {$failed} failed.");
        
        // Send notification email if configured
        $this->send_completion_notification($processed, $failed);
    }
    
    /**
     * Stop background processing
     */
    public function stop_processing() {
        $queue_data = get_option(self::QUEUE_OPTION);
        
        if ($queue_data) {
            $queue_data['status'] = 'stopped';
            $queue_data['stopped'] = current_time('mysql');
            update_option(self::QUEUE_OPTION, $queue_data);
        }
        
        wp_clear_scheduled_hook('okvir_image_migrator_process_queue');
        
        $this->logger->info('Background processing stopped by user');
        
        return true;
    }
    
    /**
     * Clear queue data
     */
    public function clear_queue() {
        delete_option(self::QUEUE_OPTION);
        delete_option(self::PROGRESS_OPTION);
        
        wp_clear_scheduled_hook('okvir_image_migrator_process_queue');
        
        $this->logger->info('Queue data cleared');
        
        return true;
    }
    
    /**
     * Update progress tracking
     */
    public function update_progress($current, $total, $message = '') {
        $percentage = $total > 0 ? round(($current / $total) * 100, 2) : 0;
        
        $progress_data = [
            'current' => $current,
            'total' => $total,
            'percentage' => $percentage,
            'message' => $message,
            'timestamp' => current_time('mysql')
        ];
        
        update_option(self::PROGRESS_OPTION, $progress_data);
        
        return $progress_data;
    }
    
    /**
     * Get current progress
     */
    public function get_progress() {
        return get_option(self::PROGRESS_OPTION, [
            'current' => 0,
            'total' => 0,
            'percentage' => 0,
            'message' => 'No processing in progress',
            'timestamp' => current_time('mysql')
        ]);
    }
    
    /**
     * Get queue status
     */
    public function get_queue_status() {
        $queue_data = get_option(self::QUEUE_OPTION);
        $progress_data = $this->get_progress();
        
        if (!$queue_data) {
            return [
                'is_processing' => false,
                'status' => 'idle',
                'progress' => $progress_data,
                'stats' => [
                    'total' => 0,
                    'processed' => 0,
                    'failed' => 0,
                    'remaining' => 0
                ]
            ];
        }
        
        $processed = count($queue_data['processed'] ?? []);
        $failed = count($queue_data['failed'] ?? []);
        $total = $queue_data['total_count'] ?? 0;
        
        return [
            'is_processing' => in_array($queue_data['status'], ['pending', 'processing']),
            'status' => $queue_data['status'],
            'started' => $queue_data['started'] ?? null,
            'completed' => $queue_data['completed'] ?? null,
            'progress' => $progress_data,
            'stats' => [
                'total' => $total,
                'processed' => $processed,
                'failed' => $failed,
                'remaining' => max(0, $total - $processed - $failed)
            ]
        ];
    }
    
    /**
     * Get processing history
     */
    public function get_processing_history($limit = 10) {
        $queue_data = get_option(self::QUEUE_OPTION);
        
        if (!$queue_data) {
            return [];
        }
        
        $history = [];
        
        // Add processed items
        foreach (($queue_data['processed'] ?? []) as $item) {
            $history[] = [
                'id' => $item['id'],
                'status' => 'success',
                'timestamp' => $item['timestamp'],
                'message' => 'Converted successfully'
            ];
        }
        
        // Add failed items
        foreach (($queue_data['failed'] ?? []) as $item) {
            $history[] = [
                'id' => $item['id'],
                'status' => 'error',
                'timestamp' => $item['timestamp'],
                'message' => $item['error']
            ];
        }
        
        // Sort by timestamp (newest first)
        usort($history, function($a, $b) {
            return strcmp($b['timestamp'], $a['timestamp']);
        });
        
        return array_slice($history, 0, $limit);
    }
    
    /**
     * Send completion notification
     */
    private function send_completion_notification($processed, $failed) {
        $settings = get_option('okvir_image_safe_migrator_settings', []);
        
        if (empty($settings['email_notifications'])) {
            return;
        }
        
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        
        $subject = sprintf('[%s] Image Conversion Completed', $site_name);
        
        $message = sprintf(
            "Background image conversion has completed on %s.\n\n" .
            "Results:\n" .
            "- Successfully converted: %d images\n" .
            "- Failed conversions: %d images\n" .
            "- Total processed: %d images\n\n" .
            "You can view detailed results in the WordPress admin at Media > Image Migrator.\n\n" .
            "This is an automated notification from the Okvir Image Safe Migrator plugin.",
            $site_name,
            $processed,
            $failed,
            $processed + $failed
        );
        
        wp_mail($admin_email, $subject, $message);
    }
    
    /**
     * Get queue performance metrics
     */
    public function get_performance_metrics() {
        $queue_data = get_option(self::QUEUE_OPTION);
        
        if (!$queue_data || empty($queue_data['started'])) {
            return null;
        }
        
        $start_time = strtotime($queue_data['started']);
        $end_time = isset($queue_data['completed']) ? 
                   strtotime($queue_data['completed']) : time();
        
        $duration = $end_time - $start_time;
        $processed = count($queue_data['processed'] ?? []);
        
        return [
            'duration' => $duration,
            'processed_count' => $processed,
            'avg_per_second' => $duration > 0 ? round($processed / $duration, 2) : 0,
            'avg_per_minute' => $duration > 0 ? round(($processed / $duration) * 60, 2) : 0
        ];
    }
}
