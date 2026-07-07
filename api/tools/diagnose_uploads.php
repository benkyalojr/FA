<?php
/**
 * Upload path diagnostics (run on the server).
 *
 *   php api/tools/diagnose_uploads.php
 *
 * Checks storage permissions, the uploads-route directory trap, and PHP limits.
 */
require dirname(__DIR__) . '/bootstrap.php';

function out($m) { fwrite(STDOUT, $m . "\n"); }

global $AVOGS_CFG, $db_connections;
$apiRoot = dirname(__DIR__);
$storage = isset($AVOGS_CFG['upload_dir']) ? $AVOGS_CFG['upload_dir'] : ($apiRoot . '/storage');
$trapDir = $apiRoot . '/uploads';
$expectedStorage = $apiRoot . '/storage';

out("=== AVO'Gs upload diagnostics ===\n");

out("Storage dir (upload_dir): {$storage}");
if (realpath($storage) === realpath($trapDir) || preg_match('#/uploads/?$#', $storage)) {
    out("  *** WRONG PATH — upload_dir must be api/storage, NOT api/uploads.");
    out("      Edit api/config.php: 'upload_dir' => __DIR__ . '/storage',");
}
if ($storage !== $expectedStorage && realpath($storage) !== realpath($expectedStorage)) {
    out("  (expected default: {$expectedStorage})");
}
out("  exists:   " . (is_dir($storage) ? 'yes' : 'NO'));
out("  writable: " . (is_writable($storage) ? 'yes' : 'NO — fix with chmod/chown'));

if (is_dir($trapDir)) {
    out("\n*** PROBLEM: {$trapDir} exists as a directory.");
    out("    nginx/apache will serve it instead of routing POST /api/uploads to PHP.");
    out("    Result: 301 → /api/uploads/ then 403 Forbidden on POST.");
    out("    Fix: rm -rf api/uploads   (photos belong in api/storage/)");
} else {
    out("\nOK: no api/uploads/ directory (POST /api/uploads can reach index.php).");
}

out("\nPHP upload limits:");
foreach (array('upload_max_filesize', 'post_max_size', 'file_uploads', 'upload_tmp_dir') as $k) {
    out("  {$k}: " . (ini_get($k) !== '' ? ini_get($k) : '(default)'));
}
$maxUp = ini_get('upload_max_filesize');
if ($maxUp && (int) $maxUp < 10) {
    out("  *** upload_max_filesize is very low for phone photos — raise to at least 20M in php.ini.");
}

$comp = isset($_SESSION['wa_current_user']) ? $_SESSION['wa_current_user']->cur_con : 0;
$P = isset($db_connections[$comp]['tbpref']) ? $db_connections[$comp]['tbpref'] : '';
$tbl = ($P !== '' ? $P : '0_') . 'avogs_uploads';
$n = (int) db_fetch_assoc(db_query("SELECT COUNT(*) AS n FROM {$tbl}", 'count uploads'))['n'];
out("\nDB registry {$tbl}: {$n} row(s)");

out("\nRecommended mobile endpoint on nginx hosts:");
out("  POST /api/media   (alias, avoids /uploads directory name collision)");
out("  POST /api/uploads (legacy — OK if api/uploads/ does NOT exist on disk)");
out("\nAfter fixing, test:");
out("  curl -X POST https://your-host/api/media -H 'Authorization: Bearer TOKEN' -F 'file=@photo.jpg'");
out('');
