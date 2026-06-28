<?php
/**
 * Integration tests for WebP_Migrator_Logger.
 *
 * Exercises level thresholds, DB-backed log storage, filtering/ordering,
 * statistics, export formats, file storage, clearing, and the private
 * format_bytes() helper (via WebP_Reflect).
 */
class Test_Logger extends WP_UnitTestCase {

    /** @var string[] Temp log files created during a test, removed in tear_down(). */
    private $temp_files = [];

    public function set_up(): void {
        parent::set_up();
        // Start from a clean slate so prior runs/options never leak in.
        delete_option(WebP_Migrator_Logger::LOG_OPTION);
        $this->temp_files = [];
    }

    public function tear_down(): void {
        delete_option('webp_migrator_logs');

        foreach ($this->temp_files as $file) {
            if ($file && file_exists($file)) {
                @unlink($file);
            }
        }
        $this->temp_files = [];

        parent::tear_down();
    }

    /**
     * Build a logger that stores in the DB only (no file writes) unless told
     * otherwise. Avoids touching the filesystem for the DB-focused tests.
     */
    private function make_db_logger($log_level = 'info') {
        return new WebP_Migrator_Logger([
            'log_level'     => $log_level,
            'store_in_db'   => true,
            'store_in_file' => false,
        ]);
    }

    /**
     * Seed the log option directly with controlled timestamps.
     *
     * The logger stamps every entry with current_time('mysql') (1-second
     * resolution, no filter hook in core), so seeding the option in the
     * documented storage schema is the deterministic way to assert ordering
     * and oldest/newest behaviour without coupling to wall-clock time.
     *
     * @param array[] $entries Each: ['level' => ..., 'message' => ..., 'ts' => unix-seconds].
     */
    private function seed_logs(array $entries): void {
        $rows = [];
        foreach ($entries as $e) {
            $rows[] = [
                'timestamp'    => date('Y-m-d H:i:s', $e['ts']),
                'level'        => $e['level'],
                'message'      => $e['message'],
                'context'      => $e['context'] ?? [],
                'memory_usage' => $e['memory_usage'] ?? 0,
                'memory_peak'  => $e['memory_peak'] ?? 0,
            ];
        }
        update_option(WebP_Migrator_Logger::LOG_OPTION, $rows);
    }

    public function test_levels_and_threshold(): void {
        $logger = $this->make_db_logger('info');

        // Below threshold: dropped.
        $logger->debug('a debug message');
        // At/above threshold: stored.
        $logger->info('an info message');
        $logger->error('an error message');

        $logs = $logger->get_logs();

        $this->assertCount(2, $logs, 'debug should be dropped at info threshold');

        $levels = array_column($logs, 'level');
        $this->assertContains('info', $levels);
        $this->assertContains('error', $levels);
        $this->assertNotContains('debug', $levels);

        $messages = array_column($logs, 'message');
        $this->assertContains('an info message', $messages);
        $this->assertContains('an error message', $messages);
        $this->assertNotContains('a debug message', $messages);
    }

    public function test_get_logs_filter_and_order(): void {
        $logger = $this->make_db_logger('debug');

        // Seed with strictly increasing timestamps so newest-first ordering is
        // deterministic (real logging only has 1-second resolution).
        $base = strtotime('2026-01-01 00:00:00');
        $this->seed_logs([
            ['level' => 'info',    'message' => 'info-1',    'ts' => $base + 0],
            ['level' => 'error',   'message' => 'error-1',   'ts' => $base + 1],
            ['level' => 'warning', 'message' => 'warning-1', 'ts' => $base + 2],
            ['level' => 'error',   'message' => 'error-2',   'ts' => $base + 3],
            ['level' => 'error',   'message' => 'error-3',   'ts' => $base + 4],
        ]);

        // Filtered to error only.
        $errors = $logger->get_logs(100, 'error');
        $this->assertCount(3, $errors);
        foreach ($errors as $entry) {
            $this->assertSame('error', $entry['level']);
        }

        // Newest-first: error-3 was logged last, so it comes first.
        $error_messages = array_column($errors, 'message');
        $this->assertSame(['error-3', 'error-2', 'error-1'], $error_messages);

        // Unfiltered newest-first across all five entries.
        $all = $logger->get_logs();
        $this->assertCount(5, $all);
        $this->assertSame('error-3', $all[0]['message'], 'most recent entry should be first');
        $this->assertSame('info-1', $all[count($all) - 1]['message'], 'oldest entry should be last');

        // limit honoured.
        $this->assertCount(2, $logger->get_logs(2));
    }

    public function test_log_stats(): void {
        $logger = $this->make_db_logger('debug');

        $base = strtotime('2026-02-01 12:00:00');
        $this->seed_logs([
            ['level' => 'info',    'message' => 'i1', 'ts' => $base + 0],
            ['level' => 'info',    'message' => 'i2', 'ts' => $base + 1],
            ['level' => 'error',   'message' => 'e1', 'ts' => $base + 2],
            ['level' => 'warning', 'message' => 'w1', 'ts' => $base + 3],
        ]);

        $stats = $logger->get_log_stats();

        $this->assertSame(4, $stats['total']);

        $this->assertSame(2, $stats['by_level']['info']);
        $this->assertSame(1, $stats['by_level']['error']);
        $this->assertSame(1, $stats['by_level']['warning']);
        $this->assertArrayNotHasKey('debug', $stats['by_level']);

        // by_level counts must sum to the total.
        $this->assertSame($stats['total'], array_sum($stats['by_level']));

        // oldest/newest consistent with the strictly increasing timestamps.
        $this->assertSame(date('Y-m-d H:i:s', $base), $stats['oldest']);
        $this->assertSame(date('Y-m-d H:i:s', $base + 3), $stats['newest']);
        $this->assertLessThanOrEqual(
            strtotime($stats['newest']),
            strtotime($stats['oldest']),
            'oldest should not be after newest'
        );
    }

    public function test_log_stats_empty(): void {
        $logger = $this->make_db_logger('debug');

        $stats = $logger->get_log_stats();

        $this->assertSame(0, $stats['total']);
        $this->assertSame([], $stats['by_level']);
        $this->assertNull($stats['oldest']);
        $this->assertNull($stats['newest']);
    }

    public function test_export_json_csv_txt(): void {
        $logger = $this->make_db_logger('debug');
        $logger->info('export me', ['k' => 'v']);
        $logger->error('and me');

        // JSON.
        $json = $logger->export_logs('json');
        $this->assertSame('application/json', $json['mime_type']);
        $this->assertStringEndsWith('.json', $json['filename']);
        $decoded = json_decode($json['content'], true);
        $this->assertNotNull($decoded, 'JSON export must be valid JSON');
        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);

        // CSV.
        $csv = $logger->export_logs('csv');
        $this->assertSame('text/csv', $csv['mime_type']);
        $this->assertStringEndsWith('.csv', $csv['filename']);
        $lines = preg_split('/\r\n|\r|\n/', trim($csv['content']));
        $this->assertNotEmpty($lines);
        // Header row first.
        $this->assertStringContainsString('Timestamp', $lines[0]);
        $this->assertStringContainsString('Level', $lines[0]);
        $this->assertStringContainsString('Message', $lines[0]);
        // Header + 2 data rows (messages contain no embedded newlines here).
        $this->assertCount(3, $lines);
        $this->assertStringContainsString('export me', $csv['content']);

        // TXT.
        $txt = $logger->export_logs('txt');
        $this->assertSame('text/plain', $txt['mime_type']);
        $this->assertStringEndsWith('.txt', $txt['filename']);
        $this->assertStringContainsString('WebP Migrator Logs Export', $txt['content']);
        $this->assertStringContainsString('export me', $txt['content']);
        $this->assertStringContainsString('ERROR: and me', $txt['content']);
        // Plain text, not JSON.
        $this->assertNull(json_decode($txt['content']));
    }

    public function test_file_storage(): void {
        $upload = wp_upload_dir();
        $log_file = trailingslashit($upload['basedir']) . 'webp-migrator-test-' . uniqid() . '.log';
        $this->temp_files[] = $log_file;

        $logger = new WebP_Migrator_Logger([
            'log_level'     => 'info',
            'store_in_db'   => false,
            'store_in_file' => true,
            'log_file'      => $log_file,
        ]);

        $logger->info('written to file');

        $this->assertFileExists($log_file);
        $contents = file_get_contents($log_file);
        $this->assertStringContainsString('written to file', $contents);
        $this->assertStringContainsString('INFO:', $contents);

        // store_in_db was false → nothing in the option.
        $this->assertSame([], get_option(WebP_Migrator_Logger::LOG_OPTION, []));
    }

    public function test_clear_logs(): void {
        $logger = $this->make_db_logger('debug');
        $logger->info('something');
        $logger->error('something else');

        $this->assertNotEmpty($logger->get_logs());
        $this->assertNotFalse(get_option(WebP_Migrator_Logger::LOG_OPTION, false));

        $this->assertTrue($logger->clear_logs());

        $this->assertSame([], $logger->get_logs());
        $this->assertFalse(get_option(WebP_Migrator_Logger::LOG_OPTION, false),
            'clear_logs() should delete the option entirely');
    }

    public function test_format_bytes_human_readable(): void {
        $logger = $this->make_db_logger();

        $this->assertSame('1.5 KB', WebP_Reflect::call($logger, 'format_bytes', [1536]));
        $this->assertSame('0 B', WebP_Reflect::call($logger, 'format_bytes', [0]));
        $this->assertSame('1 KB', WebP_Reflect::call($logger, 'format_bytes', [1024]));
        $this->assertSame('1 MB', WebP_Reflect::call($logger, 'format_bytes', [1048576]));
    }
}
