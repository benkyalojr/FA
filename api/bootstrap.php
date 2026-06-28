<?php
/**
 * Headless FrontAccounting bootstrap for the AVO'Gs REST API.
 *
 * Pre-fills the service-account login so FA's session.inc authenticates and
 * continues (instead of rendering the login page), then discards any HTML FA
 * buffered during start-up so we can emit clean JSON.
 */

if (!defined('AVOGS_API')) {
    define('AVOGS_API', 1);
}

$AVOGS_CFG = require __DIR__ . '/config.php';

// We surface our own JSON errors; keep PHP/FA notices out of the response body.
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING & ~E_STRICT);
ini_set('display_errors', '0');

$path_to_root = $AVOGS_CFG['fa_root'];

// Minimal web context FA's session.inc expects.
if (!isset($_SERVER['REQUEST_URI']))     $_SERVER['REQUEST_URI'] = '/api';
if (!isset($_SERVER['REMOTE_ADDR']))     $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
if (!isset($_SERVER['HTTP_USER_AGENT'])) $_SERVER['HTTP_USER_AGENT'] = 'avogs-api';
if (!isset($_SERVER['SERVER_NAME']))     $_SERVER['SERVER_NAME'] = 'localhost';

// Preserve any real request form params, then hand FA the service login.
$AVOGS_CLIENT_POST = $_POST;
$_POST = array(
    'company_login_name'   => $AVOGS_CFG['fa_company'],
    'user_name_entry_field' => $AVOGS_CFG['fa_service_user'],
    'password'             => $AVOGS_CFG['fa_service_pass'],
    'ui_mode'             => 0,
);

include_once($path_to_root . '/includes/session.inc');

// Drop the HTML page buffer FA opened; the API outputs JSON only.
while (ob_get_level() > 0) {
    ob_end_clean();
}

$_POST = $AVOGS_CLIENT_POST;

if (!isset($_SESSION['wa_current_user']) || !$_SESSION['wa_current_user']->logged_in()) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(array('error' => array(
        'code' => 'fa_bootstrap_failed',
        'message' => 'Could not bootstrap the FrontAccounting session. Check config_db.php and the service account.',
    )));
    exit;
}

// Prefix for the app's own tables (FA prefix + avogs_), e.g. "0_avogs_".
if (!defined('AVOGS_PREF')) {
    define('AVOGS_PREF', TB_PREF . 'avogs_');
}

require_once __DIR__ . '/lib/Request.php';
require_once __DIR__ . '/lib/Response.php';
require_once __DIR__ . '/lib/Router.php';
require_once __DIR__ . '/lib/Db.php';
require_once __DIR__ . '/lib/Auth.php';

foreach (glob(__DIR__ . '/controllers/*.php') as $__controller) {
    require_once $__controller;
}
