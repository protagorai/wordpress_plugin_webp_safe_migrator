/**
 * Okvir Image Safe Migrator Admin JavaScript
 * Enhanced UI with real-time progress tracking and better user experience
 */

(function($) {
    'use strict';
    
    const OkvirImageMigrator = {
        
        // Configuration
        config: {
            ajaxUrl: ajaxurl,
            nonce: okvirImageMigratorAdmin.nonce,
            refreshInterval: 2000, // 2 seconds
            maxRetries: 3
        },
        
        // State
        state: {
            isProcessing: false,
            progressInterval: null,
            retryCount: 0
        },
        
        // Initialize
        init: function() {
            this.bindEvents();
            this.initializeUI();
            this.checkProcessingStatus();
        },
        
        // Bind event handlers
        bindEvents: function() {
            // Start background processing
            $(document).on('click', '#start-background-processing', this.startBackgroundProcessing.bind(this));
            
            // Stop processing
            $(document).on('click', '#stop-processing', this.stopProcessing.bind(this));
            
            // Clear queue
            $(document).on('click', '#clear-queue', this.clearQueue.bind(this));
            
            // Refresh status
            $(document).on('click', '#refresh-status', this.refreshStatus.bind(this));
            
            // Settings form enhancements
            $(document).on('change', '#conversion-mode', this.toggleConversionOptions.bind(this));
            
            // Preview changes
            $(document).on('click', '.preview-attachment', this.previewAttachment.bind(this));
            
            // Batch selection
            $(document).on('change', '#select-all-attachments', this.toggleAllAttachments.bind(this));
            $(document).on('change', '.attachment-checkbox', this.updateSelectionCount.bind(this));
        },
        
        // Initialize UI components
        initializeUI: function() {
            // Initialize progress bar
            this.initProgressBar();
            
            // Initialize tooltips
            this.initTooltips();
            
            // Initialize conversion options
            this.toggleConversionOptions();
            
            // Update selection count
            this.updateSelectionCount();
        },
        
        // Initialize progress bar
        initProgressBar: function() {
            const $progressContainer = $('#progress-container');
            if ($progressContainer.length === 0) {
                return;
            }
            
            // Create progress bar HTML if it doesn't exist
            if ($progressContainer.find('.progress-bar').length === 0) {
                $progressContainer.html(`
                    <div class="progress-wrapper">
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: 0%"></div>
                        </div>
                        <div class="progress-text">
                            <span class="progress-percentage">0%</span>
                            <span class="progress-message">Ready to start</span>
                        </div>
                        <div class="progress-details">
                            <span class="progress-current">0</span> / 
                            <span class="progress-total">0</span> processed
                        </div>
                    </div>
                `);
            }
        },
        
        // Initialize tooltips
        initTooltips: function() {
            $('[data-tooltip]').each(function() {
                const $element = $(this);
                const tooltip = $element.data('tooltip');
                
                $element.attr('title', tooltip);
            });
        },
        
        // Start background processing
        startBackgroundProcessing: function(e) {
            e.preventDefault();
            
            if (this.state.isProcessing) {
                this.showNotice('Background processing is already running.', 'warning');
                return;
            }
            
            const selectedAttachments = this.getSelectedAttachments();
            if (selectedAttachments.length === 0) {
                this.showNotice('Please select at least one attachment to process.', 'error');
                return;
            }
            
            const options = this.getConversionOptions();
            
            // Confirm action
            if (!confirm(`Start background processing for ${selectedAttachments.length} attachments?`)) {
                return;
            }
            
            // Show loading state
            this.setProcessingState(true);
            
            // Start processing
            $.ajax({
                url: this.config.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'okvir_image_migrator_start_background',
                    nonce: this.config.nonce,
                    attachment_ids: selectedAttachments,
                    options: options
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotice('Background processing started successfully!', 'success');
                        this.startProgressTracking();
                    } else {
                        this.showNotice('Failed to start processing: ' + (response.data || 'Unknown error'), 'error');
                        this.setProcessingState(false);
                    }
                },
                error: () => {
                    this.showNotice('Network error occurred. Please try again.', 'error');
                    this.setProcessingState(false);
                }
            });
        },
        
        // Stop processing
        stopProcessing: function(e) {
            e.preventDefault();
            
            if (!confirm('Are you sure you want to stop the background processing?')) {
                return;
            }
            
            $.ajax({
                url: this.config.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'okvir_image_migrator_stop_background',
                    nonce: this.config.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotice('Background processing stopped.', 'success');
                        this.setProcessingState(false);
                        this.stopProgressTracking();
                    } else {
                        this.showNotice('Failed to stop processing: ' + (response.data || 'Unknown error'), 'error');
                    }
                }
            });
        },
        
        // Clear queue
        clearQueue: function(e) {
            e.preventDefault();
            
            if (!confirm('Clear all queue data? This will remove progress information.')) {
                return;
            }
            
            $.ajax({
                url: this.config.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'okvir_image_migrator_clear_queue',
                    nonce: this.config.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotice('Queue cleared successfully.', 'success');
                        this.updateProgressBar(0, 0, 'Queue cleared');
                        this.setProcessingState(false);
                    } else {
                        this.showNotice('Failed to clear queue: ' + (response.data || 'Unknown error'), 'error');
                    }
                }
            });
        },
        
        // Start progress tracking
        startProgressTracking: function() {
            if (this.state.progressInterval) {
                clearInterval(this.state.progressInterval);
            }
            
            this.state.progressInterval = setInterval(() => {
                this.updateProgress();
            }, this.config.refreshInterval);
            
            // Initial update
            this.updateProgress();
        },
        
        // Stop progress tracking
        stopProgressTracking: function() {
            if (this.state.progressInterval) {
                clearInterval(this.state.progressInterval);
                this.state.progressInterval = null;
            }
        },
        
        // Update progress
        updateProgress: function() {
            $.ajax({
                url: this.config.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'okvir_image_migrator_get_progress',
                    nonce: this.config.nonce
                },
                success: (response) => {
                    if (response.success && response.data) {
                        const progress = response.data;
                        
                        this.updateProgressBar(
                            progress.current,
                            progress.total,
                            progress.message,
                            progress.percentage
                        );
                        
                        // Check if processing is complete
                        if (progress.percentage >= 100 || progress.current >= progress.total) {
                            this.setProcessingState(false);
                            this.stopProgressTracking();
                            
                            if (progress.current > 0) {
                                this.showNotice('Background processing completed!', 'success');
                                // Optionally refresh the page or update UI
                                setTimeout(() => {
                                    location.reload();
                                }, 2000);
                            }
                        }
                        
                        this.state.retryCount = 0; // Reset retry count on success
                    } else {
                        this.handleProgressError();
                    }
                },
                error: () => {
                    this.handleProgressError();
                }
            });
        },
        
        // Handle progress update errors
        handleProgressError: function() {
            this.state.retryCount++;
            
            if (this.state.retryCount >= this.config.maxRetries) {
                this.showNotice('Lost connection to background process. Please refresh the page.', 'warning');
                this.stopProgressTracking();
                this.state.retryCount = 0;
            }
        },
        
        // Update progress bar
        updateProgressBar: function(current, total, message, percentage) {
            percentage = percentage || (total > 0 ? Math.round((current / total) * 100) : 0);
            
            $('.progress-fill').css('width', percentage + '%');
            $('.progress-percentage').text(percentage + '%');
            $('.progress-message').text(message || 'Processing...');
            $('.progress-current').text(current);
            $('.progress-total').text(total);
            
            // Update progress bar color based on status
            const $progressFill = $('.progress-fill');
            $progressFill.removeClass('progress-error progress-warning progress-success');
            
            if (percentage >= 100) {
                $progressFill.addClass('progress-success');
            } else if (message && message.toLowerCase().includes('error')) {
                $progressFill.addClass('progress-error');
            } else if (message && message.toLowerCase().includes('warning')) {
                $progressFill.addClass('progress-warning');
            }
        },
        
        // Set processing state
        setProcessingState: function(isProcessing) {
            this.state.isProcessing = isProcessing;
            
            // Update UI elements
            $('#start-background-processing').prop('disabled', isProcessing);
            $('#stop-processing').prop('disabled', !isProcessing);
            
            if (isProcessing) {
                $('#start-background-processing').text('Processing...');
                $('#processing-indicator').show();
            } else {
                $('#start-background-processing').text('Start Background Processing');
                $('#processing-indicator').hide();
            }
        },
        
        // Check current processing status
        checkProcessingStatus: function() {
            $.ajax({
                url: this.config.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'okvir_image_migrator_get_queue_status',
                    nonce: this.config.nonce
                },
                success: (response) => {
                    if (response.success && response.data) {
                        const status = response.data;
                        
                        if (status.is_processing) {
                            this.setProcessingState(true);
                            this.startProgressTracking();
                        } else {
                            this.setProcessingState(false);
                        }
                        
                        // Update queue stats if available
                        if (status.stats) {
                            this.updateQueueStats(status.stats);
                        }
                    }
                }
            });
        },
        
        // Get selected attachments
        getSelectedAttachments: function() {
            const selected = [];
            $('.attachment-checkbox:checked').each(function() {
                selected.push(parseInt($(this).val()));
            });
            return selected;
        },
        
        // Get conversion options
        getConversionOptions: function() {
            return {
                quality: parseInt($('#quality').val()) || 59,
                batch_size: parseInt($('#batch-size').val()) || 10,
                validation_mode: $('#validation-mode').is(':checked'),
                conversion_mode: $('#conversion-mode').val() || 'quality_only',
                max_width: parseInt($('#max-width').val()) || 0,
                max_height: parseInt($('#max-height').val()) || 0,
                preserve_dimensions: $('#preserve-dimensions').is(':checked')
            };
        },
        
        // Toggle conversion options based on mode
        toggleConversionOptions: function() {
            const mode = $('#conversion-mode').val();
            const $sizeOptions = $('.size-options');
            
            if (mode === 'resize_only' || mode === 'both') {
                $sizeOptions.show();
            } else {
                $sizeOptions.hide();
            }
        },
        
        // Toggle all attachments
        toggleAllAttachments: function() {
            const isChecked = $('#select-all-attachments').is(':checked');
            $('.attachment-checkbox').prop('checked', isChecked);
            this.updateSelectionCount();
        },
        
        // Update selection count
        updateSelectionCount: function() {
            const count = $('.attachment-checkbox:checked').length;
            const total = $('.attachment-checkbox').length;
            
            $('#selection-count').text(`${count} of ${total} selected`);
            
            // Update select all checkbox state
            const $selectAll = $('#select-all-attachments');
            if (count === 0) {
                $selectAll.prop('indeterminate', false).prop('checked', false);
            } else if (count === total) {
                $selectAll.prop('indeterminate', false).prop('checked', true);
            } else {
                $selectAll.prop('indeterminate', true);
            }
        },
        
        // Preview attachment
        previewAttachment: function(e) {
            e.preventDefault();
            
            const attachmentId = $(e.currentTarget).data('attachment-id');
            
            // Open preview modal/popup
            this.openAttachmentPreview(attachmentId);
        },
        
        // Open attachment preview
        openAttachmentPreview: function(attachmentId) {
            // Create modal if it doesn't exist
            if ($('#attachment-preview-modal').length === 0) {
                $('body').append(`
                    <div id="attachment-preview-modal" class="okvir-modal">
                        <div class="okvir-modal-content">
                            <div class="okvir-modal-header">
                                <h3>Attachment Preview</h3>
                                <button class="okvir-modal-close">&times;</button>
                            </div>
                            <div class="okvir-modal-body">
                                <div class="loading">Loading...</div>
                            </div>
                        </div>
                    </div>
                `);
                
                // Bind close events
                $(document).on('click', '.okvir-modal-close, .okvir-modal', function(e) {
                    if (e.target === this) {
                        $('#attachment-preview-modal').hide();
                    }
                });
            }
            
            // Show modal and load content
            const $modal = $('#attachment-preview-modal');
            const $body = $modal.find('.okvir-modal-body');
            
            $body.html('<div class="loading">Loading...</div>');
            $modal.show();
            
            // Load attachment preview
            $.ajax({
                url: this.config.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'okvir_image_migrator_preview_attachment',
                    nonce: this.config.nonce,
                    attachment_id: attachmentId
                },
                success: (response) => {
                    if (response.success) {
                        $body.html(response.data);
                    } else {
                        $body.html('<div class="error">Failed to load preview.</div>');
                    }
                },
                error: () => {
                    $body.html('<div class="error">Network error occurred.</div>');
                }
            });
        },
        
        // Refresh status
        refreshStatus: function(e) {
            e.preventDefault();
            
            this.checkProcessingStatus();
            this.updateProgress();
            
            this.showNotice('Status refreshed.', 'info');
        },
        
        // Update queue statistics
        updateQueueStats: function(stats) {
            $('#queue-total').text(stats.total || 0);
            $('#queue-processed').text(stats.processed || 0);
            $('#queue-failed').text(stats.failed || 0);
            $('#queue-remaining').text(stats.remaining || 0);
        },
        
        // Show notice
        showNotice: function(message, type) {
            type = type || 'info';
            
            // Remove existing notices
            $('.okvir-notice').remove();
            
            // Create notice
            const $notice = $(`
                <div class="notice notice-${type} okvir-notice is-dismissible">
                    <p>${message}</p>
                    <button type="button" class="notice-dismiss">
                        <span class="screen-reader-text">Dismiss this notice.</span>
                    </button>
                </div>
            `);
            
            // Add to page
            $('.wrap h1').after($notice);
            
            // Bind dismiss event
            $notice.find('.notice-dismiss').on('click', function() {
                $notice.fadeOut();
            });
            
            // Auto-dismiss after 5 seconds for success/info
            if (type === 'success' || type === 'info') {
                setTimeout(() => {
                    $notice.fadeOut();
                }, 5000);
            }
        }
    };
    
    // Initialize when document is ready
    $(document).ready(function() {
        OkvirImageMigrator.init();
    });
    
    // Export to global scope for external access
    window.OkvirImageMigrator = OkvirImageMigrator;
    
})(jQuery);
