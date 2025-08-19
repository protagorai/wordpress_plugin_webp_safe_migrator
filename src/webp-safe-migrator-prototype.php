<?php
/**
 * Plugin Name: WebP Safe Migrator
 * Description: Convert non-WebP media to WebP at a fixed quality, update all usages & metadata safely, then (optionally) remove originals after validation.
 * Version:     0.1.0
 * Author:      Your Name
 * License:     GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

class WebP_Safe_Migrator {
    const OPTION = 'webp_safe_migrator_settings';
    const NONCE  = 'webp_safe_migrator_nonce';
    const STATUS_META = '_webp_migrator_status'; // converted|relinked|committed
    const BACKUP_META = '_webp_migrator_backup_dir';

    private $settings;

    public function __construct() {
        $this->settings = wp_parse_args(get_option(self::OPTION, []), [
            'quality'     => 59,
            'batch_size'  => 10,
            'validation'  => 1, // 1=validate (keep originals), 0=delete originals immediately
        ]);

        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'handle_actions']);
        register_activation_hook(__FILE__, [$this, 'on_activate']);
    }

    public function on_activate() {
        // Check for WebP support
        $gd_webp = function_exists('imagewebp');
        $imagick_webp = class_exists('Imagick') && in_array('WEBP', (new Imagick())->queryFormats('WEBP'), true);
        if (!$gd_webp && !$imagick_webp) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die('WebP Safe Migrator requires GD (imagewebp) or Imagick with WEBP support.');
        }
    }

    public function menu() {
        add_media_page('WebP Safe Migrator', 'WebP Migrator', 'manage_options', 'webp-safe-migrator', [$this, 'render']);
    }

    private function update_settings_from_request() {
        if (!current_user_can('manage_options')) return;
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce($_POST[self::NONCE], 'save_settings')) return;

        $quality    = isset($_POST['quality']) ? max(1, min(100, (int)$_POST['quality'])) : 59;
        $batch_size = isset($_POST['batch_size']) ? max(1, min(1000, (int)$_POST['batch_size'])) : 10;
        $validation = isset($_POST['validation']) ? 1 : 0;

        $this->settings = [
            'quality'     => $quality,
            'batch_size'  => $batch_size,
            'validation'  => $validation,
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

        global $wpdb;

        // Run batch conversion
        if (isset($_POST['webp_migrator_run']) && wp_verify_nonce($_POST[self::NONCE] ?? '', 'run_batch')) {
            $batch = $this->get_non_webp_attachments($this->settings['batch_size']);
            foreach ($batch as $att_id) {
                $this->process_attachment((int)$att_id, $this->settings['quality'], (bool)$this->settings['validation']);
            }
            add_settings_error('webp_safe_migrator', 'batch', 'Batch processed.', 'updated');
        }

        // Commit deletions for selected attachment
        if (isset($_POST['webp_migrator_commit_one']) && wp_verify_nonce($_POST[self::NONCE] ?? '', 'commit_one')) {
            $att_id = (int)($_POST['attachment_id'] ?? 0);
            $ok = $this->commit_deletions($att_id);
            if ($ok) {
                add_settings_error('webp_safe_migrator', 'commit', "Committed deletions for attachment #{$att_id}.", 'updated');
            } else {
                add_settings_error('webp_safe_migrator', 'commit_err', "Nothing to delete or commit failed for #{$att_id}.", 'error');
            }
        }

        // Commit all with status "relinked"
        if (isset($_POST['webp_migrator_commit_all']) && wp_verify_nonce($_POST[self::NONCE] ?? '', 'commit_all')) {
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

    public function render() {
        settings_errors('webp_safe_migrator');
        $quality    = (int)$this->settings['quality'];
        $batch_size = (int)$this->settings['batch_size'];
        $validation = (int)$this->settings['validation'];
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
                </table>
                <p>
                    <button class="button button-primary" name="webp_migrator_save_settings" value="1">Save settings</button>
                </p>
            </form>

            <hr/>
            <h2>Run batch</h2>
            <p><strong>Tip:</strong> make a full backup of your database and uploads before large migrations.</p>
            <form method="post">
                <?php wp_nonce_field('run_batch', self::NONCE); ?>
                <button class="button button-secondary" name="webp_migrator_run" value="1">Process next batch</button>
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
        if (!$ids) { echo '<p>None found.</p>'; return; }
        echo '<ul>';
        foreach ($ids as $id) {
            $id = (int)$id;
            $file = get_attached_file($id);
            $type = get_post_mime_type($id);
            echo '<li>#'.esc_html($id).' — '.esc_html(basename($file)).' ('.esc_html($type).')</li>';
        }
        echo '</ul>';
    }

    private function get_non_webp_attachments($limit = 10) {
        global $wpdb;
        $mimes = ['image/jpeg','image/png','image/gif'];
        $in = implode(',', array_fill(0, count($mimes), '%s'));
        $sql = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type IN ($in)
             ORDER BY ID ASC LIMIT %d",
             ...$mimes, (int)$limit
        );
        return $wpdb->get_col($sql);
    }

    private function is_animated_gif($path) {
        if (function_exists('imagecreatefromgif')) {
            $contents = @file_get_contents($path, false, null, 0, 1024 * 128); // first chunk
            return $contents && strpos($contents, 'NETSCAPE2.0') !== false; // simple/fast indicator
        }
        return false;
    }

    private function process_attachment($att_id, $quality, $validation_mode) {
        $file = get_attached_file($att_id);
        if (!$file || !file_exists($file)) return false;

        $mime = get_post_mime_type($att_id);
        if ($mime === 'image/webp') return true;

        // Skip animated GIF unless you add animated WebP support
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

        // Ensure dest dir
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

        // Update usages across DB (posts, postmeta, options)
        $this->replace_everywhere($map);

        // Update attachment post + metas
        wp_update_post([
            'ID'             => $att_id,
            'post_mime_type' => 'image/webp',
            'guid'           => $uploads['baseurl'] . '/' . $new_meta['file'],
        ]);
        update_post_meta($att_id, '_wp_attached_file', $new_meta['file']);
        wp_update_attachment_metadata($att_id, $new_meta);

        // Backup originals to a safe folder (used if validation mode on; deleted on commit)
        $backup_dir = trailingslashit($uploads['basedir']) . 'webp-migrator-backup/' . date('Ymd-His') . "/att-{$att_id}/";
        if (!wp_mkdir_p($backup_dir)) $backup_dir = null;

        // Move original + old sizes into backup (instead of deleting now) if validation, else delete immediately
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

        // Also map direct filesystem paths found in content (rare but seen)
        $map[trailingslashit($uploads['basedir']).$old_orig_rel] = trailingslashit($uploads['basedir']).$new_orig_rel;
        foreach ($new_sizes as $size => $n) {
            if (!isset($old_sizes[$size]['file'])) continue;
            $map[trailingslashit($uploads['basedir']).$old_dir_rel.$old_sizes[$size]['file']]
                = trailingslashit($uploads['basedir']).$new_dir_rel.$n['file'];
        }

        // Map extension-only swaps (e.g., .../image.jpg → .../image.webp, including size suffixes)
        $exts = ['jpg','jpeg','png','gif'];
        foreach ($exts as $ext) {
            $map_ext = function($url){
                return preg_replace('/\.(jpg|jpeg|png|gif)\b/i', '.webp', $url);
            };
            foreach (array_keys($map) as $k) {
                $map[$map_ext($k)] = $map[$k]; // helps catch relative/variant strings
            }
        }

        return $map;
    }

    private function replace_everywhere(array $url_map) {
        global $wpdb;

        // POSTS
        foreach ($url_map as $old => $new) {
            if ($old === $new) continue;
            // Find candidate posts first (LIKE) then do safe in-PHP replace and update
            $like = '%' . $wpdb->esc_like($old) . '%';
            $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s", $like));
            foreach ($ids as $pid) {
                $post = get_post($pid);
                if (!$post) continue;
                $content = $post->post_content;
                $new_content = str_replace($old, $new, $content);
                if ($new_content !== $content) {
                    wp_update_post(['ID' => $pid, 'post_content' => $new_content]);
                }
            }
        }

        // POSTMETA
        $this->replace_in_table_serialized($url_map, 'postmeta', 'meta_value', function($row){
            return function($new_value) use ($row){
                return update_post_meta((int)$row->post_id, $row->meta_key, $new_value);
            };
        });

        // OPTIONS
        $this->replace_in_table_serialized($url_map, 'options', 'option_value', function($row){
            return function($new_value) use ($row){
                return update_option($row->option_name, $new_value);
            };
        });
    }

    private function replace_in_table_serialized(array $url_map, $table, $value_col, $update_closure_factory) {
        global $wpdb;

        // Build WHERE with OR of LIKEs (limited to first few to avoid huge SQL)
        $likes = [];
        $map_keys = array_slice(array_keys($url_map), 0, 10);
        foreach ($map_keys as $k) {
            $likes[] = $wpdb->prepare("$value_col LIKE %s", '%'.$wpdb->esc_like($k).'%');
        }
        if (!$likes) return;

        $table_name = $table === 'postmeta' ? $wpdb->postmeta : $wpdb->options;
        $sql = "SELECT * FROM {$table_name} WHERE " . implode(' OR ', $likes) . " LIMIT 5000";
        $rows = $wpdb->get_results($sql);

        foreach ($rows as $row) {
            $raw = $row->{$value_col};
            $value = maybe_unserialize($raw);
            $new_value = $this->deep_replace($value, $url_map);
            if ($new_value !== $value) {
                $update = $update_closure_factory($row);
                $update(maybe_serialize($new_value));
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
        $deleted_any = false;
        if ($backup_dir && is_dir($backup_dir)) {
            // Delete the backup directory tree
            $deleted_any = $this->rrmdir($backup_dir);
        }

        update_post_meta($att_id, self::STATUS_META, 'committed');
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
}

new WebP_Safe_Migrator();
