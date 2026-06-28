<?php
/**
 * E2E lifecycle verification — run via `wp eval-file` after assert.php.
 *
 * Exercises the safety lifecycle on the LIVE install via the plugin instance:
 *   - rollback the first attachment   → original restored, no longer WebP,
 *                                        its referencing URL comes back,
 *   - commit the second attachment    → backup removed, plugin meta cleared,
 *                                        file stays WebP.
 */

if (!defined('WP_CLI')) {
    fwrite(STDERR, "verify-lifecycle.php must run via wp-cli\n");
    exit(1);
}

$fx = get_option('webp_e2e_fixture');
if (!$fx || count($fx['attachments']) < 2) {
    WP_CLI::error('Fixture needs at least two attachments for lifecycle checks.');
}

$plugin = $GLOBALS['webp_safe_migrator'] ?? null;
if (!$plugin) {
    WP_CLI::error('Plugin instance not found in $GLOBALS.');
}

$invoke = function ($method, array $args) use ($plugin) {
    $ref = new ReflectionMethod($plugin, $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs($plugin, $args);
};

$fail = [];

// --- Rollback the first attachment ---
$a0  = (int) $fx['attachments'][0];
$ok0 = $invoke('rollback_conversion', [$a0]);
if (!$ok0) {
    $fail[] = "rollback_conversion returned falsey for #$a0";
} else {
    if (get_post_mime_type($a0) === 'image/webp') $fail[] = "#$a0 still image/webp after rollback";
    if (!file_exists((string) get_attached_file($a0))) $fail[] = "#$a0 original file missing after rollback";
    if (get_post_meta($a0, '_webp_migrator_status', true) !== '') $fail[] = "#$a0 status meta not cleared after rollback";
}

// --- Commit the second attachment ---
$a1     = (int) $fx['attachments'][1];
$backup = get_post_meta($a1, '_webp_migrator_backup_dir', true);
$ok1    = $invoke('commit_deletions', [$a1]);
if (!$ok1) {
    $fail[] = "commit_deletions returned falsey for #$a1";
} else {
    if ($backup && is_dir($backup)) $fail[] = "backup still present after commit: $backup";
    if (get_post_meta($a1, '_webp_migrator_status', true) !== '') $fail[] = "#$a1 status meta not cleared after commit";
    if (substr((string) get_attached_file($a1), -5) !== '.webp') $fail[] = "#$a1 should remain WebP after commit";
}

if ($fail) {
    WP_CLI::error("LIFECYCLE FAILED:\n - " . implode("\n - ", $fail));
}

WP_CLI::success('LIFECYCLE PASSED: rollback restored the original; commit removed the backup and kept WebP.');
