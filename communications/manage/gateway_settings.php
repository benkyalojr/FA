<?php
/**********************************************************************
    Communications Gateway Settings - one save-config panel plus one
    send-test panel per channel: SMS (DigiSoft Solutions), Email (SMTP,
    per explicit direction), WhatsApp (Meta Cloud API). The SMS panel
    mirrors nyala's sms/sms_configuration.php, the only real gateway
    settings screen found among the v1 clients.
***********************************************************************/
$page_security = 'SA_COMMSETUP';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/ui.inc");

include_once($path_to_root . "/communications/includes/db/comm_gateway_config_db.inc");
include_once($path_to_root . "/communications/includes/comm_http.inc");
include_once($path_to_root . "/communications/includes/channels/comm_sms_digisoft.inc");
include_once($path_to_root . "/communications/includes/channels/comm_whatsapp_meta.inc");
include_once($path_to_root . "/communications/includes/channels/comm_email_core.inc");
include_once($path_to_root . "/communications/includes/channels/comm_smtp_client.inc");
include_once($path_to_root . "/communications/includes/db/comm_send_db.inc");

page(_($help_context = "Communications Gateway Settings"));

function comm_mask_secret($value)
{
	$value = (string)$value;
	if ($value === '')
		return _('Not set');
	return str_repeat('*', 8) . ' (' . sprintf(_('%s characters stored'), strlen($value)) . ')';
}

function comm_show_test_result($result)
{
	if ($result === null)
		return;
	if (!empty($result['ok']))
		label_row(_('Result'), "<span style='color: green; font-weight: bold;'>" . htmlspecialchars((string)$result['response']) . "</span>");
	else
		label_row(_('Result'), "<span class='err_msg'>" . htmlspecialchars((string)$result['response']) . "</span>");
}

// ---- SMS: save configuration -------------------------------------------
if (isset($_POST['SAVE_SMS'])) {
	$send_url = trim(get_post('sms_send_url'));
	$balance_url = trim(get_post('sms_balance_url'));
	$sender_id = trim(get_post('sms_sender_id'));
	$sender_name = trim(get_post('sms_sender_name'));
	$api_key = trim(get_post('sms_api_key'));

	$sms_existing = comm_get_gateway_config('sms');
	if ($sender_id === '' || $sender_name === '') {
		display_error(_('Partner ID and Shortcode/sender name are required.'));
	} elseif (!$sms_existing && $api_key === '') {
		display_error(_('API key is required.'));
	} else {
		$fields = array(
			'sender_id' => $sender_id,
			'sender_name' => $sender_name,
			'send_url' => $send_url !== '' ? $send_url : comm_sms_default_send_url(),
			'balance_url' => $balance_url !== '' ? $balance_url : comm_sms_default_balance_url(),
			'is_active' => 1,
		);
		if ($api_key !== '')
			$fields['api_key'] = $api_key;
		comm_save_gateway_config('sms', $fields);
		display_notification(_('SMS gateway settings have been saved.'));
		$Ajax->activate('_page_body');
	}
}

// ---- WhatsApp: save configuration ---------------------------------------
if (isset($_POST['SAVE_WHATSAPP'])) {
	$phone_number_id = trim(get_post('wa_phone_number_id'));
	$business_account_id = trim(get_post('wa_business_account_id'));
	$access_token = trim(get_post('wa_access_token'));

	$wa_existing = comm_get_gateway_config('whatsapp');
	if ($phone_number_id === '') {
		display_error(_('Phone Number ID is required.'));
	} elseif (!$wa_existing && $access_token === '') {
		display_error(_('Access token is required.'));
	} else {
		$fields = array(
			'phone_number_id' => $phone_number_id,
			'business_account_id' => $business_account_id,
			'is_active' => 1,
		);
		if ($access_token !== '')
			$fields['access_token'] = $access_token;
		comm_save_gateway_config('whatsapp', $fields);
		display_notification(_('WhatsApp gateway settings have been saved.'));
		$Ajax->activate('_page_body');
	}
}

// ---- Email: save SMTP configuration -------------------------------------
if (isset($_POST['SAVE_EMAIL'])) {
	$smtp_host = trim(get_post('smtp_host'));
	$smtp_port = trim(get_post('smtp_port'));
	$smtp_encryption = get_post('smtp_encryption');
	$smtp_username = trim(get_post('smtp_username'));
	$smtp_password = trim(get_post('smtp_password'));
	$smtp_from_email = trim(get_post('smtp_from_email'));
	$smtp_from_name = trim(get_post('smtp_from_name'));

	$email_existing = comm_get_gateway_config('email');
	if ($smtp_host === '') {
		display_error(_('SMTP host is required.'));
	} elseif ($smtp_port !== '' && (!is_numeric($smtp_port) || $smtp_port < 1 || $smtp_port > 65535)) {
		display_error(_('SMTP port must be a number between 1 and 65535.'));
	} elseif (!in_array($smtp_encryption, array('none', 'ssl', 'tls'), true)) {
		display_error(_('Select a valid encryption mode.'));
	} elseif ($smtp_from_email !== '' && !filter_var($smtp_from_email, FILTER_VALIDATE_EMAIL)) {
		display_error(_('From email address is not valid.'));
	} else {
		$fields = array(
			'smtp_host' => $smtp_host,
			'smtp_port' => $smtp_port !== '' ? (int)$smtp_port : 587,
			'smtp_encryption' => $smtp_encryption,
			'smtp_username' => $smtp_username,
			'smtp_from_email' => $smtp_from_email,
			'smtp_from_name' => $smtp_from_name,
			'is_active' => 1,
		);
		if ($smtp_password !== '')
			$fields['smtp_password'] = $smtp_password;
		comm_save_gateway_config('email', $fields);
		display_notification(_('SMTP settings have been saved.'));
		$Ajax->activate('_page_body');
	}
}

// ---- Test-send (any channel) --------------------------------------------
// Each branch must activate _page_body itself (matching nyala's own
// sms_configuration.php) - without it, FA's AJAX submission still runs
// this code and gets a full response back, but the browser never swaps
// the Result row into view, so a real send/failure looks like nothing
// happened at all.
$test_result = array('sms' => null, 'email' => null, 'whatsapp' => null);
if (isset($_POST['SEND_TEST_SMS'])) {
	$Ajax->activate('_page_body');
	$test_result['sms'] = comm_send_raw('sms', trim(get_post('sms_test_to')), null,
		_('This is a test message from ') . (string)get_company_pref('coy_name'));
}
if (isset($_POST['SEND_TEST_EMAIL'])) {
	$Ajax->activate('_page_body');
	$test_result['email'] = comm_send_raw('email', trim(get_post('email_test_to')), _('Test message'),
		_('This is a test message from ') . (string)get_company_pref('coy_name'));
}
if (isset($_POST['SEND_TEST_WHATSAPP'])) {
	$Ajax->activate('_page_body');
	$test_result['whatsapp'] = comm_send_raw('whatsapp', trim(get_post('wa_test_to')), null,
		_('This is a test message from ') . (string)get_company_pref('coy_name'));
}

// ================= SMS =================
$sms_cfg = comm_get_gateway_config('sms');

start_form();
start_table(TABLESTYLE2, "width='80%'");
table_section_title(_('SMS Gateway (DigiSoft Solutions)'));
text_row(_('Send message URL'), 'sms_send_url', $sms_cfg && $sms_cfg['send_url'] ? $sms_cfg['send_url'] : comm_sms_default_send_url(), 70, 255);
text_row(_('Balance check URL'), 'sms_balance_url', $sms_cfg && $sms_cfg['balance_url'] ? $sms_cfg['balance_url'] : comm_sms_default_balance_url(), 70, 255);
text_row(_('Partner ID'), 'sms_sender_id', $sms_cfg ? $sms_cfg['sender_id'] : '', 20, 50);
text_row(_('Shortcode / sender name'), 'sms_sender_name', $sms_cfg ? $sms_cfg['sender_name'] : '', 30, 100);
label_row(_('API key'), "<input type='password' name='sms_api_key' size='50' maxlength='128' autocomplete='new-password'>");
label_row('', ($sms_cfg ? sprintf(_('Stored key: %s.'), comm_mask_secret($sms_cfg['api_key'])) . ' ' . _('Leave blank to keep it.') : _('Required on first save.')), '', '', 'helphint');
if ($sms_cfg && !empty($sms_cfg['api_key'])) {
	$balance = comm_sms_digisoft_balance($sms_cfg);
	if (!empty($balance['ok']))
		label_row(_('Current SMS balance'), number_format($balance['balance']));
	else
		label_row(_('Current SMS balance'), "<span class='err_msg'>" . htmlspecialchars((string)$balance['message']) . "</span>");
}
end_table(1);
submit_center('SAVE_SMS', _('Save SMS Settings'), true, '', 'default');
end_form();

start_form();
start_table(TABLESTYLE2, "width='80%'");
table_section_title(_('Send Test SMS'));
text_row(_('Phone number'), 'sms_test_to', get_post('sms_test_to'), 20, 20, null, "placeholder='0712345678'");
comm_show_test_result($test_result['sms']);
end_table(1);
submit_center('SEND_TEST_SMS', _('Send Test SMS'), true, '', 'default');
end_form();

// ================= WhatsApp =================
$wa_cfg = comm_get_gateway_config('whatsapp');

start_form();
start_table(TABLESTYLE2, "width='80%'");
table_section_title(_('WhatsApp Gateway (Meta Cloud API)'));
label_row('', _('Free-text messages only work as replies within a 24-hour customer-service window. Business-initiated messages outside that window need a pre-approved message template registered in Meta Business Manager - an account-side step done outside this app.'), '', '', 'helphint');
text_row(_('Phone Number ID'), 'wa_phone_number_id', $wa_cfg ? $wa_cfg['phone_number_id'] : '', 30, 50);
text_row(_('Business Account ID'), 'wa_business_account_id', $wa_cfg ? $wa_cfg['business_account_id'] : '', 30, 50);
label_row(_('Access token'), "<input type='password' name='wa_access_token' size='50' maxlength='512' autocomplete='new-password'>");
label_row('', ($wa_cfg ? sprintf(_('Stored token: %s.'), comm_mask_secret($wa_cfg['access_token'])) . ' ' . _('Leave blank to keep it.') : _('Required on first save.')), '', '', 'helphint');
end_table(1);
submit_center('SAVE_WHATSAPP', _('Save WhatsApp Settings'), true, '', 'default');
end_form();

start_form();
start_table(TABLESTYLE2, "width='80%'");
table_section_title(_('Send Test WhatsApp Message'));
text_row(_('Phone number'), 'wa_test_to', get_post('wa_test_to'), 20, 20, null, "placeholder='0712345678'");
comm_show_test_result($test_result['whatsapp']);
end_table(1);
submit_center('SEND_TEST_WHATSAPP', _('Send Test WhatsApp Message'), true, '', 'default');
end_form();

// ================= Email (SMTP) =================
$email_cfg = comm_get_gateway_config('email');
$company_bcc = (string)get_company_pref('bcc_email');

start_form();
start_table(TABLESTYLE2, "width='80%'");
table_section_title(_('Email Gateway (SMTP)'));
text_row(_('SMTP Host'), 'smtp_host', $email_cfg ? $email_cfg['smtp_host'] : '', 40, 255);
text_row(_('SMTP Port'), 'smtp_port', $email_cfg && $email_cfg['smtp_port'] ? $email_cfg['smtp_port'] : '587', 10, 5);
array_selector_row(_('Encryption'), 'smtp_encryption',
	$email_cfg && $email_cfg['smtp_encryption'] ? $email_cfg['smtp_encryption'] : 'tls',
	array('none' => _('None'), 'ssl' => _('SSL (implicit, e.g. port 465)'), 'tls' => _('STARTTLS (e.g. port 587)')));
text_row(_('SMTP Username'), 'smtp_username', $email_cfg ? $email_cfg['smtp_username'] : '', 40, 150);
label_row(_('SMTP Password'), "<input type='password' name='smtp_password' size='40' maxlength='255' autocomplete='new-password'>");
label_row('', ($email_cfg ? sprintf(_('Stored password: %s.'), comm_mask_secret($email_cfg['smtp_password'])) . ' ' . _('Leave blank to keep it.') : _('Required on first save unless the SMTP server allows unauthenticated relay.')), '', '', 'helphint');
text_row(_('From Email'), 'smtp_from_email', $email_cfg ? $email_cfg['smtp_from_email'] : '', 40, 150);
label_row('', _('Leave blank to fall back to the company email address (Setup > Company Preferences).'), '', '', 'helphint');
text_row(_('From Name'), 'smtp_from_name', $email_cfg ? $email_cfg['smtp_from_name'] : '', 40, 150);
label_row('', _('Leave blank to fall back to the company name.'), '', '', 'helphint');
if ($company_bcc !== '')
	label_row(_('BCC address'), html_specials_encode($company_bcc) . ' ' . _('(from Company Preferences - every outgoing message is copied here).'));
end_table(1);
submit_center('SAVE_EMAIL', _('Save SMTP Settings'), true, '', 'default');
end_form();

start_form();
start_table(TABLESTYLE2, "width='80%'");
table_section_title(_('Send Test Email'));
text_row(_('Email address'), 'email_test_to', get_post('email_test_to'), 40, 150);
comm_show_test_result($test_result['email']);
end_table(1);
submit_center('SEND_TEST_EMAIL', _('Send Test Email'), true, '', 'default');
end_form();

end_page();
