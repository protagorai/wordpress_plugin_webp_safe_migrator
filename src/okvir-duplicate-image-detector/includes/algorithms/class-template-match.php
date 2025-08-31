<?php
/**
 * Template Matching Algorithm
 * 
 * Uses normalized cross-correlation to detect similar images and portions
 * of images. Good for finding cropped versions or images embedded within
 * larger images. More computationally expensive but can detect partial matches.
 */

if (!defined('ABSPATH')) {
    exit;
}

class OkvirDupDetector_TemplateMatch {
    
    const MAX_TEMPLATE_SIZE = 512; // Maximum template size for memory efficiency
    const MIN_TEMPLATE_SIZE = 64;  // Minimum useful template size
    const MULTI_SCALE_LEVELS = 5;   // Number of scale levels to test
    const ROTATION_ANGLES = [0, 90, 180, 270]; // Rotation angles to test
    
    /**
     * Generate signature for template matching method
     */
    public function generate_signature($image_info) {
        try {
            if (!file_exists($image_info['file_path'])) {
                return [
                    'success' => false,
                    'error' => 'File does not exist'
                ];
            }
            
            // Load image
            $image = $this->load_image($image_info['file_path']);
            if (!$image) {
                return [
                    'success' => false,
                    'error' => 'Failed to load image'
                ];
            }
            
            // Create templates at different scales
            $templates = $this->create_multi_scale_templates($image);
            
            // Extract feature descriptors for each template
            $descriptors = [];
            foreach ($templates as $scale => $template_data) {
                $descriptors[$scale] = $this->extract_template_features($template_data['image']);
                // Clean up template image
                imagedestroy($template_data['image']);
            }
            
            imagedestroy($image);
            
            // Create compact signature
            $signature = $this->create_template_signature($descriptors);
            
            return [
                'success' => true,
                'signature' => hash('sha256', serialize($signature)),
                'data' => [
                    'templates' => $descriptors,
                    'signature_compact' => $signature,
                    'scales_generated' => array_keys($descriptors),
                    'method' => 'template_match',
                    'generated_at' => time(),
                    'image_dimensions' => [
                        'original_width' => $image_info['width'],
                        'original_height' => $image_info['height'],
                        'template_sizes' => array_column($templates, 'size')
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
     * Load image from file
     */
    private function load_image($file_path) {
        $image_info = getimagesize($file_path);
        if (!$image_info) {
            return false;
        }
        
        switch ($image_info[2]) {
            case IMAGETYPE_JPEG:
                return imagecreatefromjpeg($file_path);
            case IMAGETYPE_PNG:
                return imagecreatefrompng($file_path);
            case IMAGETYPE_GIF:
                return imagecreatefromgif($file_path);
            case IMAGETYPE_WEBP:
                if (function_exists('imagecreatefromwebp')) {
                    return imagecreatefromwebp($file_path);
                }
                break;
        }
        
        return false;
    }
    
    /**
     * Create templates at multiple scales
     */
    private function create_multi_scale_templates($source_image) {
        $width = imagesx($source_image);
        $height = imagesy($source_image);
        $templates = [];
        
        // Generate templates at different scales
        for ($level = 0; $level < self::MULTI_SCALE_LEVELS; $level++) {
            $scale_factor = pow(0.8, $level); // Each level is 80% of previous
            $new_width = max(self::MIN_TEMPLATE_SIZE, (int)($width * $scale_factor));
            $new_height = max(self::MIN_TEMPLATE_SIZE, (int)($height * $scale_factor));
            
            // Don't exceed maximum template size
            if ($new_width > self::MAX_TEMPLATE_SIZE || $new_height > self::MAX_TEMPLATE_SIZE) {
                $ratio = min(self::MAX_TEMPLATE_SIZE / $new_width, self::MAX_TEMPLATE_SIZE / $new_height);
                $new_width = (int)($new_width * $ratio);
                $new_height = (int)($new_height * $ratio);
            }
            
            // Create scaled template
            $template = imagecreatetruecolor($new_width, $new_height);
            if (!$template) {
                continue;
            }
            
            // Preserve transparency
            imagealphablending($template, false);
            imagesavealphahandle($template, true);
            
            // Resize with high quality resampling
            $success = imagecopyresampled(
                $template, $source_image,
                0, 0, 0, 0,
                $new_width, $new_height,
                $width, $height
            );
            
            if ($success) {
                $templates["scale_{$level}"] = [
                    'image' => $template,
                    'width' => $new_width,
                    'height' => $new_height,
                    'scale_factor' => $scale_factor,
                    'level' => $level,
                    'size' => $new_width * $new_height
                ];
            } else {
                imagedestroy($template);
            }
        }
        
        return $templates;
    }
    
    /**
     * Extract feature descriptors from template
     */
    private function extract_template_features($template) {
        $width = imagesx($template);
        $height = imagesy($template);
        
        $features = [
            'dimensions' => [$width, $height],
            'intensity_features' => $this->extract_intensity_features($template),
            'edge_features' => $this->extract_edge_features($template),
            'corner_features' => $this->extract_corner_features($template),
            'texture_features' => $this->extract_texture_features($template)
        ];
        
        return $features;
    }
    
    /**
     * Extract intensity-based features
     */
    private function extract_intensity_features($image) {
        $width = imagesx($image);
        $height = imagesy($image);
        $total_pixels = $width * $height;
        
        $intensities = [];
        $sum = 0;
        
        // Convert to grayscale and collect intensities
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                
                $gray = (int)(0.299 * $r + 0.587 * $g + 0.114 * $b);
                $intensities[] = $gray;
                $sum += $gray;
            }
        }
        
        $mean = $sum / $total_pixels;
        
        // Calculate variance
        $variance = 0;
        foreach ($intensities as $intensity) {
            $variance += pow($intensity - $mean, 2);
        }
        $variance /= $total_pixels;
        
        // Create histogram
        $histogram = array_fill(0, 256, 0);
        foreach ($intensities as $intensity) {
            $histogram[$intensity]++;
        }
        
        // Normalize histogram
        for ($i = 0; $i < 256; $i++) {
            $histogram[$i] /= $total_pixels;
        }
        
        return [
            'mean' => $mean,
            'variance' => $variance,
            'std_dev' => sqrt($variance),
            'histogram' => $histogram,
            'min' => min($intensities),
            'max' => max($intensities)
        ];
    }
    
    /**
     * Extract edge features using Sobel operator
     */
    private function extract_edge_features($image) {
        $width = imagesx($image);
        $height = imagesy($image);
        
        // Sobel kernels
        $sobel_x = [[-1, 0, 1], [-2, 0, 2], [-1, 0, 1]];
        $sobel_y = [[-1, -2, -1], [0, 0, 0], [1, 2, 1]];
        
        $edge_magnitude = [];
        $edge_direction = [];
        
        // Convert to grayscale first
        $gray = $this->convert_to_grayscale_array($image);
        
        for ($x = 1; $x < $width - 1; $x++) {
            for ($y = 1; $y < $height - 1; $y++) {
                $gx = 0;
                $gy = 0;
                
                // Apply Sobel kernels
                for ($i = -1; $i <= 1; $i++) {
                    for ($j = -1; $j <= 1; $j++) {
                        $pixel = $gray[$x + $i][$y + $j];
                        $gx += $pixel * $sobel_x[$i + 1][$j + 1];
                        $gy += $pixel * $sobel_y[$i + 1][$j + 1];
                    }
                }
                
                $magnitude = sqrt($gx * $gx + $gy * $gy);
                $direction = atan2($gy, $gx);
                
                $edge_magnitude[] = $magnitude;
                $edge_direction[] = $direction;
            }
        }
        
        // Calculate edge statistics
        $mean_magnitude = array_sum($edge_magnitude) / count($edge_magnitude);
        $max_magnitude = max($edge_magnitude);
        
        // Edge direction histogram (8 bins)
        $direction_histogram = array_fill(0, 8, 0);
        foreach ($edge_direction as $dir) {
            $bin = (int)((($dir + M_PI) / (2 * M_PI)) * 8) % 8;
            $direction_histogram[$bin]++;
        }
        
        // Normalize direction histogram
        $total_edges = count($edge_direction);
        for ($i = 0; $i < 8; $i++) {
            $direction_histogram[$i] /= $total_edges;
        }
        
        return [
            'mean_magnitude' => $mean_magnitude,
            'max_magnitude' => $max_magnitude,
            'direction_histogram' => $direction_histogram,
            'edge_density' => count(array_filter($edge_magnitude, function($m) { return $m > 30; })) / count($edge_magnitude)
        ];
    }
    
    /**
     * Extract corner features using Harris corner detector
     */
    private function extract_corner_features($image) {
        $width = imagesx($image);
        $height = imagesy($image);
        
        // Convert to grayscale
        $gray = $this->convert_to_grayscale_array($image);
        
        // Calculate gradients
        $ix = [];
        $iy = [];
        $ixy = [];
        
        for ($x = 1; $x < $width - 1; $x++) {
            for ($y = 1; $y < $height - 1; $y++) {
                $dx = ($gray[$x + 1][$y] - $gray[$x - 1][$y]) / 2;
                $dy = ($gray[$x][$y + 1] - $gray[$x][$y - 1]) / 2;
                
                $ix[$x][$y] = $dx * $dx;
                $iy[$x][$y] = $dy * $dy;
                $ixy[$x][$y] = $dx * $dy;
            }
        }
        
        // Apply Gaussian window and calculate Harris response
        $corners = [];
        $window_size = 3;
        $k = 0.04; // Harris parameter
        
        for ($x = $window_size; $x < $width - $window_size; $x++) {
            for ($y = $window_size; $y < $height - $window_size; $y++) {
                $sum_ix = 0;
                $sum_iy = 0;
                $sum_ixy = 0;
                
                // Sum over window
                for ($i = -$window_size; $i <= $window_size; $i++) {
                    for ($j = -$window_size; $j <= $window_size; $j++) {
                        $sum_ix += $ix[$x + $i][$y + $j] ?? 0;
                        $sum_iy += $iy[$x + $i][$y + $j] ?? 0;
                        $sum_ixy += $ixy[$x + $i][$y + $j] ?? 0;
                    }
                }
                
                // Calculate Harris response
                $det = $sum_ix * $sum_iy - $sum_ixy * $sum_ixy;
                $trace = $sum_ix + $sum_iy;
                $response = $det - $k * $trace * $trace;
                
                if ($response > 1000) { // Threshold for corner detection
                    $corners[] = [$x, $y, $response];
                }
            }
        }
        
        // Sort corners by response strength
        usort($corners, function($a, $b) {
            return $b[2] <=> $a[2];
        });
        
        // Keep top corners
        $corners = array_slice($corners, 0, 50);
        
        return [
            'corner_count' => count($corners),
            'corners' => $corners,
            'corner_density' => count($corners) / ($width * $height)
        ];
    }
    
    /**
     * Extract texture features using Local Binary Patterns
     */
    private function extract_texture_features($image) {
        $width = imagesx($image);
        $height = imagesy($image);
        
        // Convert to grayscale
        $gray = $this->convert_to_grayscale_array($image);
        
        // Calculate LBP
        $lbp_histogram = array_fill(0, 256, 0);
        
        for ($x = 1; $x < $width - 1; $x++) {
            for ($y = 1; $y < $height - 1; $y++) {
                $center = $gray[$x][$y];
                $lbp = 0;
                
                // 8-connected neighbors
                $neighbors = [
                    $gray[$x - 1][$y - 1], $gray[$x - 1][$y], $gray[$x - 1][$y + 1],
                    $gray[$x][$y + 1], $gray[$x + 1][$y + 1], $gray[$x + 1][$y],
                    $gray[$x + 1][$y - 1], $gray[$x][$y - 1]
                ];
                
                for ($i = 0; $i < 8; $i++) {
                    if ($neighbors[$i] >= $center) {
                        $lbp |= (1 << $i);
                    }
                }
                
                $lbp_histogram[$lbp]++;
            }
        }
        
        // Normalize histogram
        $total = array_sum($lbp_histogram);
        if ($total > 0) {
            for ($i = 0; $i < 256; $i++) {
                $lbp_histogram[$i] /= $total;
            }
        }
        
        return [
            'lbp_histogram' => $lbp_histogram,
            'texture_energy' => array_sum(array_map(function($x) { return $x * $x; }, $lbp_histogram)),
            'texture_entropy' => -array_sum(array_map(function($x) { return $x > 0 ? $x * log($x, 2) : 0; }, $lbp_histogram))
        ];
    }
    
    /**
     * Convert image to grayscale array
     */
    private function convert_to_grayscale_array($image) {
        $width = imagesx($image);
        $height = imagesy($image);
        $gray = [];
        
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                
                $gray[$x][$y] = (int)(0.299 * $r + 0.587 * $g + 0.114 * $b);
            }
        }
        
        return $gray;
    }
    
    /**
     * Create compact signature from template descriptors
     */
    private function create_template_signature($descriptors) {
        $signature = [];
        
        foreach ($descriptors as $scale => $features) {
            $signature[$scale] = [
                'dimensions' => $features['dimensions'],
                'intensity_mean' => $features['intensity_features']['mean'],
                'intensity_variance' => $features['intensity_features']['variance'],
                'edge_density' => $features['edge_features']['edge_density'],
                'corner_count' => $features['corner_features']['corner_count'],
                'texture_energy' => $features['texture_features']['texture_energy'],
                'feature_hash' => hash('sha256', serialize($features))
            ];
        }
        
        return $signature;
    }
    
    /**
     * Calculate similarity between two template signatures
     */
    public function calculate_similarity($signature1_data, $signature2_data) {
        if (!is_array($signature1_data) || !is_array($signature2_data)) {
            return 0.0;
        }
        
        if (!isset($signature1_data['templates']) || !isset($signature2_data['templates'])) {
            return 0.0;
        }
        
        $templates1 = $signature1_data['templates'];
        $templates2 = $signature2_data['templates'];
        
        $best_similarities = [];
        
        // Compare each template in signature1 with each template in signature2
        foreach ($templates1 as $scale1 => $features1) {
            $scale_similarities = [];
            
            foreach ($templates2 as $scale2 => $features2) {
                $similarity = $this->compare_template_features($features1, $features2);
                $scale_similarities[] = $similarity;
            }
            
            // Take the best match for this scale
            $best_similarities[] = max($scale_similarities);
        }
        
        // Return average of best similarities
        return count($best_similarities) > 0 ? array_sum($best_similarities) / count($best_similarities) : 0.0;
    }
    
    /**
     * Compare two sets of template features
     */
    private function compare_template_features($features1, $features2) {
        $similarities = [];
        
        // Compare intensity features
        $intensity_sim = $this->compare_intensity_features($features1['intensity_features'], $features2['intensity_features']);
        $similarities['intensity'] = $intensity_sim;
        
        // Compare edge features
        $edge_sim = $this->compare_edge_features($features1['edge_features'], $features2['edge_features']);
        $similarities['edge'] = $edge_sim;
        
        // Compare corner features
        $corner_sim = $this->compare_corner_features($features1['corner_features'], $features2['corner_features']);
        $similarities['corner'] = $corner_sim;
        
        // Compare texture features
        $texture_sim = $this->compare_texture_features($features1['texture_features'], $features2['texture_features']);
        $similarities['texture'] = $texture_sim;
        
        // Weighted average
        $weights = [
            'intensity' => 0.2,
            'edge' => 0.3,
            'corner' => 0.3,
            'texture' => 0.2
        ];
        
        $weighted_sum = 0;
        foreach ($similarities as $type => $sim) {
            $weighted_sum += $sim * $weights[$type];
        }
        
        return round($weighted_sum, 2);
    }
    
    /**
     * Compare intensity features
     */
    private function compare_intensity_features($features1, $features2) {
        $mean_diff = abs($features1['mean'] - $features2['mean']) / 255;
        $var_diff = abs($features1['variance'] - $features2['variance']) / (255 * 255);
        
        // Compare histograms
        $hist_correlation = $this->calculate_histogram_correlation($features1['histogram'], $features2['histogram']);
        
        $mean_sim = 1 - $mean_diff;
        $var_sim = 1 - $var_diff;
        
        return ($mean_sim * 0.3 + $var_sim * 0.2 + $hist_correlation * 0.5) * 100;
    }
    
    /**
     * Compare edge features
     */
    private function compare_edge_features($features1, $features2) {
        $magnitude_diff = abs($features1['mean_magnitude'] - $features2['mean_magnitude']);
        $max_magnitude = max($features1['mean_magnitude'], $features2['mean_magnitude'], 1);
        $magnitude_sim = 1 - ($magnitude_diff / $max_magnitude);
        
        $density_diff = abs($features1['edge_density'] - $features2['edge_density']);
        $density_sim = 1 - $density_diff;
        
        // Compare direction histograms
        $direction_correlation = $this->calculate_histogram_correlation($features1['direction_histogram'], $features2['direction_histogram']);
        
        return ($magnitude_sim * 0.3 + $density_sim * 0.3 + $direction_correlation * 0.4) * 100;
    }
    
    /**
     * Compare corner features
     */
    private function compare_corner_features($features1, $features2) {
        $count_diff = abs($features1['corner_count'] - $features2['corner_count']);
        $max_count = max($features1['corner_count'], $features2['corner_count'], 1);
        $count_sim = 1 - ($count_diff / $max_count);
        
        $density_diff = abs($features1['corner_density'] - $features2['corner_density']);
        $density_sim = 1 - $density_diff;
        
        return ($count_sim * 0.5 + $density_sim * 0.5) * 100;
    }
    
    /**
     * Compare texture features
     */
    private function compare_texture_features($features1, $features2) {
        // Compare LBP histograms
        $lbp_correlation = $this->calculate_histogram_correlation($features1['lbp_histogram'], $features2['lbp_histogram']);
        
        // Compare texture energy
        $energy_diff = abs($features1['texture_energy'] - $features2['texture_energy']);
        $max_energy = max($features1['texture_energy'], $features2['texture_energy'], 0.001);
        $energy_sim = 1 - ($energy_diff / $max_energy);
        
        return ($lbp_correlation * 0.7 + $energy_sim * 0.3) * 100;
    }
    
    /**
     * Calculate histogram correlation
     */
    private function calculate_histogram_correlation($hist1, $hist2) {
        if (count($hist1) !== count($hist2)) {
            return 0.0;
        }
        
        $mean1 = array_sum($hist1) / count($hist1);
        $mean2 = array_sum($hist2) / count($hist2);
        
        $numerator = 0;
        $sum_sq1 = 0;
        $sum_sq2 = 0;
        
        for ($i = 0; $i < count($hist1); $i++) {
            $diff1 = $hist1[$i] - $mean1;
            $diff2 = $hist2[$i] - $mean2;
            
            $numerator += $diff1 * $diff2;
            $sum_sq1 += $diff1 * $diff1;
            $sum_sq2 += $diff2 * $diff2;
        }
        
        $denominator = sqrt($sum_sq1 * $sum_sq2);
        
        return $denominator > 0 ? $numerator / $denominator : 0.0;
    }
    
    /**
     * Get method information
     */
    public function get_method_info() {
        return [
            'name' => 'Template Matching',
            'description' => 'Multi-scale template matching with feature descriptors',
            'speed' => 'slow',
            'accuracy' => 'very_high',
            'memory_usage' => 'high',
            'tolerance' => 'scale, rotation, minor_modifications',
            'detects_portions' => true,
            'transformation_resistant' => true
        ];
    }
    
    /**
     * Validate if method can process this image type
     */
    public function can_process($image_info) {
        return $this->load_image($image_info['file_path']) !== false;
    }
    
    /**
     * Get estimated processing time
     */
    public function estimate_processing_time($file_size) {
        // Slow - complex feature extraction
        return max(2.0, $file_size / (1024 * 1024));
    }
    
    /**
     * Get estimated memory usage
     */
    public function estimate_memory_usage($image_info) {
        // High memory usage for templates and feature extraction
        $base_memory = self::MAX_TEMPLATE_SIZE * self::MAX_TEMPLATE_SIZE * 4; // Base template
        $multi_scale_memory = $base_memory * self::MULTI_SCALE_LEVELS;
        $feature_memory = 1024 * 1024; // 1MB for features
        
        return $multi_scale_memory + $feature_memory;
    }
    
    /**
     * Get configuration options for this method
     */
    public function get_config_options() {
        return [
            'max_template_size' => [
                'label' => 'Maximum Template Size',
                'type' => 'number',
                'default' => 512,
                'min' => 128,
                'max' => 1024,
                'description' => 'Maximum size for template processing'
            ],
            'scale_levels' => [
                'label' => 'Multi-scale Levels',
                'type' => 'number',
                'default' => 5,
                'min' => 3,
                'max' => 8,
                'description' => 'Number of scale levels for template matching'
            ],
            'corner_threshold' => [
                'label' => 'Corner Detection Threshold',
                'type' => 'number',
                'default' => 1000,
                'min' => 100,
                'max' => 5000,
                'description' => 'Threshold for Harris corner detection'
            ],
            'enable_rotation_invariance' => [
                'label' => 'Rotation Invariance',
                'type' => 'boolean',
                'default' => false,
                'description' => 'Test multiple rotation angles (slower but more robust)'
            ]
        ];
    }
}
