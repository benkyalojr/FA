<?php
if (!isset($mpesa_hook_secret, $mpesa_hook_kind)) { http_response_code(404); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$root = dirname(__DIR__);
require $root.'/config_db.php';
$mpesa_company = isset($mpesa_company) ? (int)$mpesa_company : (int)$def_coy;
$c = $db_connections[$mpesa_company] ?? null;
if (!$c) { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli($c['host'], $c['dbuser'], $c['dbpassword'], $c['dbname'], !empty($c['port']) ? (int)$c['port'] : 3306);
if ($db->connect_errno) { http_response_code(503); exit; }
$db->set_charset('utf8mb4');
$p = $c['tbpref'];
$row = $db->query("SELECT hook_secret FROM {$p}mpesa_config WHERE id=1");
$cfg = $row ? $row->fetch_assoc() : null;
if (!$cfg || !$cfg['hook_secret'] || !hash_equals($cfg['hook_secret'], (string)$mpesa_hook_secret)
    || !in_array($mpesa_hook_kind, array('stk','c2b','c2bv','b2c','b2ctimeout','reversal'), true)) {
    http_response_code(404); exit;
}
$body = file_get_contents('php://input', false, null, 0, 1048577);
if (!$body || strlen($body) > 1048576 || !is_array(json_decode($body, true))) { http_response_code(400); exit; }
header('Content-Type: application/json');
$reply = json_encode(array('ResultCode'=>0, 'ResultDesc'=>'Accepted'));
if ($mpesa_hook_kind === 'c2bv') { echo $reply; exit; }
$addr = $_SERVER['REMOTE_ADDR'] ?? '';
$stmt = $db->prepare("INSERT INTO {$p}mpesa_inbox (kind, body, remote_addr, received_at) VALUES (?, ?, ?, NOW())");
if (!$stmt) { http_response_code(503); exit; }
$stmt->bind_param('sss', $mpesa_hook_kind, $body, $addr);
if (!$stmt->execute()) { http_response_code(503); exit; }
$inbox_id = (int)$db->insert_id;
$db->close();
unset($db, $c, $p, $row, $cfg, $stmt, $addr);
ignore_user_abort(true);
header('Content-Length: '.strlen($reply));
header('Connection: close');
echo $reply;
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); else { @ob_flush(); @flush(); }
require __DIR__.'/bootstrap.php';
if ($mpesa_fa_ready) {
    require_once $path_to_root.'/mpesa/includes/mpesa_inbox.inc';
    $r = db_fetch(db_query('SELECT * FROM '.TB_PREF.'mpesa_inbox WHERE id='.(int)$inbox_id));
    if ($r) mpesa_process_inbox_row($r);
}
exit;
