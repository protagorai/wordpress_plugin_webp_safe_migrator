<?php
/**
 * Image Analyzer Class
 * 
 * Coordinates the analysis of images using multiple detection algorithms
 * and manages the progressive processing approach.
 */

if (!defined('ABSPATH')) {
    exit;
}

class OkvirDupDetector_ImageAnalyzer {
    
    private $algorithms = [];
    private $settings = [];
    
    public function __construct() {
        $this->settings = OkvirDuplicateImageDetector::get_instance()->get_settings();
        $this->init_algorithms();
    }
    
    /**
     * Initialize detection algorithms
     */
    private function init_algorithms() {
        $this->algorithms = [
            OkvirDuplicateImageDetector::METHOD_FILE_HASH => new OkvirDupDetector_FileHash(),
            OkvirDuplicateImageDetector::METHOD_PERCEPTUAL_HASH => new OkvirDupDetector_PerceptualHash(),
            OkvirDuplicateImageDetector::METHOD_COLOR_HISTOGRAM => new OkvirDupDetector_ColorHistogram(),
            OkvirDuplicateImageDetector::METHOD_TEMPLATE_MATCH => new OkvirDupDetector_TemplateMatch(),
            OkvirDuplicateImageDetector::METHOD_KEYPOINT_MATCH => new OkvirDupDetector_KeypointMatch()
        ];
    }
    
    /**
     * Analyze a single image using progressive method approach
     */
    public function analyze_image($attachment_id) {
        $start_time = microtime(true);
        $start_memory = memory_get_usage(true);
        
        // Get image information
        $image_info = $this->get_image_info($attachment_id);
        if (!$image_info) {
            return $this->create_error_result($attachment_id, 'Failed to get image information');
        }
        
        // Check if image meets criteria
        if (!$this->validate_image($image_info)) {
            return $this->create_error_result($attachment_id, 'Image does not meet analysis criteria');
        }
        
        // Get enabled methods in order of computational cost
        $enabled_methods = $this->get_enabled_methods_ordered();
        if (empty($enabled_methods)) {
            return $this->create_error_result($attachment_id, 'No detection methods enabled');
        }
        
        $results = [];
        $signatures = [];
        $matches_found = [];
        $processing_log = [];
        
        // Progressive analysis - stop early if enough matches found
        foreach ($enabled_methods as $method) {
            $method_start = microtime(true);
            
            try {
                // Generate signature for this method
                $signature_result = $this->algorithms[$method]->generate_signature($image_info);
                
                if ($signature_result['success']) {
                    $signatures[$method] = $signature_result;
                    
                    // Look for matches using this signature
                    $matches = $this->find_matches($method, $signature_result['signature'], $signature_result['data']);
                    
                    if (!empty($matches)) {
                        $matches_found[$method] = $matches;
                    }
                    
                    // Log successful processing
                    $processing_log[] = [
                        'method' => $method,
                        'status' => 'success',
                        'execution_time' => microtime(true) - $method_start,
                        'signature_generated' => true,
                        'matches_found' => count($matches ?? [])
                    ];
                    
                } else {
                    // Log failed processing
                    $processing_log[] = [
                        'method' => $method,
                        'status' => 'failed',
                        'execution_time' => microtime(true) - $method_start,
                        'error' => $signature_result['error'] ?? 'Unknown error'
                    ];
                }
                
                // Early termination if we have enough confirming methods
                if ($this->has_sufficient_matches($matches_found)) {
                    break;
                }
                
            } catch (Exception $e) {
                $processing_log[] = [
                    'method' => $method,
                    'status' => 'exception',
                    'execution_time' => microtime(true) - $method_start,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        // Calculate overall analysis score and determine duplicates
        $analysis_result = $this->calculate_analysis_result($matches_found, $signatures);
        
        // Store results in database
        $analysis_id = $this->store_analysis_results($attachment_id, $image_info, $signatures, $analysis_result);
        
        // Log processing details
        $this->log_processing_details($attachment_id, $processing_log);
        
        return [
            'success' => true,
            'analysis_id' => $analysis_id,
            'attachment_id' => $attachment_id,
            'signatures_generated' => count($signatures),
            'methods_processed' => array_keys($signatures),
            'matches_found' => $matches_found,
            'analysis_score' => $analysis_result['score'],
            'is_duplicate' => $analysis_result['is_duplicate'],
            'execution_time' => microtime(true) - $start_time,
            'memory_used' => memory_get_usage(true) - $start_memory,
            'processing_log' => $processing_log
        ];
    }
    
    /**
     * Get image information for analysis
     */
    private function get_image_info($attachment_id) {
        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            return false;
        }
        
        $image_size = @getimagesize($file_path);
        if (!$image_size) {
            return false;
        }
        
        $file_size = filesize($file_path);
        $mime_type = get_post_mime_type($attachment_id);
        
        return [
            'attachment_id' => $attachment_id,
            'file_path' => $file_path,
            'file_size' => $file_size,
            'width' => $image_size[0],
            'height' => $image_size[1],
            'mime_type' => $mime_type,
            'file_hash' => md5_file($file_path)
        ];
    }
    
    /**
     * Validate if image meets analysis criteria
     */
    private function validate_image($image_info) {
        // Check file size limits
        if ($image_info['file_size'] < $this->settings['min_file_size']) {
            return false;
        }
        
        if ($image_info['file_size'] > $this->settings['max_file_size']) {
            return false;
        }
        
        // Check if image type is enabled
        $file_ext = strtolower(pathinfo($image_info['file_path'], PATHINFO_EXTENSION));
        if (!in_array($file_ext, $this->settings['image_types'])) {
            return false;
        }
        
        // Check minimum dimensions (avoid tiny images)
        if ($image_info['width'] < 10 || $image_info['height'] < 10) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Get enabled methods ordered by computational cost (fastest first)
     */
    private function get_enabled_methods_ordered() {
        $method_order = [
            OkvirDuplicateImageDetector::METHOD_FILE_HASH,
            OkvirDuplicateImageDetector::METHOD_PERCEPTUAL_HASH,
            OkvirDuplicateImageDetector::METHOD_COLOR_HISTOGRAM,
            OkvirDuplicateImageDetector::METHOD_TEMPLATE_MATCH,
            OkvirDuplicateImageDetector::METHOD_KEYPOINT_MATCH
        ];
        
        $enabled = [];
        foreach ($method_order as $method) {
            if (!empty($this->settings['enabled_methods'][$method])) {
                $enabled[] = $method;
            }
        }
        
        return $enabled;
    }
    
    /**
     * Find matches for a given signature
     */
    private function find_matches($method, $signature_hash, $signature_data = null) {
        global $wpdb;
        
        $table_signatures = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_IMAGE_SIGNATURES;
        $table_analysis = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_IMAGE_ANALYSIS;
        $table_links = $wpdb->prefix . 'okvir_image_signature_links';
        
        $matches = [];
        
        if ($method === OkvirDuplicateImageDetector::METHOD_FILE_HASH) {
            // Exact hash match
            $existing = $wpdb->get_results($wpdb->prepare(
                "SELECT s.*, l.analysis_id, a.attachment_id 
                 FROM {$table_signatures} s
                 LEFT JOIN {$table_links} l ON s.id = l.signature_id
                 LEFT JOIN {$table_analysis} a ON l.analysis_id = a.id
                 WHERE s.method = %s AND s.signature_hash = %s",
                $method, $signature_hash
            ), ARRAY_A);
            
            foreach ($existing as $match) {
                $matches[] = [
                    'signature_id' => $match['id'],
                    'analysis_id' => $match['analysis_id'],
                    'attachment_id' => $match['attachment_id'],
                    'similarity_score' => 100.0,
                    'method' => $method
                ];
            }
            
        } else {
            // Similarity-based matching for other methods
            $threshold = $this->settings['similarity_threshold'][$method] ?? 80;
            $matches = $this->find_similarity_matches($method, $signature_hash, $signature_data, $threshold);
        }
        
        return $matches;
    }
    
    /**
     * Find similarity matches for advanced algorithms
     */
    private function find_similarity_matches($method, $signature_hash, $signature_data, $threshold) {
        global $wpdb;
        
        $table_signatures = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_IMAGE_SIGNATURES;
        $table_analysis = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_IMAGE_ANALYSIS;
        $table_links = $wpdb->prefix . 'okvir_image_signature_links';
        
        // Get all signatures for this method
        $existing_signatures = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, l.analysis_id, a.attachment_id 
             FROM {$table_signatures} s
             LEFT JOIN {$table_links} l ON s.id = l.signature_id
             LEFT JOIN {$table_analysis} a ON l.analysis_id = a.id
             WHERE s.method = %s",
            $method
        ), ARRAY_A);
        
        $matches = [];
        
        foreach ($existing_signatures as $existing) {
            $similarity = $this->algorithms[$method]->calculate_similarity(
                $signature_data, 
                json_decode($existing['signature_data'], true)
            );
            
            if ($similarity >= $threshold) {
                $matches[] = [
                    'signature_id' => $existing['id'],
                    'analysis_id' => $existing['analysis_id'],
                    'attachment_id' => $existing['attachment_id'],
                    'similarity_score' => $similarity,
                    'method' => $method
                ];
            }
        }
        
        return $matches;
    }
    
    /**
     * Check if we have sufficient matching methods
     */
    private function has_sufficient_matches($matches_found) {
        if (count($matches_found) < OkvirDuplicateImageDetector::MIN_MATCH_METHODS) {
            return false;
        }
        
        // Check if matches are consistent across methods
        $all_matches = [];
        foreach ($matches_found as $method_matches) {
            foreach ($method_matches as $match) {
                $attachment_id = $match['attachment_id'];
                if (!isset($all_matches[$attachment_id])) {
                    $all_matches[$attachment_id] = [];
                }
                $all_matches[$attachment_id][] = $match['method'];
            }
        }
        
        // Find attachments matched by multiple methods
        foreach ($all_matches as $attachment_id => $methods) {
            if (count($methods) >= OkvirDuplicateImageDetector::MIN_MATCH_METHODS) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Calculate overall analysis result
     */
    private function calculate_analysis_result($matches_found, $signatures) {
        $is_duplicate = false;
        $total_score = 0;
        $method_count = 0;
        $confirmed_duplicates = [];
        
        // Analyze matches for consistency
        $attachment_matches = [];
        foreach ($matches_found as $method => $method_matches) {
            foreach ($method_matches as $match) {
                $attachment_id = $match['attachment_id'];
                if (!isset($attachment_matches[$attachment_id])) {
                    $attachment_matches[$attachment_id] = [];
                }
                $attachment_matches[$attachment_id][$method] = $match['similarity_score'];
            }
        }
        
        // Find confirmed duplicates (matched by multiple methods)
        foreach ($attachment_matches as $attachment_id => $method_scores) {
            if (count($method_scores) >= OkvirDuplicateImageDetector::MIN_MATCH_METHODS) {
                $avg_score = array_sum($method_scores) / count($method_scores);
                $confirmed_duplicates[$attachment_id] = [
                    'methods' => array_keys($method_scores),
                    'scores' => $method_scores,
                    'average_score' => $avg_score
                ];
                
                $total_score += $avg_score;
                $method_count++;
                $is_duplicate = true;
            }
        }
        
        $analysis_score = $method_count > 0 ? ($total_score / $method_count) : 0;
        
        return [
            'is_duplicate' => $is_duplicate,
            'score' => round($analysis_score, 2),
            'confirmed_duplicates' => $confirmed_duplicates,
            'methods_processed' => array_keys($signatures),
            'total_methods' => count($signatures)
        ];
    }
    
    /**
     * Store analysis results in database
     */
    private function store_analysis_results($attachment_id, $image_info, $signatures, $analysis_result) {
        global $wpdb;
        
        $table_analysis = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_IMAGE_ANALYSIS;
        $table_signatures = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_IMAGE_SIGNATURES;
        $table_links = $wpdb->prefix . 'okvir_image_signature_links';
        
        // Store image analysis record
        $wpdb->insert($table_analysis, [
            'attachment_id' => $attachment_id,
            'file_path' => $image_info['file_path'],
            'file_hash' => $image_info['file_hash'],
            'file_size' => $image_info['file_size'],
            'image_width' => $image_info['width'],
            'image_height' => $image_info['height'],
            'mime_type' => $image_info['mime_type'],
            'methods_processed' => json_encode($analysis_result['methods_processed']),
            'processing_status' => $analysis_result['is_duplicate'] ? 'duplicate' : 'unique',
            'analysis_score' => $analysis_result['score']
        ], [
            '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%f'
        ]);
        
        $analysis_id = $wpdb->insert_id;
        
        // Store signatures and create links
        foreach ($signatures as $method => $signature_result) {
            // Insert or get existing signature
            $existing_signature = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table_signatures} WHERE method = %s AND signature_hash = %s",
                $method, $signature_result['signature']
            ));
            
            if ($existing_signature) {
                $signature_id = $existing_signature;
            } else {
                $wpdb->insert($table_signatures, [
                    'method' => $method,
                    'signature_hash' => $signature_result['signature'],
                    'signature_data' => json_encode($signature_result['data'])
                ], ['%s', '%s', '%s']);
                
                $signature_id = $wpdb->insert_id;
            }
            
            // Create link between analysis and signature
            $wpdb->insert($table_links, [
                'analysis_id' => $analysis_id,
                'signature_id' => $signature_id,
                'similarity_score' => 100.0 // Self-similarity is always 100%
            ], ['%d', '%d', '%f']);
        }
        
        return $analysis_id;
    }
    
    /**
     * Log processing details
     */
    private function log_processing_details($attachment_id, $processing_log) {
        global $wpdb;
        
        $table_log = $wpdb->prefix . 'okvir_processing_log';
        
        foreach ($processing_log as $log_entry) {
            $wpdb->insert($table_log, [
                'attachment_id' => $attachment_id,
                'method' => $log_entry['method'],
                'status' => $log_entry['status'],
                'execution_time' => $log_entry['execution_time'],
                'memory_usage' => memory_get_usage(true),
                'error_message' => $log_entry['error'] ?? null,
                'debug_data' => json_encode($log_entry)
            ], ['%d', '%s', '%s', '%f', '%d', '%s', '%s']);
        }
    }
    
    /**
     * Create error result
     */
    private function create_error_result($attachment_id, $error_message) {
        return [
            'success' => false,
            'attachment_id' => $attachment_id,
            'error' => $error_message
        ];
    }
    
    /**
     * Get analysis results for an attachment
     */
    public function get_analysis_results($attachment_id) {
        global $wpdb;
        
        $table_analysis = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_IMAGE_ANALYSIS;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_analysis} WHERE attachment_id = %d",
            $attachment_id
        ), ARRAY_A);
    }
    
    /**
     * Check if attachment has been analyzed
     */
    public function is_analyzed($attachment_id) {
        global $wpdb;
        
        $table_analysis = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_IMAGE_ANALYSIS;
        
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table_analysis} WHERE attachment_id = %d",
            $attachment_id
        ));
        
        return !empty($exists);
    }
    
    /**
     * Re-analyze an image (useful when settings change)
     */
    public function reanalyze_image($attachment_id) {
        // Remove existing analysis
        $this->remove_analysis($attachment_id);
        
        // Perform fresh analysis
        return $this->analyze_image($attachment_id);
    }
    
    /**
     * Remove analysis data for an attachment
     */
    public function remove_analysis($attachment_id) {
        global $wpdb;
        
        $table_analysis = $wpdb->prefix . OkvirDuplicateImageDetector::TABLE_IMAGE_ANALYSIS;
        
        // Get analysis ID first
        $analysis_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table_analysis} WHERE attachment_id = %d",
            $attachment_id
        ));
        
        if ($analysis_id) {
            // Delete from analysis table (cascade will handle related records)
            $wpdb->delete($table_analysis, ['id' => $analysis_id], ['%d']);
        }
        
        return true;
    }
}
