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

// Inventory tab: searchable item list with stock figures; edit opens FA's item form.
$page_security = 'SA_ITEM';
$path_to_root = "../..";

include_once($path_to_root . "/includes/db_pager.inc");
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/ui/inventory.inc");
page(_($help_context = "Items"));
include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/includes/ui/attachment.inc");
include_once($path_to_root . "/inventory/includes/db/items_db.inc");
include_once($path_to_root . "/inventory/includes/db/items_codes_db.inc");

//--------------------------------------------------------------------------------------------
// Deletes (one row or the selection), with the same safeguards as the item form.
$ids = ma_selected_ids('del_', 'DeleteSelected', 'sel');
if ($ids && check_csrf_token()) {
	$deleted = 0;
	foreach ($ids as $stock_id) {
		$item = get_item($stock_id);
		if (!$item)
			continue;
		$msg = item_in_foreign_codes($stock_id);
		if ($msg !== '')
			display_error(sprintf(_("Item '%s' was not deleted. %s"), $item['description'], $msg));
		else {
			delete_item($stock_id);
			$deleted++;
		}
	}
	if ($deleted)
		display_notification(sprintf(_("%d item(s) deleted."), $deleted));
	$Ajax->activate('_page_body');
}
if (get_post('Search') || get_post('page_size'))
	$Ajax->activate('item_tbl');

//--------------------------------------------------------------------------------------------
start_form();

ma_sales_filter_start();
ma_sales_field(_('Item Type'), function() {
	echo array_selector('item_type', get_post('item_type'), array('' => _('All'), 'B' => _('Purchased'), 'M' => _('Manufactured'), 'D' => _('Service')));
});
ma_sales_field('', function() { check_cells(_('Show also inactive'), 'show_inactive'); }, 'ma-sales-check');
ma_sales_field(_('Location'), function() { locations_list_cells(null, 'location', null, true); });
ma_sales_field(_('Category'), function() {
	$cats = array('' => _('All Categories'));
	$res = db_query("SELECT category_id, description FROM ".TB_PREF."stock_category WHERE dflt_mb_flag!='F' ORDER BY description");
	while ($r = db_fetch($res)) $cats[$r[0]] = $r[1];
	echo array_selector('category', get_post('category'), $cats);
});
ma_sales_field(_('Tax Type'), function() {
	$tax = array('' => _('All Tax'));
	$res = db_query("SELECT id, name FROM ".TB_PREF."item_tax_types ORDER BY name");
	while ($r = db_fetch($res)) $tax[$r[0]] = $r[1];
	echo array_selector('tax_type', get_post('tax_type'), $tax);
});
ma_sales_field(_('Search'), function() { text_cells(null, 'search', get_post('search'), 26, 60); });
ma_sales_filter_end('Search', _('Search items'), ma_inventory_new_action('inventory'));

//--------------------------------------------------------------------------------------------
$loc = get_post('location');
$loc = ($loc === null || $loc === '' || $loc === ALL_TEXT) ? '' : $loc;
$loc_moves = $loc !== '' ? " AND m.loc_code=".db_escape($loc) : '';
$loc_orders = $loc !== '' ? " AND o.from_stk_loc=".db_escape($loc) : '';
$loc_po = $loc !== '' ? " AND po.into_stock_location=".db_escape($loc) : '';
$loc_reorder = $loc !== '' ? " AND l.loc_code=".db_escape($loc) : '';
$home = get_company_pref('curr_default');
$base = (int)get_company_pref('base_sales');

$sql = "SELECT s.stock_id, s.stock_id AS code, s.description, c.description AS category, t.name AS tax, u.abbr AS unit,
		(s.material_cost+s.labour_cost+s.overhead_cost) AS std_cost,
		(SELECT p.price FROM ".TB_PREF."prices p WHERE p.stock_id=s.stock_id AND p.sales_type_id=$base AND p.curr_abrev=".db_escape($home)." LIMIT 1) AS retail,
		COALESCE((SELECT SUM(m.qty) FROM ".TB_PREF."stock_moves m WHERE m.stock_id=s.stock_id$loc_moves),0) AS on_hand,
		COALESCE((SELECT SUM(d.quantity-d.qty_sent) FROM ".TB_PREF."sales_order_details d
			JOIN ".TB_PREF."sales_orders o ON o.order_no=d.order_no AND o.trans_type=d.trans_type
			WHERE d.stk_code=s.stock_id AND o.trans_type=".ST_SALESORDER."$loc_orders),0) AS demand,
		0 AS available,
		COALESCE((SELECT SUM(pd.quantity_ordered-pd.quantity_received) FROM ".TB_PREF."purch_order_details pd
			JOIN ".TB_PREF."purch_orders po ON po.order_no=pd.order_no WHERE pd.item_code=s.stock_id$loc_po),0) AS on_po,
		s.inactive, s.mb_flag
	FROM ".TB_PREF."stock_master s
	JOIN ".TB_PREF."stock_category c ON c.category_id=s.category_id
	LEFT JOIN ".TB_PREF."item_tax_types t ON t.id=s.tax_type_id
	LEFT JOIN ".TB_PREF."item_units u ON u.abbr=s.units
	WHERE s.mb_flag<>'F'";
if (in_array(get_post('item_type'), array('B', 'M', 'D'), true))
	$sql .= " AND s.mb_flag=".db_escape(get_post('item_type'));
if (get_post('category') !== '' && get_post('category') !== null)
	$sql .= " AND s.category_id=".db_escape(get_post('category'));
if (get_post('tax_type') !== '' && get_post('tax_type') !== null)
	$sql .= " AND s.tax_type_id=".db_escape(get_post('tax_type'));
if (trim(get_post('search')) !== '') {
	$like = db_escape('%'.trim(get_post('search')).'%');
	$sql .= " AND (s.stock_id LIKE $like OR s.description LIKE $like OR c.description LIKE $like)";
}
if (!check_value('show_inactive'))
	$sql .= " AND !s.inactive";

function item_name_link($row)
{
	return '<a href="'.ma_ui_escape(ma_ui_href('inventory/manage/items.php?stock_id='.urlencode($row['stock_id']))).'">'
		.ma_ui_escape($row['description']).'</a>'.($row['inactive'] ? ' <span class="ma-pill late">'._('Inactive').'</span>' : '');
}
function item_available($row)
{
	return qty_cell_html($row['on_hand'] - $row['demand']);
}
function qty_cell_html($qty) { return number_format2($qty, 2); }
function item_select($row) { return ma_row_select('sel['.$row['stock_id'].']', $row['description']); }
function item_edit($row) { return ma_row_actions('inventory/manage/items.php?stock_id='.urlencode($row['stock_id']), '', $row['description']); }

$cols = array(
	array('insert'=>true, 'fun'=>'item_select', 'align'=>'center'),
	_("Stock ID") => array('ord'=>'', 'name'=>'s.stock_id'),
	'code' => 'skip',
	_("Name") => array('fun'=>'item_name_link', 'ord'=>'', 'name'=>'s.description'),
	_("Category"),
	_("Tax Type"),
	_("Unit"),
	_("Std Cost") => array('type'=>'amount', 'align'=>'right'),
	_("Retail") => array('type'=>'amount', 'align'=>'right'),
	_("On Hand") => array('type'=>'amount', 'align'=>'right'),
	_("Demand") => array('type'=>'amount', 'align'=>'right'),
	_("Available") => array('fun'=>'item_available', 'align'=>'right'),
	_("On PO Order") => array('type'=>'amount', 'align'=>'right'),
	'inactive' => 'skip',
	'mb_flag' => 'skip',
	array('insert'=>true, 'fun'=>'item_edit'),
);

$table =& new_db_pager('item_tbl', $sql, $cols);
$table->width = '100%';
if ((int)get_post('page_size') > 0)
	$table->page_len = (int)get_post('page_size');
display_db_pager($table);
ma_list_bottom('DeleteSelected');

end_form();
end_page();
