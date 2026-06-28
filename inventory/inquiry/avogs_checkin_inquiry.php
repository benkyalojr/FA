<?php
/**********************************************************************
    AVO'Gs — Shift Check-in (Opening) Inquiry
    Reads the opening-checklist data captured by the mobile app
    (0_avogs_shifts) and presents it as a standard FA inquiry.
***********************************************************************/
$page_security = 'SA_ITEMSTRANSVIEW';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/date_functions.inc");
include_once($path_to_root . "/includes/ui.inc");

$js = "";
if (user_use_date_picker())
    $js .= get_js_date_picker();

$_SESSION['page_title'] = _($help_context = "Shift Check-in (Opening) Inquiry");
page($_SESSION['page_title'], false, false, "", $js);

if (get_post('ShowList'))
    $Ajax->activate('inq_tbl');

//------------------------------------------------------------------------------------------------
$shifts_tbl = TB_PREF . "avogs_shifts";

// Build store filter from the shifts that exist.
$store_opts = array('' => _("All Stores"));
$sres = db_query("SELECT DISTINCT store_code FROM $shifts_tbl ORDER BY store_code", "store list");
while ($r = db_fetch($sres))
    $store_opts[$r['store_code']] = $r['store_code'];

// Build user filter from FA users.
$user_opts = array('' => _("All Users"));
$ures = db_query("SELECT user_id, real_name FROM " . TB_PREF . "users WHERE inactive = 0 ORDER BY real_name", "user list");
while ($r = db_fetch($ures))
    $user_opts[$r['user_id']] = $r['real_name'];

start_form();

start_table(TABLESTYLE_NOBORDER);
start_row();
date_cells(_("From:"), 'FromDate', '', null, -30);
date_cells(_("To:"), 'ToDate');
label_cell(_("User:"));
label_cell(array_selector('user', get_post('user'), $user_opts));
label_cell(_("Store:"));
label_cell(array_selector('store', get_post('store'), $store_opts));
label_cell(_("Shift:"));
label_cell(array_selector('shift', get_post('shift'),
    array('' => _("All"), 'morning' => _("Morning"), 'evening' => _("Evening"))));
submit_cells('ShowList', _("Show"), '', _('Refresh Inquiry'), 'default');
end_row();
end_table();
end_form();

//------------------------------------------------------------------------------------------------
$from = date2sql(get_post('FromDate'));
$to = date2sql(get_post('ToDate'));

$where = "DATE(s.opened_at) BETWEEN " . db_escape($from) . " AND " . db_escape($to);
if (get_post('user') != '')
    $where .= " AND s.opened_by = " . db_escape(get_post('user'));
if (get_post('store') != '')
    $where .= " AND s.store_code = " . db_escape(get_post('store'));
if (get_post('shift') != '')
    $where .= " AND s.shift_key = " . db_escape(get_post('shift'));

$sql = "SELECT s.*, u.real_name FROM $shifts_tbl s
    LEFT JOIN " . TB_PREF . "users u ON u.user_id = s.opened_by
    WHERE $where ORDER BY s.opened_at DESC, s.id DESC";
$result = db_query($sql, "could not retrieve check-in data");

div_start('inq_tbl');
start_table(TABLESTYLE);
$th = array(_("Opened At"), _("Opened By"), _("Store"), _("Shift"), _("Opening Stock (pcs)"),
    _("Opening Till"), _("Opening Float"), _("Stock"), _("Cash"), _("Status"));
table_header($th);

$k = 0;
$count = 0;
$tot_stock = 0; $tot_till = 0; $tot_float = 0;
while ($row = db_fetch($result)) {
    alt_table_row_color($k);

    label_cell($row['opened_at'] ? sql2date(substr($row['opened_at'], 0, 10)) . " " . substr($row['opened_at'], 11, 5) : "-");
    label_cell($row['real_name'] ? $row['real_name'] : ($row['opened_by'] ? $row['opened_by'] : "-"));
    label_cell($row['store_code']);
    label_cell(ucfirst($row['shift_key']));
    qty_cell($row['opening_stock'], false, 0);
    amount_cell($row['opening_till']);
    amount_cell($row['opening_float']);
    // Discrepancy flags from the opening count.
    label_cell($row['stock_discrepancy'] ? "<span style='color:#c0392b'>" . _("Flagged") . "</span>" : "<span style='color:#27ae60'>" . _("OK") . "</span>", "align=center");
    label_cell($row['cash_discrepancy'] ? "<span style='color:#c0392b'>" . _("Flagged") . "</span>" : "<span style='color:#27ae60'>" . _("OK") . "</span>", "align=center");
    label_cell($row['status'] == 'open' ? _("Open") : _("Closed"), "align=center");
    end_row();

    $tot_stock += $row['opening_stock'];
    $tot_till += $row['opening_till'];
    $tot_float += $row['opening_float'];
    $count++;
}

if ($count == 0) {
    label_row(_("No check-ins found for the selected period."), "", "colspan=10 align=center");
} else {
    start_row("class='inquirybg'");
    label_cell("<b>" . _("Totals") . " ($count " . _("check-ins") . ")</b>", "colspan=4");
    qty_cell($tot_stock, false, 0);
    amount_cell($tot_till);
    amount_cell($tot_float);
    label_cell("&nbsp;", "colspan=3");
    end_row();
}

end_table(1);
div_end();

end_page();
