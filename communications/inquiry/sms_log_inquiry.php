<?php
/**********************************************************************
    Communications - SMS Inquiry. SMS-only view of the delivery log, with
    a resolved party name/type, a select-with-search Party picker, and a
    general free-text search - see communications/includes/
    comm_channel_log_ui.inc for the shared rendering (also used by the
    Email Inquiry).
***********************************************************************/
$page_security = 'SA_COMMLOG';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");

if (!get_company_pref('use_communications')) {
	page(_($help_context = "SMS Inquiry"));
	display_error(_("The Communications module is not enabled."));
	end_page();
	exit;
}

include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/communications/includes/db/comm_log_db.inc");
include_once($path_to_root . "/communications/includes/comm_channel_log_ui.inc");

comm_channel_log_screen('sms', _("SMS Inquiry"));
