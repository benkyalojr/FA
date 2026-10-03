<?php
/**********************************************************************
    eTIMS Item Mapping - read-only overview of every stock item's KRA
    registration state, with filters. Actual editing/registration moved
    entirely to the eTIMS Item Registration tab on the Item Entry screen
    (inventory/etims_item.php) - editing a whole catalog's worth of
    items through one giant multi-select-per-row form here was both
    redundant with that better, per-item flow and genuinely risky (one
    "Save All" touching every row at once, no per-row confirmation).
    This screen is purely a report now: filter by status, see resolved
    code labels (not just raw codes) for what's on file.
***********************************************************************/
$page_security = 'SA_ETIMSITEMS';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/ui.inc");

include_once($path_to_root . "/etims/includes/etims_setup.inc");
if (!etims_require_setup())
	return;

page(_($help_context = "eTIMS Item Mapping"));

$statuses = array(
	'' => _('-- Any --'),
	'registered' => _('Registered'),
	'not_registered' => _('Not registered'),
	'failed' => _('Failed'),
);

start_form();
start_table(TABLESTYLE_NOBORDER);
start_row();
echo "<td>" . _("Status:") . "</td><td>";
echo array_selector('show_status', get_post('show_status'), $statuses, array('select_submit' => true));
echo "</td>";
echo "<td>" . _("Item:") . "</td><td>";
// text_cells() emits its own <td>, which would nest inside this one.
echo "<input type='text' name='show_search' size='20' maxlength='40' value='" . html_specials_encode((string)get_post('show_search')) . "' placeholder='" . _('name or code') . "'>";
echo "</td>";
submit_cells('RefreshInquiry', _("Show"), '', _('Refresh'), 'default');
end_row();
end_table();

// Show is an AJAX submit: only the regions activated here are redrawn.
global $Ajax;
if (get_post('RefreshInquiry') || list_updated('show_status')) $Ajax->activate('table_');
div_start('table_');

$where = array('s.inactive = 0');
$status = get_post('show_status');
if ($status === 'registered')
	$where[] = "m.is_registered = 1";
elseif ($status === 'not_registered')
	$where[] = "(m.is_registered IS NULL OR m.is_registered = 0) AND (m.last_error IS NULL OR m.last_error = '')";
elseif ($status === 'failed')
	$where[] = "(m.is_registered IS NULL OR m.is_registered = 0) AND m.last_error IS NOT NULL AND m.last_error <> ''";

$search = trim(get_post('show_search'));
if ($search !== '')
	$where[] = "(s.stock_id LIKE " . db_escape('%' . $search . '%') . " OR s.description LIKE " . db_escape('%' . $search . '%') . ")";

// Every code column resolved against etims_code_list for its own
// category, so the report shows readable labels (e.g. "B - VAT 16%")
// rather than raw codes - each join is scoped to one cl_name, so there's
// no risk of the stock_id-collision bug already fixed once in
// get_etims_item_maps() (m.* silently overwriting s.stock_id on a LEFT
// JOIN miss) resurfacing here, since nothing here selects a whole
// joined table's columns.
$sql = "SELECT s.stock_id, s.description,
	m.kra_item_cd,
	m.item_cls_cd, cls.code_name AS item_cls_name,
	m.item_ty_cd, ity.code_name AS item_ty_name,
	m.orgn_nat_cd, org.code_name AS orgn_nat_name,
	m.kra_item_category_id, cat.code_name AS category_name,
	m.tax_ty_cd, tax.code_name AS tax_ty_name,
	m.pkg_unit_cd, pkg.code_name AS pkg_unit_name,
	m.qty_unit_cd, qty.code_name AS qty_unit_name,
	m.is_registered, m.registered_at, m.last_error
	FROM " . TB_PREF . "stock_master s
	LEFT JOIN " . TB_PREF . "etims_item_map m ON m.stock_id = s.stock_id
	LEFT JOIN " . TB_PREF . "etims_code_list cls ON cls.cl_name='Item Classification' AND cls.code = m.item_cls_cd
	LEFT JOIN " . TB_PREF . "etims_code_list ity ON ity.cl_name='Item Type' AND ity.code = m.item_ty_cd
	LEFT JOIN " . TB_PREF . "etims_code_list org ON org.cl_name='Country' AND org.code = m.orgn_nat_cd
	LEFT JOIN " . TB_PREF . "etims_code_list cat ON cat.cl_name='Item Category' AND cat.code = m.kra_item_category_id
	LEFT JOIN " . TB_PREF . "etims_code_list tax ON tax.cl_name='Taxation Type' AND tax.code = m.tax_ty_cd
	LEFT JOIN " . TB_PREF . "etims_code_list pkg ON pkg.cl_name='Packing Unit' AND pkg.code = m.pkg_unit_cd
	LEFT JOIN " . TB_PREF . "etims_code_list qty ON qty.cl_name='Quantity Unit' AND qty.code = m.qty_unit_cd
	WHERE " . implode(' AND ', $where) . "
	ORDER BY s.description LIMIT 500";
$result = db_query($sql, 'Cannot list eTIMS item mappings');

start_table(TABLESTYLE, "width=98%");
table_header(array(_("Stock Item"), _("KRA Item Code"), _("Classification"), _("Item Type"),
	_("Origin"), _("Category"), _("Tax Type"), _("Pkg Unit"), _("Qty Unit"), _("Status"), _("Registered"), ""));

$k = 0;
$count = 0;
while ($row = db_fetch($result)) {
	$count++;
	alt_table_row_color($k);
	label_cell(html_specials_encode($row['stock_id'] . ' - ' . $row['description']));
	label_cell(html_specials_encode((string)$row['kra_item_cd']));
	label_cell($row['item_cls_cd'] ? html_specials_encode($row['item_cls_cd'] . ' - ' . $row['item_cls_name']) : '');
	label_cell($row['item_ty_cd'] ? html_specials_encode($row['item_ty_cd'] . ' - ' . $row['item_ty_name']) : '');
	label_cell($row['orgn_nat_cd'] ? html_specials_encode($row['orgn_nat_cd'] . ' - ' . $row['orgn_nat_name']) : '');
	label_cell($row['kra_item_category_id'] ? html_specials_encode($row['category_name']) : '');
	label_cell($row['tax_ty_cd'] ? html_specials_encode($row['tax_ty_cd'] . ' - ' . $row['tax_ty_name']) : '');
	label_cell($row['pkg_unit_cd'] ? html_specials_encode($row['pkg_unit_cd'] . ' - ' . $row['pkg_unit_name']) : '');
	label_cell($row['qty_unit_cd'] ? html_specials_encode($row['qty_unit_cd'] . ' - ' . $row['qty_unit_name']) : '');

	if ((int)$row['is_registered'] === 1)
		label_cell("<span style='color: green; font-weight: bold;'>" . _('Registered') . "</span>");
	elseif (!empty($row['last_error']))
		label_cell("<span class='err_msg' title='" . html_specials_encode($row['last_error']) . "'>" . _('Failed') . "</span>");
	else
		label_cell(_('Not registered'));

	label_cell(html_specials_encode((string)$row['registered_at']));
	label_cell("<a href='" . $path_to_root . "/inventory/etims_item.php?stock_id=" . urlencode($row['stock_id']) . "'>" . ((int)$row['is_registered'] === 1 ? _('Edit') : _('Map & register')) . "</a>", "nowrap");
	end_row();
}
end_table(1);

if (!$count)
	display_note(_('No stock items match these filters.'));

div_end();

end_form();

display_note(_('Read-only. Use the link on each row to set classification and tax type and to register the item with KRA.'));

end_page();
