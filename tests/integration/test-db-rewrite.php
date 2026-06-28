<?php
/**
 * Integration: database reference rewriting across content, postmeta (plain +
 * serialized array), options (serialized) and comments — the riskiest code in
 * the plugin.
 *
 * Plugin limitation: URLs inside JSON strings stored in core postmeta are not
 * rewritten because wp_json_encode escapes slashes and the postmeta path uses
 * raw str_replace (only custom tables are json-decoded). See review.md.
 */
class Test_DB_Rewrite extends WP_UnitTestCase {

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

    public function test_references_rewritten_everywhere(): void {
        $att = WebP_Image_Factory::attachment('jpeg', 640, 480);
        $url = wp_get_attachment_url($att);
        $this->assertStringEndsWith('.jpg', $url);

        $post_id    = self::factory()->post->create(['post_content' => 'See <img src="' . $url . '">']);
        $comment_id = self::factory()->comment->create([
            'comment_post_ID' => $post_id,
            'comment_content' => 'inline ' . $url,
        ]);
        update_post_meta($post_id, 'plain_meta', $url);
        // WP serializes the array; the plugin's maybe_unserialize + deep_replace
        // handles the nested URL (URLs in serialized PHP arrays are stored
        // unescaped, unlike wp_json_encode output). See class docblock for the
        // JSON-string limitation we intentionally do not exercise here.
        update_post_meta($post_id, 'gallery', ['images' => [$url]]); // serialized array
        update_option('theme_mods_e2e', ['header' => $url]); // serialized array

        $this->assertTrue($this->plugin->process_attachment($att, 75, true));

        $new_url = wp_get_attachment_url($att);
        $this->assertStringEndsWith('.webp', $new_url);

        $content = get_post($post_id)->post_content;
        $this->assertStringContainsString('.webp', $content);
        $this->assertStringNotContainsString($url, $content);

        $this->assertStringNotContainsString($url, get_comment($comment_id)->comment_content);

        $this->assertStringEndsWith('.webp', get_post_meta($post_id, 'plain_meta', true));

        $gallery = get_post_meta($post_id, 'gallery', true); // unserialized array
        $this->assertStringEndsWith('.webp', $gallery['images'][0]);

        $mods = get_option('theme_mods_e2e');
        $this->assertStringEndsWith('.webp', $mods['header']);
    }
}
