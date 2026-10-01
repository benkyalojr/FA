<?php
/**********************************************************************
    eTIMS Products - read-only listing of every item registered with
    Stanbest itself (GetAllProducts), paginated. Distinct from Setup >
    eTIMS Item Mapping, which is this system's own local mapping table -
    this is what Stanbest's own catalog actually has on file, useful for
    spotting drift (an item registered here but never mapped locally, or
    vice versa).
***********************************************************************/
$page_security = 'SA_ETIMSVIEW';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/ui.inc");

include_once($path_to_root . "/etims/includes/etims_setup.inc");
if (!etims_require_setup())
	return;
include_once($path_to_root . "/etims/includes/db/etims_config_db.inc");
include_once($path_to_root . "/etims/includes/db/etims_client_db.inc");
include_once($path_to_root . "/etims/includes/etims_remote_list_ui.inc");

page(_($help_context = "eTIMS Products"));

$cfg = etims_get_config();
if (!$cfg || $cfg['pin'] === '' || $cfg['password'] === '') {
	display_error(_('Configure eTIMS (Setup > eTIMS Configuration) first.'));
	end_page();
	return;
}

$limit = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

if (!etims_remote_load_requested($page)) {
	end_page();
	return;
}

try {
	$result = etims_get_all_products($cfg, $limit, ($page - 1) * $limit);
} catch (Exception $e) {
	display_error($e->getMessage());
	end_page();
	return;
}

if (!$result['ok']) {
	display_error(sprintf(_('Could not list products: %s'), etims_error_message($result)));
	end_page();
	return;
}

$items = isset($result['data']['items']) ? $result['data']['items'] : array();

start_table(TABLESTYLE, "width=98%");
table_header(array(_("Item Code"), _("Description"), _("Classification"), _("Tax Type"),
	_("Default Price"), _("Current Stock"), _("Status"), _("Registered")));

$k = 0;
foreach ($items as $item) {
	alt_table_row_color($k);
	label_cell(html_specials_encode((string)$item['itemCd']));
	label_cell(html_specials_encode((string)$item['itemNm']));
	label_cell(html_specials_encode((string)$item['itemClsCd']));
	label_cell(html_specials_encode((string)$item['taxTyCd']));
	amount_cell((float)$item['dftPrc']);
	qty_cell((float)$item['currentStock'], false, 2);
	label_cell(etims_status_cell($item['status']));
	label_cell(html_specials_encode(substr((string)$item['createdAt'], 0, 10)));
	end_row();
}
end_table(1);

if (!$items)
	display_note(_('No products found.'));

etims_remote_pager_nav($page, isset($result['data']['totalPages']) ? $result['data']['totalPages'] : 1,
	isset($result['data']['total']) ? $result['data']['total'] : count($items));

end_page();
