<?php
/**********************************************************************
    Copyright (C) FrontAccounting, LLC.
	Released under the terms of the GNU General Public License, GPL,
	as published by the Free Software Foundation, either version 3
	of the License, or (at your option) any later version.
    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
    See the License here <http://www.gnu.org/licenses/gpl-3.0.html>.
***********************************************************************/
$page_security = 'SA_CUSTOMER';
$path_to_root = "../..";
include($path_to_root . "/includes/session.inc");

page(_($help_context = "Credit Note Reasons"));

include($path_to_root . "/sql/credit_note_reasons.php");

include($path_to_root . "/sales/includes/db/credit_note_reasons_db.inc");

include($path_to_root . "/includes/ui.inc");

// Read-only readiness check: the table is created by the deployment migration,
// never by a page request.
if (!db_num_rows(db_query("SHOW TABLES LIKE '".TB_PREF."credit_note_reasons'"))) {
	display_error(sprintf(_('Credit note reasons need a deployment update. Ask your administrator to run: php scripts/run_migrations.php %d --apply --only=credit_note_reasons'),
		isset($_SESSION['wa_current_user']->cur_con) ? (int)$_SESSION['wa_current_user']->cur_con : 0));
	end_page();
	exit;
}

simple_page_mode(true);
//-----------------------------------------------------------------------------------

function can_process()
{
	if (strlen($_POST['reason_description']) == 0)
	{
		display_error(_("The credit note reason description cannot be empty."));
		set_focus('reason_description');
		return false;
	}

	return true;
}

//-----------------------------------------------------------------------------------

if ($Mode=='ADD_ITEM' && can_process())
{
	add_credit_note_reason($_POST['reason_description']);
	display_notification(_('New credit note reason has been added'));
	$Mode = 'RESET';
}

//-----------------------------------------------------------------------------------

if ($Mode=='UPDATE_ITEM' && can_process())
{
	update_credit_note_reason($selected_id, $_POST['reason_description']);
	display_notification(_('Selected credit note reason has been updated'));
	$Mode = 'RESET';
}

//-----------------------------------------------------------------------------------

//-----------------------------------------------------------------------------------

if ($Mode == 'Delete')
{
	delete_credit_note_reason($selected_id);
	display_notification(_('Selected credit note reason has been deleted'));
	$Mode = 'RESET';
}

if ($Mode == 'RESET')
{
	$selected_id = -1;
	$sav = get_post('show_inactive');
	unset($_POST);
	$_POST['show_inactive'] = $sav;
}
//-----------------------------------------------------------------------------------

$result = get_all_credit_note_reasons(check_value('show_inactive'));

start_form();
ma_settings_new_bar(_('Add New'));
start_table(TABLESTYLE, "width='100%'");
$th = array(_("Description"), '', '');
inactive_control_column($th);
table_header($th);

$k = 0;
while ($myrow = db_fetch($result))
{
	alt_table_row_color($k);

	label_cell($myrow["reason_description"]);
	inactive_control_cell($myrow["id"], $myrow["inactive"], 'credit_note_reasons', 'id');
 	edit_button_cell("Edit".$myrow['id'], _("Edit"));
 	delete_button_cell("Delete".$myrow['id'], _("Delete"));
	end_row();
}

inactive_control_row($th);
end_table();
echo '<br>';

//-----------------------------------------------------------------------------------

$modal_open = isset($_GET['new']) || $selected_id != -1 || in_array($Mode, array('ADD_ITEM', 'UPDATE_ITEM'));
ma_modal_begin();
start_table(TABLESTYLE2);

if ($selected_id != -1)
{
 	if ($Mode == 'Edit') {
		//editing an existing reason

		$myrow = get_credit_note_reason($selected_id);

		$_POST['reason_description']  = $myrow["reason_description"];
	}
	hidden('selected_id', $selected_id);
}

text_row_ex(_("Description:"), 'reason_description', 50);

end_table(1);

submit_add_or_update_center($selected_id == -1, '', 'both');
ma_modal_end($selected_id == -1 ? _('New Credit Note Reason') : _('Edit Credit Note Reason'), $modal_open);

end_form();

//------------------------------------------------------------------------------------

end_page();
