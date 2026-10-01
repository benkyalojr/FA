<?php
// Shared presentation data for sign-in and password recovery. No authentication
// or password changes happen here; those remain in the session layer.
if (!isset($path_to_root)) { http_response_code(404); exit; }
// Result handlers include this file from function scope. FA's form helpers
// initialize these variables at include time and later access them globally.
global $ajax_divs, $form_nested, $hidden_fields;
include_once $path_to_root . '/includes/ui/ui_controls.inc';
include_once $path_to_root . '/includes/ui/ui_input.inc';

$identity = require $path_to_root . '/ui/config.php';
$encoding = $_SESSION['language']->encoding ?? 'UTF-8';
$escape = static function ($value) use ($encoding) {
    // Session bootstrap already HTML-encodes POST values and the return URI.
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE,
        strtolower($encoding) === 'iso-8859-2' ? 'ISO-8859-1' : $encoding, false);
};
$allow = SECURE_ONLY !== true
    || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['SERVER_NAME'] ?? '') === 'localhost';
$login_timeout = false;
$blocked = false;
$auth_message = '';
$auth_error = false;
$coy = user_company() ?? ($def_coy ?? 0);
if (empty($_SESSION['wa_current_user']->last_act) && isset($_POST['company_login_name'])
    && is_scalar($_POST['company_login_name']) && isset($db_connections[$_POST['company_login_name']]))
    $coy = $_POST['company_login_name'];
$company_nickname = is_string($_POST['company_login_nickname'] ?? null) ? $_POST['company_login_nickname'] : '';
