<?php
/**********************************************************************
    Communications - recipient opt-in preferences data layer (the
    person-level gate). No row for an entity = opted in to everything;
    only overrides are stored.

    Also holds this screen's own entity pickers, kept local to
    Communications rather than pulled in from another module's includes
    (e.g. payroll's employee_picker()) to keep this module's dependency
    on any other module strictly optional - Communications must work
    with only Suppliers/Customers (always present) if Payroll/Farmers
    aren't installed.
***********************************************************************/

function get_comm_recipient_pref($entity_type, $entity_id)
{
	return db_fetch(db_query("SELECT * FROM " . TB_PREF . "comm_recipient_prefs
		WHERE entity_type=" . db_escape($entity_type) . " AND entity_id=" . db_escape($entity_id),
		'Cannot fetch recipient preference'));
}

function save_comm_recipient_pref($entity_type, $entity_id, $sms_opt_in, $email_opt_in, $whatsapp_opt_in)
{
	$existing = get_comm_recipient_pref($entity_type, $entity_id);
	if ($existing) {
		db_query("UPDATE " . TB_PREF . "comm_recipient_prefs SET
			sms_opt_in=" . db_escape($sms_opt_in) . ", email_opt_in=" . db_escape($email_opt_in) . ",
			whatsapp_opt_in=" . db_escape($whatsapp_opt_in) . "
			WHERE entity_type=" . db_escape($entity_type) . " AND entity_id=" . db_escape($entity_id),
			'Cannot update recipient preference');
	} else {
		db_query("INSERT INTO " . TB_PREF . "comm_recipient_prefs
			(entity_type, entity_id, sms_opt_in, email_opt_in, whatsapp_opt_in)
			VALUES (" . db_escape($entity_type) . ", " . db_escape($entity_id) . ",
			" . db_escape($sms_opt_in) . ", " . db_escape($email_opt_in) . ", " . db_escape($whatsapp_opt_in) . ")",
			'Cannot insert recipient preference');
	}
}

function get_comm_recipient_prefs_list()
{
	return db_query("SELECT * FROM " . TB_PREF . "comm_recipient_prefs ORDER BY entity_type, entity_id",
		'Cannot fetch recipient preferences');
}

// -- Local entity pickers (employee and system-user; supplier/customer
// use core's own supplier_list_row()/customer_list_row()) -------------

function comm_employee_picker($name, $selected_id = null, $spec_option = false, $submit_on_change = true)
{
	$sql = "SELECT id, emp_no, CONCAT(emp_no, ' - ', fname, ' ', COALESCE(lname,'')) AS label
		FROM " . TB_PREF . "employees";
	return combo_input($name, $selected_id, $sql, 'id', 'label', array(
		'order' => array('emp_no'),
		'search_box' => true,
		'search' => array('emp_no', 'fname', 'lname'),
		'type' => 1, // by-name search - 'id' is an opaque PK, see employee_picker()'s own note
		'spec_option' => $spec_option,
		'spec_id' => -1,
		'select_submit' => $submit_on_change,
		'async' => false,
	));
}

function comm_employee_picker_row($label, $name, $selected_id = null, $spec_option = false, $submit_on_change = true)
{
	echo "<tr><td class='label'>$label</td><td nowrap>";
	echo comm_employee_picker($name, $selected_id, $spec_option, $submit_on_change);
	echo "</td></tr>\n";
}

function comm_user_picker($name, $selected_id = null, $spec_option = false, $submit_on_change = true)
{
	$sql = "SELECT id, user_id, CONCAT(user_id, ' - ', COALESCE(real_name,'')) AS label
		FROM " . TB_PREF . "users";
	return combo_input($name, $selected_id, $sql, 'id', 'label', array(
		'order' => array('user_id'),
		'search_box' => true,
		'search' => array('user_id', 'real_name'),
		'type' => 1,
		'spec_option' => $spec_option,
		'spec_id' => -1,
		'select_submit' => $submit_on_change,
		'async' => false,
	));
}

function comm_user_picker_row($label, $name, $selected_id = null, $spec_option = false, $submit_on_change = true)
{
	echo "<tr><td class='label'>$label</td><td nowrap>";
	echo comm_user_picker($name, $selected_id, $spec_option, $submit_on_change);
	echo "</td></tr>\n";
}
