<?php
/**
 * Duplicates Management Tab
 * 
 * Interface for reviewing, managing, and deleting duplicate images.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get duplicate groups for display
$page = max(1, intval($_GET['paged'] ?? 1));
$per_page = 10;

$filters = [
    'min_confidence' => floatval($_GET['min_confidence'] ?? 0),
    'min_duplicates' => intval($_GET['min_duplicates'] ?? 1),
    'method' => sanitize_text_field($_GET['method'] ?? '')
];

$groups_data = $db_manager->get_duplicate_groups($page, $per_page, $filters);
$groups = $groups_data['groups'];
$total_pages = $groups_data['pages'];
?>

<h2>🗂️ Duplicate Image Management</h2>

<?php if (empty($groups)): ?>
    <div style="background: #f0f8ff; border: 1px solid #0073aa; border-radius: 4px; padding: 20px; margin: 20px 0; text-align: center;">
        <h3>🎉 No Duplicates Found!</h3>
        
        <?php if ($stats['analyzed_images'] == 0): ?>
            <p>No images have been analyzed yet. <a href="?page=okvir-duplicate-detector&tab=analysis">Start analyzing your images</a> to detect duplicates.</p>
        <?php else: ?>
            <p>Great news! No duplicate images were found in your analyzed library.</p>
            <p><strong><?php echo number_format($stats['analyzed_images']); ?></strong> images analyzed.</p>
        <?php endif; ?>
    </div>
<?php else: ?>

<!-- Filters -->
<div style="background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; padding: 15px; margin: 20px 0;">
    <h4>🔍 Filter Duplicate Groups</h4>
    
    <form method="get" style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
        <input type="hidden" name="page" value="okvir-duplicate-detector">
        <input type="hidden" name="tab" value="duplicates">
        
        <label>
            Min Confidence: 
            <input type="number" name="min_confidence" value="<?php echo $filters['min_confidence']; ?>" 
                   min="0" max="100" step="5" style="width: 80px;">%
        </label>
        
        <label>
            Min Duplicates: 
            <input type="number" name="min_duplicates" value="<?php echo $filters['min_duplicates']; ?>" 
                   min="1" max="50" style="width: 80px;">
        </label>
        
        <label>
            Method: 
            <select name="method">
                <option value="">All Methods</option>
                <option value="file_hash" <?php selected($filters['method'], 'file_hash'); ?>>File Hash</option>
                <option value="perceptual_hash" <?php selected($filters['method'], 'perceptual_hash'); ?>>Perceptual Hash</option>
                <option value="color_histogram" <?php selected($filters['method'], 'color_histogram'); ?>>Color Histogram</option>
                <option value="template_match" <?php selected($filters['method'], 'template_match'); ?>>Template Match</option>
                <option value="keypoint_match" <?php selected($filters['method'], 'keypoint_match'); ?>>Keypoint Match</option>
            </select>
        </label>
        
        <button type="submit" class="button">Apply Filters</button>
        <a href="?page=okvir-duplicate-detector&tab=duplicates" class="button">Clear</a>
    </form>
</div>

<!-- Bulk Actions -->
<div style="background: #fff8e1; border: 1px solid #ffb900; border-radius: 4px; padding: 15px; margin: 20px 0;">
    <h4>⚡ Bulk Actions</h4>
    
    <div style="display: flex; gap: 10px; align-items: center;">
        <button type="button" class="button" onclick="okvirDuplicateManager.selectAll()">
            ☑️ Select All Duplicates
        </button>
        
        <button type="button" class="button" onclick="okvirDuplicateManager.selectNone()">
            ☐ Deselect All
        </button>
        
        <button type="button" class="button" onclick="okvirDuplicateManager.selectHighConfidence()">
            🎯 Select High Confidence (≥90%)
        </button>
        
        <button type="button" class="button button-primary" onclick="okvirDuplicateManager.deleteSelected()">
            🗑️ Delete Selected Duplicates
        </button>
        
        <button type="button" class="button" onclick="okvirDuplicateManager.previewDeletion()">
            👁️ Preview Changes
        </button>
    </div>
</div>

<!-- Duplicate Groups -->
<div id="okvir-duplicate-groups">
    <?php foreach ($groups as $group): 
        $members = $db_manager->get_group_members($group['id']);
        $confidence_class = $group['confidence_score'] >= 90 ? 'okvir-confidence-high' : 
                           ($group['confidence_score'] >= 70 ? 'okvir-confidence-medium' : 'okvir-confidence-low');
    ?>
        <div class="okvir-duplicate-group" data-group-id="<?php echo $group['id']; ?>">
            <div class="okvir-group-header">
                <div>
                    <h4>
                        <label>
                            <input type="checkbox" class="group-checkbox" value="<?php echo $group['id']; ?>">
                            Duplicate Group #<?php echo $group['id']; ?>
                        </label>
                    </h4>
                    <div>
                        <span class="<?php echo $confidence_class; ?>">
                            <?php echo round($group['confidence_score'], 1); ?>% Confidence
                        </span>
                        | <?php echo $group['duplicate_count']; ?> duplicates
                        | <?php echo size_format($group['total_file_size']); ?> potential savings
                        | Methods: <?php echo implode(', ', json_decode($group['methods_matched'], true) ?: []); ?>
                    </div>
                </div>
                
                <div>
                    <button type="button" class="button button-small" onclick="okvirDuplicateManager.toggleGroup(<?php echo $group['id']; ?>)">
                        👁️ Toggle Details
                    </button>
                </div>
            </div>
            
            <div class="okvir-group-content" id="group-content-<?php echo $group['id']; ?>" style="display: none;">
                <div class="okvir-image-preview">
                    <?php foreach ($members as $member): 
                        $attachment_url = wp_get_attachment_url($member['attachment_id']);
                        $thumbnail_url = wp_get_attachment_image_url($member['attachment_id'], 'thumbnail');
                        $is_original = $member['is_original'];
                    ?>
                        <div class="okvir-image-item <?php echo $is_original ? 'okvir-original' : 'okvir-duplicate'; ?>">
                            <?php if ($is_original): ?>
                                <div style="color: #46b450; font-weight: bold; margin-bottom: 5px;">
                                    👑 ORIGINAL - KEEP
                                </div>
                            <?php else: ?>
                                <div style="color: #dc3232; font-weight: bold; margin-bottom: 5px;">
                                    <label>
                                        <input type="checkbox" class="duplicate-checkbox" 
                                               value="<?php echo $member['attachment_id']; ?>"
                                               data-group-id="<?php echo $group['id']; ?>">
                                        🗑️ DUPLICATE - DELETE
                                    </label>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($thumbnail_url): ?>
                                <img src="<?php echo esc_url($thumbnail_url); ?>" 
                                     alt="<?php echo esc_attr(basename($member['file_path'])); ?>">
                            <?php endif; ?>
                            
                            <div style="margin-top: 8px; font-size: 11px;">
                                <strong><?php echo esc_html(basename($member['file_path'])); ?></strong><br>
                                <?php echo $member['image_width']; ?>×<?php echo $member['image_height']; ?><br>
                                <?php echo size_format($member['file_size']); ?><br>
                                ID: <?php echo $member['attachment_id']; ?>
                            </div>
                            
                            <div style="margin-top: 5px;">
                                <a href="<?php echo esc_url($attachment_url); ?>" target="_blank" class="button button-small">
                                    🔗 View Full
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Group Actions -->
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
                    <button type="button" class="button" onclick="okvirDuplicateManager.selectGroupDuplicates(<?php echo $group['id']; ?>)">
                        ☑️ Select All Duplicates in Group
                    </button>
                    
                    <button type="button" class="button" onclick="okvirDuplicateManager.deleteGroupDuplicates(<?php echo $group['id']; ?>)">
                        🗑️ Delete Group Duplicates
                    </button>
                    
                    <button type="button" class="button" onclick="okvirDuplicateManager.showGroupReferences(<?php echo $group['id']; ?>)">
                        🔗 Show References
                    </button>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
<div style="text-align: center; margin: 20px 0;">
    <?php
    $base_url = "?page=okvir-duplicate-detector&tab=duplicates";
    if ($filters['min_confidence']) $base_url .= "&min_confidence=" . $filters['min_confidence'];
    if ($filters['min_duplicates']) $base_url .= "&min_duplicates=" . $filters['min_duplicates'];
    if ($filters['method']) $base_url .= "&method=" . $filters['method'];
    
    if ($page > 1): ?>
        <a href="<?php echo $base_url; ?>&paged=<?php echo $page - 1; ?>" class="button">« Previous</a>
    <?php endif; ?>
    
    <span style="margin: 0 10px;">
        Page <?php echo $page; ?> of <?php echo $total_pages; ?>
    </span>
    
    <?php if ($page < $total_pages): ?>
        <a href="<?php echo $base_url; ?>&paged=<?php echo $page + 1; ?>" class="button">Next »</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; ?>

<!-- Delete Results Display -->
<div id="okvir-delete-results" style="display: none;"></div>

<script>
// Duplicate management functions
window.okvirDuplicateManager = {
    // Toggle group visibility
    toggleGroup: function(groupId) {
        const content = document.getElementById('group-content-' + groupId);
        if (content.style.display === 'none') {
            content.style.display = 'block';
        } else {
            content.style.display = 'none';
        }
    },
    
    // Select all duplicates
    selectAll: function() {
        document.querySelectorAll('.duplicate-checkbox').forEach(cb => {
            cb.checked = true;
        });
    },
    
    // Deselect all
    selectNone: function() {
        document.querySelectorAll('.duplicate-checkbox').forEach(cb => {
            cb.checked = false;
        });
    },
    
    // Select high confidence duplicates
    selectHighConfidence: function() {
        document.querySelectorAll('.okvir-duplicate-group').forEach(group => {
            const groupId = group.dataset.groupId;
            const confidenceText = group.querySelector('.okvir-group-header .okvir-confidence-high');
            
            if (confidenceText) {
                group.querySelectorAll('.duplicate-checkbox').forEach(cb => {
                    cb.checked = true;
                });
            }
        });
    },
    
    // Select all duplicates in a specific group
    selectGroupDuplicates: function(groupId) {
        document.querySelectorAll(`.duplicate-checkbox[data-group-id="${groupId}"]`).forEach(cb => {
            cb.checked = true;
        });
    },
    
    // Delete selected duplicates
    deleteSelected: function() {
        const selected = document.querySelectorAll('.duplicate-checkbox:checked');
        const duplicateIds = Array.from(selected).map(cb => cb.value);
        
        if (duplicateIds.length === 0) {
            alert('Please select duplicates to delete');
            return;
        }
        
        if (!confirm(`Are you sure you want to delete ${duplicateIds.length} duplicate images?\n\nThis will:\n- Delete the duplicate files\n- Replace all references with original images\n- Create backups if enabled\n\nThis action cannot be undone.`)) {
            return;
        }
        
        okvirDupDetector.deleteSelectedDuplicates();
    },
    
    // Delete all duplicates in a group
    deleteGroupDuplicates: function(groupId) {
        const groupCheckboxes = document.querySelectorAll(`.duplicate-checkbox[data-group-id="${groupId}"]`);
        const duplicateIds = Array.from(groupCheckboxes).map(cb => cb.value);
        
        if (duplicateIds.length === 0) {
            alert('No duplicates found in this group');
            return;
        }
        
        if (!confirm(`Delete all ${duplicateIds.length} duplicates in this group?`)) {
            return;
        }
        
        // Select all checkboxes in the group and trigger deletion
        groupCheckboxes.forEach(cb => cb.checked = true);
        okvirDupDetector.deleteSelectedDuplicates();
    },
    
    // Show references for a group
    showGroupReferences: function(groupId) {
        // This would open a modal showing all content references
        alert('Reference viewer will be implemented');
    },
    
    // Preview deletion changes
    previewDeletion: function() {
        const selected = document.querySelectorAll('.duplicate-checkbox:checked');
        const duplicateIds = Array.from(selected).map(cb => cb.value);
        
        if (duplicateIds.length === 0) {
            alert('Please select duplicates to preview');
            return;
        }
        
        // Show preview modal
        this.showPreviewModal(duplicateIds);
    },
    
    // Show preview modal
    showPreviewModal: function(duplicateIds) {
        const modal = document.createElement('div');
        modal.style.cssText = `
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); z-index: 100000; display: flex;
            align-items: center; justify-content: center;
        `;
        
        modal.innerHTML = `
            <div style="background: white; padding: 20px; border-radius: 8px; max-width: 80%; max-height: 80%; overflow: auto;">
                <h3>🔍 Deletion Preview</h3>
                <p>The following changes will be made:</p>
                <div id="preview-content">Loading preview...</div>
                <div style="margin-top: 20px; text-align: right;">
                    <button type="button" class="button" onclick="this.closest('.modal').remove()">Cancel</button>
                    <button type="button" class="button button-primary" onclick="this.closest('.modal').remove(); okvirDupDetector.deleteSelectedDuplicates();">
                        Proceed with Deletion
                    </button>
                </div>
            </div>
        `;
        
        modal.className = 'modal';
        document.body.appendChild(modal);
        
        // Load preview data via AJAX
        fetch(ajaxurl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'okvir_dup_preview_deletion',
                nonce: okvirDupDetector.nonce,
                duplicate_ids: JSON.stringify(duplicateIds)
            })
        })
        .then(response => response.json())
        .then(data => {
            const previewContent = document.getElementById('preview-content');
            if (data.success) {
                previewContent.innerHTML = this.formatPreviewData(data.data);
            } else {
                previewContent.innerHTML = '<p style="color: #dc3232;">Failed to load preview: ' + (data.data || 'Unknown error') + '</p>';
            }
        })
        .catch(error => {
            document.getElementById('preview-content').innerHTML = '<p style="color: #dc3232;">Error loading preview: ' + error.message + '</p>';
        });
    },
    
    // Format preview data
    formatPreviewData: function(previewData) {
        let html = '<ul>';
        
        previewData.forEach(item => {
            html += `
                <li>
                    <strong>${item.duplicate_file}</strong> → <strong>${item.original_file}</strong>
                    <br>
                    <small>
                        ${item.total_references} references will be updated
                        (${Object.entries(item.reference_breakdown).map(([type, count]) => `${count} ${type}`).join(', ')})
                    </small>
                </li>
            `;
        });
        
        html += '</ul>';
        return html;
    }
};

// Auto-expand groups with high confidence
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.okvir-confidence-high').forEach(element => {
        const group = element.closest('.okvir-duplicate-group');
        if (group) {
            const groupId = group.dataset.groupId;
            const content = document.getElementById('group-content-' + groupId);
            if (content) {
                content.style.display = 'block';
            }
        }
    });
});
</script>
