<?php
// Create or revoke the public share link of a sales invoice (JSON, POST only).
$page_security = 'SA_SALESTRANSVIEW';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/date_functions.inc");
include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/sales/includes/sales_db.inc");

header('Content-Type: application/json; charset=UTF-8');
function share_reply($data, $code = 200) { http_response_code($code); echo json_encode($data); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
	|| !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['_token'] ?? '')))
	share_reply(array('error' => _('Request from outside of this page is forbidden.')), 403);

$type = (int)($_POST['trans_type'] ?? 0);
$no = (int)($_POST['trans_no'] ?? 0);
if ($type !== ST_SALESINVOICE || $no <= 0 || !get_customer_trans($no, $type))
	share_reply(array('error' => _('Only existing sales invoices can be shared.')), 404);

$t = TB_PREF.'share_links';
$prefix = $db_connections[$_SESSION['wa_current_user']->cur_con]['tbpref'];
if (!db_fetch(db_query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ".db_escape($prefix.'share_links'), 'share table')))
	share_reply(array('error' => _('Sharing is not set up yet. Run the database migrations (share_links) first.')), 503);

$base = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST']
	.rtrim(dirname(dirname(dirname($_SERVER['SCRIPT_NAME']))), '/\\');

$row = db_fetch(db_query("SELECT * FROM $t WHERE doc_type=$type AND trans_no=$no AND revoked=0
	AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY id DESC LIMIT 1", 'share lookup'));

if (($_POST['action'] ?? '') === 'revoke') {
	db_query("UPDATE $t SET revoked=1 WHERE doc_type=$type AND trans_no=$no", 'share revoke');
	share_reply(array('url' => null));
}
if (!$row) {
	$token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
	db_query("INSERT INTO $t (token, doc_type, trans_no, created_by, created_at) VALUES ("
		.db_escape($token).", $type, $no, ".(int)$_SESSION['wa_current_user']->user.", NOW())", 'share create');
} else
	$token = $row['token'];
share_reply(array('url' => $base.'/share/invoice/'.$token, 'views' => $row ? (int)$row['views'] : 0));
