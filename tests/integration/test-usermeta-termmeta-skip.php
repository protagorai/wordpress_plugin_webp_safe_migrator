<?php
/**
 * Integration: usermeta / termmeta URL rewriting and the skip-folders rule.
 *
 * (a) Exercises the usermeta + termmeta branches of replace_everywhere() (via
 *     replace_in_table_serialized_with_report) by converting an attachment and
 *     verifying that plain-string and serialized-array meta values that pointed
 *     at the original URL are rewritten to the new (.webp) URL.
 *
 * (b) Exercises get_skip_rules()/get_non_target_format_attachments(): an
 *     attachment whose file lives inside a folder listed in skip_folders is
 *     filtered out of the conversion queue (case-insensitive substring match
 *     against the path relative to the uploads basedir), while a root-level
 *     attachment is still returned.
 *
 * WP_UnitTestCase wraps each test in a transaction that is rolled back in
 * tear_down(), so DB rows (users, terms, attachments, meta) do not persist;
 * we still clean up the on-disk image files via WebP_Image_Factory::cleanup().
 */
class Test_Usermeta_Termmeta_Skip extends WP_UnitTestCase {

    /** @var WebP_Safe_Migrator */
    private $plugin;

    /** @var array Saved settings to restore in tear_down. */
    private $saved_settings;

    public function set_up(): void {
        parent::set_up();
        $this->plugin = $GLOBALS['webp_safe_migrator'];

        $this->saved_settings = WebP_Reflect::get($this->plugin, 'settings');
        WebP_Reflect::set($this->plugin, 'settings', array_merge(
            $this->saved_settings,
            [
                'target_format'             => 'webp',
                'quality'                   => 75,
                'webp_quality'              => 75,
                'enable_bounding_box'       => 0,
                'check_filename_dimensions' => 0,
                'skip_folders'              => '',
                'skip_mimes'                => '',
            ]
        ));
    }

    public function tear_down(): void {
        WebP_Reflect::set($this->plugin, 'settings', $this->saved_settings);
        WebP_Image_Factory::cleanup();
        parent::tear_down();
    }

    /**
     * (a) usermeta (plain string + serialized array) and termmeta (plain string)
     * URL references are rewritten to the converted .webp URL.
     */
    public function test_usermeta_and_termmeta_rewritten(): void {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('WebP encoding (imagewebp) not available.');
        }

        $att = WebP_Image_Factory::attachment('jpeg', 300, 200);
        $url = wp_get_attachment_url($att);
        $this->assertStringEndsWith('.jpg', $url, 'original attachment URL should end with .jpg');

        // usermeta: a plain string and a serialized array both referencing the URL.
        $uid = self::factory()->user->create();
        update_user_meta($uid, 'avatar_url', $url);
        update_user_meta($uid, 'profile', ['img' => $url]);

        // termmeta: a plain string referencing the URL.
        $tid = self::factory()->term->create(['taxonomy' => 'category']);
        update_term_meta($tid, 'thumb', $url);

        $ok = $this->plugin->process_attachment($att, 75, true);
        $this->assertTrue($ok, 'process_attachment should succeed and rewrite references');

        // usermeta plain string -> usermeta branch of replace_everywhere
        $this->assertStringEndsWith(
            '.webp',
            get_user_meta($uid, 'avatar_url', true),
            'plain-string usermeta should be rewritten to .webp'
        );

        // usermeta serialized array -> serialized handling in the usermeta branch
        $profile = get_user_meta($uid, 'profile', true);
        $this->assertIsArray($profile);
        $this->assertStringEndsWith(
            '.webp',
            $profile['img'],
            'serialized-array usermeta value should be rewritten to .webp'
        );

        // termmeta plain string -> termmeta branch of replace_everywhere
        $this->assertStringEndsWith(
            '.webp',
            get_term_meta($tid, 'thumb', true),
            'plain-string termmeta should be rewritten to .webp'
        );
    }

    /**
     * (b) An attachment inside a folder listed in skip_folders is excluded from
     * the conversion queue, while a normal root-level attachment is included.
     */
    public function test_skip_folders_excludes_attachment(): void {
        $uploads = wp_get_upload_dir();
        $sub = trailingslashit($uploads['basedir']) . 'private-uploads';
        wp_mkdir_p($sub);

        // Image (and attachment) living inside the skipped subfolder.
        $path = WebP_Image_Factory::image('jpeg', 120, 90, 'private-uploads/skipme.jpg');
        $aid = wp_insert_attachment([
            'post_mime_type' => 'image/jpeg',
            'post_title'     => 'skipme',
            'post_status'    => 'inherit',
        ], $path);
        $this->assertNotWPError($aid);
        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata($aid, wp_generate_attachment_metadata($aid, $path));

        // A normal root-level attachment that should still be queued.
        $normal = WebP_Image_Factory::attachment('jpeg', 100, 100);

        // skip_folders matches "private-uploads" as a case-insensitive substring
        // of the attachment path relative to the uploads basedir.
        WebP_Reflect::set($this->plugin, 'settings', array_merge(
            WebP_Reflect::get($this->plugin, 'settings'),
            ['skip_folders' => "private-uploads"]
        ));

        $ids = $this->plugin->get_non_target_format_attachments(100, false);

        $this->assertTrue(in_array($normal, $ids, true), 'root attachment should be in the queue');
        $this->assertFalse(in_array($aid, $ids, true), 'attachment in skipped folder should be excluded');
    }
}
