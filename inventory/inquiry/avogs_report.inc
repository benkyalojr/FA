<?php
/**********************************************************************
    AVO'Gs — shared helpers for check-in / check-out inquiry reports.
***********************************************************************/

if (!defined('AVOGS_REPORT_INC')) {
    define('AVOGS_REPORT_INC', 1);
}

// ── Data helpers ──────────────────────────────────────────────────────────

function avogs_shifts_tbl() { return TB_PREF . 'avogs_shifts'; }
function avogs_stock_lines_tbl() { return TB_PREF . 'avogs_shift_stock_lines'; }
function avogs_uploads_tbl() { return TB_PREF . 'avogs_uploads'; }
function avogs_handover_tbl() { return TB_PREF . 'avogs_handover'; }

function avogs_json_decode_row($raw)
{
    if ($raw === null || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return $decoded;
    }
    $fixed = html_entity_decode($raw, ENT_QUOTES, 'UTF-8');
    $decoded = json_decode($fixed, true);
    return is_array($decoded) ? $decoded : null;
}

function avogs_photo_slot_label($slot)
{
    static $labels = array(
        'shop_opening'    => 'Opening shop / taking over',
        'juice_station'   => 'Juice & smoothie station',
        'arrangement'     => 'Arranging of items',
        'shop_closed'     => 'Shop closed / locked up',
        'cash_count'      => 'Cash count',
        'stock_remaining' => 'Remaining stock',
    );
    return _(isset($labels[$slot]) ? $labels[$slot] : ucwords(str_replace('_', ' ', $slot)));
}

function avogs_checkin_photo_keys()
{
    return array('shop_opening', 'juice_station', 'arrangement');
}

function avogs_checkout_photo_keys()
{
    return array('shop_closed', 'cash_count', 'stock_remaining');
}

function avogs_shift_notes($row)
{
    $notes = avogs_json_decode_row(isset($row['notes']) ? $row['notes'] : null);
    return is_array($notes) ? $notes : array();
}

/** Closing photos only (from notes snapshot or known checkout slots). */
function avogs_shift_close_photos($row)
{
    $notes = avogs_shift_notes($row);
    if (!empty($notes['close_photos']) && is_array($notes['close_photos'])) {
        return avogs_photos_from_map($notes['close_photos'], 'checkout');
    }
    $decoded = avogs_json_decode_row(isset($row['photos_json']) ? $row['photos_json'] : null);
    $map = array();
    if (is_array($decoded)) {
        foreach (avogs_checkout_photo_keys() as $k) {
            if (!empty($decoded[$k])) {
                $map[$k] = $decoded[$k];
            }
        }
    }
    if (empty($map) && !empty($row['photo_ids'])) {
        $ids = avogs_json_decode_row($row['photo_ids']);
        if (is_array($ids) && avogs_shift_notes($row) === array()) {
            $slots = avogs_checkout_photo_keys();
            $i = 0;
            foreach ($ids as $uid) {
                if (is_array($uid)) continue;
                $key = isset($slots[$i]) ? $slots[$i] : ('photo_' . ($i + 1));
                $map[$key] = $uid;
                $i++;
            }
        }
    }
    return avogs_photos_from_map($map, 'checkout');
}

function avogs_shift_open_photos($row)
{
    $decoded = avogs_json_decode_row(isset($row['photos_json']) ? $row['photos_json'] : null);
    $map = array();
    if (is_array($decoded)) {
        foreach (avogs_checkin_photo_keys() as $k) {
            if (!empty($decoded[$k])) {
                $map[$k] = $decoded[$k];
            }
        }
    }
    return avogs_photos_from_map($map, 'checkin');
}

function avogs_photos_from_map($map, $type)
{
    $out = array();
    if (!is_array($map)) {
        return $out;
    }
    foreach ($map as $slot => $uploadId) {
        $e = avogs_photo_entry($slot, $uploadId);
        if ($e) {
            $out[] = $e;
        }
    }
    return $out;
}

function avogs_shift_handover_snapshot($row)
{
    $notes = avogs_shift_notes($row);
    if (!empty($notes['handover_snapshot']) && is_array($notes['handover_snapshot'])) {
        return $notes['handover_snapshot'];
    }
    return null;
}

function avogs_shift_sales_summary($row)
{
    if (empty($row['opened_at'])) {
        return array('shift_total' => 0, 'invoice_count' => 0);
    }
    $store = $row['store_code'];
    $shift = $row['shift_key'];
    $opened = $row['opened_at'];
    $closed = !empty($row['closed_at']) ? $row['closed_at'] : date('Y-m-d H:i:s');
    $tbl = avogs_shifts_tbl();
    $openedSql = db_escape($opened);
    $closedSql = db_escape($closed);
    $total = 0;
    $count = 0;
    $r = db_fetch(db_query(
        "SELECT COALESCE(SUM(total),0), COUNT(*) FROM " . TB_PREF . "avogs_sales
         WHERE store_code = " . db_escape($store) . "
           AND shift_key = " . db_escape($shift) . "
           AND trans_date >= $openedSql AND trans_date <= $closedSql",
        'shift sales'
    ));
    if ($r) {
        $total = (int) $r[0];
        $count = (int) $r[1];
    }
    return array('shift_total' => $total, 'invoice_count' => $count);
}

function avogs_report_alert($type, $message)
{
    $cls = ($type === 'warn') ? 'avogs-alert-warn' : 'avogs-alert-info';
    echo "<div class='avogs-alert $cls'>" . htmlspecialchars($message) . "</div>";
}

function avogs_handover_is_empty($h)
{
    if (!$h || !is_array($h)) {
        return true;
    }
    foreach (array('avo', 'till', 'float', 'juice', 'smoothie', 'ginger', 'h250', 'h450', 'h900') as $k) {
        $v = isset($h[$k]) ? (int) $h[$k] : 0;
        if ($v !== 0) {
            return false;
        }
    }
    return true;
}

function avogs_count_close_photos($row)
{
    return count(avogs_shift_close_photos($row));
}

function avogs_format_dt($dt)
{
    if (!$dt) return '-';
    return sql2date(substr($dt, 0, 10)) . ' ' . substr($dt, 11, 5);
}

function avogs_shift_row($shift_id)
{
    $tbl = avogs_shifts_tbl();
    return db_fetch(db_query(
        "SELECT s.*, ou.real_name AS opened_name, cu.real_name AS closed_name
         FROM $tbl s
         LEFT JOIN " . TB_PREF . "users ou ON ou.user_id = s.opened_by
         LEFT JOIN " . TB_PREF . "users cu ON cu.user_id = s.closed_by
         WHERE s.id = " . db_escape((int) $shift_id),
        'AVOGs shift detail'
    ));
}

function avogs_handover_for_shift($store_code, $shift_key)
{
    return db_fetch(db_query(
        "SELECT * FROM " . avogs_handover_tbl() . "
         WHERE store_code = " . db_escape($store_code) . "
           AND shift_key = " . db_escape($shift_key),
        'handover lookup'
    ));
}

function avogs_handover_after_close($store_code, $shift_key)
{
    $next = ($shift_key === 'morning') ? 'evening' : 'morning';
    return db_fetch(db_query(
        "SELECT * FROM " . avogs_handover_tbl() . "
         WHERE store_code = " . db_escape($store_code) . "
           AND shift_key = " . db_escape($next),
        'handover after close'
    ));
}

function avogs_stock_lines($shift_id)
{
    $lines = array();
    $res = db_query(
        "SELECT * FROM " . avogs_stock_lines_tbl() . "
         WHERE shift_id = " . db_escape((int) $shift_id) . " ORDER BY id",
        'stock lines'
    );
    while ($r = db_fetch($res)) $lines[] = $r;
    return $lines;
}

function avogs_lookup_upload($upload_id)
{
    return db_fetch(db_query(
        "SELECT upload_id, url, path FROM " . avogs_uploads_tbl() . "
         WHERE upload_id = " . db_escape($upload_id),
        'upload lookup'
    ));
}

function avogs_shift_photos($row)
{
    $out = array();
    if (!empty($row['photos_json'])) {
        $decoded = avogs_json_decode_row($row['photos_json']);
        if (is_array($decoded)) {
            foreach ($decoded as $slot => $uploadId) {
                if (is_array($uploadId)) {
                    foreach ($uploadId as $uid) {
                        $e = avogs_photo_entry($slot, $uid);
                        if ($e) $out[] = $e;
                    }
                } else {
                    $e = avogs_photo_entry($slot, $uploadId);
                    if ($e) $out[] = $e;
                }
            }
        }
    }
    if (empty($out) && !empty($row['photo_ids'])) {
        $decoded = avogs_json_decode_row($row['photo_ids']);
        if (is_array($decoded)) {
            $i = 1;
            foreach ($decoded as $uploadId) {
                if (is_array($uploadId)) continue;
                $e = avogs_photo_entry('photo_' . $i, $uploadId);
                if ($e) $out[] = $e;
                $i++;
            }
        }
    }
    return $out;
}

function avogs_photo_entry($slot, $uploadId)
{
    $uploadId = trim((string) $uploadId);
    if ($uploadId === '') return null;
    $u = avogs_lookup_upload($uploadId);
    return array(
        'slot'      => $slot,
        'label'     => avogs_photo_slot_label($slot),
        'upload_id' => $uploadId,
        'url'       => ($u && !empty($u['url'])) ? $u['url'] : '',
    );
}

function avogs_count_photos($row) { return count(avogs_shift_photos($row)); }

function avogs_has_comments($row)
{
    return (!empty($row['calls_deliveries']) || !empty($row['pending_orders']));
}

function avogs_inquiry_icon($yes)
{
    return $yes
        ? "<span class='avogs-ico-yes' title='" . _('Yes') . "'>&#10003;</span>"
        : "<span class='avogs-ico-no' title='" . _('No') . "'>&mdash;</span>";
}

function avogs_flag_html($flagged, $ok_text = null, $bad_text = null)
{
    if ($ok_text === null) $ok_text = _('OK');
    if ($bad_text === null) $bad_text = _('Flagged');
    if ($flagged) {
        return "<span style='color:#dc2626;font-weight:700;'>" . $bad_text . "</span>";
    }
    return "<span style='color:#16a34a;font-weight:600;'>" . $ok_text . "</span>";
}

function avogs_view_link($path_to_root, $script, $shift_id, $label = null)
{
    if ($label === null) $label = _('View report');
    $url = $path_to_root . '/inventory/inquiry/' . $script . '?shift_id=' . (int) $shift_id;
    return '<a class="avogs-link" href="' . htmlspecialchars($url) . '">' . $label . '</a>';
}

// ── Presentation ──────────────────────────────────────────────────────────

function avogs_report_styles()
{
    return <<<'CSS'
<style type="text/css">
/* ── AVO'Gs shift report ─────────────────────────────────────────────── */
.avogs-rpt { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #1a1a2e; max-width: 1180px; margin: 0 auto 32px; line-height: 1.5; }
.avogs-rpt * { box-sizing: border-box; }

/* Toolbar */
.avogs-rpt-toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; gap: 12px; flex-wrap: wrap; }
.avogs-rpt-toolbar a { color: #4361ee; text-decoration: none; font-size: 13px; font-weight: 500; }
.avogs-rpt-toolbar a:hover { text-decoration: underline; }
.avogs-rpt-actions { display: flex; gap: 8px; }
.avogs-btn { display: inline-block; padding: 7px 16px; font-size: 12px; font-weight: 600; border-radius: 6px; border: 1px solid #ccd2d8; background: #fff; color: #333; cursor: pointer; text-decoration: none !important; }
.avogs-btn:hover { background: #f4f6f8; }
.avogs-btn-primary { background: #4361ee; color: #fff !important; border-color: #4361ee; }
.avogs-btn-primary:hover { background: #3a56d4; }

/* Masthead */
.avogs-rpt-head { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%); color: #fff; border-radius: 10px; padding: 28px 32px 24px; margin-bottom: 20px; box-shadow: 0 4px 20px rgba(26,26,46,.25); }
.avogs-rpt-head.checkin  { border-left: 5px solid #2ecc71; }
.avogs-rpt-head.checkout { border-left: 5px solid #e67e22; }
.avogs-rpt-badge { display: inline-block; font-size: 10px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; background: rgba(255,255,255,.15); padding: 4px 10px; border-radius: 20px; margin-bottom: 10px; }
.avogs-rpt-title { font-size: 26px; font-weight: 700; margin: 0 0 4px; letter-spacing: -.02em; }
.avogs-rpt-subtitle { font-size: 14px; opacity: .75; margin: 0 0 20px; }
.avogs-rpt-meta { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 16px; padding-top: 18px; border-top: 1px solid rgba(255,255,255,.15); }
.avogs-rpt-meta-item .k { font-size: 10px; text-transform: uppercase; letter-spacing: .08em; opacity: .6; margin-bottom: 2px; }
.avogs-rpt-meta-item .v { font-size: 15px; font-weight: 600; }
.avogs-rpt-pills { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 16px; }
.avogs-pill { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 600; padding: 5px 12px; border-radius: 20px; }
.avogs-pill-ok   { background: rgba(46,204,113,.2); color: #a8f0c8; }
.avogs-pill-warn { background: rgba(231,76,60,.25); color: #ffb3aa; }
.avogs-pill-info { background: rgba(67,97,238,.3); color: #b8c9ff; }
.avogs-pill-neutral { background: rgba(255,255,255,.12); color: #ddd; }

/* KPI strip */
.avogs-kpi-row { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; margin-bottom: 24px; }
.avogs-kpi { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
.avogs-kpi .k { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: #64748b; margin-bottom: 6px; }
.avogs-kpi .v { font-size: 22px; font-weight: 700; color: #1a1a2e; }
.avogs-kpi .v.ok   { color: #16a34a; }
.avogs-kpi .v.warn { color: #dc2626; }
.avogs-kpi .s { font-size: 11px; color: #94a3b8; margin-top: 4px; }

/* Sections */
.avogs-section { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; margin-bottom: 20px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.04); }
.avogs-section-hd { background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 14px 20px; display: flex; align-items: baseline; justify-content: space-between; gap: 12px; }
.avogs-section-hd h2 { margin: 0; font-size: 16px; font-weight: 700; color: #1a1a2e; }
.avogs-section-hd p  { margin: 2px 0 0; font-size: 12px; color: #64748b; }
.avogs-section-bd { padding: 0; }

/* Tables */
.avogs-tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.avogs-tbl thead th { background: #1a1a2e; color: #fff; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; padding: 11px 14px; text-align: left; white-space: nowrap; }
.avogs-tbl thead th.num { text-align: right; }
.avogs-tbl tbody td { padding: 10px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.avogs-tbl tbody tr:nth-child(even) td { background: #f8fafc; }
.avogs-tbl tbody tr:hover td { background: #eef2ff; }
.avogs-tbl tbody td.num { text-align: right; font-variant-numeric: tabular-nums; }
.avogs-tbl tfoot td { padding: 11px 14px; background: #f1f5f9; font-weight: 700; border-top: 2px solid #cbd5e1; }
.avogs-tbl tfoot td.num { text-align: right; }
.avogs-tbl .code { font-family: ui-monospace, monospace; font-size: 12px; color: #4361ee; }
.avogs-tbl .var-ok   { color: #16a34a; }
.avogs-tbl .var-bad  { color: #dc2626; font-weight: 700; }
.avogs-tbl-empty { padding: 32px 20px; text-align: center; color: #94a3b8; font-style: italic; }

/* Comments grid */
.avogs-notes-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; padding: 20px; }
@media (max-width: 700px) { .avogs-notes-grid { grid-template-columns: 1fr; } }
.avogs-note { border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
.avogs-note-hd { background: #f8fafc; padding: 10px 14px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #475569; border-bottom: 1px solid #e2e8f0; }
.avogs-note-bd { padding: 14px; font-size: 13px; white-space: pre-wrap; min-height: 60px; color: #334155; }
.avogs-note-bd.empty { color: #94a3b8; font-style: italic; }

/* Photos */
.avogs-photos { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; padding: 20px; }
.avogs-photo { border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; background: #fff; transition: box-shadow .2s; }
.avogs-photo:hover { box-shadow: 0 6px 20px rgba(0,0,0,.1); }
.avogs-photo a { display: block; line-height: 0; background: #f1f5f9; }
.avogs-photo img { width: 100%; height: 200px; object-fit: cover; }
.avogs-photo-cap { padding: 12px 14px; }
.avogs-photo-cap strong { display: block; font-size: 13px; color: #1a1a2e; margin-bottom: 2px; }
.avogs-photo-cap span { font-size: 11px; color: #94a3b8; font-family: ui-monospace, monospace; }
.avogs-photos-empty { padding: 32px 20px; text-align: center; color: #94a3b8; font-style: italic; }

/* Related link bar */
.avogs-related { background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 8px; padding: 12px 18px; margin-bottom: 20px; font-size: 13px; color: #3730a3; }
.avogs-related a { color: #4361ee; font-weight: 600; }

/* Inquiry list helpers */
.avogs-alert { padding: 12px 16px; border-radius: 8px; margin: 0 0 16px; font-size: 13px; line-height: 1.45; }
.avogs-alert-warn { background: #fff8e6; border: 1px solid #f0c040; color: #7a5c00; }
.avogs-alert-info { background: #eef2ff; border: 1px solid #c7d2fe; color: #3730a3; }
.avogs-ico-no  { color: #cbd5e1; }
.avogs-link { font-weight: 600; }

/* Print */
@media print {
    .avogs-rpt-toolbar, .avogs-btn { display: none !important; }
    .avogs-rpt-head { background: #1a1a2e !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .avogs-section { break-inside: avoid; }
    .avogs-tbl thead th { background: #333 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
CSS;
}

function avogs_report_begin($type, $title, $subtitle, $back_url, $back_label = null, $meta = array(), $pills = array())
{
    if ($back_label === null) $back_label = _('Back to inquiry');
    $type_class = ($type === 'checkout') ? 'checkout' : 'checkin';
    echo avogs_report_styles();
    echo "<div class='avogs-rpt'>";
    echo "<div class='avogs-rpt-toolbar'>";
    echo "<a href='" . htmlspecialchars($back_url) . "'>&larr; " . htmlspecialchars($back_label) . "</a>";
    echo "<div class='avogs-rpt-actions'>";
    echo "<button type='button' class='avogs-btn' onclick='window.print()'>" . _('Print report') . "</button>";
    echo "</div></div>";
    echo "<div class='avogs-rpt-head $type_class'>";
    $badge = ($type === 'checkout') ? _('Check-out') : _('Check-in');
    echo "<div class='avogs-rpt-badge'>" . $badge . "</div>";
    echo "<h1 class='avogs-rpt-title'>" . htmlspecialchars($title) . "</h1>";
    if ($subtitle) {
        echo "<p class='avogs-rpt-subtitle'>" . htmlspecialchars($subtitle) . "</p>";
    }
    if (!empty($meta)) {
        avogs_report_meta($meta);
    }
    if (!empty($pills)) {
        avogs_report_pills($pills);
    }
    echo "</div>";
}

function avogs_report_meta($items)
{
    echo "<div class='avogs-rpt-meta'>";
    foreach ($items as $item) {
        echo "<div class='avogs-rpt-meta-item'>";
        echo "<div class='k'>" . htmlspecialchars($item['label']) . "</div>";
        echo "<div class='v'>" . $item['value'] . "</div>";
        echo "</div>";
    }
    echo "</div>";
}

function avogs_report_pills($pills)
{
    if (empty($pills)) return;
    echo "<div class='avogs-rpt-pills'>";
    foreach ($pills as $p) {
        $cls = isset($p['type']) ? $p['type'] : 'neutral';
        echo "<span class='avogs-pill avogs-pill-$cls'>" . $p['text'] . "</span>";
    }
    echo "</div>";
}

function avogs_report_end()
{
    echo "</div>";
}

function avogs_report_kpi_row($items)
{
    echo "<div class='avogs-kpi-row'>";
    foreach ($items as $item) {
        $cls = isset($item['class']) ? $item['class'] : '';
        echo "<div class='avogs-kpi'>";
        echo "<div class='k'>" . htmlspecialchars($item['label']) . "</div>";
        echo "<div class='v $cls'>" . $item['value'] . "</div>";
        if (!empty($item['sub'])) {
            echo "<div class='s'>" . htmlspecialchars($item['sub']) . "</div>";
        }
        echo "</div>";
    }
    echo "</div>";
}

function avogs_report_section_open($title, $subtitle = '')
{
    echo "<div class='avogs-section'>";
    echo "<div class='avogs-section-hd'>";
    echo "<div><h2>" . htmlspecialchars($title) . "</h2>";
    if ($subtitle) echo "<p>" . htmlspecialchars($subtitle) . "</p>";
    echo "</div></div>";
    echo "<div class='avogs-section-bd'>";
}

function avogs_report_section_close()
{
    echo "</div></div>";
}

function avogs_report_table($headers, $rows, $footer = null)
{
    echo "<table class='avogs-tbl'><thead><tr>";
    foreach ($headers as $h) {
        $cls = !empty($h['class']) ? " class='" . $h['class'] . "'" : '';
        echo "<th$cls>" . htmlspecialchars($h['label']) . "</th>";
    }
    echo "</tr></thead><tbody>";
    if (empty($rows)) {
        echo "<tr><td colspan='" . count($headers) . "' class='avogs-tbl-empty'>" . _('No data recorded.') . "</td></tr>";
    } else {
        foreach ($rows as $row) {
            echo "<tr>";
            foreach ($row as $cell) {
                $cls = !empty($cell['class']) ? " class='" . $cell['class'] . "'" : '';
                echo "<td$cls>" . $cell['html'] . "</td>";
            }
            echo "</tr>";
        }
    }
    echo "</tbody>";
    if ($footer) {
        echo "<tfoot><tr>";
        foreach ($footer as $cell) {
            $cls = !empty($cell['class']) ? " class='" . $cell['class'] . "'" : '';
            echo "<td$cls>" . $cell['html'] . "</td>";
        }
        echo "</tr></tfoot>";
    }
    echo "</table>";
}

function avogs_report_notes($notes)
{
    echo "<div class='avogs-notes-grid'>";
    foreach ($notes as $n) {
        $text = isset($n['text']) ? trim($n['text']) : '';
        echo "<div class='avogs-note'>";
        echo "<div class='avogs-note-hd'>" . htmlspecialchars($n['title']) . "</div>";
        if ($text !== '') {
            echo "<div class='avogs-note-bd'>" . nl2br(htmlspecialchars($text)) . "</div>";
        } else {
            echo "<div class='avogs-note-bd empty'>" . _('None recorded.') . "</div>";
        }
        echo "</div>";
    }
    echo "</div>";
}

function avogs_report_photos($photos)
{
    if (empty($photos)) {
        echo "<div class='avogs-photos-empty'>" . _('No photos attached.') . "</div>";
        return;
    }
    echo "<div class='avogs-photos'>";
    foreach ($photos as $p) {
        if (!$p || empty($p['upload_id'])) continue;
        $label = htmlspecialchars($p['label']);
        $uid   = htmlspecialchars($p['upload_id']);
        echo "<div class='avogs-photo'>";
        if (!empty($p['url'])) {
            $url = htmlspecialchars($p['url']);
            echo "<a href='$url' target='_blank' title='" . _('Open full size') . "'>";
            echo "<img src='$url' alt='$label' loading='lazy'>";
            echo "</a>";
        } else {
            echo "<div style='height:200px;display:flex;align-items:center;justify-content:center;color:#94a3b8;'>" . _('Image unavailable') . "</div>";
        }
        echo "<div class='avogs-photo-cap'><strong>$label</strong><span>$uid</span></div>";
        echo "</div>";
    }
    echo "</div>";
}

function avogs_report_related($html)
{
    echo "<div class='avogs-related'>$html</div>";
}

function avogs_pill_status($open)
{
    return array(
        'type' => $open ? 'info' : 'neutral',
        'text' => $open ? _('Shift open') : _('Shift closed'),
    );
}

function avogs_pill_check($label, $flagged)
{
    return array(
        'type' => $flagged ? 'warn' : 'ok',
        'text' => $label . ': ' . ($flagged ? _('Flagged') : _('OK')),
    );
}

function avogs_kpi_flag($flagged)
{
    return array(
        'class' => $flagged ? 'warn' : 'ok',
        'value' => $flagged ? _('Flagged') : _('OK'),
    );
}
