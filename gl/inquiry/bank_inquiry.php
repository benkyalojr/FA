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
$page_security = 'SA_BANKTRANSVIEW';
$path_to_root="../..";
include_once($path_to_root . "/includes/session.inc");

include_once($path_to_root . "/includes/date_functions.inc");
include_once($path_to_root . "/includes/db_pager.inc");
include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/includes/data_checks.inc");

include_once($path_to_root . "/gl/includes/gl_db.inc");
include_once($path_to_root . "/includes/banking.inc");

$js = "";
if ($SysPrefs->use_popup_windows)
	$js .= get_js_open_window(800, 500);
if (user_use_date_picker())
	$js .= get_js_date_picker();
page(_($help_context = "Bank Account Inquiry"), isset($_GET['bank_account']) && !isset($_GET['TransAfterDate']), false, "", $js, false, "", true);

check_db_has_bank_accounts(_("There are no bank accounts defined in the system."));

//-----------------------------------------------------------------------------------
// Ajax updates
//
if (get_post('Show'))
{
	$Ajax->activate('trans_tbl');
}
//------------------------------------------------------------------------------------------------

if (isset($_GET['bank_account']))
	$_POST['bank_account'] = $_GET['bank_account'];

start_form();
ma_sales_filter_start();
ma_sales_field(_('Search'), function() { ref_cells(null, 'search', '', null, _('Reference, type, person or memo')); });
if (!$page_nested)
	ma_sales_field(_('Account'), function() { bank_accounts_list_cells(null, 'bank_account', null); });
ma_sales_field(_('Date'), function() {
	date_cells(null, 'TransAfterDate', '', null, -user_transaction_days());
	date_cells(null, 'TransToDate');
});
$bank_new = array();
foreach (array(array(_('New Payment'), 'gl/gl_bank.php?NewPayment=Yes', 'SA_PAYMENT'),
	array(_('New Deposit'), 'gl/gl_bank.php?NewDeposit=Yes', 'SA_DEPOSIT'),
	array(_('New Transfer'), 'gl/bank_transfer.php?', 'SA_BANKTRANSFER')) as $n)
	if ($_SESSION['wa_current_user']->can_access_page($n[2]))
		$bank_new[] = array($n[0], $n[1]);
ma_sales_filter_end('Show', _('Show'), $bank_new, _('Show'));
end_form();

//------------------------------------------------------------------------------------------------

if (!isset($_POST['bank_account']))
	$_POST['bank_account'] = "";

$result = get_bank_trans_for_bank_account($_POST['bank_account'], $_POST['TransAfterDate'], $_POST['TransToDate']);	

div_start('trans_tbl');
if (!$page_nested)
{
	$act = get_bank_account($_POST["bank_account"]);
	display_heading($act['bank_account_name']." - ".$act['bank_curr_code']);
}

start_table(TABLESTYLE);

$th = array(_("Type"), _("#"), _("Reference"), _("Date"),
	_("Debit"), _("Credit"), _("Balance"), _("Person/Item"), _("Memo"), _("Status"), "", "");
table_header($th);

$bfw = get_balance_before_for_bank_account($_POST['bank_account'], $_POST['TransAfterDate']);

$credit = $debit = 0;
start_row("class='inquirybg' style='font-weight:bold'");
label_cell(_("Opening Balance")." - ".$_POST['TransAfterDate'], "colspan=4");
display_debit_or_credit_cells($bfw);
label_cell("");
label_cell("", "colspan=5");

end_row();
$running_total = $bfw;
if ($bfw > 0 ) 
	$debit += $bfw;
else 
	$credit += $bfw;
$j = 1;
$k = 0; //row colour counter
while ($myrow = db_fetch($result))
{

	alt_table_row_color($k);

	$running_total += $myrow["amount"];

	$person = payment_person_name($myrow["person_type_id"],$myrow["person_id"]);
	$memo = get_comments_string($myrow["type"], $myrow["trans_no"]);
	$needle = trim(get_post('search'));
	if ($needle !== '' && stripos($systypes_array[$myrow["type"]].' '.$myrow["ref"].' '.$person.' '.$memo, $needle) === false)
	{
		if ($myrow["amount"] > 0) $debit += $myrow["amount"]; else $credit += $myrow["amount"];
		continue;
	}

	$trandate = sql2date($myrow["trans_date"]);
	label_cell($systypes_array[$myrow["type"]]);
	label_cell(get_trans_view_str($myrow["type"],$myrow["trans_no"]));
	label_cell(get_trans_view_str($myrow["type"],$myrow["trans_no"],$myrow['ref']));
	label_cell($trandate);
	display_debit_or_credit_cells($myrow["amount"]);
	amount_cell($running_total);

	label_cell($person);

	label_cell($memo);
	label_cell('<span class="ma-pill '.($myrow["reconciled"] ? 'paid' : 'pending').'">'
		.($myrow["reconciled"] ? _("Reconciled") : _("Unreconciled")).'</span>');
	label_cell(get_gl_view_str($myrow["type"], $myrow["trans_no"]));
	if (!$page_nested)
		label_cell(trans_editor_link($myrow["type"], $myrow["trans_no"]));

	end_row();
 	if ($myrow["amount"] > 0 ) 
 		$debit += $myrow["amount"];
 	else 
 		$credit += $myrow["amount"];

	if ($j == 12)
	{
		$j = 1;
		table_header($th);
	}
	$j++;
}
//end of while loop

start_row("class='inquirybg' style='font-weight:bold'");
label_cell(_("Ending Balance")." - ". $_POST['TransToDate'], "colspan=4");
amount_cell($debit);
amount_cell(-$credit);
//display_debit_or_credit_cells($running_total);
amount_cell($debit+$credit);
label_cell("", "colspan=5");
end_row();
end_table(2);
div_end();
//------------------------------------------------------------------------------------------------

end_page();

