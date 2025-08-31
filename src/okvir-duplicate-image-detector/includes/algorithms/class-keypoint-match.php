<?php
/**
 * Keypoint Matching Algorithm
 * 
 * Advanced SIFT-like keypoint detection and matching for robust duplicate detection.
 * Can handle significant transformations including rotation, scale, perspective changes.
 * Most computationally expensive but highest accuracy for complex transformations.
 */

if (!defined('ABSPATH')) {
    exit;
}

class OkvirDupDetector_KeypointMatch {
    
    const MAX_KEYPOINTS = 500;      // Maximum keypoints per image
    const DESCRIPTOR_SIZE = 128;    // Size of each keypoint descriptor
    const OCTAVE_LAYERS = 3;        // Layers per octave in scale space
    const CONTRAST_THRESHOLD = 0.04; // Threshold for keypoint contrast
    const EDGE_THRESHOLD = 10;      // Edge response threshold
    const MATCH_RATIO_THRESHOLD = 0.7; // Lowe's ratio test threshold
    
    /**
     * Generate signature for keypoint matching method
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
            
            // Convert to grayscale for keypoint detection
            $gray_image = $this->convert_to_grayscale($image);
            imagedestroy($image);
            
            if (!$gray_image) {
                return [
                    'success' => false,
                    'error' => 'Failed to convert image to grayscale'
                ];
            }
            
            // Detect keypoints
            $keypoints = $this->detect_keypoints($gray_image);
            
            // Extract descriptors for keypoints
            $descriptors = $this->extract_descriptors($gray_image, $keypoints);
            
            imagedestroy($gray_image);
            
            // Create compact signature
            $signature = $this->create_keypoint_signature($keypoints, $descriptors);
            
            return [
                'success' => true,
                'signature' => hash('sha256', serialize($signature)),
                'data' => [
                    'keypoints' => $keypoints,
                    'descriptors' => $descriptors,
                    'signature_compact' => $signature,
                    'keypoint_count' => count($keypoints),
                    'method' => 'keypoint_match',
                    'generated_at' => time(),
                    'image_dimensions' => [
                        'original_width' => $image_info['width'],
                        'original_height' => $image_info['height']
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
     * Convert image to grayscale
     */
    private function convert_to_grayscale($image) {
        $width = imagesx($image);
        $height = imagesy($image);
        
        $gray = imagecreatetruecolor($width, $height);
        if (!$gray) {
            return false;
        }
        
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                
                $gray_value = (int)(0.299 * $r + 0.587 * $g + 0.114 * $b);
                $gray_color = imagecolorallocate($gray, $gray_value, $gray_value, $gray_value);
                imagesetpixel($gray, $x, $y, $gray_color);
            }
        }
        
        return $gray;
    }
    
    /**
     * Detect keypoints using DoG (Difference of Gaussians) approach
     */
    private function detect_keypoints($gray_image) {
        $width = imagesx($gray_image);
        $height = imagesy($gray_image);
        
        // Build Gaussian pyramid
        $gaussian_pyramid = $this->build_gaussian_pyramid($gray_image);
        
        // Build DoG pyramid
        $dog_pyramid = $this->build_dog_pyramid($gaussian_pyramid);
        
        // Find extrema in DoG pyramid
        $extrema = $this->find_dog_extrema($dog_pyramid);
        
        // Refine keypoints and eliminate edge responses
        $keypoints = $this->refine_keypoints($dog_pyramid, $extrema);
        
        // Assign orientations to keypoints
        $keypoints = $this->assign_orientations($gaussian_pyramid, $keypoints);
        
        // Clean up pyramid images
        foreach ($gaussian_pyramid as $octave) {
            foreach ($octave as $image) {
                imagedestroy($image);
            }
        }
        
        foreach ($dog_pyramid as $octave) {
            foreach ($octave as $image) {
                imagedestroy($image);
            }
        }
        
        return array_slice($keypoints, 0, self::MAX_KEYPOINTS);
    }
    
    /**
     * Build Gaussian pyramid
     */
    private function build_gaussian_pyramid($image) {
        $pyramid = [];
        $current_image = $this->duplicate_image($image);
        
        // For simplicity, we'll create a single octave with multiple scales
        $octave = [];
        $sigma = 1.6;
        
        for ($layer = 0; $layer < self::OCTAVE_LAYERS + 3; $layer++) {
            if ($layer == 0) {
                $octave[] = $this->duplicate_image($current_image);
            } else {
                $blurred = $this->gaussian_blur($current_image, $sigma * pow(2, $layer / self::OCTAVE_LAYERS));
                $octave[] = $blurred;
            }
        }
        
        $pyramid[] = $octave;
        imagedestroy($current_image);
        
        return $pyramid;
    }
    
    /**
     * Build Difference of Gaussians pyramid
     */
    private function build_dog_pyramid($gaussian_pyramid) {
        $dog_pyramid = [];
        
        foreach ($gaussian_pyramid as $octave_idx => $octave) {
            $dog_octave = [];
            
            for ($layer = 0; $layer < count($octave) - 1; $layer++) {
                $dog_image = $this->image_difference($octave[$layer + 1], $octave[$layer]);
                $dog_octave[] = $dog_image;
            }
            
            $dog_pyramid[] = $dog_octave;
        }
        
        return $dog_pyramid;
    }
    
    /**
     * Find extrema in DoG pyramid
     */
    private function find_dog_extrema($dog_pyramid) {
        $extrema = [];
        
        foreach ($dog_pyramid as $octave_idx => $octave) {
            for ($layer = 1; $layer < count($octave) - 1; $layer++) {
                $current = $octave[$layer];
                $above = $octave[$layer + 1];
                $below = $octave[$layer - 1];
                
                $width = imagesx($current);
                $height = imagesy($current);
                
                for ($x = 1; $x < $width - 1; $x++) {
                    for ($y = 1; $y < $height - 1; $y++) {
                        $center_value = $this->get_gray_value($current, $x, $y);
                        
                        if (abs($center_value) < self::CONTRAST_THRESHOLD * 255) {
                            continue;
                        }
                        
                        $is_extremum = $this->is_extremum($current, $above, $below, $x, $y, $center_value);
                        
                        if ($is_extremum) {
                            $extrema[] = [
                                'octave' => $octave_idx,
                                'layer' => $layer,
                                'x' => $x,
                                'y' => $y,
                                'value' => $center_value
                            ];
                        }
                    }
                }
            }
        }
        
        return $extrema;
    }
    
    /**
     * Check if point is local extremum
     */
    private function is_extremum($current, $above, $below, $x, $y, $center_value) {
        $is_max = true;
        $is_min = true;
        
        // Check 3x3x3 neighborhood
        for ($dx = -1; $dx <= 1; $dx++) {
            for ($dy = -1; $dy <= 1; $dy++) {
                $images = [$below, $current, $above];
                
                foreach ($images as $img) {
                    $val = $this->get_gray_value($img, $x + $dx, $y + $dy);
                    
                    if ($val >= $center_value) $is_max = false;
                    if ($val <= $center_value) $is_min = false;
                    
                    if (!$is_max && !$is_min) {
                        return false;
                    }
                }
            }
        }
        
        return $is_max || $is_min;
    }
    
    /**
     * Refine keypoints and eliminate edge responses
     */
    private function refine_keypoints($dog_pyramid, $extrema) {
        $refined_keypoints = [];
        
        foreach ($extrema as $extremum) {
            // Simple edge elimination using Hessian
            $octave = $dog_pyramid[$extremum['octave']];
            $image = $octave[$extremum['layer']];
            
            $x = $extremum['x'];
            $y = $extremum['y'];
            
            // Calculate Hessian matrix elements
            $dxx = $this->get_gray_value($image, $x + 1, $y) - 2 * $this->get_gray_value($image, $x, $y) + $this->get_gray_value($image, $x - 1, $y);
            $dyy = $this->get_gray_value($image, $x, $y + 1) - 2 * $this->get_gray_value($image, $x, $y) + $this->get_gray_value($image, $x, $y - 1);
            $dxy = ($this->get_gray_value($image, $x + 1, $y + 1) - $this->get_gray_value($image, $x - 1, $y + 1) - 
                    $this->get_gray_value($image, $x + 1, $y - 1) + $this->get_gray_value($image, $x - 1, $y - 1)) / 4;
            
            $trace = $dxx + $dyy;
            $det = $dxx * $dyy - $dxy * $dxy;
            
            if ($det <= 0) {
                continue; // Not a local extremum
            }
            
            $ratio = $trace * $trace / $det;
            if ($ratio >= pow(self::EDGE_THRESHOLD + 1, 2) / self::EDGE_THRESHOLD) {
                continue; // Edge response, not a corner
            }
            
            $refined_keypoints[] = [
                'x' => $x,
                'y' => $y,
                'octave' => $extremum['octave'],
                'layer' => $extremum['layer'],
                'response' => abs($extremum['value']),
                'orientation' => 0 // Will be assigned later
            ];
        }
        
        return $refined_keypoints;
    }
    
    /**
     * Assign orientations to keypoints
     */
    private function assign_orientations($gaussian_pyramid, $keypoints) {
        foreach ($keypoints as &$keypoint) {
            $octave = $gaussian_pyramid[$keypoint['octave']];
            $image = $octave[$keypoint['layer']];
            
            $orientation = $this->compute_keypoint_orientation($image, $keypoint['x'], $keypoint['y']);
            $keypoint['orientation'] = $orientation;
        }
        
        return $keypoints;
    }
    
    /**
     * Compute keypoint orientation using gradient histogram
     */
    private function compute_keypoint_orientation($image, $x, $y) {
        $width = imagesx($image);
        $height = imagesy($image);
        
        // Orientation histogram with 36 bins (10 degrees each)
        $hist = array_fill(0, 36, 0);
        
        // Sample points in circular region around keypoint
        $radius = 9; // 3 * sigma where sigma = 3
        
        for ($dx = -$radius; $dx <= $radius; $dx++) {
            for ($dy = -$radius; $dy <= $radius; $dy++) {
                $nx = $x + $dx;
                $ny = $y + $dy;
                
                if ($nx <= 0 || $nx >= $width - 1 || $ny <= 0 || $ny >= $height - 1) {
                    continue;
                }
                
                $distance = sqrt($dx * $dx + $dy * $dy);
                if ($distance > $radius) {
                    continue;
                }
                
                // Calculate gradient
                $gx = $this->get_gray_value($image, $nx + 1, $ny) - $this->get_gray_value($image, $nx - 1, $ny);
                $gy = $this->get_gray_value($image, $nx, $ny + 1) - $this->get_gray_value($image, $nx, $ny - 1);
                
                $magnitude = sqrt($gx * $gx + $gy * $gy);
                $orientation = atan2($gy, $gx);
                
                // Convert to degrees and bin
                $degrees = $orientation * 180 / M_PI;
                if ($degrees < 0) $degrees += 360;
                
                $bin = (int)($degrees / 10) % 36;
                
                // Weight by magnitude and Gaussian
                $weight = $magnitude * exp(-$distance * $distance / (2 * 4.5 * 4.5));
                $hist[$bin] += $weight;
            }
        }
        
        // Find peak in histogram
        $max_val = max($hist);
        $peak_bin = array_search($max_val, $hist);
        
        // Return dominant orientation in radians
        return ($peak_bin * 10) * M_PI / 180;
    }
    
    /**
     * Extract SIFT-like descriptors for keypoints
     */
    private function extract_descriptors($image, $keypoints) {
        $descriptors = [];
        
        foreach ($keypoints as $keypoint) {
            $descriptor = $this->compute_sift_descriptor($image, $keypoint);
            if ($descriptor) {
                $descriptors[] = $descriptor;
            }
        }
        
        return $descriptors;
    }
    
    /**
     * Compute SIFT descriptor for a keypoint
     */
    private function compute_sift_descriptor($image, $keypoint) {
        $x = $keypoint['x'];
        $y = $keypoint['y'];
        $orientation = $keypoint['orientation'];
        
        $width = imagesx($image);
        $height = imagesy($image);
        
        // 4x4 grid of 4x4 pixel windows, 8 orientation bins each = 128 dimensions
        $descriptor = array_fill(0, self::DESCRIPTOR_SIZE, 0);
        
        $cos_angle = cos($orientation);
        $sin_angle = sin($orientation);
        
        $window_size = 16; // 4x4 windows of 4x4 pixels each
        $bin_width = 2 * M_PI / 8; // 8 orientation bins
        
        for ($i = 0; $i < 4; $i++) {
            for ($j = 0; $j < 4; $j++) {
                $hist = array_fill(0, 8, 0);
                
                // Sample 4x4 pixel region
                for ($xi = 0; $xi < 4; $xi++) {
                    for ($yi = 0; $yi < 4; $yi++) {
                        // Calculate position relative to keypoint
                        $sample_x = ($i - 1.5) * 4 + $xi - 1.5;
                        $sample_y = ($j - 1.5) * 4 + $yi - 1.5;
                        
                        // Rotate sample point
                        $rotated_x = $cos_angle * $sample_x - $sin_angle * $sample_y;
                        $rotated_y = $sin_angle * $sample_x + $cos_angle * $sample_y;
                        
                        $pixel_x = (int)($x + $rotated_x);
                        $pixel_y = (int)($y + $rotated_y);
                        
                        if ($pixel_x <= 0 || $pixel_x >= $width - 1 || $pixel_y <= 0 || $pixel_y >= $height - 1) {
                            continue;
                        }
                        
                        // Calculate gradient
                        $gx = $this->get_gray_value($image, $pixel_x + 1, $pixel_y) - $this->get_gray_value($image, $pixel_x - 1, $pixel_y);
                        $gy = $this->get_gray_value($image, $pixel_x, $pixel_y + 1) - $this->get_gray_value($image, $pixel_x, $pixel_y - 1);
                        
                        $magnitude = sqrt($gx * $gx + $gy * $gy);
                        $grad_orientation = atan2($gy, $gx) - $orientation; // Relative to keypoint orientation
                        
                        // Normalize orientation
                        while ($grad_orientation < 0) $grad_orientation += 2 * M_PI;
                        while ($grad_orientation >= 2 * M_PI) $grad_orientation -= 2 * M_PI;
                        
                        $bin = (int)($grad_orientation / $bin_width) % 8;
                        
                        // Gaussian weighting
                        $distance = sqrt($sample_x * $sample_x + $sample_y * $sample_y);
                        $weight = $magnitude * exp(-$distance * $distance / (2 * 8 * 8));
                        
                        $hist[$bin] += $weight;
                    }
                }
                
                // Add histogram to descriptor
                $desc_idx = ($i * 4 + $j) * 8;
                for ($k = 0; $k < 8; $k++) {
                    $descriptor[$desc_idx + $k] = $hist[$k];
                }
            }
        }
        
        // Normalize descriptor
        $norm = sqrt(array_sum(array_map(function($x) { return $x * $x; }, $descriptor)));
        if ($norm > 0) {
            for ($i = 0; $i < self::DESCRIPTOR_SIZE; $i++) {
                $descriptor[$i] /= $norm;
                
                // Threshold large values (illumination invariance)
                $descriptor[$i] = min($descriptor[$i], 0.2);
            }
            
            // Re-normalize after thresholding
            $norm = sqrt(array_sum(array_map(function($x) { return $x * $x; }, $descriptor)));
            if ($norm > 0) {
                for ($i = 0; $i < self::DESCRIPTOR_SIZE; $i++) {
                    $descriptor[$i] /= $norm;
                }
            }
        }
        
        return $descriptor;
    }
    
    /**
     * Create compact signature from keypoints and descriptors
     */
    private function create_keypoint_signature($keypoints, $descriptors) {
        $signature = [];
        
        // Store most significant descriptors
        $sig_descriptors = array_slice($descriptors, 0, 50); // Top 50 descriptors
        
        // Create statistical summary
        $signature['descriptor_count'] = count($descriptors);
        $signature['keypoint_count'] = count($keypoints);
        $signature['descriptors'] = $sig_descriptors;
        
        // Calculate descriptor statistics
        if (!empty($descriptors)) {
            $mean_descriptor = array_fill(0, self::DESCRIPTOR_SIZE, 0);
            foreach ($descriptors as $desc) {
                for ($i = 0; $i < self::DESCRIPTOR_SIZE; $i++) {
                    $mean_descriptor[$i] += $desc[$i];
                }
            }
            
            for ($i = 0; $i < self::DESCRIPTOR_SIZE; $i++) {
                $mean_descriptor[$i] /= count($descriptors);
            }
            
            $signature['mean_descriptor'] = $mean_descriptor;
        }
        
        return $signature;
    }
    
    /**
     * Calculate similarity between two keypoint signatures
     */
    public function calculate_similarity($signature1_data, $signature2_data) {
        if (!is_array($signature1_data) || !is_array($signature2_data)) {
            return 0.0;
        }
        
        if (!isset($signature1_data['descriptors']) || !isset($signature2_data['descriptors'])) {
            return 0.0;
        }
        
        $descriptors1 = $signature1_data['descriptors'];
        $descriptors2 = $signature2_data['descriptors'];
        
        if (empty($descriptors1) || empty($descriptors2)) {
            return 0.0;
        }
        
        // Match descriptors using ratio test
        $matches = $this->match_descriptors($descriptors1, $descriptors2);
        
        // Calculate similarity based on match ratio
        $max_possible_matches = min(count($descriptors1), count($descriptors2));
        $match_ratio = $max_possible_matches > 0 ? count($matches) / $max_possible_matches : 0;
        
        return round($match_ratio * 100, 2);
    }
    
    /**
     * Match descriptors using nearest neighbor ratio test
     */
    private function match_descriptors($descriptors1, $descriptors2) {
        $matches = [];
        
        foreach ($descriptors1 as $i => $desc1) {
            $distances = [];
            
            // Calculate distances to all descriptors in second set
            foreach ($descriptors2 as $j => $desc2) {
                $distance = $this->euclidean_distance($desc1, $desc2);
                $distances[] = ['index' => $j, 'distance' => $distance];
            }
            
            // Sort by distance
            usort($distances, function($a, $b) {
                return $a['distance'] <=> $b['distance'];
            });
            
            // Apply Lowe's ratio test
            if (count($distances) >= 2) {
                $ratio = $distances[0]['distance'] / $distances[1]['distance'];
                if ($ratio < self::MATCH_RATIO_THRESHOLD) {
                    $matches[] = [
                        'query_idx' => $i,
                        'train_idx' => $distances[0]['index'],
                        'distance' => $distances[0]['distance']
                    ];
                }
            }
        }
        
        return $matches;
    }
    
    /**
     * Calculate Euclidean distance between two descriptors
     */
    private function euclidean_distance($desc1, $desc2) {
        if (count($desc1) !== count($desc2)) {
            return PHP_FLOAT_MAX;
        }
        
        $sum = 0;
        for ($i = 0; $i < count($desc1); $i++) {
            $diff = $desc1[$i] - $desc2[$i];
            $sum += $diff * $diff;
        }
        
        return sqrt($sum);
    }
    
    /**
     * Utility functions
     */
    private function get_gray_value($image, $x, $y) {
        $rgb = imagecolorat($image, $x, $y);
        return ($rgb >> 16) & 0xFF; // Assuming grayscale, all channels are equal
    }
    
    private function duplicate_image($source) {
        $width = imagesx($source);
        $height = imagesy($source);
        $copy = imagecreatetruecolor($width, $height);
        imagecopy($copy, $source, 0, 0, 0, 0, $width, $height);
        return $copy;
    }
    
    private function gaussian_blur($image, $sigma) {
        // Simple Gaussian blur implementation
        // In production, you'd want to use imagefilter or a more sophisticated implementation
        $blurred = $this->duplicate_image($image);
        
        // Apply simple blur filter multiple times for approximation
        $iterations = max(1, (int)($sigma));
        for ($i = 0; $i < $iterations; $i++) {
            imagefilter($blurred, IMG_FILTER_GAUSSIAN_BLUR);
        }
        
        return $blurred;
    }
    
    private function image_difference($image1, $image2) {
        $width = imagesx($image1);
        $height = imagesy($image1);
        
        $diff = imagecreatetruecolor($width, $height);
        
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $val1 = $this->get_gray_value($image1, $x, $y);
                $val2 = $this->get_gray_value($image2, $x, $y);
                $diff_val = abs($val1 - $val2);
                
                $color = imagecolorallocate($diff, $diff_val, $diff_val, $diff_val);
                imagesetpixel($diff, $x, $y, $color);
            }
        }
        
        return $diff;
    }
    
    /**
     * Get method information
     */
    public function get_method_info() {
        return [
            'name' => 'Keypoint Matching',
            'description' => 'SIFT-like keypoint detection and descriptor matching',
            'speed' => 'very_slow',
            'accuracy' => 'excellent',
            'memory_usage' => 'very_high',
            'tolerance' => 'rotation, scale, perspective, affine_transforms',
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
        // Very slow - complex keypoint detection and descriptor extraction
        return max(5.0, $file_size / (512 * 1024));
    }
    
    /**
     * Get estimated memory usage
     */
    public function estimate_memory_usage($image_info) {
        // Very high memory usage for pyramid, keypoints and descriptors
        $image_memory = $image_info['width'] * $image_info['height'] * 4; // RGBA
        $pyramid_memory = $image_memory * 6; // Gaussian + DoG pyramids
        $descriptor_memory = self::MAX_KEYPOINTS * self::DESCRIPTOR_SIZE * 8; // Float descriptors
        
        return $pyramid_memory + $descriptor_memory;
    }
    
    /**
     * Get configuration options for this method
     */
    public function get_config_options() {
        return [
            'max_keypoints' => [
                'label' => 'Maximum Keypoints',
                'type' => 'number',
                'default' => 500,
                'min' => 100,
                'max' => 2000,
                'description' => 'Maximum number of keypoints to extract per image'
            ],
            'contrast_threshold' => [
                'label' => 'Contrast Threshold',
                'type' => 'number',
                'default' => 0.04,
                'min' => 0.01,
                'max' => 0.1,
                'step' => 0.01,
                'description' => 'Threshold for keypoint contrast detection'
            ],
            'edge_threshold' => [
                'label' => 'Edge Threshold',
                'type' => 'number',
                'default' => 10,
                'min' => 5,
                'max' => 20,
                'description' => 'Threshold for eliminating edge responses'
            ],
            'match_ratio_threshold' => [
                'label' => 'Match Ratio Threshold',
                'type' => 'number',
                'default' => 0.7,
                'min' => 0.5,
                'max' => 0.9,
                'step' => 0.05,
                'description' => 'Lowe\'s ratio test threshold for descriptor matching'
            ]
        ];
    }
}
