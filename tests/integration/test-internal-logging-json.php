<?php
/**
 * Integration: error/dimension logging, custom-table JSON rewriting, and
 * statistics/cleanup internals, exercised through the private methods via
 * reflection (WebP_Reflect) and real generated images (WebP_Image_Factory).
 *
 * Covers:
 *   - log_conversion_error() / remove_conversion_error() / get_attachment_ids_with_errors()
 *   - validate_filename_dimensions() / parse_filename_dimensions() /
 *     log_dimension_inconsistency() / remove_dimension_inconsistency()
 *   - replace_in_json_columns() / deep_replace() against a custom table
 *   - get_plugin_statistics() / cleanup_orphaned_metadata()
 */
class Test_Internal_Logging_Json extends WP_UnitTestCase {

    /** @var WebP_Safe_Migrator */
    private $plugin;

    /** @var array Saved copy of plugin settings to restore in tear_down. */
    private $saved_settings;

    /** @var string|null Name of the custom table created by a test (for DROP). */
    private $custom_table = null;

    private function error_log_file(): string {
        $uploads = wp_get_upload_dir();
        return trailingslashit($uploads['basedir']) . 'webp-migrator-conversion-errors.json';
    }

    private function dimension_log_file(): string {
        $uploads = wp_get_upload_dir();
        return trailingslashit($uploads['basedir']) . 'webp-migrator-dimension-inconsistencies.json';
    }

    public function set_up(): void {
        parent::set_up();
        $this->plugin = $GLOBALS['webp_safe_migrator'];
        $this->saved_settings = WebP_Reflect::get($this->plugin, 'settings');

        // Start each test from a clean slate for the JSON log files.
        foreach ([$this->error_log_file(), $this->dimension_log_file()] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    public function tear_down(): void {
        // Remove the JSON log files this suite may have created.
        foreach ([$this->error_log_file(), $this->dimension_log_file()] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }

        // Drop any custom table created during a test.
        if ($this->custom_table) {
            global $wpdb;
            $wpdb->query("DROP TABLE IF EXISTS `{$this->custom_table}`");
            $this->custom_table = null;
        }

        // Remove generated image files.
        WebP_Image_Factory::cleanup();

        // Restore settings in case a test mutated them.
        if (is_array($this->saved_settings)) {
            WebP_Reflect::set($this->plugin, 'settings', $this->saved_settings);
        }

        parent::tear_down();
    }

    /**
     * log_conversion_error() should write post meta + the central JSON log,
     * increment error_count on repeat, expose the id via
     * get_attachment_ids_with_errors(), and remove_conversion_error() should
     * delete the entry.
     */
    public function test_error_logging_increments_and_removes(): void {
        $att = WebP_Image_Factory::attachment('jpeg', 100, 100);

        WebP_Reflect::call($this->plugin, 'log_conversion_error',
            [$att, 'boom', 'format_conversion', ['x' => 1]]);
        WebP_Reflect::call($this->plugin, 'log_conversion_error',
            [$att, 'boom', 'format_conversion', ['x' => 1]]);

        // Post meta is set and carries the error message.
        $meta_raw = get_post_meta($att, WebP_Safe_Migrator::ERROR_META, true);
        $this->assertNotEmpty($meta_raw, 'ERROR_META post meta should be set.');
        $meta = json_decode($meta_raw, true);
        $this->assertIsArray($meta);
        $this->assertSame('boom', $meta['error']);

        // JSON log file exists and tracks the attachment with error_count == 2.
        $log_file = $this->error_log_file();
        $this->assertFileExists($log_file);
        $log = json_decode(file_get_contents($log_file), true);
        $this->assertIsArray($log);
        // JSON object keys come back as (string) numeric keys.
        $this->assertArrayHasKey((string) $att, $log);
        $this->assertSame(2, $log[(string) $att]['error_count']);
        $this->assertSame('boom', $log[(string) $att]['error']);

        // get_attachment_ids_with_errors() returns the id (as a numeric string).
        $ids = WebP_Reflect::call($this->plugin, 'get_attachment_ids_with_errors', []);
        $this->assertTrue(
            in_array((string) $att, $ids, true) || in_array($att, $ids, true),
            'Attachment id should be present in get_attachment_ids_with_errors().'
        );

        // remove_conversion_error() removes the entry and returns true.
        $removed = WebP_Reflect::call($this->plugin, 'remove_conversion_error', [$att]);
        $this->assertTrue($removed);

        $log_after = json_decode(file_get_contents($log_file), true);
        $this->assertIsArray($log_after);
        $this->assertArrayNotHasKey((string) $att, $log_after);

        // A second removal (now absent) returns false.
        $this->assertFalse(
            WebP_Reflect::call($this->plugin, 'remove_conversion_error', [$att])
        );

        wp_delete_attachment($att, true);
    }

    /**
     * With check_filename_dimensions enabled, validate_filename_dimensions()
     * should detect a filename claiming a size that does not match the actual
     * image and log it; remove_dimension_inconsistency() should then clear it.
     */
    public function test_dimension_inconsistency(): void {
        // Enable the feature (merge into existing settings).
        WebP_Reflect::set($this->plugin, 'settings', array_merge(
            WebP_Reflect::get($this->plugin, 'settings'),
            ['check_filename_dimensions' => 1]
        ));

        // Sanity check the parser independently.
        $this->assertSame([1920, 1080],
            WebP_Reflect::call($this->plugin, 'parse_filename_dimensions', ['photo-1920x1080.jpg']));

        // Real 200x150 image whose filename claims 1920x1080 (a mismatch).
        $path = WebP_Image_Factory::image('jpeg', 200, 150, 'photo-1920x1080.jpg');

        // Build an attachment pointing at that file so get_attached_file works.
        $filetype = wp_check_filetype(basename($path), null);
        $att = wp_insert_attachment([
            'post_mime_type' => $filetype['type'],
            'post_title'     => sanitize_file_name(basename($path)),
            'post_status'    => 'inherit',
        ], $path);
        $this->assertIsInt($att);
        $this->assertGreaterThan(0, $att);

        WebP_Reflect::call($this->plugin, 'validate_filename_dimensions', [$path, $att]);

        // The dimension JSON log now contains an entry for this attachment.
        $log_file = $this->dimension_log_file();
        $this->assertFileExists($log_file);
        $log = json_decode(file_get_contents($log_file), true);
        $this->assertIsArray($log);
        $this->assertArrayHasKey((string) $att, $log);
        $entry = $log[(string) $att];
        $this->assertSame(1920, $entry['parsed_dimensions']['width']);
        $this->assertSame(1080, $entry['parsed_dimensions']['height']);
        $this->assertSame(200, $entry['actual_dimensions']['width']);
        $this->assertSame(150, $entry['actual_dimensions']['height']);

        // remove_dimension_inconsistency() clears the entry.
        $this->assertTrue(
            WebP_Reflect::call($this->plugin, 'remove_dimension_inconsistency', [$att])
        );
        $log_after = json_decode(file_get_contents($log_file), true);
        $this->assertArrayNotHasKey((string) $att, (array) $log_after);

        // Removing an absent entry returns false.
        $this->assertFalse(
            WebP_Reflect::call($this->plugin, 'remove_dimension_inconsistency', [$att])
        );

        wp_delete_attachment($att, true);
    }

    /**
     * replace_in_json_columns() should walk non-core (custom) tables, find URLs
     * in JSON/text columns, rewrite them via deep_replace(), persist the change,
     * and record the touched row in $report['custom_tables'].
     *
     * NOTE: the method takes $report BY REFERENCE. ReflectionMethod::invokeArgs
     * preserves a reference for an array element that is a referenced variable,
     * so we pass [$map, &$report] directly via a ReflectionMethod (not
     * WebP_Reflect, whose signature does not forward the reference).
     */
    public function test_replace_in_json_columns_rewrites_custom_table(): void {
        global $wpdb;

        $this->custom_table = $wpdb->prefix . 'webp_e2e_custom';
        $wpdb->query("DROP TABLE IF EXISTS `{$this->custom_table}`");
        $wpdb->query(
            "CREATE TABLE `{$this->custom_table}` (
                id INT NOT NULL AUTO_INCREMENT,
                payload LONGTEXT NULL,
                PRIMARY KEY (id)
            ) {$wpdb->get_charset_collate()}"
        );

        $old_url = 'http://example.org/a/x.jpg';
        $new_url = 'http://example.org/a/x.webp';

        // Use unescaped slashes so the plugin's LIKE pre-filter (which searches the
        // raw URL) matches the row. NOTE plugin limitation: default wp_json_encode
        // escapes slashes (http:\/\/...), which replace_in_json_columns' LIKE filter
        // would miss — so it only rewrites custom-table JSON stored unescaped.
        $wpdb->insert($this->custom_table, [
            'payload' => wp_json_encode(['img' => $old_url], JSON_UNESCAPED_SLASHES),
        ]);
        $row_id = (int) $wpdb->insert_id;
        $this->assertGreaterThan(0, $row_id);

        $map = [$old_url => $new_url];

        // By-reference reflection call.
        $m = new ReflectionMethod($this->plugin, 'replace_in_json_columns');
        $m->setAccessible(true);
        $report = [];
        $m->invokeArgs($this->plugin, [$map, &$report]);

        // The row payload now contains the new URL (and not the old one).
        $payload = $wpdb->get_var($wpdb->prepare(
            "SELECT payload FROM `{$this->custom_table}` WHERE id = %d",
            $row_id
        ));
        // Decode before asserting: the plugin re-encodes with wp_json_encode, which
        // escapes slashes (http:\/\/...), so a raw substring check would miss.
        $decoded = json_decode($payload, true);
        $this->assertIsArray($decoded);
        $this->assertSame($new_url, $decoded['img']);

        // The report records that a custom table row was rewritten.
        $this->assertArrayHasKey('custom_tables', $report);
        $this->assertNotEmpty($report['custom_tables']);

        $matched = false;
        foreach ($report['custom_tables'] as $r) {
            if (($r['table'] ?? '') === $this->custom_table
                && ($r['column'] ?? '') === 'payload') {
                $matched = true;
                break;
            }
        }
        $this->assertTrue($matched, 'Report should reference the custom table/column rewritten.');
    }

    /**
     * get_plugin_statistics() returns the expected structure and reflects a
     * 'relinked' status; cleanup_orphaned_metadata() runs cleanly and returns an
     * int >= 0 (counting the orphaned status row we leave behind).
     */
    public function test_statistics_and_cleanup(): void {
        $att = WebP_Image_Factory::attachment('jpeg', 120, 90);
        update_post_meta($att, WebP_Safe_Migrator::STATUS_META, 'relinked');

        $stats = WebP_Reflect::call($this->plugin, 'get_plugin_statistics', []);
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('conversion_stats', $stats);
        $this->assertArrayHasKey('meta_counts', $stats);
        $this->assertArrayHasKey('status_counts', $stats);
        $this->assertIsArray($stats['conversion_stats']);
        $this->assertIsArray($stats['meta_counts']);
        $this->assertIsArray($stats['status_counts']);
        $this->assertArrayHasKey('relinked', $stats['status_counts']);
        $this->assertGreaterThanOrEqual(1, $stats['status_counts']['relinked']);

        // Create orphaned metadata: delete the post but leave a STATUS_META row
        // behind pointing at the now non-existent post id.
        wp_delete_attachment($att, true);
        // wp_delete_attachment removes the post's meta, so re-insert an orphan.
        add_post_meta($att, WebP_Safe_Migrator::STATUS_META, 'relinked');

        global $wpdb;
        $orphan_exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
             WHERE pm.meta_key = %s AND p.ID IS NULL AND pm.post_id = %d",
            WebP_Safe_Migrator::STATUS_META,
            $att
        ));
        $this->assertGreaterThanOrEqual(1, $orphan_exists, 'Orphan meta row should exist before cleanup.');

        $cleaned = WebP_Reflect::call($this->plugin, 'cleanup_orphaned_metadata', []);
        $this->assertIsInt($cleaned);
        $this->assertGreaterThanOrEqual(0, $cleaned);

        // The orphaned row should be gone after cleanup.
        $orphan_after = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id = %d",
            WebP_Safe_Migrator::STATUS_META,
            $att
        ));
        $this->assertSame(0, $orphan_after, 'cleanup_orphaned_metadata should remove the orphaned row.');
    }
}
