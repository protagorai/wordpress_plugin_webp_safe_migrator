<?php
/**
 * E2E assertions — run via `wp eval-file` after a conversion on the LIVE install.
 *
 * Validates EVERYTHING the conversion is responsible for:
 *   Filesystem  - main WebP + every size variant exist on disk; original moved to
 *                 a backup directory (validation mode).
 *   Attachment  - MIME is image/webp; metadata 'sizes' all point at .webp files.
 *   Plugin meta - status == relinked; report stored with a non-empty url_map.
 *   Database    - referencing post rewritten to .webp; a global scan finds NO
 *                 remaining references to any original URL in posts, options or
 *                 comments.
 *
 * Exits non-zero (WP_CLI::error) on any failure.
 */

if (!defined('WP_CLI')) {
    fwrite(STDERR, "assert.php must run via wp-cli\n");
    exit(1);
}

global $wpdb;

$fx = get_option('webp_e2e_fixture');
if (!$fx || empty($fx['attachments'])) {
    WP_CLI::error('Fixture record missing — did seed-media.php run?');
}

$fail    = [];
$uploads = wp_get_upload_dir();

foreach ($fx['attachments'] as $att_id) {
    $file = (string) get_attached_file($att_id);
    $mime = get_post_mime_type($att_id);

    if (substr($file, -5) !== '.webp')      $fail[] = "#$att_id not WebP: $file";
    elseif (!file_exists($file))            $fail[] = "#$att_id WebP missing on disk: $file";
    if ($mime !== 'image/webp')             $fail[] = "#$att_id MIME is $mime";

    // Size variants on disk.
    $meta = wp_get_attachment_metadata($att_id);
    if (!empty($meta['sizes'])) {
        $dir = trailingslashit($uploads['basedir']) . trailingslashit(dirname($meta['file']));
        foreach ($meta['sizes'] as $name => $size) {
            if (empty($size['file'])) continue;
            if (substr($size['file'], -5) !== '.webp') $fail[] = "#$att_id size '$name' not webp: {$size['file']}";
            if (!file_exists($dir . $size['file']))     $fail[] = "#$att_id size '$name' missing: {$size['file']}";
        }
    }

    // Plugin status + backup (validation mode).
    $status = get_post_meta($att_id, '_webp_migrator_status', true);
    if ($status !== 'relinked') $fail[] = "#$att_id status is '$status', expected relinked";

    $backup = get_post_meta($att_id, '_webp_migrator_backup_dir', true);
    if (!$backup || !is_dir($backup)) {
        $fail[] = "#$att_id backup directory missing: " . var_export($backup, true);
    } else {
        $contents = array_diff(scandir($backup) ?: [], ['.', '..']);
        if (!$contents) $fail[] = "#$att_id backup directory is empty: $backup";
    }

    // Report + url_map (needed for rollback).
    $report = json_decode((string) get_post_meta($att_id, '_webp_migrator_report', true), true);
    if (!is_array($report) || empty($report['url_map'])) {
        $fail[] = "#$att_id report/url_map missing";
    }
}

// Referencing post rewritten.
$post = get_post($fx['post_id']);
if (!$post) {
    $fail[] = 'fixture post not found';
} elseif (strpos($post->post_content, '.webp') === false) {
    $fail[] = 'post content has no .webp reference after conversion';
}

// Global DB scan: no original URL should survive anywhere obvious.
// orig_urls are base64-encoded in the fixture (see seed-media.php) so the
// conversion didn't rewrite them; decode before scanning.
$orig_urls = array_map('base64_decode', $fx['orig_urls']);
foreach ($orig_urls as $url) {
    $like = '%' . $wpdb->esc_like($url) . '%';
    $in_posts    = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE %s", $like));
    $in_options  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s", $like));
    $in_comments = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_content LIKE %s", $like));
    if ($in_posts || $in_options || $in_comments) {
        $fail[] = "original URL still present (posts=$in_posts options=$in_options comments=$in_comments): $url";
    }
}

if ($fail) {
    WP_CLI::error("E2E FAILED:\n - " . implode("\n - ", $fail));
}

WP_CLI::success(sprintf(
    'E2E PASSED: %d attachments converted to WebP; filesystem, metadata and all DB references verified.',
    count($fx['attachments'])
));
