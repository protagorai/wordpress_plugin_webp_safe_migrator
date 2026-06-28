<?php
/**
 * Integration: pure-ish internal helpers (dimension parsing, bounding-box math,
 * format options, deep replace) exercised via reflection.
 */
class Test_Internal_Helpers extends WP_UnitTestCase {

    /** @var WebP_Safe_Migrator */
    private $plugin;

    public function set_up(): void {
        parent::set_up();
        $this->plugin = $GLOBALS['webp_safe_migrator'];
    }

    public function test_parse_filename_dimensions(): void {
        $this->assertSame([1920, 1080],
            WebP_Reflect::call($this->plugin, 'parse_filename_dimensions', ['hero-1920x1080.jpg']));
        $this->assertSame([150, 150],
            WebP_Reflect::call($this->plugin, 'parse_filename_dimensions', ['thumb_150x150.png']));
        $this->assertNull(
            WebP_Reflect::call($this->plugin, 'parse_filename_dimensions', ['no-dimensions.jpg']));
    }

    public function test_bounding_box_max_scales_down_but_never_up(): void {
        [$w, $h] = WebP_Reflect::call($this->plugin, 'calculate_bounding_box_dimensions',
            [4000, 2000, 1920, 1080, 'max']);
        $this->assertLessThanOrEqual(1920, $w);
        $this->assertLessThanOrEqual(1080, $h);

        // Already within bounds → returned unchanged.
        $this->assertSame([800, 600],
            WebP_Reflect::call($this->plugin, 'calculate_bounding_box_dimensions',
                [800, 600, 1920, 1080, 'max']));
    }

    public function test_get_format_options_includes_avif_speed(): void {
        WebP_Reflect::set($this->plugin, 'settings',
            array_merge(WebP_Reflect::get($this->plugin, 'settings'), ['avif_speed' => 4]));
        $opts = WebP_Reflect::call($this->plugin, 'get_format_options', ['avif', null]);
        $this->assertArrayHasKey('speed', $opts);
        $this->assertSame(4, $opts['speed']);
    }

    public function test_deep_replace_walks_arrays_and_objects(): void {
        $map = ['http://a/x.jpg' => 'http://a/x.webp'];
        $in  = ['k' => ['http://a/x.jpg', (object) ['u' => 'http://a/x.jpg']]];
        $out = WebP_Reflect::call($this->plugin, 'deep_replace', [$in, $map]);
        $this->assertSame('http://a/x.webp', $out['k'][0]);
        $this->assertSame('http://a/x.webp', $out['k'][1]->u);
    }
}
