<?php
/**
 * Router script for the PHP built-in server (local dev):
 *   php -S 127.0.0.1:8088 api/router.php
 * Then call e.g. http://127.0.0.1:8088/api/stores
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve uploaded files directly if they exist.
if (preg_match('#^/api/uploads/(.+)$#', $uri, $m)) {
    $file = __DIR__ . '/uploads/' . basename($m[1]);
    if (is_file($file)) {
        return false; // let the built-in server serve the static file
    }
}

// Everything else under /api goes through the front controller.
require __DIR__ . '/index.php';
