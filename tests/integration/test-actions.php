<?php
/**
 * Integration: admin form-action handlers driven through handle_actions().
 *
 * Covers settings save (with/without nonce) and the maintenance actions
 * (reset statistics, clear completed data). These run against the global plugin
 * instance the same way admin_init would invoke them on a real POST request.
 */
class Test_Actions extends WP_UnitTestCase {

    /** @var WebP_Safe_Migrator */
    private $plugin;

    /** @var int[] Attachments created by a test, removed in tear_down(). */
    private $created_attachments = [];

    public function set_up(): void {
        parent::set_up();
        $this->plugin = $GLOBALS['webp_safe_migrator'];
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->created_attachments = [];
    }

    public function tear_down(): void {
        foreach ($this->created_attachments as $att_id) {
            wp_delete_attachment($att_id, true);
        }
        $this->created_attachments = [];

        delete_option(WebP_Safe_Migrator::OPTION);
        delete_option('webp_migrator_statistics');

        $_POST = [];
        $_REQUEST = [];

        WebP_Image_Factory::cleanup();
        parent::tear_down();
    }

    public function test_settings_save(): void {
        $_POST = [
            WebP_Safe_Migrator::NONCE         => wp_create_nonce('save_settings'),
            'webp_migrator_save_settings'     => '1',
            'target_format'                   => 'webp',
            'quality'                         => '70',
            'batch_size'                      => '5',
            'validation'                      => '1',
            'webp_quality'                    => '65',
            'skip_mimes'                      => 'image/gif',
        ];

        $this->plugin->handle_actions();

        $opt = get_option(WebP_Safe_Migrator::OPTION);
        $this->assertIsArray($opt);
        $this->assertSame(70, (int) $opt['quality']);
        $this->assertSame(5, (int) $opt['batch_size']);
        $this->assertSame('webp', $opt['target_format']);
        $this->assertSame(1, (int) $opt['validation']);

        // The in-memory copy on the plugin instance should reflect the save too.
        $settings = WebP_Reflect::get($this->plugin, 'settings');
        $this->assertSame(70, (int) $settings['quality']);
        $this->assertSame(5, (int) $settings['batch_size']);
    }

    public function test_settings_save_rejected_without_nonce(): void {
        // Seed a known option value so we can prove it is untouched.
        $original = [
            'target_format' => 'webp',
            'quality'       => 42,
            'batch_size'    => 11,
            'validation'    => 1,
        ];
        update_option(WebP_Safe_Migrator::OPTION, $original);

        $_POST = [
            'webp_migrator_save_settings' => '1',
            'target_format'               => 'webp',
            'quality'                     => '99',
            'batch_size'                  => '99',
            WebP_Safe_Migrator::NONCE     => 'not-a-real-nonce',
        ];

        $this->plugin->handle_actions();

        $opt = get_option(WebP_Safe_Migrator::OPTION);
        $this->assertSame(42, (int) $opt['quality'], 'Quality must not change without a valid nonce');
        $this->assertSame(11, (int) $opt['batch_size'], 'Batch size must not change without a valid nonce');
    }

    public function test_reset_statistics(): void {
        update_option('webp_migrator_statistics', [
            'total_converted' => 17,
            'total_errors'    => 3,
            'last_run'        => time(),
        ]);
        $this->assertNotFalse(get_option('webp_migrator_statistics'));

        $_POST = [
            WebP_Safe_Migrator::NONCE => wp_create_nonce('reset_statistics'),
            'reset_statistics'        => '1',
        ];

        $this->plugin->handle_actions();

        $this->assertEmpty(get_option('webp_migrator_statistics'),
            'Statistics option should be deleted (false/empty) after reset');
    }

    public function test_clear_completed_data(): void {
        $att_id = WebP_Image_Factory::attachment('jpeg', 120, 90);
        $this->created_attachments[] = $att_id;

        update_post_meta($att_id, WebP_Safe_Migrator::STATUS_META, 'committed');
        $this->assertSame('committed', get_post_meta($att_id, WebP_Safe_Migrator::STATUS_META, true));

        $_POST = [
            WebP_Safe_Migrator::NONCE => wp_create_nonce('clear_completed'),
            'clear_completed_data'    => '1',
        ];

        $this->plugin->handle_actions();

        global $wpdb;
        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = 'committed'",
            WebP_Safe_Migrator::STATUS_META
        ));
        $this->assertSame(0, $remaining, 'No committed status meta rows should remain');
        // The action deletes via direct SQL, so drop the stale meta cache before reading.
        wp_cache_delete($att_id, 'post_meta');
        $this->assertSame('', get_post_meta($att_id, WebP_Safe_Migrator::STATUS_META, true));
    }
}
