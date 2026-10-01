<?php
// Server check for installing / troubleshooting: PHP version, extensions, folder
// permissions and where PHP errors are logged. It only answers while the system is
// not installed yet (no config_db.php); afterwards it returns 404, so it is safe to leave.
// Delete this file once you no longer need it.
if (file_exists(__DIR__.'/config_db.php')) {
	http_response_code(404);
	exit;
}
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

if (isset($_GET['phpinfo'])) {   // the full PHP report (pre-install only)
	phpinfo();
	exit;
}

$h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$ok = function ($good, $yes = 'OK', $no = 'Problem') use ($h) {
	return '<span style="color:#fff;background:'.($good ? '#15803d' : '#c81e1e').';padding:2px 8px;border-radius:10px;font-size:12px">'.$h($good ? $yes : $no).'</span>';
};
$rows = function ($title, $items) use ($h) {
	echo '<h2>'.$h($title).'</h2><table>';
	foreach ($items as $k => $v) echo '<tr><th>'.$h($k).'</th><td>'.$v.'</td></tr>';
	echo '</table>';
};

echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><title>Server check</title>'
	.'<style>body{font:14px/1.5 Arial,sans-serif;margin:30px auto;max-width:900px;padding:0 14px;color:#202c40}h1{font-size:22px}h2{font-size:15px;margin:26px 0 8px}'
	.'table{border-collapse:collapse;width:100%}th,td{text-align:left;padding:7px 10px;border-bottom:1px solid #e1e7f0;vertical-align:top}th{width:260px;color:#526079;font-weight:600}code{background:#f1f5fc;padding:1px 5px;border-radius:4px}</style>';
echo '<h1>Server check</h1><p>Not installed yet (no <code>config_db.php</code>). <a href="?phpinfo=1">Full PHP report</a></p>';

$php_ok = version_compare(PHP_VERSION, '7.0.0', '>=');
$rows('PHP', array(
	'Version' => $h(PHP_VERSION).' '.$ok($php_ok, 'Supported', 'Too old: this application needs PHP 7.0 or newer (8.x recommended)'),
	'Server API' => $h(PHP_SAPI),
	'Web server' => $h($_SERVER['SERVER_SOFTWARE'] ?? 'unknown'),
	'Running as user' => $h(function_exists('posix_geteuid') && function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : get_current_user()),
	'Operating system' => $h(php_uname('s').' '.php_uname('r')),
	'php.ini in use' => $h(php_ini_loaded_file() ?: 'none'),
	'Document root' => $h($_SERVER['DOCUMENT_ROOT'] ?? ''),
	'This folder' => $h(__DIR__),
));

$ini = function ($k) { return ini_get($k) === '' ? '(empty)' : ini_get($k); };
$rows('Limits and errors', array(
	'display_errors' => $h($ini('display_errors')).' <small>(off is right for production)</small>',
	'error_log' => $h($ini('error_log') !== '(empty)' ? $ini('error_log') : 'not set: errors go to the web server / PHP-FPM log'),
	'log_errors' => $h($ini('log_errors')),
	'memory_limit' => $h($ini('memory_limit')),
	'max_execution_time' => $h($ini('max_execution_time')).' s',
	'upload_max_filesize / post_max_size' => $h($ini('upload_max_filesize')).' / '.$h($ini('post_max_size')),
	'session.save_path' => $h($ini('session.save_path')).' '.$ok(is_writable(session_save_path() ?: sys_get_temp_dir()), 'writable', 'not writable'),
	'date.timezone' => $h($ini('date.timezone')),
	'default_charset' => $h($ini('default_charset')),
));

$ext = array();
foreach (array('mysqli' => 'database', 'mbstring' => 'text handling', 'gd' => 'images and charts', 'curl' => 'eTIMS, SMS and email gateways', 'openssl' => 'secure connections',
	'json' => '', 'session' => '', 'zlib' => 'backups', 'iconv' => '', 'ctype' => '', 'fileinfo' => 'logo uploads', 'xml' => '') as $name => $why)
	$ext[$name.($why ? ' ('.$why.')' : '')] = $ok(extension_loaded($name), 'loaded', 'missing');
$rows('Extensions', $ext);

$dirs = array();
foreach (array('' => 'project root (config_db.php is created here)', 'company' => 'company', 'tmp' => 'tmp', 'lang' => 'lang', 'backups' => 'backups') as $d => $label) {
	$path = __DIR__.($d !== '' ? '/'.$d : '');
	$dirs[$label] = !file_exists($path) ? '<span style="color:#c81e1e">does not exist</span>' : $ok(is_writable($path), 'writable', 'not writable by the PHP user');
}
$rows('Folders the installer writes to', $dirs);

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || isset($_SERVER['HTTP_CF_VISITOR']);
$rows('Request', array(
	'Seen as HTTPS' => $ok($https, 'yes', 'no (login cookies are marked secure and need HTTPS)'),
	'Host' => $h($_SERVER['HTTP_HOST'] ?? ''),
	'Through Cloudflare' => isset($_SERVER['HTTP_CF_RAY']) ? 'yes' : 'no',
));
