<?php
/**********************************************************************
    Communications - Delivery Log. Every send attempt (including gated/
    opted-out skips) is recorded here by comm_send_notification() /
    comm_send_raw() (communications/includes/db/comm_send_db.inc).
***********************************************************************/
$page_security = 'SA_COMMLOG';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");

if (!get_company_pref('use_communications')) {
	page(_($help_context = "Communications Delivery Log"));
	display_error(_("The Communications module is not enabled."));
	end_page();
	exit;
}

include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/communications/includes/comm_events.inc");
include_once($path_to_root . "/communications/includes/db/comm_log_db.inc");
include_once($path_to_root . "/communications/includes/comm_channel_log_ui.inc");

comm_log_screen(null, _("Communications Delivery Log"));
