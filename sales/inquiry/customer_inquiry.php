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
$page_security = 'SA_SALESTRANSVIEW';
$path_to_root = "../..";
include_once($path_to_root . "/includes/db_pager.inc");
include_once($path_to_root . "/includes/session.inc");

include_once($path_to_root . "/sales/includes/sales_ui.inc");
include_once($path_to_root . "/sales/includes/sales_db.inc");
include_once($path_to_root . "/reporting/includes/reporting.inc");

$js = "";
if ($SysPrefs->use_popup_windows)
	$js .= get_js_open_window(900, 500);
if (user_use_date_picker())
	$js .= get_js_date_picker();
// Tab links preselect a transaction type, e.g. ?filterType=1 for invoices.
if (!isset($_POST['filterType']) && isset($_GET['filterType']) && is_scalar($_GET['filterType']))
	$_POST['filterType'] = $_GET['filterType'];
page(_($help_context = "Customer Transactions"), isset($_GET['customer_id']), false, "", $js);

//------------------------------------------------------------------------------------------------

function systype_name($dummy, $type)
{
	global $systypes_array;

	return $systypes_array[$type];
}

function order_view($row)
{
	return $row['order_']>0 ?
		get_customer_trans_view_str(ST_SALESORDER, $row['order_'])
		: "";
}

function trans_view($trans)
{
	return get_trans_view_str($trans["type"], $trans["trans_no"]);
}

function due_date($row)
{
	return	$row["type"] == ST_SALESINVOICE	? $row["due_date"] : '';
}

// eTIMS/VAT column - only sales invoices and credit notes are ever
// stamped, matching etims_stamp_now_or_queue()'s own scope. Shows the
// KRA QR verification link once stamped, or a Restamp action otherwise
// - one small per-row lookup, same style gl_view()/trans_view() above
// already use for this exact list.
function etims_status($row)
{
	if ($row['type'] != ST_SALESINVOICE && $row['type'] != ST_CUSTCREDIT)
		return '';

	$sub = db_fetch(db_query("SELECT status, short_url FROM " . TB_PREF . "etims_submissions
		WHERE trans_type=" . db_escape($row['type']) . " AND trans_no=" . db_escape($row['trans_no']),
		'Cannot get eTIMS submission'));

	if ($sub && $sub['status'] === 'stamped' && $sub['short_url'])
		return "<a href='" . html_specials_encode($sub['short_url']) . "' target='_blank'>" . _('VAT') . "</a>";

	$restamp_url = $_SERVER['PHP_SELF'] . "?etims_restamp=" . $row['type'] . "-" . $row['trans_no']
		. "&customer_id=" . urlencode(get_post('customer_id'));
	return "<a href='" . $restamp_url . "'>" . _('Restamp') . "</a>";
}

function gl_view($row)
{
	return get_gl_view_str($row["type"], $row["trans_no"]);
}

function fmt_amount($row)
{
	$value =
	    $row['type']==ST_CUSTCREDIT || $row['type']==ST_CUSTPAYMENT || $row['type']==ST_BANKDEPOSIT ? -$row["TotalAmount"] : $row["TotalAmount"];
    return price_format($value);
}

function credit_link($row)
{
	global $page_nested;

	if ($page_nested)
		return '';
	if ($row["Outstanding"] > 0)
	{
		if ($row['type'] == ST_CUSTDELIVERY)
			return pager_link(_('Invoice'), "/sales/customer_invoice.php?DeliveryNumber=" 
				.$row['trans_no'], ICON_DOC);
		else if ($row['type'] == ST_SALESINVOICE)
			return pager_link(_("Credit This") ,
			"/sales/customer_credit_invoice.php?InvoiceNumber=". $row['trans_no'], ICON_CREDIT);
	}	
}

function edit_link($row)
{
	global $page_nested;

	if ($page_nested)
		return '';

	return $row['type'] == ST_CUSTCREDIT && $row['order_'] ? '' : 	// allow  only free hand credit notes edition
			trans_editor_link($row['type'], $row['trans_no']);
}

function copy_link($row)
{
    global $page_nested;

    if ($page_nested)
        return '';
    if ($row['type'] == ST_CUSTDELIVERY)
        return pager_link(_("Copy Delivery"), "/sales/sales_order_entry.php?NewDelivery=" 
            .$row['order_'], ICON_DOC);
    elseif ($row['type'] == ST_SALESINVOICE)
        return pager_link(_("Copy Invoice"),    "/sales/sales_order_entry.php?NewInvoice="
            . $row['order_'], ICON_DOC);
}

function prt_link($row)
{
  	if ($row['type'] == ST_CUSTPAYMENT || $row['type'] == ST_BANKDEPOSIT) 
		return print_document_link($row['trans_no']."-".$row['type'], _("Print Receipt"), true, ST_CUSTPAYMENT, ICON_PRINT);
  	elseif ($row['type'] == ST_BANKPAYMENT) // bank payment printout not defined yet.
		return '';
 	else
 		return print_document_link($row['trans_no']."-".$row['type'], _("Print"), true, $row['type'], ICON_PRINT);
}

function check_overdue($row)
{
	return $row['OverDue'] == 1
		&& floatcmp(ABS($row["TotalAmount"]), $row["Allocated"]) != 0;
}
//------------------------------------------------------------------------------------------------

function display_customer_summary($customer_record)
{
	$past1 = get_company_pref('past_due_days');
	$past2 = 2 * $past1;
    if ($customer_record && $customer_record["dissallow_invoices"] != 0)
    {
    	echo "<center><font color=red size=4><b>" . _("CUSTOMER ACCOUNT IS ON HOLD") . "</font></b></center>";
    }

	$nowdue = "1-" . $past1 . " " . _('Days');
	$pastdue1 = $past1 + 1 . "-" . $past2 . " " . _('Days');
	$pastdue2 = _('Over') . " " . $past2 . " " . _('Days');

    start_table(TABLESTYLE, "width='80%'");
    $th = array(_("Currency"), _("Terms"), _("Current"), $nowdue,
    	$pastdue1, $pastdue2, _("Total Balance"));
    table_header($th);
    if ($customer_record != false)
    {
		start_row();
	    label_cell($customer_record["curr_code"]);
	    label_cell($customer_record["terms"]);
		amount_cell($customer_record["Balance"] - $customer_record["Due"]);
		amount_cell($customer_record["Due"] - $customer_record["Overdue1"]);
		amount_cell($customer_record["Overdue1"] - $customer_record["Overdue2"]);
		amount_cell($customer_record["Overdue2"]);
		amount_cell($customer_record["Balance"]);
		end_row();
	}

	end_table();
}

if (isset($_GET['customer_id']))
{
	$_POST['customer_id'] = $_GET['customer_id'];
}

//------------------------------------------------------------------------------------------------

if (isset($_GET['etims_restamp'])) {
	list($etims_type, $etims_trans_no) = explode('-', $_GET['etims_restamp'], 2);
	include_once($path_to_root . "/etims/includes/etims_setup.inc");
	if (etims_schema_ready()) {
		include_once($path_to_root . "/etims/includes/db/etims_submit_db.inc");
		etims_stamp_now_or_queue((int)$etims_type, (int)$etims_trans_no);
		display_notification(_('Restamp attempted - refresh in a moment to see the result.'));
	} else {
		display_error(etims_setup_message());
	}
}

start_form();

if (!isset($_POST['customer_id']))
	$_POST['customer_id'] = get_global_customer();

ma_sales_filter_start();
ma_sales_field(_('Reference'), function() { ref_cells(null, 'Ref', '', NULL, _('Enter reference fragment or leave empty')); });
if (!$page_nested)
	ma_sales_field(_('Customer'), function() { customer_list_cells(null, 'customer_id', null, true, true, false, true); });
ma_sales_field(_('Type'), function() { cust_allocations_list_cells(null, 'filterType', null, true, true); });
if ($_POST['filterType'] != '2')
{
	ma_sales_field(_('Date'), function() {
		date_cells(null, 'TransAfterDate', '', null, -user_transaction_days());
		date_cells(null, 'TransToDate', '', null);
	});
}
ma_sales_field('', function() { check_cells(_("Zero values"), 'show_voided'); }, 'ma-sales-check');
ma_sales_filter_end('RefreshInquiry', _('Refresh Inquiry'));

set_global_customer($_POST['customer_id']);

//------------------------------------------------------------------------------------------------

div_start('totals_tbl');
if ($_POST['customer_id'] != "" && $_POST['customer_id'] != ALL_TEXT)
{
	$customer_record = get_customer_details(get_post('customer_id'), get_post('TransToDate'), false);
    display_customer_summary($customer_record);
    echo "<br>";
}
div_end();

if (get_post('RefreshInquiry') || list_updated('filterType'))
{
	$Ajax->activate('_page_body');
}
//------------------------------------------------------------------------------------------------
$sql = get_sql_for_customer_inquiry(get_post('TransAfterDate'), get_post('TransToDate'),
	get_post('customer_id'), get_post('filterType'), check_value('show_voided'), get_post('Ref'));

//------------------------------------------------------------------------------------------------
//db_query("set @bal:=0");

$cols = array(
	_("Type") => array('fun'=>'systype_name', 'ord'=>''),
	_("#") => array('fun'=>'trans_view', 'ord'=>'', 'align'=>'right'),
	_("Order") => array('fun'=>'order_view', 'align'=>'right'), 
	_("Reference"), 
	_("Date") => array('name'=>'tran_date', 'type'=>'date', 'ord'=>'desc'),
	_("Due Date") => array('type'=>'date', 'fun'=>'due_date'),
	_("Customer") => array('ord'=>''), 
	_("Branch") => array('ord'=>''), 
	_("Currency") => array('align'=>'center'),
	_("Amount") => array('align'=>'right', 'fun'=>'fmt_amount'), 
	_("Balance") => array('align'=>'right', 'type'=>'amount'),
	_("VAT") => array('fun'=>'etims_status', 'align'=>'center')
	// GL, Edit, Credit, Copy and Print live in the document modal (click the #).
	);


if ($_POST['customer_id'] != ALL_TEXT) {
	$cols[_("Customer")] = 'skip';
	$cols[_("Currency")] = 'skip';
}
if ($_POST['filterType'] != '2')
	$cols[_("Balance")] = 'skip';
if (!get_company_pref('use_etims_stamping'))
	$cols[_("VAT")] = 'skip';

$table =& new_db_pager('trans_tbl', $sql, $cols);
$table->set_marker('check_overdue', _("Marked items are overdue."));

$table->width = "85%";

display_db_pager($table);

end_form();
end_page();
