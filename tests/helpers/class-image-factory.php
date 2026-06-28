<?php
/**
 * WebP Image Factory
 *
 * Generates real image files (and attachments) for integration tests.
 * Referenced by tests/bootstrap.php. Complements WebP_Test_Helper with a
 * focused, format-aware image generator.
 */

class WebP_Image_Factory {

    /** @var string[] Absolute paths of files created during the test run. */
    private static $files = [];

    /**
     * Create an image file of the given type/size and return its absolute path.
     *
     * @param string $type  jpeg|png|gif|webp
     * @param int    $w
     * @param int    $h
     * @param string|null $name Optional filename (within the uploads dir).
     */
    public static function image(string $type = 'jpeg', int $w = 320, int $h = 240, ?string $name = null): string {
        if (!function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('GD is required to generate test images.');
        }

        $uploads = wp_get_upload_dir();
        wp_mkdir_p($uploads['basedir']);

        $ext = $type === 'jpeg' ? 'jpg' : $type;
        $name = $name ?: ('factory-' . $type . '-' . $w . 'x' . $h . '-' . uniqid() . '.' . $ext);
        $path = trailingslashit($uploads['basedir']) . $name;

        $im = imagecreatetruecolor($w, $h);
        // Two-tone content so compression has something to chew on.
        $bg = imagecolorallocate($im, 60, 120, 180);
        imagefilledrectangle($im, 0, 0, $w, $h, $bg);
        $fg = imagecolorallocate($im, 240, 240, 240);
        imagefilledellipse($im, (int)($w / 2), (int)($h / 2), (int)($w / 2), (int)($h / 2), $fg);

        switch ($type) {
            case 'jpeg':
                imagejpeg($im, $path, 90);
                break;
            case 'png':
                imagepng($im, $path);
                break;
            case 'gif':
                imagegif($im, $path);
                break;
            case 'webp':
                if (!function_exists('imagewebp')) {
                    imagedestroy($im);
                    throw new RuntimeException('WebP encoding (imagewebp) not available.');
                }
                imagewebp($im, $path, 80);
                break;
            default:
                imagedestroy($im);
                throw new InvalidArgumentException("Unsupported image type: {$type}");
        }
        imagedestroy($im);

        self::$files[] = $path;
        return $path;
    }

    /**
     * Create a (pseudo) animated GIF carrying the NETSCAPE2.0 marker the plugin
     * uses to detect animation.
     */
    public static function animated_gif(int $w = 64, int $h = 64): string {
        $uploads = wp_get_upload_dir();
        wp_mkdir_p($uploads['basedir']);
        $path = trailingslashit($uploads['basedir']) . 'factory-animated-' . uniqid() . '.gif';

        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 200, 30, 30));
        ob_start();
        imagegif($im);
        $data = ob_get_clean();
        imagedestroy($im);

        // imagegif() may emit either a 'GIF87a' or 'GIF89a' header, so anchor the
        // marker injection to whichever header is present (normalising to GIF89a).
        // A plain str_replace('GIF89a', ...) silently no-ops on GIF87a output,
        // leaving the NETSCAPE2.0 marker absent and the GIF undetected as animated.
        $marker = "NETSCAPE2.0\x03\x01\x00\x00\x00";
        $data = preg_replace('/^GIF8[79]a/', 'GIF89a' . $marker, $data, 1);
        file_put_contents($path, $data);

        self::$files[] = $path;
        return $path;
    }

    /**
     * Create an attachment from a generated image and return the attachment ID.
     */
    public static function attachment(string $type = 'jpeg', int $w = 320, int $h = 240): int {
        $path = self::image($type, $w, $h);
        $filename = basename($path);

        $filetype = wp_check_filetype($filename, null);
        $att_id = wp_insert_attachment([
            'post_mime_type' => $filetype['type'],
            'post_title'     => sanitize_file_name($filename),
            'post_status'    => 'inherit',
        ], $path);

        if (is_wp_error($att_id) || !$att_id) {
            throw new RuntimeException('Failed to insert attachment for test image.');
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $meta = wp_generate_attachment_metadata($att_id, $path);
        wp_update_attachment_metadata($att_id, $meta);

        return (int) $att_id;
    }

    /**
     * Remove any files created during the run. Attachments created via
     * attachment() should be removed with wp_delete_attachment() by the test.
     */
    public static function cleanup(): void {
        foreach (self::$files as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        self::$files = [];
    }
}
