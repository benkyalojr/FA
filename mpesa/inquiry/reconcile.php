<?php
/**********************************************************************
    M-Pesa Reconciliation - compare the M-Pesa statement (CSV from the business
    portal) with what was recorded here: money Safaricom took that we never heard about,
    payments recorded that are not on the statement, amounts that differ, and fees.
***********************************************************************/
$page_security = 'SA_MPESAVIEW';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/date_functions.inc");
include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/mpesa/includes/mpesa_setup.inc");

page(_($help_context = "M-Pesa Reconciliation"));
if (!mpesa_require_setup()) return;
include_once($path_to_root . "/mpesa/includes/db/mpesa_tx_db.inc");
include_once($path_to_root . "/mpesa/includes/mpesa_reconcile.inc");
include_once($path_to_root . "/mpesa/includes/mpesa_statement.inc");
require_once $path_to_root . '/ui/dashboard.inc';

$e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$money = function ($v) { return number_format((float)$v, 2); };
$can = $_SESSION['wa_current_user']->can_access_page('SA_MPESAREVIEW');

if ($can && isset($_POST['import']) && check_csrf_token()) {
	if (!isset($_FILES['statement']) || $_FILES['statement']['error'] !== UPLOAD_ERR_OK) display_error(_('Choose the statement CSV file first.'));
	else {
		$fh = fopen($_FILES['statement']['tmp_name'], 'r');
		try {
			list($n, $dupes) = mpesa_statement_import(mpesa_statement_parse($fh));
			display_notification(sprintf(_('%d new statement line(s) imported, %d already known.'), $n, $dupes));
		} catch (Throwable $ex) { display_error(html_specials_encode($ex->getMessage())); }
		fclose($fh);
	}
}
if ($can && isset($_POST['adopt']) && check_csrf_token()) {
	if (mpesa_statement_adopt((int)$_POST['adopt'])) display_notification(_('Added to Needs Review: assign it to a customer there.'));
	else display_error(_('That line could not be added.'));
}

$rec = mpesa_reconcile();
$t = TB_PREF.'mpesa_transactions';
ma_fin_tiles(array(
	array(_('Statement lines'), $rec ? $rec['lines'] : 0, $rec ? sprintf(_('%s to %s'), sql2date($rec['from']), sql2date($rec['to'])) : _('Import a statement to begin'), 'file', 'blue'),
	array(_('Not on our books'), $rec ? count($rec['missing']) : 0, _('Paid to the Till, never reported to us'), 'alert', 'red'),
	array(_('Not on the statement'), $rec ? count($rec['extra']) : 0, _('Recorded here, missing from the file'), 'search', 'orange'),
	array(_('Fees'), $rec ? $money($rec['fee_posted']) : '0.00', $rec && $rec['charge'] > 0 ? sprintf(_('Statement charges %s'), $money($rec['charge'])) : _('Posted as bank charges'), 'bank', 'purple'),
));

start_form(true);
echo '<section class="ma-panel ma-cfg-card"><div class="ma-panel-head"><h2>'.$e(_('Import the M-Pesa statement')).'</h2></div><div class="ma-cfg-body">'
	.'<p class="ma-cfg-hint">'.$e(_('Download the statement as CSV from the M-Pesa business portal (Receipt No., Completion Time, Details, Paid In, Withdrawn) and upload it here. Charges are read from a Charge column when the file has one. Importing the same lines twice is safe.')).'</p>'
	.'<div class="ma-cfg-actions"><input type="file" name="statement" accept=".csv,text/csv">'
	.($can ? '<button class="ma-btn ma-btn-primary" type="submit" name="import" value="1">'.$e(_('Import statement')).'</button>' : '').'</div></div></section>';

if ($rec) {
	$block = function ($title, $hint, $head, $rows) use ($e) {
		echo '<section class="ma-panel" style="margin-top:16px"><div class="ma-panel-head"><h2>'.$e($title).'</h2></div>';
		if (!$rows) { echo '<div class="ma-empty">'.$e(_('Nothing to report.')).'</div></section>'; return; }
		echo '<p class="ma-cfg-hint" style="padding:0 18px">'.$e($hint).'</p><div class="ma-table-wrap"><table class="ma-sales-table"><thead><tr>';
		foreach ($head as $h) echo '<th>'.$e($h).'</th>';
		echo '</tr></thead><tbody>'.implode('', $rows).'</tbody></table></div></section>';
	};
	$rows = array();
	foreach ($rec['missing'] as $l)
		$rows[] = '<tr><td>'.$e(sql2date(substr($l['trans_time'], 0, 10)).' '.substr($l['trans_time'], 11, 5)).'</td><td>'.$e($l['receipt']).'</td><td>'.$e($l['details']).'</td><td class="num">'.$e($money($l['paid_in'])).'</td><td>'
			.($l['tx_status'] ? '<small>'.$e($l['tx_status'] === 'review' ? _('Waiting in Needs Review') : sprintf(_('Recorded, status: %s'), $l['tx_status'])).'</small>'
				: ($can ? '<button class="ma-btn ma-btn-secondary" type="submit" name="adopt" value="'.(int)$l['id'].'">'.$e(_('Add to Needs Review')).'</button>' : '')).'</td></tr>';
	$block(_('On the statement, not on our books'), _('Safaricom took these payments but never told this system. Add them to Needs Review and assign them to a customer.'), array(_('Time'), _('Receipt'), _('Details'), _('Amount'), ''), $rows);
	$rows = array();
	foreach ($rec['extra'] as $x)
		$rows[] = '<tr><td>'.$e(sql2date(substr($x['trans_time'], 0, 10)).' '.substr($x['trans_time'], 11, 5)).'</td><td>'.$e($x['receipt']).'</td><td>'.$e($x['phone']).'</td><td class="num">'.$e($money($x['amount'])).'</td><td>'.$e($x['status']).'</td></tr>';
	$block(_('Recorded here, not on the statement'), _('Check the statement covers these dates. If it does, these payments need investigating.'), array(_('Time'), _('Receipt'), _('Phone'), _('Amount'), _('Status')), $rows);
	$rows = array();
	foreach ($rec['differ'] as $x)
		$rows[] = '<tr><td>'.$e($x['receipt']).'</td><td class="num">'.$e($money($x['amount'])).'</td><td class="num">'.$e($money($x['paid_in'])).'</td><td class="num">'.$e($money($x['amount'] - $x['paid_in'])).'</td></tr>';
	$block(_('Different amounts'), _('The amount recorded differs from the statement.'), array(_('Receipt'), _('Recorded'), _('Statement'), _('Difference')), $rows);
	if ($rec['charge'] > 0 && abs($rec['charge'] - $rec['fee_posted']) > 0.005)
		echo '<div class="ma-cfg-result bad" style="margin-top:16px">'.$e(sprintf(_('The statement shows charges of %s but %s was posted as fees (difference %s). Enter a Bank Payment to the bank-charge account for the difference, or correct the fee percentage in M-Pesa Configuration.'),
			$money($rec['charge']), $money($rec['fee_posted']), $money($rec['charge'] - $rec['fee_posted']))).' <a href="'.$e(ma_ui_href('gl/gl_bank.php?NewPayment=Yes')).'">'.$e(_('New Payment')).'</a></div>';
}
end_form();
end_page();
