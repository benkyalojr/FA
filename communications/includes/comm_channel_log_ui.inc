<?php
/**********************************************************************
    Communications - shared single-channel delivery log screen, used by
    both the SMS and Email log inquiries (and WhatsApp's, if that gets
    added later) rather than duplicating the same filters/table three
    times - the only thing that differs between them is the channel.
***********************************************************************/

function comm_channel_log_party_types()
{
    return array(
        '' => _('-- Any --'),
        'supplier' => _('Supplier / Farmer'),
        'customer' => _('Customer'),
        'employee' => _('Employee'),
        'user' => _('System User'),
    );
}

function comm_channel_log_status_labels()
{
    return array(
        'queued' => _('Queued'),
        'sent' => _('Sent'),
        'failed' => _('Failed'),
        'skipped_optout' => _('Skipped (opted out)'),
    );
}

// One log screen for the delivery log (all channels, $channel = null) and for the
// SMS / Email logs, with the New SMS / New Email compose modal.
function comm_log_screen($channel, $title)
{
    global $path_to_root, $help_context;

    include_once($path_to_root . '/includes/date_functions.inc');
    include_once($path_to_root . '/communications/includes/comm_events.inc');
    include_once($path_to_root . '/communications/includes/comm_compose.inc');

    $js = '';
    if (user_use_date_picker())
        $js .= get_js_date_picker();

    page(_($help_context = $title), false, false, '', $js);

    if (get_post('show_from') == '') $_POST['show_from'] = begin_month(Today());
    if (get_post('show_to') == '') $_POST['show_to'] = Today();

    $party_types = comm_channel_log_party_types();
    $statuses = array_merge(comm_channel_log_status_labels(), array('skipped_gate' => _('Skipped (rule off)')));
    $channels = comm_channel_catalog();
    $events = comm_event_catalog();
    $e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $can_send = $_SESSION['wa_current_user']->can_access_page('SA_COMMSEND');

    // ---- compose (New SMS / New Email)
    $compose = $_POST['compose_channel'] ?? ($_GET['compose'] ?? '');
    if (!$can_send || !in_array($compose, array('sms', 'email'), true)) $compose = '';
    if ($compose !== '' && isset($_POST['send_message'])) {
        list($ok, $msg) = comm_compose_send($compose);
        if ($ok) {
            display_notification($msg);
            $compose = '';
            foreach (array('comp_party', 'comp_to', 'comp_subject', 'comp_body') as $k) unset($_POST[$k]);
        } else
            display_error($msg);
    }

    // ---- filters
    start_form();
    ma_sales_filter_start();
    ma_sales_field(_('Date'), function () {
        date_cells(null, 'show_from');
        date_cells(null, 'show_to');
    });
    if ($channel === null) {
        ma_sales_field(_('Channel'), function () use ($channels) {
            echo array_selector('show_channel', get_post('show_channel'), array_merge(array('' => _('All')), $channels));
        });
        ma_sales_field(_('Status'), function () use ($statuses) {
            echo array_selector('show_status', get_post('show_status'), array_merge(array('' => _('All')), $statuses));
        });
    } else {
        ma_sales_field(_('Type'), function () use ($party_types) {
            echo array_selector('show_type', get_post('show_type'), $party_types);
        });
        ma_sales_field(_('Search'), function () { text_cells(null, 'show_search', get_post('show_search'), 26, 100); });
    }
    $new = array();
    if ($can_send) {
        if ($channel === null || $channel === 'sms') $new[] = array(_('New SMS'), $_SERVER['PHP_SELF'] . '?compose=sms');
        if ($channel === null || $channel === 'email') $new[] = array(_('New Email'), $_SERVER['PHP_SELF'] . '?compose=email');
    }
    ma_sales_filter_end('RefreshInquiry', _('Refresh'), $new, _('Show'));

    // ---- data
    $page_size = 50;
    $page_no = max(0, (int)get_post('page_no', 0));
    if (isset($_POST['RefreshInquiry'])) $page_no = 0;
    elseif (isset($_POST['next_page'])) $page_no++;
    elseif (isset($_POST['previous_page'])) $page_no = max(0, $page_no - 1);
    if ($channel === null) {
        $fc = get_post('show_channel') !== '' ? get_post('show_channel') : null;
        $fs = get_post('show_status') !== '' ? get_post('show_status') : null;
        $total_rows = get_comm_log_count(null, $fc, $fs, get_post('show_from'), get_post('show_to'));
        $result = get_comm_log(null, $fc, $fs, get_post('show_from'), get_post('show_to'), $page_size, $page_no * $page_size);
    } else {
        $ft = get_post('show_type') !== '' ? get_post('show_type') : null;
        $total_rows = get_comm_channel_log_count($channel, get_post('show_from'), get_post('show_to'), $ft, null, get_post('show_search'));
        $result = get_comm_channel_log($channel, get_post('show_from'), get_post('show_to'), $ft, null, get_post('show_search'), $page_size, $page_no * $page_size);
    }

    // ---- table
    $tone = array('sent' => 'paid', 'failed' => 'late', 'queued' => 'pending', 'skipped_gate' => 'pending', 'skipped_optout' => 'pending');
    $head = array(_('Date'), _('Time'), _('Party'), _('Type'));
    if ($channel === null) { $head[] = _('Channel'); $head[] = _('Event'); }
    $head = array_merge($head, array(_('Number / Address'), _('Message'), _('Status')));
    echo '<section class="ma-panel"><div class="ma-table-wrap"><table class="ma-sales-table"><thead><tr>';
    foreach ($head as $h) echo '<th>' . $e($h) . '</th>';
    echo '</tr></thead><tbody>';
    $n = 0;
    while ($row = db_fetch($result)) {
        $n++;
        $created = (string)$row['created_at'];
        $type = $row['recipient_type'];
        $name = (string)$row['recipient_name'];
        $msg = trim((string)$row['body']);
        if ($row['subject']) $msg = trim($row['subject'] . ' - ' . $msg, ' -');
        $short = function_exists('mb_strimwidth') ? mb_strimwidth($msg, 0, 90, '...') : substr($msg, 0, 90);
        $key = $row['event_key'];
        $event = isset($events[$key]) ? $events[$key]['label'] : ($key === 'manual' ? _('Manual message') : ($key === 'test_send' ? _('Test message') : $key));
        echo '<tr><td>' . $e($created !== '' ? sql2date(substr($created, 0, 10)) : '') . '</td><td>' . $e($created !== '' ? substr($created, 11, 5) : '') . '</td>'
            . '<td>' . ($name !== '' ? $e($name) : '-') . '</td><td>' . ($type ? $e($party_types[$type] ?? $type) : '-') . '</td>';
        if ($channel === null)
            echo '<td>' . $e($channels[$row['channel']] ?? $row['channel']) . '</td><td>' . $e($event) . '</td>';
        echo '<td>' . $e($row['recipient_address']) . '</td><td title="' . $e($msg) . '">' . $e($short) . '</td>'
            . '<td><span class="ma-pill ' . ($tone[$row['status']] ?? 'open') . '"' . ($row['provider_response'] ? ' title="' . $e($row['provider_response']) . '"' : '') . '>'
            . $e($statuses[$row['status']] ?? $row['status']) . '</span></td></tr>';
    }
    if (!$n) echo '<tr><td colspan="' . count($head) . '" class="ma-empty">' . $e(_('No messages match these filters.')) . '</td></tr>';
    echo '</tbody></table></div></section>';

    // ---- pager
    echo '<nav class="ma-live-pager"><span>' . $e(sprintf(_('Showing %d-%d of %d'), $total_rows ? $page_no * $page_size + 1 : 0,
        min(($page_no + 1) * $page_size, $total_rows), $total_rows)) . '</span><span class="ma-live-pager-links">';
    echo '<input type="hidden" name="page_no" value="' . (int)$page_no . '">';
    if ($page_no > 0) echo '<button class="ma-btn ma-btn-secondary" type="submit" name="previous_page" value="1">' . $e(_('Previous')) . '</button>';
    if (($page_no + 1) * $page_size < $total_rows) echo '<button class="ma-btn ma-btn-secondary" type="submit" name="next_page" value="1">' . $e(_('Next')) . '</button>';
    echo '</span></nav>';

    // ---- compose modal
    ma_modal_begin();
    if ($compose !== '') comm_compose_form($compose);
    ma_modal_end($compose === 'sms' ? _('New SMS') : _('New Email'), $compose !== '');

    end_form();
    end_page();
}

function comm_channel_log_screen($channel, $title)
{
    comm_log_screen($channel, $title);
}
