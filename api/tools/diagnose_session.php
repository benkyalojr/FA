<?php
/**
 * Session diagnostics for live troubleshooting.
 *
 *   php api/tools/diagnose_session.php
 *
 * Prints login_tout, session save path, FA cookie name, and proxy headers
 * so you can see why sessions expire on the server.
 */

require dirname(__DIR__) . '/bootstrap.php';

function out($m) { fwrite(STDOUT, $m . "\n"); }

$P = TB_PREF;
$faCookie = session_name();

out("=== AVO'Gs / FA session diagnostics ===\n");

out("FA session cookie name: {$faCookie}");
out("PHP version:            " . PHP_VERSION);
out("session.save_path:      " . session_save_path());
out("session.save_path writable: " . (is_writable(session_save_path()) ? 'yes' : 'NO'));
out("session.gc_maxlifetime: " . ini_get('session.gc_maxlifetime'));
out("session.use_cookies:    " . ini_get('session.use_cookies') . " (API bootstrap forces 0)");

$row = db_fetch_assoc(db_query("SELECT value FROM {$P}sys_prefs WHERE name = 'login_tout'", 'login_tout'));
$tout = $row ? (int)$row['value'] : null;
out("\nlogin_tout (seconds):   " . ($tout === null ? 'NOT SET' : $tout));
if ($tout !== null && $tout < 300) {
    out("  ^ TOO LOW — run: php api/tools/fix_login_tout.php");
}

out("\nProxy / HTTPS signals (what PHP sees on CLI may differ from web):");
foreach (array('REMOTE_ADDR', 'HTTPS', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_PROTO') as $k) {
    $v = isset($_SERVER[$k]) ? $_SERVER[$k] : '(not set)';
    out("  {$k}: {$v}");
}

out("\nIf web users logout when the mobile app or /api/ is used:");
out("  deploy api/bootstrap.php with session.use_cookies=0 (latest APIs branch).");
out("If logout happens on every new link behind nginx:");
out("  deploy includes/session.inc proxy IP fix; ensure nginx sets X-Forwarded-For or X-Real-IP.");
out("\nAfter changing login_tout, users must log out and back in.\n");
