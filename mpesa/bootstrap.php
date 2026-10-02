<?php
/**
 * Headless FrontAccounting login for M-Pesa callbacks and the cron job.
 * Same technique as api/bootstrap.php: pre-fill the service-account login so
 * session.inc authenticates instead of showing the login page, then discard
 * the HTML it buffered.
 *
 * Include this file from the top level of a script (not from inside a function):
 * session.inc defines global variables. It sets $mpesa_fa_ready to true when FA is
 * ready and false otherwise (callers then leave the work queued; nothing is lost).
 */
$mpesa_fa_ready = false;

// The service account is chosen in M-Pesa Configuration (stored encrypted in the database).
// mpesa/config.php, or failing that api/config.php, is the fallback.
$mpesa_svc = null;
$path_to_root = dirname(__DIR__);
if (is_readable($path_to_root.'/config_db.php')) {
	require $path_to_root.'/config_db.php';
	if (isset($db_connections[$def_coy])) {
		$mpesa_c = $db_connections[$def_coy];
		mysqli_report(MYSQLI_REPORT_OFF);
		$mpesa_db = @new mysqli($mpesa_c['host'], $mpesa_c['dbuser'], $mpesa_c['dbpassword'], $mpesa_c['dbname'], $mpesa_c['port'] !== '' ? (int)$mpesa_c['port'] : 3306);
		if (!$mpesa_db->connect_errno) {
			$mpesa_q = @$mpesa_db->query("SELECT service_user, service_password FROM {$mpesa_c['tbpref']}mpesa_config WHERE id=1");
			$mpesa_row = $mpesa_q ? $mpesa_q->fetch_assoc() : null;
			if ($mpesa_row && $mpesa_row['service_user'] !== '') {
				$supplier_company_id = $def_coy;   // tells mpesa_crypto.inc which company's key to read
				require_once __DIR__.'/includes/mpesa_crypto.inc';
				$mpesa_pw = mpesa_decrypt($mpesa_row['service_password']);
				if ($mpesa_pw !== '') $mpesa_svc = array('fa_root' => $path_to_root, 'fa_company' => $def_coy, 'fa_service_user' => $mpesa_row['service_user'], 'fa_service_pass' => $mpesa_pw);
			}
			$mpesa_db->close();
		}
	}
}
if ($mpesa_svc === null) {
	$mpesa_cfg_file = is_readable(__DIR__.'/config.php') ? __DIR__.'/config.php' : (is_readable($path_to_root.'/api/config.php') ? $path_to_root.'/api/config.php' : null);
	if ($mpesa_cfg_file !== null) $mpesa_svc = require $mpesa_cfg_file;
}
if ($mpesa_svc === null) {
	error_log('M-Pesa: no service account. Choose one in Banking > M-Pesa Configuration (or create mpesa/config.php).');
} else {
	$path_to_root = $mpesa_svc['fa_root'] ?? dirname(__DIR__);

	if (!isset($_SERVER['REQUEST_URI']))     $_SERVER['REQUEST_URI'] = '/pay/hook';
	if (!isset($_SERVER['REMOTE_ADDR']))     $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
	if (!isset($_SERVER['HTTP_USER_AGENT'])) $_SERVER['HTTP_USER_AGENT'] = 'mpesa-hook';
	if (!isset($_SERVER['SERVER_NAME']))     $_SERVER['SERVER_NAME'] = 'localhost';

	// Never touch a browser's session: ignore its cookie and never send one back.
	foreach ($_COOKIE as $mpesa_k => $mpesa_v) if (preg_match('/^FA[0-9a-f]{32}$/i', $mpesa_k)) unset($_COOKIE[$mpesa_k]);
	@ini_set('session.use_cookies', '0');
	@ini_set('session.use_only_cookies', '1');

	$_POST = array('company_login_name' => $mpesa_svc['fa_company'] ?? 0, 'user_name_entry_field' => $mpesa_svc['fa_service_user'],
		'password' => $mpesa_svc['fa_service_pass'], 'ui_mode' => 0);
	$_GET = array();
	include_once($path_to_root.'/includes/session.inc');
	while (ob_get_level() > 0) ob_end_clean();
	$_POST = array();

	if (isset($_SESSION['wa_current_user']) && $_SESSION['wa_current_user']->logged_in())
		$mpesa_fa_ready = true;
	else
		error_log('M-Pesa: the service account could not log in to FrontAccounting.');
}
