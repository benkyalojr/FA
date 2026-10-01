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

function comm_channel_log_screen($channel, $title)
{
    global $path_to_root, $help_context;

    include_once($path_to_root . '/includes/date_functions.inc');

    $js = '';
    if (user_use_date_picker())
        $js .= get_js_date_picker();

    page(_($help_context = $title), false, false, '', $js);

    if (get_post('show_from') == '') $_POST['show_from'] = begin_month(Today());
    if (get_post('show_to') == '') $_POST['show_to'] = Today();

    $party_types = comm_channel_log_party_types();
    $statuses = comm_channel_log_status_labels();

    start_form();
    start_table(TABLESTYLE_NOBORDER);
    start_row();
    date_cells(_('From:'), 'show_from');
    date_cells(_('To:'), 'show_to');
    echo "<td>" . _('Type:') . "</td><td>";
    echo array_selector('show_type', get_post('show_type'), $party_types);
    echo "</td>";
    end_row();
    start_row();
    echo "<td>" . _('Party:') . "</td><td colspan=3>";
    echo comm_channel_party_picker($channel, 'show_party', get_post('show_party'));
    echo "</td>";
    end_row();
    start_row();
    text_cells(_('Search:'), 'show_search', get_post('show_search'), 30, 100);
    submit_cells('RefreshInquiry', _('Show'), '', _('Refresh'), 'default');
    end_row();
    end_table();

    list($party_type, $party_id) = array_pad(explode(':', (string)get_post('show_party'), 2), 2, null);
	$page_size = 50;
	$page_no = max(0, (int)get_post('page_no', 0));
	if (isset($_POST['RefreshInquiry'])) $page_no = 0;
	elseif (isset($_POST['next_page'])) $page_no++;
	elseif (isset($_POST['previous_page'])) $page_no = max(0, $page_no - 1);
	$filter_type = get_post('show_type') !== '' ? get_post('show_type') : null;
	$filter_party = $party_type !== '' && $party_type !== null ? array('type' => $party_type, 'id' => $party_id) : null;
	$filter_search = get_post('show_search');
	$total_rows = get_comm_channel_log_count($channel, get_post('show_from'), get_post('show_to'),
		$filter_type, $filter_party, $filter_search);

    $result = get_comm_channel_log(
        $channel,
        get_post('show_from'), get_post('show_to'),
        $filter_type, $filter_party, $filter_search, $page_size, $page_no * $page_size
    );

    start_table(TABLESTYLE, "width=95%");
    $th = array(_('Date'), _('Time'), _('Party'), _('Type'), _('Number'), _('Message'), _('Status'));
    table_header($th);

    $k = 0;
    while ($row = db_fetch($result)) {
        alt_table_row_color($k);
        $created = (string)$row['created_at'];
        label_cell($created !== '' ? sql2date(substr($created, 0, 10)) : '');
        label_cell($created !== '' ? substr($created, 11, 5) : '');
        $name = (string)$row['recipient_name'];
        label_cell($name !== '' ? html_specials_encode($name) : '-');
        // $party_types[''] is the filter dropdown's "-- Any --" placeholder,
        // not a data value - a null recipient_type (a test send has no real
        // party) must not fall into it via PHP's null-to-'' array key coercion.
        $type = $row['recipient_type'];
        label_cell($type ? html_specials_encode(isset($party_types[$type]) ? $party_types[$type] : $type) : '-');
        label_cell(html_specials_encode((string)$row['recipient_address']));
        label_cell(html_specials_encode((string)$row['body']));
        label_cell(html_specials_encode(isset($statuses[$row['status']]) ? $statuses[$row['status']] : (string)$row['status']));
        end_row();
    }
    end_table(1);

	label_row('', sprintf(_('Showing %d-%d of %d entries'), $total_rows ? $page_no * $page_size + 1 : 0,
		min(($page_no + 1) * $page_size, $total_rows), $total_rows), 'colspan=7');
	hidden('page_no', $page_no);
	if ($page_no > 0) submit('previous_page', _('Previous'));
	if (($page_no + 1) * $page_size < $total_rows) submit('next_page', _('Next'));

    end_form();
    end_page();
}
