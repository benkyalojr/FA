<?php
/**
 * One-shot fix for broken photo uploads on nginx/Apache hosts.
 *
 *   php api/tools/fix_upload_setup.php
 *
 * - Sets upload_dir in api/config.php to api/storage (not api/uploads)
 * - Creates api/storage + api/logs with sane permissions
 * - Moves any files from api/uploads/ into api/storage/, then removes api/uploads/
 *
 * After running, raise PHP upload_max_filesize to 20M if still at 2M (see output).
 */
$apiRoot = dirname(__DIR__);
$configFile = $apiRoot . '/config.php';
$exampleFile = $apiRoot . '/config.example.php';
$storageDir = $apiRoot . '/storage';
$logsDir = $apiRoot . '/logs';
$trapDir = $apiRoot . '/uploads';

function out($m) { fwrite(STDOUT, $m . "\n"); }

out("=== Fix AVO'Gs upload setup ===\n");

if (!is_readable($configFile)) {
    if (!is_readable($exampleFile)) {
        out("ERROR: missing api/config.php — run: php api/tools/setup_config.php");
        exit(1);
    }
    copy($exampleFile, $configFile);
    out("Created api/config.php from config.example.php");
}

$text = file_get_contents($configFile);
$orig = $text;

// Normalise upload_dir to __DIR__ . '/storage'
$text = preg_replace(
    "/(['\"]upload_dir['\"]\\s*=>\\s*)__DIR__\\s*\\.\\s*['\"]\\/uploads['\"]/",
    "$1__DIR__ . '/storage'",
    $text
);
$text = preg_replace(
    "/(['\"]upload_dir['\"]\\s*=>\\s*)['\"][^'\"]*\\/uploads\\/?['\"]/",
    "$1__DIR__ . '/storage'",
    $text
);
if ($text !== $orig) {
    file_put_contents($configFile, $text);
    out("Updated api/config.php: upload_dir → __DIR__ . '/storage'");
} else {
    out("api/config.php upload_dir already points at storage (or could not auto-patch — check manually).");
}

foreach (array($storageDir, $logsDir) as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
        out("Created {$dir}");
    }
    @chmod($dir, 0775);
}

if (is_dir($trapDir)) {
    $moved = 0;
    foreach (glob($trapDir . '/*') ?: array() as $f) {
        if (is_file($f)) {
            $dest = $storageDir . '/' . basename($f);
            if (@rename($f, $dest)) {
                $moved++;
            }
        }
    }
    if (@rmdir($trapDir) || !is_dir($trapDir)) {
        // remove leftover non-empty dir
        if (is_dir($trapDir)) {
            $left = glob($trapDir . '/*');
            if (empty($left)) {
                @rmdir($trapDir);
            } else {
                out("WARNING: could not remove api/uploads/ — move remaining files manually, then rm -rf api/uploads");
            }
        }
    }
    if (!is_dir($trapDir)) {
        out("Removed api/uploads/ directory trap" . ($moved ? " (moved {$moved} file(s) to storage/)" : ''));
    }
} else {
    out("OK: no api/uploads/ directory");
}

$writable = is_writable($storageDir);
out("\nStorage: {$storageDir}");
out("  writable (CLI user): " . ($writable ? 'yes' : 'NO'));

if (!$writable) {
    out("\nRun as root or with sudo (adjust www-data to your PHP-FPM user):");
    out("  sudo chown -R www-data:www-data {$storageDir} {$logsDir}");
    out("  sudo chmod 775 {$storageDir} {$logsDir}");
}

$maxUp = ini_get('upload_max_filesize');
if ($maxUp && (int) $maxUp < 10) {
    out("\nPHP upload_max_filesize is {$maxUp} — phone photos often fail. In php.ini:");
    out("  upload_max_filesize = 20M");
    out("  post_max_size = 22M");
    out("Then: sudo systemctl restart php8.2-fpm  (adjust version)");
}

out("\nMobile app: POST /api/media  (multipart field: file)");
out("Verify: php api/tools/diagnose_uploads.php\n");
