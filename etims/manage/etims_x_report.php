<?php
/**********************************************************************
    eTIMS X Report - the interim, non-closing shift report. Safe to run
    any number of times: confirmed against the sandbox that XReportStart
    just reports the currently-open shift's live totals without changing
    its state. For the actual day-closing action, see etims_z_report.php
    - deliberately a separate screen with its own security area, since
    that one is a real, consequential, once-a-day KRA action.
***********************************************************************/
$page_security = 'SA_ETIMSXREPORT';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/ui.inc");

include_once($path_to_root . "/etims/includes/etims_setup.inc");
if (!etims_require_setup())
	return;
include_once($path_to_root . "/etims/includes/db/etims_config_db.inc");
include_once($path_to_root . "/etims/includes/db/etims_client_db.inc");
include_once($path_to_root . "/etims/includes/etims_remote_list_ui.inc");

page(_($help_context = "eTIMS X Report"));

$cfg = etims_get_config();
if (!$cfg || $cfg['pin'] === '' || $cfg['password'] === '') {
	display_error(_('Configure eTIMS (Setup > eTIMS Configuration) before running reports.'));
	end_page();
	return;
}

$shift = null;
$error = null;
if (etims_shift_preview_requested()) {
	try {
		$result = etims_xreport_start($cfg);
		if ($result['ok'])
			$shift = $result['data']['shift'];
		else
			$error = etims_error_message($result);
	} catch (Exception $e) {
		$error = $e->getMessage();
	}
}

if ($error) {
	display_error(sprintf(_('Could not get the current shift: %s'), $error));
} elseif ($shift) {
	start_table(TABLESTYLE2, "width='60%'");
	table_section_title(_('Current Shift'));
	label_row(_('Shift started:'), html_specials_encode((string)$shift['startTime']));
	label_row(_('Status:'), $shift['status'] === 'active'
		? "<span style='color: green; font-weight: bold;'>" . _('Open') . "</span>"
		: html_specials_encode((string)$shift['status']));
	label_row(_('Sales count:'), number_format((int)$shift['countSales']));
	label_row(_('Sales total:'), $shift['totalSales'] !== null ? number_format((float)$shift['totalSales'], 2) : '-');
	label_row(_('Credit notes count:'), number_format((int)$shift['countCredit']));
	label_row(_('Credit notes total:'), $shift['totalCredit'] !== null ? number_format((float)$shift['totalCredit'], 2) : '-');
	end_table(1);

	display_note(_('These are Stanbest\'s own running shift totals (from when the shift started), not necessarily the same as this system\'s own eTIMS Submissions count - a shift only ends when you run Close Day (Z Report).'));
}

start_form();
start_table(TABLESTYLE2, "width='60%'");
table_section_title(_('Download'));
label_row('', _('Note: the X Report PDF endpoint is currently returning a server error on Stanbest\'s side (confirmed, not a bug in this screen). The Daily Report PDF below is a live preview of the same period\'s totals and works normally.'), '', '', 'helphint');
end_table(1);
echo "<center>";
echo "<a href='etims_report_pdf.php?type=xreport' target='_blank'>" . _('Download X Report PDF') . "</a>&nbsp;&nbsp;";
echo "<a href='etims_report_pdf.php?type=zreport' target='_blank'>" . _('Download Daily Report PDF (preview, does not close the day)') . "</a>";
echo "</center>";
end_form();

if (user_check_access('SA_ETIMSZREPORT'))
	hyperlink_no_params('etims_z_report.php', _('Close Day (Z Report)'));

end_page();
