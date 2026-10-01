<?php
/**********************************************************************
    Copyright (C) FrontAccounting, LLC.
	Released under the terms of the GNU General Public License, GPL,
	as published by the Free Software Foundation, either version 3
	of the License, or (at your option) any later version.
    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
    See the License here <http://www.gnu.org/licenses/gpl-3.0.html>.
***********************************************************************/
// Tax periods of a financial year: tax collected on sales, paid on purchases,
// and the return for each period (prepare, file, record the payment).
$page_security = 'SA_TAXREP';
$path_to_root = "..";
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/date_functions.inc");
include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/gl/includes/gl_db.inc");
include_once($path_to_root . "/admin/db/fiscalyears_db.inc");

page(_($help_context = "Tax Periods"));

$today = date2sql(Today());
$has_table = ma_taxes_returns_table_exists();
$cur = get_company_pref('curr_default');
$h = function ($v) { return ma_ui_escape($v); };
$money = function ($v) { return number_format((float)$v, user_price_dec()); };

// ---- financial year
$years = array();
$res = get_all_fiscalyears();
while ($y = db_fetch($res)) $years[$y['id']] = $y;
krsort($years);
$current = get_current_fiscalyear();
$fy_id = (int)($_GET['fy'] ?? $_POST['fy'] ?? ($current['id'] ?? key($years)));
if (!isset($years[$fy_id])) $fy_id = (int)($current['id'] ?? key($years));
$fy = $years[$fy_id] ?? null;
if (!$fy) { display_error(_("No financial year is defined.")); end_page(); exit; }
$self = $_SERVER['PHP_SELF'];
$fy_query = '?fy='.$fy_id;

// ---- returns already filed, keyed by period start
$filed = array();
if ($has_table) {
	$r = db_query("SELECT * FROM ".TB_PREF."tax_returns WHERE period_start >= ".db_escape($fy['begin'])." AND period_start <= ".db_escape($fy['end']), 'filed returns');
	while ($row = db_fetch($r)) $filed[$row['period_start'].'|'.$row['period_end']] = $row;
}

$periods = ma_taxes_periods($fy, $today);
$by_key = array();
foreach ($periods as $p) $by_key[$p[0].'|'.$p[1]] = $p;

// ---- save / withdraw a return
$modal_key = null;
if (($_POST['tax_action'] ?? '') !== '' && check_csrf_token()) {
	$key = ($_POST['period_start'] ?? '').'|'.($_POST['period_end'] ?? '');
	$modal_key = $key;
	if (!$has_table)
		display_error(_("Filing returns is not set up yet. Run the database migrations (tax_returns) first."));
	elseif (!isset($by_key[$key]))
		display_error(_("Unknown tax period."));
	elseif ($_POST['tax_action'] === 'withdraw') {
		db_query("DELETE FROM ".TB_PREF."tax_returns WHERE period_start=".db_escape($by_key[$key][0])." AND period_end=".db_escape($by_key[$key][1]), 'withdraw return');
		unset($filed[$key]);
		display_notification(_("The filing was withdrawn. The period is open again."));
		$modal_key = null;
	} else {
		list($ps, $pe) = $by_key[$key];
		$fdate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['filed_date'] ?? '') ? $_POST['filed_date'] : '';
		$pay = (float)str_replace(',', '', $_POST['payments'] ?? '0');
		if ($pe > $today)
			display_error(_("This period has not ended yet. File the return once it has."));
		elseif ($fdate === '')
			display_error(_("Enter the date the return was filed."));
		elseif ($pay < 0)
			display_error(_("The payment cannot be negative."));
		else {
			$ref = substr(trim($_POST['reference'] ?? ''), 0, 60);
			$memo = substr(trim($_POST['memo'] ?? ''), 0, 255);
			if (isset($filed[$key])) {
				db_query("UPDATE ".TB_PREF."tax_returns SET filed_date=".db_escape($fdate).", payments=".db_escape($pay).", reference=".db_escape($ref).", memo=".db_escape($memo)
					.", updated_at=NOW() WHERE id=".(int)$filed[$key]['id'], 'update return');
				display_notification(_("The return was updated."));
			} else {
				$f = ma_taxes_figures($ps, $pe);   // figures are frozen at filing
				db_query("INSERT INTO ".TB_PREF."tax_returns (period_start, period_end, filed_date, output_tax, input_tax, amount_due, payments, reference, memo, filed_by, created_at, updated_at)
					VALUES (".db_escape($ps).", ".db_escape($pe).", ".db_escape($fdate).", ".db_escape($f['output']).", ".db_escape($f['input']).", ".db_escape($f['due']).", "
					.db_escape($pay).", ".db_escape($ref).", ".db_escape($memo).", ".(int)$_SESSION['wa_current_user']->user.", NOW(), NOW())", 'file return');
				display_notification(_("The return was filed."));
			}
			$r = db_fetch(db_query("SELECT * FROM ".TB_PREF."tax_returns WHERE period_start=".db_escape($ps)." AND period_end=".db_escape($pe), 'return'));
			$filed[$key] = $r;
			$modal_key = null;
		}
	}
}
if ($modal_key === null && isset($_GET['period'], $_GET['end'])) $modal_key = $_GET['period'].'|'.$_GET['end'];

// ---- figures per period
$rows = array(); $tot_out = $tot_in = 0; $n_filed = 0;
foreach ($periods as $p) {
	$key = $p[0].'|'.$p[1];
	$ret = $filed[$key] ?? null;
	if ($ret) { $f = array('output' => (float)$ret['output_tax'], 'input' => (float)$ret['input_tax'], 'due' => (float)$ret['amount_due']); $n_filed++; }
	else $f = ma_taxes_figures($p[0], $p[1]);
	$tot_out += $f['output']; $tot_in += $f['input'];
	$rows[] = array($p, $key, $ret, $f);
}
$net = $tot_out - $tot_in;

// ---- toolbar year selector and stat cards
echo '<form method="get" class="ma-tax-year" action="'.$h($self).'"><label>'.$h(_('Financial Year')).' <select name="fy" onchange="this.form.submit()">';
foreach ($years as $id => $y)
	echo '<option value="'.(int)$id.'"'.($id == $fy_id ? ' selected' : '').'>'.$h(sql2date($y['begin']).' - '.sql2date($y['end']).(!$y['closed'] ? '   '._('Active') : '')).'</option>';
echo '</select></label></form>';

ma_fin_tiles(array(
	array(_('Collected on sales'), $money($tot_out), _('Output tax for the year'), 'receipt', 'blue'),
	array(_('Paid on purchases'), $money($tot_in), _('Input tax for the year'), 'cart', 'purple'),
	array($net < 0 ? _('Net refundable') : _('Net payable'), $money(abs($net)), sql2date($fy['begin']).' - '.sql2date($fy['end']), 'swap', $net < 0 ? 'green' : 'orange'),
	array(_('Periods filed'), $n_filed.' / '.count($periods), _('Tax periods started this year'), 'calendar', 'teal'),
));
if (!$has_table)
	echo '<div class="ma-empty">'.$h(_('Filing returns is not set up yet. Run the database migrations (tax_returns) first. Figures below are live.')).'</div>';

// ---- table
echo '<section class="ma-panel"><div class="ma-table-wrap"><table class="ma-sales-table"><thead><tr><th>'.$h(_('Start Date')).'</th><th>'.$h(_('End Date')).'</th><th>'
	.$h(_('File Date')).'</th><th class="num">'.$h(_('Amount Due')).'</th><th class="num">'.$h(_('Payments')).'</th><th class="num">'.$h(_('Balance')).'</th><th>'
	.$h(_('Status')).'</th><th>'.$h(_('Action')).'</th></tr></thead><tbody>';
if (!$rows) echo '<tr><td colspan="8" class="ma-empty">'.$h(_('This financial year has not started yet.')).'</td></tr>';
foreach ($rows as $row) {
	list($p, $key, $ret, $f) = $row;
	$pay = $ret ? (float)$ret['payments'] : 0;
	$bal = $f['due'] - $pay;
	$class = function ($v) { return $v < -0.004 ? ' ma-fin-pos' : ''; };
	$status = !$ret ? array(_('Not Filed'), 'pending') : (abs($bal) < 0.005 ? array(_('Settled'), 'paid') : array(_('Filed'), 'open'));
	echo '<tr><td>'.$h(sql2date($p[0])).'</td><td>'.$h(sql2date($p[1])).'</td><td>'.($ret ? $h(sql2date($ret['filed_date'])) : '<span class="muted">-</span>').'</td>'
		.'<td class="num'.$class($f['due']).'">'.$h($money($f['due'])).'</td><td class="num">'.$h($money($pay)).'</td><td class="num'.$class($bal).'">'.$h($money($bal)).'</td>'
		.'<td><span class="ma-pill '.$status[1].'">'.$h($status[0]).'</span></td><td><a class="ma-row-edit" href="'
		.$h($self.$fy_query.'&period='.$p[0].'&end='.$p[1]).'">'.$h($ret ? _('View Return') : _('Prepare Return')).'</a></td></tr>';
}
echo '</tbody></table></div></section>';
echo '<p class="ma-fin-note">'.$h(sprintf(_('Amounts in %s - %d period(s) of %d month(s).'), $cur, count($periods), max(1, (int)get_company_pref('tax_prd')))).'</p>';

// ---- modal: prepare / view return
if ($modal_key !== null && isset($by_key[$modal_key])) {
	list($ps, $pe) = $by_key[$modal_key];
	$ret = $filed[$modal_key] ?? null;
	$live = ma_taxes_figures($ps, $pe);
	$f = $ret ? array('output' => (float)$ret['output_tax'], 'input' => (float)$ret['input_tax'], 'due' => (float)$ret['amount_due']) : $live;
	$title = ($ret ? _('Return') : _('Prepare Return')).' - '.sql2date($ps).' - '.sql2date($pe);
	$close = $self.$fy_query;
	echo '<div class="ma-modal" role="dialog" aria-modal="true" aria-label="'.$h($title).'"><a class="ma-modal-backdrop" href="'.$h($close).'" tabindex="-1" aria-hidden="true"></a>'
		.'<div class="ma-modal-panel"><div class="ma-modal-head"><h2>'.$h($title).'</h2><a class="ma-modal-close" href="'.$h($close).'" aria-label="'.$h(_('Close')).'">&times;</a></div><div class="ma-modal-body">';
	echo '<div class="ma-tax-summary"><div>'.$h(_('Collected on sales')).'<strong>'.$h($cur.' '.$money($f['output'])).'</strong></div><div>'.$h(_('Paid on purchases'))
		.'<strong>'.$h($cur.' '.$money($f['input'])).'</strong></div><div>'.$h($f['due'] < 0 ? _('Net refundable') : _('Net amount due')).'<strong class="'.($f['due'] < 0 ? 'ma-fin-pos' : '').'">'
		.$h($cur.' '.$money(abs($f['due']))).'</strong></div></div>';
	if ($ret && (abs($live['due'] - $f['due']) > 0.005))
		echo '<p class="ma-fin-warn">'.$h(sprintf(_('The books now show %s %s for this period; the filed return keeps the figures from when it was filed.'), $cur, $money($live['due']))).'</p>';
	if (!$ret && $pe > $today)
		echo '<p class="ma-fin-warn">'.$h(_('This period is still open: the figures are to date and the return can be filed once it has ended.')).'</p>';
	if ($live['lines']) {
		echo '<div class="ma-table-wrap"><table class="ma-sales-table"><thead><tr><th>'.$h(_('Tax')).'</th><th class="num">'.$h(_('Sales (net)')).'</th><th class="num">'.$h(_('Output tax')).'</th><th class="num">'
			.$h(_('Purchases (net)')).'</th><th class="num">'.$h(_('Input tax')).'</th></tr></thead><tbody>';
		foreach ($live['lines'] as $l)
			echo '<tr><td>'.$h($l['name']).'</td><td class="num">'.$h($money($l['net_output'])).'</td><td class="num">'.$h($money($l['output'])).'</td><td class="num">'
				.$h($money($l['net_input'])).'</td><td class="num">'.$h($money($l['input'])).'</td></tr>';
		echo '</tbody></table></div>';
	}
	echo '<form method="post" class="ma-tax-form" action="'.$h($self).'"><input type="hidden" name="_token" value="'.$h($_SESSION['csrf_token'] ?? '').'">'
		.'<input type="hidden" name="fy" value="'.$fy_id.'"><input type="hidden" name="period_start" value="'.$h($ps).'"><input type="hidden" name="period_end" value="'.$h($pe).'">'
		.'<div class="ma-tax-fields"><label>'.$h(_('Date filed')).'<input type="date" name="filed_date" required value="'.$h($ret ? $ret['filed_date'] : $today).'"></label>'
		.'<label>'.$h(_('Payment made')).'<input type="number" name="payments" step="0.01" min="0" value="'.$h($ret ? $ret['payments'] : '0.00').'"></label>'
		.'<label>'.$h(_('Reference')).'<input type="text" name="reference" maxlength="60" value="'.$h($ret ? $ret['reference'] : '').'" placeholder="'.$h(_('Acknowledgement / payment no.')).'"></label>'
		.'<label>'.$h(_('Memo')).'<input type="text" name="memo" maxlength="255" value="'.$h($ret ? $ret['memo'] : '').'"></label></div>'
		.'<div class="ma-tax-actions"><button class="ma-sales-new" type="submit" name="tax_action" value="file"'.(!$has_table ? ' disabled' : '').'>'
		.$h($ret ? _('Update Return') : _('Mark as Filed')).'</button>';
	if ($ret)
		echo '<button class="ma-bulk-delete" type="submit" name="tax_action" value="withdraw" onclick="return confirm(\''.$h(addslashes(_('Withdraw this filing and reopen the period?'))).'\')">'.$h(_('Withdraw filing')).'</button>';
	echo '<a class="ma-sales-link" href="'.$h(ma_ui_href('gl/inquiry/tax_inquiry.php')).'">'.$h(_('Open Tax Inquiry')).' &rsaquo;</a></div></form>';
	echo '</div></div></div>';
}

end_page();
