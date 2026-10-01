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
	return sprintf(_('%d characters stored'), strlen($value));
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
		update_company_prefs(array('use_etims_stamping' => check_value('etims_stamping_on') ? 1 : 0));
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

// Stamping on/off is a plain sys_prefs toggle (use_etims_stamping), not a
// column on etims_config - the sales-invoice/credit-note hook checks it
// via get_company_pref() alone so it stays cheap and safe even for a
// company that has never opened this screen (see sql/etims.php).
if (isset($_POST['SAVE_STAMPING'])) {
	update_company_prefs(array('use_etims_stamping' => check_value('etims_stamping_on')));
	display_notification(_('eTIMS stamping setting has been saved.'));
	$Ajax->activate('_page_body');
}
$sync_result = null;
if (isset($_POST['SYNC_CATEGORIES'])) {
	$Ajax->activate('_page_body');
	$sync_result = etims_sync_categories();
	if ($sync_result['ok'])
		$sync_result['message'] = sprintf(_('Synced %d categor%s from Stanbest.'), $sync_result['count'], $sync_result['count'] == 1 ? 'y' : 'ies');
}


// ---------------------------------------------------------------- page
$e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$has_creds = $cfg && $cfg['pin'] !== '' && $cfg['username'] !== '' && $cfg['password'] !== '';
$live = $cfg && $cfg['is_live'];
$stamping = isset($_POST['SAVE_STAMPING']) ? check_value('etims_stamping_on') : (bool)get_company_pref('use_etims_stamping');
$token_ok = $cfg && !empty($cfg['access_token']) && (empty($cfg['token_expires_at']) || strtotime($cfg['token_expires_at']) > time());
$pill = function ($text, $tone) use ($e) { return '<span class="ma-pill '.$tone.'">'.$e($text).'</span>'; };

start_form();
echo '<div class="ma-cfg">';

// Status strip
echo '<section class="ma-cfg-status"><div><span class="ma-cfg-label">'.$e(_('Connection')).'</span>'
	.($token_ok ? $pill(_('Connected'), 'paid') : ($has_creds ? $pill(_('Not tested'), 'pending') : $pill(_('Not set up'), 'late'))).'</div>'
	.'<div><span class="ma-cfg-label">'.$e(_('Environment')).'</span>'.($live ? $pill(_('Live'), 'late') : $pill(_('Sandbox'), 'open')).'</div>'
	.'<div><span class="ma-cfg-label">'.$e(_('Stamping')).'</span>'.($stamping ? $pill(_('On'), 'paid') : $pill(_('Off'), 'pending')).'</div>'
	.'<div><span class="ma-cfg-label">'.$e(_('Account')).'</span><strong>'.$e($cfg && $cfg['pin'] !== '' ? 'PIN '.$cfg['pin'].' / Branch '.$cfg['bhf_id'] : _('Not set')).'</strong></div></section>';

if ($test_result !== null)
	echo '<div class="ma-cfg-result '.($test_result['ok'] ? 'ok' : 'bad').'" role="status">'.$e($test_result['message']).'</div>';
if ($sync_result !== null)
	echo '<div class="ma-cfg-result '.($sync_result['ok'] ? 'ok' : 'bad').'" role="status">'.$e($sync_result['ok'] ? $sync_result['message'] : $sync_result['error']).'</div>';

echo '<div class="ma-cfg-grid">';

// Credentials + environment (one Save)
echo '<section class="ma-panel ma-cfg-card"><div class="ma-panel-head"><h2>'.$e(_('Stanbest / KRA eTIMS Credentials')).'</h2></div><div class="ma-cfg-body">'
	.'<label>'.$e(_('PIN')).'<input type="text" name="etims_pin" maxlength="20" value="'.$e(get_post('etims_pin') !== null ? get_post('etims_pin') : ($cfg ? $cfg['pin'] : '')).'" placeholder="A000000000X"></label>'
	.'<label>'.$e(_('Username')).'<input type="text" name="etims_username" maxlength="100" value="'.$e(get_post('etims_username') !== null ? get_post('etims_username') : ($cfg ? $cfg['username'] : '')).'" autocomplete="off"></label>'
	.'<label>'.$e(_('Password')).'<input type="password" name="etims_password" maxlength="255" autocomplete="new-password" placeholder="'.$e($cfg && $cfg['password'] !== '' ? _('Leave blank to keep the stored password') : _('Required on first save')).'"></label>'
	.'<p class="ma-cfg-hint">'.$e($cfg && $cfg['password'] !== '' ? sprintf(_('Stored password: %s.'), etims_mask_secret($cfg['password'])) : _('No password stored yet.')).'</p></div></section>';

echo '<section class="ma-panel ma-cfg-card"><div class="ma-panel-head"><h2>'.$e(_('Environment')).'</h2></div><div class="ma-cfg-body">'
	.'<label class="ma-cfg-switch"><input type="checkbox" name="etims_is_live" value="1"'.(($cfg && $cfg['is_live']) || check_value('etims_is_live') ? ' checked' : '').'><span class="ma-cfg-track" aria-hidden="true"></span>'
	.'<span><strong>'.$e(_('Live mode')).'</strong><small>'.$e(_('Off = Sandbox. Turn on only once Stanbest has issued production credentials.')).'</small></span></label>'
	.'<label>'.$e(_('Sandbox Base URL')).'<input type="text" value="'.$e($cfg ? $cfg['sandbox_base_url'] : '').'" readonly></label>'
	.'<label>'.$e(_('Live Base URL')).'<input type="text" name="etims_live_url" maxlength="255" value="'.$e(get_post('etims_live_url') !== null ? get_post('etims_live_url') : ($cfg ? $cfg['live_base_url'] : '')).'" placeholder="https://"></label>'
	.'<label class="ma-cfg-short">'.$e(_('Branch ID (bhfId)')).'<input type="text" name="etims_bhf_id" maxlength="2" value="'.$e(get_post('etims_bhf_id') !== null ? get_post('etims_bhf_id') : ($cfg ? $cfg['bhf_id'] : '00')).'"></label>'
	.'</div></section>';
echo '</div>';

// Stamping and categories live in the same form, so there is one Save.
if (!isset($_POST['etims_stamping_on']) && !isset($_POST['SAVE']))
	$_POST['etims_stamping_on'] = $stamping;
echo '<div class="ma-cfg-grid">'
	.'<section class="ma-panel ma-cfg-card"><div class="ma-panel-head"><h2>'.$e(_('Stamping')).'</h2></div><div class="ma-cfg-body">'
	.'<label class="ma-cfg-switch"><input type="checkbox" name="etims_stamping_on" value="1"'.(check_value('etims_stamping_on') ? ' checked' : '').'><span class="ma-cfg-track" aria-hidden="true"></span>'
	.'<span><strong>'.$e(_('Stamp sales invoices and credit notes with KRA eTIMS')).'</strong><small>'
	.$e(_('When on, every posted Sales Invoice and Credit Note is queued for KRA stamping through the background task scheduler. When off, invoices post normally with no eTIMS submission.')).'</small></span></label>'
	.'<p class="ma-cfg-hint">'.$e(_('Stamping runs from the background task scheduler (Setup > Maintenance > Background Tasks), not while an invoice is saved, so a Stanbest outage never blocks a sale.')).'</p></div></section>'
	.'<section class="ma-panel ma-cfg-card"><div class="ma-panel-head"><h2>'.$e(_('Item Categories')).'</h2></div><div class="ma-cfg-body">'
	.'<p class="ma-cfg-hint">'.$e(_('Bring your own item categories from Stanbest (for example "Default" or "Food & Drinks") into the Item Category list on the eTIMS item registration screens. These are groupings you manage in Stanbest, not a KRA reference list.')).'</p>'
	.'<p class="ma-cfg-actions-inline"><button class="ma-btn ma-btn-secondary" type="submit" name="SYNC_CATEGORIES" value="1">'.$e(_('Sync categories now')).'</button></p></div></section>'
	.'</div>';

echo '<div class="ma-cfg-actions"><button class="ma-btn ma-btn-primary" type="submit" name="SAVE" value="1">'.$e(_('Save configuration')).'</button>'
	.'<button class="ma-btn ma-btn-secondary" type="submit" name="TEST_CONNECTION" value="1">'.$e(_('Test connection')).'</button>'
	.'<span class="ma-cfg-hint">'.$e(_('Test connection uses the saved credentials. Save first if you changed them.')).'</span></div>';
echo '</div>';
end_form();

end_page();
