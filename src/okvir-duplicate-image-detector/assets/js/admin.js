/**
 * Okvir Duplicate Image Detector - Admin JavaScript
 * 
 * Handles all client-side interactions for the admin interface.
 */

(function($) {
    'use strict';
    
    // Global namespace
    window.OkvirDupDetector = window.OkvirDupDetector || {};
    
    const OkvirDupDetector = window.OkvirDupDetector;
    
    // Initialize when document is ready
    $(document).ready(function() {
        OkvirDupDetector.init();
    });
    
    // Main initialization
    OkvirDupDetector.init = function() {
        this.bindEvents();
        this.initAutoRefresh();
        this.initProgressTracking();
    };
    
    // Bind event handlers
    OkvirDupDetector.bindEvents = function() {
        // Batch processing
        $('.okvir-process-button').on('click', function(e) {
            e.preventDefault();
            
            const batchSize = $('#batch-size').val() || $('#quick-batch-size').val() || 20;
            const imageTypes = $('input[name="image_types[]"]:checked').map(function() {
                return this.value;
            }).get();
            
            OkvirDupDetector.startBatchProcessing(batchSize, imageTypes);
        });
        
        // Duplicate selection
        $('.duplicate-checkbox').on('change', function() {
            OkvirDupDetector.updateSelectionCount();
        });
        
        // Group checkboxes
        $('.group-checkbox').on('change', function() {
            const groupId = $(this).val();
            const checked = $(this).prop('checked');
            
            $(`.duplicate-checkbox[data-group-id="${groupId}"]`).prop('checked', checked);
            OkvirDupDetector.updateSelectionCount();
        });
        
        // Method configuration changes
        $('input[name^="method_"]').on('change', function() {
            const card = $(this).closest('.okvir-method-card');
            
            if ($(this).prop('checked')) {
                card.removeClass('okvir-method-disabled').addClass('okvir-method-enabled');
            } else {
                card.removeClass('okvir-method-enabled').addClass('okvir-method-disabled');
            }
            
            OkvirDupDetector.validateMethodSelection();
        });
        
        // Keyboard shortcuts
        $(document).on('keydown', function(e) {
            if (e.ctrlKey || e.metaKey) {
                switch (e.key) {
                    case 'a':
                        if ($('.duplicate-checkbox').length > 0) {
                            e.preventDefault();
                            OkvirDupDetector.selectAllDuplicates();
                        }
                        break;
                    case 'd':
                        if ($('.duplicate-checkbox:checked').length > 0) {
                            e.preventDefault();
                            OkvirDupDetector.deleteSelectedDuplicates();
                        }
                        break;
                }
            }
        });
    };
    
    // Auto-refresh functionality
    OkvirDupDetector.initAutoRefresh = function() {
        setInterval(() => {
            if (!this.processing && $('.nav-tab-active').text().includes('Dashboard')) {
                this.refreshStatus();
            }
        }, 30000); // Refresh every 30 seconds
    };
    
    // Progress tracking
    OkvirDupDetector.initProgressTracking = function() {
        this.processing = false;
        this.progressData = {
            processed: 0,
            successful: 0,
            failed: 0,
            duplicates: 0
        };
    };
    
    // Start batch processing
    OkvirDupDetector.startBatchProcessing = function(batchSize, imageTypes) {
        if (this.processing) {
            this.showNotification('Processing is already in progress', 'warning');
            return;
        }
        
        // Validate inputs
        batchSize = Math.min(parseInt(batchSize) || 20, okvirDupDetector.maxBatchSize);
        imageTypes = imageTypes || ['jpg', 'jpeg', 'png', 'webp'];
        
        if (imageTypes.length === 0) {
            this.showNotification('Please select at least one image type', 'error');
            return;
        }
        
        this.processing = true;
        this.updateProcessingUI(true);
        this.showProgressContainer(true);
        
        // Start processing
        $.ajax({
            url: okvirDupDetector.ajaxurl,
            type: 'POST',
            data: {
                action: 'okvir_dup_process_batch',
                nonce: okvirDupDetector.nonce,
                batch_size: batchSize,
                image_types: JSON.stringify(imageTypes)
            },
            success: (response) => {
                this.processing = false;
                this.updateProcessingUI(false);
                
                if (response.success) {
                    this.showBatchResults(response.data);
                    this.refreshStatus();
                } else {
                    this.showNotification('Processing failed: ' + (response.data || 'Unknown error'), 'error');
                }
            },
            error: (xhr, status, error) => {
                this.processing = false;
                this.updateProcessingUI(false);
                this.showNotification('AJAX error: ' + error, 'error');
            }
        });
    };
    
    // Update processing UI elements
    OkvirDupDetector.updateProcessingUI = function(isProcessing) {
        $('.okvir-process-button').each(function() {
            const $btn = $(this);
            
            if (isProcessing) {
                $btn.prop('disabled', true);
                if (!$btn.data('original-text')) {
                    $btn.data('original-text', $btn.text());
                }
                $btn.html('<span class="okvir-spinner"></span> Processing...');
            } else {
                $btn.prop('disabled', false);
                $btn.text($btn.data('original-text') || 'Start Analysis');
            }
        });
        
        $('#okvir-progress-indicator').toggle(isProcessing);
    };
    
    // Show/hide progress container
    OkvirDupDetector.showProgressContainer = function(show) {
        const $container = $('#okvir-progress-container');
        
        if (show) {
            $container.show().addClass('okvir-fade-in');
            this.updateProgressBar(0);
            this.updateProgressText('Starting batch processing...');
        } else {
            $container.hide().removeClass('okvir-fade-in');
        }
    };
    
    // Update progress bar
    OkvirDupDetector.updateProgressBar = function(percentage) {
        $('#okvir-progress-bar').css('width', percentage + '%');
    };
    
    // Update progress text
    OkvirDupDetector.updateProgressText = function(text) {
        $('#okvir-progress-text').text(text);
    };
    
    // Update progress statistics
    OkvirDupDetector.updateProgressStats = function(stats) {
        $('#progress-processed').text(stats.processed || 0);
        $('#progress-successful').text(stats.successful || 0);
        $('#progress-failed').text(stats.failed || 0);
        $('#progress-duplicates').text(stats.duplicates || 0);
    };
    
    // Show batch processing results
    OkvirDupDetector.showBatchResults = function(results) {
        const $resultsDiv = $('#okvir-batch-results');
        
        let statusClass = 'success';
        if (results.failed_count > 0) {
            statusClass = results.failed_count > results.successful_count ? 'error' : 'warning';
        }
        
        const html = `
            <div class="okvir-notification ${statusClass}">
                <h4>Batch Processing Complete!</h4>
                <div class="okvir-stats-grid" style="margin-top: 10px;">
                    <div>
                        <strong>Processed:</strong> ${results.processed_count} images
                    </div>
                    <div>
                        <strong>Successful:</strong> ${results.successful_count}
                    </div>
                    <div>
                        <strong>Failed:</strong> ${results.failed_count}
                    </div>
                    <div>
                        <strong>Duplicates Found:</strong> ${results.duplicates_found}
                    </div>
                    <div>
                        <strong>Processing Time:</strong> ${results.total_time.toFixed(2)}s
                    </div>
                    <div>
                        <strong>Memory Used:</strong> ${this.formatBytes(results.memory_used)}
                    </div>
                </div>
                
                ${results.errors.length > 0 ? `
                    <details style="margin-top: 15px;">
                        <summary style="cursor: pointer; color: #dc3232;">
                            <strong>Errors (${results.errors.length})</strong>
                        </summary>
                        <ul style="margin: 10px 0;">
                            ${results.errors.map(error => 
                                `<li><strong>${error.file_name}:</strong> ${error.error}</li>`
                            ).join('')}
                        </ul>
                    </details>
                ` : ''}
            </div>
        `;
        
        $resultsDiv.html(html).show().addClass('okvir-fade-in');
        
        // Auto-hide after 10 seconds
        setTimeout(() => {
            $resultsDiv.fadeOut();
        }, 10000);
    };
    
    // Refresh status information
    OkvirDupDetector.refreshStatus = function() {
        $.ajax({
            url: okvirDupDetector.ajaxurl,
            type: 'POST',
            data: {
                action: 'okvir_dup_get_status',
                nonce: okvirDupDetector.nonce
            },
            success: (response) => {
                if (response.success) {
                    this.updateStatusDisplay(response.data);
                }
            },
            error: (xhr, status, error) => {
                console.error('Failed to refresh status:', error);
            }
        });
    };
    
    // Update status display elements
    OkvirDupDetector.updateStatusDisplay = function(status) {
        const elements = {
            'total-images': status.total_images,
            'processed-images': status.processed_images,
            'duplicate-groups': status.duplicate_groups,
            'queue-remaining': status.queue_remaining,
            'queue-pending': status.queue_pending,
            'queue-processing': status.queue_processing,
            'queue-completed': status.queue_completed,
            'queue-failed': status.queue_failed
        };
        
        Object.keys(elements).forEach(id => {
            const $element = $('#' + id);
            if ($element.length) {
                $element.text(this.formatNumber(elements[id]));
            }
        });
    };
    
    // Delete selected duplicates
    OkvirDupDetector.deleteSelectedDuplicates = function() {
        const $selected = $('.duplicate-checkbox:checked');
        const duplicateIds = $selected.map(function() { return this.value; }).get();
        
        if (duplicateIds.length === 0) {
            this.showNotification('Please select duplicates to delete', 'warning');
            return;
        }
        
        const confirmMsg = `Are you sure you want to delete ${duplicateIds.length} duplicate images?\n\n` +
                          `This will:\n` +
                          `- Delete the duplicate files\n` +
                          `- Replace all references with original images\n` +
                          `- Create backups if enabled\n\n` +
                          `This action cannot be undone.`;
        
        if (!confirm(confirmMsg)) {
            return;
        }
        
        // Show loading state
        $selected.closest('.okvir-image-item').addClass('okvir-loading');
        
        $.ajax({
            url: okvirDupDetector.ajaxurl,
            type: 'POST',
            data: {
                action: 'okvir_dup_delete_duplicates',
                nonce: okvirDupDetector.nonce,
                duplicate_ids: JSON.stringify(duplicateIds)
            },
            success: (response) => {
                if (response.success) {
                    this.showDeleteResults(response.data);
                    this.refreshStatus();
                    
                    // Remove deleted items from UI
                    $selected.closest('.okvir-image-item').fadeOut();
                    
                    // Reload page after short delay
                    setTimeout(() => {
                        location.reload();
                    }, 3000);
                } else {
                    this.showNotification('Deletion failed: ' + (response.data || 'Unknown error'), 'error');
                    $selected.closest('.okvir-image-item').removeClass('okvir-loading');
                }
            },
            error: (xhr, status, error) => {
                this.showNotification('AJAX error: ' + error, 'error');
                $selected.closest('.okvir-image-item').removeClass('okvir-loading');
            }
        });
    };
    
    // Show deletion results
    OkvirDupDetector.showDeleteResults = function(results) {
        const statusClass = results.errors.length === 0 ? 'success' : 'warning';
        
        const html = `
            <div class="okvir-notification ${statusClass}">
                <h4>🗑️ Deletion Complete!</h4>
                <div class="okvir-stats-grid" style="margin-top: 10px;">
                    <div><strong>Deleted:</strong> ${results.deleted} images</div>
                    <div><strong>References Updated:</strong> ${results.replacements.length}</div>
                    <div><strong>Backups Created:</strong> ${results.backups_created}</div>
                    <div><strong>Errors:</strong> ${results.errors.length}</div>
                </div>
                
                ${results.errors.length > 0 ? `
                    <details style="margin-top: 15px;">
                        <summary style="cursor: pointer; color: #dc3232;">
                            <strong>Deletion Errors (${results.errors.length})</strong>
                        </summary>
                        <ul style="margin: 10px 0;">
                            ${results.errors.map(error => 
                                `<li><strong>ID ${error.attachment_id}:</strong> ${error.error}</li>`
                            ).join('')}
                        </ul>
                    </details>
                ` : ''}
            </div>
        `;
        
        $('#okvir-delete-results').html(html).show().addClass('okvir-fade-in');
    };
    
    // Selection management
    OkvirDupDetector.selectAllDuplicates = function() {
        $('.duplicate-checkbox').prop('checked', true);
        this.updateSelectionCount();
    };
    
    OkvirDupDetector.selectNone = function() {
        $('.duplicate-checkbox, .group-checkbox').prop('checked', false);
        this.updateSelectionCount();
    };
    
    OkvirDupDetector.selectHighConfidence = function() {
        $('.okvir-confidence-high').each(function() {
            const $group = $(this).closest('.okvir-duplicate-group');
            $group.find('.duplicate-checkbox').prop('checked', true);
        });
        this.updateSelectionCount();
    };
    
    OkvirDupDetector.selectGroupDuplicates = function(groupId) {
        $(`.duplicate-checkbox[data-group-id="${groupId}"]`).prop('checked', true);
        this.updateSelectionCount();
    };
    
    // Update selection count display
    OkvirDupDetector.updateSelectionCount = function() {
        const count = $('.duplicate-checkbox:checked').length;
        
        // Update any selection count displays
        $('.selection-count').text(count);
        
        // Enable/disable bulk action buttons
        $('.bulk-action-button').prop('disabled', count === 0);
    };
    
    // Group management
    OkvirDupDetector.toggleGroup = function(groupId) {
        const $content = $(`#group-content-${groupId}`);
        
        if ($content.is(':visible')) {
            $content.slideUp();
        } else {
            $content.slideDown().addClass('okvir-slide-down');
        }
    };
    
    OkvirDupDetector.deleteGroupDuplicates = function(groupId) {
        const $checkboxes = $(`.duplicate-checkbox[data-group-id="${groupId}"]`);
        const count = $checkboxes.length;
        
        if (count === 0) {
            this.showNotification('No duplicates found in this group', 'warning');
            return;
        }
        
        if (!confirm(`Delete all ${count} duplicates in this group?`)) {
            return;
        }
        
        // Select all checkboxes in the group
        $checkboxes.prop('checked', true);
        this.updateSelectionCount();
        
        // Trigger deletion
        this.deleteSelectedDuplicates();
    };
    
    // Notification system
    OkvirDupDetector.showNotification = function(message, type = 'info', duration = 5000) {
        const $notification = $(`
            <div class="okvir-notification ${type}">
                <p>${message}</p>
            </div>
        `);
        
        // Add to page
        $('.wrap h1').after($notification);
        $notification.addClass('okvir-fade-in');
        
        // Auto-remove
        setTimeout(() => {
            $notification.fadeOut(() => {
                $notification.remove();
            });
        }, duration);
    };
    
    // Settings management
    OkvirDupDetector.applyConfig = function(configType) {
        const configs = {
            speed: {
                methods: ['file_hash', 'perceptual_hash'],
                batch_size: 50,
                thresholds: {
                    perceptual_hash: 95,
                    color_histogram: 85,
                    template_match: 90,
                    keypoint_match: 80
                }
            },
            balanced: {
                methods: ['file_hash', 'perceptual_hash', 'color_histogram'],
                batch_size: 20,
                thresholds: {
                    perceptual_hash: 90,
                    color_histogram: 80,
                    template_match: 85,
                    keypoint_match: 75
                }
            },
            accuracy: {
                methods: ['file_hash', 'perceptual_hash', 'color_histogram', 'template_match', 'keypoint_match'],
                batch_size: 5,
                thresholds: {
                    perceptual_hash: 85,
                    color_histogram: 75,
                    template_match: 80,
                    keypoint_match: 70
                }
            }
        };
        
        const config = configs[configType];
        if (!config) return;
        
        // Update method checkboxes
        $('input[name^="method_"]').each(function() {
            const methodName = this.name.replace('method_', '');
            $(this).prop('checked', config.methods.includes(methodName)).trigger('change');
        });
        
        // Update batch size
        $('input[name="batch_size"]').val(config.batch_size);
        
        // Update thresholds
        Object.keys(config.thresholds).forEach(method => {
            $(`input[name="threshold_${method}"]`).val(config.thresholds[method]);
        });
        
        this.showNotification(`Applied ${configType} configuration. Don't forget to save settings!`, 'success');
    };
    
    // Validate method selection
    OkvirDupDetector.validateMethodSelection = function() {
        const enabledCount = $('input[name^="method_"]:checked').length;
        const $warning = $('#method-selection-warning');
        
        if (enabledCount < 2) {
            if ($warning.length === 0) {
                $('input[name^="method_"]').first().closest('.okvir-method-card').before(`
                    <div id="method-selection-warning" class="notice notice-warning inline">
                        <p><strong>⚠️ Warning:</strong> At least 2 methods should be enabled for reliable duplicate detection.</p>
                    </div>
                `);
            }
        } else {
            $warning.remove();
        }
    };
    
    // Utility functions
    OkvirDupDetector.formatBytes = function(bytes) {
        if (bytes === 0) return '0 Bytes';
        
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    };
    
    OkvirDupDetector.formatNumber = function(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    };
    
    OkvirDupDetector.formatTime = function(seconds) {
        if (seconds < 60) {
            return Math.round(seconds) + ' seconds';
        } else if (seconds < 3600) {
            return Math.round(seconds / 60) + ' minutes';
        } else {
            const hours = Math.floor(seconds / 3600);
            const minutes = Math.round((seconds % 3600) / 60);
            return hours + ' hours' + (minutes > 0 ? ', ' + minutes + ' minutes' : '');
        }
    };
    
    // Queue management
    OkvirDupDetector.addAllToQueue = function() {
        if (!confirm('Add all unprocessed images to the processing queue?')) {
            return;
        }
        
        $.ajax({
            url: okvirDupDetector.ajaxurl,
            type: 'POST',
            data: {
                action: 'okvir_dup_add_to_queue',
                nonce: okvirDupDetector.nonce
            },
            success: (response) => {
                if (response.success) {
                    this.showNotification(`Added ${response.data.added} images to queue`, 'success');
                    this.refreshStatus();
                } else {
                    this.showNotification('Failed to add to queue: ' + response.data, 'error');
                }
            }
        });
    };
    
    OkvirDupDetector.clearCompleted = function() {
        if (!confirm('Clear all completed queue items?')) {
            return;
        }
        
        $.ajax({
            url: okvirDupDetector.ajaxurl,
            type: 'POST',
            data: {
                action: 'okvir_dup_clear_completed',
                nonce: okvirDupDetector.nonce
            },
            success: (response) => {
                if (response.success) {
                    this.showNotification(`Cleared ${response.data.cleared} completed items`, 'success');
                    this.refreshStatus();
                } else {
                    this.showNotification('Failed to clear completed items', 'error');
                }
            }
        });
    };
    
    OkvirDupDetector.retryFailed = function() {
        if (!confirm('Retry all failed queue items?')) {
            return;
        }
        
        $.ajax({
            url: okvirDupDetector.ajaxurl,
            type: 'POST',
            data: {
                action: 'okvir_dup_retry_failed',
                nonce: okvirDupDetector.nonce
            },
            success: (response) => {
                if (response.success) {
                    this.showNotification(`Reset ${response.data.reset} failed items for retry`, 'success');
                    this.refreshStatus();
                } else {
                    this.showNotification('Failed to retry items', 'error');
                }
            }
        });
    };
    
    // Export functionality
    OkvirDupDetector.exportData = function(type) {
        const currentUrl = new URL(window.location);
        const params = new URLSearchParams(currentUrl.search);
        params.set('action', `okvir_dup_export_${type}`);
        params.set('nonce', okvirDupDetector.nonce);
        
        const exportUrl = okvirDupDetector.ajaxurl + '?' + params.toString();
        window.open(exportUrl, '_blank');
    };
    
    // Help system
    OkvirDupDetector.showHelp = function(topic) {
        const helpContent = {
            'detection-methods': `
                <h3>🧮 Detection Methods Explained</h3>
                <p><strong>File Hash:</strong> Compares exact file content. Perfect for identical files.</p>
                <p><strong>Perceptual Hash:</strong> Analyzes image structure. Good for compressed/format changes.</p>
                <p><strong>Color Histogram:</strong> Compares color distribution. Handles rotation and lighting.</p>
                <p><strong>Template Matching:</strong> Feature-based comparison. Can find cropped portions.</p>
                <p><strong>Keypoint Matching:</strong> Advanced SIFT-like analysis. Handles all transformations.</p>
            `,
            'confidence-scores': `
                <h3>🎯 Understanding Confidence Scores</h3>
                <p><strong>95-100%:</strong> Very high confidence - likely true duplicates</p>
                <p><strong>85-94%:</strong> High confidence - review recommended</p>
                <p><strong>70-84%:</strong> Medium confidence - manual verification needed</p>
                <p><strong>Below 70%:</strong> Low confidence - likely false positives</p>
            `,
            'safety-features': `
                <h3>🛡️ Safety Features</h3>
                <p><strong>Multiple Method Verification:</strong> At least 2 methods must agree</p>
                <p><strong>Reference Replacement:</strong> All content references updated before deletion</p>
                <p><strong>Backup Creation:</strong> Original files backed up before deletion</p>
                <p><strong>Rollback Capability:</strong> Failed deletions are automatically rolled back</p>
            `
        };
        
        const content = helpContent[topic] || '<p>Help topic not found.</p>';
        
        const modal = $(`
            <div class="okvir-modal">
                <div class="okvir-modal-content">
                    ${content}
                    <div style="text-align: right; margin-top: 20px;">
                        <button type="button" class="button button-primary" onclick="$(this).closest('.okvir-modal').remove()">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        `);
        
        $('body').append(modal);
        modal.addClass('okvir-fade-in');
    };
    
})(jQuery);

// Global functions for inline onclick handlers
function okvirStartBatch(batchSize, imageTypes) {
    OkvirDupDetector.startBatchProcessing(batchSize, imageTypes);
}

function okvirToggleGroup(groupId) {
    OkvirDupDetector.toggleGroup(groupId);
}

function okvirDeleteSelected() {
    OkvirDupDetector.deleteSelectedDuplicates();
}

function okvirSelectAll() {
    OkvirDupDetector.selectAllDuplicates();
}

function okvirSelectNone() {
    OkvirDupDetector.selectNone();
}

function okvirSelectHighConfidence() {
    OkvirDupDetector.selectHighConfidence();
}

function okvirApplyConfig(configType) {
    OkvirDupDetector.applyConfig(configType);
}

function okvirShowHelp(topic) {
    OkvirDupDetector.showHelp(topic);
}
