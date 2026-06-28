<?php
/**
 * Integration: the safety lifecycle — rollback restores the original and reverts
 * DB references; commit deletes the backup and clears plugin metadata.
 */
class Test_Rollback_And_Commit extends WP_UnitTestCase {

    /** @var WebP_Safe_Migrator */
    private $plugin;

    public function set_up(): void {
        parent::set_up();
        $this->plugin = $GLOBALS['webp_safe_migrator'];
        WebP_Reflect::set($this->plugin, 'settings', array_merge(
            WebP_Reflect::get($this->plugin, 'settings'),
            ['target_format' => 'webp', 'quality' => 75, 'enable_bounding_box' => 0,
             'check_filename_dimensions' => 0, 'skip_folders' => '', 'skip_mimes' => '']
        ));
    }

    public function tear_down(): void {
        WebP_Image_Factory::cleanup();
        parent::tear_down();
    }

    public function test_rollback_restores_original_and_reverts_references(): void {
        $att = WebP_Image_Factory::attachment('jpeg', 640, 480);
        $url = wp_get_attachment_url($att);
        $post_id = self::factory()->post->create(['post_content' => '<img src="' . $url . '">']);

        $this->assertTrue($this->plugin->process_attachment($att, 75, true));
        $this->assertSame('image/webp', get_post_mime_type($att));
        $this->assertStringNotContainsString($url, get_post($post_id)->post_content);

        $rolled = WebP_Reflect::call($this->plugin, 'rollback_conversion', [$att]);
        $this->assertTrue((bool) $rolled, 'rollback should succeed');

        $this->assertNotSame('image/webp', get_post_mime_type($att));
        $this->assertFileExists(get_attached_file($att));
        $this->assertStringContainsString($url, get_post($post_id)->post_content);
        $this->assertSame('', get_post_meta($att, WebP_Safe_Migrator::STATUS_META, true));
    }

    public function test_commit_removes_backup_and_clears_meta(): void {
        $att = WebP_Image_Factory::attachment('jpeg', 400, 300);
        $this->assertTrue($this->plugin->process_attachment($att, 75, true));

        $backup = get_post_meta($att, WebP_Safe_Migrator::BACKUP_META, true);
        $this->assertDirectoryExists($backup);

        $committed = WebP_Reflect::call($this->plugin, 'commit_deletions', [$att]);
        $this->assertTrue((bool) $committed);

        $this->assertDirectoryDoesNotExist($backup);
        $this->assertSame('', get_post_meta($att, WebP_Safe_Migrator::STATUS_META, true));
        $this->assertSame('', get_post_meta($att, WebP_Safe_Migrator::BACKUP_META, true));
        $this->assertStringEndsWith('.webp', get_attached_file($att));
    }
}
