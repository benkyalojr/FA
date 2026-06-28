<?php
/**
 * AVO'Gs REST API configuration — EXAMPLE.
 *
 * Copy this file to `config.php` (which is git-ignored) and adjust for your
 * environment. The API runs *inside* FrontAccounting: it bootstraps FA
 * headlessly (see bootstrap.php) using the service account below, so every
 * request has FA's DB connection and business functions available.
 */
return array(
    // FrontAccounting install root (one level up from this api/ folder).
    'fa_root' => dirname(__DIR__),

    // Which FA company (db_connections index) and service account to log in as.
    // Use a dedicated, least-privilege FA user in production.
    'fa_company' => 0,
    'fa_service_user' => 'admin',
    'fa_service_pass' => 'CHANGE_ME',

    // API token lifetime in days.
    'token_ttl_days' => 30,

    // Default FA stock location used for inventory/sales.
    'default_location' => 'DEF',

    // Display currency for the app (informational; amounts are integer KSh).
    'currency' => 'KSh',

    // Directory for uploaded checklist photos (created if missing).
    'upload_dir' => __DIR__ . '/uploads',

    // --- Logging ---------------------------------------------------------
    // File the API writes its log to (directory created if missing).
    // Make sure the web-server user can write to this path.
    'log_file' => __DIR__ . '/logs/api.log',
    // Minimum level to record: debug | info | warning | error.
    // Use 'warning' or 'error' in production to keep the log lean.
    'log_level' => 'debug',
    // Log a line for every incoming request (method + path).
    'log_requests' => true,
);
