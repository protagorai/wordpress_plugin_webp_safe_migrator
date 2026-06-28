<?php
/**
 * Integration: queue/scope selection and skip rules.
 */
class Test_Scope_And_Skip extends WP_UnitTestCase {

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

    public function test_queue_lists_unconverted_then_excludes_converted(): void {
        $a = WebP_Image_Factory::attachment('jpeg', 300, 200);
        $b = WebP_Image_Factory::attachment('png', 300, 200);

        $ids = $this->plugin->get_non_target_format_attachments(50, false);
        $this->assertContains($a, $ids);
        $this->assertContains($b, $ids);

        $this->plugin->process_attachment($a, 75, true);
        $ids2 = $this->plugin->get_non_target_format_attachments(50, false);
        $this->assertNotContains($a, $ids2, 'converted item should drop out of the queue');
    }

    public function test_skip_mimes_excludes_format(): void {
        $gif = WebP_Image_Factory::attachment('gif', 120, 120);
        WebP_Reflect::set($this->plugin, 'settings', array_merge(
            WebP_Reflect::get($this->plugin, 'settings'), ['skip_mimes' => 'image/gif']
        ));
        $ids = $this->plugin->get_non_target_format_attachments(50, false);
        $this->assertNotContains($gif, $ids);
    }

    public function test_animated_gif_is_skipped(): void {
        $path = WebP_Image_Factory::animated_gif();
        $name = basename($path);
        $att  = wp_insert_attachment([
            'post_mime_type' => 'image/gif',
            'post_title'     => $name,
            'post_status'    => 'inherit',
        ], $path);
        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata($att, wp_generate_attachment_metadata($att, $path));

        $ok = $this->plugin->process_attachment($att, 75, true);
        $this->assertFalse($ok, 'animated GIF should be skipped (returns false)');
        $this->assertSame('skipped_animated_gif', get_post_meta($att, WebP_Safe_Migrator::STATUS_META, true));

        wp_delete_attachment($att, true);
    }
}
