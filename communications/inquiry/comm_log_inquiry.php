<?php
/**********************************************************************
    Communications - Delivery Log. Every send attempt (including gated/
    opted-out skips) is recorded here by comm_send_notification() /
    comm_send_raw() (communications/includes/db/comm_send_db.inc).
***********************************************************************/
$page_security = 'SA_COMMLOG';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");

if (!get_company_pref('use_communications')) {
	page(_($help_context = "Communications Delivery Log"));
	display_error(_("The Communications module is not enabled."));
	end_page();
	exit;
}

include_once($path_to_root . "/includes/date_functions.inc");
include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/communications/includes/comm_events.inc");
include_once($path_to_root . "/communications/includes/db/comm_log_db.inc");

$js = "";
if (user_use_date_picker())
	$js .= get_js_date_picker();

page(_($help_context = "Communications Delivery Log"), false, false, "", $js);

if (get_post('show_from') == '') $_POST['show_from'] = begin_month(Today());
if (get_post('show_to') == '') $_POST['show_to'] = Today();

$comm_events = comm_event_catalog();
$comm_channels = comm_channel_catalog();
$comm_statuses = array(
	'sent' => _('Sent'),
	'failed' => _('Failed'),
	'skipped_gate' => _('Skipped (rule off)'),
	'skipped_optout' => _('Skipped (opted out)'),
);

start_form();
start_table(TABLESTYLE_NOBORDER);
start_row();
date_cells(_("From:"), 'show_from');
date_cells(_("To:"), 'show_to');
echo "<td>" . _("Channel:") . "</td><td>";
echo array_selector('show_channel', get_post('show_channel'), array_merge(array('' => _('-- Any --')), $comm_channels));
echo "</td>";
echo "<td>" . _("Status:") . "</td><td>";
echo array_selector('show_status', get_post('show_status'), array_merge(array('' => _('-- Any --')), $comm_statuses));
echo "</td>";
submit_cells('RefreshInquiry', _("Show"), '', _('Refresh'), 'default');
end_row();
end_table();

$show_channel = get_post('show_channel');
$show_status = get_post('show_status');
$page_size = 50;
$page_no = max(0, (int)get_post('page_no', 0));
$filters_changed = isset($_POST['RefreshInquiry']);
if ($filters_changed) $page_no = 0;
elseif (isset($_POST['next_page'])) $page_no++;
elseif (isset($_POST['previous_page'])) $page_no = max(0, $page_no - 1);
$filter_channel = $show_channel !== '' ? $show_channel : null;
$filter_status = $show_status !== '' ? $show_status : null;
$total_rows = get_comm_log_count(null, $filter_channel, $filter_status, get_post('show_from'), get_post('show_to'));
$result = get_comm_log(null, $show_channel !== '' ? $show_channel : null, $show_status !== '' ? $show_status : null,
	get_post('show_from'), get_post('show_to'), $page_size, $page_no * $page_size);

start_table(TABLESTYLE, "width=95%");
$th = array(_("Date"), _("Event"), _("Channel"), _("Recipient"), _("Address"), _("Status"), _("Response"));
table_header($th);

$k = 0;
while ($row = db_fetch($result))
{
	alt_table_row_color($k);
	$created = (string)$row['created_at'];
	label_cell(($created !== '' ? sql2date(substr($created, 0, 10)) . ' ' . substr($created, 11, 5) : ''));
	label_cell(html_specials_encode(isset($comm_events[$row['event_key']]) ? $comm_events[$row['event_key']]['label'] : $row['event_key']));
	label_cell(html_specials_encode(isset($comm_channels[$row['channel']]) ? $comm_channels[$row['channel']] : $row['channel']));
	label_cell(html_specials_encode((string)$row['recipient_type']));
	label_cell(html_specials_encode((string)$row['recipient_address']));
	label_cell(html_specials_encode(isset($comm_statuses[$row['status']]) ? $comm_statuses[$row['status']] : $row['status']));
	label_cell(html_specials_encode((string)$row['provider_response']));
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
