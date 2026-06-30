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

// Bring up the file logger before anything else can fail, so start-up problems
// (including FA's headless bootstrap failing) are captured.
require_once __DIR__ . '/lib/Logger.php';
Logger::init(
    isset($AVOGS_CFG['log_file']) ? $AVOGS_CFG['log_file'] : (__DIR__ . '/logs/api.log'),
    isset($AVOGS_CFG['log_level']) ? $AVOGS_CFG['log_level'] : 'debug'
);

// Capture PHP fatals/warnings that would otherwise be invisible (display_errors
// is off above). Without this, a fatal during FA bootstrap leaves no trace.
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false; // respect the suppression configured above
    }
    Logger::warning('PHP error: ' . $message, array('file' => $file, 'line' => $line));
    return false; // let PHP's normal handling continue
});
set_exception_handler(function ($e) {
    Logger::error('Uncaught exception: ' . $e->getMessage(), array(
        'file' => $e->getFile(), 'line' => $e->getLine(),
    ));
});
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
        Logger::error('PHP fatal: ' . $err['message'], array(
            'file' => $err['file'], 'line' => $err['line'],
        ));
    }
    // If FA's session.inc rendered its HTML login page and exited (service
    // account login failed), execution never reaches the controllers. Flag it.
    if (!defined('AVOGS_BOOTSTRAP_OK')) {
        Logger::error('FA bootstrap did not complete: the request exited before the API ran. '
            . 'This usually means the FA service-account login failed (FA rendered its login page) '
            . 'or /api was not routed to api/index.php (e.g. nginx without an /api location block).');
    }
});

Logger::debug('Bootstrapping FA', array(
    'method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null,
    'uri' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null,
    'company' => isset($AVOGS_CFG['fa_company']) ? $AVOGS_CFG['fa_company'] : null,
    'service_user' => isset($AVOGS_CFG['fa_service_user']) ? $AVOGS_CFG['fa_service_user'] : null,
));

$path_to_root = $AVOGS_CFG['fa_root'];

// Minimal web context FA's session.inc expects.
if (!isset($_SERVER['REQUEST_URI']))     $_SERVER['REQUEST_URI'] = '/api';
if (!isset($_SERVER['REMOTE_ADDR']))     $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
if (!isset($_SERVER['HTTP_USER_AGENT'])) $_SERVER['HTTP_USER_AGENT'] = 'avogs-api';
if (!isset($_SERVER['SERVER_NAME']))     $_SERVER['SERVER_NAME'] = 'localhost';

// ── Session isolation ────────────────────────────────────────────────────────
// FA's session cookie name is  'FA' + md5(dirname(session.inc)).
// HTTP cookies are port-agnostic: the browser sends the same FA cookie to both
// the web UI (e.g. :8080) and this API (e.g. :8088).  If session.inc picks up
// that cookie it runs login_timeout() on the BROWSER's session and, when the
// timeout fires, writes logged=false back — silently logging the web user out.
//
// Fix: strip any FA-style session cookie from $_COOKIE before session.inc
// calls session_start(), so the API always creates a fresh service-account
// session and NEVER touches the browser's session file.
foreach ($_COOKIE as $_k => $_v) {
    // FA session cookie names are 'FA' followed by a 32-char hex MD5.
    if (preg_match('/^FA[0-9a-f]{32}$/i', $_k)) {
        unset($_COOKIE[$_k]);
    }
}
unset($_k, $_v);

// The API must never send a session cookie back to the browser.  Stripping
// $_COOKIE above stops us READING the user's session, but session.inc still
// uses the FA cookie name — without this, session_start() creates a new id
// and PHP emits  Set-Cookie: FA<hash>=<newid>  which REPLACES the user's
// cookie.  The next web UI click loads the API's empty session → logout.
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '1');
// ────────────────────────────────────────────────────────────────────────────

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
    Logger::error('FA service-account login failed during bootstrap.', array(
        'company' => isset($AVOGS_CFG['fa_company']) ? $AVOGS_CFG['fa_company'] : null,
        'service_user' => isset($AVOGS_CFG['fa_service_user']) ? $AVOGS_CFG['fa_service_user'] : null,
    ));
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(array('error' => array(
        'code' => 'fa_bootstrap_failed',
        'message' => 'Could not bootstrap the FrontAccounting session. Check config_db.php and the service account.',
    )));
    exit;
}

// Reached only when FA authenticated the service account successfully; the
// shutdown handler uses this to detect a silent early exit.
define('AVOGS_BOOTSTRAP_OK', 1);
Logger::debug('FA session ready', array('user' => $AVOGS_CFG['fa_service_user']));

// Release the session write-lock now.  The API uses bearer-token auth stored
// in the database (0_avogs_tokens), not PHP sessions, so there is no reason
// to keep the session file locked for the entire request.  Releasing early
// also prevents concurrent API requests from serialising on the same lock.
// $_SESSION data is still readable after session_write_close().
session_write_close();

// Prefix for the app's own tables (FA prefix + avogs_), e.g. "0_avogs_".
if (!defined('AVOGS_PREF')) {
    define('AVOGS_PREF', TB_PREF . 'avogs_');
}

require_once __DIR__ . '/lib/Request.php';
require_once __DIR__ . '/lib/Response.php';
require_once __DIR__ . '/lib/Router.php';
require_once __DIR__ . '/lib/Db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/FaTransaction.php';

// FA's session.inc registers exception_handler() → end_page() (HTML footer).
// For API requests, emit JSON instead so Phase-2 controllers never leak UI.
set_exception_handler(function ($e) {
    Logger::error('API exception: ' . $e->getMessage(), array(
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'class' => get_class($e),
    ));
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('error' => array(
        'code' => 'server_error',
        'message' => $e->getMessage(),
    )));
    exit;
});

foreach (glob(__DIR__ . '/controllers/*.php') as $__controller) {
    require_once $__controller;
}
