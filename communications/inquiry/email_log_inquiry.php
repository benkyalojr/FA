<?php
/**********************************************************************
    Communications - Email Inquiry. Email-only view of the delivery log -
    see communications/includes/comm_channel_log_ui.inc for the shared
    rendering (also used by the SMS Inquiry).
***********************************************************************/
$page_security = 'SA_COMMLOG';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");

if (!get_company_pref('use_communications')) {
	page(_($help_context = "Email Inquiry"));
	display_error(_("The Communications module is not enabled."));
	end_page();
	exit;
}

include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/communications/includes/db/comm_log_db.inc");
include_once($path_to_root . "/communications/includes/comm_channel_log_ui.inc");

comm_channel_log_screen('email', _("Email Inquiry"));
