<?php
/**
 * Create api/config.php from config.example.php (one-time server setup).
 *
 *   php api/tools/setup_config.php
 *
 * config.php is not in git — each server needs its own copy with the correct
 * FA service account credentials.
 */
$config = dirname(__DIR__) . '/config.php';
$example = dirname(__DIR__) . '/config.example.php';

if (is_readable($config)) {
    fwrite(STDOUT, "api/config.php already exists — nothing to do.\n");
    exit(0);
}

if (!is_readable($example)) {
    fwrite(STDERR, "Missing api/config.example.php\n");
    exit(1);
}

if (!copy($example, $config)) {
    fwrite(STDERR, "Could not write api/config.php — check directory permissions.\n");
    exit(1);
}

fwrite(STDOUT, "Created api/config.php from config.example.php\n");
fwrite(STDOUT, "Edit fa_service_user and fa_service_pass if your FA service account differs.\n");
fwrite(STDOUT, "Then re-run your tool, e.g. php api/tools/fix_combo_search_prefs.php\n");
