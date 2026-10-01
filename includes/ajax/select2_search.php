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
/**********************************************************************
  JSON search endpoint backing the Select2 item/customer/supplier combos.
  Reuses the same get_*_search() functions as the classic popup lookups
  (inventory/inquiry/stock_list.php, sales/inquiry/customers_list.php,
  purchasing/inquiry/suppliers_list.php) so search results stay identical
  between the two UIs; only the transport (JSON vs HTML table) differs.
***********************************************************************/
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$entity = isset($_GET['entity']) && is_string($_GET['entity']) ? $_GET['entity'] : '';
$term = isset($_GET['term']) && is_string($_GET['term']) ? substr($_GET['term'], 0, 100) : '';
$active_only = !empty($_GET['active']);

$security_map = array(
	'item' => 'SA_ITEM',
	'customer' => 'SA_SALESORDER',
	'supplier' => 'SA_PURCHASEORDER',
	// GL account combos are used across every module, each already gated by
	// its own page_security; any logged-in user of this company may search
	// the chart of accounts here, as combo_input()'s inline search allows.
	'account' => null,
);

if (!array_key_exists($entity, $security_map)
	|| ($security_map[$entity] !== null
		&& !$_SESSION["wa_current_user"]->can_access_page($security_map[$entity]))) {
	http_response_code(403);
	echo json_encode(array('results' => array()));
	exit;
}

$results = array();

switch ($entity) {
	case 'item':
		include_once($path_to_root . "/inventory/includes/db/items_db.inc");
		// get_items_search() reads the term via get_post('description') for
		// two of its three LIKE clauses; mirror what the popup page sends.
		$_POST['description'] = $term;
		$type = isset($_GET['type']) && is_string($_GET['type']) ? $_GET['type'] : 'all';
		$allowed_types = array('all', 'sales', 'sales_local', 'manufactured', 'purchase_service',
			'procurement', 'purchasable', 'costable', 'component', 'kits');
		if (!in_array($type, $allowed_types, true)) {
			http_response_code(400);
			echo json_encode(array('results' => array()));
			exit;
		}
		$parent = isset($_GET['parent']) && is_string($_GET['parent']) ? substr($_GET['parent'], 0, 100) : '';
		$result = get_items_search($term, $type, $parent);
		while ($row = db_fetch_assoc($result)) {
			$text = (user_show_codes() ? $row['item_code'] . ' - ' : '') . $row['description'];
			$results[] = array('id' => $row['item_code'], 'text' => $text);
		}
		break;

	case 'customer':
		include_once($path_to_root . "/sales/includes/db/customers_db.inc");
		$result = get_customers_search($term, $active_only);
		while ($row = db_fetch_assoc($result)) {
			$text = $row['name'] . ($row['debtor_ref'] !== '' ? ' (' . $row['debtor_ref'] . ')' : '');
			$results[] = array('id' => $row['debtor_no'], 'text' => $text);
		}
		break;

	case 'supplier':
		include_once($path_to_root . "/purchasing/includes/db/suppliers_db.inc");
		$result = get_suppliers_search($term, $active_only);
		while ($row = db_fetch_assoc($result)) {
			$text = $row['supp_name'] . ($row['supp_ref'] !== '' ? ' (' . $row['supp_ref'] . ')' : '');
			$results[] = array('id' => $row['supplier_id'], 'text' => $text);
		}
		break;

	case 'account':
		include_once($path_to_root . "/gl/includes/db/gl_db_accounts.inc");
		$skip = (isset($_GET['type']) && $_GET['type'] === 'skip_bank');
		$account_type = isset($_GET['account_type']) && ctype_digit((string)$_GET['account_type'])
			? (int)$_GET['account_type'] : null;
		$result = get_chart_accounts_search($term, $skip, $active_only, $account_type);
		while ($row = db_fetch_assoc($result)) {
			$results[] = array('id' => $row['account_code'],
				'text' => $row['account_code'] . ' - ' . $row['account_name']);
		}
		break;
}

echo json_encode(array('results' => $results));
