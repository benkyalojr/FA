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

// Contacts tab: people recorded against customers and their branches.
// Contacts are edited on the customer form (Contacts tab) or the branch form.
$page_security = 'SA_CUSTOMER';
$path_to_root = "../..";

include_once($path_to_root . "/includes/db_pager.inc");
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/ui/customers.inc");
page(_($help_context = "Customer Contacts"));
include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/sales/includes/db/customers_db.inc");

if (get_post('Search') || get_post('page_size'))
	$Ajax->activate('contact_tbl');

//--------------------------------------------------------------------------------------------
start_form();

ma_sales_filter_start();
ma_sales_field(_('Customer'), function() { customer_list_cells(null, 'customer_id', null, _('All Customers'), false, false, false); });
ma_sales_field(_('Category'), function() {
	$cats = array('' => _('All Categories'));
	$res = db_query("SELECT DISTINCT action FROM ".TB_PREF."crm_contacts WHERE type IN ('customer','cust_branch') ORDER BY action");
	while ($r = db_fetch($res)) $cats[$r[0]] = ucfirst($r[0]);
	echo array_selector('category', get_post('category'), $cats);
});
ma_sales_field(_('Search'), function() { text_cells(null, 'search', get_post('search'), 28, 60); });
ma_sales_field('', function() { check_cells(_('Show also inactive'), 'show_inactive'); }, 'ma-sales-check');
ma_sales_filter_end('Search', _('Search contacts'), array(_('New Contact'), 'sales/manage/customers.php?_tabs_sel=contacts'));

//--------------------------------------------------------------------------------------------
$sql = "SELECT cc.id, cc.type, cc.entity_id, cc.action, per.name AS person, per.name2, per.phone, per.email, per.inactive,
		IF(cc.type='customer', c.debtor_no, b.debtor_no) AS debtor_no,
		IF(cc.type='customer', c.name, bcm.name) AS customer, IF(cc.type='cust_branch', b.br_name, '') AS branch
	FROM ".TB_PREF."crm_contacts cc
	JOIN ".TB_PREF."crm_persons per ON per.id=cc.person_id
	LEFT JOIN ".TB_PREF."debtors_master c ON cc.type='customer' AND c.debtor_no=cc.entity_id
	LEFT JOIN ".TB_PREF."cust_branch b ON cc.type='cust_branch' AND b.branch_code=cc.entity_id
	LEFT JOIN ".TB_PREF."debtors_master bcm ON bcm.debtor_no=b.debtor_no
	WHERE cc.type IN ('customer','cust_branch')";
if (get_post('customer_id') && get_post('customer_id') != ALL_TEXT)
	$sql .= " AND IF(cc.type='customer', c.debtor_no, b.debtor_no)=".db_escape(get_post('customer_id'));
if (get_post('category') !== '' && get_post('category') !== null)
	$sql .= " AND cc.action=".db_escape(get_post('category'));
if (trim(get_post('search')) !== '') {
	$like = db_escape('%'.trim(get_post('search')).'%');
	$sql .= " AND (per.name LIKE $like OR per.name2 LIKE $like OR per.phone LIKE $like OR per.email LIKE $like)";
}
if (!check_value('show_inactive'))
	$sql .= " AND !per.inactive";

function ct_name($row)
{
	$name = trim($row['person'].' '.$row['name2']);
	return '<a href="'.ma_ui_escape(ma_ui_href(ct_edit_url($row))).'">'.ma_ui_escape($name).'</a>'
		.($row['inactive'] ? ' <span class="ma-pill late">'._('Inactive').'</span>' : '');
}
function ct_edit_url($row)
{
	return $row['type'] == 'cust_branch'
		? 'sales/manage/customer_branches.php?SelectedBranch='.$row['entity_id']
		: 'sales/manage/customers.php?debtor_no='.$row['debtor_no'].'&_tabs_sel=contacts';
}
function ct_edit($row) { return ma_row_actions(ct_edit_url($row), '', $row['person']); }

$cols = array(
	'id' => 'skip', 'type' => 'skip', 'entity_id' => 'skip',
	_("Category") => array('fun'=>'ct_category'),
	_("Name") => array('fun'=>'ct_name'),
	'name2' => 'skip',
	_("Phone"),
	_("Email") => 'email',
	'inactive' => 'skip',
	'debtor_no' => 'skip',
	_("Customer") => array('ord'=>''),
	_("Branch"),
	array('insert'=>true, 'fun'=>'ct_edit'),
);
function ct_category($row, $cell) { return ma_ui_escape(ucfirst($row['action'])); }
$cols[_("Category")] = array('fun'=>'ct_category');

$table =& new_db_pager('contact_tbl', $sql, $cols);
$table->width = '100%';
if ((int)get_post('page_size') > 0)
	$table->page_len = (int)get_post('page_size');
display_db_pager($table);
ma_list_bottom(null, true);

end_form();
end_page();
