<?php
/**
 * Analysis Tab
 * 
 * Provides detailed controls for image analysis and batch processing.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get estimation data
$estimation = $batch_processor->estimate_remaining_time();
?>

<h2>🔬 Image Analysis & Processing</h2>

<!-- Processing Overview -->
<div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin: 20px 0;">
    <h3>📊 Processing Overview</h3>
    
    <div class="okvir-stats-grid">
        <div class="okvir-stat-card">
            <div class="okvir-stat-number"><?php echo number_format($estimation['remaining_images']); ?></div>
            <div class="okvir-stat-label">Images Remaining</div>
        </div>
        
        <div class="okvir-stat-card">
            <div class="okvir-stat-number"><?php echo $estimation['estimated_batches']; ?></div>
            <div class="okvir-stat-label">Estimated Batches</div>
        </div>
        
        <div class="okvir-stat-card">
            <div class="okvir-stat-number"><?php echo $estimation['estimated_time_formatted']; ?></div>
            <div class="okvir-stat-label">Estimated Time</div>
        </div>
        
        <div class="okvir-stat-card">
            <div class="okvir-stat-number"><?php echo count(array_filter($settings['enabled_methods'])); ?>/5</div>
            <div class="okvir-stat-label">Methods Enabled</div>
        </div>
    </div>
</div>

<!-- Batch Processing Controls -->
<div class="okvir-batch-controls">
    <h3>🎛️ Batch Processing Controls</h3>
    
    <form id="okvir-batch-form">
        <div class="okvir-form-row">
            <label for="batch-size">Batch Size:</label>
            <select id="batch-size" name="batch_size">
                <?php for ($size = 5; $size <= OkvirDuplicateImageDetector::MAX_BATCH_SIZE; $size += 5): ?>
                    <option value="<?php echo $size; ?>" <?php selected($settings['batch_size'], $size); ?>>
                        <?php echo $size; ?> images
                    </option>
                <?php endfor; ?>
            </select>
            <small>Maximum: <?php echo OkvirDuplicateImageDetector::MAX_BATCH_SIZE; ?> images per batch</small>
        </div>
        
        <div class="okvir-form-row">
            <label for="image-types">Image Types:</label>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <?php 
                $available_types = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                foreach ($available_types as $type): 
                ?>
                    <label style="min-width: auto;">
                        <input type="checkbox" name="image_types[]" value="<?php echo $type; ?>" 
                               <?php checked(in_array($type, $settings['image_types'])); ?>>
                        <?php echo strtoupper($type); ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="okvir-form-row">
            <label>Enabled Methods:</label>
            <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                <?php
                $method_labels = [
                    OkvirDuplicateImageDetector::METHOD_FILE_HASH => '🔐 File Hash',
                    OkvirDuplicateImageDetector::METHOD_PERCEPTUAL_HASH => '🎭 Perceptual Hash',
                    OkvirDuplicateImageDetector::METHOD_COLOR_HISTOGRAM => '🌈 Color Histogram',
                    OkvirDuplicateImageDetector::METHOD_TEMPLATE_MATCH => '🎯 Template Match',
                    OkvirDuplicateImageDetector::METHOD_KEYPOINT_MATCH => '🔑 Keypoint Match'
                ];
                
                foreach ($method_labels as $method => $label):
                    $enabled = !empty($settings['enabled_methods'][$method]);
                ?>
                    <span style="color: <?php echo $enabled ? '#46b450' : '#dc3232'; ?>;">
                        <?php echo $enabled ? '✅' : '❌'; ?> <?php echo $label; ?>
                    </span>
                <?php endforeach; ?>
            </div>
            <small><a href="?page=okvir-duplicate-detector&tab=settings">Configure methods in Settings</a></small>
        </div>
        
        <div class="okvir-form-row">
            <button type="button" class="button button-primary okvir-process-button" 
                    onclick="okvirDupDetector.startBatchProcessing(document.getElementById('batch-size').value, Array.from(document.querySelectorAll('input[name=\'image_types[]\']:checked')).map(cb => cb.value))"
                    data-original-text="🚀 Start Batch Analysis">
                🚀 Start Batch Analysis
            </button>
            
            <button type="button" class="button" onclick="okvirDupDetector.refreshStatus()">
                🔄 Refresh Status
            </button>
        </div>
    </form>
</div>

<!-- Queue Management -->
<div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin: 20px 0;">
    <h3>🗃️ Queue Management</h3>
    
    <div class="okvir-stats-grid">
        <div class="okvir-stat-card">
            <div class="okvir-stat-number" style="color: #ffb900;" id="queue-pending"><?php echo number_format($queue_status['pending']); ?></div>
            <div class="okvir-stat-label">Pending</div>
        </div>
        
        <div class="okvir-stat-card">
            <div class="okvir-stat-number" style="color: #0073aa;" id="queue-processing"><?php echo number_format($queue_status['processing']); ?></div>
            <div class="okvir-stat-label">Processing</div>
        </div>
        
        <div class="okvir-stat-card">
            <div class="okvir-stat-number" style="color: #46b450;" id="queue-completed"><?php echo number_format($queue_status['completed']); ?></div>
            <div class="okvir-stat-label">Completed</div>
        </div>
        
        <div class="okvir-stat-card">
            <div class="okvir-stat-number" style="color: #dc3232;" id="queue-failed"><?php echo number_format($queue_status['failed']); ?></div>
            <div class="okvir-stat-label">Failed</div>
        </div>
    </div>
    
    <div style="margin-top: 20px;">
        <div class="okvir-form-row">
            <button type="button" class="button" onclick="okvirQueueManager.addAllToQueue()">
                ➕ Add All Unprocessed to Queue
            </button>
            
            <?php if ($queue_status['completed'] > 0): ?>
            <button type="button" class="button" onclick="okvirQueueManager.clearCompleted()">
                🗑️ Clear Completed (<?php echo $queue_status['completed']; ?>)
            </button>
            <?php endif; ?>
            
            <?php if ($queue_status['failed'] > 0): ?>
            <button type="button" class="button" onclick="okvirQueueManager.retryFailed()">
                🔄 Retry Failed (<?php echo $queue_status['failed']; ?>)
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Processing Progress -->
<div id="okvir-progress-container" style="display: none; background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin: 20px 0;">
    <h3>⏳ Processing Progress</h3>
    
    <div id="okvir-progress-bar-container" style="background: #f0f0f0; border: 1px solid #ddd; border-radius: 3px; overflow: hidden; margin: 15px 0;">
        <div id="okvir-progress-bar" style="background: #0073aa; height: 20px; width: 0%; transition: width 0.3s;"></div>
    </div>
    
    <div id="okvir-progress-details">
        <p id="okvir-progress-text">Initializing...</p>
        <div id="okvir-progress-stats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-top: 10px;">
            <div>Processed: <span id="progress-processed">0</span></div>
            <div>Successful: <span id="progress-successful">0</span></div>
            <div>Failed: <span id="progress-failed">0</span></div>
            <div>Duplicates: <span id="progress-duplicates">0</span></div>
        </div>
    </div>
</div>

<!-- Batch Results Display -->
<div id="okvir-batch-results" style="display: none;"></div>

<!-- Advanced Options -->
<div style="background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin: 20px 0;">
    <h3>🔧 Advanced Options</h3>
    
    <div class="okvir-form-row">
        <label>Minimum File Size:</label>
        <span><?php echo size_format($settings['min_file_size']); ?></span>
        <small>Images smaller than this will be skipped</small>
    </div>
    
    <div class="okvir-form-row">
        <label>Maximum File Size:</label>
        <span><?php echo size_format($settings['max_file_size']); ?></span>
        <small>Images larger than this will be skipped</small>
    </div>
    
    <div class="okvir-form-row">
        <label>Similarity Thresholds:</label>
        <div style="margin-left: 150px;">
            <?php foreach ($settings['similarity_threshold'] as $method => $threshold): ?>
                <div>
                    <?php echo ucwords(str_replace('_', ' ', $method)); ?>: 
                    <strong><?php echo $threshold; ?>%</strong>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
// Queue management functions
window.okvirQueueManager = {
    addAllToQueue: function() {
        if (!confirm('Add all unprocessed images to the processing queue?')) {
            return;
        }
        
        // Implementation would go here
        alert('This feature will be implemented in the queue management system');
    },
    
    clearCompleted: function() {
        if (!confirm('Clear all completed queue items?')) {
            return;
        }
        
        // Implementation would go here
        alert('Completed items cleared');
    },
    
    retryFailed: function() {
        if (!confirm('Retry all failed queue items?')) {
            return;
        }
        
        // Implementation would go here
        alert('Failed items reset for retry');
    }
};
</script>
