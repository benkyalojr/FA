<?php
// Queue or retry KRA eTIMS stamping of a sales invoice or credit note (JSON, POST only, from the document modal).
$page_security = 'SA_ETIMSVIEW';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/date_functions.inc");
include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/sales/includes/sales_db.inc");
include_once($path_to_root . "/etims/includes/etims_setup.inc");

header('Content-Type: application/json; charset=UTF-8');
function restamp_reply($data, $code = 200) { http_response_code($code); echo json_encode($data); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
	|| !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['_token'] ?? '')))
	restamp_reply(array('error' => _('Request from outside of this page is forbidden.')), 403);

$type = (int)($_POST['trans_type'] ?? 0);
$no = (int)($_POST['trans_no'] ?? 0);
if (($type !== ST_SALESINVOICE && $type !== ST_CUSTCREDIT) || $no <= 0 || !get_customer_trans($no, $type))
	restamp_reply(array('error' => _('Only existing sales invoices and credit notes can be stamped.')), 404);
if (get_voided_entry($type, $no))
	restamp_reply(array('error' => _('A voided document cannot be stamped.')), 422);
if (!etims_schema_ready())
	restamp_reply(array('error' => etims_setup_message()), 503);

include_once($path_to_root . "/etims/includes/db/etims_submit_db.inc");
if (!etims_stamping_active())
	restamp_reply(array('error' => _('eTIMS stamping is switched off or not configured. Turn it on under eTIMS Configuration.')), 422);

$sub = db_fetch(db_query("SELECT s.status, s.bg_task_id, t.status AS task_status FROM ".TB_PREF."etims_submissions s
	LEFT JOIN ".TB_PREF."bg_tasks t ON t.id=s.bg_task_id
	WHERE s.trans_type=$type AND s.trans_no=$no", 'eTIMS submission'));
if ($sub && $sub['status'] === 'stamped')
	restamp_reply(array('status' => 'stamped', 'message' => _('Already stamped.')));
if ($sub && $sub['task_status'] === 'pending')
	restamp_reply(array('status' => 'queued', 'message' => _('Already queued: it will be retried automatically.')));

try {
	etims_stamp_now_or_queue($type, $no);   // tries once now, otherwise a fresh queued task with 5 attempts
} catch (Throwable $e) {
	restamp_reply(array('error' => $e->getMessage()), 500);
}
$sub = db_fetch(db_query("SELECT status FROM ".TB_PREF."etims_submissions WHERE trans_type=$type AND trans_no=$no", 'eTIMS submission'));
restamp_reply($sub && $sub['status'] === 'stamped'
	? array('status' => 'stamped', 'message' => _('Stamped with KRA.'))
	: array('status' => 'queued', 'message' => _('Not stamped yet. It is queued and will be retried automatically.')));
