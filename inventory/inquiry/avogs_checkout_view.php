<?php
/**********************************************************************
    AVO'Gs — Shift Check-out (Closing) Report
***********************************************************************/
$page_security = 'SA_ITEMSTRANSVIEW';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/date_functions.inc");
include_once($path_to_root . "/includes/ui.inc");
include_once(__DIR__ . "/avogs_report.inc");

$shift_id = (int) get_post('shift_id', isset($_GET['shift_id']) ? $_GET['shift_id'] : 0);
if (!$shift_id) {
    page(_("Check-out Report"), true, false, "", "");
    display_error(_("No check-out selected."));
    end_page();
    exit;
}

$shift = avogs_shift_row($shift_id);
if (!$shift) {
    page(_("Check-out Report"), true, false, "", "");
    display_error(_("Shift not found."));
    end_page();
    exit;
}

if ($shift['status'] != 'closed') {
    page(_("Check-out Report"), true, false, "", "");
    display_error(_("This shift is still open — check-out report is available after close."));
    echo "<p><a href='" . $path_to_root . "/inventory/inquiry/avogs_checkin_view.php?shift_id="
        . (int) $shift_id . "'>" . _('View check-in report') . "</a></p>";
    end_page();
    exit;
}

$back_url     = $path_to_root . "/inventory/inquiry/avogs_checkout_inquiry.php";
$checkin_url  = $path_to_root . "/inventory/inquiry/avogs_checkin_view.php?shift_id=" . (int) $shift_id;
$closed_by    = $shift['closed_name'] ? $shift['closed_name'] : $shift['closed_by'];
$next_shift   = ($shift['shift_key'] === 'morning') ? _('Evening') : _('Morning');
$shift_label  = ucfirst($shift['shift_key']) . ' ' . _('shift');

$notes          = avogs_shift_notes($shift);
$handover       = avogs_shift_handover_snapshot($shift);
$handover_live  = avogs_handover_after_close($shift['store_code'], $shift['shift_key']);
if (!$handover && $handover_live) {
    $handover = array(
        'next_shift' => ($shift['shift_key'] === 'morning') ? 'evening' : 'morning',
        'avo' => (int) $handover_live['avo'],
        'till' => (int) $handover_live['till'],
        'float' => (int) $handover_live['flt'],
        'juice' => (int) $handover_live['juice'],
        'smoothie' => (int) $handover_live['smoothie'],
        'ginger' => (int) $handover_live['ginger'],
        'h250' => (int) $handover_live['h250'],
        'h450' => (int) $handover_live['h450'],
        'h900' => (int) $handover_live['h900'],
        '_estimated' => true,
    );
}
$close_photos   = avogs_shift_close_photos($shift);
$open_photos    = avogs_shift_open_photos($shift);
$sales          = avogs_shift_sales_summary($shift);

$duration = '';
if ($shift['opened_at'] && $shift['closed_at']) {
    $secs = strtotime($shift['closed_at']) - strtotime($shift['opened_at']);
    if ($secs > 0) {
        $hrs = floor($secs / 3600);
        $mins = floor(($secs % 3600) / 60);
        $duration = $hrs . 'h ' . $mins . 'm';
    }
}

$_SESSION['page_title'] = _($help_context = "Check-out Report #" . $shift_id);
page($_SESSION['page_title'], true, false, "", "");

avogs_report_begin(
    'checkout',
    _("Close Shop Report") . " #" . $shift_id,
    $shift_label . ' — ' . $shift['store_code'],
    $back_url,
    _('Back to Check-out Inquiry'),
    array(
        array('label' => _('Store'),      'value' => htmlspecialchars($shift['store_code'])),
        array('label' => _('Shift'),       'value' => ucfirst($shift['shift_key'])),
        array('label' => _('Opened'),      'value' => avogs_format_dt($shift['opened_at'])),
        array('label' => _('Closed'),      'value' => avogs_format_dt($shift['closed_at'])),
        array('label' => _('Duration'),    'value' => $duration ? $duration : '-'),
        array('label' => _('Closed by'),   'value' => htmlspecialchars($closed_by)),
        array('label' => _('Handover to'), 'value' => $next_shift),
    ),
    array(
        array('type' => 'info',    'text' => price_format($shift['cash_counted']) . ' ' . _('cash counted')),
        array('type' => 'neutral', 'text' => count($close_photos) . ' ' . _('closing photos')),
        array('type' => 'neutral', 'text' => price_format($sales['shift_total']) . ' ' . _('shift sales')),
    )
);

avogs_report_related(
    '<a href="' . htmlspecialchars($checkin_url) . '">' . _('View opening check-in report') . '</a>'
    . ' — ' . _('till') . ' ' . price_format($shift['opening_till'])
    . ', ' . _('float') . ' ' . price_format($shift['opening_float'])
    . ', ' . (int) $shift['opening_stock'] . ' ' . _('avo pcs at open')
);

if ((int) $shift['cash_counted'] === 0) {
    avogs_report_alert('warn', _('Cash counted is zero — this close may have been submitted without a cash count.'));
}
if (avogs_handover_is_empty($handover)) {
    avogs_report_alert('warn', _('No handover stock/cash was recorded for the next shift.'));
}
if (!empty($handover['_estimated'])) {
    avogs_report_alert('info', _('Handover figures shown from the live handover table (this close was saved before snapshots). They may reflect a later close if another shift closed since.'));
}

avogs_report_kpi_row(array(
    array('label' => _('Cash counted'),     'value' => price_format($shift['cash_counted'])),
    array('label' => _('Opening till'),     'value' => price_format($shift['opening_till'])),
    array('label' => _('Opening float'),    'value' => price_format($shift['opening_float'])),
    array('label' => _('Shift sales'),      'value' => price_format($sales['shift_total']), 'sub' => $sales['invoice_count'] . ' ' . _('invoices')),
    array('label' => _('Handover avo'),     'value' => $handover ? number_format2($handover['avo'], 0) . ' pcs' : '-'),
    array('label' => _('Next till / float'), 'value' => $handover ? price_format($handover['till']) . ' / ' . price_format($handover['float']) : '-'),
));

// ── Cash at close ─────────────────────────────────────────────────────────
avogs_report_section_open(_('Cash at Close'), _('Total cash in hand when the shop was closed'));
avogs_report_table(
    array(
        array('label' => _('Item')),
        array('label' => _('Amount'), 'class' => 'num'),
    ),
    array(
        array(
            array('html' => '<strong>' . _('Cash counted at close') . '</strong>'),
            array('html' => '<strong>' . price_format($shift['cash_counted']) . '</strong>', 'class' => 'num'),
        ),
        array(
            array('html' => _('Opening till (from check-in)')),
            array('html' => price_format($shift['opening_till']), 'class' => 'num'),
        ),
        array(
            array('html' => _('Opening float (from check-in)')),
            array('html' => price_format($shift['opening_float']), 'class' => 'num'),
        ),
        array(
            array('html' => _('Sales recorded this shift')),
            array('html' => price_format($sales['shift_total']), 'class' => 'num'),
        ),
    )
);
avogs_report_section_close();

// ── Handover ──────────────────────────────────────────────────────────────
$ho_rows = array();
if ($handover && !avogs_handover_is_empty($handover)) {
    $ho_items = array(
        array(_('Remaining avocados (pcs)'), number_format2($handover['avo'], 0)),
        array(_('Till for next shift'), price_format($handover['till'])),
        array(_('Float for next shift'), price_format($handover['float'])),
        array(_('Juice bottles'), number_format2($handover['juice'], 0)),
        array(_('Smoothie bottles'), number_format2($handover['smoothie'], 0)),
        array(_('Ginger bottles'), number_format2($handover['ginger'], 0)),
        array(_('Honey 250g'), number_format2($handover['h250'], 0)),
        array(_('Honey 450g'), number_format2($handover['h450'], 0)),
        array(_('Honey 900g'), number_format2($handover['h900'], 0)),
    );
    foreach ($ho_items as $item) {
        $ho_rows[] = array(
            array('html' => $item[0]),
            array('html' => $item[1], 'class' => 'num'),
        );
    }
}

avogs_report_section_open(
    _('Handover to') . ' ' . $next_shift,
    _('Stock and cash left for the incoming shift')
);
avogs_report_table(
    array(
        array('label' => _('Item')),
        array('label' => _('Quantity / Amount'), 'class' => 'num'),
    ),
    $ho_rows
);
avogs_report_section_close();

// ── Closing notes ─────────────────────────────────────────────────────────
avogs_report_section_open(_('Closing Notes'), _('Wastage and other notes from the close checklist'));
$note_items = array();
if (!empty($notes['wastage'])) {
    $note_items[] = array('title' => _('Wastage'), 'text' => $notes['wastage']);
}
if (!empty($notes['closing_notes'])) {
    $note_items[] = array('title' => _('Closing notes'), 'text' => $notes['closing_notes']);
}
if (!empty($note_items)) {
    avogs_report_notes($note_items);
} else {
    echo "<div class='avogs-photos-empty'>" . _('None recorded.') . "</div>";
}
avogs_report_section_close();

// ── Closing photos ────────────────────────────────────────────────────────
avogs_report_section_open(
    _('Closing Photos'),
    sprintf(_('%d photo(s) taken at close'), count($close_photos))
);
if (empty($close_photos)) {
    echo "<div class='avogs-photos-empty'>" . _('No closing photos were attached.') . "</div>";
    if (!empty($open_photos)) {
        echo "<p class='avogs-muted'>" . _('Opening photos from check-in are on the') . " "
            . "<a href='" . htmlspecialchars($checkin_url) . "'>" . _('check-in report') . "</a>.</p>";
    }
} else {
    avogs_render_photos_grid($close_photos);
}
avogs_report_section_close();

avogs_report_end();
end_page();
