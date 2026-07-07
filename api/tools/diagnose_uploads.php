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

global $AVOGS_CFG;
$apiRoot = dirname(__DIR__);
$storage = isset($AVOGS_CFG['upload_dir']) ? $AVOGS_CFG['upload_dir'] : ($apiRoot . '/storage');
$trapDir = $apiRoot . '/uploads';

out("=== AVO'Gs upload diagnostics ===\n");

out("Storage dir (upload_dir): {$storage}");
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

$tbl = Db::t('uploads');
$n = (int) db_fetch_assoc(db_query("SELECT COUNT(*) AS n FROM {$tbl}", 'count uploads'))['n'];
out("\nDB registry {$tbl}: {$n} row(s)");

out("\nRecommended mobile endpoint on nginx hosts:");
out("  POST /api/media   (alias, avoids /uploads directory name collision)");
out("  POST /api/uploads (legacy — OK if api/uploads/ does NOT exist on disk)");
out("\nAfter fixing, test:");
out("  curl -X POST https://your-host/api/media -H 'Authorization: Bearer TOKEN' -F 'file=@photo.jpg'");
out('');
