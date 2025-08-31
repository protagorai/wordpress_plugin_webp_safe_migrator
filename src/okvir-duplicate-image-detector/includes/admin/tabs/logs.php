<?php
/**
 * Processing Logs Tab
 * 
 * Displays detailed processing logs, error messages, and debugging information.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get log filters
$log_filters = [
    'method' => sanitize_text_field($_GET['log_method'] ?? ''),
    'status' => sanitize_text_field($_GET['log_status'] ?? ''),
    'days' => intval($_GET['log_days'] ?? 7),
    'attachment_id' => intval($_GET['log_attachment_id'] ?? 0)
];

// Get processing logs
$log_page = max(1, intval($_GET['log_page'] ?? 1));
$logs_per_page = 50;
$log_offset = ($log_page - 1) * $logs_per_page;

global $wpdb;
$log_table = $wpdb->prefix . 'okvir_processing_log';
$analysis_table = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_IMAGE_ANALYSIS;

// Build WHERE clause for filters
$where_conditions = ["l.created_at >= DATE_SUB(NOW(), INTERVAL {$log_filters['days']} DAY)"];
$where_params = [];

if ($log_filters['method']) {
    $where_conditions[] = "l.method = %s";
    $where_params[] = $log_filters['method'];
}

if ($log_filters['status']) {
    $where_conditions[] = "l.status = %s";
    $where_params[] = $log_filters['status'];
}

if ($log_filters['attachment_id']) {
    $where_conditions[] = "l.attachment_id = %d";
    $where_params[] = $log_filters['attachment_id'];
}

$where_clause = implode(' AND ', $where_conditions);

// Get logs
$logs_query = "
    SELECT l.*, a.attachment_id, p.post_title, p.post_mime_type
    FROM {$log_table} l
    LEFT JOIN {$analysis_table} a ON l.attachment_id = a.attachment_id
    LEFT JOIN {$wpdb->posts} p ON l.attachment_id = p.ID
    WHERE {$where_clause}
    ORDER BY l.created_at DESC
    LIMIT {$logs_per_page} OFFSET {$log_offset}
";

if (!empty($where_params)) {
    $logs = $wpdb->get_results($wpdb->prepare($logs_query, $where_params), ARRAY_A);
} else {
    $logs = $wpdb->get_results($logs_query, ARRAY_A);
}

// Get total count for pagination
$count_query = "SELECT COUNT(*) FROM {$log_table} l WHERE {$where_clause}";
$total_logs = !empty($where_params) ? 
    $wpdb->get_var($wpdb->prepare($count_query, $where_params)) : 
    $wpdb->get_var($count_query);

$total_log_pages = ceil($total_logs / $logs_per_page);
?>

<h2>📋 Processing Logs & Debugging</h2>

<!-- Log Summary -->
<div class="okvir-stats-grid">
    <?php
    $summary_stats = $wpdb->get_results(
        "SELECT status, COUNT(*) as count 
         FROM {$log_table} 
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$log_filters['days']} DAY)
         GROUP BY status",
        ARRAY_A
    );
    
    $summary = ['success' => 0, 'failed' => 0, 'exception' => 0];
    foreach ($summary_stats as $stat) {
        $summary[$stat['status']] = (int) $stat['count'];
    }
    ?>
    
    <div class="okvir-stat-card">
        <div class="okvir-stat-number" style="color: #46b450;"><?php echo number_format($summary['success']); ?></div>
        <div class="okvir-stat-label">Successful Operations</div>
    </div>
    
    <div class="okvir-stat-card">
        <div class="okvir-stat-number" style="color: #dc3232;"><?php echo number_format($summary['failed']); ?></div>
        <div class="okvir-stat-label">Failed Operations</div>
    </div>
    
    <div class="okvir-stat-card">
        <div class="okvir-stat-number" style="color: #ffb900;"><?php echo number_format($summary['exception']); ?></div>
        <div class="okvir-stat-label">Exceptions</div>
    </div>
    
    <div class="okvir-stat-card">
        <div class="okvir-stat-number"><?php echo number_format($total_logs); ?></div>
        <div class="okvir-stat-label">Total Log Entries</div>
    </div>
</div>

<!-- Log Filters -->
<div style="background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; padding: 15px; margin: 20px 0;">
    <h4>🔍 Filter Logs</h4>
    
    <form method="get" style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
        <input type="hidden" name="page" value="okvir-duplicate-detector">
        <input type="hidden" name="tab" value="logs">
        
        <label>
            Method:
            <select name="log_method">
                <option value="">All Methods</option>
                <option value="file_hash" <?php selected($log_filters['method'], 'file_hash'); ?>>File Hash</option>
                <option value="perceptual_hash" <?php selected($log_filters['method'], 'perceptual_hash'); ?>>Perceptual Hash</option>
                <option value="color_histogram" <?php selected($log_filters['method'], 'color_histogram'); ?>>Color Histogram</option>
                <option value="template_match" <?php selected($log_filters['method'], 'template_match'); ?>>Template Match</option>
                <option value="keypoint_match" <?php selected($log_filters['method'], 'keypoint_match'); ?>>Keypoint Match</option>
            </select>
        </label>
        
        <label>
            Status:
            <select name="log_status">
                <option value="">All Statuses</option>
                <option value="success" <?php selected($log_filters['status'], 'success'); ?>>Success</option>
                <option value="failed" <?php selected($log_filters['status'], 'failed'); ?>>Failed</option>
                <option value="exception" <?php selected($log_filters['status'], 'exception'); ?>>Exception</option>
            </select>
        </label>
        
        <label>
            Time Period:
            <select name="log_days">
                <option value="1" <?php selected($log_filters['days'], 1); ?>>Last 24 Hours</option>
                <option value="7" <?php selected($log_filters['days'], 7); ?>>Last 7 Days</option>
                <option value="30" <?php selected($log_filters['days'], 30); ?>>Last 30 Days</option>
                <option value="90" <?php selected($log_filters['days'], 90); ?>>Last 90 Days</option>
            </select>
        </label>
        
        <label>
            Attachment ID:
            <input type="number" name="log_attachment_id" value="<?php echo $log_filters['attachment_id']; ?>" 
                   placeholder="Optional" style="width: 100px;">
        </label>
        
        <button type="submit" class="button">Apply Filters</button>
        <a href="?page=okvir-duplicate-detector&tab=logs" class="button">Clear</a>
    </form>
</div>

<!-- Processing Logs Table -->
<?php if (!empty($logs)): ?>
<div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin: 20px 0;">
    <h3>📊 Processing Logs</h3>
    
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width: 60px;">Image</th>
                <th style="width: 120px;">Method</th>
                <th style="width: 80px;">Status</th>
                <th style="width: 80px;">Time</th>
                <th style="width: 80px;">Memory</th>
                <th>Details</th>
                <th style="width: 120px;">Timestamp</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($logs as $log): 
                $status_class = $log['status'] == 'success' ? 'success' : ($log['status'] == 'failed' ? 'error' : 'warning');
                $debug_data = json_decode($log['debug_data'], true);
            ?>
                <tr>
                    <td>
                        <?php if ($log['post_title']): ?>
                            <strong><?php echo esc_html(substr($log['post_title'], 0, 20)); ?></strong><br>
                        <?php endif; ?>
                        <small>ID: <?php echo $log['attachment_id']; ?></small>
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
                        echo ($method_icons[$log['method']] ?? '🔧');
                        ?>
                        <br><small><?php echo ucwords(str_replace('_', ' ', $log['method'])); ?></small>
                    </td>
                    
                    <td>
                        <span class="notice-<?php echo $status_class; ?> inline" style="padding: 2px 8px; border-radius: 4px;">
                            <?php 
                            switch ($log['status']) {
                                case 'success': echo '✅ Success'; break;
                                case 'failed': echo '❌ Failed'; break;
                                case 'exception': echo '⚠️ Exception'; break;
                                default: echo ucfirst($log['status']);
                            }
                            ?>
                        </span>
                    </td>
                    
                    <td><?php echo number_format($log['execution_time'], 3); ?>s</td>
                    
                    <td><?php echo size_format($log['memory_usage']); ?></td>
                    
                    <td>
                        <?php if ($log['error_message']): ?>
                            <details>
                                <summary style="color: #dc3232; cursor: pointer;">
                                    <?php echo esc_html(substr($log['error_message'], 0, 50)); ?>...
                                </summary>
                                <pre style="background: #f9f9f9; padding: 10px; margin: 5px 0; font-size: 11px; overflow-x: auto;"><?php echo esc_html($log['error_message']); ?></pre>
                            </details>
                        <?php endif; ?>
                        
                        <?php if ($debug_data): ?>
                            <details style="margin-top: 5px;">
                                <summary style="color: #0073aa; cursor: pointer; font-size: 11px;">Debug Data</summary>
                                <pre style="background: #f0f8ff; padding: 10px; margin: 5px 0; font-size: 10px; max-height: 200px; overflow: auto;"><?php echo esc_html(json_encode($debug_data, JSON_PRETTY_PRINT)); ?></pre>
                            </details>
                        <?php endif; ?>
                    </td>
                    
                    <td><?php echo date('M j, H:i:s', strtotime($log['created_at'])); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <!-- Log Pagination -->
    <?php if ($total_log_pages > 1): ?>
    <div style="text-align: center; margin-top: 20px;">
        <?php
        $base_url = "?page=okvir-duplicate-detector&tab=logs";
        if ($log_filters['method']) $base_url .= "&log_method=" . $log_filters['method'];
        if ($log_filters['status']) $base_url .= "&log_status=" . $log_filters['status'];
        if ($log_filters['days']) $base_url .= "&log_days=" . $log_filters['days'];
        if ($log_filters['attachment_id']) $base_url .= "&log_attachment_id=" . $log_filters['attachment_id'];
        
        if ($log_page > 1): ?>
            <a href="<?php echo $base_url; ?>&log_page=<?php echo $log_page - 1; ?>" class="button">« Previous</a>
        <?php endif; ?>
        
        <span style="margin: 0 10px;">
            Page <?php echo $log_page; ?> of <?php echo $total_log_pages; ?>
        </span>
        
        <?php if ($log_page < $total_log_pages): ?>
            <a href="<?php echo $base_url; ?>&log_page=<?php echo $log_page + 1; ?>" class="button">Next »</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php else: ?>
    <div style="background: #f0f8ff; border: 1px solid #0073aa; border-radius: 4px; padding: 20px; margin: 20px 0; text-align: center;">
        <h3>📝 No Logs Found</h3>
        <p>No processing logs found for the selected criteria.</p>
        
        <?php if ($log_filters['days'] < 7): ?>
            <p><a href="?page=okvir-duplicate-detector&tab=logs&log_days=30">Try expanding the time period</a></p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Error Summary -->
<?php 
$error_summary = $wpdb->get_results(
    "SELECT method, error_message, COUNT(*) as count
     FROM {$log_table}
     WHERE status IN ('failed', 'exception')
     AND created_at >= DATE_SUB(NOW(), INTERVAL {$log_filters['days']} DAY)
     GROUP BY method, error_message
     ORDER BY count DESC
     LIMIT 10",
    ARRAY_A
);
?>

<?php if (!empty($error_summary)): ?>
<div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin: 20px 0;">
    <h3>⚠️ Most Common Errors (Last <?php echo $log_filters['days']; ?> Days)</h3>
    
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>Method</th>
                <th>Error Message</th>
                <th>Occurrences</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($error_summary as $error): ?>
                <tr>
                    <td>
                        <?php
                        $method_icons = [
                            'file_hash' => '🔐',
                            'perceptual_hash' => '🎭',
                            'color_histogram' => '🌈',
                            'template_match' => '🎯',
                            'keypoint_match' => '🔑'
                        ];
                        echo ($method_icons[$error['method']] ?? '🔧') . ' ' . ucwords(str_replace('_', ' ', $error['method']));
                        ?>
                    </td>
                    <td>
                        <code><?php echo esc_html(substr($error['error_message'], 0, 100)); ?></code>
                        <?php if (strlen($error['error_message']) > 100): ?>...<?php endif; ?>
                    </td>
                    <td><strong><?php echo number_format($error['count']); ?></strong></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Performance Metrics -->
<?php
$performance_metrics = $wpdb->get_results(
    "SELECT 
        method,
        COUNT(*) as total_operations,
        AVG(execution_time) as avg_time,
        MAX(execution_time) as max_time,
        MIN(execution_time) as min_time,
        AVG(memory_usage) as avg_memory,
        MAX(memory_usage) as max_memory
     FROM {$log_table}
     WHERE status = 'success'
     AND created_at >= DATE_SUB(NOW(), INTERVAL {$log_filters['days']} DAY)
     GROUP BY method
     ORDER BY avg_time ASC",
    ARRAY_A
);
?>

<?php if (!empty($performance_metrics)): ?>
<div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin: 20px 0;">
    <h3>📈 Performance Metrics (Last <?php echo $log_filters['days']; ?> Days)</h3>
    
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>Method</th>
                <th>Operations</th>
                <th>Avg Time</th>
                <th>Max Time</th>
                <th>Avg Memory</th>
                <th>Max Memory</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($performance_metrics as $metric): ?>
                <tr>
                    <td>
                        <?php
                        $method_icons = [
                            'file_hash' => '🔐',
                            'perceptual_hash' => '🎭',
                            'color_histogram' => '🌈',
                            'template_match' => '🎯',
                            'keypoint_match' => '🔑'
                        ];
                        echo ($method_icons[$metric['method']] ?? '🔧') . ' ' . ucwords(str_replace('_', ' ', $metric['method']));
                        ?>
                    </td>
                    <td><?php echo number_format($metric['total_operations']); ?></td>
                    <td><?php echo number_format($metric['avg_time'], 3); ?>s</td>
                    <td><?php echo number_format($metric['max_time'], 3); ?>s</td>
                    <td><?php echo size_format($metric['avg_memory']); ?></td>
                    <td><?php echo size_format($metric['max_memory']); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Log Maintenance -->
<div style="background: #fff8e1; border: 1px solid #ffb900; border-radius: 4px; padding: 20px; margin: 20px 0;">
    <h3>🧹 Log Maintenance</h3>
    
    <div class="okvir-form-row">
        <button type="button" class="button" onclick="okvirLogManager.cleanupOldLogs(30)">
            🗑️ Clean Up Logs Older Than 30 Days
        </button>
        
        <button type="button" class="button" onclick="okvirLogManager.optimizeTables()">
            🚀 Optimize Database Tables
        </button>
        
        <button type="button" class="button" onclick="okvirLogManager.exportLogs()">
            📤 Export Current Logs
        </button>
    </div>
    
    <p style="margin-top: 15px; color: #666;">
        <strong>Note:</strong> Processing logs help identify issues and optimize performance. 
        Old logs are automatically cleaned up, but you can manually clean them to free database space.
    </p>
</div>

<!-- Debug Information -->
<div style="background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin: 20px 0;">
    <h3>🐛 Debug Information</h3>
    
    <div class="okvir-form-row">
        <label>PHP Version:</label>
        <span><?php echo PHP_VERSION; ?></span>
    </div>
    
    <div class="okvir-form-row">
        <label>WordPress Version:</label>
        <span><?php echo get_bloginfo('version'); ?></span>
    </div>
    
    <div class="okvir-form-row">
        <label>Plugin Version:</label>
        <span><?php echo OKVIR_DUP_DETECTOR_VERSION; ?></span>
    </div>
    
    <div class="okvir-form-row">
        <label>GD Extension:</label>
        <span style="color: <?php echo extension_loaded('gd') ? '#46b450' : '#dc3232'; ?>;">
            <?php echo extension_loaded('gd') ? '✅ Loaded' : '❌ Not loaded'; ?>
            <?php if (extension_loaded('gd')): ?>
                <small>(Version: <?php echo gd_info()['GD Version'] ?? 'Unknown'; ?>)</small>
            <?php endif; ?>
        </span>
    </div>
    
    <div class="okvir-form-row">
        <label>Imagick Extension:</label>
        <span style="color: <?php echo extension_loaded('imagick') ? '#46b450' : '#ffb900'; ?>;">
            <?php echo extension_loaded('imagick') ? '✅ Loaded' : '⚠️ Not loaded (optional)'; ?>
            <?php if (extension_loaded('imagick')): ?>
                <small>(Version: <?php echo phpversion('imagick'); ?>)</small>
            <?php endif; ?>
        </span>
    </div>
    
    <div class="okvir-form-row">
        <label>Current Memory Usage:</label>
        <span><?php echo size_format(memory_get_usage(true)); ?></span>
    </div>
    
    <div class="okvir-form-row">
        <label>Peak Memory Usage:</label>
        <span><?php echo size_format(memory_get_peak_usage(true)); ?></span>
    </div>
    
    <div class="okvir-form-row">
        <label>Database Version:</label>
        <span><?php echo get_option('okvir_duplicate_detector_db_version', 'Unknown'); ?></span>
    </div>
</div>

<script>
window.okvirLogManager = {
    cleanupOldLogs: function(days) {
        if (!confirm(`Delete processing logs older than ${days} days?`)) {
            return;
        }
        
        // Implementation would call AJAX endpoint
        alert('Old logs cleaned up');
    },
    
    optimizeTables: function() {
        if (!confirm('Optimize all plugin database tables? This may take a few moments.')) {
            return;
        }
        
        // Implementation would call AJAX endpoint
        alert('Database tables optimized');
    },
    
    exportLogs: function() {
        const currentFilters = new URLSearchParams(window.location.search);
        const exportUrl = ajaxurl + '?action=okvir_dup_export_logs&' + currentFilters.toString() + '&nonce=' + okvirDupDetector.nonce;
        
        window.open(exportUrl, '_blank');
    }
};
</script>
