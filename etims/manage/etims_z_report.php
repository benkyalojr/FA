<?php
/**********************************************************************
    eTIMS Z Report - closes the current KRA fiscal shift. A real,
    consequential, once-per-day action: cannot be undone, and every
    sale made after closing belongs to a new shift. Deliberately its own
    screen/security area (SA_ETIMSZREPORT), separate from the safe,
    repeatable X Report (etims_x_report.php).

    Calls etims_xreport_end() (POST /xreport/end), NOT etims_zreport()
    (POST /zreport) - confirmed against the sandbox that /xreport/end is
    what actually closes a shift, despite the confusing name ("Zreport"
    sounds like the obvious choice, but was never tested - see the
    comment on etims_zreport() in etims_client_db.inc for why).
***********************************************************************/
$page_security = 'SA_ETIMSZREPORT';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/ui.inc");

include_once($path_to_root . "/etims/includes/etims_setup.inc");
if (!etims_require_setup())
	return;
include_once($path_to_root . "/etims/includes/db/etims_config_db.inc");
include_once($path_to_root . "/etims/includes/db/etims_client_db.inc");
include_once($path_to_root . "/etims/includes/etims_remote_list_ui.inc");

page(_($help_context = "eTIMS Z Report - Close Day"));

$cfg = etims_get_config();
if (!$cfg || $cfg['pin'] === '' || $cfg['password'] === '') {
	display_error(_('Configure eTIMS (Setup > eTIMS Configuration) before running reports.'));
	end_page();
	return;
}

$closed_result = null;
if (isset($_POST['CONFIRM_CLOSE']) && !check_value('confirm_understood')) {
	display_error(_('Check the confirmation box before closing the day.'));
} elseif (isset($_POST['CONFIRM_CLOSE'])) {
	try {
		$result = etims_xreport_end($cfg);
		if ($result['ok'])
			$closed_result = array('ok' => true, 'shift' => $result['data']['shift']);
		else
			$closed_result = array('ok' => false, 'error' => etims_error_message($result));
	} catch (Exception $e) {
		$closed_result = array('ok' => false, 'error' => $e->getMessage());
	}
}

if ($closed_result) {
	if ($closed_result['ok']) {
		$shift = $closed_result['shift'];
		display_notification(_('Day closed successfully.'));
		start_table(TABLESTYLE2, "width='60%'");
		table_section_title(_('Closed Shift Summary'));
		label_row(_('Shift period:'), html_specials_encode($shift['startTime']) . ' &ndash; ' . html_specials_encode($shift['endTime']));
		label_row(_('Sales count:'), number_format((int)$shift['countSales']));
		label_row(_('Sales total:'), number_format((float)$shift['totalSales'], 2));
		label_row(_('Tax total:'), number_format((float)$shift['taxSales'], 2));
		label_row(_('Credit notes count:'), number_format((int)$shift['countCredit']));
		label_row(_('Credit notes total:'), number_format((float)$shift['totalCredit'], 2));
		end_table(1);
		echo "<center><a href='etims_report_pdf.php?type=zreport' target='_blank'>" . _('Download Z Report PDF') . "</a></center>";
	} else {
		display_error(sprintf(_('Could not close the day: %s'), $closed_result['error']));
	}
}

if (!$closed_result || !$closed_result['ok']) {
	$shift = null;
	if (etims_shift_preview_requested()) {
		try {
			$result = etims_xreport_start($cfg);
			if ($result['ok'])
				$shift = $result['data']['shift'];
			else
				display_error(sprintf(_('Could not get the current shift: %s'), etims_error_message($result)));
		} catch (Exception $e) {
			display_error($e->getMessage());
		}
	}

	if ($shift) {
		start_table(TABLESTYLE2, "width='60%'");
		table_section_title(_('Shift About To Be Closed'));
		label_row(_('Shift started:'), html_specials_encode((string)$shift['startTime']));
		label_row(_('Sales count so far:'), number_format((int)$shift['countSales']));
		label_row(_('Sales total so far:'), $shift['totalSales'] !== null ? number_format((float)$shift['totalSales'], 2) : '-');
		end_table(1);
	}

	display_warning(_('Closing the day is permanent and cannot be undone. Every sale after this point starts a new fiscal shift. Only close the day once, at the end of your actual trading day, per your KRA eTIMS obligations.'));

	start_form();
	start_table(TABLESTYLE2, "width='60%'");
	check_row(_('I understand this permanently closes today\'s KRA fiscal session.'), 'confirm_understood', null);
	end_table(1);
	submit_center('CONFIRM_CLOSE', _('Close Day (Z Report)'), true, '', 'default');
	end_form();
}

hyperlink_no_params('etims_x_report.php', _('Back to X Report'));

end_page();
