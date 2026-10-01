<?php
/**********************************************************************
    Communications - Message Templates. One row per event x channel,
    pre-seeded by migrate_communications() (see sql/communications.php).
    Add/Delete are available for completeness, but the normal workflow
    is editing an existing seeded row's subject/body.
***********************************************************************/
$page_security = 'SA_COMMTEMPLATES';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");

if (!get_company_pref('use_communications')) {
	page(_($help_context = "Message Templates"));
	display_error(_("The Communications module is not enabled."));
	end_page();
	exit;
}

include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/communications/includes/comm_events.inc");
include_once($path_to_root . "/communications/includes/db/comm_templates_db.inc");

page(_($help_context = "Message Templates"));

simple_page_mode(true);

$comm_events = array();
$comm_modules = array('' => _('-- All modules --'));
foreach (comm_event_catalog() as $ev_key => $ev)
	$comm_modules[$ev['module']] = $ev['module'];

foreach (comm_event_catalog() as $ev_key => $ev)
	$comm_events[$ev_key] = $ev['label'];
$comm_channels = comm_channel_catalog();
$module_filter = get_post('module_filter', '');

function can_process()
{
	global $comm_events, $comm_channels;

	if (!isset($comm_events[get_post('event_key')])) {
		display_error(_("Select an event."));
		return false;
	}
	if (!isset($comm_channels[get_post('channel')])) {
		display_error(_("Select a channel."));
		return false;
	}
	if (trim(get_post('body')) === '') {
		display_error(_("Enter a message body."));
		set_focus('body');
		return false;
	}
	return true;
}

if (($Mode == 'ADD_ITEM' || $Mode == 'UPDATE_ITEM') && can_process())
{
	$subject = get_post('channel') == 'email' ? trim(get_post('subject')) : null;
	if ($Mode == 'ADD_ITEM')
		add_comm_template(get_post('event_key'), get_post('channel'), $subject, get_post('body'));
	else
		update_comm_template($selected_id, get_post('event_key'), get_post('channel'), $subject,
			get_post('body'), check_value('is_active') ? 1 : 0);
	display_notification($Mode == 'ADD_ITEM' ? _('Template has been added.') : _('Template has been updated.'));
	$Mode = 'RESET';
}

if ($Mode == 'Delete')
{
	delete_comm_template($selected_id);
	display_notification(_('Template has been deleted.'));
	$Mode = 'RESET';
}

if ($Mode == 'RESET')
{
	$selected_id = -1;
	unset($_POST);
}

$result = get_comm_templates();

start_form();
start_table(TABLESTYLE_NOBORDER);
// Submit the whole form when the module changes so the result table is
// re-rendered with the selected filter.  The default async selector update
// only refreshes the select control itself and leaves the rows unchanged.
array_selector_row(_('Module:'), 'module_filter', $module_filter, $comm_modules,
	array('select_submit' => true, 'async' => false));
end_table(1);
start_table(TABLESTYLE, "width=95%");
$th = array(_("Event"), _("Channel"), _("Subject"), _("Body"), _("Active"), '', '');
table_header($th);

$k = 0;
while ($myrow = db_fetch($result))
{
	$event = comm_event_catalog()[$myrow['event_key']] ?? null;
	if ($module_filter !== '' && (!$event || $event['module'] !== $module_filter)) continue;
	alt_table_row_color($k);
	label_cell(html_specials_encode(isset($comm_events[$myrow['event_key']]) ? $comm_events[$myrow['event_key']] : $myrow['event_key']));
	label_cell(html_specials_encode(isset($comm_channels[$myrow['channel']]) ? $comm_channels[$myrow['channel']] : $myrow['channel']));
	label_cell(html_specials_encode((string)$myrow['subject']));
	label_cell(nl2br(html_specials_encode((string)$myrow['body'])));
	label_cell($myrow['is_active'] ? _("Yes") : _("No"));
	edit_button_cell("Edit" . $myrow['id'], _("Edit"));
	delete_button_cell("Delete" . $myrow['id'], _("Delete"));
	end_row();
}
end_table(1);

start_table(TABLESTYLE2);
if ($selected_id != -1)
{
	if ($Mode == 'Edit') {
		$myrow = get_comm_template($selected_id);
		$_POST['event_key'] = $myrow['event_key'];
		$_POST['channel'] = $myrow['channel'];
		$_POST['subject'] = $myrow['subject'];
		$_POST['body'] = $myrow['body'];
		$_POST['is_active'] = $myrow['is_active'];
	}
	hidden('selected_id', $selected_id);
}
if (!isset($_POST['event_key']))
	$_POST = array_merge($_POST, array('event_key' => '', 'channel' => '', 'subject' => '', 'body' => '', 'is_active' => 1));

array_selector_row(_("Event:"), 'event_key', null, $comm_events);
array_selector_row(_("Channel:"), 'channel', null, $comm_channels);
text_row(_("Subject (email only):"), 'subject', null, 50, 255);
textarea_row(_("Body:"), 'body', null, 50, 5, 2000);
label_row('', _('Placeholders like {customer_name} are filled in when the message is sent - see the event catalog for which ones each event provides.'), '', '', 'helphint');
check_row(_("Active:"), 'is_active', null);

end_table(1);
submit_add_or_update_center($selected_id == -1, '', 'both');
end_form();
end_page();
