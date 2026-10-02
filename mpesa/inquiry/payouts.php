<?php
/**********************************************************************
    M-Pesa Payouts - paying suppliers from M-Pesa (B2C). Request, a second person
    approves, then it is sent; the Supplier Payment is posted when Safaricom confirms.
    Off until the B2C details are saved in M-Pesa Configuration.
***********************************************************************/
$page_security = 'SA_MPESAVIEW';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/date_functions.inc");
include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/mpesa/includes/mpesa_setup.inc");

page(_($help_context = "M-Pesa Payouts"));
if (!mpesa_require_setup()) return;
include_once($path_to_root . "/mpesa/includes/mpesa_payout.inc");
include_once($path_to_root . "/includes/db/crm_contacts_db.inc");
include_once($path_to_root . "/purchasing/includes/db/suppliers_db.inc");

$e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$money = function ($v) { return number_format((float)$v, 2); };
$user = $_SESSION['wa_current_user'];
$cfg = mpesa_get_config();
$ready = mpesa_b2c_ready($cfg);
$can_request = $user->can_access_page('SA_MPESAPAYOUT');
$can_approve = $user->can_access_page('SA_MPESAAPPROVE');
$say = function ($r) { $r[0] ? display_notification($r[1]) : display_error($r[1]); };

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['request_payout']) || isset($_POST['approve']) || isset($_POST['reject']) || isset($_POST['reverse'])) && check_csrf_token()) {
	if ($can_request && isset($_POST['request_payout']))
		$say(mpesa_payout_request((int)$_POST['supplier_id'], $_POST['phone'] ?? '', $_POST['amount'] ?? 0, $_POST['reason'] ?? '', (int)$user->user));
	if ($can_approve && isset($_POST['approve'])) $say(mpesa_payout_approve((int)$_POST['approve'], (int)$user->user));
	if ($can_approve && isset($_POST['reject'])) $say(mpesa_payout_reject((int)$_POST['reject'], (int)$user->user));
	if ($can_approve && isset($_POST['reverse'])) $say(mpesa_payout_reverse((int)$_POST['reverse']));
}

if (!$ready)
	echo '<div class="ma-empty"><strong>'.$e(_('Payouts are switched off')).'</strong><p>'.$e(_('Paying suppliers from M-Pesa needs a B2C shortcode and initiator account, which a Till does not have. When you have them, add them in M-Pesa Configuration and turn payouts on.')).'</p>'
		.'<p><a class="ma-sales-new" href="'.$e(ma_ui_href('mpesa/manage/mpesa_config.php')).'">'.$e(_('Open M-Pesa Configuration')).'</a></p></div>';

start_form();
if ($ready && $can_request) {
	$sup = array();
	$r = db_query("SELECT supplier_id, supp_name FROM ".TB_PREF."suppliers WHERE !inactive ORDER BY supp_name", 'suppliers');
	while ($s = db_fetch($r)) {
		$phone = '';
		foreach (get_supplier_contacts((int)$s['supplier_id']) as $c) if (trim((string)$c['phone']) !== '') { $phone = trim($c['phone']); break; }
		$sup[] = array((int)$s['supplier_id'], $s['supp_name'], $phone);
	}
	ensure_select2_assets();
	echo '<section class="ma-panel ma-cfg-card"><div class="ma-panel-head"><h2>'.$e(_('New payout')).'</h2><span class="ma-gw-badges"><span class="ma-pill open">'
		.$e(sprintf(_('Today %s of %s'), $money(mpesa_payout_today_total()), $money($cfg['payout_daily_limit']))).'</span></span></div><div class="ma-cfg-body">'
		.'<div class="ma-gw-grid"><label>'.$e(_('Supplier')).'<select name="supplier_id" id="po-supplier" class="fa-select2" data-select2-local="1"><option value="">'.$e(_('Choose a supplier...')).'</option>';
	foreach ($sup as $s) echo '<option value="'.$s[0].'" data-phone="'.$e($s[2]).'">'.$e($s[1]).'</option>';
	echo '</select></label><label>'.$e(_('Phone number')).'<input type="text" name="phone" id="po-phone" placeholder="0712345678"></label>'
		.'<label>'.$e(_('Amount (KES)')).'<input type="text" name="amount" inputmode="decimal"></label><label>'.$e(_('Reason')).'<input type="text" name="reason" maxlength="200"></label></div>'
		.'<div class="ma-cfg-actions"><button class="ma-btn ma-btn-primary" type="submit" name="request_payout" value="1">'.$e(_('Request payout')).'</button>'
		.'<span class="ma-cfg-hint">'.$e(_('A second person approves it before any money moves.')).'</span></div></div></section>'
		.'<script>(function(){var s=document.getElementById("po-supplier"),p=document.getElementById("po-phone");function f(){var o=s.options[s.selectedIndex];if(o)p.value=o.getAttribute("data-phone")||"";}'
		.'if(window.jQuery)jQuery(s).on("change",f);s.addEventListener("change",f);})();</script>';
}

$statuses = array('requested' => array(_('Waiting for approval'), 'pending'), 'approved' => array(_('Approved'), 'open'), 'sent' => array(_('Sent'), 'open'), 'success' => array(_('Paid'), 'paid'),
	'failed' => array(_('Failed'), 'late'), 'rejected' => array(_('Rejected'), 'pending'), 'reversed' => array(_('Reversed'), 'pending'));
$res = db_query("SELECT p.*, s.supp_name, ur.real_name AS requester, ua.real_name AS approver FROM ".TB_PREF."mpesa_payouts p LEFT JOIN ".TB_PREF."suppliers s ON s.supplier_id=p.supplier_id
	LEFT JOIN ".TB_PREF."users ur ON ur.id=p.requested_by LEFT JOIN ".TB_PREF."users ua ON ua.id=p.approved_by ORDER BY p.id DESC LIMIT 100", 'payouts');
echo '<section class="ma-panel"><div class="ma-table-wrap"><table class="ma-sales-table"><thead><tr><th>'.$e(_('Date')).'</th><th>'.$e(_('Supplier')).'</th><th>'.$e(_('Phone')).'</th><th class="num">'.$e(_('Amount')).'</th><th>'
	.$e(_('Reason')).'</th><th>'.$e(_('Requested by')).'</th><th>'.$e(_('Status')).'</th><th>'.$e(_('Receipt')).'</th><th></th></tr></thead><tbody>';
$n = 0;
while ($p = db_fetch($res)) {
	$n++;
	$st = $statuses[$p['status']];
	$act = '';
	if ($can_approve && $p['status'] === 'requested') {
		if ((int)$p['requested_by'] === (int)$user->user) $act = '<small>'.$e(_('Another person must approve')).'</small>';
		else $act = '<button class="ma-btn ma-btn-primary" type="submit" name="approve" value="'.(int)$p['id'].'" onclick="return confirm(\''.$e(addslashes(sprintf(_('Approve and send %s to %s?'), $money($p['amount']), $p['supp_name']))).'\')">'.$e(_('Approve and send')).'</button> '
			.'<button class="ma-btn ma-btn-secondary" type="submit" name="reject" value="'.(int)$p['id'].'">'.$e(_('Reject')).'</button>';
	} elseif ($can_approve && $p['status'] === 'success')
		$act = '<button class="ma-btn ma-btn-secondary" type="submit" name="reverse" value="'.(int)$p['id'].'" onclick="return confirm(\''.$e(addslashes(_('Ask Safaricom to reverse this payout?'))).'\')">'.$e(_('Reverse')).'</button>'
			.($p['payment_no'] > 0 ? ' '.get_trans_view_str(ST_SUPPAYMENT, $p['payment_no']) : '');
	echo '<tr><td>'.$e(sql2date(substr($p['created_at'], 0, 10))).'</td><td>'.$e($p['supp_name']).'</td><td>'.$e($p['phone']).'</td><td class="num">'.$e($money($p['amount'])).'</td><td>'.$e($p['reason']).'</td>'
		.'<td>'.$e($p['requester']).'</td><td><span class="ma-pill '.$st[1].'"'.($p['result_desc'] ? ' title="'.$e($p['result_desc']).'"' : '').'>'.$e($st[0]).'</span></td><td>'.$e($p['receipt'] ?: '-').'</td><td>'.$act.'</td></tr>';
}
if (!$n) echo '<tr><td colspan="9" class="ma-empty">'.$e(_('No payouts yet.')).'</td></tr>';
echo '</tbody></table></div></section>';
end_form();
end_page();
