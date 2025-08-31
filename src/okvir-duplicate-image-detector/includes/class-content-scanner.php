<?php
/**
 * Content Scanner Class
 * 
 * Scans WordPress content for image references and manages safe replacement
 * and deletion of duplicate images across all content types.
 */

if (!defined('ABSPATH')) {
    exit;
}

class OkvirDupDetector_ContentScanner {
    
    private $settings;
    
    public function __construct() {
        $this->settings = OkvirDuplicateImageDetector::get_instance()->get_settings();
    }
    
    /**
     * Safely delete duplicates by replacing references with original
     */
    public function delete_duplicates_safely($duplicate_attachment_ids) {
        if (empty($duplicate_attachment_ids)) {
            return ['deleted' => 0, 'errors' => []];
        }
        
        $results = [
            'deleted' => 0,
            'errors' => [],
            'replacements' => [],
            'backups_created' => 0
        ];
        
        foreach ($duplicate_attachment_ids as $duplicate_id) {
            try {
                $delete_result = $this->delete_single_duplicate($duplicate_id);
                
                if ($delete_result['success']) {
                    $results['deleted']++;
                    $results['replacements'] = array_merge($results['replacements'], $delete_result['replacements']);
                    if ($delete_result['backup_created']) {
                        $results['backups_created']++;
                    }
                } else {
                    $results['errors'][] = [
                        'attachment_id' => $duplicate_id,
                        'error' => $delete_result['error']
                    ];
                }
                
            } catch (Exception $e) {
                $results['errors'][] = [
                    'attachment_id' => $duplicate_id,
                    'error' => 'Exception: ' . $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Delete a single duplicate image safely
     */
    private function delete_single_duplicate($duplicate_attachment_id) {
        // Find the original image for this duplicate
        $original_attachment_id = $this->find_original_for_duplicate($duplicate_attachment_id);
        
        if (!$original_attachment_id) {
            return [
                'success' => false,
                'error' => 'Could not find original image for duplicate'
            ];
        }
        
        // Find all references to the duplicate
        $references = $this->find_all_references($duplicate_attachment_id);
        
        // Create backup if enabled
        $backup_created = false;
        if ($this->settings['backup_before_delete']) {
            $backup_created = $this->create_image_backup($duplicate_attachment_id);
        }
        
        // Replace all references
        $replacements = [];
        foreach ($references as $reference) {
            $replacement_result = $this->replace_reference($reference, $duplicate_attachment_id, $original_attachment_id);
            if ($replacement_result['success']) {
                $replacements[] = $replacement_result;
            }
        }
        
        // Delete the duplicate attachment
        $delete_success = wp_delete_attachment($duplicate_attachment_id, true);
        
        if (!$delete_success) {
            // Rollback replacements if deletion failed
            $this->rollback_replacements($replacements);
            
            return [
                'success' => false,
                'error' => 'Failed to delete duplicate attachment'
            ];
        }
        
        return [
            'success' => true,
            'replacements' => $replacements,
            'backup_created' => $backup_created,
            'references_updated' => count($replacements)
        ];
    }
    
    /**
     * Find original image for a duplicate
     */
    private function find_original_for_duplicate($duplicate_attachment_id) {
        global $wpdb;
        
        $groups_table = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_DUPLICATE_GROUPS;
        $members_table = $wpdb->prefix . 'okvir_duplicate_group_members';
        $analysis_table = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_IMAGE_ANALYSIS;
        
        // Find the group this duplicate belongs to
        $original_analysis_id = $wpdb->get_var($wpdb->prepare(
            "SELECT g.original_analysis_id
             FROM {$groups_table} g
             INNER JOIN {$members_table} gm ON g.id = gm.group_id
             INNER JOIN {$analysis_table} a ON gm.analysis_id = a.id
             WHERE a.attachment_id = %d AND g.status = 'active'",
            $duplicate_attachment_id
        ));
        
        if (!$original_analysis_id) {
            return false;
        }
        
        // Get the original attachment ID
        return $wpdb->get_var($wpdb->prepare(
            "SELECT attachment_id FROM {$analysis_table} WHERE id = %d",
            $original_analysis_id
        ));
    }
    
    /**
     * Find all references to an image across WordPress
     */
    public function find_all_references($attachment_id) {
        $references = [];
        
        // Scan posts content
        $references = array_merge($references, $this->scan_post_content($attachment_id));
        
        // Scan post meta
        $references = array_merge($references, $this->scan_post_meta($attachment_id));
        
        // Scan options
        $references = array_merge($references, $this->scan_options($attachment_id));
        
        // Scan custom tables (theme/plugin specific)
        $references = array_merge($references, $this->scan_custom_tables($attachment_id));
        
        // Scan featured images
        $references = array_merge($references, $this->scan_featured_images($attachment_id));
        
        // Store references in database for tracking
        $this->store_references($attachment_id, $references);
        
        return $references;
    }
    
    /**
     * Scan post content for image references
     */
    private function scan_post_content($attachment_id) {
        global $wpdb;
        
        $attachment_url = wp_get_attachment_url($attachment_id);
        $upload_dir = wp_get_upload_dir();
        $file_path = get_attached_file($attachment_id);
        $relative_path = str_replace($upload_dir['basedir'] . '/', '', $file_path);
        
        $references = [];
        
        // Search for various URL patterns
        $search_patterns = [
            $attachment_url,
            $upload_dir['baseurl'] . '/' . $relative_path,
            $relative_path,
            basename($file_path)
        ];
        
        foreach ($search_patterns as $pattern) {
            if (empty($pattern)) continue;
            
            $posts = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_title, post_content, post_type 
                 FROM {$wpdb->posts} 
                 WHERE post_content LIKE %s 
                 AND post_status IN ('publish', 'draft', 'private')",
                '%' . $wpdb->esc_like($pattern) . '%'
            ), ARRAY_A);
            
            foreach ($posts as $post) {
                $references[] = [
                    'type' => 'post_content',
                    'reference_id' => $post['ID'],
                    'field' => 'post_content',
                    'context' => $post['post_type'] . ': ' . $post['post_title'],
                    'url_found' => $pattern,
                    'content_excerpt' => $this->get_content_excerpt($post['post_content'], $pattern)
                ];
            }
        }
        
        return $references;
    }
    
    /**
     * Scan post meta for image references
     */
    private function scan_post_meta($attachment_id) {
        global $wpdb;
        
        $references = [];
        
        // Direct attachment ID references
        $meta_refs = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_key, meta_value
             FROM {$wpdb->postmeta}
             WHERE meta_value = %s
             OR meta_value LIKE %s",
            $attachment_id,
            '%' . $wpdb->esc_like('"' . $attachment_id . '"') . '%'
        ), ARRAY_A);
        
        foreach ($meta_refs as $meta) {
            $post = get_post($meta['post_id']);
            $references[] = [
                'type' => 'post_meta',
                'reference_id' => $meta['post_id'],
                'field' => $meta['meta_key'],
                'context' => ($post ? $post->post_type . ': ' . $post->post_title : 'Unknown post'),
                'url_found' => $attachment_id,
                'meta_value' => $meta['meta_value']
            ];
        }
        
        // URL-based references in meta values
        $attachment_url = wp_get_attachment_url($attachment_id);
        if ($attachment_url) {
            $url_refs = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, meta_key, meta_value
                 FROM {$wpdb->postmeta}
                 WHERE meta_value LIKE %s",
                '%' . $wpdb->esc_like($attachment_url) . '%'
            ), ARRAY_A);
            
            foreach ($url_refs as $meta) {
                $post = get_post($meta['post_id']);
                $references[] = [
                    'type' => 'post_meta_url',
                    'reference_id' => $meta['post_id'],
                    'field' => $meta['meta_key'],
                    'context' => ($post ? $post->post_type . ': ' . $post->post_title : 'Unknown post'),
                    'url_found' => $attachment_url,
                    'meta_value' => $meta['meta_value']
                ];
            }
        }
        
        return $references;
    }
    
    /**
     * Scan options table for image references
     */
    private function scan_options($attachment_id) {
        global $wpdb;
        
        $attachment_url = wp_get_attachment_url($attachment_id);
        $references = [];
        
        $search_patterns = [$attachment_id, $attachment_url];
        
        foreach ($search_patterns as $pattern) {
            if (empty($pattern)) continue;
            
            $options = $wpdb->get_results($wpdb->prepare(
                "SELECT option_name, option_value
                 FROM {$wpdb->options}
                 WHERE option_value LIKE %s",
                '%' . $wpdb->esc_like($pattern) . '%'
            ), ARRAY_A);
            
            foreach ($options as $option) {
                $references[] = [
                    'type' => 'option',
                    'reference_id' => 0,
                    'field' => $option['option_name'],
                    'context' => 'WordPress Option: ' . $option['option_name'],
                    'url_found' => $pattern,
                    'option_value' => $option['option_value']
                ];
            }
        }
        
        return $references;
    }
    
    /**
     * Scan custom tables for image references
     */
    private function scan_custom_tables($attachment_id) {
        global $wpdb;
        
        $references = [];
        $attachment_url = wp_get_attachment_url($attachment_id);
        
        // Get all custom tables (non-WordPress core)
        $tables = $wpdb->get_results("SHOW TABLES LIKE '{$wpdb->prefix}%'", ARRAY_N);
        $core_tables = ['posts', 'postmeta', 'options', 'users', 'usermeta', 'terms', 'term_taxonomy', 'term_relationships', 'comments', 'commentmeta', 'links'];
        
        foreach ($tables as $table_array) {
            $table = $table_array[0];
            $table_name = str_replace($wpdb->prefix, '', $table);
            
            // Skip core tables
            if (in_array($table_name, $core_tables)) {
                continue;
            }
            
            // Get table structure
            $columns = $wpdb->get_results("DESCRIBE {$table}", ARRAY_A);
            $text_columns = [];
            
            foreach ($columns as $column) {
                if (strpos(strtolower($column['Type']), 'text') !== false || 
                    strpos(strtolower($column['Type']), 'varchar') !== false ||
                    strpos(strtolower($column['Type']), 'longtext') !== false) {
                    $text_columns[] = $column['Field'];
                }
            }
            
            // Search in text columns
            foreach ($text_columns as $column) {
                foreach ([$attachment_id, $attachment_url] as $pattern) {
                    if (empty($pattern)) continue;
                    
                    $results = $wpdb->get_results($wpdb->prepare(
                        "SELECT * FROM {$table} WHERE {$column} LIKE %s LIMIT 10",
                        '%' . $wpdb->esc_like($pattern) . '%'
                    ), ARRAY_A);
                    
                    foreach ($results as $row) {
                        $primary_key = $this->get_primary_key_value($table, $row);
                        
                        $references[] = [
                            'type' => 'custom_table',
                            'reference_id' => $primary_key,
                            'field' => $column,
                            'context' => 'Custom Table: ' . $table_name,
                            'url_found' => $pattern,
                            'table_name' => $table,
                            'row_data' => $row
                        ];
                    }
                }
            }
        }
        
        return $references;
    }
    
    /**
     * Scan for featured image references
     */
    private function scan_featured_images($attachment_id) {
        global $wpdb;
        
        $references = [];
        
        // Find posts using this attachment as featured image
        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_type
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON p.ID = m.post_id
             WHERE m.meta_key = '_thumbnail_id' AND m.meta_value = %s",
            $attachment_id
        ), ARRAY_A);
        
        foreach ($posts as $post) {
            $references[] = [
                'type' => 'featured_image',
                'reference_id' => $post['ID'],
                'field' => '_thumbnail_id',
                'context' => $post['post_type'] . ': ' . $post['post_title'],
                'url_found' => $attachment_id
            ];
        }
        
        return $references;
    }
    
    /**
     * Replace reference with original image
     */
    private function replace_reference($reference, $duplicate_id, $original_id) {
        global $wpdb;
        
        $backup_data = [];
        $replacement_success = false;
        
        try {
            switch ($reference['type']) {
                case 'post_content':
                    $replacement_success = $this->replace_in_post_content($reference, $duplicate_id, $original_id, $backup_data);
                    break;
                    
                case 'post_meta':
                case 'post_meta_url':
                    $replacement_success = $this->replace_in_post_meta($reference, $duplicate_id, $original_id, $backup_data);
                    break;
                    
                case 'option':
                    $replacement_success = $this->replace_in_option($reference, $duplicate_id, $original_id, $backup_data);
                    break;
                    
                case 'custom_table':
                    $replacement_success = $this->replace_in_custom_table($reference, $duplicate_id, $original_id, $backup_data);
                    break;
                    
                case 'featured_image':
                    $replacement_success = $this->replace_featured_image($reference, $duplicate_id, $original_id, $backup_data);
                    break;
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Exception: ' . $e->getMessage(),
                'reference' => $reference
            ];
        }
        
        return [
            'success' => $replacement_success,
            'reference' => $reference,
            'backup_data' => $backup_data
        ];
    }
    
    /**
     * Replace reference in post content
     */
    private function replace_in_post_content($reference, $duplicate_id, $original_id, &$backup_data) {
        global $wpdb;
        
        $post = get_post($reference['reference_id']);
        if (!$post) {
            return false;
        }
        
        $backup_data['original_content'] = $post->post_content;
        
        // Get URLs for replacement
        $duplicate_url = wp_get_attachment_url($duplicate_id);
        $original_url = wp_get_attachment_url($original_id);
        
        if (!$duplicate_url || !$original_url) {
            return false;
        }
        
        // Replace all occurrences of duplicate URL with original URL
        $new_content = str_replace($duplicate_url, $original_url, $post->post_content);
        
        // Also replace attachment ID references
        $new_content = str_replace(
            'wp-image-' . $duplicate_id,
            'wp-image-' . $original_id,
            $new_content
        );
        
        // Update post if content changed
        if ($new_content !== $post->post_content) {
            $result = $wpdb->update(
                $wpdb->posts,
                ['post_content' => $new_content],
                ['ID' => $reference['reference_id']],
                ['%s'],
                ['%d']
            );
            
            return $result !== false;
        }
        
        return true;
    }
    
    /**
     * Replace reference in post meta
     */
    private function replace_in_post_meta($reference, $duplicate_id, $original_id, &$backup_data) {
        $backup_data['original_meta_value'] = $reference['meta_value'];
        
        // Handle different meta value formats
        if (is_numeric($reference['meta_value']) && $reference['meta_value'] == $duplicate_id) {
            // Direct attachment ID reference
            return update_post_meta($reference['reference_id'], $reference['field'], $original_id);
        } else {
            // URL or serialized data reference
            $duplicate_url = wp_get_attachment_url($duplicate_id);
            $original_url = wp_get_attachment_url($original_id);
            
            $new_value = str_replace($duplicate_url, $original_url, $reference['meta_value']);
            $new_value = str_replace($duplicate_id, $original_id, $new_value);
            
            if ($new_value !== $reference['meta_value']) {
                return update_post_meta($reference['reference_id'], $reference['field'], $new_value);
            }
        }
        
        return true;
    }
    
    /**
     * Replace reference in options
     */
    private function replace_in_option($reference, $duplicate_id, $original_id, &$backup_data) {
        $backup_data['original_option_value'] = $reference['option_value'];
        
        $duplicate_url = wp_get_attachment_url($duplicate_id);
        $original_url = wp_get_attachment_url($original_id);
        
        $new_value = str_replace($duplicate_url, $original_url, $reference['option_value']);
        $new_value = str_replace($duplicate_id, $original_id, $new_value);
        
        if ($new_value !== $reference['option_value']) {
            return update_option($reference['field'], $new_value);
        }
        
        return true;
    }
    
    /**
     * Replace reference in custom table
     */
    private function replace_in_custom_table($reference, $duplicate_id, $original_id, &$backup_data) {
        global $wpdb;
        
        $backup_data['original_row_data'] = $reference['row_data'];
        
        $table = $reference['table_name'];
        $column = $reference['field'];
        $primary_key = $this->get_primary_key_value($table, $reference['row_data']);
        $primary_key_column = $this->get_primary_key_column($table);
        
        if (!$primary_key || !$primary_key_column) {
            return false;
        }
        
        $duplicate_url = wp_get_attachment_url($duplicate_id);
        $original_url = wp_get_attachment_url($original_id);
        
        $current_value = $reference['row_data'][$column];
        $new_value = str_replace($duplicate_url, $original_url, $current_value);
        $new_value = str_replace($duplicate_id, $original_id, $new_value);
        
        if ($new_value !== $current_value) {
            $result = $wpdb->update(
                $table,
                [$column => $new_value],
                [$primary_key_column => $primary_key],
                ['%s'],
                ['%s']
            );
            
            return $result !== false;
        }
        
        return true;
    }
    
    /**
     * Replace featured image reference
     */
    private function replace_featured_image($reference, $duplicate_id, $original_id, &$backup_data) {
        $backup_data['original_thumbnail_id'] = get_post_meta($reference['reference_id'], '_thumbnail_id', true);
        
        return update_post_meta($reference['reference_id'], '_thumbnail_id', $original_id);
    }
    
    /**
     * Create backup of image before deletion
     */
    private function create_image_backup($attachment_id) {
        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            return false;
        }
        
        $upload_dir = wp_get_upload_dir();
        $backup_dir = trailingslashit($upload_dir['basedir']) . 'okvir-duplicate-detector-backups';
        
        if (!file_exists($backup_dir)) {
            wp_mkdir_p($backup_dir);
        }
        
        $backup_filename = $attachment_id . '_' . time() . '_' . basename($file_path);
        $backup_path = $backup_dir . '/' . $backup_filename;
        
        $success = copy($file_path, $backup_path);
        
        if ($success) {
            // Store backup information
            update_option('okvir_dup_backup_' . $attachment_id, [
                'original_path' => $file_path,
                'backup_path' => $backup_path,
                'created_at' => current_time('mysql'),
                'file_size' => filesize($file_path)
            ]);
        }
        
        return $success;
    }
    
    /**
     * Store references in database for tracking
     */
    private function store_references($attachment_id, $references) {
        global $wpdb;
        
        $references_table = $wpdb->prefix . 'okvir_content_references';
        
        // Clear existing references for this attachment
        $wpdb->delete($references_table, ['attachment_id' => $attachment_id], ['%d']);
        
        // Insert new references
        foreach ($references as $reference) {
            $wpdb->insert($references_table, [
                'attachment_id' => $attachment_id,
                'reference_type' => $reference['type'],
                'reference_id' => $reference['reference_id'],
                'reference_field' => $reference['field'],
                'reference_context' => $reference['context'],
                'url_found' => $reference['url_found']
            ], ['%d', '%s', '%d', '%s', '%s', '%s']);
        }
    }
    
    /**
     * Rollback replacements if deletion fails
     */
    private function rollback_replacements($replacements) {
        foreach ($replacements as $replacement) {
            if (!$replacement['success'] || empty($replacement['backup_data'])) {
                continue;
            }
            
            $reference = $replacement['reference'];
            $backup = $replacement['backup_data'];
            
            try {
                switch ($reference['type']) {
                    case 'post_content':
                        if (isset($backup['original_content'])) {
                            wp_update_post([
                                'ID' => $reference['reference_id'],
                                'post_content' => $backup['original_content']
                            ]);
                        }
                        break;
                        
                    case 'post_meta':
                    case 'post_meta_url':
                        if (isset($backup['original_meta_value'])) {
                            update_post_meta($reference['reference_id'], $reference['field'], $backup['original_meta_value']);
                        }
                        break;
                        
                    case 'option':
                        if (isset($backup['original_option_value'])) {
                            update_option($reference['field'], $backup['original_option_value']);
                        }
                        break;
                        
                    case 'featured_image':
                        if (isset($backup['original_thumbnail_id'])) {
                            update_post_meta($reference['reference_id'], '_thumbnail_id', $backup['original_thumbnail_id']);
                        }
                        break;
                }
            } catch (Exception $e) {
                error_log('Okvir Duplicate Detector: Rollback failed for reference - ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Utility functions
     */
    private function get_content_excerpt($content, $search_term, $context_length = 100) {
        $pos = stripos($content, $search_term);
        if ($pos === false) {
            return substr($content, 0, $context_length) . '...';
        }
        
        $start = max(0, $pos - $context_length / 2);
        $excerpt = substr($content, $start, $context_length);
        
        return ($start > 0 ? '...' : '') . $excerpt . '...';
    }
    
    private function get_primary_key_column($table) {
        global $wpdb;
        
        $keys = $wpdb->get_results("SHOW KEYS FROM {$table} WHERE Key_name = 'PRIMARY'", ARRAY_A);
        
        return !empty($keys) ? $keys[0]['Column_name'] : 'id';
    }
    
    private function get_primary_key_value($table, $row) {
        $pk_column = $this->get_primary_key_column($table);
        return $row[$pk_column] ?? null;
    }
    
    /**
     * Get reference statistics
     */
    public function get_reference_statistics($attachment_id) {
        global $wpdb;
        
        $references_table = $wpdb->prefix . 'okvir_content_references';
        
        $stats = $wpdb->get_results($wpdb->prepare(
            "SELECT reference_type, COUNT(*) as count
             FROM {$references_table}
             WHERE attachment_id = %d
             GROUP BY reference_type",
            $attachment_id
        ), ARRAY_A);
        
        $formatted_stats = [];
        foreach ($stats as $stat) {
            $formatted_stats[$stat['reference_type']] = (int) $stat['count'];
        }
        
        return $formatted_stats;
    }
    
    /**
     * Preview what would be replaced
     */
    public function preview_replacements($duplicate_attachment_ids) {
        $preview = [];
        
        foreach ($duplicate_attachment_ids as $duplicate_id) {
            $original_id = $this->find_original_for_duplicate($duplicate_id);
            if (!$original_id) {
                continue;
            }
            
            $references = $this->find_all_references($duplicate_id);
            $reference_stats = $this->get_reference_statistics($duplicate_id);
            
            $preview[] = [
                'duplicate_id' => $duplicate_id,
                'original_id' => $original_id,
                'total_references' => count($references),
                'reference_breakdown' => $reference_stats,
                'duplicate_file' => basename(get_attached_file($duplicate_id)),
                'original_file' => basename(get_attached_file($original_id))
            ];
        }
        
        return $preview;
    }
}
