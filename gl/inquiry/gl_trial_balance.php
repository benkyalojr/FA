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
$page_security = 'SA_GLANALYTIC';
$path_to_root="../..";

include_once($path_to_root . "/includes/session.inc");

include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/includes/date_functions.inc");
include_once($path_to_root . "/admin/db/fiscalyears_db.inc");
include_once($path_to_root . "/includes/data_checks.inc");

include_once($path_to_root . "/gl/includes/gl_db.inc");

$js = "";
if (user_use_date_picker())
	$js = get_js_date_picker();

page(_($help_context = "Trial Balance"), false, false, "", $js);

// First visit: the current fiscal year to date, hiding accounts with no activity.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	$fy = get_current_fiscalyear();
	if (!isset($_POST['TransFromDate']))
		$_POST['TransFromDate'] = sql2date($fy['begin']);
	if (!isset($_POST['TransToDate']))
		$_POST['TransToDate'] = today();
	$_POST['NoZero'] = 1;
}

//----------------------------------------------------------------------------------------------------
// Ajax updates
//
if (get_post('Show'))
{
	$Ajax->activate('balance_tbl');
}

function gl_inquiry_controls()
{
	$dim = get_company_pref('use_dimension');
	start_form();

	ma_sales_filter_start();
	ma_sales_field(_('Date'), function() {
		date_cells(null, 'TransFromDate');
		date_cells(null, 'TransToDate');
	});
	if ($dim >= 1)
		ma_sales_field(_('Dimension').' 1', function() { dimensions_list_cells(null, 'Dimension', null, true, " ", false, 1); });
	if ($dim > 1)
		ma_sales_field(_('Dimension').' 2', function() { dimensions_list_cells(null, 'Dimension2', null, true, " ", false, 2); });
	ma_sales_field('', function() { check_cells(_("No zero values"), 'NoZero', null); }, 'ma-sales-check');
	ma_sales_field('', function() { check_cells(_("Only balances"), 'Balance', null); }, 'ma-sales-check');
	ma_sales_field('', function() { check_cells(_("Group totals only"), 'GroupTotalOnly', null); }, 'ma-sales-check');
	ma_sales_filter_end('Show', _('Show'), null, _('Show'));
	end_form();
}

//----------------------------------------------------------------------------------------------------
// Rendering helpers: same figures as FA's get_balance(), laid out as cards.

function ma_tb_amount($value, $class = '')
{
	$value = round2($value, user_price_dec());
	return '<td class="num '.$class.($value == 0 ? ' zero' : '').'">'.number_format2($value, user_price_dec()).'</td>';
}

// A debit/credit pair for a net balance, as display_debit_or_credit_cells() does.
function ma_tb_balance_pair($value, $class = '')
{
	$value = round2($value, user_price_dec());
	return $value >= 0 ? ma_tb_amount($value, $class).ma_tb_amount(0, $class) : ma_tb_amount(0, $class).ma_tb_amount(abs($value), $class);
}

function display_trial_balance($type, $typename)
{
	global $path_to_root, $SysPrefs, $ma_tb;

	$printtitle = 0; // flag for printing the group name
	$group_only = check_value('GroupTotalOnly');
	$balances = check_value('Balance');

	$accounts = get_gl_accounts(null, null, $type);

	$begin = get_fiscalyear_begin_for_date($_POST['TransFromDate']);
	if (date1_greater_date2($begin, $_POST['TransFromDate']))
		$begin = $_POST['TransFromDate'];
	$begin = add_days($begin, -1);

	$before = $ma_tb; // totals before this group, to derive its own subtotal

	while ($account = db_fetch($accounts))
	{
		if (!$printtitle)
		{
			if (!$group_only)
				echo '<tr class="ma-tb-group"><td colspan="8">'.ma_ui_escape(_("Group")).' &middot; '.ma_ui_escape($type).' &middot; '.ma_ui_escape($typename).'</td></tr>';
			$printtitle = 1;
		}

		// FA doesn't clear closed years, so brought-forward balances include the past.
		if (@$SysPrefs->clear_trial_balance_opening)
		{
			$open = get_balance($account["account_code"], $_POST['Dimension'], $_POST['Dimension2'], $begin,  $begin, false, true);
			$offset = min($open['debit'], $open['credit']);
		} else
			$offset = 0;

		$prev = get_balance($account["account_code"], $_POST['Dimension'], $_POST['Dimension2'], $begin, $_POST['TransFromDate'], false, false);
		$curr = get_balance($account["account_code"], $_POST['Dimension'], $_POST['Dimension2'], $_POST['TransFromDate'], $_POST['TransToDate'], true, true);
		$tot = get_balance($account["account_code"], $_POST['Dimension'], $_POST['Dimension2'], $begin, $_POST['TransToDate'], false, true);
		if (check_value("NoZero") && !$prev['balance'] && !$curr['balance'] && !$tot['balance'])
			continue;
		if (!$group_only)
		{
			$url = "<a class='ma-tb-account' href='$path_to_root/gl/inquiry/gl_account_inquiry.php?TransFromDate=" . urlencode($_POST["TransFromDate"])
				. "&TransToDate=" . urlencode($_POST["TransToDate"]) . "&account=" . urlencode($account["account_code"])
				. "&Dimension=" . urlencode($_POST["Dimension"]) . "&Dimension2=" . urlencode($_POST["Dimension2"]) . "'>"
				. ma_ui_escape($account["account_code"]) . "</a>";
			echo '<tr class="ma-tb-data"><td>'.$url.'</td><td>'.ma_ui_escape($account["account_name"]).'</td>';
			if ($balances)
				echo ma_tb_balance_pair($prev['balance']).ma_tb_balance_pair($curr['balance']).ma_tb_balance_pair($tot['balance'], 'ma-tb-bal');
			else
				echo ma_tb_amount($prev['debit']-$offset).ma_tb_amount($prev['credit']-$offset)
					.ma_tb_amount($curr['debit']).ma_tb_amount($curr['credit'])
					.ma_tb_amount($tot['debit']-$offset, 'ma-tb-bal').ma_tb_amount($tot['credit']-$offset, 'ma-tb-bal');
			echo '</tr>';
			$ma_tb['accounts']++;
		}
		if (!$balances)
		{
			$ma_tb['pdeb'] += $prev['debit'];
			$ma_tb['pcre'] += $prev['credit'];
			$ma_tb['cdeb'] += $curr['debit'];
			$ma_tb['ccre'] += $curr['credit'];
			$ma_tb['tdeb'] += $tot['debit'];
			$ma_tb['tcre'] += $tot['credit'];
		}
		$ma_tb['pbal'] += $prev['balance'];
		$ma_tb['cbal'] += $curr['balance'];
		$ma_tb['tbal'] += $tot['balance'];
	}

	// Account groups under this group.
	$result = get_account_types(false, false, $type);
	while ($accounttype = db_fetch($result))
	{
		if (!$printtitle)
		{
			echo '<tr class="ma-tb-group"><td colspan="8">'.ma_ui_escape(_("Group")).' &middot; '.ma_ui_escape($type).' &middot; '.ma_ui_escape($typename).'</td></tr>';
			$printtitle = 1;
		}
		display_trial_balance($accounttype["id"], $accounttype["name"].' ('.$typename.')');
	}

	// Group subtotal, for groups that have accounts or sub-groups.
	if (!$printtitle)
		return;
	echo '<tr class="ma-tb-subtotal"><td colspan="2">'.ma_ui_escape(_("Total")).' &middot; '.ma_ui_escape($typename).'</td>';
	if (!$balances)
		echo ma_tb_amount($ma_tb['pdeb'] - $before['pdeb']).ma_tb_amount($ma_tb['pcre'] - $before['pcre'])
			.ma_tb_amount($ma_tb['cdeb'] - $before['cdeb']).ma_tb_amount($ma_tb['ccre'] - $before['ccre'])
			.ma_tb_amount($ma_tb['tdeb'] - $before['tdeb'], 'ma-tb-bal').ma_tb_amount($ma_tb['tcre'] - $before['tcre'], 'ma-tb-bal');
	else
		echo ma_tb_balance_pair($ma_tb['pbal'] - $before['pbal']).ma_tb_balance_pair($ma_tb['cbal'] - $before['cbal'])
			.ma_tb_balance_pair($ma_tb['tbal'] - $before['tbal'], 'ma-tb-bal');
	echo '</tr>';
}

function ma_tb_table_header()
{
	return '<thead><tr class="ma-tb-band"><th rowspan="2" class="l">'.ma_ui_escape(_("Account")).'</th><th rowspan="2" class="l">'.ma_ui_escape(_("Account Name")).'</th>'
		.'<th colspan="2">'.ma_ui_escape(_("Brought Forward")).'</th><th colspan="2">'.ma_ui_escape(_("This Period")).'</th><th colspan="2" class="ma-tb-bal">'
		.ma_ui_escape(_("Balance")).'</th></tr><tr class="ma-tb-sub">'
		.str_repeat('<th class="num">'.ma_ui_escape(_("Debit")).'</th><th class="num">'.ma_ui_escape(_("Credit")).'</th>', 3).'</tr></thead>';
}

//----------------------------------------------------------------------------------------------------

echo '<div class="ma-tb-intro"><p>'.ma_ui_escape(_('Account movements and balances at a glance')).'</p><div><span class="ma-tb-tag">'
	.ma_ui_escape(get_company_pref('curr_default')).'</span><button type="button" class="ma-tb-print" onclick="window.print()">'
	.ma_ui_icon('file').' '.ma_ui_escape(_('Print / PDF')).'</button></div></div>';

gl_inquiry_controls();

if (isset($_POST['TransFromDate']))
{
	$row = get_current_fiscalyear();
	if (date1_greater_date2($_POST['TransFromDate'], sql2date($row['end'])))
	{
		display_error(_("The from date cannot be bigger than the fiscal year end."));
		set_focus('TransFromDate');
		return;
	}
}
div_start('balance_tbl');
if (!isset($_POST['Dimension']))
	$_POST['Dimension'] = 0;
if (!isset($_POST['Dimension2']))
	$_POST['Dimension2'] = 0;

$ma_tb = array('pdeb'=>0, 'pcre'=>0, 'cdeb'=>0, 'ccre'=>0, 'tdeb'=>0, 'tcre'=>0, 'pbal'=>0, 'cbal'=>0, 'tbal'=>0, 'accounts'=>0);
$cur = get_company_pref('curr_default');

$classresult = get_account_classes(false);
while ($class = db_fetch($classresult))
{
	ob_start();
	$ma_tb['accounts'] = 0;
	// Account groups with no parent group, within this class.
	$typeresult = get_account_types(false, $class['cid'], -1);
	while ($accounttype = db_fetch($typeresult))
		display_trial_balance($accounttype["id"], $accounttype["name"]);
	$rows = ob_get_clean();
	if (trim($rows) === '')
		continue; // nothing to show for this class
	echo '<section class="ma-tb-card"><div class="ma-tb-title"><h2>'.ma_ui_escape($class['class_name']).'</h2><span>'
		.ma_ui_escape(_('Account balances')).' &middot; '.ma_ui_escape($cur).'</span></div><div class="ma-table-wrap"><table class="ma-tb-table">'
		.ma_tb_table_header().'<tbody>'.$rows.'</tbody></table></div><div class="ma-tb-footer"><span>'
		.ma_ui_escape(sprintf(_('%d accounts'), $ma_tb['accounts'])).'</span><span>'.ma_ui_escape(_('Debit and credit amounts')).'</span></div></section>';
}

// Grand totals across every class.
echo '<section class="ma-tb-card ma-tb-totals"><div class="ma-table-wrap"><table class="ma-tb-table">'.ma_tb_table_header().'<tbody>';
if (!check_value('Balance'))
	echo '<tr class="ma-tb-subtotal"><td colspan="2">'.ma_ui_escape(_("Total").' - '.$_POST['TransToDate']).'</td>'
		.ma_tb_amount($ma_tb['pdeb']).ma_tb_amount($ma_tb['pcre']).ma_tb_amount($ma_tb['cdeb']).ma_tb_amount($ma_tb['ccre'])
		.ma_tb_amount($ma_tb['tdeb'], 'ma-tb-bal').ma_tb_amount($ma_tb['tcre'], 'ma-tb-bal').'</tr>';
echo '<tr class="ma-tb-ending"><td colspan="2">'.ma_ui_escape(_("Ending Balance").' - '.$_POST['TransToDate']).'</td>'
	.ma_tb_balance_pair($ma_tb['pbal']).ma_tb_balance_pair($ma_tb['cbal']).ma_tb_balance_pair($ma_tb['tbal'], 'ma-tb-bal').'</tr>';
echo '</tbody></table></div></section>';

if (($pbal = round2($ma_tb['pbal'], user_price_dec())) != 0 && $_POST['Dimension'] == 0 && $_POST['Dimension2'] == 0)
	display_warning(_("The Opening Balance is not in balance, probably due to a non closed Previous Fiscalyear."));
div_end();

//----------------------------------------------------------------------------------------------------

end_page();
