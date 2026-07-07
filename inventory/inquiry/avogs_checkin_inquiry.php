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
include_once(__DIR__ . "/avogs_report.inc");

$js = "";
if (user_use_date_picker())
    $js .= get_js_date_picker();

$_SESSION['page_title'] = _($help_context = "Shift Check-in (Opening) Inquiry");
page($_SESSION['page_title'], false, false, "", $js);

if (get_post('ShowList'))
    $Ajax->activate('inq_tbl');

//------------------------------------------------------------------------------------------------
$shifts_tbl = avogs_shifts_tbl();
$lines_tbl = avogs_stock_lines_tbl();

$store_opts = array('' => _("All Stores"));
$sres = db_query("SELECT DISTINCT store_code FROM $shifts_tbl ORDER BY store_code", "store list");
while ($r = db_fetch($sres))
    $store_opts[$r['store_code']] = $r['store_code'];

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

$sql = "SELECT s.*, u.real_name,
        (SELECT COUNT(*) FROM $lines_tbl l WHERE l.shift_id = s.id) AS stock_line_count
    FROM $shifts_tbl s
    LEFT JOIN " . TB_PREF . "users u ON u.user_id = s.opened_by
    WHERE $where
    ORDER BY s.opened_at DESC, s.id DESC";
$result = db_query($sql, "could not retrieve check-in data");

echo avogs_report_styles();

div_start('inq_tbl');
start_table(TABLESTYLE);
$th = array(_("Opened At"), _("Opened By"), _("Store"), _("Shift"),
    _("Items"), _("Avo (pcs)"), _("Till"), _("Float"),
    _("Stock"), _("Cash"), _("Photos"), _("Notes"), _("Status"), "");
table_header($th);

$k = 0;
$count = 0;
$tot_stock = 0;
$tot_till = 0;
$tot_float = 0;
while ($row = db_fetch($result)) {
    alt_table_row_color($k);

    label_cell(avogs_format_dt($row['opened_at']));
    label_cell($row['real_name'] ? $row['real_name'] : ($row['opened_by'] ? $row['opened_by'] : "-"));
    label_cell($row['store_code']);
    label_cell(ucfirst($row['shift_key']));
    label_cell((int) $row['stock_line_count'], "align=center");
    qty_cell($row['opening_stock'], false, 0);
    amount_cell($row['opening_till']);
    amount_cell($row['opening_float']);
    label_cell(avogs_flag_html($row['stock_discrepancy']), "align=center");
    label_cell(avogs_flag_html($row['cash_discrepancy']), "align=center");
    label_cell((string) avogs_count_photos($row), "align=center");
    label_cell(avogs_inquiry_icon(avogs_has_comments($row)), "align=center");
    label_cell($row['status'] == 'open' ? _("Open") : _("Closed"), "align=center");
    label_cell(avogs_view_link($path_to_root, 'avogs_checkin_view.php', $row['id']), "align=center");
    end_row();

    $tot_stock += $row['opening_stock'];
    $tot_till += $row['opening_till'];
    $tot_float += $row['opening_float'];
    $count++;
}

if ($count == 0) {
    label_row(_("No check-ins found for the selected period."), "", "colspan=14 align=center");
} else {
    start_row("class='inquirybg'");
    label_cell("<b>" . _("Totals") . " ($count " . _("check-ins") . ")</b>", "colspan=5");
    qty_cell($tot_stock, false, 0);
    amount_cell($tot_till);
    amount_cell($tot_float);
    label_cell("&nbsp;", "colspan=6");
    end_row();
}

end_table(1);
div_end();

end_page();
