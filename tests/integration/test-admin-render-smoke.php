<?php
/**
 * Integration smoke: every admin tab renders without fatals and emits markup.
 * Cheap but meaningful coverage of the large UI-rendering surface.
 */
class Test_Admin_Render_Smoke extends WP_UnitTestCase {

    /** @var WebP_Safe_Migrator */
    private $plugin;

    public function set_up(): void {
        parent::set_up();
        $this->plugin = $GLOBALS['webp_safe_migrator'];
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function render(string $method): string {
        ob_start();
        try {
            $this->plugin->$method();
        } finally {
            $out = ob_get_clean();
        }
        return (string) $out;
    }

    public function test_all_tabs_render(): void {
        $methods = [
            'render_settings_tab',
            'render_reports_tab',
            'render_errors_tab',
            'render_reprocess_tab',
            'render_dimensions_tab',
            'render_maintenance_tab',
            'render_batch_tab',
            'render_tabbed_interface',
        ];
        foreach ($methods as $m) {
            $html = $this->render($m);
            $this->assertNotSame('', $html, "$m should emit markup");
        }
    }
}
