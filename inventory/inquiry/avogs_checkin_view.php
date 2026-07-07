<?php
/**********************************************************************
    AVO'Gs — Shift Check-in (Opening) Report
***********************************************************************/
$page_security = 'SA_ITEMSTRANSVIEW';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/date_functions.inc");
include_once($path_to_root . "/includes/ui.inc");
include_once(__DIR__ . "/avogs_report.inc");

$shift_id = (int) get_post('shift_id', isset($_GET['shift_id']) ? $_GET['shift_id'] : 0);
if (!$shift_id) {
    page(_("Check-in Report"), true, false, "", "");
    display_error(_("No check-in selected."));
    end_page();
    exit;
}

$shift = avogs_shift_row($shift_id);
if (!$shift) {
    page(_("Check-in Report"), true, false, "", "");
    display_error(_("Check-in not found."));
    end_page();
    exit;
}

$back_url   = $path_to_root . "/inventory/inquiry/avogs_checkin_inquiry.php";
$handover   = avogs_handover_for_shift($shift['store_code'], $shift['shift_key']);
$lines      = avogs_stock_lines($shift_id);
$photos     = avogs_shift_photos($shift);
$opened_by  = $shift['opened_name'] ? $shift['opened_name'] : $shift['opened_by'];
$shift_label = ucfirst($shift['shift_key']) . ' ' . _('shift');

$_SESSION['page_title'] = _($help_context = "Check-in Report #" . $shift_id);
page($_SESSION['page_title'], true, false, "", "");

// ── Report shell ──────────────────────────────────────────────────────────
avogs_report_begin(
    'checkin',
    _("Open Shop Report") . " #" . $shift_id,
    $shift_label . ' — ' . $shift['store_code'] . ' — ' . avogs_format_dt($shift['opened_at']),
    $back_url,
    _('Back to Check-in Inquiry'),
    array(
        array('label' => _('Store'),     'value' => htmlspecialchars($shift['store_code'])),
        array('label' => _('Shift'),      'value' => ucfirst($shift['shift_key'])),
        array('label' => _('Opened'),     'value' => avogs_format_dt($shift['opened_at'])),
        array('label' => _('Opened by'),  'value' => htmlspecialchars($opened_by)),
        array('label' => _('Report ID'), 'value' => '#' . $shift_id),
    ),
    array(
        avogs_pill_status($shift['status'] == 'open'),
        avogs_pill_check(_('Stock'), $shift['stock_discrepancy']),
        avogs_pill_check(_('Cash'), $shift['cash_discrepancy']),
        array('type' => 'neutral', 'text' => count($lines) . ' ' . _('items counted')),
        array('type' => 'neutral', 'text' => count($photos) . ' ' . _('photos')),
    )
);

if ($shift['status'] == 'closed' && $shift['closed_at']) {
    $close_url = $path_to_root . "/inventory/inquiry/avogs_checkout_view.php?shift_id=" . (int) $shift_id;
    avogs_report_related(
        _('This shift was closed') . ' ' . avogs_format_dt($shift['closed_at'])
        . ' &mdash; <a href="' . htmlspecialchars($close_url) . '">' . _('View check-out report') . '</a>'
    );
}

// ── KPIs ──────────────────────────────────────────────────────────────────
$stock_kpi = avogs_kpi_flag($shift['stock_discrepancy']);
$cash_kpi  = avogs_kpi_flag($shift['cash_discrepancy']);
avogs_report_kpi_row(array(
    array('label' => _('Avocado pcs'), 'value' => number_format2($shift['opening_stock'], 0)),
    array('label' => _('Opening till'), 'value' => price_format($shift['opening_till'])),
    array('label' => _('Opening float'), 'value' => price_format($shift['opening_float'])),
    array('label' => _('Stock check'), 'value' => $stock_kpi['value'], 'class' => $stock_kpi['class']),
    array('label' => _('Cash check'),  'value' => $cash_kpi['value'],  'class' => $cash_kpi['class']),
    array('label' => _('Line items'),  'value' => count($lines)),
));

// ── Cash ──────────────────────────────────────────────────────────────────
$exp_till  = $handover ? (int) $handover['till'] : 0;
$exp_float = $handover ? (int) $handover['flt'] : 0;
$act_till  = (int) $shift['opening_till'];
$act_float = (int) $shift['opening_float'];
$vt = $act_till - $exp_till;
$vf = $act_float - $exp_float;

avogs_report_section_open(_('Cash Confirmation'), _('Expected handover vs actual count at opening'));
avogs_report_table(
    array(
        array('label' => _('Item')),
        array('label' => _('Expected'), 'class' => 'num'),
        array('label' => _('Actual'),   'class' => 'num'),
        array('label' => _('Variance'), 'class' => 'num'),
    ),
    array(
        array(
            array('html' => _('Till')),
            array('html' => price_format($exp_till),  'class' => 'num'),
            array('html' => price_format($act_till),  'class' => 'num'),
            array('html' => price_format($vt, 0),     'class' => 'num ' . (abs($vt) > 0 ? 'var-bad' : 'var-ok')),
        ),
        array(
            array('html' => _('Float')),
            array('html' => price_format($exp_float), 'class' => 'num'),
            array('html' => price_format($act_float), 'class' => 'num'),
            array('html' => price_format($vf, 0),     'class' => 'num ' . (abs($vf) > 0 ? 'var-bad' : 'var-ok')),
        ),
    )
);
avogs_report_section_close();

// ── Stock ─────────────────────────────────────────────────────────────────
$stock_rows = array();
$tot_exp = 0;
$tot_act = 0;
$variance_count = 0;
foreach ($lines as $line) {
    $var = (float) $line['actual_qty'] - (float) $line['expected_qty'];
    if (abs($var) > 0.0001) $variance_count++;
    $tot_exp += (float) $line['expected_qty'];
    $tot_act += (float) $line['actual_qty'];
    $stock_rows[] = array(
        array('html' => '<span class="code">' . htmlspecialchars($line['stock_id']) . '</span>'),
        array('html' => htmlspecialchars($line['description'])),
        array('html' => htmlspecialchars($line['units'])),
        array('html' => number_format2($line['expected_qty'], 2), 'class' => 'num'),
        array('html' => number_format2($line['actual_qty'], 2),   'class' => 'num'),
        array('html' => number_format2($var, 2), 'class' => 'num ' . (abs($var) > 0.0001 ? 'var-bad' : 'var-ok')),
    );
}

avogs_report_section_open(
    _('Stock Confirmation'),
    $variance_count > 0
        ? sprintf(_('%d item(s) with variance'), $variance_count)
        : _('All items match expected quantities')
);
avogs_report_table(
    array(
        array('label' => _('Stock ID')),
        array('label' => _('Description')),
        array('label' => _('Units')),
        array('label' => _('Expected'), 'class' => 'num'),
        array('label' => _('Actual'),   'class' => 'num'),
        array('label' => _('Variance'), 'class' => 'num'),
    ),
    $stock_rows,
    empty($lines) ? null : array(
        array('html' => '<strong>' . _('Totals') . '</strong>', 'class' => ''),
        array('html' => '', 'class' => ''),
        array('html' => '', 'class' => ''),
        array('html' => number_format2($tot_exp, 2), 'class' => 'num'),
        array('html' => number_format2($tot_act, 2), 'class' => 'num'),
        array('html' => number_format2($tot_act - $tot_exp, 2), 'class' => 'num'),
    )
);
avogs_report_section_close();

// ── Comments ──────────────────────────────────────────────────────────────
avogs_report_section_open(_('Operational Notes'), _('Calls, deliveries and pending orders'));
avogs_report_notes(array(
    array('title' => _('Calls & deliveries'), 'text' => $shift['calls_deliveries']),
    array('title' => _('Pending orders'),     'text' => $shift['pending_orders']),
));
avogs_report_section_close();

// ── Photos ────────────────────────────────────────────────────────────────
avogs_report_section_open(_('Photo Evidence'), sprintf(_('%d photo(s) captured at opening'), count($photos)));
avogs_report_photos($photos);
avogs_report_section_close();

avogs_report_end();
end_page();
