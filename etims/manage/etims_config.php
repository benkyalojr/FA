<?php
/**********************************************************************
    eTIMS Integration Setup - Stanbest/Vibranium credentials, sandbox/
    live switch, branch id, and the on/off stamping switch itself.
    Mirrors communications/manage/gateway_settings.php's masked-secret
    convention ("leave blank to keep").
***********************************************************************/
$page_security = 'SA_ETIMSSETUP';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/ui.inc");

include_once($path_to_root . "/etims/includes/etims_setup.inc");
if (!etims_require_setup())
	return;
include_once($path_to_root . "/etims/includes/db/etims_config_db.inc");
include_once($path_to_root . "/etims/includes/db/etims_client_db.inc");
include_once($path_to_root . "/etims/includes/db/etims_categories_db.inc");

page(_($help_context = "eTIMS Integration Setup"));

function etims_mask_secret($value)
{
	$value = (string)$value;
	if ($value === '')
		return _('Not set');
	return str_repeat('*', 8) . ' (' . sprintf(_('%s characters stored'), strlen($value)) . ')';
}

if (isset($_POST['SAVE'])) {
	$pin = trim(get_post('etims_pin'));
	$username = trim(get_post('etims_username'));
	$password = trim(get_post('etims_password'));
	$live_url = trim(get_post('etims_live_url'));
	$bhf_id = trim(get_post('etims_bhf_id'));
	$is_live = check_value('etims_is_live') ? 1 : 0;

	$existing = etims_get_config();
	if ($pin === '' || $username === '') {
		display_error(_('PIN and Username are required.'));
	} elseif (!$existing['password'] && $password === '') {
		display_error(_('Password is required on first save.'));
	} elseif ($bhf_id === '' || strlen($bhf_id) !== 2) {
		display_error(_('Branch ID must be exactly 2 characters (KRA default is "00").'));
	} elseif ($is_live && $live_url === '') {
		display_error(_('Live Base URL is required when Live mode is enabled.'));
	} else {
		$fields = array(
			'pin' => $pin,
			'username' => $username,
			'is_live' => $is_live,
			'live_base_url' => $live_url,
			'bhf_id' => $bhf_id,
		);
		if ($password !== '') {
			$fields['password'] = $password;
			// A credential change invalidates any cached token from the
			// old login.
			$fields['access_token'] = null;
			$fields['token_expires_at'] = null;
		}
		etims_save_config($fields);
		display_notification(_('eTIMS configuration has been saved.'));
		$Ajax->activate('_page_body');
	}
}

$test_result = null;
if (isset($_POST['TEST_CONNECTION'])) {
	$Ajax->activate('_page_body');
	$cfg = etims_get_config();
	if (!$cfg || $cfg['pin'] === '' || $cfg['password'] === '') {
		$test_result = array('ok' => false, 'message' => _('Save PIN/Username/Password first.'));
	} else {
		try {
			$result = etims_login($cfg);
			if ($result['ok']) {
				$biz = isset($result['data']['business']['name']) ? $result['data']['business']['name'] : '';
				etims_save_config(array(
					'access_token' => $result['data']['access_token'],
					'token_expires_at' => etims_jwt_expiry($result['data']['access_token']),
				));
				$test_result = array('ok' => true, 'message' => sprintf(_('Connected. Business: %s'), $biz));
			} else {
				$test_result = array('ok' => false, 'message' => etims_error_message($result));
			}
		} catch (Exception $e) {
			$test_result = array('ok' => false, 'message' => $e->getMessage());
		}
	}
}

$cfg = etims_get_config();

start_form();
start_table(TABLESTYLE2, "width='80%'");
table_section_title(_('Stanbest / KRA eTIMS Credentials'));
text_row(_('PIN'), 'etims_pin', $cfg ? $cfg['pin'] : '', 20, 20);
text_row(_('Username'), 'etims_username', $cfg ? $cfg['username'] : '', 30, 100);
label_row(_('Password'), "<input type='password' name='etims_password' size='30' maxlength='255' autocomplete='new-password'>");
label_row('', ($cfg && $cfg['password'] !== '' ? sprintf(_('Stored password: %s.'), etims_mask_secret($cfg['password'])) . ' ' . _('Leave blank to keep it.') : _('Required on first save.')), '', '', 'helphint');

table_section_title(_('Environment'));
check_row(_('Live mode (unchecked = Sandbox)'), 'etims_is_live', $cfg ? $cfg['is_live'] : 0);
label_row('', _('Sandbox Base URL is fixed to the Stanbest sandbox host below. Live Base URL is required once Live mode is checked - get it from Stanbest when they issue production credentials.'), '', '', 'helphint');
text_row(_('Sandbox Base URL'), 'etims_sandbox_url_display', $cfg ? $cfg['sandbox_base_url'] : '', 50, 255, null, "readonly");
text_row(_('Live Base URL'), 'etims_live_url', $cfg ? $cfg['live_base_url'] : '', 50, 255);
text_row(_('Branch ID (bhfId)'), 'etims_bhf_id', $cfg ? $cfg['bhf_id'] : '00', 5, 2);
end_table(1);
submit_center('SAVE', _('Save'), true, '', 'default');
end_form();

// Stamping on/off is a plain sys_prefs toggle (use_etims_stamping), not a
// column on etims_config - the sales-invoice/credit-note hook checks it
// via get_company_pref() alone so it stays cheap and safe even for a
// company that has never opened this screen (see sql/etims.php).
if (isset($_POST['SAVE_STAMPING'])) {
	update_company_prefs(array('use_etims_stamping' => check_value('etims_stamping_on')));
	display_notification(_('eTIMS stamping setting has been saved.'));
	$Ajax->activate('_page_body');
}
if (!isset($_POST['etims_stamping_on']))
	$_POST['etims_stamping_on'] = get_company_pref('use_etims_stamping');

start_form();
start_table(TABLESTYLE2, "width='80%'");
table_section_title(_('Stamping'));
check_row(_('Stamp sales invoices and credit notes with KRA eTIMS').field_hint(_('When on, every posted Sales Invoice and Credit Note is queued for KRA stamping via the background task scheduler. When off, invoices post normally with no eTIMS submission at all.')),
	'etims_stamping_on', null);
end_table(1);
submit_center('SAVE_STAMPING', _('Update'), true, '', 'default');
end_form();

$sync_result = null;
if (isset($_POST['SYNC_CATEGORIES'])) {
	$Ajax->activate('_page_body');
	$sync_result = etims_sync_categories();
	if ($sync_result['ok'])
		$sync_result['message'] = sprintf(_('Synced %d categor%s from Stanbest.'), $sync_result['count'], $sync_result['count'] == 1 ? 'y' : 'ies');
}

start_form();
start_table(TABLESTYLE2, "width='80%'");
table_section_title(_('Item Categories'));
label_row('', _('Syncs your own custom item categories from Stanbest (e.g. "Default", "Food & Drinks") into the Item Category dropdown on the eTIMS Item Registration screens - these are account-specific groupings you manage in Stanbest itself, not a KRA reference list.'), '', '', 'helphint');
if ($sync_result !== null) {
	if ($sync_result['ok'])
		label_row(_('Result'), "<span style='color: green; font-weight: bold;'>" . htmlspecialchars($sync_result['message']) . "</span>");
	else
		label_row(_('Result'), "<span class='err_msg'>" . htmlspecialchars($sync_result['error']) . "</span>");
}
end_table(1);
submit_center('SYNC_CATEGORIES', _('Sync Categories from Stanbest'), true, '', 'default');
end_form();

start_form();
start_table(TABLESTYLE2, "width='80%'");
table_section_title(_('Test Connection'));
if ($test_result !== null) {
	if ($test_result['ok'])
		label_row(_('Result'), "<span style='color: green; font-weight: bold;'>" . htmlspecialchars($test_result['message']) . "</span>");
	else
		label_row(_('Result'), "<span class='err_msg'>" . htmlspecialchars($test_result['message']) . "</span>");
}
end_table(1);
submit_center('TEST_CONNECTION', _('Test Connection'), true, '', 'default');
end_form();

display_note(_('Stamping runs off the background task scheduler (Setup > Maintenance > Background Tasks), not inline when an invoice is saved - a Stanbest outage never blocks a sale from posting.'));

end_page();
