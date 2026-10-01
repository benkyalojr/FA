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
	return sprintf(_('%d characters stored'), strlen($value));
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

// ================= page =================
$e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$pill = function ($text, $tone) use ($e) { return '<span class="ma-pill '.$tone.'">'.$e($text).'</span>'; };
$field = function ($label, $name, $value, $opts = array()) use ($e) {
	$type = $opts['type'] ?? 'text';
	return '<label class="'.($opts['class'] ?? '').'">'.$e($label).'<input type="'.$type.'" name="'.$e($name).'" value="'.($type === 'password' ? '' : $e($value)).'" maxlength="'.(int)($opts['max'] ?? 255).'"'
		.(isset($opts['placeholder']) ? ' placeholder="'.$e($opts['placeholder']).'"' : '').($type === 'password' ? ' autocomplete="new-password"' : '').'></label>';
};
$card_start = function ($title, $badges) use ($e) {
	return '<form method="post" action="'.$e($_SERVER['PHP_SELF']).'"><section class="ma-panel ma-cfg-card ma-gw"><div class="ma-panel-head"><h2>'.$e($title).'</h2><span class="ma-gw-badges">'.$badges.'</span></div><div class="ma-cfg-body">';
};
$test_block = function ($label, $name, $placeholder, $button, $result) use ($e) {
	$h = '<div class="ma-gw-test"><label>'.$e($label).'<input type="text" name="'.$e($name).'" value="'.$e(get_post($name)).'" placeholder="'.$e($placeholder).'"></label>'
		.'<button class="ma-btn ma-btn-secondary" type="submit" name="'.$e($button[0]).'" value="1">'.$e($button[1]).'</button></div>';
	if ($result !== null)
		$h .= '<div class="ma-cfg-result '.(!empty($result['ok']) ? 'ok' : 'bad').'" role="status">'.$e((string)$result['response']).'</div>';
	return $h;
};
$enabled_badge = function ($channel) use ($pill) {
	return get_company_pref('use_communications_'.$channel) ? $pill(_('Enabled'), 'paid') : $pill(_('Switched off'), 'pending');
};
$note = function ($text) use ($e) { return '<p class="ma-cfg-hint">'.$e($text).'</p>'; };
$save = function ($name, $label) use ($e) { return '<div class="ma-cfg-actions"><button class="ma-btn ma-btn-primary" type="submit" name="'.$e($name).'" value="1">'.$e($label).'</button></div>'; };
$card_end = '</div></section></form>';

echo '<div class="ma-cfg">';
echo '<p class="ma-cfg-hint ma-gw-intro">'.$e(_('Set up each channel here. Turn channels on or off under Communications Module Setup, and choose which events send under Notification Rules.')).'</p>';

// ---- SMS
$sms_cfg = comm_get_gateway_config('sms');
echo $card_start(_('SMS (DigiSoft Solutions)'), ($sms_cfg && !empty($sms_cfg['api_key']) ? $pill(_('Configured'), 'paid') : $pill(_('Not configured'), 'late')).' '.$enabled_badge('sms'));
echo '<div class="ma-gw-grid">'
	.$field(_('Send message URL'), 'sms_send_url', $sms_cfg && $sms_cfg['send_url'] ? $sms_cfg['send_url'] : comm_sms_default_send_url(), array('class' => 'wide'))
	.$field(_('Balance check URL'), 'sms_balance_url', $sms_cfg && $sms_cfg['balance_url'] ? $sms_cfg['balance_url'] : comm_sms_default_balance_url(), array('class' => 'wide'))
	.$field(_('Partner ID'), 'sms_sender_id', $sms_cfg ? $sms_cfg['sender_id'] : '', array('max' => 50))
	.$field(_('Shortcode / sender name'), 'sms_sender_name', $sms_cfg ? $sms_cfg['sender_name'] : '', array('max' => 100))
	.$field(_('API key'), 'sms_api_key', '', array('type' => 'password', 'max' => 128, 'placeholder' => $sms_cfg ? _('Leave blank to keep the stored key') : _('Required on first save')))
	.'</div>';
echo $note($sms_cfg ? sprintf(_('Stored key: %s.'), comm_mask_secret($sms_cfg['api_key'])) : _('No API key stored yet.'));
if ($sms_cfg && !empty($sms_cfg['api_key'])) {
	$balance = comm_sms_digisoft_balance($sms_cfg);
	echo !empty($balance['ok']) ? '<p class="ma-gw-balance">'.$e(_('SMS balance')).': <strong>'.$e(number_format($balance['balance'])).'</strong></p>'
		: '<div class="ma-cfg-result bad">'.$e(_('Could not read the SMS balance: ').(string)$balance['message']).'</div>';
}
echo $save('SAVE_SMS', _('Save SMS settings'));
echo $test_block(_('Send a test SMS to'), 'sms_test_to', '0712345678', array('SEND_TEST_SMS', _('Send test SMS')), $test_result['sms']);
echo $card_end;

// ---- Email
$email_cfg = comm_get_gateway_config('email');
$company_bcc = (string)get_company_pref('bcc_email');
echo $card_start(_('Email (SMTP)'), ($email_cfg && !empty($email_cfg['smtp_host']) ? $pill(_('Configured'), 'paid') : $pill(_('Not configured'), 'late')).' '.$enabled_badge('email'));
$enc = $email_cfg && $email_cfg['smtp_encryption'] ? $email_cfg['smtp_encryption'] : 'tls';
$enc_html = '<label>'.$e(_('Encryption')).'<select name="smtp_encryption">';
foreach (array('none' => _('None'), 'ssl' => _('SSL (implicit, e.g. port 465)'), 'tls' => _('STARTTLS (e.g. port 587)')) as $k => $label)
	$enc_html .= '<option value="'.$e($k).'"'.($k === $enc ? ' selected' : '').'>'.$e($label).'</option>';
$enc_html .= '</select></label>';
echo '<div class="ma-gw-grid">'
	.$field(_('SMTP host'), 'smtp_host', $email_cfg ? $email_cfg['smtp_host'] : '', array('class' => 'wide'))
	.$field(_('SMTP port'), 'smtp_port', $email_cfg && $email_cfg['smtp_port'] ? $email_cfg['smtp_port'] : '587', array('max' => 5))
	.$enc_html
	.$field(_('SMTP username'), 'smtp_username', $email_cfg ? $email_cfg['smtp_username'] : '', array('max' => 150))
	.$field(_('SMTP password'), 'smtp_password', '', array('type' => 'password', 'placeholder' => $email_cfg ? _('Leave blank to keep the stored password') : _('Required unless the server allows relay without login')))
	.$field(_('From email'), 'smtp_from_email', $email_cfg ? $email_cfg['smtp_from_email'] : '', array('max' => 150, 'placeholder' => _('Defaults to the company email')))
	.$field(_('From name'), 'smtp_from_name', $email_cfg ? $email_cfg['smtp_from_name'] : '', array('max' => 150, 'placeholder' => _('Defaults to the company name')))
	.'</div>';
echo $note($email_cfg ? sprintf(_('Stored password: %s.'), comm_mask_secret($email_cfg['smtp_password'])) : _('No password stored yet.'));
if ($company_bcc !== '')
	echo $note(sprintf(_('Every outgoing message is copied to %s (set in Company Preferences).'), $company_bcc));
echo $save('SAVE_EMAIL', _('Save email settings'));
echo $test_block(_('Send a test email to'), 'email_test_to', 'name@example.com', array('SEND_TEST_EMAIL', _('Send test email')), $test_result['email']);
echo $card_end;

// ---- WhatsApp
$wa_cfg = comm_get_gateway_config('whatsapp');
echo $card_start(_('WhatsApp (Meta Cloud API)'), ($wa_cfg && !empty($wa_cfg['access_token']) ? $pill(_('Configured'), 'paid') : $pill(_('Not configured'), 'late')).' '.$enabled_badge('whatsapp'));
echo '<div class="ma-gw-grid">'
	.$field(_('Phone Number ID'), 'wa_phone_number_id', $wa_cfg ? $wa_cfg['phone_number_id'] : '', array('max' => 50))
	.$field(_('Business Account ID'), 'wa_business_account_id', $wa_cfg ? $wa_cfg['business_account_id'] : '', array('max' => 50))
	.$field(_('Access token'), 'wa_access_token', '', array('type' => 'password', 'max' => 512, 'class' => 'wide', 'placeholder' => $wa_cfg ? _('Leave blank to keep the stored token') : _('Required on first save')))
	.'</div>';
echo $note($wa_cfg ? sprintf(_('Stored token: %s.'), comm_mask_secret($wa_cfg['access_token'])) : _('No access token stored yet.'));
echo $note(_('Free-text messages only work as replies within a 24-hour customer-service window. Business-initiated messages outside that window need a message template approved in Meta Business Manager, which is set up outside this app.'));
echo $save('SAVE_WHATSAPP', _('Save WhatsApp settings'));
echo $test_block(_('Send a test message to'), 'wa_test_to', '0712345678', array('SEND_TEST_WHATSAPP', _('Send test message')), $test_result['whatsapp']);
echo $card_end;
echo '</div>';

end_page();
