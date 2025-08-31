<?php
/**
 * Admin Page Template
 * 
 * Renders the main admin interface for the duplicate image detector.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get plugin instance and components
$plugin = OkvirDuplicateImageDetector::get_instance();
$settings = $plugin->get_settings();
$db_manager = new OkvirDupDetector_DatabaseManager();
$batch_processor = new OkvirDupDetector_BatchProcessor();
$duplicate_detector = new OkvirDupDetector_DuplicateDetector();

// Handle form submissions
if ($_POST) {
    if (isset($_POST['save_settings']) && wp_verify_nonce($_POST['okvir_nonce'], 'okvir_dup_settings')) {
        $new_settings = [
            'enabled_methods' => [
                OkvirDuplicateImageDetector::METHOD_FILE_HASH => !empty($_POST['method_file_hash']),
                OkvirDuplicateImageDetector::METHOD_PERCEPTUAL_HASH => !empty($_POST['method_perceptual_hash']),
                OkvirDuplicateImageDetector::METHOD_COLOR_HISTOGRAM => !empty($_POST['method_color_histogram']),
                OkvirDuplicateImageDetector::METHOD_TEMPLATE_MATCH => !empty($_POST['method_template_match']),
                OkvirDuplicateImageDetector::METHOD_KEYPOINT_MATCH => !empty($_POST['method_keypoint_match'])
            ],
            'batch_size' => min(intval($_POST['batch_size']), OkvirDuplicateImageDetector::MAX_BATCH_SIZE),
            'enable_background_processing' => !empty($_POST['enable_background_processing']),
            'similarity_threshold' => [
                OkvirDuplicateImageDetector::METHOD_PERCEPTUAL_HASH => intval($_POST['threshold_perceptual_hash']),
                OkvirDuplicateImageDetector::METHOD_COLOR_HISTOGRAM => intval($_POST['threshold_color_histogram']),
                OkvirDuplicateImageDetector::METHOD_TEMPLATE_MATCH => intval($_POST['threshold_template_match']),
                OkvirDuplicateImageDetector::METHOD_KEYPOINT_MATCH => intval($_POST['threshold_keypoint_match'])
            ],
            'image_types' => $_POST['image_types'] ?? $settings['image_types'],
            'min_file_size' => intval($_POST['min_file_size']),
            'max_file_size' => intval($_POST['max_file_size']),
            'auto_delete_confirmed_duplicates' => !empty($_POST['auto_delete_confirmed_duplicates']),
            'backup_before_delete' => !empty($_POST['backup_before_delete'])
        ];
        
        $plugin->update_settings($new_settings);
        $settings = $new_settings;
        
        echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
    }
}

// Get current statistics
$stats = $db_manager->get_statistics();
$processing_stats = $batch_processor->get_processing_statistics();
$queue_status = $batch_processor->get_queue_status();
$detection_summary = $duplicate_detector->get_detection_summary();

// Determine active tab
$active_tab = $_GET['tab'] ?? 'dashboard';
$valid_tabs = ['dashboard', 'analysis', 'duplicates', 'settings', 'logs'];
if (!in_array($active_tab, $valid_tabs)) {
    $active_tab = 'dashboard';
}
?>

<div class="wrap">
    <h1>🔍 Duplicate Image Detector</h1>
    <p>Advanced duplicate image detection using 5 different algorithms for comprehensive analysis.</p>
    
    <!-- Tab Navigation -->
    <h2 class="nav-tab-wrapper">
        <a href="?page=okvir-duplicate-detector&tab=dashboard" class="nav-tab <?php echo $active_tab == 'dashboard' ? 'nav-tab-active' : ''; ?>">📊 Dashboard</a>
        <a href="?page=okvir-duplicate-detector&tab=analysis" class="nav-tab <?php echo $active_tab == 'analysis' ? 'nav-tab-active' : ''; ?>">🔬 Image Analysis</a>
        <a href="?page=okvir-duplicate-detector&tab=duplicates" class="nav-tab <?php echo $active_tab == 'duplicates' ? 'nav-tab-active' : ''; ?>">🗂️ Manage Duplicates</a>
        <a href="?page=okvir-duplicate-detector&tab=settings" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">⚙️ Settings</a>
        <a href="?page=okvir-duplicate-detector&tab=logs" class="nav-tab <?php echo $active_tab == 'logs' ? 'nav-tab-active' : ''; ?>">📋 Processing Logs</a>
    </h2>
    
    <!-- Tab Content -->
    <div class="tab-content">
        <?php
        switch ($active_tab) {
            case 'dashboard':
                include 'tabs/dashboard.php';
                break;
            case 'analysis':
                include 'tabs/analysis.php';
                break;
            case 'duplicates':
                include 'tabs/duplicates.php';
                break;
            case 'settings':
                include 'tabs/settings.php';
                break;
            case 'logs':
                include 'tabs/logs.php';
                break;
            default:
                include 'tabs/dashboard.php';
                break;
        }
        ?>
    </div>
</div>

<!-- JavaScript for dynamic functionality -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Global duplicate detector object
    window.okvirDupDetector = {
        processing: false,
        
        // Start batch processing
        startBatchProcessing: function(batchSize, imageTypes) {
            if (this.processing) return;
            
            this.processing = true;
            this.updateProcessingUI(true);
            
            fetch(ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'okvir_dup_process_batch',
                    nonce: okvirDupDetector.nonce,
                    batch_size: batchSize,
                    image_types: JSON.stringify(imageTypes)
                })
            })
            .then(response => response.json())
            .then(data => {
                this.processing = false;
                this.updateProcessingUI(false);
                
                if (data.success) {
                    this.showBatchResults(data.data);
                    this.refreshStatus();
                } else {
                    alert('Processing failed: ' + (data.data || 'Unknown error'));
                }
            })
            .catch(error => {
                this.processing = false;
                this.updateProcessingUI(false);
                alert('Error: ' + error.message);
            });
        },
        
        // Update processing UI
        updateProcessingUI: function(isProcessing) {
            const buttons = document.querySelectorAll('.okvir-process-button');
            const progressIndicator = document.getElementById('okvir-progress-indicator');
            
            buttons.forEach(button => {
                button.disabled = isProcessing;
                button.textContent = isProcessing ? 'Processing...' : button.dataset.originalText || button.textContent;
            });
            
            if (progressIndicator) {
                progressIndicator.style.display = isProcessing ? 'block' : 'none';
            }
        },
        
        // Show batch processing results
        showBatchResults: function(results) {
            const resultsDiv = document.getElementById('okvir-batch-results');
            if (!resultsDiv) return;
            
            resultsDiv.innerHTML = `
                <div class="notice notice-success">
                    <p><strong>Batch Processing Complete!</strong></p>
                    <ul>
                        <li>Processed: ${results.processed_count} images</li>
                        <li>Successful: ${results.successful_count}</li>
                        <li>Failed: ${results.failed_count}</li>
                        <li>Duplicates Found: ${results.duplicates_found}</li>
                        <li>Processing Time: ${results.total_time.toFixed(2)} seconds</li>
                        <li>Memory Used: ${this.formatBytes(results.memory_used)}</li>
                    </ul>
                </div>
            `;
            
            resultsDiv.style.display = 'block';
        },
        
        // Refresh status information
        refreshStatus: function() {
            fetch(ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'okvir_dup_get_status',
                    nonce: okvirDupDetector.nonce
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.updateStatusDisplay(data.data);
                }
            })
            .catch(error => {
                console.error('Failed to refresh status:', error);
            });
        },
        
        // Update status display
        updateStatusDisplay: function(status) {
            const elements = {
                'total-images': status.total_images,
                'processed-images': status.processed_images,
                'duplicate-groups': status.duplicate_groups,
                'queue-remaining': status.queue_remaining
            };
            
            Object.keys(elements).forEach(id => {
                const element = document.getElementById(id);
                if (element) {
                    element.textContent = elements[id];
                }
            });
        },
        
        // Format bytes for display
        formatBytes: function(bytes) {
            if (bytes === 0) return '0 Bytes';
            
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        },
        
        // Delete selected duplicates
        deleteSelectedDuplicates: function() {
            const checkboxes = document.querySelectorAll('.duplicate-checkbox:checked');
            const duplicateIds = Array.from(checkboxes).map(cb => cb.value);
            
            if (duplicateIds.length === 0) {
                alert('Please select duplicates to delete');
                return;
            }
            
            if (!confirm(`Are you sure you want to delete ${duplicateIds.length} duplicate images? This action cannot be undone.`)) {
                return;
            }
            
            fetch(ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'okvir_dup_delete_duplicates',
                    nonce: okvirDupDetector.nonce,
                    duplicate_ids: JSON.stringify(duplicateIds)
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.showDeleteResults(data.data);
                    this.refreshStatus();
                    // Reload duplicates tab
                    location.reload();
                } else {
                    alert('Deletion failed: ' + (data.data || 'Unknown error'));
                }
            })
            .catch(error => {
                alert('Error: ' + error.message);
            });
        },
        
        // Show deletion results
        showDeleteResults: function(results) {
            const resultsDiv = document.getElementById('okvir-delete-results');
            if (!resultsDiv) return;
            
            resultsDiv.innerHTML = `
                <div class="notice notice-success">
                    <p><strong>Deletion Complete!</strong></p>
                    <ul>
                        <li>Deleted: ${results.deleted} images</li>
                        <li>References Updated: ${results.replacements.length}</li>
                        <li>Backups Created: ${results.backups_created}</li>
                        <li>Errors: ${results.errors.length}</li>
                    </ul>
                </div>
            `;
        }
    };
    
    // Auto-refresh status every 30 seconds
    setInterval(() => {
        if (!okvirDupDetector.processing) {
            okvirDupDetector.refreshStatus();
        }
    }, 30000);
});
</script>

<style>
.okvir-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin: 20px 0;
}

.okvir-stat-card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 15px;
    text-align: center;
}

.okvir-stat-number {
    font-size: 2em;
    font-weight: bold;
    color: #0073aa;
}

.okvir-stat-label {
    color: #666;
    font-size: 0.9em;
    margin-top: 5px;
}

.okvir-method-card {
    background: #f9f9f9;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 15px;
    margin: 10px 0;
}

.okvir-method-enabled {
    border-left: 4px solid #46b450;
}

.okvir-method-disabled {
    border-left: 4px solid #dc3232;
    opacity: 0.6;
}

.okvir-progress-indicator {
    display: none;
    text-align: center;
    padding: 20px;
}

.okvir-spinner {
    display: inline-block;
    width: 20px;
    height: 20px;
    border: 3px solid #f3f3f3;
    border-top: 3px solid #0073aa;
    border-radius: 50%;
    animation: okvir-spin 1s linear infinite;
    margin-right: 10px;
}

@keyframes okvir-spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.okvir-duplicate-group {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
    margin: 15px 0;
    overflow: hidden;
}

.okvir-group-header {
    background: #f1f1f1;
    padding: 15px;
    border-bottom: 1px solid #ddd;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.okvir-group-content {
    padding: 15px;
}

.okvir-image-preview {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
}

.okvir-image-item {
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 10px;
    text-align: center;
    min-width: 150px;
}

.okvir-image-item img {
    max-width: 100px;
    max-height: 100px;
    border-radius: 4px;
}

.okvir-original {
    border-color: #46b450;
    background: #f0fff0;
}

.okvir-duplicate {
    border-color: #dc3232;
    background: #fff0f0;
}

.okvir-confidence-high {
    color: #46b450;
    font-weight: bold;
}

.okvir-confidence-medium {
    color: #ffb900;
    font-weight: bold;
}

.okvir-confidence-low {
    color: #dc3232;
    font-weight: bold;
}

.okvir-batch-controls {
    background: #f0f8ff;
    border: 1px solid #0073aa;
    border-radius: 4px;
    padding: 20px;
    margin: 20px 0;
}

.okvir-form-row {
    display: flex;
    gap: 15px;
    align-items: center;
    margin: 10px 0;
}

.okvir-form-row label {
    min-width: 150px;
    font-weight: 600;
}
</style>
