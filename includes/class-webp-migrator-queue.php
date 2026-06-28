<?php
/**
 * WebP Migrator Queue Class
 * 
 * Handles background processing and job queue management
 */

if (!defined('ABSPATH')) exit;

class WebP_Migrator_Queue {
    
    const QUEUE_OPTION = 'webp_migrator_queue';
    const PROGRESS_OPTION = 'webp_migrator_progress';
    
    /** @var WebP_Migrator_Logger */
    private $logger;
    
    /** @var array Queue settings */
    private $settings;
    
    public function __construct($logger = null) {
        $this->logger = $logger ?: new WebP_Migrator_Logger();
        $this->settings = get_option('webp_safe_migrator_settings', []);
        
        // Hook into WordPress cron
        add_action('webp_migrator_process_queue', [$this, 'process_queue_batch']);
        
        // Register custom cron interval
        add_filter('cron_schedules', [$this, 'add_cron_intervals']);
    }
    
    /**
     * Add custom cron intervals
     */
    public function add_cron_intervals($schedules) {
        $schedules['webp_migrator_interval'] = [
            'interval' => 30, // 30 seconds
            'display' => __('Every 30 seconds (WebP Migrator)', 'webp-safe-migrator')
        ];
        return $schedules;
    }
    
    /**
     * Start background processing
     */
    public function start_background_processing($attachment_ids, $options = []) {
        $options = wp_parse_args($options, [
            'batch_size' => (int)($this->settings['batch_size'] ?? 10),
            'quality' => (int)($this->settings['quality'] ?? 59),
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
        if (!wp_next_scheduled('webp_migrator_process_queue')) {
            wp_schedule_event(time(), 'webp_migrator_interval', 'webp_migrator_process_queue');
        }
        
        $this->logger->info('Started background processing for ' . count($attachment_ids) . ' attachments');
        
        return true;
    }
    
    /**
     * Process a batch of items from the queue.
     *
     * Delegates each attachment to the main plugin's real, tested pipeline
     * (WebP_Safe_Migrator::process_attachment()), which performs conversion,
     * database reference rewriting and backup. This keeps the durable,
     * resumable option-based design: each call advances the current_index
     * cursor and continues where the previous call left off, so cron (or a
     * direct call) can resume an interrupted migration.
     */
    public function process_queue_batch() {
        $queue_data = get_option(self::QUEUE_OPTION);
        if (!$queue_data || $queue_data['status'] !== 'pending') {
            return false;
        }

        // Resolve the main plugin singleton; bail gracefully if it isn't loaded.
        $plugin = WebP_Safe_Migrator::instance();
        if (!$plugin) {
            $this->logger->error('Cannot process queue batch: main plugin instance unavailable.');
            return false;
        }

        $start_time = time();
        $max_execution_time = $queue_data['options']['max_execution_time'];
        $batch_size = $queue_data['options']['batch_size'];
        $quality = (int)($queue_data['options']['quality'] ?? 75);
        $validation_mode = (bool)($queue_data['options']['validation_mode'] ?? true);
        $processed_count = 0;

        $this->logger->info('Processing queue batch...');

        // Update status to processing
        $queue_data['status'] = 'processing';
        update_option(self::QUEUE_OPTION, $queue_data);

        // Process attachments
        while ($queue_data['current_index'] < $queue_data['total_count'] &&
               $processed_count < $batch_size &&
               (time() - $start_time) < $max_execution_time) {

            $att_id = (int)$queue_data['attachment_ids'][$queue_data['current_index']];

            try {
                $this->update_progress(
                    $queue_data['current_index'],
                    $queue_data['total_count'],
                    "Processing attachment #{$att_id}..."
                );

                // Delegate to the main plugin pipeline (conversion + DB rewrite + backup).
                $ok = $plugin->process_attachment($att_id, $quality, $validation_mode);

                if ($ok === true) {
                    $queue_data['processed'][] = [
                        'id' => $att_id,
                        'timestamp' => current_time('mysql'),
                    ];
                    $this->logger->info("Successfully processed attachment #{$att_id}");
                } else {
                    // process_attachment() records details under ERROR_META on failure.
                    $error_message = 'Conversion failed';
                    $error_raw = get_post_meta($att_id, WebP_Safe_Migrator::ERROR_META, true);
                    if ($error_raw) {
                        $decoded = json_decode($error_raw, true);
                        if (is_array($decoded) && !empty($decoded['error'])) {
                            $error_message = (string)$decoded['error'];
                        }
                    }
                    $queue_data['failed'][] = [
                        'id' => $att_id,
                        'error' => $error_message,
                        'timestamp' => current_time('mysql')
                    ];
                    $this->logger->error("Failed to convert attachment #{$att_id}: " . $error_message);
                }

            } catch (Throwable $e) {
                $queue_data['failed'][] = [
                    'id' => $att_id,
                    'error' => 'Exception: ' . $e->getMessage(),
                    'timestamp' => current_time('mysql')
                ];
                $this->logger->error("Exception processing attachment #{$att_id}: " . $e->getMessage());
            }

            $queue_data['current_index']++;
            $processed_count++;
        }
        
        // Check if queue is complete
        if ($queue_data['current_index'] >= $queue_data['total_count']) {
            $queue_data['status'] = 'completed';
            $queue_data['completed'] = current_time('mysql');
            
            // Clear the scheduled event
            wp_clear_scheduled_hook('webp_migrator_process_queue');
            
            $this->update_progress(
                $queue_data['total_count'], 
                $queue_data['total_count'], 
                'Background processing completed!'
            );
            
            $this->logger->info('Background processing completed. Processed: ' . count($queue_data['processed']) . ', Failed: ' . count($queue_data['failed']));
        } else {
            $queue_data['status'] = 'pending'; // Ready for next batch
        }
        
        update_option(self::QUEUE_OPTION, $queue_data);
        
        return true;
    }
    
    /**
     * Stop background processing
     */
    public function stop_background_processing() {
        wp_clear_scheduled_hook('webp_migrator_process_queue');
        
        $queue_data = get_option(self::QUEUE_OPTION);
        if ($queue_data) {
            $queue_data['status'] = 'stopped';
            $queue_data['stopped'] = current_time('mysql');
            update_option(self::QUEUE_OPTION, $queue_data);
        }
        
        $this->update_progress(0, 0, 'Background processing stopped by user.');
        $this->logger->info('Background processing stopped by user');
        
        return true;
    }
    
    /**
     * Get current queue status
     */
    public function get_queue_status() {
        return get_option(self::QUEUE_OPTION, []);
    }
    
    /**
     * Get current progress
     */
    public function get_progress() {
        return get_option(self::PROGRESS_OPTION, [
            'current' => 0,
            'total' => 0,
            'message' => 'No active processing',
            'percentage' => 0,
            'updated' => current_time('mysql')
        ]);
    }
    
    /**
     * Update progress tracking
     */
    private function update_progress($current, $total, $message = '') {
        $percentage = $total > 0 ? round(($current / $total) * 100, 1) : 0;
        
        $progress = [
            'current' => $current,
            'total' => $total,
            'message' => $message,
            'percentage' => $percentage,
            'updated' => current_time('mysql')
        ];
        
        update_option(self::PROGRESS_OPTION, $progress);
        
        // Trigger action for real-time updates
        do_action('webp_migrator_progress_updated', $progress);
    }
    
    /**
     * Clear queue and progress data
     */
    public function clear_queue() {
        delete_option(self::QUEUE_OPTION);
        delete_option(self::PROGRESS_OPTION);
        wp_clear_scheduled_hook('webp_migrator_process_queue');
        
        $this->logger->info('Queue cleared');
        
        return true;
    }
    
    /**
     * Check if background processing is active
     */
    public function is_processing() {
        $queue_data = get_option(self::QUEUE_OPTION);
        return $queue_data && in_array($queue_data['status'], ['pending', 'processing']);
    }
    
    /**
     * Get queue statistics
     */
    public function get_queue_stats() {
        $queue_data = get_option(self::QUEUE_OPTION, []);
        
        if (!$queue_data) {
            return null;
        }
        
        return [
            'total' => $queue_data['total_count'] ?? 0,
            'processed' => count($queue_data['processed'] ?? []),
            'failed' => count($queue_data['failed'] ?? []),
            'remaining' => max(0, ($queue_data['total_count'] ?? 0) - ($queue_data['current_index'] ?? 0)),
            'status' => $queue_data['status'] ?? 'unknown',
            'started' => $queue_data['started'] ?? null,
            'completed' => $queue_data['completed'] ?? null,
        ];
    }
}
