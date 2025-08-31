<?php
/**
 * Perceptual Hash Algorithm
 * 
 * Generates perceptual hashes that can detect similar images even with minor
 * modifications like compression, slight resizing, or format changes.
 * Uses DCT (Discrete Cosine Transform) for robust image fingerprinting.
 */

if (!defined('ABSPATH')) {
    exit;
}

class OkvirDupDetector_PerceptualHash {
    
    const HASH_SIZE = 8; // 8x8 DCT grid for 64-bit hash
    const RESIZE_DIM = 32; // Resize to 32x32 for DCT processing
    
    /**
     * Generate signature for perceptual hash method
     */
    public function generate_signature($image_info) {
        try {
            if (!file_exists($image_info['file_path'])) {
                return [
                    'success' => false,
                    'error' => 'File does not exist'
                ];
            }
            
            // Load and preprocess image
            $preprocessed = $this->preprocess_image($image_info['file_path']);
            if (!$preprocessed) {
                return [
                    'success' => false,
                    'error' => 'Failed to preprocess image'
                ];
            }
            
            // Calculate DCT
            $dct_matrix = $this->calculate_dct($preprocessed);
            if (!$dct_matrix) {
                return [
                    'success' => false,
                    'error' => 'Failed to calculate DCT'
                ];
            }
            
            // Generate hash from DCT coefficients
            $hash = $this->generate_hash_from_dct($dct_matrix);
            
            // Convert to hex string
            $hash_string = $this->hash_to_string($hash);
            
            return [
                'success' => true,
                'signature' => $hash_string,
                'data' => [
                    'hash' => $hash,
                    'hash_string' => $hash_string,
                    'hash_bits' => self::HASH_SIZE * self::HASH_SIZE,
                    'dct_average' => $this->calculate_dct_average($dct_matrix),
                    'method' => 'perceptual_hash',
                    'generated_at' => time(),
                    'image_dimensions' => [
                        'original_width' => $image_info['width'],
                        'original_height' => $image_info['height'],
                        'processed_size' => self::RESIZE_DIM
                    ]
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
     * Preprocess image for perceptual hashing
     */
    private function preprocess_image($file_path) {
        // Create image resource based on file type
        $image_info = getimagesize($file_path);
        if (!$image_info) {
            return false;
        }
        
        switch ($image_info[2]) {
            case IMAGETYPE_JPEG:
                $source = imagecreatefromjpeg($file_path);
                break;
            case IMAGETYPE_PNG:
                $source = imagecreatefrompng($file_path);
                break;
            case IMAGETYPE_GIF:
                $source = imagecreatefromgif($file_path);
                break;
            case IMAGETYPE_WEBP:
                if (function_exists('imagecreatefromwebp')) {
                    $source = imagecreatefromwebp($file_path);
                } else {
                    return false;
                }
                break;
            default:
                return false;
        }
        
        if (!$source) {
            return false;
        }
        
        // Resize to standard size
        $resized = imagecreatetruecolor(self::RESIZE_DIM, self::RESIZE_DIM);
        if (!$resized) {
            imagedestroy($source);
            return false;
        }
        
        // Enable alpha blending for PNG transparency
        imagealphablending($resized, false);
        imagesavealphahandle($resized, true);
        
        // Resize with resampling
        $success = imagecopyresampled(
            $resized, $source, 
            0, 0, 0, 0,
            self::RESIZE_DIM, self::RESIZE_DIM,
            imagesx($source), imagesy($source)
        );
        
        imagedestroy($source);
        
        if (!$success) {
            imagedestroy($resized);
            return false;
        }
        
        // Convert to grayscale
        $grayscale = $this->convert_to_grayscale($resized);
        imagedestroy($resized);
        
        return $grayscale;
    }
    
    /**
     * Convert image to grayscale matrix
     */
    private function convert_to_grayscale($image) {
        $width = imagesx($image);
        $height = imagesy($image);
        $grayscale = [];
        
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                
                // Standard grayscale conversion
                $gray = (int)(0.299 * $r + 0.587 * $g + 0.114 * $b);
                $grayscale[$y][$x] = $gray;
            }
        }
        
        return $grayscale;
    }
    
    /**
     * Calculate Discrete Cosine Transform
     */
    private function calculate_dct($grayscale_matrix) {
        $size = self::RESIZE_DIM;
        $dct = [];
        
        // Initialize DCT matrix
        for ($u = 0; $u < $size; $u++) {
            for ($v = 0; $v < $size; $v++) {
                $dct[$u][$v] = 0;
            }
        }
        
        // Calculate DCT coefficients
        for ($u = 0; $u < $size; $u++) {
            for ($v = 0; $v < $size; $v++) {
                $sum = 0;
                
                for ($x = 0; $x < $size; $x++) {
                    for ($y = 0; $y < $size; $y++) {
                        $sum += $grayscale_matrix[$x][$y] * 
                                cos(((2 * $x + 1) * $u * M_PI) / (2 * $size)) * 
                                cos(((2 * $y + 1) * $v * M_PI) / (2 * $size));
                    }
                }
                
                // Apply normalization factors
                $cu = ($u == 0) ? (1 / sqrt(2)) : 1;
                $cv = ($v == 0) ? (1 / sqrt(2)) : 1;
                
                $dct[$u][$v] = (2 / $size) * $cu * $cv * $sum;
            }
        }
        
        return $dct;
    }
    
    /**
     * Generate hash from DCT coefficients
     */
    private function generate_hash_from_dct($dct_matrix) {
        // Extract top-left 8x8 coefficients (excluding DC component)
        $coefficients = [];
        for ($u = 0; $u < self::HASH_SIZE; $u++) {
            for ($v = 0; $v < self::HASH_SIZE; $v++) {
                if ($u == 0 && $v == 0) continue; // Skip DC component
                $coefficients[] = $dct_matrix[$u][$v];
            }
        }
        
        // Calculate median
        sort($coefficients);
        $median = $coefficients[count($coefficients) / 2];
        
        // Generate binary hash
        $hash = [];
        $bit_index = 0;
        
        for ($u = 0; $u < self::HASH_SIZE; $u++) {
            for ($v = 0; $v < self::HASH_SIZE; $v++) {
                if ($u == 0 && $v == 0) continue; // Skip DC component
                
                $hash[] = $dct_matrix[$u][$v] > $median ? 1 : 0;
                $bit_index++;
            }
        }
        
        return $hash;
    }
    
    /**
     * Convert hash array to string
     */
    private function hash_to_string($hash) {
        $hex_string = '';
        
        // Convert to hexadecimal
        for ($i = 0; $i < count($hash); $i += 4) {
            $nibble = 0;
            for ($j = 0; $j < 4 && ($i + $j) < count($hash); $j++) {
                if ($hash[$i + $j]) {
                    $nibble |= (1 << (3 - $j));
                }
            }
            $hex_string .= dechex($nibble);
        }
        
        return $hex_string;
    }
    
    /**
     * Calculate DCT average for metadata
     */
    private function calculate_dct_average($dct_matrix) {
        $sum = 0;
        $count = 0;
        
        for ($u = 0; $u < self::HASH_SIZE; $u++) {
            for ($v = 0; $v < self::HASH_SIZE; $v++) {
                if ($u == 0 && $v == 0) continue; // Skip DC component
                $sum += abs($dct_matrix[$u][$v]);
                $count++;
            }
        }
        
        return $count > 0 ? ($sum / $count) : 0;
    }
    
    /**
     * Calculate similarity between two perceptual hashes
     */
    public function calculate_similarity($signature1_data, $signature2_data) {
        if (!is_array($signature1_data) || !is_array($signature2_data)) {
            return 0.0;
        }
        
        if (!isset($signature1_data['hash']) || !isset($signature2_data['hash'])) {
            return 0.0;
        }
        
        $hash1 = $signature1_data['hash'];
        $hash2 = $signature2_data['hash'];
        
        if (count($hash1) !== count($hash2)) {
            return 0.0;
        }
        
        // Calculate Hamming distance
        $differences = 0;
        for ($i = 0; $i < count($hash1); $i++) {
            if ($hash1[$i] !== $hash2[$i]) {
                $differences++;
            }
        }
        
        // Convert to similarity percentage
        $total_bits = count($hash1);
        $similarity = (($total_bits - $differences) / $total_bits) * 100;
        
        return round($similarity, 2);
    }
    
    /**
     * Calculate Hamming distance between two hashes
     */
    public function hamming_distance($hash1, $hash2) {
        if (count($hash1) !== count($hash2)) {
            return -1;
        }
        
        $distance = 0;
        for ($i = 0; $i < count($hash1); $i++) {
            if ($hash1[$i] !== $hash2[$i]) {
                $distance++;
            }
        }
        
        return $distance;
    }
    
    /**
     * Get method information
     */
    public function get_method_info() {
        return [
            'name' => 'Perceptual Hash',
            'description' => 'DCT-based perceptual hashing for detecting similar images with minor modifications',
            'speed' => 'fast',
            'accuracy' => 'high',
            'memory_usage' => 'low',
            'tolerance' => 'compression, minor_resize, format_change',
            'detects_portions' => false,
            'transformation_resistant' => true
        ];
    }
    
    /**
     * Validate if method can process this image type
     */
    public function can_process($image_info) {
        if (!file_exists($image_info['file_path'])) {
            return false;
        }
        
        $image_info_data = getimagesize($image_info['file_path']);
        if (!$image_info_data) {
            return false;
        }
        
        $supported_types = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF];
        if (function_exists('imagecreatefromwebp')) {
            $supported_types[] = IMAGETYPE_WEBP;
        }
        
        return in_array($image_info_data[2], $supported_types);
    }
    
    /**
     * Get estimated processing time
     */
    public function estimate_processing_time($file_size) {
        // Medium speed - DCT calculation takes some time
        return max(0.1, $file_size / (10 * 1024 * 1024));
    }
    
    /**
     * Get estimated memory usage
     */
    public function estimate_memory_usage($image_info) {
        // Memory for resized image + DCT matrix + processing
        return (self::RESIZE_DIM * self::RESIZE_DIM * 4) + (1024 * 1024); // ~5MB
    }
    
    /**
     * Batch process multiple images with optimized memory usage
     */
    public function batch_generate_signatures($image_infos) {
        $results = [];
        
        // Process images one by one to conserve memory
        foreach ($image_infos as $image_info) {
            $result = $this->generate_signature($image_info);
            $results[] = $result;
            
            // Force garbage collection after each image to free memory
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
        
        return $results;
    }
    
    /**
     * Get configuration options for this method
     */
    public function get_config_options() {
        return [
            'hash_size' => [
                'label' => 'Hash Size',
                'type' => 'select',
                'options' => [
                    '8' => '8x8 (64-bit, fast)',
                    '16' => '16x16 (256-bit, more accurate)',
                    '32' => '32x32 (1024-bit, highest accuracy)'
                ],
                'default' => '8',
                'description' => 'Size of the hash matrix. Larger sizes are more accurate but slower.'
            ],
            'resize_dimension' => [
                'label' => 'Processing Size',
                'type' => 'number',
                'default' => 32,
                'min' => 16,
                'max' => 128,
                'description' => 'Size to resize images for processing. Larger sizes preserve more detail.'
            ],
            'similarity_threshold' => [
                'label' => 'Similarity Threshold (%)',
                'type' => 'number',
                'default' => 95,
                'min' => 70,
                'max' => 100,
                'description' => 'Minimum similarity percentage to consider images as duplicates'
            ],
            'enable_dct_optimization' => [
                'label' => 'DCT Optimization',
                'type' => 'boolean',
                'default' => true,
                'description' => 'Use optimized DCT calculation for better performance'
            ]
        ];
    }
}
