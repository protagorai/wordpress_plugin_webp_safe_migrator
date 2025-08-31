<?php
/**
 * Settings Tab
 * 
 * Configuration interface for detection methods, thresholds, and plugin behavior.
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<h2>⚙️ Plugin Settings</h2>

<form method="post" action="">
    <?php wp_nonce_field('okvir_dup_settings', 'okvir_nonce'); ?>
    
    <!-- Detection Methods Configuration -->
    <div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin: 20px 0;">
        <h3>🧮 Detection Methods</h3>
        <p>Configure which algorithms to use for duplicate detection. At least 2 methods must agree for an image to be considered a duplicate.</p>
        
        <?php
        $methods = [
            OkvirDuplicateImageDetector::METHOD_FILE_HASH => [
                'name' => 'File Hash (MD5/SHA256)',
                'icon' => '🔐',
                'description' => 'Exact file content matching. Very fast, detects identical files only.',
                'pros' => ['Extremely fast', '100% accurate for exact duplicates', 'Memory efficient'],
                'cons' => ['Zero tolerance for changes', 'Misses compressed/resized versions'],
                'recommended' => true,
                'cost' => 'Very Low'
            ],
            OkvirDuplicateImageDetector::METHOD_PERCEPTUAL_HASH => [
                'name' => 'Perceptual Hash (DCT)',
                'icon' => '🎭',
                'description' => 'DCT-based image fingerprinting. Good for minor modifications.',
                'pros' => ['Tolerates compression', 'Handles format changes', 'Fast processing'],
                'cons' => ['Limited transformation tolerance', 'Cannot detect portions'],
                'recommended' => true,
                'cost' => 'Low'
            ],
            OkvirDuplicateImageDetector::METHOD_COLOR_HISTOGRAM => [
                'name' => 'Color Histogram',
                'icon' => '🌈',
                'description' => 'Analyzes color distribution patterns in images.',
                'pros' => ['Rotation invariant', 'Scale tolerant', 'Good for similar scenes'],
                'cons' => ['Different images can have similar histograms', 'Poor with monochromatic images'],
                'recommended' => true,
                'cost' => 'Medium'
            ],
            OkvirDuplicateImageDetector::METHOD_TEMPLATE_MATCH => [
                'name' => 'Template Matching',
                'icon' => '🎯',
                'description' => 'Multi-scale template matching with feature extraction.',
                'pros' => ['Detects cropped portions', 'Multi-scale detection', 'High precision'],
                'cons' => ['Computationally expensive', 'Poor rotation tolerance', 'Memory intensive'],
                'recommended' => false,
                'cost' => 'High'
            ],
            OkvirDuplicateImageDetector::METHOD_KEYPOINT_MATCH => [
                'name' => 'Keypoint Matching (SIFT-like)',
                'icon' => '🔑',
                'description' => 'Advanced keypoint detection and descriptor matching.',
                'pros' => ['Handles all transformations', 'Detects partial matches', 'Most robust'],
                'cons' => ['Very slow', 'Memory intensive', 'Complex implementation'],
                'recommended' => false,
                'cost' => 'Very High'
            ]
        ];
        
        foreach ($methods as $method_key => $method_info):
            $enabled = !empty($settings['enabled_methods'][$method_key]);
        ?>
            <div class="okvir-method-card <?php echo $enabled ? 'okvir-method-enabled' : 'okvir-method-disabled'; ?>" style="margin: 15px 0;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                    <div style="flex: 1;">
                        <h4>
                            <label>
                                <input type="checkbox" name="method_<?php echo $method_key; ?>" value="1" 
                                       <?php checked($enabled); ?> onchange="this.closest('.okvir-method-card').classList.toggle('okvir-method-enabled', this.checked); this.closest('.okvir-method-card').classList.toggle('okvir-method-disabled', !this.checked);">
                                <?php echo $method_info['icon']; ?> <?php echo $method_info['name']; ?>
                            </label>
                            <?php if ($method_info['recommended']): ?>
                                <span style="background: #46b450; color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; margin-left: 10px;">RECOMMENDED</span>
                            <?php endif; ?>
                        </h4>
                        
                        <p><?php echo $method_info['description']; ?></p>
                        
                        <div style="display: flex; gap: 20px; margin: 10px 0;">
                            <div>
                                <strong>✅ Pros:</strong>
                                <ul style="margin: 5px 0; margin-left: 20px;">
                                    <?php foreach ($method_info['pros'] as $pro): ?>
                                        <li><?php echo $pro; ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            
                            <div>
                                <strong>❌ Cons:</strong>
                                <ul style="margin: 5px 0; margin-left: 20px;">
                                    <?php foreach ($method_info['cons'] as $con): ?>
                                        <li><?php echo $con; ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <div style="text-align: right; margin-left: 20px;">
                        <div style="background: #f0f0f0; padding: 8px 12px; border-radius: 4px; margin-bottom: 10px;">
                            <strong>Cost: <?php echo $method_info['cost']; ?></strong>
                        </div>
                        
                        <?php if (isset($stats['methods'][$method_key])): ?>
                            <div style="color: #666; font-size: 12px;">
                                <?php echo number_format($stats['methods'][$method_key]); ?> signatures
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Method-specific settings -->
                <?php if (in_array($method_key, [OkvirDuplicateImageDetector::METHOD_PERCEPTUAL_HASH, OkvirDuplicateImageDetector::METHOD_COLOR_HISTOGRAM, OkvirDuplicateImageDetector::METHOD_TEMPLATE_MATCH, OkvirDuplicateImageDetector::METHOD_KEYPOINT_MATCH])): ?>
                    <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
                        <label>
                            <strong>Similarity Threshold:</strong>
                            <input type="number" name="threshold_<?php echo $method_key; ?>" 
                                   value="<?php echo $settings['similarity_threshold'][$method_key] ?? 85; ?>"
                                   min="50" max="100" step="5" style="width: 80px;">%
                        </label>
                        <small>Minimum similarity percentage to consider images as duplicates</small>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Processing Settings -->
    <div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin: 20px 0;">
        <h3>⚡ Processing Settings</h3>
        
        <table class="form-table">
            <tr>
                <th scope="row">Batch Size</th>
                <td>
                    <input type="number" name="batch_size" value="<?php echo $settings['batch_size']; ?>" 
                           min="1" max="<?php echo OkvirDuplicateImageDetector::MAX_BATCH_SIZE; ?>" style="width: 100px;">
                    <p class="description">
                        Number of images to process in each batch. 
                        <strong>Maximum: <?php echo OkvirDuplicateImageDetector::MAX_BATCH_SIZE; ?></strong> (hard-coded limit)
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">Background Processing</th>
                <td>
                    <label>
                        <input type="checkbox" name="enable_background_processing" value="1" 
                               <?php checked($settings['enable_background_processing']); ?>>
                        Enable automatic background processing
                    </label>
                    <p class="description">
                        When enabled, images will be processed automatically in the background using WordPress cron.
                        Recommended for large image libraries.
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">Image Types</th>
                <td>
                    <?php 
                    $available_types = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    foreach ($available_types as $type): 
                    ?>
                        <label style="margin-right: 15px;">
                            <input type="checkbox" name="image_types[]" value="<?php echo $type; ?>" 
                                   <?php checked(in_array($type, $settings['image_types'])); ?>>
                            <?php echo strtoupper($type); ?>
                        </label>
                    <?php endforeach; ?>
                    <p class="description">Select which image file types to analyze for duplicates.</p>
                </td>
            </tr>
        </table>
    </div>
    
    <!-- File Size Limits -->
    <div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin: 20px 0;">
        <h3>📏 File Size Limits</h3>
        
        <table class="form-table">
            <tr>
                <th scope="row">Minimum File Size</th>
                <td>
                    <input type="number" name="min_file_size" value="<?php echo $settings['min_file_size']; ?>" 
                           min="0" max="10485760" style="width: 120px;"> bytes
                    <p class="description">
                        Ignore images smaller than this size. Current: <?php echo size_format($settings['min_file_size']); ?>
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">Maximum File Size</th>
                <td>
                    <input type="number" name="max_file_size" value="<?php echo $settings['max_file_size']; ?>" 
                           min="1048576" max="104857600" style="width: 120px;"> bytes
                    <p class="description">
                        Skip images larger than this size to prevent memory issues. Current: <?php echo size_format($settings['max_file_size']); ?>
                    </p>
                </td>
            </tr>
        </table>
    </div>
    
    <!-- Deletion Settings -->
    <div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin: 20px 0;">
        <h3>🗑️ Deletion & Safety Settings</h3>
        
        <table class="form-table">
            <tr>
                <th scope="row">Auto-Delete Confirmed Duplicates</th>
                <td>
                    <label>
                        <input type="checkbox" name="auto_delete_confirmed_duplicates" value="1" 
                               <?php checked($settings['auto_delete_confirmed_duplicates']); ?>>
                        Automatically delete duplicates with very high confidence (≥98%)
                    </label>
                    <p class="description">
                        <strong>⚠️ Use with caution!</strong> Only enable if you're confident in the detection accuracy.
                    </p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">Create Backups Before Deletion</th>
                <td>
                    <label>
                        <input type="checkbox" name="backup_before_delete" value="1" 
                               <?php checked($settings['backup_before_delete']); ?>>
                        Create backup copies before deleting duplicate images
                    </label>
                    <p class="description">
                        Recommended for safety. Backups are stored in <code>/wp-content/uploads/okvir-duplicate-detector-backups/</code>
                    </p>
                </td>
            </tr>
        </table>
    </div>
    
    <!-- Advanced Configuration -->
    <div style="background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin: 20px 0;">
        <h3>🔧 Advanced Configuration</h3>
        
        <div class="okvir-form-row">
            <label>Minimum Matching Methods:</label>
            <span><strong><?php echo OkvirDuplicateImageDetector::MIN_MATCH_METHODS; ?></strong> methods must agree</span>
            <small>This is hard-coded for safety and cannot be changed</small>
        </div>
        
        <div class="okvir-form-row">
            <label>Maximum Batch Size:</label>
            <span><strong><?php echo OkvirDuplicateImageDetector::MAX_BATCH_SIZE; ?></strong> images</span>
            <small>Hard-coded limit to prevent server overload</small>
        </div>
        
        <div class="okvir-form-row">
            <label>Database Tables:</label>
            <div>
                <?php
                $tables = [
                    OkvirDuplicateImageDetector::TABLE_IMAGE_SIGNATURES => 'Image Signatures',
                    OkvirDuplicateImageDetector::TABLE_IMAGE_ANALYSIS => 'Image Analysis',
                    OkvirDuplicateImageDetector::TABLE_DUPLICATE_GROUPS => 'Duplicate Groups',
                    OkvirDuplicateImageDetector::TABLE_PROCESSING_QUEUE => 'Processing Queue'
                ];
                
                foreach ($tables as $table_name => $table_label):
                    $full_table_name = $wpdb->prefix . $table_name;
                    $exists = $wpdb->get_var("SHOW TABLES LIKE '{$full_table_name}'") === $full_table_name;
                ?>
                    <div style="color: <?php echo $exists ? '#46b450' : '#dc3232'; ?>;">
                        <?php echo $exists ? '✅' : '❌'; ?> <?php echo $table_label; ?> 
                        <code><?php echo $full_table_name; ?></code>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <!-- Performance Optimization -->
    <div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 20px; margin: 20px 0;">
        <h3>🚀 Performance Optimization</h3>
        
        <div class="notice notice-info inline">
            <p><strong>💡 Performance Tips:</strong></p>
            <ul>
                <li><strong>For Small Libraries (&lt;1000 images):</strong> Enable File Hash + Perceptual Hash</li>
                <li><strong>For Medium Libraries (1000-10000 images):</strong> Add Color Histogram for better coverage</li>
                <li><strong>For Large Libraries (&gt;10000 images):</strong> Enable background processing and use conservative settings</li>
                <li><strong>For Maximum Accuracy:</strong> Enable all methods but expect slower processing</li>
            </ul>
        </div>
        
        <div class="okvir-stats-grid" style="margin-top: 15px;">
            <div class="okvir-stat-card">
                <div class="okvir-stat-number"><?php echo ini_get('memory_limit'); ?></div>
                <div class="okvir-stat-label">PHP Memory Limit</div>
            </div>
            
            <div class="okvir-stat-card">
                <div class="okvir-stat-number"><?php echo ini_get('max_execution_time'); ?>s</div>
                <div class="okvir-stat-label">Max Execution Time</div>
            </div>
            
            <div class="okvir-stat-card">
                <div class="okvir-stat-number"><?php echo size_format(memory_get_usage(true)); ?></div>
                <div class="okvir-stat-label">Current Memory Usage</div>
            </div>
            
            <div class="okvir-stat-card">
                <div class="okvir-stat-number"><?php echo extension_loaded('imagick') ? 'Yes' : 'No'; ?></div>
                <div class="okvir-stat-label">ImageMagick Available</div>
            </div>
        </div>
    </div>
    
    <!-- Recommended Configurations -->
    <div style="background: #f0f8ff; border: 1px solid #0073aa; border-radius: 4px; padding: 20px; margin: 20px 0;">
        <h3>🎯 Recommended Configurations</h3>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px;">
            <div style="background: #e7f7ff; padding: 15px; border-radius: 4px;">
                <h4>🏃‍♂️ Speed Optimized</h4>
                <p><strong>Best for:</strong> Quick scans, small libraries</p>
                <ul>
                    <li>✅ File Hash</li>
                    <li>✅ Perceptual Hash</li>
                    <li>❌ Other methods</li>
                </ul>
                <button type="button" class="button button-small" onclick="okvirSettings.applyConfig('speed')">Apply Config</button>
            </div>
            
            <div style="background: #fff4e6; padding: 15px; border-radius: 4px;">
                <h4>⚖️ Balanced</h4>
                <p><strong>Best for:</strong> Most users, medium libraries</p>
                <ul>
                    <li>✅ File Hash</li>
                    <li>✅ Perceptual Hash</li>
                    <li>✅ Color Histogram</li>
                    <li>❌ Advanced methods</li>
                </ul>
                <button type="button" class="button button-small" onclick="okvirSettings.applyConfig('balanced')">Apply Config</button>
            </div>
            
            <div style="background: #f0fff0; padding: 15px; border-radius: 4px;">
                <h4>🎯 Maximum Accuracy</h4>
                <p><strong>Best for:</strong> Critical accuracy, small batches</p>
                <ul>
                    <li>✅ All methods enabled</li>
                    <li>🐌 Slower processing</li>
                    <li>💾 High memory usage</li>
                </ul>
                <button type="button" class="button button-small" onclick="okvirSettings.applyConfig('accuracy')">Apply Config</button>
            </div>
        </div>
    </div>
    
    <!-- Save Button -->
    <div style="text-align: center; margin: 30px 0;">
        <button type="submit" name="save_settings" class="button button-primary button-large">
            💾 Save Settings
        </button>
    </div>
</form>

<script>
window.okvirSettings = {
    applyConfig: function(configType) {
        const configs = {
            speed: {
                methods: ['file_hash', 'perceptual_hash'],
                batch_size: 50,
                thresholds: {
                    perceptual_hash: 95,
                    color_histogram: 85,
                    template_match: 90,
                    keypoint_match: 80
                }
            },
            balanced: {
                methods: ['file_hash', 'perceptual_hash', 'color_histogram'],
                batch_size: 20,
                thresholds: {
                    perceptual_hash: 90,
                    color_histogram: 80,
                    template_match: 85,
                    keypoint_match: 75
                }
            },
            accuracy: {
                methods: ['file_hash', 'perceptual_hash', 'color_histogram', 'template_match', 'keypoint_match'],
                batch_size: 5,
                thresholds: {
                    perceptual_hash: 85,
                    color_histogram: 75,
                    template_match: 80,
                    keypoint_match: 70
                }
            }
        };
        
        const config = configs[configType];
        if (!config) return;
        
        // Update method checkboxes
        document.querySelectorAll('input[name^="method_"]').forEach(cb => {
            const methodName = cb.name.replace('method_', '');
            cb.checked = config.methods.includes(methodName);
            
            // Update visual state
            const card = cb.closest('.okvir-method-card');
            card.classList.toggle('okvir-method-enabled', cb.checked);
            card.classList.toggle('okvir-method-disabled', !cb.checked);
        });
        
        // Update batch size
        document.querySelector('input[name="batch_size"]').value = config.batch_size;
        
        // Update thresholds
        Object.keys(config.thresholds).forEach(method => {
            const input = document.querySelector(`input[name="threshold_${method}"]`);
            if (input) {
                input.value = config.thresholds[method];
            }
        });
        
        alert(`Applied ${configType} configuration. Don't forget to save settings!`);
    }
};
</script>
