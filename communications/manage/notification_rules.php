<?php
/**********************************************************************
    Communications - Notification Rules. The org-level gate: nothing
    sends for an event/channel combination until it is checked here,
    even if the channel itself is toggled on and configured.
***********************************************************************/
$page_security = 'SA_COMMRULES';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");

if (!get_company_pref('use_communications')) {
	page(_($help_context = "Notification Rules"));
	display_error(_("The Communications module is not enabled."));
	end_page();
	exit;
}

include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/communications/includes/comm_events.inc");
include_once($path_to_root . "/communications/includes/db/comm_rules_db.inc");

page(_($help_context = "Notification Rules"));

$comm_events = comm_event_catalog();
$comm_channels = comm_channel_catalog();

if (isset($_POST['update'])) {
	foreach ($comm_events as $event_key => $ev) {
		foreach ($comm_channels as $channel => $label) {
			$field = 'rule_' . $event_key . '_' . $channel;
			set_comm_rule($event_key, $channel, check_value($field) ? 1 : 0);
		}
	}
	display_notification(_('Notification rules have been updated.'));
	$Ajax->activate('_page_body');
}

$current = array();
$res = get_comm_rules();
while ($row = db_fetch($res))
	$current[$row['event_key'] . '|' . $row['channel']] = $row['is_enabled'];

start_form();
label_row('', _('Nothing is sent for an event/channel combination until it is checked here, even if the channel itself is enabled and configured under Communications Module Setup.'), '', '', 'helphint');

start_table(TABLESTYLE, "width=80%");
$th = array(_("Event"));
foreach ($comm_channels as $label)
	$th[] = $label;
table_header($th);

$k = 0;
foreach ($comm_events as $event_key => $ev) {
	alt_table_row_color($k);
	label_cell(html_specials_encode($ev['label']));
	foreach ($comm_channels as $channel => $label) {
		$field = 'rule_' . $event_key . '_' . $channel;
		$checked = !empty($current[$event_key . '|' . $channel]);
		echo "<td align='center'>";
		echo checkbox(null, $field, $checked); // checkbox() returns its HTML, doesn't echo it
		echo "</td>";
	}
	end_row();
}
end_table(1);
submit_center('update', _("Update"), true, '', 'default');
end_form();
end_page();
