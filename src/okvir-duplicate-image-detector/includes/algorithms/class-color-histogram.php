<?php
/**
 * Color Histogram Algorithm
 * 
 * Analyzes color distribution in images to detect duplicates.
 * Good for detecting images with similar color composition even
 * with different positioning or minor modifications.
 */

if (!defined('ABSPATH')) {
    exit;
}

class OkvirDupDetector_ColorHistogram {
    
    const RGB_BINS = 64; // Number of bins per RGB channel (64^3 = 262,144 total bins)
    const HSV_BINS = 32; // Number of bins per HSV channel for additional analysis
    const RESIZE_DIM = 256; // Resize to standard size for consistent analysis
    
    /**
     * Generate signature for color histogram method
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
            $image = $this->load_image($image_info['file_path']);
            if (!$image) {
                return [
                    'success' => false,
                    'error' => 'Failed to load image'
                ];
            }
            
            // Resize to standard dimensions for consistent analysis
            $resized = $this->resize_image($image);
            imagedestroy($image);
            
            if (!$resized) {
                return [
                    'success' => false,
                    'error' => 'Failed to resize image'
                ];
            }
            
            // Calculate RGB histogram
            $rgb_histogram = $this->calculate_rgb_histogram($resized);
            
            // Calculate HSV histogram
            $hsv_histogram = $this->calculate_hsv_histogram($resized);
            
            // Calculate additional color features
            $color_features = $this->calculate_color_features($resized);
            
            imagedestroy($resized);
            
            // Normalize histograms
            $normalized_rgb = $this->normalize_histogram($rgb_histogram);
            $normalized_hsv = $this->normalize_histogram($hsv_histogram);
            
            // Create compact signature
            $signature = $this->create_signature($normalized_rgb, $normalized_hsv, $color_features);
            
            return [
                'success' => true,
                'signature' => hash('sha256', serialize($signature)),
                'data' => [
                    'rgb_histogram' => $normalized_rgb,
                    'hsv_histogram' => $normalized_hsv,
                    'color_features' => $color_features,
                    'signature_compact' => $signature,
                    'total_pixels' => self::RESIZE_DIM * self::RESIZE_DIM,
                    'method' => 'color_histogram',
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
     * Resize image to standard dimensions
     */
    private function resize_image($source) {
        $width = imagesx($source);
        $height = imagesy($source);
        
        $resized = imagecreatetruecolor(self::RESIZE_DIM, self::RESIZE_DIM);
        if (!$resized) {
            return false;
        }
        
        // Preserve transparency for PNG/GIF
        imagealphablending($resized, false);
        imagesavealphahandle($resized, true);
        
        $success = imagecopyresampled(
            $resized, $source,
            0, 0, 0, 0,
            self::RESIZE_DIM, self::RESIZE_DIM,
            $width, $height
        );
        
        return $success ? $resized : false;
    }
    
    /**
     * Calculate RGB histogram
     */
    private function calculate_rgb_histogram($image) {
        $width = imagesx($image);
        $height = imagesy($image);
        $histogram = [];
        
        // Initialize histogram bins
        for ($r = 0; $r < self::RGB_BINS; $r++) {
            for ($g = 0; $g < self::RGB_BINS; $g++) {
                for ($b = 0; $b < self::RGB_BINS; $b++) {
                    $histogram[$r][$g][$b] = 0;
                }
            }
        }
        
        // Calculate histogram
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                
                // Map to bins
                $r_bin = min(self::RGB_BINS - 1, (int)($r * self::RGB_BINS / 256));
                $g_bin = min(self::RGB_BINS - 1, (int)($g * self::RGB_BINS / 256));
                $b_bin = min(self::RGB_BINS - 1, (int)($b * self::RGB_BINS / 256));
                
                $histogram[$r_bin][$g_bin][$b_bin]++;
            }
        }
        
        return $histogram;
    }
    
    /**
     * Calculate HSV histogram
     */
    private function calculate_hsv_histogram($image) {
        $width = imagesx($image);
        $height = imagesy($image);
        $histogram = [];
        
        // Initialize histogram bins
        for ($h = 0; $h < self::HSV_BINS; $h++) {
            for ($s = 0; $s < self::HSV_BINS; $s++) {
                for ($v = 0; $v < self::HSV_BINS; $v++) {
                    $histogram[$h][$s][$v] = 0;
                }
            }
        }
        
        // Calculate histogram
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $rgb = imagecolorat($image, $x, $y);
                $r = (($rgb >> 16) & 0xFF) / 255.0;
                $g = (($rgb >> 8) & 0xFF) / 255.0;
                $b = ($rgb & 0xFF) / 255.0;
                
                // Convert RGB to HSV
                $hsv = $this->rgb_to_hsv($r, $g, $b);
                
                // Map to bins
                $h_bin = min(self::HSV_BINS - 1, (int)($hsv[0] * self::HSV_BINS / 360));
                $s_bin = min(self::HSV_BINS - 1, (int)($hsv[1] * self::HSV_BINS));
                $v_bin = min(self::HSV_BINS - 1, (int)($hsv[2] * self::HSV_BINS));
                
                $histogram[$h_bin][$s_bin][$v_bin]++;
            }
        }
        
        return $histogram;
    }
    
    /**
     * Convert RGB to HSV
     */
    private function rgb_to_hsv($r, $g, $b) {
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $diff = $max - $min;
        
        // Value
        $v = $max;
        
        // Saturation
        $s = ($max == 0) ? 0 : ($diff / $max);
        
        // Hue
        if ($diff == 0) {
            $h = 0;
        } elseif ($max == $r) {
            $h = 60 * (($g - $b) / $diff);
            if ($h < 0) $h += 360;
        } elseif ($max == $g) {
            $h = 60 * (2 + ($b - $r) / $diff);
        } else { // $max == $b
            $h = 60 * (4 + ($r - $g) / $diff);
        }
        
        return [$h, $s, $v];
    }
    
    /**
     * Calculate additional color features
     */
    private function calculate_color_features($image) {
        $width = imagesx($image);
        $height = imagesy($image);
        $total_pixels = $width * $height;
        
        $features = [
            'mean_rgb' => [0, 0, 0],
            'std_rgb' => [0, 0, 0],
            'dominant_colors' => [],
            'color_variance' => 0,
            'brightness_average' => 0,
            'contrast_measure' => 0
        ];
        
        // First pass: calculate means
        $sum_r = $sum_g = $sum_b = 0;
        $brightness_sum = 0;
        
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                
                $sum_r += $r;
                $sum_g += $g;
                $sum_b += $b;
                
                $brightness_sum += ($r + $g + $b) / 3;
            }
        }
        
        $features['mean_rgb'] = [
            $sum_r / $total_pixels,
            $sum_g / $total_pixels,
            $sum_b / $total_pixels
        ];
        
        $features['brightness_average'] = $brightness_sum / $total_pixels;
        
        // Second pass: calculate standard deviations and variance
        $var_r = $var_g = $var_b = 0;
        $contrast_sum = 0;
        
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                
                $var_r += pow($r - $features['mean_rgb'][0], 2);
                $var_g += pow($g - $features['mean_rgb'][1], 2);
                $var_b += pow($b - $features['mean_rgb'][2], 2);
                
                $brightness = ($r + $g + $b) / 3;
                $contrast_sum += pow($brightness - $features['brightness_average'], 2);
            }
        }
        
        $features['std_rgb'] = [
            sqrt($var_r / $total_pixels),
            sqrt($var_g / $total_pixels),
            sqrt($var_b / $total_pixels)
        ];
        
        $features['contrast_measure'] = sqrt($contrast_sum / $total_pixels);
        $features['color_variance'] = ($features['std_rgb'][0] + $features['std_rgb'][1] + $features['std_rgb'][2]) / 3;
        
        return $features;
    }
    
    /**
     * Normalize histogram
     */
    private function normalize_histogram($histogram) {
        $total = 0;
        $normalized = [];
        
        // Calculate total count
        foreach ($histogram as $channel1) {
            foreach ($channel1 as $channel2) {
                foreach ($channel2 as $count) {
                    $total += $count;
                }
            }
        }
        
        if ($total == 0) {
            return $histogram;
        }
        
        // Normalize
        foreach ($histogram as $i => $channel1) {
            foreach ($channel1 as $j => $channel2) {
                foreach ($channel2 as $k => $count) {
                    $normalized[$i][$j][$k] = $count / $total;
                }
            }
        }
        
        return $normalized;
    }
    
    /**
     * Create compact signature from histograms
     */
    private function create_signature($rgb_hist, $hsv_hist, $features) {
        $signature = [];
        
        // Extract key features from RGB histogram
        $rgb_features = $this->extract_histogram_features($rgb_hist);
        $hsv_features = $this->extract_histogram_features($hsv_hist);
        
        $signature['rgb_features'] = $rgb_features;
        $signature['hsv_features'] = $hsv_features;
        $signature['color_features'] = $features;
        
        return $signature;
    }
    
    /**
     * Extract key features from histogram
     */
    private function extract_histogram_features($histogram) {
        $features = [
            'peak_bins' => [],
            'moments' => [],
            'entropy' => 0
        ];
        
        $flat_hist = [];
        foreach ($histogram as $channel1) {
            foreach ($channel1 as $channel2) {
                foreach ($channel2 as $value) {
                    $flat_hist[] = $value;
                }
            }
        }
        
        // Sort to find peaks
        arsort($flat_hist);
        $features['peak_bins'] = array_slice($flat_hist, 0, 10, true);
        
        // Calculate entropy
        $entropy = 0;
        foreach ($flat_hist as $value) {
            if ($value > 0) {
                $entropy -= $value * log($value, 2);
            }
        }
        $features['entropy'] = $entropy;
        
        // Calculate statistical moments
        $mean = array_sum($flat_hist) / count($flat_hist);
        $variance = 0;
        $skewness = 0;
        
        foreach ($flat_hist as $value) {
            $diff = $value - $mean;
            $variance += $diff * $diff;
            $skewness += $diff * $diff * $diff;
        }
        
        $variance /= count($flat_hist);
        $std_dev = sqrt($variance);
        
        if ($std_dev > 0) {
            $skewness = ($skewness / count($flat_hist)) / pow($std_dev, 3);
        }
        
        $features['moments'] = [
            'mean' => $mean,
            'variance' => $variance,
            'std_dev' => $std_dev,
            'skewness' => $skewness
        ];
        
        return $features;
    }
    
    /**
     * Calculate similarity between two histograms
     */
    public function calculate_similarity($signature1_data, $signature2_data) {
        if (!is_array($signature1_data) || !is_array($signature2_data)) {
            return 0.0;
        }
        
        $similarities = [];
        
        // Compare RGB histograms
        if (isset($signature1_data['rgb_histogram']) && isset($signature2_data['rgb_histogram'])) {
            $rgb_sim = $this->compare_histograms($signature1_data['rgb_histogram'], $signature2_data['rgb_histogram']);
            $similarities['rgb'] = $rgb_sim;
        }
        
        // Compare HSV histograms
        if (isset($signature1_data['hsv_histogram']) && isset($signature2_data['hsv_histogram'])) {
            $hsv_sim = $this->compare_histograms($signature1_data['hsv_histogram'], $signature2_data['hsv_histogram']);
            $similarities['hsv'] = $hsv_sim;
        }
        
        // Compare color features
        if (isset($signature1_data['color_features']) && isset($signature2_data['color_features'])) {
            $feature_sim = $this->compare_color_features($signature1_data['color_features'], $signature2_data['color_features']);
            $similarities['features'] = $feature_sim;
        }
        
        // Calculate weighted average
        $weights = [
            'rgb' => 0.4,
            'hsv' => 0.4,
            'features' => 0.2
        ];
        
        $total_similarity = 0;
        $total_weight = 0;
        
        foreach ($similarities as $type => $similarity) {
            $total_similarity += $similarity * $weights[$type];
            $total_weight += $weights[$type];
        }
        
        return $total_weight > 0 ? round($total_similarity / $total_weight, 2) : 0.0;
    }
    
    /**
     * Compare two histograms using correlation method
     */
    private function compare_histograms($hist1, $hist2) {
        $correlation = $this->calculate_correlation($hist1, $hist2);
        $intersection = $this->calculate_intersection($hist1, $hist2);
        
        // Combine correlation and intersection for robust comparison
        return ($correlation * 0.7 + $intersection * 0.3) * 100;
    }
    
    /**
     * Calculate correlation coefficient between histograms
     */
    private function calculate_correlation($hist1, $hist2) {
        $flat1 = $this->flatten_histogram($hist1);
        $flat2 = $this->flatten_histogram($hist2);
        
        if (count($flat1) !== count($flat2)) {
            return 0.0;
        }
        
        $mean1 = array_sum($flat1) / count($flat1);
        $mean2 = array_sum($flat2) / count($flat2);
        
        $numerator = 0;
        $sum_sq1 = 0;
        $sum_sq2 = 0;
        
        for ($i = 0; $i < count($flat1); $i++) {
            $diff1 = $flat1[$i] - $mean1;
            $diff2 = $flat2[$i] - $mean2;
            
            $numerator += $diff1 * $diff2;
            $sum_sq1 += $diff1 * $diff1;
            $sum_sq2 += $diff2 * $diff2;
        }
        
        $denominator = sqrt($sum_sq1 * $sum_sq2);
        
        return $denominator > 0 ? $numerator / $denominator : 0.0;
    }
    
    /**
     * Calculate histogram intersection
     */
    private function calculate_intersection($hist1, $hist2) {
        $flat1 = $this->flatten_histogram($hist1);
        $flat2 = $this->flatten_histogram($hist2);
        
        if (count($flat1) !== count($flat2)) {
            return 0.0;
        }
        
        $intersection = 0;
        for ($i = 0; $i < count($flat1); $i++) {
            $intersection += min($flat1[$i], $flat2[$i]);
        }
        
        return $intersection;
    }
    
    /**
     * Flatten 3D histogram to 1D array
     */
    private function flatten_histogram($histogram) {
        $flat = [];
        foreach ($histogram as $channel1) {
            foreach ($channel1 as $channel2) {
                foreach ($channel2 as $value) {
                    $flat[] = $value;
                }
            }
        }
        return $flat;
    }
    
    /**
     * Compare color features
     */
    private function compare_color_features($features1, $features2) {
        $similarities = [];
        
        // Compare mean RGB values
        if (isset($features1['mean_rgb']) && isset($features2['mean_rgb'])) {
            $rgb_diff = 0;
            for ($i = 0; $i < 3; $i++) {
                $rgb_diff += abs($features1['mean_rgb'][$i] - $features2['mean_rgb'][$i]);
            }
            $rgb_diff /= (3 * 255); // Normalize to 0-1
            $similarities['mean_rgb'] = 1 - $rgb_diff;
        }
        
        // Compare brightness
        if (isset($features1['brightness_average']) && isset($features2['brightness_average'])) {
            $brightness_diff = abs($features1['brightness_average'] - $features2['brightness_average']) / 255;
            $similarities['brightness'] = 1 - $brightness_diff;
        }
        
        // Compare contrast
        if (isset($features1['contrast_measure']) && isset($features2['contrast_measure'])) {
            $max_contrast = max($features1['contrast_measure'], $features2['contrast_measure'], 1);
            $contrast_diff = abs($features1['contrast_measure'] - $features2['contrast_measure']) / $max_contrast;
            $similarities['contrast'] = 1 - $contrast_diff;
        }
        
        // Calculate average similarity
        return array_sum($similarities) / count($similarities);
    }
    
    /**
     * Get method information
     */
    public function get_method_info() {
        return [
            'name' => 'Color Histogram',
            'description' => 'Analyzes color distribution using RGB and HSV histograms',
            'speed' => 'medium',
            'accuracy' => 'good',
            'memory_usage' => 'medium',
            'tolerance' => 'rotation, minor_modifications, lighting_changes',
            'detects_portions' => false,
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
        // Medium speed - histogram calculation
        return max(0.2, $file_size / (5 * 1024 * 1024));
    }
    
    /**
     * Get estimated memory usage
     */
    public function estimate_memory_usage($image_info) {
        // Memory for resized image + histograms + processing
        $histogram_size = (self::RGB_BINS ** 3) * 4 + (self::HSV_BINS ** 3) * 4; // Bytes
        return (self::RESIZE_DIM * self::RESIZE_DIM * 4) + $histogram_size + (1024 * 1024); // ~2MB
    }
    
    /**
     * Get configuration options for this method
     */
    public function get_config_options() {
        return [
            'rgb_bins' => [
                'label' => 'RGB Histogram Bins',
                'type' => 'select',
                'options' => [
                    '32' => '32 bins (fast, less accurate)',
                    '64' => '64 bins (balanced)',
                    '128' => '128 bins (slow, more accurate)'
                ],
                'default' => '64',
                'description' => 'Number of bins per RGB channel'
            ],
            'hsv_bins' => [
                'label' => 'HSV Histogram Bins',
                'type' => 'select',
                'options' => [
                    '16' => '16 bins (fast)',
                    '32' => '32 bins (balanced)',
                    '64' => '64 bins (accurate)'
                ],
                'default' => '32',
                'description' => 'Number of bins per HSV channel'
            ],
            'processing_size' => [
                'label' => 'Processing Size',
                'type' => 'number',
                'default' => 256,
                'min' => 64,
                'max' => 512,
                'description' => 'Size to resize images for processing'
            ],
            'comparison_method' => [
                'label' => 'Comparison Method',
                'type' => 'select',
                'options' => [
                    'correlation' => 'Correlation (recommended)',
                    'intersection' => 'Intersection',
                    'chi_square' => 'Chi-square',
                    'combined' => 'Combined methods'
                ],
                'default' => 'combined',
                'description' => 'Method for comparing histograms'
            ]
        ];
    }
}
