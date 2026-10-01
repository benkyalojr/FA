<?php
/**********************************************************************
    eTIMS Credit Notes - read-only listing of every credit note Stanbest
    has on file (GetAllCreditNotes), paginated. Same "Stanbest's own
    record, for cross-checking" purpose as etims_sales.php.
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

page(_($help_context = "eTIMS Credit Notes"));

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
	$result = etims_get_all_credit_notes($cfg, $limit, ($page - 1) * $limit);
} catch (Exception $e) {
	display_error($e->getMessage());
	end_page();
	return;
}

if (!$result['ok']) {
	display_error(sprintf(_('Could not list credit notes: %s'), etims_error_message($result)));
	end_page();
	return;
}

$credits = isset($result['data']['credit']) ? $result['data']['credit'] : array();

start_table(TABLESTYLE, "width='100%'");
table_header(array(_("Trader Invoice No"), _("Stanbest Credit No"), _("Original Invoice No"), _("Customer"),
	_("Date"), _("Total"), _("Receipt Sign"), _("Status"), ''));

$k = 0;
foreach ($credits as $credit) {
	alt_table_row_color($k);
	label_cell(html_specials_encode((string)$credit['trdInvcNo']));
	label_cell(html_specials_encode((string)$credit['invcNo']));
	label_cell(html_specials_encode((string)$credit['orgInvcNo']));
	label_cell(html_specials_encode((string)$credit['custNm']));
	label_cell(etims_fmt_dt($credit['cfmDt']));
	amount_cell((float)$credit['totAmt']);
	label_cell(html_specials_encode((string)$credit['rcptSign']));
	label_cell(etims_status_cell($credit['status']));
	if (!empty($credit['short_url']))
		label_cell("<a href='" . html_specials_encode($credit['short_url']) . "' target='_blank'>" . _('QR') . "</a>");
	else
		label_cell('');
	end_row();
}
end_table(1);

if (!$credits)
	display_note(_('No credit notes found.'));

etims_remote_pager_nav($page, isset($result['data']['totalPages']) ? $result['data']['totalPages'] : 1,
	isset($result['data']['total']) ? $result['data']['total'] : count($credits));

end_page();
