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

// Contacts tab: people recorded against suppliers. Contacts are edited on the
// supplier form (Contacts tab).
$page_security = 'SA_SUPPLIER';
$path_to_root = "../..";

include_once($path_to_root . "/includes/db_pager.inc");
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/ui/suppliers.inc");
page(_($help_context = "Supplier Contacts"));
include_once($path_to_root . "/includes/ui.inc");

if (get_post('Search') || get_post('page_size'))
	$Ajax->activate('supp_contact_tbl');

//--------------------------------------------------------------------------------------------
start_form();

ma_sales_filter_start();
ma_sales_field(_('Supplier'), function() { supplier_list_cells(null, 'supplier_id', null, true, false, false, false); });
ma_sales_field(_('Category'), function() {
	$cats = array('' => _('All Categories'));
	$res = db_query("SELECT DISTINCT action FROM ".TB_PREF."crm_contacts WHERE type='supplier' ORDER BY action");
	while ($r = db_fetch($res)) $cats[$r[0]] = ucfirst($r[0]);
	echo array_selector('category', get_post('category'), $cats);
});
ma_sales_field(_('Search'), function() { text_cells(null, 'search', get_post('search'), 28, 60); });
ma_sales_field('', function() { check_cells(_('Show also inactive'), 'show_inactive'); }, 'ma-sales-check');
ma_sales_filter_end('Search', _('Search contacts'), array(_('New Contact'), 'purchasing/manage/suppliers.php?_tabs_sel=contacts'));

//--------------------------------------------------------------------------------------------
$sql = "SELECT cc.id, cc.entity_id, cc.action, per.name AS person, per.name2, per.phone, per.email, per.inactive, s.supp_name
	FROM ".TB_PREF."crm_contacts cc
	JOIN ".TB_PREF."crm_persons per ON per.id=cc.person_id
	JOIN ".TB_PREF."suppliers s ON s.supplier_id=cc.entity_id
	WHERE cc.type='supplier'";
if (get_post('supplier_id') && get_post('supplier_id') != ALL_TEXT)
	$sql .= " AND cc.entity_id=".db_escape(get_post('supplier_id'));
if (get_post('category') !== '' && get_post('category') !== null)
	$sql .= " AND cc.action=".db_escape(get_post('category'));
if (trim(get_post('search')) !== '') {
	$like = db_escape('%'.trim(get_post('search')).'%');
	$sql .= " AND (per.name LIKE $like OR per.name2 LIKE $like OR per.phone LIKE $like OR per.email LIKE $like)";
}
if (!check_value('show_inactive'))
	$sql .= " AND !per.inactive";

function sc_edit_url($row) { return 'purchasing/manage/suppliers.php?supplier_id='.$row['entity_id'].'&_tabs_sel=contacts'; }
function sc_category($row) { return ma_ui_escape(ucfirst($row['action'])); }
function sc_name($row)
{
	return '<a href="'.ma_ui_escape(ma_ui_href(sc_edit_url($row))).'">'.ma_ui_escape(trim($row['person'].' '.$row['name2'])).'</a>'
		.($row['inactive'] ? ' <span class="ma-pill late">'._('Inactive').'</span>' : '');
}
function sc_edit($row) { return ma_row_actions(sc_edit_url($row), '', $row['person']); }

$cols = array(
	'id' => 'skip', 'entity_id' => 'skip',
	_("Category") => array('fun'=>'sc_category'),
	_("Name") => array('fun'=>'sc_name'),
	'name2' => 'skip',
	_("Phone"),
	_("Email") => 'email',
	'inactive' => 'skip',
	_("Supplier") => array('ord'=>'', 'name'=>'s.supp_name'),
	array('insert'=>true, 'fun'=>'sc_edit'),
);

$table =& new_db_pager('supp_contact_tbl', $sql, $cols);
$table->width = '100%';
if ((int)get_post('page_size') > 0)
	$table->page_len = (int)get_post('page_size');
display_db_pager($table);
ma_list_bottom(null, true);

end_form();
end_page();
