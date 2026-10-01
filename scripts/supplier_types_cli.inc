<?php
// CLI-only database adapter for the migration and isolated integration checks.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$path_to_root = dirname(__DIR__);
require $path_to_root.'/config_db.php';
$company_id = isset($supplier_company_id) ? $supplier_company_id : 0;
if (!isset($db_connections[$company_id]))
    throw new RuntimeException('Unknown company ID.');
$c = $db_connections[$company_id];
$db = new mysqli($c['host'], $c['dbuser'], $c['dbpassword'], $c['dbname'], !empty($c['port']) ? (int)$c['port'] : 3306);
$db->set_charset('utf8mb4');
define('TB_PREF', isset($supplier_test_prefix) ? $supplier_test_prefix : $c['tbpref']);
require_once $path_to_root.'/includes/db/system_audit.inc';
function db_query($sql, $message=null) {
    global $db;
    system_audit_context($db,TB_PREF);
    $result=$db->query($sql);
    if ($result) system_audit_refresh_schema($db,TB_PREF,$sql);
    return $result;
}
function db_escape($value) { global $db; return $value === null ? 'NULL' : "'".$db->real_escape_string((string)$value)."'"; }
function db_fetch($result) { return $result->fetch_array(MYSQLI_BOTH); }
function db_fetch_assoc($result) { return $result->fetch_assoc(); }
function db_num_rows($result) { return $result->num_rows; }
function db_insert_id() { global $db; return $db->insert_id; }
function db_num_affected_rows() { global $db; return $db->affected_rows; }

// Minimal CLI-compatible stand-ins for functions that normally come from
// the full web session bootstrap (admin/db/company_db.inc's
// get_company_pref() needs a $SysPrefs object built at login; there's no
// such thing here). Only the single-string-lookup form is implemented -
// that's all any CLI-run code path (background task handlers, etc.)
// actually calls. Guarded so a script that DOES load the real versions
// isn't affected.
if (!function_exists('get_company_pref')) {
	function get_company_pref($pref = null)
	{
		if ($pref === null) {
			$all = array();
			$result = db_query("SELECT name, value FROM ".TB_PREF."sys_prefs");
			while ($row = db_fetch($result)) $all[$row['name']] = $row['value'];
			return $all;
		}
		$row = db_fetch(db_query("SELECT value FROM ".TB_PREF."sys_prefs WHERE name=".db_escape($pref)));
		return $row ? $row['value'] : null;
	}
}
if (!function_exists('update_company_prefs')) {
	function update_company_prefs($params)
	{
		foreach ($params as $name => $value)
			db_query("UPDATE ".TB_PREF."sys_prefs SET value=".db_escape($value)." WHERE name=".db_escape($name));
		return true;
	}
}
if (!function_exists('set_company_pref')) {
	function set_company_pref($pref, $category, $type, $length, $value)
	{
		db_query("REPLACE ".TB_PREF."sys_prefs SET name=".db_escape($pref).", category=".db_escape($category)
			.", type=".db_escape($type).", length=".db_escape($length).", value=".db_escape($value));
	}
}
// PHP's gettext _() may not be bound outside the full FA bootstrap
// (includes/lang), and some channel-adapter code paths call it for
// user-facing status text.
if (!function_exists('_')) {
	function _($s) { return $s; }
}
// Core FA functions like sales/includes/db/cust_trans_db.inc::
// get_customer_trans() call display_db_error() directly (not just via
// db_query()'s own error path) on a genuinely anomalous condition (e.g.
// "no debtor trans found for given params") - normally defined in
// includes/errors.inc, loaded by the full web session bootstrap, never by
// this shim. Without this, that call fatals as "Call to undefined
// function" inside a bg_tasks handler - still caught and retried by
// cron/run_scheduled_tasks.php's own Throwable handling, but with a
// useless error message. Throws instead of exiting (errors.inc's own
// default is to exit()) so it behaves like any other CLI/cron failure -
// caught, logged with its real message, and retried.
if (!function_exists('display_db_error')) {
	function display_db_error($msg, $sql_statement = null, $exit = true)
	{
		throw new RuntimeException((string)$msg);
	}
}
