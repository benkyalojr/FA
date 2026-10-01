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

// Branches tab: every customer branch in one searchable list.
$page_security = 'SA_CUSTOMER';
$path_to_root = "../..";

include_once($path_to_root . "/includes/db_pager.inc");
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/ui/customers.inc");
page(_($help_context = "Customer Branches"));
include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/includes/db/crm_contacts_db.inc");
include_once($path_to_root . "/includes/ui/attachment.inc");
include_once($path_to_root . "/sales/includes/db/customers_db.inc");
include_once($path_to_root . "/sales/includes/db/branches_db.inc");

//--------------------------------------------------------------------------------------------
// Deletes: ids are "<customer>-<branch>" so each branch is checked against its own customer.
$ids = ma_selected_ids('del_', 'DeleteSelected', 'sel');
if ($ids && check_csrf_token()) {
	$deleted = 0;
	foreach ($ids as $id) {
		list($customer_id, $branch_code) = array_pad(explode('-', $id, 2), 2, '');
		$branch = get_cust_branch($customer_id, $branch_code);
		if (!$branch)
			continue;
		$why = ma_branch_delete_blocker($customer_id, $branch_code);
		if ($why !== '')
			display_error(sprintf(_("Branch '%s' cannot be deleted: %s."), $branch['br_name'], $why));
		else {
			delete_branch($customer_id, $branch_code);
			$deleted++;
		}
	}
	if ($deleted)
		display_notification(sprintf(_("%d branch(es) deleted."), $deleted));
	$Ajax->activate('_page_body');
}
if (get_post('Search') || get_post('page_size'))
	$Ajax->activate('branch_tbl');

//--------------------------------------------------------------------------------------------
start_form();

ma_sales_filter_start();
ma_sales_field(_('Customer'), function() { customer_list_cells(null, 'customer_id', null, _('All Customers'), false, false, false); });
ma_sales_field(_('Sales Person'), function() { sales_persons_list_cells(null, 'salesman', null, _('All Sales Persons')); });
ma_sales_field(_('Search'), function() { text_cells(null, 'search', get_post('search'), 28, 60); });
ma_sales_field('', function() { check_cells(_('Show also inactive'), 'show_inactive'); }, 'ma-sales-check');
$new_link = 'sales/manage/customer_branches.php'.((get_post('customer_id') && get_post('customer_id') != ALL_TEXT) ? '?debtor_no='.urlencode(get_post('customer_id')) : '');
ma_sales_filter_end('Search', _('Search branches'), array(_('New Branch'), $new_link));

//--------------------------------------------------------------------------------------------
$sql = "SELECT b.debtor_no, b.branch_code, c.name AS customer, b.branch_ref, b.br_name, p.name AS contact_name,
		s.salesman_name, a.description AS area, p.phone, p.email, b.inactive
	FROM ".TB_PREF."cust_branch b
	JOIN ".TB_PREF."debtors_master c ON c.debtor_no=b.debtor_no
	LEFT JOIN ".TB_PREF."areas a ON a.area_code=b.area
	LEFT JOIN ".TB_PREF."salesman s ON s.salesman_code=b.salesman
	LEFT JOIN ".TB_PREF."crm_contacts cc ON cc.entity_id=b.branch_code AND cc.type='cust_branch' AND cc.action='general'
	LEFT JOIN ".TB_PREF."crm_persons p ON p.id=cc.person_id
	WHERE 1=1";
if (get_post('customer_id') && get_post('customer_id') != ALL_TEXT)
	$sql .= " AND b.debtor_no=".db_escape(get_post('customer_id'));
if (get_post('salesman') && get_post('salesman') != ALL_NUMERIC)
	$sql .= " AND b.salesman=".db_escape(get_post('salesman'));
if (trim(get_post('search')) !== '') {
	$like = db_escape('%'.trim(get_post('search')).'%');
	$sql .= " AND (b.br_name LIKE $like OR b.branch_ref LIKE $like OR c.name LIKE $like OR p.name LIKE $like OR p.phone LIKE $like OR p.email LIKE $like)";
}
if (!check_value('show_inactive'))
	$sql .= " AND !b.inactive AND !c.inactive";
$sql .= " GROUP BY b.branch_code";

function br_key($row) { return $row['debtor_no'].'-'.$row['branch_code']; }
function br_name_link($row)
{
	return '<a href="'.ma_ui_escape(ma_ui_href('sales/manage/customer_branches.php?SelectedBranch='.$row['branch_code'])).'">'
		.ma_ui_escape($row['br_name']).'</a>'.($row['inactive'] ? ' <span class="ma-pill late">'._('Inactive').'</span>' : '');
}
function br_select($row) { return ma_row_select('sel['.br_key($row).']', $row['br_name']); }
function br_edit($row) { return ma_row_actions('sales/manage/customer_branches.php?SelectedBranch='.$row['branch_code'], '', $row['br_name']); }
function br_delete($row) { return ma_row_delete('del_'.br_key($row), $row['br_name']); }

$cols = array(
	array('insert'=>true, 'fun'=>'br_select', 'align'=>'center'),
	'debtor_no' => 'skip',
	'branch_code' => 'skip',
	_("Customer") => array('ord'=>''),
	_("Short Name"),
	_("Branch") => array('fun'=>'br_name_link', 'ord'=>''),
	_("Contact"),
	_("Sales Person"),
	_("Area"),
	_("Phone"),
	_("Email") => 'email',
	'inactive' => 'skip',
	array('insert'=>true, 'fun'=>'br_edit'),
	array('insert'=>true, 'fun'=>'br_delete', 'align'=>'center'),
);

$table =& new_db_pager('branch_tbl', $sql, $cols);
$table->width = '100%';
if ((int)get_post('page_size') > 0)
	$table->page_len = (int)get_post('page_size');
display_db_pager($table);
ma_list_bottom('DeleteSelected');

end_form();
end_page();
