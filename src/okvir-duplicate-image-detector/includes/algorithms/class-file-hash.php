<?php
/**
 * File Hash Algorithm
 * 
 * Simple and fast exact duplicate detection using MD5/SHA256 file hashing.
 * This is the fastest method but only detects byte-for-byte identical files.
 */

if (!defined('ABSPATH')) {
    exit;
}

class OkvirDupDetector_FileHash {
    
    /**
     * Generate signature for file hash method
     */
    public function generate_signature($image_info) {
        try {
            if (!file_exists($image_info['file_path'])) {
                return [
                    'success' => false,
                    'error' => 'File does not exist'
                ];
            }
            
            // Generate MD5 hash of file content
            $md5_hash = md5_file($image_info['file_path']);
            if ($md5_hash === false) {
                return [
                    'success' => false,
                    'error' => 'Failed to generate MD5 hash'
                ];
            }
            
            // Also generate SHA256 for better security/uniqueness
            $sha256_hash = hash_file('sha256', $image_info['file_path']);
            if ($sha256_hash === false) {
                return [
                    'success' => false,
                    'error' => 'Failed to generate SHA256 hash'
                ];
            }
            
            // Use combined hash as primary signature
            $combined_hash = hash('sha256', $md5_hash . $sha256_hash);
            
            return [
                'success' => true,
                'signature' => $combined_hash,
                'data' => [
                    'md5' => $md5_hash,
                    'sha256' => $sha256_hash,
                    'combined' => $combined_hash,
                    'file_size' => $image_info['file_size'],
                    'method' => 'file_hash',
                    'generated_at' => time()
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Exception: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Calculate similarity between two signatures
     * For file hash, it's either 100% match or 0% match
     */
    public function calculate_similarity($signature1_data, $signature2_data) {
        if (!is_array($signature1_data) || !is_array($signature2_data)) {
            return 0.0;
        }
        
        // Check if any of the hashes match
        $matches = [
            'md5' => false,
            'sha256' => false,
            'combined' => false
        ];
        
        foreach (['md5', 'sha256', 'combined'] as $hash_type) {
            if (isset($signature1_data[$hash_type]) && isset($signature2_data[$hash_type])) {
                if ($signature1_data[$hash_type] === $signature2_data[$hash_type]) {
                    $matches[$hash_type] = true;
                }
            }
        }
        
        // If all available hashes match, it's 100% similarity
        if ($matches['combined'] || ($matches['md5'] && $matches['sha256'])) {
            return 100.0;
        }
        
        return 0.0;
    }
    
    /**
     * Get method information
     */
    public function get_method_info() {
        return [
            'name' => 'File Hash',
            'description' => 'Exact file content matching using MD5 and SHA256 hashes',
            'speed' => 'very_fast',
            'accuracy' => 'exact',
            'memory_usage' => 'very_low',
            'tolerance' => 'none',
            'detects_portions' => false,
            'transformation_resistant' => false
        ];
    }
    
    /**
     * Validate if method can process this image type
     */
    public function can_process($image_info) {
        // File hash can process any file type
        return file_exists($image_info['file_path']) && is_readable($image_info['file_path']);
    }
    
    /**
     * Get estimated processing time
     */
    public function estimate_processing_time($file_size) {
        // Very fast - roughly 50MB/second on average hardware
        return max(0.001, $file_size / (50 * 1024 * 1024));
    }
    
    /**
     * Get estimated memory usage
     */
    public function estimate_memory_usage($image_info) {
        // File hash uses minimal memory - just buffer for file reading
        return 1024 * 1024; // 1MB buffer
    }
    
    /**
     * Batch process multiple files (optimized version)
     */
    public function batch_generate_signatures($image_infos) {
        $results = [];
        
        foreach ($image_infos as $image_info) {
            $results[] = $this->generate_signature($image_info);
        }
        
        return $results;
    }
    
    /**
     * Quick duplicate check without full signature generation
     */
    public function quick_duplicate_check($file_path1, $file_path2) {
        if (!file_exists($file_path1) || !file_exists($file_path2)) {
            return false;
        }
        
        // Quick file size check first
        if (filesize($file_path1) !== filesize($file_path2)) {
            return false;
        }
        
        // Compare MD5 hashes
        $hash1 = md5_file($file_path1);
        $hash2 = md5_file($file_path2);
        
        return $hash1 === $hash2;
    }
    
    /**
     * Find potential duplicates in a batch of files
     */
    public function find_batch_duplicates($image_infos) {
        $hashes = [];
        $duplicates = [];
        
        foreach ($image_infos as $index => $image_info) {
            if (!file_exists($image_info['file_path'])) {
                continue;
            }
            
            $md5 = md5_file($image_info['file_path']);
            if ($md5 === false) {
                continue;
            }
            
            $key = $md5 . '_' . $image_info['file_size'];
            
            if (isset($hashes[$key])) {
                // Duplicate found
                if (!isset($duplicates[$key])) {
                    $duplicates[$key] = [
                        'original_index' => $hashes[$key],
                        'duplicates' => []
                    ];
                }
                $duplicates[$key]['duplicates'][] = $index;
            } else {
                $hashes[$key] = $index;
            }
        }
        
        return $duplicates;
    }
    
    /**
     * Verify file integrity
     */
    public function verify_file_integrity($image_info, $expected_hash = null) {
        if (!file_exists($image_info['file_path'])) {
            return [
                'valid' => false,
                'error' => 'File does not exist'
            ];
        }
        
        $current_hash = md5_file($image_info['file_path']);
        
        if ($expected_hash) {
            return [
                'valid' => $current_hash === $expected_hash,
                'current_hash' => $current_hash,
                'expected_hash' => $expected_hash
            ];
        }
        
        return [
            'valid' => $current_hash !== false,
            'hash' => $current_hash
        ];
    }
    
    /**
     * Get configuration options for this method
     */
    public function get_config_options() {
        return [
            'hash_algorithms' => [
                'label' => 'Hash Algorithms',
                'type' => 'multiselect',
                'options' => [
                    'md5' => 'MD5 (fast)',
                    'sha256' => 'SHA256 (secure)',
                    'combined' => 'Combined (recommended)'
                ],
                'default' => ['md5', 'sha256', 'combined'],
                'description' => 'Select which hash algorithms to use for comparison'
            ],
            'enable_quick_size_check' => [
                'label' => 'Quick Size Check',
                'type' => 'boolean',
                'default' => true,
                'description' => 'Perform file size check before hash comparison for speed'
            ],
            'chunk_size' => [
                'label' => 'Chunk Size (KB)',
                'type' => 'number',
                'default' => 1024,
                'min' => 64,
                'max' => 8192,
                'description' => 'Size of chunks to read when processing large files'
            ]
        ];
    }
}
