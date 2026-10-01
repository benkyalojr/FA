<?php
/**********************************************************************
    Communications - Recipient Preferences. The person-level gate: pick
    a recipient, then opt them in/out per channel. No row for a person
    means opted in to everything (see comm_recipient_allowed() in
    communications/includes/db/comm_send_db.inc) - only overrides are
    stored here.
***********************************************************************/
$page_security = 'SA_COMMRECIPIENTPREFS';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");

if (!get_company_pref('use_communications')) {
	page(_($help_context = "Recipient Preferences"));
	display_error(_("The Communications module is not enabled."));
	end_page();
	exit;
}

include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/communications/includes/db/comm_recipient_prefs_db.inc");

page(_($help_context = "Recipient Preferences"));

$entity_types = array(
	'supplier' => _('Supplier'),
	'customer' => _('Customer'),
	'user' => _('System User'),
);
if (get_company_pref('use_payroll'))
	$entity_types['employee'] = _('Employee');

$entity_type = get_post('entity_type', 'supplier');
if (!isset($entity_types[$entity_type]))
	$entity_type = 'supplier';
$_POST['entity_type'] = $entity_type;

if (isset($_POST['save_pref'])) {
	$entity_id = (int)get_post('entity_id');
	if ($entity_id > 0) {
		save_comm_recipient_pref($entity_type, $entity_id,
			check_value('sms_opt_in') ? 1 : 0,
			check_value('email_opt_in') ? 1 : 0,
			check_value('whatsapp_opt_in') ? 1 : 0);
		display_notification(_('Recipient preference has been saved.'));
	} else {
		display_error(_('Select a recipient first.'));
	}
}

start_form();
start_table(TABLESTYLE_NOBORDER);
array_selector_row(_("Recipient Type:"), 'entity_type', $entity_type, $entity_types, array('select_submit' => true));
end_table();

$entity_id = 0;
start_table(TABLESTYLE_NOBORDER);
switch ($entity_type) {
	case 'supplier':
		supplier_list_row(_("Supplier:"), 'entity_id', null, false, true);
		break;
	case 'customer':
		customer_list_row(_("Customer:"), 'entity_id', null, false, true);
		break;
	case 'employee':
		comm_employee_picker_row(_("Employee:"), 'entity_id', null, false, true);
		break;
	case 'user':
		comm_user_picker_row(_("System User:"), 'entity_id', null, false, true);
		break;
}
end_table();
$entity_id = (int)get_post('entity_id');

if ($entity_id > 0) {
	$pref = get_comm_recipient_pref($entity_type, $entity_id);
	start_table(TABLESTYLE2);
	check_row(_("SMS opt-in:"), 'sms_opt_in', $pref ? $pref['sms_opt_in'] : 1);
	check_row(_("Email opt-in:"), 'email_opt_in', $pref ? $pref['email_opt_in'] : 1);
	check_row(_("WhatsApp opt-in:"), 'whatsapp_opt_in', $pref ? $pref['whatsapp_opt_in'] : 1);
	end_table(1);
	submit_center('save_pref', _("Save Preference"), true, '', 'default');
}
end_form();

// -- Existing overrides -----------------------------------------------
$list = get_comm_recipient_prefs_list();
$has_rows = false;
start_table(TABLESTYLE, "width=80%");
$th = array(_("Type"), _("Entity ID"), _("SMS"), _("Email"), _("WhatsApp"));
table_header($th);
$k = 0;
while ($row = db_fetch($list)) {
	$has_rows = true;
	alt_table_row_color($k);
	label_cell(html_specials_encode(isset($entity_types[$row['entity_type']]) ? $entity_types[$row['entity_type']] : $row['entity_type']));
	label_cell($row['entity_id']);
	label_cell($row['sms_opt_in'] ? _("Yes") : _("No"));
	label_cell($row['email_opt_in'] ? _("Yes") : _("No"));
	label_cell($row['whatsapp_opt_in'] ? _("Yes") : _("No"));
	end_row();
}
if (!$has_rows)
	label_row('', _('No overrides yet - everyone is opted in to every enabled channel by default.'), 'colspan=5', '', 'helphint');
end_table(1);

end_page();
