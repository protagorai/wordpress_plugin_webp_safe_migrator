<?php
/**
 * E2E seed script — run via `wp eval-file`.
 *
 * Creates a few real images, registers them as attachments, and publishes a post
 * that references their full-size URLs. Stores a fixture record so assert.php can
 * verify the conversion afterwards.
 */

if (!defined('WP_CLI')) {
    fwrite(STDERR, "seed-media.php must be run via wp-cli (wp eval-file)\n");
    exit(1);
}

require_once ABSPATH . 'wp-admin/includes/image.php';

if (!function_exists('imagecreatetruecolor')) {
    WP_CLI::error('GD is required to create seed images.');
}

$specs       = [['jpeg', 640, 480], ['png', 800, 600], ['jpeg', 320, 240]];
$attachments = [];
$orig_urls   = [];

$uploads = wp_get_upload_dir();
wp_mkdir_p($uploads['basedir']);

foreach ($specs as $i => $spec) {
    list($type, $w, $h) = $spec;
    $ext  = $type === 'jpeg' ? 'jpg' : $type;
    $name = sprintf('e2e-%d-%dx%d.%s', $i, $w, $h, $ext);
    $path = trailingslashit($uploads['basedir']) . $name;

    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 40 + $i * 30, 90, 160));
    imagefilledellipse($im, (int) ($w / 2), (int) ($h / 2), (int) ($w / 2), (int) ($h / 2),
        imagecolorallocate($im, 240, 240, 240));
    if ($type === 'jpeg') {
        imagejpeg($im, $path, 90);
    } else {
        imagepng($im, $path);
    }
    imagedestroy($im);

    $filetype = wp_check_filetype($name, null);
    $att_id   = wp_insert_attachment([
        'post_mime_type' => $filetype['type'],
        'post_title'     => 'E2E ' . $name,
        'post_status'    => 'inherit',
    ], $path);

    if (is_wp_error($att_id) || !$att_id) {
        WP_CLI::error('Failed to insert attachment for ' . $name);
    }

    wp_update_attachment_metadata($att_id, wp_generate_attachment_metadata($att_id, $path));

    $attachments[] = (int) $att_id;
    $orig_urls[]   = wp_get_attachment_url($att_id);
}

$content = "<h2>E2E fixture</h2>\n";
foreach ($orig_urls as $url) {
    $content .= '<img src="' . esc_url($url) . '" alt="e2e" />' . "\n";
}

$post_id = wp_insert_post([
    'post_title'   => 'E2E Fixture Post',
    'post_content' => $content,
    'post_status'  => 'publish',
]);

if (is_wp_error($post_id) || !$post_id) {
    WP_CLI::error('Failed to create fixture post.');
}

update_option('webp_e2e_fixture', [
    'attachments' => $attachments,
    // Store base64 so the conversion's DB-rewrite pass cannot mutate our record of
    // the original URLs (it legitimately rewrites every literal .jpg/.png occurrence
    // everywhere, which would otherwise include this very fixture option).
    'orig_urls'   => array_map('base64_encode', $orig_urls),
    'post_id'     => (int) $post_id,
], false);

WP_CLI::success(sprintf('Seeded %d attachments and fixture post #%d', count($attachments), $post_id));
