<?php
/**********************************************************************
    M-Pesa Transactions - every STK Push request and Till receipt, with
    the customer, the fee and the FrontAccounting payment it became.
***********************************************************************/
$page_security = 'SA_MPESAVIEW';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/date_functions.inc");
include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/mpesa/includes/mpesa_setup.inc");

$js = '';
if (user_use_date_picker()) $js .= get_js_date_picker();
page(_($help_context = "M-Pesa Transactions"), false, false, '', $js);
if (!mpesa_require_setup()) return;
include_once($path_to_root . "/mpesa/includes/db/mpesa_tx_db.inc");
require_once $path_to_root . '/ui/dashboard.inc';

$e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$money = function ($v) { return number_format((float)$v, 2); };
if (get_post('show_from') == '') $_POST['show_from'] = begin_month(Today());
if (get_post('show_to') == '') $_POST['show_to'] = Today();

// ---- stat cards
$today = date('Y-m-d'); $month = date('Y-m-01');
$one = function ($sql) { return ma_dashboard_scalar($sql); };
$t = TB_PREF.'mpesa_transactions';
ma_fin_tiles(array(
	array(_('Received today'), $money($one("SELECT COALESCE(SUM(amount),0) FROM $t WHERE status='posted' AND DATE(trans_time)='$today'")), _('Posted M-Pesa payments'), 'wallet', 'green'),
	array(_('Received this month'), $money($one("SELECT COALESCE(SUM(amount),0) FROM $t WHERE status='posted' AND DATE(trans_time)>='$month'")), sprintf(_('Fees %s'), $money($one("SELECT COALESCE(SUM(fee),0) FROM $t WHERE status='posted' AND DATE(trans_time)>='$month'"))), 'chart', 'blue'),
	array(_('Waiting for the customer'), (int)$one("SELECT COUNT(*) FROM $t WHERE kind='stk' AND status='pending'"), _('STK requests not yet answered'), 'clock', 'orange'),
	array(_('Needs review'), (int)$one("SELECT COUNT(*) FROM $t WHERE status='review'"), _('Payments to assign to a customer'), 'alert', 'purple'),
));

// ---- filters
$statuses = array('pending' => _('Waiting'), 'received' => _('Received'), 'review' => _('Needs review'), 'posted' => _('Posted'), 'failed' => _('Failed'), 'cancelled' => _('Cancelled'));
start_form();
ma_sales_filter_start();
ma_sales_field(_('Date'), function () { date_cells(null, 'show_from'); date_cells(null, 'show_to'); });
ma_sales_field(_('Type'), function () { echo array_selector('show_kind', get_post('show_kind'), array('' => _('All'), 'stk' => _('STK Push'), 'c2b' => _('Till receipt'))); });
ma_sales_field(_('Status'), function () use ($statuses) { echo array_selector('show_status', get_post('show_status'), array_merge(array('' => _('All')), $statuses)); });
ma_sales_field(_('Search'), function () { text_cells(null, 'show_search', get_post('show_search'), 24, 60); });
ma_sales_filter_end('RefreshInquiry', _('Refresh'), false, _('Show'));

$page_size = 50;
$page_no = max(0, (int)get_post('page_no', 0));
if (isset($_POST['RefreshInquiry'])) $page_no = 0;
elseif (isset($_POST['next_page'])) $page_no++;
elseif (isset($_POST['previous_page'])) $page_no = max(0, $page_no - 1);
$res = mpesa_tx_list(array('status' => get_post('show_status'), 'kind' => get_post('show_kind'), 'search' => trim(get_post('show_search')),
	'from' => is_date(get_post('show_from')) ? date2sql(get_post('show_from')) : null, 'to' => is_date(get_post('show_to')) ? date2sql(get_post('show_to')) : null),
	$page_size, $page_no * $page_size, $total);

$tone = array('pending' => 'pending', 'received' => 'open', 'review' => 'late', 'posted' => 'paid', 'failed' => 'late', 'cancelled' => 'pending');
echo '<section class="ma-panel"><div class="ma-table-wrap"><table class="ma-sales-table"><thead><tr><th>'.$e(_('Date')).'</th><th>'.$e(_('Receipt')).'</th><th>'.$e(_('Type')).'</th><th>'
	.$e(_('Phone')).'</th><th>'.$e(_('Customer')).'</th><th class="num">'.$e(_('Amount')).'</th><th class="num">'.$e(_('Fee')).'</th><th>'.$e(_('Status')).'</th><th>'.$e(_('Payment')).'</th></tr></thead><tbody>';
$n = 0;
while ($r = db_fetch($res)) {
	$n++;
	$when = $r['trans_time'] ?: $r['created_at'];
	$payment = $r['payment_no'] > 0 ? get_trans_view_str(ST_CUSTPAYMENT, $r['payment_no']).($r['allocated'] > 0 ? ' <small>('.$e(sprintf(_('%s allocated'), $money($r['allocated']))).')</small>' : ' <small>'.$e(_('not allocated')).'</small>') : '';
	if ($r['status'] === 'review')
		$payment = '<a class="ma-sales-link" href="'.$e(ma_ui_href('mpesa/inquiry/review.php')).'">'.$e(_('Assign')).' &rsaquo;</a>';
	echo '<tr><td>'.$e(sql2date(substr($when, 0, 10)).' '.substr($when, 11, 5)).'</td><td>'.$e($r['receipt'] ?: '-').'</td><td>'.$e($r['kind'] === 'stk' ? _('STK Push') : _('Till')).'</td>'
		.'<td>'.$e($r['phone'] ?: '-').'</td><td>'.$e($r['customer'] ?: ($r['payer_name'] ?: '-')).'</td><td class="num">'.$e($money($r['amount'])).'</td><td class="num">'.$e($r['fee'] > 0 ? $money($r['fee']) : '-').'</td>'
		.'<td><span class="ma-pill '.$tone[$r['status']].'"'.($r['result_desc'] || $r['note'] ? ' title="'.$e($r['result_desc'] ?: $r['note']).'"' : '').'>'.$e($statuses[$r['status']] ?? $r['status']).'</span></td><td>'.$payment.'</td></tr>';
}
if (!$n) echo '<tr><td colspan="9" class="ma-empty">'.$e(_('No M-Pesa transactions match these filters.')).'</td></tr>';
echo '</tbody></table></div></section>';
echo '<nav class="ma-live-pager"><span>'.$e(sprintf(_('Showing %d-%d of %d'), $total ? $page_no * $page_size + 1 : 0, min(($page_no + 1) * $page_size, $total), $total)).'</span><span class="ma-live-pager-links">'
	.'<input type="hidden" name="page_no" value="'.(int)$page_no.'">';
if ($page_no > 0) echo '<button class="ma-btn ma-btn-secondary" type="submit" name="previous_page" value="1">'.$e(_('Previous')).'</button>';
if (($page_no + 1) * $page_size < $total) echo '<button class="ma-btn ma-btn-secondary" type="submit" name="next_page" value="1">'.$e(_('Next')).'</button>';
echo '</span></nav>';
end_form();
end_page();
