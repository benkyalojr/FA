<?php
/**********************************************************************
    AVO'Gs — Shift Check-out (Closing) Inquiry
    Reads the closing-checklist data captured by the mobile app
    (0_avogs_shifts + the handover counts in 0_avogs_handover) and
    presents it as a standard FA inquiry.
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

$_SESSION['page_title'] = _($help_context = "Shift Check-out (Closing) Inquiry");
page($_SESSION['page_title'], false, false, "", $js);

if (get_post('ShowList'))
    $Ajax->activate('inq_tbl');

//------------------------------------------------------------------------------------------------
$shifts_tbl = avogs_shifts_tbl();
$ho_tbl = avogs_handover_tbl();

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

$where = "s.status = 'closed' AND DATE(s.closed_at) BETWEEN " . db_escape($from) . " AND " . db_escape($to);
if (get_post('user') != '')
    $where .= " AND s.closed_by = " . db_escape(get_post('user'));
if (get_post('store') != '')
    $where .= " AND s.store_code = " . db_escape(get_post('store'));
if (get_post('shift') != '')
    $where .= " AND s.shift_key = " . db_escape(get_post('shift'));

$sql = "SELECT s.*, u.real_name,
        h.avo, h.till AS h_till, h.flt, h.juice, h.smoothie, h.ginger, h.h250, h.h450, h.h900
    FROM $shifts_tbl s
    LEFT JOIN " . TB_PREF . "users u ON u.user_id = s.closed_by
    LEFT JOIN $ho_tbl h
      ON h.store_code = s.store_code
     AND h.shift_key = CASE s.shift_key WHEN 'morning' THEN 'evening' ELSE 'morning' END
    WHERE $where
    ORDER BY s.closed_at DESC, s.id DESC";
$result = db_query($sql, "could not retrieve check-out data");

echo avogs_report_styles();

div_start('inq_tbl');
start_table(TABLESTYLE);
$th = array(_("Closed At"), _("Closed By"), _("Store"), _("Shift"), _("Cash Counted"),
    _("Remaining Avo"), _("Next Till"), _("Next Float"),
    _("Juice"), _("Smoothie"), _("Ginger"), _("Honey 250/450/900"),
    _("Photos"), "");
table_header($th);

$k = 0;
$count = 0;
$tot_cash = 0;
while ($row = db_fetch($result)) {
    alt_table_row_color($k);

    label_cell(avogs_format_dt($row['closed_at']));
    label_cell($row['real_name'] ? $row['real_name'] : ($row['closed_by'] ? $row['closed_by'] : "-"));
    label_cell($row['store_code']);
    label_cell(ucfirst($row['shift_key']));
    amount_cell($row['cash_counted']);
    qty_cell(is_null($row['avo']) ? 0 : $row['avo'], false, 0);
    amount_cell(is_null($row['h_till']) ? 0 : $row['h_till']);
    amount_cell(is_null($row['flt']) ? 0 : $row['flt']);
    qty_cell(is_null($row['juice']) ? 0 : $row['juice'], false, 0);
    qty_cell(is_null($row['smoothie']) ? 0 : $row['smoothie'], false, 0);
    qty_cell(is_null($row['ginger']) ? 0 : $row['ginger'], false, 0);
    label_cell(((int) $row['h250']) . " / " . ((int) $row['h450']) . " / " . ((int) $row['h900']), "align=center");
    label_cell((string) avogs_count_close_photos($row), "align=center");
    label_cell(avogs_view_link($path_to_root, 'avogs_checkout_view.php', $row['id']), "align=center");
    end_row();

    $tot_cash += $row['cash_counted'];
    $count++;
}

if ($count == 0) {
    label_row(_("No check-outs found for the selected period."), "", "colspan=14 align=center");
} else {
    start_row("class='inquirybg'");
    label_cell("<b>" . _("Total cash counted") . " ($count " . _("check-outs") . ")</b>", "colspan=4");
    amount_cell($tot_cash);
    label_cell("&nbsp;", "colspan=9");
    end_row();
}

end_table(1);
div_end();

end_page();
