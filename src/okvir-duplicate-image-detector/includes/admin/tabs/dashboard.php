<?php
/**
 * Dashboard Tab
 * 
 * Main overview dashboard showing statistics, progress, and quick actions.
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<h2>📊 Dashboard Overview</h2>

<!-- Quick Statistics -->
<div class="okvir-stats-grid">
    <div class="okvir-stat-card">
        <div class="okvir-stat-number" id="total-images"><?php echo number_format($processing_stats['queue']['unqueued'] + $stats['analyzed_images']); ?></div>
        <div class="okvir-stat-label">Total Images in Library</div>
    </div>
    
    <div class="okvir-stat-card">
        <div class="okvir-stat-number" id="processed-images"><?php echo number_format($stats['analyzed_images']); ?></div>
        <div class="okvir-stat-label">Images Analyzed</div>
    </div>
    
    <div class="okvir-stat-card">
        <div class="okvir-stat-number" id="duplicate-groups"><?php echo number_format($stats['duplicate_groups']); ?></div>
        <div class="okvir-stat-label">Duplicate Groups Found</div>
    </div>
    
    <div class="okvir-stat-card">
        <div class="okvir-stat-number"><?php echo isset($processing_stats['potential_savings']['total_size_formatted']) ? $processing_stats['potential_savings']['total_size_formatted'] : '0 B'; ?></div>
        <div class="okvir-stat-label">Potential Space Savings</div>
    </div>
</div>

<!-- Processing Status -->
<div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin: 20px 0;">
    <h3>🔄 Processing Status</h3>
    
    <div class="okvir-stats-grid">
        <div class="okvir-stat-card">
            <div class="okvir-stat-number" style="color: #46b450;" id="queue-completed"><?php echo number_format($queue_status['completed']); ?></div>
            <div class="okvir-stat-label">Completed</div>
        </div>
        
        <div class="okvir-stat-card">
            <div class="okvir-stat-number" style="color: #ffb900;" id="queue-pending"><?php echo number_format($queue_status['pending']); ?></div>
            <div class="okvir-stat-label">Pending</div>
        </div>
        
        <div class="okvir-stat-card">
            <div class="okvir-stat-number" style="color: #0073aa;" id="queue-processing"><?php echo number_format($queue_status['processing']); ?></div>
            <div class="okvir-stat-label">Processing</div>
        </div>
        
        <div class="okvir-stat-card">
            <div class="okvir-stat-number" style="color: #dc3232;" id="queue-failed"><?php echo number_format($queue_status['failed']); ?></div>
            <div class="okvir-stat-label">Failed</div>
        </div>
    </div>
    
    <?php if ($queue_status['pending'] > 0 || $queue_status['processing'] > 0): ?>
        <div class="okvir-progress-indicator" id="okvir-progress-indicator">
            <div class="okvir-spinner"></div>
            Processing images... Please wait.
        </div>
    <?php endif; ?>
</div>

<!-- Quick Actions -->
<div class="okvir-batch-controls">
    <h3>🚀 Quick Actions</h3>
    
    <div class="okvir-form-row">
        <label for="quick-batch-size">Batch Size:</label>
        <select id="quick-batch-size">
            <option value="10">10 images</option>
            <option value="20" selected>20 images</option>
            <option value="50">50 images</option>
            <option value="<?php echo OkvirDuplicateImageDetector::MAX_BATCH_SIZE; ?>"><?php echo OkvirDuplicateImageDetector::MAX_BATCH_SIZE; ?> images (max)</option>
        </select>
        
        <button type="button" class="button button-primary okvir-process-button" 
                onclick="okvirDupDetector.startBatchProcessing(document.getElementById('quick-batch-size').value, <?php echo json_encode($settings['image_types']); ?>)"
                data-original-text="Start Analysis">
            Start Analysis
        </button>
    </div>
    
    <div class="okvir-form-row">
        <label>Background Processing:</label>
        <span style="color: <?php echo $settings['enable_background_processing'] ? '#46b450' : '#dc3232'; ?>;">
            <?php echo $settings['enable_background_processing'] ? '✅ Enabled' : '❌ Disabled'; ?>
        </span>
        
        <?php if ($settings['enable_background_processing']): ?>
            <small>Images will be processed automatically in the background.</small>
        <?php else: ?>
            <small>Enable in Settings for automatic processing.</small>
        <?php endif; ?>
    </div>
</div>

<!-- Method Status -->
<div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin: 20px 0;">
    <h3>🧮 Detection Methods Status</h3>
    
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px;">
        <?php
        $methods = [
            OkvirDuplicateImageDetector::METHOD_FILE_HASH => [
                'name' => 'File Hash',
                'icon' => '🔐',
                'speed' => 'Very Fast',
                'description' => 'Exact byte-for-byte matching'
            ],
            OkvirDuplicateImageDetector::METHOD_PERCEPTUAL_HASH => [
                'name' => 'Perceptual Hash',
                'icon' => '🎭',
                'speed' => 'Fast',
                'description' => 'DCT-based similarity detection'
            ],
            OkvirDuplicateImageDetector::METHOD_COLOR_HISTOGRAM => [
                'name' => 'Color Histogram',
                'icon' => '🌈',
                'speed' => 'Medium',
                'description' => 'Color distribution analysis'
            ],
            OkvirDuplicateImageDetector::METHOD_TEMPLATE_MATCH => [
                'name' => 'Template Matching',
                'icon' => '🎯',
                'speed' => 'Slow',
                'description' => 'Feature-based template matching'
            ],
            OkvirDuplicateImageDetector::METHOD_KEYPOINT_MATCH => [
                'name' => 'Keypoint Matching',
                'icon' => '🔑',
                'speed' => 'Very Slow',
                'description' => 'SIFT-like keypoint detection'
            ]
        ];
        
        foreach ($methods as $method_key => $method_info):
            $enabled = !empty($settings['enabled_methods'][$method_key]);
            $usage_count = $stats['methods'][$method_key] ?? 0;
        ?>
            <div class="okvir-method-card <?php echo $enabled ? 'okvir-method-enabled' : 'okvir-method-disabled'; ?>">
                <h4><?php echo $method_info['icon']; ?> <?php echo $method_info['name']; ?></h4>
                <p><strong>Speed:</strong> <?php echo $method_info['speed']; ?></p>
                <p><?php echo $method_info['description']; ?></p>
                <p><strong>Status:</strong> <?php echo $enabled ? '✅ Enabled' : '❌ Disabled'; ?></p>
                <p><strong>Signatures Generated:</strong> <?php echo number_format($usage_count); ?></p>
                
                <?php if (isset($processing_stats['method_performance'][$method_key])): ?>
                    <p><strong>Success Rate:</strong> 
                        <?php 
                        $perf = $processing_stats['method_performance'][$method_key];
                        $total = $perf['success'] + $perf['failed'];
                        $rate = $total > 0 ? round(($perf['success'] / $total) * 100, 1) : 0;
                        echo $rate . '%';
                        ?>
                    </p>
                <?php endif; ?>
                
                <?php if (isset($processing_stats['processing_times'][$method_key])): ?>
                    <p><strong>Avg Time:</strong> <?php echo $processing_stats['processing_times'][$method_key]['average']; ?>s</p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Recent Activity -->
<?php if (!empty($processing_stats['potential_savings'])): ?>
<div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin: 20px 0;">
    <h3>💾 Potential Space Savings</h3>
    
    <div class="okvir-stats-grid">
        <div class="okvir-stat-card">
            <div class="okvir-stat-number" style="color: #dc3232;"><?php echo number_format($processing_stats['potential_savings']['duplicate_files']); ?></div>
            <div class="okvir-stat-label">Duplicate Files</div>
        </div>
        
        <div class="okvir-stat-card">
            <div class="okvir-stat-number" style="color: #ffb900;"><?php echo $processing_stats['potential_savings']['total_size_formatted']; ?></div>
            <div class="okvir-stat-label">Storage Space</div>
        </div>
        
        <div class="okvir-stat-card">
            <div class="okvir-stat-number" style="color: #46b450;"><?php echo $processing_stats['potential_savings']['average_confidence']; ?>%</div>
            <div class="okvir-stat-label">Average Confidence</div>
        </div>
        
        <div class="okvir-stat-card">
            <div class="okvir-stat-number" style="color: #0073aa;"><?php echo number_format($stats['duplicate_groups']); ?></div>
            <div class="okvir-stat-label">Groups to Review</div>
        </div>
    </div>
    
    <?php if ($stats['duplicate_groups'] > 0): ?>
        <div style="text-align: center; margin-top: 20px;">
            <a href="?page=okvir-duplicate-detector&tab=duplicates" class="button button-primary button-large">
                🗂️ Review Duplicates
            </a>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- System Health Check -->
<div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin: 20px 0;">
    <h3>🩺 System Health</h3>
    
    <div class="okvir-form-row">
        <label>Database Tables:</label>
        <span style="color: <?php echo $db_manager->verify_tables() ? '#46b450' : '#dc3232'; ?>;">
            <?php echo $db_manager->verify_tables() ? '✅ All tables exist' : '❌ Missing tables'; ?>
        </span>
    </div>
    
    <div class="okvir-form-row">
        <label>PHP GD Extension:</label>
        <span style="color: <?php echo extension_loaded('gd') ? '#46b450' : '#dc3232'; ?>;">
            <?php echo extension_loaded('gd') ? '✅ Available' : '❌ Missing'; ?>
        </span>
    </div>
    
    <div class="okvir-form-row">
        <label>PHP Imagick Extension:</label>
        <span style="color: <?php echo extension_loaded('imagick') ? '#46b450' : '#ffb900'; ?>;">
            <?php echo extension_loaded('imagick') ? '✅ Available' : '⚠️ Not available (optional)'; ?>
        </span>
    </div>
    
    <div class="okvir-form-row">
        <label>Memory Limit:</label>
        <span><?php echo ini_get('memory_limit'); ?></span>
    </div>
    
    <div class="okvir-form-row">
        <label>Max Execution Time:</label>
        <span><?php echo ini_get('max_execution_time'); ?>s</span>
    </div>
    
    <div class="okvir-form-row">
        <label>Upload Max Filesize:</label>
        <span><?php echo ini_get('upload_max_filesize'); ?></span>
    </div>
</div>

<!-- Quick Start Guide -->
<?php if ($stats['analyzed_images'] == 0): ?>
<div style="background: #f0f8ff; border: 1px solid #0073aa; border-radius: 4px; padding: 20px; margin: 20px 0;">
    <h3>🚀 Quick Start Guide</h3>
    
    <ol>
        <li><strong>Configure Methods:</strong> Go to <a href="?page=okvir-duplicate-detector&tab=settings">Settings</a> to enable detection methods</li>
        <li><strong>Start Analysis:</strong> Use the "Start Analysis" button above to process your first batch</li>
        <li><strong>Review Results:</strong> Check the <a href="?page=okvir-duplicate-detector&tab=duplicates">Duplicates</a> tab for found duplicates</li>
        <li><strong>Safe Deletion:</strong> Delete duplicates while preserving all references to original images</li>
    </ol>
    
    <div style="margin-top: 15px; padding: 10px; background: #e7f7ff; border-radius: 4px;">
        <strong>💡 Tip:</strong> Start with File Hash and Perceptual Hash methods enabled for quick and reliable duplicate detection. 
        Enable advanced methods (Template Matching, Keypoint Matching) only for comprehensive analysis of complex duplicates.
    </div>
</div>
<?php endif; ?>

<!-- Results Display Area -->
<div id="okvir-batch-results" style="display: none; margin: 20px 0;"></div>

<!-- Recent Processing Activity -->
<?php 
$recent_activity = $wpdb->get_results(
    "SELECT l.*, a.attachment_id, p.post_title
     FROM {$wpdb->prefix}okvir_processing_log l
     LEFT JOIN {$wpdb->prefix}" . OkvirDuplicateImageDetector::TABLE_IMAGE_ANALYSIS . " a ON l.attachment_id = a.attachment_id
     LEFT JOIN {$wpdb->posts} p ON l.attachment_id = p.ID
     WHERE l.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
     ORDER BY l.created_at DESC
     LIMIT 10",
    ARRAY_A
);
?>

<?php if (!empty($recent_activity)): ?>
<div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin: 20px 0;">
    <h3>📝 Recent Activity (Last 24 Hours)</h3>
    
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>Image</th>
                <th>Method</th>
                <th>Status</th>
                <th>Execution Time</th>
                <th>Timestamp</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recent_activity as $activity): ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html($activity['post_title'] ?: 'Unknown'); ?></strong><br>
                        <small>ID: <?php echo $activity['attachment_id']; ?></small>
                    </td>
                    <td>
                        <?php
                        $method_icons = [
                            'file_hash' => '🔐',
                            'perceptual_hash' => '🎭',
                            'color_histogram' => '🌈',
                            'template_match' => '🎯',
                            'keypoint_match' => '🔑'
                        ];
                        echo ($method_icons[$activity['method']] ?? '🔧') . ' ' . ucwords(str_replace('_', ' ', $activity['method']));
                        ?>
                    </td>
                    <td>
                        <?php if ($activity['status'] == 'success'): ?>
                            <span style="color: #46b450;">✅ Success</span>
                        <?php elseif ($activity['status'] == 'failed'): ?>
                            <span style="color: #dc3232;">❌ Failed</span>
                            <?php if ($activity['error_message']): ?>
                                <br><small><?php echo esc_html(substr($activity['error_message'], 0, 50)); ?>...</small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color: #ffb900;">⚠️ <?php echo ucfirst($activity['status']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo number_format($activity['execution_time'], 3); ?>s</td>
                    <td><?php echo date('M j, H:i', strtotime($activity['created_at'])); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- System Recommendations -->
<div style="background: #fff8e1; border: 1px solid #ffb900; border-radius: 4px; padding: 20px; margin: 20px 0;">
    <h3>💡 Recommendations</h3>
    
    <ul>
        <?php if (!$settings['enable_background_processing']): ?>
            <li><strong>Enable Background Processing:</strong> For large image libraries, enable background processing to analyze images automatically.</li>
        <?php endif; ?>
        
        <?php if (count(array_filter($settings['enabled_methods'])) < 2): ?>
            <li><strong>Enable Multiple Methods:</strong> Use at least 2 detection methods for reliable duplicate detection.</li>
        <?php endif; ?>
        
        <?php if ($settings['batch_size'] > 50): ?>
            <li><strong>Reduce Batch Size:</strong> Large batch sizes may cause memory issues. Consider using smaller batches.</li>
        <?php endif; ?>
        
        <?php if (ini_get('memory_limit') && (int)ini_get('memory_limit') < 256): ?>
            <li><strong>Increase Memory Limit:</strong> Image processing requires adequate memory. Consider increasing PHP memory limit to 256MB or higher.</li>
        <?php endif; ?>
        
        <?php if ($queue_status['failed'] > 0): ?>
            <li><strong>Review Failed Items:</strong> <a href="?page=okvir-duplicate-detector&tab=logs">Check processing logs</a> to understand why some images failed to process.</li>
        <?php endif; ?>
    </ul>
</div>
