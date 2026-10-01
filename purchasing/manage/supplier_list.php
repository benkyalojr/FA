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

// Suppliers tab: searchable list of suppliers; edit opens FA's supplier form.
$page_security = 'SA_SUPPLIER';
$path_to_root = "../..";

include_once($path_to_root . "/includes/db_pager.inc");
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/ui/suppliers.inc");
page(_($help_context = "Suppliers"));
include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/includes/db/crm_contacts_db.inc");
include_once($path_to_root . "/includes/ui/attachment.inc");
include_once($path_to_root . "/purchasing/includes/db/suppliers_db.inc");

//--------------------------------------------------------------------------------------------
// Deletes (one row or the selection), with the same safeguards as the supplier form.
$ids = ma_selected_ids('del_', 'DeleteSelected', 'sel');
if ($ids && check_csrf_token()) {
	$deleted = 0;
	foreach ($ids as $id) {
		$supplier = get_supplier($id);
		if (!$supplier)
			continue;
		$why = ma_supplier_delete_blocker($id);
		if ($why !== '')
			display_error(sprintf(_("Supplier '%s' cannot be deleted: %s."), $supplier['supp_name'], $why));
		else {
			delete_supplier($id);
			$deleted++;
		}
	}
	if ($deleted)
		display_notification(sprintf(_("%d supplier(s) deleted."), $deleted));
	$Ajax->activate('_page_body');
}
if (get_post('Search') || get_post('page_size'))
	$Ajax->activate('supp_tbl');

//--------------------------------------------------------------------------------------------
start_form();

ma_sales_filter_start();
ma_sales_field(_('Currency'), function() {
	$codes = array('' => _('All Currencies'));
	$res = db_query("SELECT curr_abrev FROM ".TB_PREF."currencies WHERE !inactive ORDER BY curr_abrev");
	while ($r = db_fetch($res)) $codes[$r[0]] = $r[0];
	echo array_selector('currency', get_post('currency'), $codes);
});
ma_sales_field(_('Payment Terms'), function() {
	$terms = array('' => _('All Terms'));
	$res = db_query("SELECT terms_indicator, terms FROM ".TB_PREF."payment_terms WHERE !inactive ORDER BY terms");
	while ($r = db_fetch($res)) $terms[$r[0]] = $r[1];
	echo array_selector('terms', get_post('terms'), $terms);
});
ma_sales_field(_('Search'), function() { text_cells(null, 'search', get_post('search'), 28, 60); });
ma_sales_field('', function() { check_cells(_('Show also inactive'), 'show_inactive'); }, 'ma-sales-check');
ma_sales_filter_end('Search', _('Search suppliers'),
	$_SESSION['wa_current_user']->can_access_page('SA_SUPPLIER') ? array(_('New Supplier'), 'purchasing/manage/suppliers.php') : null);

//--------------------------------------------------------------------------------------------
$sql = "SELECT s.supplier_id, s.supp_name, s.supp_ref, s.curr_code, pt.terms, p.phone, p.email, s.inactive
	FROM ".TB_PREF."suppliers s
	LEFT JOIN ".TB_PREF."payment_terms pt ON pt.terms_indicator=s.payment_terms
	LEFT JOIN ".TB_PREF."crm_contacts cc ON cc.entity_id=s.supplier_id AND cc.type='supplier' AND cc.action='general'
	LEFT JOIN ".TB_PREF."crm_persons p ON p.id=cc.person_id
	WHERE 1=1";
if (get_post('currency') !== '' && get_post('currency') !== null)
	$sql .= " AND s.curr_code=".db_escape(get_post('currency'));
if (get_post('terms') !== '' && get_post('terms') !== null)
	$sql .= " AND s.payment_terms=".db_escape(get_post('terms'));
if (trim(get_post('search')) !== '') {
	$like = db_escape('%'.trim(get_post('search')).'%');
	$sql .= " AND (s.supp_name LIKE $like OR s.supp_ref LIKE $like OR s.gst_no LIKE $like OR p.phone LIKE $like OR p.email LIKE $like)";
}
if (!check_value('show_inactive'))
	$sql .= " AND !s.inactive";
$sql .= " GROUP BY s.supplier_id";

function sup_name_link($row)
{
	return '<a href="'.ma_ui_escape(ma_ui_href('purchasing/manage/suppliers.php?supplier_id='.$row['supplier_id'])).'">'
		.ma_ui_escape($row['supp_name']).'</a>'.($row['inactive'] ? ' <span class="ma-pill late">'._('Inactive').'</span>' : '');
}
function sup_select($row) { return ma_row_select('sel['.$row['supplier_id'].']', $row['supp_name']); }
function sup_edit($row) { return ma_row_actions('purchasing/manage/suppliers.php?supplier_id='.$row['supplier_id'], '', $row['supp_name']); }
function sup_delete($row) { return ma_row_delete('del_'.$row['supplier_id'], $row['supp_name']); }

$cols = array(
	array('insert'=>true, 'fun'=>'sup_select', 'align'=>'center'),
	'supplier_id' => 'skip',
	_("Name") => array('fun'=>'sup_name_link', 'ord'=>'', 'name'=>'s.supp_name'),
	_("Short Name") => array('ord'=>'', 'name'=>'s.supp_ref'),
	_("Currency") => array('align'=>'center'),
	_("Payment Terms"),
	_("Phone"),
	_("Email") => 'email',
	'inactive' => 'skip',
	array('insert'=>true, 'fun'=>'sup_edit'),
	array('insert'=>true, 'fun'=>'sup_delete', 'align'=>'center'),
);

$table =& new_db_pager('supp_tbl', $sql, $cols);
$table->width = '100%';
if ((int)get_post('page_size') > 0)
	$table->page_len = (int)get_post('page_size');
display_db_pager($table);
ma_list_bottom('DeleteSelected');

end_form();
end_page();
