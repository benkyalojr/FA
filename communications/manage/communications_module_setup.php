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

$e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$channels = array(
	'use_communications_sms' => array(_('SMS'), _('Turn on once the SMS gateway is configured under Gateway Settings.')),
	'use_communications_email' => array(_('Email'), _('Uses the company email address set under Company Preferences. There is no separate gateway to configure.')),
	'use_communications_whatsapp' => array(_('WhatsApp'), _('Turn on once the WhatsApp gateway is configured under Gateway Settings.')),
);
start_form();
echo '<div class="ma-cfg"><section class="ma-panel ma-cfg-card"><div class="ma-panel-head"><h2>'.$e(_('Channels')).'</h2></div><div class="ma-cfg-body">';
foreach ($channels as $pref => $c)
	echo '<label class="ma-cfg-switch"><input type="checkbox" name="'.$e($pref).'" value="1"'.(check_value($pref) ? ' checked' : '').'><span class="ma-cfg-track" aria-hidden="true"></span>'
		.'<span><strong>'.$e($c[0]).'</strong><small>'.$e($c[1]).'</small></span></label>';
echo '<p class="ma-cfg-hint">'.$e(_('Turning a channel on here is not enough by itself: each event also needs its own rule enabled under Notification Rules before anything is sent.')).'</p>'
	.'<div class="ma-cfg-actions"><button class="ma-btn ma-btn-primary" type="submit" name="update" value="1">'.$e(_('Save')).'</button></div>'
	.'</div></section></div>';
end_form();

end_page();
