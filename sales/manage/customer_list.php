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

// Customers tab: searchable list of customers; edit opens FA's customer form.
$page_security = 'SA_CUSTOMER';
$path_to_root = "../..";

include_once($path_to_root . "/includes/db_pager.inc");
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/ui/customers.inc");
page(_($help_context = "Customers"));
include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/includes/db/crm_contacts_db.inc");
include_once($path_to_root . "/includes/ui/attachment.inc");
include_once($path_to_root . "/sales/includes/db/customers_db.inc");

//--------------------------------------------------------------------------------------------
// Deletes (one row or the selection), with the same safeguards as the customer form.
$ids = ma_selected_ids('del_', 'DeleteSelected', 'sel');
if ($ids && check_csrf_token()) {
	$deleted = 0;
	foreach ($ids as $id) {
		$name = get_customer_name($id);
		$why = ma_customer_delete_blocker($id);
		if ($why !== '')
			display_error(sprintf(_("Customer '%s' cannot be deleted: %s."), $name, $why));
		else {
			delete_customer($id);
			$deleted++;
		}
	}
	if ($deleted)
		display_notification(sprintf(_("%d customer(s) deleted."), $deleted));
	$Ajax->activate('_page_body');
}
if (get_post('Search') || get_post('page_size'))
	$Ajax->activate('cust_tbl');

//--------------------------------------------------------------------------------------------
start_form();

ma_sales_filter_start();
ma_sales_field(_('Currency'), function() {
	$codes = array('' => _('All Currencies'));
	$res = db_query("SELECT curr_abrev FROM ".TB_PREF."currencies WHERE !inactive ORDER BY curr_abrev");
	while ($r = db_fetch($res)) $codes[$r[0]] = $r[0];
	echo array_selector('currency', get_post('currency'), $codes);
});
ma_sales_field(_('Sales Type'), function() { sales_types_list_cells(null, 'sales_type', null, false, _('All Sales Types')); });
ma_sales_field(_('Search'), function() { text_cells(null, 'search', get_post('search'), 28, 60); });
ma_sales_field('', function() { check_cells(_('Show also inactive'), 'show_inactive'); }, 'ma-sales-check');
ma_sales_filter_end('Search', _('Search customers'),
	$_SESSION['wa_current_user']->can_access_page('SA_CUSTOMER') ? array(_('New Customer'), 'sales/manage/customers.php') : null);

//--------------------------------------------------------------------------------------------
$sql = "SELECT c.debtor_no, c.name, c.debtor_ref, st.sales_type, c.curr_code, p.phone, p.email, c.inactive
	FROM ".TB_PREF."debtors_master c
	LEFT JOIN ".TB_PREF."sales_types st ON st.id=c.sales_type
	LEFT JOIN ".TB_PREF."crm_contacts cc ON cc.entity_id=c.debtor_no AND cc.type='customer' AND cc.action='general'
	LEFT JOIN ".TB_PREF."crm_persons p ON p.id=cc.person_id
	WHERE 1=1";
if (get_post('currency') !== '' && get_post('currency') !== null)
	$sql .= " AND c.curr_code=".db_escape(get_post('currency'));
if (get_post('sales_type') && get_post('sales_type') != ALL_NUMERIC)
	$sql .= " AND c.sales_type=".db_escape(get_post('sales_type'));
if (trim(get_post('search')) !== '') {
	$like = db_escape('%'.trim(get_post('search')).'%');
	$sql .= " AND (c.name LIKE $like OR c.debtor_ref LIKE $like OR c.tax_id LIKE $like OR p.phone LIKE $like OR p.email LIKE $like)";
}
if (!check_value('show_inactive'))
	$sql .= " AND !c.inactive";
$sql .= " GROUP BY c.debtor_no";

function cust_name_link($row)
{
	return '<a href="'.ma_ui_escape(ma_ui_href('sales/manage/customers.php?debtor_no='.$row['debtor_no'])).'">'
		.ma_ui_escape($row['name']).'</a>'.($row['inactive'] ? ' <span class="ma-pill late">'._('Inactive').'</span>' : '');
}
function cust_select($row) { return ma_row_select('sel['.$row['debtor_no'].']', $row['name']); }
function cust_edit($row) { return ma_row_actions('sales/manage/customers.php?debtor_no='.$row['debtor_no'], '', $row['name']); }
function cust_delete($row) { return ma_row_delete('del_'.$row['debtor_no'], $row['name']); }

$cols = array(
	array('insert'=>true, 'fun'=>'cust_select', 'align'=>'center'),
	'debtor_no' => 'skip',
	_("Name") => array('fun'=>'cust_name_link', 'ord'=>'', 'name'=>'c.name'),
	_("Company") => array('ord'=>'', 'name'=>'c.debtor_ref'),
	_("Sales Type"),
	_("Currency") => array('align'=>'center'),
	_("Phone"),
	_("Email") => 'email',
	'inactive' => 'skip',
	array('insert'=>true, 'fun'=>'cust_edit'),
	array('insert'=>true, 'fun'=>'cust_delete', 'align'=>'center'),
);

$table =& new_db_pager('cust_tbl', $sql, $cols);
$table->width = '100%';
if ((int)get_post('page_size') > 0)
	$table->page_len = (int)get_post('page_size');
display_db_pager($table);
ma_list_bottom('DeleteSelected');

end_form();
end_page();
