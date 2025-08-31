<?php
/**
 * Duplicate Detector Class
 * 
 * Manages duplicate detection logic, grouping of duplicate images,
 * and coordination between different detection methods.
 */

if (!defined('ABSPATH')) {
    exit;
}

class OkvirDupDetector_DuplicateDetector {
    
    private $settings;
    
    public function __construct() {
        $this->settings = OkvirDuplicateImageDetector::get_instance()->get_settings();
    }
    
    /**
     * Process potential duplicate and create/update groups
     */
    public function process_duplicate($analysis_result) {
        if (!$analysis_result['is_duplicate'] || empty($analysis_result['confirmed_duplicates'])) {
            return ['duplicates_found' => 0];
        }
        
        $duplicates_found = 0;
        
        foreach ($analysis_result['confirmed_duplicates'] as $duplicate_attachment_id => $duplicate_info) {
            // Find or create duplicate group
            $group_id = $this->find_or_create_group($analysis_result['attachment_id'], $duplicate_attachment_id, $duplicate_info);
            
            if ($group_id) {
                $duplicates_found++;
            }
        }
        
        return ['duplicates_found' => $duplicates_found];
    }
    
    /**
     * Find existing group or create new one
     */
    private function find_or_create_group($new_attachment_id, $existing_attachment_id, $duplicate_info) {
        global $wpdb;
        
        $groups_table = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_DUPLICATE_GROUPS;
        $analysis_table = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_IMAGE_ANALYSIS;
        $members_table = $wpdb->prefix . 'okvir_duplicate_group_members';
        
        // Check if existing attachment is already in a group
        $existing_group = $wpdb->get_row($wpdb->prepare(
            "SELECT g.*, gm.group_id 
             FROM {$members_table} gm
             LEFT JOIN {$groups_table} g ON gm.group_id = g.id
             WHERE gm.analysis_id = (
                 SELECT id FROM {$analysis_table} WHERE attachment_id = %d
             ) AND g.status = 'active'",
            $existing_attachment_id
        ), ARRAY_A);
        
        if ($existing_group) {
            // Add new image to existing group
            return $this->add_to_existing_group($existing_group['id'], $new_attachment_id, $duplicate_info);
        } else {
            // Create new group with both images
            return $this->create_new_group($new_attachment_id, $existing_attachment_id, $duplicate_info);
        }
    }
    
    /**
     * Add image to existing duplicate group
     */
    private function add_to_existing_group($group_id, $new_attachment_id, $duplicate_info) {
        global $wpdb;
        
        $groups_table = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_DUPLICATE_GROUPS;
        $analysis_table = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_IMAGE_ANALYSIS;
        $members_table = $wpdb->prefix . 'okvir_duplicate_group_members';
        
        // Get analysis ID for new attachment
        $analysis_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$analysis_table} WHERE attachment_id = %d",
            $new_attachment_id
        ));
        
        if (!$analysis_id) {
            return false;
        }
        
        // Check if already a member
        $existing_member = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$members_table} WHERE group_id = %d AND analysis_id = %d",
            $group_id, $analysis_id
        ));
        
        if ($existing_member) {
            return $group_id; // Already a member
        }
        
        // Get current group info
        $group = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$groups_table} WHERE id = %d",
            $group_id
        ), ARRAY_A);
        
        if (!$group) {
            return false;
        }
        
        // Get file size for new member
        $file_size = $wpdb->get_var($wpdb->prepare(
            "SELECT file_size FROM {$analysis_table} WHERE id = %d",
            $analysis_id
        ));
        
        // Add to group members
        $wpdb->insert($members_table, [
            'group_id' => $group_id,
            'analysis_id' => $analysis_id,
            'is_original' => 0,
            'duplicate_rank' => $group['duplicate_count'] + 1
        ], ['%d', '%d', '%d', '%d']);
        
        // Update group statistics
        $new_methods = array_unique(array_merge(
            json_decode($group['methods_matched'], true) ?: [],
            $duplicate_info['methods']
        ));
        
        $new_confidence = ($group['confidence_score'] + $duplicate_info['average_score']) / 2;
        
        $wpdb->update($groups_table, [
            'duplicate_count' => $group['duplicate_count'] + 1,
            'total_file_size' => $group['total_file_size'] + $file_size,
            'methods_matched' => json_encode($new_methods),
            'confidence_score' => $new_confidence
        ], ['id' => $group_id], ['%d', '%d', '%s', '%f'], ['%d']);
        
        return $group_id;
    }
    
    /**
     * Create new duplicate group
     */
    private function create_new_group($new_attachment_id, $original_attachment_id, $duplicate_info) {
        global $wpdb;
        
        $groups_table = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_DUPLICATE_GROUPS;
        $analysis_table = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_IMAGE_ANALYSIS;
        $members_table = $wpdb->prefix . 'okvir_duplicate_group_members';
        
        // Get analysis IDs
        $original_analysis = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$analysis_table} WHERE attachment_id = %d",
            $original_attachment_id
        ), ARRAY_A);
        
        $new_analysis = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$analysis_table} WHERE attachment_id = %d",
            $new_attachment_id
        ), ARRAY_A);
        
        if (!$original_analysis || !$new_analysis) {
            return false;
        }
        
        // Determine which is the original (prefer older, larger, or better quality)
        $is_new_original = $this->determine_original($original_analysis, $new_analysis);
        
        if ($is_new_original) {
            $original_analysis_id = $new_analysis['id'];
            $duplicate_analysis_id = $original_analysis['id'];
        } else {
            $original_analysis_id = $original_analysis['id'];
            $duplicate_analysis_id = $new_analysis['id'];
        }
        
        // Create group hash
        $group_hash = hash('sha256', $original_analysis_id . '_' . time());
        
        // Insert group
        $wpdb->insert($groups_table, [
            'group_hash' => $group_hash,
            'original_analysis_id' => $original_analysis_id,
            'duplicate_count' => 1,
            'total_file_size' => $new_analysis['file_size'],
            'methods_matched' => json_encode($duplicate_info['methods']),
            'confidence_score' => $duplicate_info['average_score'],
            'status' => 'active'
        ], ['%s', '%d', '%d', '%d', '%s', '%f', '%s']);
        
        $group_id = $wpdb->insert_id;
        
        if (!$group_id) {
            return false;
        }
        
        // Add original to group
        $wpdb->insert($members_table, [
            'group_id' => $group_id,
            'analysis_id' => $original_analysis_id,
            'is_original' => 1,
            'duplicate_rank' => 0
        ], ['%d', '%d', '%d', '%d']);
        
        // Add duplicate to group
        $wpdb->insert($members_table, [
            'group_id' => $group_id,
            'analysis_id' => $duplicate_analysis_id,
            'is_original' => 0,
            'duplicate_rank' => 1
        ], ['%d', '%d', '%d', '%d']);
        
        return $group_id;
    }
    
    /**
     * Determine which image should be the original
     */
    private function determine_original($analysis1, $analysis2) {
        // Priority factors (in order):
        // 1. Larger file size (better quality)
        // 2. Larger dimensions
        // 3. Older attachment (first upload)
        
        // Compare file sizes
        if ($analysis1['file_size'] !== $analysis2['file_size']) {
            return $analysis2['file_size'] > $analysis1['file_size'];
        }
        
        // Compare dimensions
        $area1 = $analysis1['image_width'] * $analysis1['image_height'];
        $area2 = $analysis2['image_width'] * $analysis2['image_height'];
        
        if ($area1 !== $area2) {
            return $area2 > $area1;
        }
        
        // Compare attachment IDs (lower = older)
        return $analysis2['attachment_id'] < $analysis1['attachment_id'];
    }
    
    /**
     * Get all duplicate groups
     */
    public function get_duplicate_groups($page = 1, $per_page = 20, $filters = []) {
        $db_manager = new OkvirDupDetector_DatabaseManager();
        return $db_manager->get_duplicate_groups($page, $per_page, $filters);
    }
    
    /**
     * Get group members
     */
    public function get_group_members($group_id) {
        $db_manager = new OkvirDupDetector_DatabaseManager();
        return $db_manager->get_group_members($group_id);
    }
    
    /**
     * Mark group as processed
     */
    public function mark_group_processed($group_id) {
        global $wpdb;
        
        $groups_table = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_DUPLICATE_GROUPS;
        
        return $wpdb->update(
            $groups_table,
            ['status' => 'processed'],
            ['id' => $group_id],
            ['%s'],
            ['%d']
        );
    }
    
    /**
     * Delete duplicate group
     */
    public function delete_group($group_id) {
        global $wpdb;
        
        $groups_table = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_DUPLICATE_GROUPS;
        
        // Cascading delete will handle members
        return $wpdb->delete($groups_table, ['id' => $group_id], ['%d']);
    }
    
    /**
     * Recalculate group statistics
     */
    public function recalculate_group_stats($group_id) {
        global $wpdb;
        
        $groups_table = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_DUPLICATE_GROUPS;
        $analysis_table = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_IMAGE_ANALYSIS;
        $members_table = $wpdb->prefix . 'okvir_duplicate_group_members';
        
        // Get current group members
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as member_count,
                SUM(a.file_size) as total_size
             FROM {$members_table} gm
             LEFT JOIN {$analysis_table} a ON gm.analysis_id = a.id
             WHERE gm.group_id = %d",
            $group_id
        ), ARRAY_A);
        
        if ($stats) {
            $wpdb->update($groups_table, [
                'duplicate_count' => max(0, $stats['member_count'] - 1), // Subtract original
                'total_file_size' => (int) $stats['total_size']
            ], ['id' => $group_id], ['%d', '%d'], ['%d']);
        }
        
        return true;
    }
    
    /**
     * Find similar images for a given attachment
     */
    public function find_similar_images($attachment_id, $similarity_threshold = 80) {
        global $wpdb;
        
        $analysis_table = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_IMAGE_ANALYSIS;
        $signatures_table = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_IMAGE_SIGNATURES;
        $links_table = $wpdb->prefix . 'okvir_image_signature_links';
        
        // Get current image's signatures
        $current_signatures = $wpdb->get_results($wpdb->prepare(
            "SELECT s.method, s.signature_hash, s.signature_data, l.similarity_score
             FROM {$signatures_table} s
             LEFT JOIN {$links_table} l ON s.id = l.signature_id
             LEFT JOIN {$analysis_table} a ON l.analysis_id = a.id
             WHERE a.attachment_id = %d",
            $attachment_id
        ), ARRAY_A);
        
        if (empty($current_signatures)) {
            return [];
        }
        
        $similar_images = [];
        
        // For each signature method, find similar images
        foreach ($current_signatures as $signature) {
            $method = $signature['method'];
            $signature_data = json_decode($signature['signature_data'], true);
            
            // Get all other signatures for this method
            $other_signatures = $wpdb->get_results($wpdb->prepare(
                "SELECT s.*, l.analysis_id, a.attachment_id, a.file_path
                 FROM {$signatures_table} s
                 LEFT JOIN {$links_table} l ON s.id = l.signature_id
                 LEFT JOIN {$analysis_table} a ON l.analysis_id = a.id
                 WHERE s.method = %s 
                 AND a.attachment_id != %d",
                $method, $attachment_id
            ), ARRAY_A);
            
            // Calculate similarities
            foreach ($other_signatures as $other_sig) {
                $other_data = json_decode($other_sig['signature_data'], true);
                
                // Load appropriate algorithm
                $algorithm = $this->get_algorithm($method);
                if (!$algorithm) {
                    continue;
                }
                
                $similarity = $algorithm->calculate_similarity($signature_data, $other_data);
                
                if ($similarity >= $similarity_threshold) {
                    $key = $other_sig['attachment_id'];
                    
                    if (!isset($similar_images[$key])) {
                        $similar_images[$key] = [
                            'attachment_id' => $other_sig['attachment_id'],
                            'file_path' => $other_sig['file_path'],
                            'methods' => [],
                            'scores' => [],
                            'average_score' => 0
                        ];
                    }
                    
                    $similar_images[$key]['methods'][] = $method;
                    $similar_images[$key]['scores'][$method] = $similarity;
                }
            }
        }
        
        // Calculate average scores and filter by minimum methods
        foreach ($similar_images as $key => &$image) {
            $image['average_score'] = array_sum($image['scores']) / count($image['scores']);
            
            // Require minimum methods agreement
            if (count($image['methods']) < OkvirDuplicateImageDetector::MIN_MATCH_METHODS) {
                unset($similar_images[$key]);
            }
        }
        
        // Sort by average score descending
        uasort($similar_images, function($a, $b) {
            return $b['average_score'] <=> $a['average_score'];
        });
        
        return array_values($similar_images);
    }
    
    /**
     * Get algorithm instance
     */
    private function get_algorithm($method) {
        switch ($method) {
            case OkvirDuplicateImageDetector::METHOD_FILE_HASH:
                return new OkvirDupDetector_FileHash();
            case OkvirDuplicateImageDetector::METHOD_PERCEPTUAL_HASH:
                return new OkvirDupDetector_PerceptualHash();
            case OkvirDuplicateImageDetector::METHOD_COLOR_HISTOGRAM:
                return new OkvirDupDetector_ColorHistogram();
            case OkvirDuplicateImageDetector::METHOD_TEMPLATE_MATCH:
                return new OkvirDupDetector_TemplateMatch();
            case OkvirDuplicateImageDetector::METHOD_KEYPOINT_MATCH:
                return new OkvirDupDetector_KeypointMatch();
            default:
                return null;
        }
    }
    
    /**
     * Merge two duplicate groups
     */
    public function merge_groups($group_id1, $group_id2) {
        global $wpdb;
        
        $groups_table = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_DUPLICATE_GROUPS;
        $members_table = $wpdb->prefix . 'okvir_duplicate_group_members';
        
        // Get both groups
        $group1 = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$groups_table} WHERE id = %d", $group_id1), ARRAY_A);
        $group2 = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$groups_table} WHERE id = %d", $group_id2), ARRAY_A);
        
        if (!$group1 || !$group2) {
            return false;
        }
        
        // Determine which group should be the primary (higher confidence)
        $primary_group = $group1['confidence_score'] >= $group2['confidence_score'] ? $group1 : $group2;
        $secondary_group = $group1['confidence_score'] >= $group2['confidence_score'] ? $group2 : $group1;
        
        // Move all members from secondary to primary group
        $wpdb->update($members_table, [
            'group_id' => $primary_group['id']
        ], [
            'group_id' => $secondary_group['id']
        ], ['%d'], ['%d']);
        
        // Update primary group statistics
        $combined_methods = array_unique(array_merge(
            json_decode($primary_group['methods_matched'], true) ?: [],
            json_decode($secondary_group['methods_matched'], true) ?: []
        ));
        
        $combined_confidence = ($primary_group['confidence_score'] + $secondary_group['confidence_score']) / 2;
        $combined_size = $primary_group['total_file_size'] + $secondary_group['total_file_size'];
        $combined_count = $primary_group['duplicate_count'] + $secondary_group['duplicate_count'];
        
        $wpdb->update($groups_table, [
            'duplicate_count' => $combined_count,
            'total_file_size' => $combined_size,
            'methods_matched' => json_encode($combined_methods),
            'confidence_score' => $combined_confidence
        ], ['id' => $primary_group['id']], ['%d', '%d', '%s', '%f'], ['%d']);
        
        // Delete secondary group
        $wpdb->delete($groups_table, ['id' => $secondary_group['id']], ['%d']);
        
        return $primary_group['id'];
    }
    
    /**
     * Split duplicate group
     */
    public function split_group($group_id, $member_ids_to_split) {
        global $wpdb;
        
        if (empty($member_ids_to_split)) {
            return false;
        }
        
        $groups_table = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_DUPLICATE_GROUPS;
        $members_table = $wpdb->prefix . 'okvir_duplicate_group_members';
        
        // Create new group for split members
        $original_group = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$groups_table} WHERE id = %d",
            $group_id
        ), ARRAY_A);
        
        if (!$original_group) {
            return false;
        }
        
        // Create new group
        $new_group_hash = hash('sha256', $group_id . '_split_' . time());
        
        $wpdb->insert($groups_table, [
            'group_hash' => $new_group_hash,
            'original_analysis_id' => $member_ids_to_split[0], // First split member becomes original
            'duplicate_count' => count($member_ids_to_split) - 1,
            'total_file_size' => 0, // Will be recalculated
            'methods_matched' => $original_group['methods_matched'],
            'confidence_score' => $original_group['confidence_score'],
            'status' => 'active'
        ], ['%s', '%d', '%d', '%d', '%s', '%f', '%s']);
        
        $new_group_id = $wpdb->insert_id;
        
        // Move specified members to new group
        foreach ($member_ids_to_split as $index => $analysis_id) {
            $wpdb->update($members_table, [
                'group_id' => $new_group_id,
                'is_original' => $index === 0 ? 1 : 0,
                'duplicate_rank' => $index
            ], [
                'analysis_id' => $analysis_id
            ], ['%d', '%d', '%d'], ['%d']);
        }
        
        // Recalculate statistics for both groups
        $this->recalculate_group_stats($group_id);
        $this->recalculate_group_stats($new_group_id);
        
        return $new_group_id;
    }
    
    /**
     * Get duplicate detection summary
     */
    public function get_detection_summary() {
        global $wpdb;
        
        $groups_table = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_DUPLICATE_GROUPS;
        $analysis_table = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_IMAGE_ANALYSIS;
        
        $summary = $wpdb->get_row(
            "SELECT 
                COUNT(g.id) as total_groups,
                SUM(g.duplicate_count) as total_duplicates,
                SUM(g.total_file_size) as potential_savings_bytes,
                AVG(g.confidence_score) as average_confidence,
                MAX(g.confidence_score) as max_confidence,
                MIN(g.confidence_score) as min_confidence
             FROM {$groups_table} g
             WHERE g.status = 'active'",
            ARRAY_A
        );
        
        $method_breakdown = $wpdb->get_results(
            "SELECT 
                SUBSTRING_INDEX(SUBSTRING_INDEX(methods_matched, '\"', 2), '\"', -1) as method,
                COUNT(*) as group_count
             FROM {$groups_table}
             WHERE status = 'active'
             AND methods_matched IS NOT NULL
             GROUP BY method",
            ARRAY_A
        );
        
        return [
            'summary' => $summary,
            'method_breakdown' => $method_breakdown,
            'potential_savings_formatted' => size_format($summary['potential_savings_bytes'] ?? 0)
        ];
    }
}
