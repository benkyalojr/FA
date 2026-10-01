<?php
/**********************************************************************
    eTIMS Sales - read-only listing of every sale Stanbest has on file
    (GetAllSales), paginated. Distinct from Setup > eTIMS Submissions
    (this system's own local stamping log) - this is Stanbest's own
    record, useful for cross-checking that what we think got stamped
    actually matches what they have.
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

page(_($help_context = "eTIMS Sales"));

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
	$result = etims_get_all_sales($cfg, $limit, ($page - 1) * $limit);
} catch (Exception $e) {
	display_error($e->getMessage());
	end_page();
	return;
}

if (!$result['ok']) {
	display_error(sprintf(_('Could not list sales: %s'), etims_error_message($result)));
	end_page();
	return;
}

$sales = isset($result['data']['sales']) ? $result['data']['sales'] : array();

start_table(TABLESTYLE, "width='100%'");
table_header(array(_("Trader Invoice No"), _("Stanbest Invoice No"), _("Customer"), _("Date"),
	_("Total"), _("Tax"), _("Receipt Sign"), _("Status"), ''));

$k = 0;
foreach ($sales as $sale) {
	alt_table_row_color($k);
	label_cell(html_specials_encode((string)$sale['trdInvcNo']));
	label_cell(html_specials_encode((string)$sale['invcNo']));
	label_cell(html_specials_encode((string)$sale['custNm']));
	label_cell(etims_fmt_dt($sale['cfmDt']));
	amount_cell((float)$sale['totAmt']);
	amount_cell((float)$sale['totTaxAmt']);
	label_cell(html_specials_encode((string)$sale['rcptSign']));
	label_cell(etims_status_cell($sale['status']));
	if (!empty($sale['short_url']))
		label_cell("<a href='" . html_specials_encode($sale['short_url']) . "' target='_blank'>" . _('QR') . "</a>");
	else
		label_cell('');
	end_row();
}
end_table(1);

if (!$sales)
	display_note(_('No sales found.'));

etims_remote_pager_nav($page, isset($result['data']['totalPages']) ? $result['data']['totalPages'] : 1,
	isset($result['data']['total']) ? $result['data']['total'] : count($sales));

end_page();
