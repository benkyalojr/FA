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

// Inventory Transfer tab: location transfers posted by Inventory Location Transfers.
$page_security = 'SA_LOCATIONTRANSFER';
$path_to_root = "../..";

include_once($path_to_root . "/includes/db_pager.inc");
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/ui/inventory.inc");
page(_($help_context = "Inventory Location Transfers"));
include_once($path_to_root . "/includes/ui.inc");

if (get_post('Search') || get_post('page_size'))
	$Ajax->activate('transfer_tbl');

//--------------------------------------------------------------------------------------------
start_form();

ma_sales_filter_start();
ma_sales_field(_('Reference'), function() { text_cells(null, 'reference', get_post('reference'), 18, 40); });
ma_sales_field(_('Date'), function() {
	date_cells(null, 'AfterDate', '', null, -30);
	date_cells(null, 'ToDate', '', null, 1);
});
ma_sales_field(_('Location'), function() { locations_list_cells(null, 'location', null, true); });
ma_sales_field(_('Item'), function() { stock_items_list_cells(null, 'item', null, true, true); });
ma_sales_filter_end('Search', _('Search transfers'), ma_inventory_new_action('transfers'));

//--------------------------------------------------------------------------------------------
$sql = "SELECT m.trans_no, m.reference, m.tran_date,
		MAX(IF(m.qty<0, l.location_name, NULL)) AS from_loc, MAX(IF(m.qty>0, l.location_name, NULL)) AS to_loc,
		COUNT(DISTINCT m.stock_id) AS items, SUM(IF(m.qty>0, m.qty, 0)) AS qty, MAX(cm.memo_) AS memo
	FROM ".TB_PREF."stock_moves m
	JOIN ".TB_PREF."locations l ON l.loc_code=m.loc_code
	LEFT JOIN ".TB_PREF."comments cm ON cm.type=m.type AND cm.id=m.trans_no
	WHERE m.type=".ST_LOCTRANSFER."
	AND m.tran_date>=".db_escape(date2sql(get_post('AfterDate')))." AND m.tran_date<=".db_escape(date2sql(get_post('ToDate')));
if (get_post('location') && get_post('location') !== ALL_TEXT)
	$sql .= " AND m.loc_code=".db_escape(get_post('location'));
if (get_post('item') && get_post('item') !== ALL_TEXT)
	$sql .= " AND m.stock_id=".db_escape(get_post('item'));
if (trim(get_post('reference')) !== '')
	$sql .= " AND m.reference LIKE ".db_escape('%'.trim(get_post('reference')).'%');
$sql .= " GROUP BY m.trans_no, m.reference, m.tran_date";

function transfer_view($row) { return get_trans_view_str(ST_LOCTRANSFER, $row['trans_no']); }

$cols = array(
	_("#") => array('fun'=>'transfer_view', 'align'=>'right', 'ord'=>'', 'name'=>'m.trans_no'),
	_("Reference") => array('ord'=>'', 'name'=>'m.reference'),
	_("Date") => array('type'=>'date', 'ord'=>'desc', 'name'=>'m.tran_date'),
	_("From"),
	_("To"),
	_("Items") => array('align'=>'right'),
	_("Quantity") => array('type'=>'amount', 'align'=>'right'),
	_("Memo"),
);

$table =& new_db_pager('transfer_tbl', $sql, $cols);
$table->width = '100%';
if ((int)get_post('page_size') > 0)
	$table->page_len = (int)get_post('page_size');
display_db_pager($table);
ma_list_bottom(null, true);

end_form();
end_page();
