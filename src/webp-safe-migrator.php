<?php
/**
 * Plugin Name: WebP Safe Migrator
 * Description: Convert non-WebP media to WebP at a fixed quality, update all usages & metadata safely, then (optionally) remove originals after validation. Includes WP-CLI, skip rules, and change reports.
 * Version:     0.2.0
 * Author:      Your Name
 * License:     GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

class WebP_Safe_Migrator {
    const OPTION = 'webp_safe_migrator_settings';
    const NONCE  = 'webp_safe_migrator_nonce';
    const STATUS_META = '_webp_migrator_status';         // converted|relinked|committed|skipped_animated_gif|convert_failed|metadata_failed
    const BACKUP_META = '_webp_migrator_backup_dir';
    const REPORT_META = '_webp_migrator_report';         // JSON-encoded per-attachment report
    const DEFAULT_BASE_MIMES = ['image/jpeg','image/png','image/gif'];

    /** @var array */
    private $settings;

    /** Allow CLI to tweak validation at runtime */
    private $runtime_validation_override = null;

    public function __construct() {
        $this->settings = wp_parse_args(get_option(self::OPTION, []), [
            'quality'     => 59,
            'batch_size'  => 10,
            'validation'  => 1,     // 1 = validate (keep originals), 0 = delete originals immediately
            'skip_folders'=> "",    // textarea, one per line (relative to uploads), substring match
            'skip_mimes'  => "",    // comma/space separated MIME types to skip (e.g. "image/gif")
        ]);

        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'handle_actions']);
        register_activation_hook(__FILE__, [$this, 'on_activate']);

        // WP-CLI
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('webp-migrator', ['WebP_Safe_Migrator_CLI', 'dispatch']);
        }
    }

    /** Singleton-ish accessor for CLI */
    public static function instance() {
        return $GLOBALS['webp_safe_migrator'] ?? null;
    }

    public function set_runtime_validation($validate_mode_bool) {
        $this->runtime_validation_override = (bool)$validate_mode_bool;
    }

    public function on_activate() {
        // Check for WebP support
        $gd_webp = function_exists('imagewebp');
        $imagick_webp = class_exists('Imagick');
        if ($imagick_webp) {
            try {
                $i = new Imagick();
                $imagick_webp = in_array('WEBP', $i->queryFormats('WEBP'), true);
            } catch (Throwable $e) {
                $imagick_webp = false;
            }
        }
        if (!$gd_webp && !$imagick_webp) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die('WebP Safe Migrator requires GD (imagewebp) or Imagick with WEBP support.');
        }
    }

    public function menu() {
        add_media_page('WebP Safe Migrator', 'WebP Migrator', 'manage_options', 'webp-safe-migrator', [$this, 'render_main']);
        add_submenu_page(
            'upload.php',
            'WebP Migrator Reports',
            'WebP Reports',
            'manage_options',
            'webp-safe-migrator-reports',
            [$this, 'render_reports']
        );
    }

    private function update_settings_from_request() {
        if (!current_user_can('manage_options')) return;
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce($_POST[self::NONCE], 'save_settings')) return;

        $quality    = isset($_POST['quality']) ? max(1, min(100, (int)$_POST['quality'])) : 59;
        $batch_size = isset($_POST['batch_size']) ? max(1, min(1000, (int)$_POST['batch_size'])) : 10;
        $validation = isset($_POST['validation']) ? 1 : 0;

        $skip_folders_raw = isset($_POST['skip_folders']) ? (string)$_POST['skip_folders'] : '';
        $skip_mimes_raw   = isset($_POST['skip_mimes']) ? (string)$_POST['skip_mimes'] : '';

        $this->settings = [
            'quality'     => $quality,
            'batch_size'  => $batch_size,
            'validation'  => $validation,
            'skip_folders'=> $skip_folders_raw,
            'skip_mimes'  => $skip_mimes_raw,
        ];
        update_option(self::OPTION, $this->settings);
        add_settings_error('webp_safe_migrator', 'saved', 'Settings saved.', 'updated');
    }

    public function handle_actions() {
        if (!current_user_can('manage_options')) return;

        // Save settings
        if (isset($_POST['webp_migrator_save_settings'])) {
            $this->update_settings_from_request();
        }

        // Run batch conversion
        if (isset($_POST['webp_migrator_run']) && wp_verify_nonce($_POST[self::NONCE] ?? '', 'run_batch')) {
            $batch = $this->get_non_webp_attachments($this->settings['batch_size']);
            $processed = 0;
            foreach ($batch as $att_id) {
                if ($this->process_attachment((int)$att_id, $this->settings['quality'], $this->current_validation_mode())) {
                    $processed++;
                }
            }
            add_settings_error('webp_safe_migrator', 'batch', "Batch processed ({$processed}/".count($batch).").", 'updated');
        }

        // Commit one
        if (isset($_POST['webp_migrator_commit_one']) && wp_verify_nonce($_POST[self::NONCE] ?? '', 'commit_one')) {
            $att_id = (int)($_POST['attachment_id'] ?? 0);
            $ok = $this->commit_deletions($att_id);
            if ($ok) {
                add_settings_error('webp_safe_migrator', 'commit', "Committed deletions for attachment #{$att_id}.", 'updated');
            } else {
                add_settings_error('webp_safe_migrator', 'commit_err', "Nothing to delete or commit failed for #{$att_id}.", 'error');
            }
        }

        // Commit all
        if (isset($_POST['webp_migrator_commit_all']) && wp_verify_nonce($_POST[self::NONCE] ?? '', 'commit_all')) {
            global $wpdb;
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id
                 WHERE p.post_type='attachment' AND pm.meta_key=%s AND pm.meta_value='relinked'",
                 self::STATUS_META
            ));
            $count = 0;
            foreach ($ids as $att_id) {
                if ($this->commit_deletions((int)$att_id)) $count++;
            }
            add_settings_error('webp_safe_migrator', 'commit_all', "Committed deletions for {$count} attachments.", 'updated');
        }
    }

    private function current_validation_mode(): bool {
        if ($this->runtime_validation_override !== null) return (bool)$this->runtime_validation_override;
        return (bool)$this->settings['validation'];
    }

    public function render_main() {
        settings_errors('webp_safe_migrator');
        $quality    = (int)$this->settings['quality'];
        $batch_size = (int)$this->settings['batch_size'];
        $validation = (int)$this->settings['validation'];
        $skip_folders = (string)$this->settings['skip_folders'];
        $skip_mimes   = (string)$this->settings['skip_mimes'];
        ?>
        <div class="wrap">
            <h1>WebP Safe Migrator</h1>
            <p>Convert all non-WebP images to WebP at the chosen quality, update usages and metadata safely, then delete originals after validation.</p>

            <form method="post">
                <?php wp_nonce_field('save_settings', self::NONCE); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="quality">Quality (0–100)</label></th>
                        <td><input type="number" name="quality" id="quality" min="1" max="100" value="<?php echo esc_attr($quality); ?>"></td>
                    </tr>
                    <tr><th scope="row"><label for="batch_size">Batch size</label></th>
                        <td><input type="number" name="batch_size" id="batch_size" min="1" max="1000" value="<?php echo esc_attr($batch_size); ?>"></td>
                    </tr>
                    <tr><th scope="row"><label for="validation">Validation mode</label></th>
                        <td><label><input type="checkbox" name="validation" <?php checked($validation, 1); ?>> Keep originals until you press “Commit”</label></td>
                    </tr>
                    <tr><th scope="row"><label for="skip_folders">Skip folders</label></th>
                        <td>
                            <textarea name="skip_folders" id="skip_folders" rows="4" cols="50" placeholder="e.g. cache
private-uploads"><?php echo esc_textarea($skip_folders); ?></textarea>
                            <p class="description">One per line, relative to <code>wp-content/uploads</code>. Substring match, case-insensitive.</p>
                        </td>
                    </tr>
                    <tr><th scope="row"><label for="skip_mimes">Skip MIME types</label></th>
                        <td>
                            <input type="text" name="skip_mimes" id="skip_mimes" value="<?php echo esc_attr($skip_mimes); ?>" placeholder="e.g. image/gif">
                            <p class="description">Comma/space separated list (e.g. <code>image/gif, image/png</code>)</p>
                        </td>
                    </tr>
                </table>
                <p>
                    <button class="button button-primary" name="webp_migrator_save_settings" value="1">Save settings</button>
                </p>
            </form>

            <hr/>
            <h2>Run batch</h2>
            <p><strong>Tip:</strong> back up your database and uploads before large migrations.</p>
            <form method="post">
                <?php wp_nonce_field('run_batch', self::NONCE); ?>
                <button class="button button-secondary" name="webp_migrator_run" value="1">Process next batch</button>
                <a class="button" href="<?php echo esc_url(admin_url('upload.php?page=webp-safe-migrator-reports')); ?>">View reports</a>
            </form>

            <hr/>
            <h2>Pending commits</h2>
            <?php $this->render_pending_commits(); ?>

            <hr/>
            <h2>Non-WebP attachments (preview)</h2>
            <?php $this->render_queue_preview(); ?>
        </div>
        <?php
    }

    public function render_reports() {
        if (!current_user_can('manage_options')) return;
        $att_id = isset($_GET['attachment_id']) ? (int)$_GET['attachment_id'] : 0;

        echo '<div class="wrap"><h1>WebP Migrator Reports</h1>';

        if ($att_id) {
            $this->render_single_report($att_id);
            echo '<p><a class="button" href="'.esc_url(admin_url('upload.php?page=webp-safe-migrator-reports')).'">&larr; Back to list</a></p>';
            echo '</div>';
            return;
        }

        // List recent reports
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, pm.meta_value AS report
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id
             WHERE p.post_type='attachment' AND pm.meta_key=%s
             ORDER BY p.ID DESC
             LIMIT 200",
            self::REPORT_META
        ), ARRAY_A);

        if (!$rows) {
            echo '<p>No reports yet.</p></div>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Title</th><th>When</th><th>Posts</th><th>Postmeta</th><th>Options</th><th>Users</th><th>Terms</th><th>Comments</th><th>Custom</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $id = (int)$r['ID'];
            $report = json_decode($r['report'], true) ?: [];
            $when = !empty($report['ts']) ? esc_html($report['ts']) : '—';
            $cntP = isset($report['posts']) ? count($report['posts']) : 0;
            $cntM = isset($report['postmeta']) ? count($report['postmeta']) : 0;
            $cntO = isset($report['options']) ? count($report['options']) : 0;
            $cntU = isset($report['usermeta']) ? count($report['usermeta']) : 0;
            $cntT = isset($report['termmeta']) ? count($report['termmeta']) : 0;
            $cntC = isset($report['comments']) ? count($report['comments']) : 0;
            $cntX = isset($report['custom_tables']) ? count($report['custom_tables']) : 0;
            echo '<tr>';
            echo '<td>'.esc_html($id).'</td>';
            echo '<td>'.esc_html(get_the_title($id)).'</td>';
            echo '<td>'.$when.'</td>';
            echo '<td>'.$cntP.'</td>';
            echo '<td>'.$cntM.'</td>';
            echo '<td>'.$cntO.'</td>';
            echo '<td>'.$cntU.'</td>';
            echo '<td>'.$cntT.'</td>';
            echo '<td>'.$cntC.'</td>';
            echo '<td>'.$cntX.'</td>';
            echo '<td><a class="button" href="'.esc_url(admin_url('upload.php?page=webp-safe-migrator-reports&attachment_id='.$id)).'">View</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    private function render_single_report($att_id) {
        $report = json_decode(get_post_meta($att_id, self::REPORT_META, true) ?: '[]', true);
        echo '<h2>Attachment #'.esc_html($att_id).' — '.esc_html(get_the_title($att_id)).'</h2>';
        $thumb = wp_get_attachment_image($att_id, [120,120], true);
        echo '<p>'.$thumb.'</p>';

        if (!$report) { echo '<p>No report stored.</p>'; return; }

        echo '<p><strong>Migrated:</strong> '.esc_html($report['ts'] ?? '—').'</p>';
        echo '<h3>URL Map Count</h3><p>'.intval($report['map_count'] ?? 0).'</p>';

        echo '<h3>Posts updated</h3>';
        if (!empty($report['posts'])) {
            echo '<ul>';
            foreach ($report['posts'] as $pid) {
                $edit = get_edit_post_link($pid);
                echo '<li>#'.intval($pid).' — '.esc_html(get_the_title($pid)).' '.($edit ? '<a href="'.esc_url($edit).'">Edit</a>' : '').'</li>';
            }
            echo '</ul>';
        } else echo '<p>—</p>';

        echo '<h3>Postmeta updated</h3>';
        if (!empty($report['postmeta'])) {
            echo '<table class="widefat striped"><thead><tr><th>Post ID</th><th>Meta key</th></tr></thead><tbody>';
            foreach ($report['postmeta'] as $row) {
                echo '<tr><td>'.intval($row['post_id']).'</td><td>'.esc_html($row['meta_key']).'</td></tr>';
            }
            echo '</tbody></table>';
        } else echo '<p>—</p>';

        echo '<h3>Options updated</h3>';
        if (!empty($report['options'])) {
            echo '<ul>';
            foreach ($report['options'] as $opt) {
                echo '<li><code>'.esc_html($opt).'</code></li>';
            }
            echo '</ul>';
        } else echo '<p>—</p>';

        echo '<h3>User metadata updated</h3>';
        if (!empty($report['usermeta'])) {
            echo '<table class="widefat striped"><thead><tr><th>User ID</th><th>Meta key</th><th>User</th></tr></thead><tbody>';
            foreach ($report['usermeta'] as $row) {
                $user = get_user_by('id', $row['user_id']);
                $username = $user ? $user->user_login : 'Unknown';
                echo '<tr><td>'.intval($row['user_id']).'</td><td>'.esc_html($row['meta_key']).'</td><td>'.esc_html($username).'</td></tr>';
            }
            echo '</tbody></table>';
        } else echo '<p>—</p>';

        echo '<h3>Term metadata updated</h3>';
        if (!empty($report['termmeta'])) {
            echo '<table class="widefat striped"><thead><tr><th>Term ID</th><th>Meta key</th><th>Term</th></tr></thead><tbody>';
            foreach ($report['termmeta'] as $row) {
                $term = get_term($row['term_id']);
                $term_name = $term && !is_wp_error($term) ? $term->name : 'Unknown';
                echo '<tr><td>'.intval($row['term_id']).'</td><td>'.esc_html($row['meta_key']).'</td><td>'.esc_html($term_name).'</td></tr>';
            }
            echo '</tbody></table>';
        } else echo '<p>—</p>';

        echo '<h3>Comments updated</h3>';
        if (!empty($report['comments'])) {
            echo '<ul>';
            foreach ($report['comments'] as $cid) {
                $comment = get_comment($cid);
                $author = $comment ? $comment->comment_author : 'Unknown';
                $post_title = get_the_title($comment->comment_post_ID ?? 0);
                echo '<li>#'.intval($cid).' — Comment by '.esc_html($author).' on "'.esc_html($post_title).'"</li>';
            }
            echo '</ul>';
        } else echo '<p>—</p>';

        echo '<h3>Custom tables updated</h3>';
        if (!empty($report['custom_tables'])) {
            echo '<table class="widefat striped"><thead><tr><th>Table</th><th>Column</th><th>Row ID</th></tr></thead><tbody>';
            foreach ($report['custom_tables'] as $row) {
                echo '<tr><td>'.esc_html($row['table']).'</td><td>'.esc_html($row['column']).'</td><td>'.esc_html($row['row_id']).'</td></tr>';
            }
            echo '</tbody></table>';
            echo '<p><em>Note: Custom tables updated include e-commerce products, plugin galleries, and other JSON/serialized data.</em></p>';
        } else echo '<p>—</p>';
    }

    private function render_pending_commits() {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID=pm.post_id
             WHERE p.post_type='attachment' AND pm.meta_key=%s AND pm.meta_value='relinked'
             ORDER BY p.ID DESC LIMIT 50",
            self::STATUS_META
        ), ARRAY_A);

        if (!$rows) { echo '<p>No items awaiting commit.</p>'; return; }

        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Title</th><th>Preview</th><th>Actions</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $id = (int)$r['ID'];
            $thumb = wp_get_attachment_image($id, [80,80], true);
            echo '<tr>';
            echo '<td>'.esc_html($id).'</td>';
            echo '<td>'.esc_html($r['post_title']).'</td>';
            echo '<td>'.$thumb.'</td>';
            echo '<td>';
            echo '<form method="post" style="display:inline-block;margin-right:6px;">';
            wp_nonce_field('commit_one', self::NONCE);
            echo '<input type="hidden" name="attachment_id" value="'.esc_attr($id).'">';
            echo '<button class="button button-primary" name="webp_migrator_commit_one" value="1">Commit delete</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        echo '<form method="post" style="margin-top:10px;">';
        wp_nonce_field('commit_all', self::NONCE);
        echo '<button class="button" name="webp_migrator_commit_all" value="1">Commit ALL above</button>';
        echo '</form>';
    }

    private function render_queue_preview() {
        $ids = $this->get_non_webp_attachments(20);
        if (!$ids) { echo '<p>None found (or all skipped by filters).</p>'; return; }
        echo '<ul>';
        foreach ($ids as $id) {
            $file = get_attached_file($id);
            $type = get_post_mime_type($id);
            echo '<li>#'.esc_html($id).' — '.esc_html(basename($file)).' ('.esc_html($type).')</li>';
        }
        echo '</ul>';
    }

    /** Parse skip settings */
    private function get_skip_rules(): array {
        $folders = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$this->settings['skip_folders'])));
        $mimes   = preg_split('/[\s,]+/', (string)$this->settings['skip_mimes'], -1, PREG_SPLIT_NO_EMPTY);
        $mimes   = array_map('trim', $mimes);
        return [ $folders, $mimes ];
    }

    /** Return $limit attachment IDs matching base mimes minus skipped mimes; apply folder skipping */
    public function get_non_webp_attachments($limit = 10): array {
        global $wpdb;
        [$skip_folders, $skip_mimes] = $this->get_skip_rules();

        // Target mimes are default base mimes minus skip_mimes
        $target_mimes = array_values(array_diff(self::DEFAULT_BASE_MIMES, $skip_mimes));
        if (!$target_mimes) return [];

        // Over-fetch to allow for folder-based skipping
        $fetch = max($limit * 5, 50);
        $in = implode(',', array_fill(0, count($target_mimes), '%s'));
        $sql = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type='attachment'
               AND post_mime_type IN ($in)
             ORDER BY ID ASC
             LIMIT %d",
            ...$target_mimes, (int)$fetch
        );
        $candidates = $wpdb->get_col($sql);

        $result = [];
        $uploads = wp_get_upload_dir();

        foreach ($candidates as $id) {
            $id = (int)$id;
            $mime = get_post_mime_type($id);
            if ($mime === 'image/webp') continue;

            $file = get_attached_file($id);
            if (!$file) continue;

            // skip folders (substring match against relative path)
            $rel = ltrim(str_replace(trailingslashit($uploads['basedir']), '', $file), '/');
            $skip = false;
            foreach ($skip_folders as $frag) {
                if ($frag !== '' && stripos($rel, $frag) !== false) { $skip = true; break; }
            }
            if ($skip) continue;

            $result[] = $id;
            if (count($result) >= $limit) break;
        }
        return $result;
    }

    private function is_animated_gif($path) {
        if (function_exists('imagecreatefromgif')) {
            $contents = @file_get_contents($path, false, null, 0, 1024 * 128); // first chunk
            return $contents && strpos($contents, 'NETSCAPE2.0') !== false; // simple/fast indicator
        }
        return false;
    }

    public function process_attachment($att_id, $quality, $validation_mode) {
        $file = get_attached_file($att_id);
        if (!$file || !file_exists($file)) return false;

        $mime = get_post_mime_type($att_id);
        if ($mime === 'image/webp') return true;

        // Skip animated GIF unless animated WebP is implemented
        if ($mime === 'image/gif' && $this->is_animated_gif($file)) {
            update_post_meta($att_id, self::STATUS_META, 'skipped_animated_gif');
            return false;
        }

        $uploads = wp_get_upload_dir();
        $old_meta = wp_get_attachment_metadata($att_id);
        if (!$old_meta || empty($old_meta['file'])) $old_meta = $this->build_metadata_fallback($file, $att_id);

        $old_rel      = $old_meta['file'];                                   // '2025/08/image.jpg'
        $old_dir_rel  = trailingslashit(dirname($old_rel));
        $old_basename = wp_basename($old_rel);
        $new_rel      = $old_dir_rel . preg_replace('/\.\w+$/', '.webp', $old_basename);
        $new_path     = trailingslashit($uploads['basedir']) . $new_rel;

        if (!wp_mkdir_p(dirname($new_path))) return false;

        // Convert original → WebP
        $converted = $this->convert_to_webp($file, $new_path, $quality);
        if (!$converted) {
            update_post_meta($att_id, self::STATUS_META, 'convert_failed');
            return false;
        }

        // Generate fresh metadata/sizes from WebP original
        $new_meta = wp_generate_attachment_metadata($att_id, $new_path);
        if (!$new_meta || empty($new_meta['file'])) {
            update_post_meta($att_id, self::STATUS_META, 'metadata_failed');
            return false;
        }

        // Build URL mapping (old → new) for original and sizes
        $map = $this->build_url_map($uploads, $old_meta, $new_meta);

        // Update usages across DB and collect a report of changes
        $report = $this->replace_everywhere($map);

        // Update attachment post + metas
        wp_update_post([
            'ID'             => $att_id,
            'post_mime_type' => 'image/webp',
            'guid'           => $uploads['baseurl'] . '/' . $new_meta['file'],
        ]);
        update_post_meta($att_id, '_wp_attached_file', $new_meta['file']);
        wp_update_attachment_metadata($att_id, $new_meta);

        // Store report
        $report_payload = [
            'ts'            => current_time('mysql'),
            'map_count'     => count($map),
            'posts'         => array_values(array_unique($report['posts'] ?? [])),
            'postmeta'      => array_values($report['postmeta'] ?? []),
            'options'       => array_values(array_unique($report['options'] ?? [])),
            'usermeta'      => array_values($report['usermeta'] ?? []),
            'termmeta'      => array_values($report['termmeta'] ?? []),
            'comments'      => array_values(array_unique($report['comments'] ?? [])),
            'custom_tables' => array_values($report['custom_tables'] ?? []),
        ];
        update_post_meta($att_id, self::REPORT_META, wp_json_encode($report_payload));

        // Backup originals to a safe folder (deleted on commit)
        $backup_dir = trailingslashit($uploads['basedir']) . 'webp-migrator-backup/' . date('Ymd-His') . "/att-{$att_id}/";
        if (!wp_mkdir_p($backup_dir)) $backup_dir = null;

        // Move/delete old files
        $this->collect_and_remove_old_files($uploads, $old_meta, $validation_mode, $backup_dir);

        // Mark status for UI
        update_post_meta($att_id, self::STATUS_META, $validation_mode ? 'relinked' : 'committed');
        if ($backup_dir) update_post_meta($att_id, self::BACKUP_META, $backup_dir);

        return true;
    }

    private function build_metadata_fallback($path, $att_id) {
        $uploads = wp_get_upload_dir();
        $rel = ltrim(str_replace(trailingslashit($uploads['basedir']), '', $path), '/');
        return ['file' => $rel, 'sizes' => []];
    }

    private function convert_to_webp($src, $dest, $quality) {
        $editor = wp_get_image_editor($src);
        if (is_wp_error($editor)) return false;
        if (method_exists($editor, 'set_quality')) $editor->set_quality((int)$quality);
        $saved = $editor->save($dest, 'image/webp');
        return !is_wp_error($saved);
    }

    private function build_url_map($uploads, $old_meta, $new_meta) {
        $map = [];

        $old_orig_rel = $old_meta['file'];
        $new_orig_rel = $new_meta['file'];
        $map[$uploads['baseurl'].'/'.$old_orig_rel] = $uploads['baseurl'].'/'.$new_orig_rel;

        $old_dir_rel = trailingslashit(dirname($old_orig_rel));
        $new_dir_rel = trailingslashit(dirname($new_orig_rel));

        $old_sizes = isset($old_meta['sizes']) && is_array($old_meta['sizes']) ? $old_meta['sizes'] : [];
        $new_sizes = isset($new_meta['sizes']) && is_array($new_meta['sizes']) ? $new_meta['sizes'] : [];

        foreach ($new_sizes as $size => $n) {
            if (!isset($old_sizes[$size]['file'])) continue; // only map names we had before
            $old_file = $uploads['baseurl'].'/'.$old_dir_rel.$old_sizes[$size]['file'];
            $new_file = $uploads['baseurl'].'/'.$new_dir_rel.$n['file'];
            $map[$old_file] = $new_file;
        }

        // Filesystem path mappings (rare but helps)
        $map[trailingslashit($uploads['basedir']).$old_orig_rel] = trailingslashit($uploads['basedir']).$new_orig_rel;
        foreach ($new_sizes as $size => $n) {
            if (!isset($old_sizes[$size]['file'])) continue;
            $map[trailingslashit($uploads['basedir']).$old_dir_rel.$old_sizes[$size]['file']]
                = trailingslashit($uploads['basedir']).$new_dir_rel.$n['file'];
        }

        // Extension swap helpers
        $exts = ['jpg','jpeg','png','gif'];
        foreach ($exts as $ext) {
            $map_ext = function($url){ return preg_replace('/\.(jpg|jpeg|png|gif)\b/i', '.webp', $url); };
            foreach (array_keys($map) as $k) {
                $map[$map_ext($k)] = $map[$k];
            }
        }

        return $map;
    }

    /** Replace everywhere and collect a report of what changed */
    private function replace_everywhere(array $url_map): array {
        $report = [
            'posts'         => [],
            'postmeta'      => [],
            'options'       => [],
            'usermeta'      => [],
            'termmeta'      => [],
            'comments'      => [],
            'custom_tables' => [],
        ];

        // POSTS
        global $wpdb;
        foreach ($url_map as $old => $new) {
            if ($old === $new) continue;
            $like = '%' . $wpdb->esc_like($old) . '%';
            $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s", $like));
            foreach ($ids as $pid) {
                $post = get_post($pid);
                if (!$post) continue;
                $content = $post->post_content;
                $new_content = str_replace($old, $new, $content);
                if ($new_content !== $content) {
                    wp_update_post(['ID' => $pid, 'post_content' => $new_content]);
                    $report['posts'][] = (int)$pid;
                }
            }
        }

        // POSTMETA
        $changed_meta = $this->replace_in_table_serialized_with_report($url_map, 'postmeta', 'meta_value', function($row){
            return function($new_value) use ($row){
                return update_post_meta((int)$row->post_id, $row->meta_key, $new_value);
            };
        }, function($row) use (&$report){
            $report['postmeta'][] = ['post_id' => (int)$row->post_id, 'meta_key' => (string)$row->meta_key];
        });

        // OPTIONS
        $this->replace_in_table_serialized_with_report($url_map, 'options', 'option_value', function($row){
            return function($new_value) use ($row){
                return update_option($row->option_name, $new_value);
            };
        }, function($row) use (&$report){
            $report['options'][] = (string)$row->option_name;
        });

        // USERMETA (user profile images, avatars, etc.)
        $this->replace_in_table_serialized_with_report($url_map, 'usermeta', 'meta_value', function($row){
            return function($new_value) use ($row){
                return update_user_meta((int)$row->user_id, $row->meta_key, $new_value);
            };
        }, function($row) use (&$report){
            $report['usermeta'][] = ['user_id' => (int)$row->user_id, 'meta_key' => (string)$row->meta_key];
        });

        // TERMMETA (category/tag images, taxonomy metadata)
        $this->replace_in_table_serialized_with_report($url_map, 'termmeta', 'meta_value', function($row){
            return function($new_value) use ($row){
                return update_term_meta((int)$row->term_id, $row->meta_key, $new_value);
            };
        }, function($row) use (&$report){
            $report['termmeta'][] = ['term_id' => (int)$row->term_id, 'meta_key' => (string)$row->meta_key];
        });

        // COMMENTS (image references in comment content)
        foreach ($url_map as $old => $new) {
            if ($old === $new) continue;
            $like = '%' . $wpdb->esc_like($old) . '%';
            $comment_ids = $wpdb->get_col($wpdb->prepare("SELECT comment_ID FROM {$wpdb->comments} WHERE comment_content LIKE %s", $like));
            foreach ($comment_ids as $cid) {
                $comment = get_comment($cid);
                if (!$comment) continue;
                $content = $comment->comment_content;
                $new_content = str_replace($old, $new, $content);
                if ($new_content !== $content) {
                    wp_update_comment([
                        'comment_ID' => $cid,
                        'comment_content' => $new_content
                    ]);
                    $report['comments'][] = (int)$cid;
                }
            }
        }

        // ENHANCED: Search for JSON-encoded image references in any column
        // This helps with modern plugins and e-commerce that store data as JSON
        $this->replace_in_json_columns($url_map, $report);

        return $report;
    }

    private function replace_in_table_serialized_with_report(array $url_map, $table, $value_col, $update_closure_factory, $on_changed_row) {
        global $wpdb;

        // Build WHERE with OR of LIKEs (cap # of probes for perf)
        $likes = [];
        $map_keys = array_slice(array_keys($url_map), 0, 10);
        foreach ($map_keys as $k) {
            $likes[] = $wpdb->prepare("$value_col LIKE %s", '%'.$wpdb->esc_like($k).'%');
        }
        if (!$likes) return;

        $table_name = $table === 'postmeta' ? $wpdb->postmeta : 
                      ($table === 'options' ? $wpdb->options :
                      ($table === 'usermeta' ? $wpdb->usermeta :
                      ($table === 'termmeta' ? $wpdb->termmeta : null)));
        
        if (!$table_name) return; // Unknown table type
        
        $pk = $table === 'postmeta' ? 'meta_id' : 
              ($table === 'options' ? 'option_id' :
              ($table === 'usermeta' ? 'umeta_id' :
              ($table === 'termmeta' ? 'meta_id' : 'id')));
        $sql = "SELECT * FROM {$table_name} WHERE " . implode(' OR ', $likes) . " LIMIT 5000";
        $rows = $wpdb->get_results($sql);

        foreach ($rows as $row) {
            $raw = $row->{$value_col};
            $value = maybe_unserialize($raw);
            $new_value = $this->deep_replace($value, $url_map);
            if ($new_value !== $value) {
                $update = $update_closure_factory($row);
                $update(maybe_serialize($new_value));
                $on_changed_row($row);
            }
        }
    }

    private function deep_replace($data, array $url_map) {
        if (is_string($data)) {
            foreach ($url_map as $old => $new) {
                if ($old !== $new) $data = str_replace($old, $new, $data);
            }
            return $data;
        } elseif (is_array($data)) {
            foreach ($data as $k => $v) $data[$k] = $this->deep_replace($v, $url_map);
            return $data;
        } elseif (is_object($data)) {
            foreach ($data as $k => $v) $data->$k = $this->deep_replace($v, $url_map);
            return $data;
        }
        return $data;
    }

    private function collect_and_remove_old_files($uploads, $old_meta, $validation_mode, $backup_dir = null) {
        $paths = [];
        $old_rel = $old_meta['file'];
        $dir_rel = trailingslashit(dirname($old_rel));
        $paths[] = trailingslashit($uploads['basedir']).$old_rel;

        if (!empty($old_meta['sizes']) && is_array($old_meta['sizes'])) {
            foreach ($old_meta['sizes'] as $size) {
                if (empty($size['file'])) continue;
                $paths[] = trailingslashit($uploads['basedir']).$dir_rel.$size['file'];
            }
        }

        foreach ($paths as $p) {
            if (!file_exists($p)) continue;
            if ($validation_mode) {
                if ($backup_dir) {
                    @wp_mkdir_p($backup_dir);
                    @rename($p, trailingslashit($backup_dir).basename($p));
                }
            } else {
                @unlink($p);
            }
        }
    }

    private function commit_deletions($att_id) {
        $status = get_post_meta($att_id, self::STATUS_META, true);
        if ($status !== 'relinked') return false;

        $backup_dir = get_post_meta($att_id, self::BACKUP_META, true);
        if ($backup_dir && is_dir($backup_dir)) {
            $this->rrmdir($backup_dir);
        }
        update_post_meta($att_id, self::STATUS_META, 'committed');
        delete_post_meta($att_id, self::BACKUP_META);
        return true;
    }

    private function rrmdir($dir) {
        if (!is_dir($dir)) return false;
        $files = scandir($dir);
        foreach ($files as $f) {
            if ($f === '.' || $f === '..') continue;
            $path = $dir.DIRECTORY_SEPARATOR.$f;
            if (is_dir($path)) $this->rrmdir($path); else @unlink($path);
        }
        return @rmdir($dir);
    }

    /**
     * Enhanced JSON column search for modern plugins and e-commerce
     * Searches for image URLs in JSON-encoded columns across custom tables
     */
    private function replace_in_json_columns(array $url_map, array &$report) {
        global $wpdb;

        // Get list of all custom tables (non-WordPress core)
        $wp_tables = [
            $wpdb->posts, $wpdb->postmeta, $wpdb->options, $wpdb->users, $wpdb->usermeta,
            $wpdb->comments, $wpdb->commentmeta, $wpdb->terms, $wpdb->termmeta, $wpdb->term_relationships,
            $wpdb->term_taxonomy, $wpdb->links
        ];
        
        $all_tables = $wpdb->get_col("SHOW TABLES");
        $custom_tables = array_diff($all_tables, $wp_tables);
        
        if (!$custom_tables) return; // No custom tables to check

        // Initialize custom tables tracking in report
        if (!isset($report['custom_tables'])) {
            $report['custom_tables'] = [];
        }

        // Check each custom table for potential JSON columns containing image URLs
        foreach (array_slice($custom_tables, 0, 10) as $table) { // Limit to 10 tables for performance
            try {
                // Get columns that might contain JSON data
                $columns = $wpdb->get_results("DESCRIBE `$table`");
                $json_candidates = [];
                
                foreach ($columns as $col) {
                    $type = strtolower($col->Type);
                    // Look for JSON, TEXT, LONGTEXT columns that might contain serialized/JSON data
                    if (strpos($type, 'json') !== false || 
                        strpos($type, 'text') !== false || 
                        strpos($type, 'longtext') !== false) {
                        $json_candidates[] = $col->Field;
                    }
                }
                
                if (!$json_candidates) continue;
                
                // Search for image URLs in these columns
                foreach ($json_candidates as $column) {
                    foreach (array_slice(array_keys($url_map), 0, 5) as $old_url) { // Limit URL checks
                        $like = '%' . $wpdb->esc_like($old_url) . '%';
                        $rows = $wpdb->get_results($wpdb->prepare(
                            "SELECT * FROM `$table` WHERE `$column` LIKE %s LIMIT 100", 
                            $like
                        ));
                        
                        foreach ($rows as $row) {
                            $raw_value = $row->{$column};
                            if (!$raw_value) continue;
                            
                            // Try to decode as JSON first
                            $decoded = json_decode($raw_value, true);
                            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                // Handle JSON data
                                $new_decoded = $this->deep_replace($decoded, $url_map);
                                if ($new_decoded !== $decoded) {
                                    $new_json = wp_json_encode($new_decoded);
                                    $wpdb->update($table, [$column => $new_json], ['id' => $row->id ?? $row->ID ?? null]);
                                    $report['custom_tables'][] = ['table' => $table, 'column' => $column, 'row_id' => $row->id ?? $row->ID ?? 'unknown'];
                                }
                            } else {
                                // Handle as serialized WordPress data
                                $unserialized = maybe_unserialize($raw_value);
                                $new_unserialized = $this->deep_replace($unserialized, $url_map);
                                if ($new_unserialized !== $unserialized) {
                                    $new_serialized = maybe_serialize($new_unserialized);
                                    $wpdb->update($table, [$column => $new_serialized], ['id' => $row->id ?? $row->ID ?? null]);
                                    $report['custom_tables'][] = ['table' => $table, 'column' => $column, 'row_id' => $row->id ?? $row->ID ?? 'unknown'];
                                }
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                // Skip tables that cause errors (permissions, structure issues, etc.)
                continue;
            }
        }
    }
}

$GLOBALS['webp_safe_migrator'] = new WebP_Safe_Migrator();

/**
 * WP-CLI handler
 *
 * Usage examples:
 *   wp webp-migrator run --batch=100 --no-validate
 *   wp webp-migrator run --batch=25
 */
if (defined('WP_CLI') && WP_CLI) {
    class WebP_Safe_Migrator_CLI {
        public static function dispatch($args, $assoc_args) {
            $sub = array_shift($args) ?: 'run';
            if ($sub === 'run') self::run($assoc_args);
            else WP_CLI::error("Unknown subcommand '$sub'. Try: wp webp-migrator run");
        }

        public static function run($assoc_args) {
            $plugin = WebP_Safe_Migrator::instance();
            if (!$plugin) WP_CLI::error('Plugin not loaded.');

            $settings = get_option(WebP_Safe_Migrator::OPTION, []);
            $quality   = isset($settings['quality']) ? (int)$settings['quality'] : 59;
            $batch     = isset($assoc_args['batch']) ? max(1, (int)$assoc_args['batch']) : (int)($settings['batch_size'] ?? 10);
            $no_validate = array_key_exists('no-validate', $assoc_args);
            $validate = !$no_validate;

            $plugin->set_runtime_validation($validate);

            $ids = $plugin->get_non_webp_attachments($batch);
            if (!$ids) {
                WP_CLI::success('No eligible attachments found (maybe all converted or skipped by filters).');
                return;
            }

            $processed = 0;
            foreach ($ids as $id) {
                $ok = $plugin->process_attachment((int)$id, $quality, $validate);
                $processed += $ok ? 1 : 0;
                WP_CLI::log(($ok ? 'OK   ' : 'SKIP')." #$id");
            }
            WP_CLI::success("Processed {$processed}/".count($ids)." attachments. Validation mode: ".($validate ? 'ON' : 'OFF'));
        }
    }
}
