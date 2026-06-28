<?php
/**
 * Integration: the background queue (WebP_Migrator_Queue) driven through the
 * real, tested main-plugin pipeline. These tests exercise the durable,
 * resumable option-based design by calling process_queue_batch() directly
 * (not via cron) and asserting that the cursor advances across batches.
 */
class Test_Queue extends WP_UnitTestCase {

    /** @var WebP_Safe_Migrator */
    private $plugin;

    public function set_up(): void {
        parent::set_up();
        $this->plugin = $GLOBALS['webp_safe_migrator'];
        // Pin a deterministic conversion target for the queue's delegation.
        WebP_Reflect::set($this->plugin, 'settings', array_merge(
            WebP_Reflect::get($this->plugin, 'settings'),
            ['target_format' => 'webp', 'quality' => 75, 'enable_bounding_box' => 0,
             'check_filename_dimensions' => 0, 'skip_folders' => '', 'skip_mimes' => '']
        ));
    }

    public function tear_down(): void {
        WebP_Image_Factory::cleanup();
        delete_option('webp_migrator_queue');
        delete_option('webp_migrator_progress');
        wp_clear_scheduled_hook('webp_migrator_process_queue');
        parent::tear_down();
    }

    public function test_processes_all_items_across_batches(): void {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('WebP encoding (imagewebp) not available.');
        }

        $ids = [
            WebP_Image_Factory::attachment('jpeg', 320, 240),
            WebP_Image_Factory::attachment('png', 300, 200),
            WebP_Image_Factory::attachment('jpeg', 400, 300),
            WebP_Image_Factory::attachment('png', 256, 256),
        ];

        $q = new WebP_Migrator_Queue();
        $q->start_background_processing($ids, [
            'batch_size'      => 2,
            'quality'         => 75,
            'validation_mode' => true,
        ]);

        // Drive batches directly (do NOT rely on cron). Each call resumes from
        // the persisted current_index cursor; a batch_size of 2 over 4 items
        // means completion needs at least two calls.
        $iterations = 0;
        do {
            $q->process_queue_batch();
            $status = $q->get_queue_status();
            $iterations++;
        } while (($status['status'] ?? '') !== 'completed' && $iterations <= 5);

        $this->assertSame('completed', $q->get_queue_status()['status'],
            'queue should reach completed status within the iteration cap');

        // Every attachment was converted to WebP by the real pipeline.
        foreach ($ids as $att_id) {
            $this->assertSame('image/webp', get_post_mime_type($att_id),
                "attachment #{$att_id} should be image/webp after processing");
        }

        $stats = $q->get_queue_stats();
        $this->assertSame(4, $stats['processed'], 'all four items should be counted as processed');
    }

    public function test_stop_and_clear(): void {
        $ids = [
            WebP_Image_Factory::attachment('jpeg', 320, 240),
            WebP_Image_Factory::attachment('png', 300, 200),
        ];

        $q = new WebP_Migrator_Queue();
        $q->start_background_processing($ids, ['batch_size' => 1]);

        $q->stop_background_processing();
        $this->assertSame('stopped', $q->get_queue_status()['status']);

        $q->clear_queue();
        $this->assertSame([], $q->get_queue_status(), 'clear_queue should remove the queue option');
    }

    public function test_progress_and_stats_shape(): void {
        $ids = [
            WebP_Image_Factory::attachment('jpeg', 320, 240),
            WebP_Image_Factory::attachment('png', 300, 200),
        ];

        $q = new WebP_Migrator_Queue();
        $q->start_background_processing($ids, ['batch_size' => 1]);

        $progress = $q->get_progress();
        $this->assertArrayHasKey('current', $progress);
        $this->assertArrayHasKey('total', $progress);
        $this->assertArrayHasKey('percentage', $progress);

        $stats = $q->get_queue_stats();
        $this->assertArrayHasKey('total', $stats);
        $this->assertArrayHasKey('processed', $stats);
        $this->assertArrayHasKey('failed', $stats);
        $this->assertArrayHasKey('remaining', $stats);
        $this->assertArrayHasKey('status', $stats);
    }
}
