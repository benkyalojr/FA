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
$page_security = 'SA_GLACCOUNT';
$path_to_root = "../..";
include_once($path_to_root . "/includes/db_pager.inc");
include($path_to_root . "/includes/session.inc");

$js = "";
if ($SysPrefs->use_popup_windows && $SysPrefs->use_popup_search)
	$js .= get_js_open_window(900, 500);

page(_($help_context = "Chart of Accounts"), false, false, "", $js);

include($path_to_root . "/includes/ui.inc");
include($path_to_root . "/gl/includes/gl_db.inc");
include_once($path_to_root . "/admin/db/tags_db.inc");
include_once($path_to_root . "/includes/data_checks.inc");

check_db_has_gl_account_groups(_("There are no account groups defined. Please define at least one account group before entering accounts."));

// Edit (and "new") open the editor as a modal over the list; the group filter can arrive as ?id=
if (isset($_GET["id"]))
	$_POST["group"] = $_GET["id"];

if (isset($_POST['selected_account']))
	$selected_account = $_POST['selected_account'];
elseif (isset($_GET['edit']))
	$selected_account = $_GET['edit'];
else
	$selected_account = "";
$modal_open = isset($_GET['new']) || isset($_GET['edit']) || $selected_account != "";
//-------------------------------------------------------------------------------------

$saved = false;
if (isset($_POST['add']) || isset($_POST['update'])) 
{

	$input_error = 0;

	if (strlen(trim($_POST['account_code'])) == 0) 
	{
		$input_error = 1;
		display_error( _("The account code must be entered."));
		set_focus('account_code');
	} 
	elseif (strlen(trim($_POST['account_name'])) == 0) 
	{
		$input_error = 1;
		display_error( _("The account name cannot be empty."));
		set_focus('account_name');
	} 
	elseif (!$SysPrefs->accounts_alpha() && !preg_match("/^[0-9.]+$/",$_POST['account_code'])) // we only allow 0-9 and a dot
	{
	    $input_error = 1;
	    display_error( _("The account code must be numeric."));
		set_focus('account_code');
	}
	if ($input_error != 1)
	{
		if ($SysPrefs->accounts_alpha() == 2)
			$_POST['account_code'] = strtoupper($_POST['account_code']);

		if (!isset($_POST['account_tags']))
			$_POST['account_tags'] = array();

    	if ($selected_account) 
		{
			if (get_post('inactive') == 1 && is_bank_account($_POST['account_code']))
			{
				display_error(_("The account belongs to a bank account and cannot be inactivated."));
			}
    		elseif (update_gl_account($_POST['account_code'], $_POST['account_name'], 
				$_POST['account_type'], $_POST['account_code2'])) {
				update_record_status($_POST['account_code'], $_POST['inactive'],
					'chart_master', 'account_code');
				update_tag_associations(TAG_ACCOUNT, $_POST['account_code'], 
					$_POST['account_tags']);
				display_notification(_("Account data has been updated."));
				$saved = true;
			}
		}
    	else 
		{
    		if (add_gl_account($_POST['account_code'], $_POST['account_name'], 
				$_POST['account_type'], $_POST['account_code2']))
				{
					add_tag_associations($_POST['account_code'], $_POST['account_tags']);
					display_notification(_("New account has been added."));
					$saved = true;
				}
			else
                 display_error(_("Account not added, possible duplicate Account Code."));
		}
		$Ajax->activate('_page_body');
		if ($saved) {
			$selected_account = "";
			$modal_open = false;
			foreach (array('account_code', 'account_code2', 'account_name', 'account_type', 'inactive', 'account_tags', 'selected_account') as $k)
				unset($_POST[$k]);
		}
	}
} 

if (!$saved && (isset($_POST['add']) || isset($_POST['update'])))
	$modal_open = true; // keep the form open on errors

//-------------------------------------------------------------------------------------

// Next free code for a new account in a group: highest numeric code of the group + 1,
// or the start of the group's class (1000 for assets, 2000 for liabilities, ...) when it is empty.
function next_gl_account_code($group)
{
	$row = db_fetch(db_query("SELECT MAX(CAST(c.account_code AS UNSIGNED)) AS m FROM ".TB_PREF."chart_master c
		WHERE c.account_type=".db_escape($group)." AND c.account_code REGEXP '^[0-9]+$'", "could not read the group's account codes"));
	if ($row && $row['m'] !== null)
		$code = (int)$row['m'] + 1;
	else {
		$t = get_account_type($group);
		$code = $t ? (int)$t['class_id'] * 1000 : 1000;
	}
	while (key_in_foreign_table($code, 'chart_master', 'account_code'))
		$code++;
	return (string)$code;
}

function can_delete($selected_account)
{
	if ($selected_account == "")
		return false;

	if (key_in_foreign_table($selected_account, 'gl_trans', 'account'))
	{
		display_error(_("Cannot delete this account because transactions have been created using this account."));
		return false;
	}

	if (gl_account_in_company_defaults($selected_account))
	{
		display_error(_("Cannot delete this account because it is used as one of the company default GL accounts."));
		return false;
	}

	if (key_in_foreign_table($selected_account, 'bank_accounts', 'account_code'))
	{
		display_error(_("Cannot delete this account because it is used by a bank account."));
		return false;
	}

	if (gl_account_in_stock_category($selected_account))
	{
		display_error(_("Cannot delete this account because it is used by one or more Item Categories."));
		return false;
	}

	if (gl_account_in_stock_master($selected_account))
	{
		display_error(_("Cannot delete this account because it is used by one or more Items."));
		return false;
	}

	if (gl_account_in_tax_types($selected_account))
	{
		display_error(_("Cannot delete this account because it is used by one or more Taxes."));
		return false;
	}

	if (gl_account_in_cust_branch($selected_account))
	{
		display_error(_("Cannot delete this account because it is used by one or more Customer Branches."));
		return false;
	}
	if (gl_account_in_suppliers($selected_account))
	{
		display_error(_("Cannot delete this account because it is used by one or more suppliers."));
		return false;
	}

	if (gl_account_in_quick_entry_lines($selected_account))
	{
		display_error(_("Cannot delete this account because it is used by one or more Quick Entry Lines."));
		return false;
	}

	return true;
}

//--------------------------------------------------------------------------------------

if (get_post('gen_code') && $selected_account == "")
{
	if (get_post('account_type') === null || get_post('account_type') === '')
		display_error(_("Choose the account group first."));
	else
		$_POST['account_code'] = next_gl_account_code($_POST['account_type']);
	$modal_open = true;
	$Ajax->activate('_page_body');
}

// One row (del_account = code) or the ticked rows (sel[code]).
$to_delete = array();
if (!empty($_POST['del_account']))
	$to_delete[] = $_POST['del_account'];
if (isset($_POST['DeleteSelected']) && is_array($_POST['sel'] ?? null))
	foreach ($_POST['sel'] as $code => $on) if ($on) $to_delete[] = (string)$code;
if ($to_delete && check_csrf_token())
{
	$deleted = 0;
	foreach (array_unique($to_delete) as $code)
		if (can_delete($code))
		{
			delete_gl_account($code);
			delete_tag_associations(TAG_ACCOUNT, $code, true);
			$deleted++;
		}
	if ($deleted)
		display_notification(sprintf(_("%d account(s) deleted."), $deleted));
	$Ajax->activate('_page_body');
}

//-------------------------------------------------------------------------------------
start_form();

ma_sales_filter_start();
ma_sales_field(_('Account Group'), function() {
	gl_account_types_list_cells(null, 'group', null, _('All Groups'), true);
});
ma_sales_field(_('Search'), function() { text_cells(null, 'search', get_post('search'), 26, 60); });
ma_sales_field('', function() { check_cells(_('Show also inactive'), 'show_inactive', null, true); }, 'ma-sales-check');
ma_sales_filter_end('Search', _('Search accounts'), array(array(_('Add New'), $_SERVER['PHP_SELF'].'?new=1')));
if (get_post('Search') || list_updated('group') || get_post('_show_inactive_update') || get_post('page_size'))
	$Ajax->activate('account_tbl');

$sql = "SELECT c.account_code, c.account_code2, c.account_name, t.name AS grp,
		(SELECT GROUP_CONCAT(tg.name ORDER BY tg.name SEPARATOR ', ') FROM ".TB_PREF."tag_associations ta
			JOIN ".TB_PREF."tags tg ON tg.id=ta.tag_id AND tg.type=".TAG_ACCOUNT." WHERE ta.record_id=c.account_code) AS tags,
		c.inactive
	FROM ".TB_PREF."chart_master c LEFT JOIN ".TB_PREF."chart_types t ON t.id=c.account_type WHERE 1=1";
$grp = get_post('group');
if ($grp !== null && $grp !== '' && $grp !== ALL_TEXT && $grp != -1)
	$sql .= " AND c.account_type=".db_escape($grp);
if (trim(get_post('search')) !== '') {
	$like = db_escape('%'.trim(get_post('search')).'%');
	$sql .= " AND (c.account_code LIKE $like OR c.account_name LIKE $like OR c.account_code2 LIKE $like OR t.name LIKE $like)";
}
if (!check_value('show_inactive'))
	$sql .= " AND !c.inactive";

function acc_select($row) { return ma_row_select('sel['.$row['account_code'].']', $row['account_name']); }
function acc_name($row)
{
	return ma_ui_escape($row['account_name']).($row['inactive'] ? ' <span class="ma-pill late">'._('Inactive').'</span>' : '');
}
function acc_actions($row)
{
	$code = $row['account_code'];
	return '<a class="ma-row-edit" href="'.ma_ui_escape(ma_ui_href('gl/manage/gl_accounts.php?edit='.rawurlencode($code))).'" aria-label="'
		.ma_ui_escape(sprintf(_('Edit %s'), $row['account_name'])).'">'.ma_ui_icon('file').'</a> '
		.'<button type="submit" class="ma-row-delete" name="del_account" value="'.ma_ui_escape($code).'" aria-label="'
		.ma_ui_escape(sprintf(_('Delete %s'), $row['account_name'])).'" onclick="return confirm(\''.ma_ui_escape(addslashes(sprintf(_('Delete account %s?'), $row['account_code']))).'\')">&times;</button>';
}

$cols = array(
	array('insert'=>true, 'fun'=>'acc_select', 'align'=>'center'),
	_("Account Code") => array('ord'=>'', 'name'=>'c.account_code'),
	_("Parent Account"),
	_("Account Name") => array('fun'=>'acc_name', 'ord'=>'', 'name'=>'c.account_name'),
	_("Account Group"),
	_("Account Tags"),
	'inactive' => 'skip',
	array('insert'=>true, 'fun'=>'acc_actions', 'align'=>'center'),
);
$table =& new_db_pager('account_tbl', $sql, $cols);
$table->width = '100%';
if ((int)get_post('page_size') > 0)
	$table->page_len = (int)get_post('page_size');
display_db_pager($table);
ma_list_bottom('DeleteSelected');

//-------------------------------------------------------------------------------------
// Editor (modal)

ma_modal_begin();
start_table(TABLESTYLE2);

if ($selected_account != "" && !isset($_POST['account_name'])) 
{
	//editing an existing account
	$myrow = get_gl_account($selected_account);

	$_POST['account_code'] = $myrow["account_code"];
	$_POST['account_code2'] = $myrow["account_code2"];
	$_POST['account_name']	= $myrow["account_name"];
	$_POST['account_type'] = $myrow["account_type"];
 	$_POST['inactive'] = $myrow["inactive"];
 	
 	$tags_result = get_tags_associated_with_record(TAG_ACCOUNT, $selected_account);
 	$tagids = array();
 	while ($tag = db_fetch($tags_result)) 
 	 	$tagids[] = $tag['id'];
 	$_POST['account_tags'] = $tagids;
}
if ($selected_account == "" && !isset($_POST['account_code'])) {
	// new account: start from the group being filtered, if any
	$_POST['account_tags'] = array();
	$_POST['account_code'] = $_POST['account_code2'] = '';
	$_POST['account_name']	= $_POST['account_type'] = '';
	$_POST['inactive'] = 0;
	$g = get_post('group');
	if ($g !== null && $g !== '' && $g != -1 && $g !== ALL_TEXT) $_POST['account_type'] = $g;
}
gl_account_types_list_row(_("Account Group:"), 'account_type', null);

if ($selected_account != "")
{
	hidden('account_code', $_POST['account_code']);
	hidden('selected_account', $selected_account);
	label_row(_("Account Code:"), $_POST['account_code']);
} 
else
{
	echo "<tr><td class='label'>"._("Account Code:")."</td><td>";
	// text_cells() would emit its own <td> inside this one and break the row.
	echo "<input type='text' name='account_code' size='15' maxlength='15' value='".html_specials_encode((string)get_post('account_code'))."'> ";
	submit('gen_code', _("Generate"), true, _("Suggest the next free code in the selected group"), true);
	echo "</td></tr>\n";
}

// Parent account: searchable list of the other accounts (Select2, filtered locally).
ensure_select2_assets();
$parents = db_query("SELECT account_code, account_name FROM ".TB_PREF."chart_master ORDER BY account_code");
$cur_parent = (string)get_post('account_code2');
echo "<tr><td class='label'>"._("Parent Account:")."</td><td><select name='account_code2' class='fa-select2' data-select2-local='1' style='min-width:260px'>"
	."<option value=''>"._("None")."</option>";
$known = false;
while ($pa = db_fetch($parents)) {
	if ((string)$pa['account_code'] === (string)get_post('account_code')) continue; // not its own parent
	$known = $known || (string)$pa['account_code'] === $cur_parent;
	echo "<option value='".html_specials_encode($pa['account_code'])."'".((string)$pa['account_code'] === $cur_parent ? " selected" : "").">"
		.html_specials_encode($pa['account_code'].' - '.$pa['account_name'])."</option>";
}
if ($cur_parent !== '' && !$known)
	echo "<option value='".html_specials_encode($cur_parent)."' selected>".html_specials_encode($cur_parent)."</option>";
echo "</select></td></tr>\n";

text_row_ex(_("Account Name:"), 'account_name', 60);

tag_list_row(_("Account Tags:"), 'account_tags', 5, TAG_ACCOUNT, true);

record_status_list_row(_("Account status:"), 'inactive');
end_table(1);

if ($selected_account == "") 
	submit_center('add', _("Add Account"), true, '', 'default');
else 
	submit_center('update', _("Update Account"), true, '', 'default');
ma_modal_end($selected_account == "" ? _('New GL Account') : _('Edit GL Account'), $modal_open);
end_form();

end_page();
