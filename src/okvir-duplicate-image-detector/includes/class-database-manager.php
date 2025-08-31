<?php
/**
 * Database Manager Class
 * 
 * Handles database table creation, schema management, and database operations
 * for the Okvir Duplicate Image Detector plugin.
 */

if (!defined('ABSPATH')) {
    exit;
}

class OkvirDupDetector_DatabaseManager {
    
    /**
     * Create all plugin tables
     */
    public function create_tables() {
        global $wpdb;
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Create image signatures table - stores unique hash/signature values
        $table_signatures = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_IMAGE_SIGNATURES;
        $sql_signatures = "CREATE TABLE {$table_signatures} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            method varchar(50) NOT NULL,
            signature_hash varchar(255) NOT NULL,
            signature_data longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_method_signature (method, signature_hash),
            KEY idx_method (method),
            KEY idx_signature_hash (signature_hash)
        ) {$charset_collate};";
        
        dbDelta($sql_signatures);
        
        // Create image analysis table - stores analysis results for each image
        $table_analysis = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_IMAGE_ANALYSIS;
        $sql_analysis = "CREATE TABLE {$table_analysis} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            attachment_id bigint(20) unsigned NOT NULL,
            file_path text NOT NULL,
            file_hash varchar(64) NOT NULL,
            file_size bigint(20) unsigned NOT NULL,
            image_width int(10) unsigned NOT NULL,
            image_height int(10) unsigned NOT NULL,
            mime_type varchar(50) NOT NULL,
            methods_processed text,
            processing_status varchar(20) DEFAULT 'pending',
            analysis_score decimal(5,2) DEFAULT 0.00,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_attachment_id (attachment_id),
            KEY idx_file_hash (file_hash),
            KEY idx_processing_status (processing_status),
            KEY idx_analysis_score (analysis_score)
        ) {$charset_collate};";
        
        dbDelta($sql_analysis);
        
        // Create signature links table - links images to their signatures
        $table_signature_links = $wpdb->prefix . 'okvir_image_signature_links';
        $sql_signature_links = "CREATE TABLE {$table_signature_links} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            analysis_id bigint(20) unsigned NOT NULL,
            signature_id bigint(20) unsigned NOT NULL,
            similarity_score decimal(5,2) DEFAULT 100.00,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_analysis_signature (analysis_id, signature_id),
            KEY idx_analysis_id (analysis_id),
            KEY idx_signature_id (signature_id),
            KEY idx_similarity_score (similarity_score),
            FOREIGN KEY (analysis_id) REFERENCES {$table_analysis}(id) ON DELETE CASCADE,
            FOREIGN KEY (signature_id) REFERENCES {$table_signatures}(id) ON DELETE CASCADE
        ) {$charset_collate};";
        
        dbDelta($sql_signature_links);
        
        // Create duplicate groups table - groups duplicate images together
        $table_groups = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_DUPLICATE_GROUPS;
        $sql_groups = "CREATE TABLE {$table_groups} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            group_hash varchar(64) NOT NULL,
            original_analysis_id bigint(20) unsigned NOT NULL,
            duplicate_count int(10) unsigned DEFAULT 0,
            total_file_size bigint(20) unsigned DEFAULT 0,
            methods_matched text,
            confidence_score decimal(5,2) DEFAULT 0.00,
            status varchar(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_group_hash (group_hash),
            KEY idx_original_analysis_id (original_analysis_id),
            KEY idx_status (status),
            KEY idx_confidence_score (confidence_score),
            FOREIGN KEY (original_analysis_id) REFERENCES {$table_analysis}(id) ON DELETE CASCADE
        ) {$charset_collate};";
        
        dbDelta($sql_groups);
        
        // Create group members table - links duplicate images to their group
        $table_group_members = $wpdb->prefix . 'okvir_duplicate_group_members';
        $sql_group_members = "CREATE TABLE {$table_group_members} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            group_id bigint(20) unsigned NOT NULL,
            analysis_id bigint(20) unsigned NOT NULL,
            is_original tinyint(1) DEFAULT 0,
            duplicate_rank int(10) unsigned DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_group_analysis (group_id, analysis_id),
            KEY idx_group_id (group_id),
            KEY idx_analysis_id (analysis_id),
            KEY idx_is_original (is_original),
            FOREIGN KEY (group_id) REFERENCES {$table_groups}(id) ON DELETE CASCADE,
            FOREIGN KEY (analysis_id) REFERENCES {$table_analysis}(id) ON DELETE CASCADE
        ) {$charset_collate};";
        
        dbDelta($sql_group_members);
        
        // Create processing queue table - manages background processing
        $table_queue = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_PROCESSING_QUEUE;
        $sql_queue = "CREATE TABLE {$table_queue} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            attachment_id bigint(20) unsigned NOT NULL,
            priority int(10) unsigned DEFAULT 10,
            status varchar(20) DEFAULT 'pending',
            attempts int(10) unsigned DEFAULT 0,
            max_attempts int(10) unsigned DEFAULT 3,
            error_message text,
            scheduled_at datetime DEFAULT CURRENT_TIMESTAMP,
            started_at datetime NULL,
            completed_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_attachment_id (attachment_id),
            KEY idx_status (status),
            KEY idx_priority (priority),
            KEY idx_scheduled_at (scheduled_at)
        ) {$charset_collate};";
        
        dbDelta($sql_queue);
        
        // Create processing log table - detailed processing logs
        $table_log = $wpdb->prefix . 'okvir_processing_log';
        $sql_log = "CREATE TABLE {$table_log} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            attachment_id bigint(20) unsigned NOT NULL,
            method varchar(50) NOT NULL,
            status varchar(20) NOT NULL,
            execution_time decimal(8,3) DEFAULT 0.000,
            memory_usage bigint(20) unsigned DEFAULT 0,
            error_message text,
            debug_data longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_attachment_id (attachment_id),
            KEY idx_method (method),
            KEY idx_status (status),
            KEY idx_created_at (created_at)
        ) {$charset_collate};";
        
        dbDelta($sql_log);
        
        // Create content references table - tracks where images are used
        $table_references = $wpdb->prefix . 'okvir_content_references';
        $sql_references = "CREATE TABLE {$table_references} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            attachment_id bigint(20) unsigned NOT NULL,
            reference_type varchar(50) NOT NULL,
            reference_id bigint(20) unsigned NOT NULL,
            reference_field varchar(100),
            reference_context text,
            url_found text,
            last_scanned datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_attachment_id (attachment_id),
            KEY idx_reference_type (reference_type),
            KEY idx_reference_id (reference_id),
            KEY idx_last_scanned (last_scanned)
        ) {$charset_collate};";
        
        dbDelta($sql_references);
        
        // Update database version
        update_option('okvir_duplicate_detector_db_version', '1.0.0');
    }
    
    /**
     * Check if tables exist and are up to date
     */
    public function verify_tables() {
        global $wpdb;
        
        $tables = [
            OkvirDuplicateImageDetector::TABLE_IMAGE_SIGNATURES,
            OkvirDuplicateImageDetector::TABLE_IMAGE_ANALYSIS,
            OkvirDuplicateImageDetector::TABLE_DUPLICATE_GROUPS,
            OkvirDuplicateImageDetector::TABLE_PROCESSING_QUEUE,
            'okvir_image_signature_links',
            'okvir_duplicate_group_members',
            'okvir_processing_log',
            'okvir_content_references'
        ];
        
        foreach ($tables as $table) {
            $table_name = $wpdb->prefix . $table;
            $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");
            
            if ($exists !== $table_name) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Get database statistics
     */
    public function get_statistics() {
        global $wpdb;
        
        $stats = [];
        
        // Basic table counts
        $tables = [
            'signatures' => OkvirDuplicateImageDetector::TABLE_IMAGE_SIGNATURES,
            'analyzed_images' => OkvirDuplicateImageDetector::TABLE_IMAGE_ANALYSIS,
            'duplicate_groups' => OkvirDuplicateImageDetector::TABLE_DUPLICATE_GROUPS,
            'queue_items' => OkvirDuplicateImageDetector::TABLE_PROCESSING_QUEUE,
        ];
        
        foreach ($tables as $key => $table) {
            $stats[$key] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}{$table}");
        }
        
        // Processing status breakdown
        $status_counts = $wpdb->get_results(
            "SELECT processing_status, COUNT(*) as count 
             FROM {$wpdb->prefix}" . OkvirDuplicateImageDetector::TABLE_IMAGE_ANALYSIS . " 
             GROUP BY processing_status",
            ARRAY_A
        );
        
        $stats['processing_status'] = [];
        foreach ($status_counts as $status) {
            $stats['processing_status'][$status['processing_status']] = (int) $status['count'];
        }
        
        // Method usage statistics
        $method_stats = $wpdb->get_results(
            "SELECT method, COUNT(*) as count 
             FROM {$wpdb->prefix}" . OkvirDuplicateImageDetector::TABLE_IMAGE_SIGNATURES . " 
             GROUP BY method",
            ARRAY_A
        );
        
        $stats['methods'] = [];
        foreach ($method_stats as $method) {
            $stats['methods'][$method['method']] = (int) $method['count'];
        }
        
        // Duplicate detection summary
        $duplicate_summary = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total_groups,
                SUM(duplicate_count) as total_duplicates,
                SUM(total_file_size) as total_duplicate_size,
                AVG(confidence_score) as avg_confidence
             FROM {$wpdb->prefix}" . OkvirDuplicateImageDetector::TABLE_DUPLICATE_GROUPS . "
             WHERE status = 'active'",
            ARRAY_A
        );
        
        $stats['duplicates'] = [
            'groups' => (int) ($duplicate_summary['total_groups'] ?? 0),
            'total_duplicates' => (int) ($duplicate_summary['total_duplicates'] ?? 0),
            'size_savings' => (int) ($duplicate_summary['total_duplicate_size'] ?? 0),
            'avg_confidence' => (float) ($duplicate_summary['avg_confidence'] ?? 0)
        ];
        
        return $stats;
    }
    
    /**
     * Clean up old processing logs
     */
    public function cleanup_old_logs($days = 30) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'okvir_processing_log';
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < %s",
                $cutoff_date
            )
        );
    }
    
    /**
     * Optimize database tables
     */
    public function optimize_tables() {
        global $wpdb;
        
        $tables = [
            OkvirDuplicateImageDetector::TABLE_IMAGE_SIGNATURES,
            OkvirDuplicateImageDetector::TABLE_IMAGE_ANALYSIS,
            OkvirDuplicateImageDetector::TABLE_DUPLICATE_GROUPS,
            OkvirDuplicateImageDetector::TABLE_PROCESSING_QUEUE,
            'okvir_image_signature_links',
            'okvir_duplicate_group_members',
            'okvir_processing_log',
            'okvir_content_references'
        ];
        
        foreach ($tables as $table) {
            $table_name = $wpdb->prefix . $table;
            $wpdb->query("OPTIMIZE TABLE {$table_name}");
        }
        
        return true;
    }
    
    /**
     * Get duplicate groups for admin interface
     */
    public function get_duplicate_groups($page = 1, $per_page = 20, $filters = []) {
        global $wpdb;
        
        $offset = ($page - 1) * $per_page;
        $where_clauses = ["g.status = 'active'"];
        $params = [];
        
        // Apply filters
        if (!empty($filters['min_confidence'])) {
            $where_clauses[] = "g.confidence_score >= %f";
            $params[] = $filters['min_confidence'];
        }
        
        if (!empty($filters['min_duplicates'])) {
            $where_clauses[] = "g.duplicate_count >= %d";
            $params[] = $filters['min_duplicates'];
        }
        
        if (!empty($filters['method'])) {
            $where_clauses[] = "g.methods_matched LIKE %s";
            $params[] = '%' . $filters['method'] . '%';
        }
        
        $where_clause = implode(' AND ', $where_clauses);
        
        // Build query
        $query = "
            SELECT 
                g.*,
                a.file_path as original_file_path,
                a.file_size as original_file_size,
                a.image_width,
                a.image_height,
                a.mime_type
            FROM {$wpdb->prefix}" . OkvirDuplicateImageDetector::TABLE_DUPLICATE_GROUPS . " g
            LEFT JOIN {$wpdb->prefix}" . OkvirDuplicateImageDetector::TABLE_IMAGE_ANALYSIS . " a 
                ON g.original_analysis_id = a.id
            WHERE {$where_clause}
            ORDER BY g.confidence_score DESC, g.duplicate_count DESC
            LIMIT %d OFFSET %d
        ";
        
        $params[] = $per_page;
        $params[] = $offset;
        
        if (!empty($params)) {
            $prepared_query = $wpdb->prepare($query, $params);
        } else {
            $prepared_query = $query . $wpdb->prepare(" LIMIT %d OFFSET %d", $per_page, $offset);
        }
        
        $groups = $wpdb->get_results($prepared_query, ARRAY_A);
        
        // Get total count for pagination
        $count_query = "
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}" . OkvirDuplicateImageDetector::TABLE_DUPLICATE_GROUPS . " g
            WHERE {$where_clause}
        ";
        
        if (!empty($where_clauses) && !empty($params)) {
            $count_params = array_slice($params, 0, -2); // Remove LIMIT params
            $total = $wpdb->get_var($wpdb->prepare($count_query, $count_params));
        } else {
            $total = $wpdb->get_var($count_query);
        }
        
        return [
            'groups' => $groups,
            'total' => (int) $total,
            'pages' => ceil($total / $per_page)
        ];
    }
    
    /**
     * Get group members (duplicates) for a specific group
     */
    public function get_group_members($group_id) {
        global $wpdb;
        
        $query = "
            SELECT 
                gm.*,
                a.attachment_id,
                a.file_path,
                a.file_size,
                a.image_width,
                a.image_height,
                a.mime_type
            FROM {$wpdb->prefix}okvir_duplicate_group_members gm
            LEFT JOIN {$wpdb->prefix}" . OkvirDuplicateImageDetector::TABLE_IMAGE_ANALYSIS . " a 
                ON gm.analysis_id = a.id
            WHERE gm.group_id = %d
            ORDER BY gm.is_original DESC, gm.duplicate_rank ASC
        ";
        
        return $wpdb->get_results($wpdb->prepare($query, $group_id), ARRAY_A);
    }
}
