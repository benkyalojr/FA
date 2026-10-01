<?php
/**********************************************************************
    Communications Module Setup - per-channel toggles, mirrors
    gl_module_setup.php/payroll_module_setup.php.
***********************************************************************/
$page_security = 'SA_COMMSETUP';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/ui.inc");

include($path_to_root . "/sql/communications_module.php");

page(_($help_context = "Communications Module Setup"));

$toggles = array('use_communications_sms', 'use_communications_email', 'use_communications_whatsapp');

if (isset($_POST['update'])) {
	$params = array();
	foreach ($toggles as $pref)
		$params[$pref] = check_value($pref);
	update_company_prefs($params);
	// The menu is built once per login and cached in $_SESSION['App'].
	unset($_SESSION['App']);
	display_notification(_('Communications module setup has been updated.'));
	$Ajax->activate('_page_body');
}

foreach ($toggles as $pref)
	if (!isset($_POST[$pref]))
		$_POST[$pref] = get_company_pref($pref);

start_form();
start_table(TABLESTYLE2);
table_section_title(_("Channel Toggles"));
check_row(_("Enable SMS:").field_hint(_('Turn on once the SMS gateway is configured under Gateway Settings.')),
	'use_communications_sms', null);
check_row(_("Enable Email:").field_hint(_('Uses the company email address set under Company Preferences - no separate gateway to configure.')),
	'use_communications_email', null);
check_row(_("Enable WhatsApp:").field_hint(_('Turn on once the WhatsApp gateway is configured under Gateway Settings.')),
	'use_communications_whatsapp', null);
label_row('', _('Turning a channel on here is not enough by itself - each event also needs its own rule enabled under Notification Rules before anything actually sends.'), '', '', 'helphint');
end_table(1);
submit_center('update', _("Update"), true, '', 'default');
end_form();

end_page();
