<?php
/**********************************************************************
    eTIMS Item Registration - a tab on the Item Entry screen
    (inventory/manage/items.php), matching wakulima's own
    inventory/etims_item.php placement exactly (George, 2026-09-21).
    Structurally this is the same nested-tab pattern core FA already
    uses for Standard Costs/Sales Pricing (inventory/cost_update.php) -
    not a literal port of wakulima's data layer, which used its own
    separate etims_stock_master table and a lookup-table-driven
    category/class/origin scheme. This tab is a per-item front end onto
    the eTIMS Item Mapping data layer already built and verified against
    the real Stanbest sandbox (etims/includes/db/etims_item_mapping_db.inc)
    - Setup > eTIMS Item Mapping still exists as the bulk/overview screen
    over the same etims_item_map table, this is just the other, more
    convenient entry point onto it.
***********************************************************************/
$page_security = 'SA_ETIMSITEMS';

if (@$_GET['page_level'] == 1)
	$path_to_root = "../..";
else
	$path_to_root = "..";

include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/inventory/includes/inventory_db.inc");
include_once($path_to_root . "/etims/includes/etims_setup.inc");
if (!etims_require_setup())
	return;
include_once($path_to_root . "/etims/includes/db/etims_item_mapping_db.inc");
include_once($path_to_root . "/etims/includes/db/etims_codes_db.inc");

if (!isset($_SESSION['page_title']))
	$_SESSION['page_title'] = _($help_context = "eTIMS Item Registration");
page($_SESSION['page_title'], false, false, "", "");

if (isset($_GET['stock_id']))
	$_POST['stock_id'] = $_GET['stock_id'];

//--------------------------------------------------------------------------------------
$should_update = false;
if (isset($_POST['SaveMap']) || isset($_POST['RegisterKRA'])) {
	$stock_id = $_POST['stock_id'];
	etims_save_item_map($stock_id, array(
		'item_cls_cd' => trim(get_post('item_cls_cd')),
		'item_ty_cd' => get_post('item_ty_cd'),
		'orgn_nat_cd' => trim(get_post('orgn_nat_cd')) !== '' ? trim(get_post('orgn_nat_cd')) : 'KE',
		'kra_item_category_id' => trim(get_post('kra_item_category_id')),
		'tax_ty_cd' => get_post('tax_ty_cd'),
		'pkg_unit_cd' => trim(get_post('pkg_unit_cd')) !== '' ? trim(get_post('pkg_unit_cd')) : 'NT',
		'qty_unit_cd' => trim(get_post('qty_unit_cd')) !== '' ? trim(get_post('qty_unit_cd')) : 'U',
	));

	if (isset($_POST['RegisterKRA'])) {
		$map = etims_get_item_map($stock_id);
		if (!$map || $map['item_cls_cd'] === '') {
			display_error(_('Set a KRA Item Classification Code before registering.'));
		} else {
			$result = etims_register_item_with_kra($stock_id);
			if ($result['ok'])
				display_notification(sprintf(_('Registered with KRA. Item code: %s'), $result['item_cd']));
			else
				display_error(sprintf(_('Registration failed: %s'), $result['error']));
		}
	} else {
		display_notification(_('eTIMS mapping saved.'));
	}
	$should_update = true;
}

if (list_updated('stock_id') || $should_update) {
	unset($_POST['memo_']);
	$Ajax->activate('etims_table');
}
//-----------------------------------------------------------------------------------------

$action = $_SERVER['PHP_SELF'];
if ($page_nested)
	$action .= "?stock_id=" . get_post('stock_id');
start_form(false, false, $action);

if (!isset($_POST['stock_id']))
	$_POST['stock_id'] = get_global_stock_item();

if (!$page_nested) {
	echo "<center>" . _("Item:") . "&nbsp;";
	echo stock_items_list('stock_id', $_POST['stock_id'], false, true);
	echo "</center><hr>";
} else {
	br(2);
}

set_global_stock_item($_POST['stock_id']);

if (!get_post('stock_id')) {
	display_warning(_("Select an item to register on eTIMS."));
} else {
	$stock_id = $_POST['stock_id'];
	$item = get_item($stock_id);
	$map = etims_get_item_map($stock_id);

	div_start('etims_table');
	start_table(TABLESTYLE2);

	label_row(_("Item Name:"), html_specials_encode($item['description']));
	label_row(_("Quantity Balance:"), number_format(get_qoh_on_date($stock_id), 2));
	label_row(_("Price:"), number_format(etims_item_selling_price($stock_id), 2));

	$_POST['item_cls_cd'] = get_post('item_cls_cd', $map ? $map['item_cls_cd'] : '');
	$_POST['item_ty_cd'] = get_post('item_ty_cd', $map && $map['item_ty_cd'] ? $map['item_ty_cd'] : '2');
	$_POST['orgn_nat_cd'] = get_post('orgn_nat_cd', $map && $map['orgn_nat_cd'] ? $map['orgn_nat_cd'] : 'KE');
	$_POST['kra_item_category_id'] = get_post('kra_item_category_id', $map ? $map['kra_item_category_id'] : '');
	$_POST['tax_ty_cd'] = get_post('tax_ty_cd', $map && $map['tax_ty_cd'] ? $map['tax_ty_cd'] : 'B');
	$_POST['pkg_unit_cd'] = get_post('pkg_unit_cd', $map && $map['pkg_unit_cd'] ? $map['pkg_unit_cd'] : 'NT');
	$_POST['qty_unit_cd'] = get_post('qty_unit_cd', $map && $map['qty_unit_cd'] ? $map['qty_unit_cd'] : 'U');

	etims_item_classification_list_row(_("KRA Item Classification:"), 'item_cls_cd', $_POST['item_cls_cd']);
	etims_item_type_list_row(_("Item Type:"), 'item_ty_cd', $_POST['item_ty_cd']);
	etims_country_list_row(_("Origin Nation:"), 'orgn_nat_cd', $_POST['orgn_nat_cd']);
	etims_category_list_row(_("KRA Item Category:"), 'kra_item_category_id', $_POST['kra_item_category_id']);
	etims_tax_type_list_row(_("Tax Type:"), 'tax_ty_cd', $_POST['tax_ty_cd']);
	etims_packing_unit_list_row(_("Packaging Unit:"), 'pkg_unit_cd', $_POST['pkg_unit_cd']);
	etims_quantity_unit_list_row(_("Quantity Unit:"), 'qty_unit_cd', $_POST['qty_unit_cd']);

	if ($map && (int)$map['is_registered'] === 1) {
		label_row(_("KRA Item Code:"), html_specials_encode($map['kra_item_cd']));
		label_row(_("Status:"), "<span style='color: green; font-weight: bold;'>" . _('Registered with KRA') . "</span>");
		label_row(_("Registered:"), html_specials_encode((string)$map['registered_at']));
	} elseif ($map && !empty($map['last_error'])) {
		label_row(_("Status:"), "<span class='err_msg'>" . html_specials_encode($map['last_error']) . "</span>");
	} else {
		label_row(_("Status:"), _('Not registered'));
	}

	end_table(1);
	div_end();

	submit_center('SaveMap', _("Save"), true, false, 'default');
	if (!$map || (int)$map['is_registered'] !== 1)
		submit_center('RegisterKRA', _("Register with KRA"), true, false, 'default');
	else
		display_note(_('Already registered - KRA has no update endpoint, so classification/tax-type edits here only affect this system\'s own future invoices, not Stanbest\'s stored item record.'));
}

end_form();
end_page();
