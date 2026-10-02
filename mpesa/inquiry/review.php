<?php
/**********************************************************************
    M-Pesa Needs Review - payments that could not be matched to a customer.
    Assigning one posts an (unallocated) Customer Payment into the M-Pesa bank
    account; the payment is then allocated to invoices on FA's allocation screen.
***********************************************************************/
$page_security = 'SA_MPESAVIEW';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/date_functions.inc");
include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/mpesa/includes/mpesa_setup.inc");

page(_($help_context = "M-Pesa Needs Review"));
if (!mpesa_require_setup()) return;
include_once($path_to_root . "/mpesa/includes/mpesa_post.inc");
include_once($path_to_root . "/sales/includes/db/customers_db.inc");

$e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$can_assign = $_SESSION['wa_current_user']->can_access_page('SA_MPESAREVIEW');

// ---- assign
if ($can_assign && isset($_POST['assign']) && check_csrf_token()) {
	$id = (int)$_POST['assign'];
	$debtor = (int)($_POST['customer'][$id] ?? 0);
	if ($debtor <= 0) display_error(_('Choose a customer for this payment first.'));
	else {
		list($ok, $msg) = mpesa_post_payment($id, $debtor, null, (int)$_SESSION['wa_current_user']->user);
		if ($ok) {
			$tx = mpesa_tx_get($id);
			display_notification($msg.' '.sprintf(_('Next, allocate it to the customer\'s invoices: %s'),
				'<a href="'.$e(ma_ui_href('sales/allocations/customer_allocate.php?trans_no='.(int)$tx['payment_no'].'&trans_type='.ST_CUSTPAYMENT.'&debtor_no='.$debtor)).'">'._('Allocate now').'</a>'));
		} else display_error($msg);
	}
}

$customers = array();
$res = db_query("SELECT debtor_no, name FROM ".TB_PREF."debtors_master WHERE !inactive ORDER BY name", 'customers');
while ($r = db_fetch($res)) $customers[(int)$r['debtor_no']] = $r['name'];
ensure_select2_assets();

start_form();
$list = db_query("SELECT * FROM ".TB_PREF."mpesa_transactions WHERE status='review' ORDER BY id DESC LIMIT 200", 'review list');
$rows = array();
while ($r = db_fetch($list)) $rows[] = $r;

if (!$rows) {
	echo '<div class="ma-empty"><strong>'.$e(_('Nothing to review')).'</strong><p>'.$e(_('Every M-Pesa payment so far was matched to a customer.')).'</p></div>';
} else {
	echo '<p class="ma-cfg-hint ma-gw-intro">'.$e(_('These payments reached your Till but could not be matched to a customer. Choose the customer and post: a customer payment is created in the M-Pesa bank account, then you allocate it to invoices.')).'</p>';
	echo '<section class="ma-panel"><div class="ma-table-wrap"><table class="ma-sales-table"><thead><tr><th>'.$e(_('Date')).'</th><th>'.$e(_('Receipt')).'</th><th>'.$e(_('Phone')).'</th><th>'.$e(_('Paid by')).'</th><th class="num">'
		.$e(_('Amount')).'</th><th>'.$e(_('Why')).'</th><th>'.$e(_('Customer')).'</th><th></th></tr></thead><tbody>';
	foreach ($rows as $r) {
		$when = $r['trans_time'] ?: $r['created_at'];
		// customers whose name shares a word with the payer's name come first
		$sug = array();
		foreach (preg_split('/\s+/', trim((string)$r['payer_name'])) as $w)
			if (strlen($w) >= 3) foreach ($customers as $cid => $cn) if (stripos($cn, $w) !== false) $sug[$cid] = $cn;
		$opts = '<option value="">'.$e(_('Choose a customer...')).'</option>';
		if ($sug) { $opts .= '<optgroup label="'.$e(_('Suggested')).'">'; foreach ($sug as $cid => $cn) $opts .= '<option value="'.$cid.'">'.$e($cn).'</option>'; $opts .= '</optgroup>'; }
		$opts .= '<optgroup label="'.$e(_('All customers')).'">'; foreach ($customers as $cid => $cn) $opts .= '<option value="'.$cid.'">'.$e($cn).'</option>'; $opts .= '</optgroup>';
		echo '<tr><td>'.$e(sql2date(substr($when, 0, 10)).' '.substr($when, 11, 5)).'</td><td>'.$e($r['receipt'] ?: '-').'</td><td>'.$e($r['phone'] ?: '-').'</td><td>'.$e($r['payer_name'] ?: '-').'</td>'
			.'<td class="num">'.$e(number_format($r['amount'], 2)).'</td><td>'.$e($r['note'] ?: '-').'</td><td style="min-width:240px">'
			.($can_assign ? '<select name="customer['.(int)$r['id'].']" class="fa-select2" data-select2-local="1" style="width:100%">'.$opts.'</select>' : '-').'</td><td>'
			.($can_assign ? '<button class="ma-btn ma-btn-primary" type="submit" name="assign" value="'.(int)$r['id'].'">'.$e(_('Post payment')).'</button>' : '').'</td></tr>';
	}
	echo '</tbody></table></div></section>';
}
end_form();
end_page();
