<?php
/**********************************************************************
    M-Pesa Configuration - Daraja credentials, the Till, where payments are posted,
    the callback addresses to register with Safaricom, and (optional) payouts.
    Secrets are stored encrypted; leave a secret blank to keep the stored one.
***********************************************************************/
$page_security = 'SA_MPESASETUP';
$path_to_root = "../..";
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/gl/includes/gl_db.inc");
include_once($path_to_root . "/mpesa/includes/mpesa_setup.inc");

page(_($help_context = "M-Pesa Configuration"));
$mpesa_initialized = false;
if (isset($_POST['INITIALIZE'])) {
	try {
		require_once $path_to_root.'/sql/mpesa.php';
		MigrationExecution::runAdmin(function () { migrate_mpesa(); });
		$SysPrefs->refresh();
		$mpesa_initialized = true;
		display_notification(_('M-Pesa database is ready. Complete the settings below.'));
	} catch (Throwable $ex) { display_error(html_specials_encode($ex->getMessage())); }
}
if (!mpesa_schema_ready()) {
	display_note(_('Initialize M-Pesa to create or update its tables for this company. Existing payments and credentials are preserved.'));
	start_form(); submit_center('INITIALIZE', _('Initialize / update M-Pesa')); end_form(); end_page(); return;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$mpesa_initialized && !check_csrf_token()) { end_page(); return; }
include_once($path_to_root . "/mpesa/includes/mpesa_http.inc");

$e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$posted = function ($k, $default = '') { return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $default; };
$result = null;   // banner: array(ok, text)

if (isset($_POST['SAVE'])) {
	$cfg0 = mpesa_get_config();
	$till = preg_replace('/\D/', '', $posted('till_number'));
	$store = preg_replace('/\D/', '', $posted('store_number'));
	$percent = (float)$posted('fee_percent', '0.55');
	$cap = (float)$posted('fee_cap', '200');
	$bank = (int)$posted('bank_account_id');
	if ($posted('till_number') !== '' && !ctype_digit($posted('till_number'))) $result = array(false, _('The Till number must be digits only.'));
	elseif ($posted('store_number') !== '' && !ctype_digit($posted('store_number'))) $result = array(false, _('The store / head office number must be digits only.'));
	elseif ($bank > 0 && (!( $bank_row = get_bank_account($bank)) || $bank_row['inactive'] || $bank_row['bank_curr_code'] !== 'KES')) $result = array(false, _('Choose an active KES bank account.'));
	elseif (!empty($_POST['b2c_enabled']) && (!( $b2c_bank = get_bank_account((int)$posted('b2c_bank_account_id'))) || $b2c_bank['inactive'] || $b2c_bank['bank_curr_code'] !== 'KES')) $result = array(false, _('Choose an active KES B2C bank account.'));
	elseif ($posted('public_url') !== '' && (!filter_var($posted('public_url'), FILTER_VALIDATE_URL) || parse_url($posted('public_url'), PHP_URL_SCHEME) !== 'https' || parse_url($posted('public_url'), PHP_URL_QUERY) || parse_url($posted('public_url'), PHP_URL_FRAGMENT))) $result = array(false, _('Enter a public HTTPS address without a query or fragment.'));
	elseif (!is_numeric($posted('fee_percent')) || !is_numeric($posted('fee_cap')) || !is_numeric($posted('payout_limit')) || !is_numeric($posted('payout_daily_limit'))) $result = array(false, _('Fees and payout limits must be numbers.'));
	elseif ($percent < 0 || $percent > 10 || $cap < 0) $result = array(false, _('Check the fee percentage and cap.'));
	elseif (!empty($_POST['b2c_enabled']) && ($posted('b2c_shortcode') === '' || $posted('b2c_initiator') === ''))
		$result = array(false, _('Payouts need a B2C shortcode and an initiator name.'));
	else {
		$fields = array('is_live' => !empty($_POST['is_live']) ? 1 : 0, 'till_number' => $till, 'store_number' => $store, 'public_url' => rtrim($posted('public_url'), '/'),
			'service_user' => $posted('service_user'), 'b2c_bank_account_id' => (int)$posted('b2c_bank_account_id') ?: null,
			'bank_account_id' => $bank > 0 ? $bank : null, 'fee_enabled' => !empty($_POST['fee_enabled']) ? 1 : 0, 'fee_percent' => $percent, 'fee_cap' => $cap,
			'b2c_enabled' => !empty($_POST['b2c_enabled']) ? 1 : 0, 'b2c_shortcode' => preg_replace('/\D/', '', $posted('b2c_shortcode')), 'b2c_initiator' => $posted('b2c_initiator'),
			'b2c_cert' => trim((string)($_POST['b2c_cert'] ?? '')) !== '' ? trim($_POST['b2c_cert']) : $cfg0['b2c_cert'],
			'payout_limit' => max(0, (float)$posted('payout_limit', '70000')), 'payout_daily_limit' => max(0, (float)$posted('payout_daily_limit', '150000')),
			'access_token' => null, 'token_expires_at' => null);   // new settings: fetch a fresh token next time
		foreach (array('consumer_key', 'consumer_secret', 'passkey', 'b2c_password', 'service_password') as $s)
			if ($posted($s) !== '') $fields[$s] = $posted($s);
		try {
			if ($fields['service_user'] !== '') {
				$pass = $posted('service_password') !== '' ? $posted('service_password') : mpesa_secret($cfg0, 'service_password');
				$auth = get_user_auth($fields['service_user'], md5(html_entity_decode($pass, ENT_QUOTES, 'UTF-8')));
				if (!$auth) throw new RuntimeException('The service username or password is incorrect for this company.');
			}
			mpesa_save_config($fields); $result = array(true, _('M-Pesa configuration saved.')); }
		catch (Throwable $ex) { $result = array(false, $ex->getMessage()); }
	}
}
if (isset($_POST['TEST'])) {
	$t = mpesa_token(null, true);
	$result = $t['ok'] ? array(true, _('Connected to Safaricom: an access token was issued.')) : array(false, sprintf(_('Could not connect: %s'), $t['error']));
}
if (isset($_POST['REGISTER'])) {
	$cfg0 = mpesa_get_config();
	if ($cfg0['till_number'] === '' || !mpesa_configured($cfg0)) $result = array(false, _('Save the Till number first.'));
	else {
		$r = mpesa_c2b_register($cfg0);
		$result = ($r['ok'] && (string)($r['data']['ResponseCode'] ?? '') === '0') ? array(true, _('Till receipt addresses registered with Safaricom.')) : array(false, sprintf(_('Safaricom refused the registration: %s'), $r['error']));
	}
}
if (isset($_POST['NEWSECRET'])) {
	mpesa_save_config(array('hook_secret' => bin2hex(random_bytes(16))));
	$result = array(true, _('A new secret was generated. Register the Till addresses with Safaricom again.'));
}

if (isset($_POST['RETRY'])) {
	require_once $path_to_root.'/mpesa/includes/mpesa_inbox.inc';
	db_query("UPDATE ".TB_PREF."mpesa_inbox SET attempts=0 WHERE processed_at IS NULL");
	$n = mpesa_process_pending_inbox();
	$result = array(true, sprintf(_('Processed %d queued callbacks. Check Transactions and Needs Review for results.'), $n));
}
$cfg = mpesa_get_config();
$queue = db_fetch(db_query("SELECT COUNT(*) AS pending, SUM(attempts >= 8) AS exhausted FROM ".TB_PREF."mpesa_inbox WHERE processed_at IS NULL"));
$live = (int)$cfg['is_live'] === 1;
$pill = function ($t, $tone) use ($e) { return '<span class="ma-pill '.$tone.'">'.$e($t).'</span>'; };
$field = function ($label, $name, $value, $opts = array()) use ($e) {
	$type = $opts['type'] ?? 'text';
	return '<label class="'.($opts['class'] ?? '').'">'.$e($label).'<input type="'.$type.'" name="'.$e($name).'" value="'.($type === 'password' ? '' : $e($value)).'"'
		.(isset($opts['placeholder']) ? ' placeholder="'.$e($opts['placeholder']).'"' : '').($opts['readonly'] ?? false ? ' readonly' : '').($type === 'password' ? ' autocomplete="new-password"' : '').'></label>';
};
$secret_ph = function ($f) use ($cfg) { return $cfg[$f] !== null && $cfg[$f] !== '' ? _('Leave blank to keep the stored value') : _('Not set'); };
$switch = function ($name, $checked, $title, $hint) use ($e) {
	return '<label class="ma-cfg-switch"><input type="checkbox" name="'.$e($name).'" value="1"'.($checked ? ' checked' : '').'><span class="ma-cfg-track" aria-hidden="true"></span>'
		.'<span><strong>'.$e($title).'</strong><small>'.$e($hint).'</small></span></label>';
};
$card = function ($title, $body) use ($e) { return '<section class="ma-panel ma-cfg-card"><div class="ma-panel-head"><h2>'.$e($title).'</h2></div><div class="ma-cfg-body">'.$body.'</div></section>'; };

start_form(); echo '<div class="ma-cfg">';
echo '<section class="ma-cfg-status"><div><span class="ma-cfg-label">'.$e(_('Credentials')).'</span>'.(mpesa_configured($cfg) ? $pill(_('Complete'), 'paid') : $pill(_('Incomplete'), 'late')).'</div>'
	.'<div><span class="ma-cfg-label">'.$e(_('Environment')).'</span>'.($live ? $pill(_('Live'), 'late') : $pill(_('Sandbox'), 'open')).'</div>'
	.'<div><span class="ma-cfg-label">'.$e(_('Till')).'</span><strong>'.$e($cfg['till_number'] !== '' ? $cfg['till_number'] : _('Not set')).'</strong></div>'
	.'<div><span class="ma-cfg-label">'.$e(_('Payouts')).'</span>'.(mpesa_b2c_ready($cfg) ? $pill(_('Ready'), 'paid') : $pill(_('Off'), 'pending')).'</div></section>';
if ($result) echo '<div class="ma-cfg-result '.($result[0] ? 'ok' : 'bad').'" role="status">'.$e($result[1]).'</div>';

$banks = '<label>'.$e(_('Bank account that receives M-Pesa payments')).'<select name="bank_account_id"><option value="">'.$e(_('Choose...')).'</option>';
$bres = get_bank_accounts();
while ($b = db_fetch($bres))
	$banks .= '<option value="'.(int)$b['id'].'"'.((int)$b['id'] === (int)$cfg['bank_account_id'] ? ' selected' : '').'>'.$e($b['bank_account_name'].' ('.$b['bank_curr_code'].')').'</option>';
$banks .= '</select></label>';

echo '<div class="ma-cfg-grid">';
echo $card(_('Daraja credentials'),
	$field(_('Consumer key'), 'consumer_key', '', array('type' => 'password', 'placeholder' => $secret_ph('consumer_key')))
	.$field(_('Consumer secret'), 'consumer_secret', '', array('type' => 'password', 'placeholder' => $secret_ph('consumer_secret')))
	.$field(_('Passkey'), 'passkey', '', array('type' => 'password', 'placeholder' => $secret_ph('passkey')))
	.'<p class="ma-cfg-hint">'.$e(_('From your app on the Safaricom Daraja portal. Stored encrypted.')).'</p>');
echo $card(_('Your Till'),
	$switch('is_live', $live, _('Live mode'), _('Off = Sandbox. Switch on only with live Daraja credentials.'))
	.$field(_('Till number (Buy Goods)'), 'till_number', $cfg['till_number'], array('placeholder' => '123456'))
	.$field(_('Store / head office number'), 'store_number', $cfg['store_number'], array('placeholder' => _('The number shown as the Business Shortcode in Daraja')))
	.'<p class="ma-cfg-hint">'.$e(_('A payment request is sent with the store number as the shortcode and the Till as the party that gets paid.')).'</p>');
echo '</div><div class="ma-cfg-grid">';
echo $card(_('Posting'),
	$banks
	.$switch('fee_enabled', (int)$cfg['fee_enabled'] === 1, _('Post the M-Pesa fee as a bank charge'), _('The bank account is credited net of the fee and the fee goes to the bank-charge account.'))
	.'<div class="ma-gw-grid">'.$field(_('Fee percentage'), 'fee_percent', $cfg['fee_percent']).$field(_('Fee cap (KES)'), 'fee_cap', $cfg['fee_cap']).'</div>'
	.'<p class="ma-cfg-hint">'.$e(_('Check these against your Till tariff. Reconciliation shows any difference from the real statement.')).'</p>');
$urls = '';
foreach (array('stk' => _('STK Push result'), 'c2b' => _('Till receipt (confirmation)'), 'c2bv' => _('Till receipt (validation)')) as $k => $label)
	$urls .= '<label>'.$e($label).'<input type="text" readonly value="'.$e(mpesa_hook_url($k, $cfg)).'" onclick="this.select()"></label>';
echo $card(_('Callback addresses'),
	$field(_('Public address of this site (leave blank to use the current one)'), 'public_url', $cfg['public_url'], array('placeholder' => 'https://erp.example.co.ke'))
	.$urls
	.'<p class="ma-cfg-hint">'.$e(_('Safaricom calls these addresses. They contain a secret, so do not share them. After changing the secret or the public address, register the Till addresses again.')).'</p>'
	.'<div class="ma-cfg-actions"><button class="ma-btn ma-btn-secondary" type="submit" name="REGISTER" value="1">'.$e(_('Register Till addresses with Safaricom')).'</button>'
	.'<button class="ma-btn ma-btn-secondary" type="submit" name="NEWSECRET" value="1" onclick="return confirm(\''.$e(addslashes(_('Generate a new secret? The old addresses stop working until you register the new ones.'))).'\')">'.$e(_('New secret')).'</button></div>');
echo '</div>';
echo $card(_('Background processing'),
	'<p>'.$e(sprintf(_('Queued callbacks: %d. Exhausted retries: %d.'), $queue['pending'], $queue['exhausted'])).'</p>'
	.
	$field(_('FrontAccounting service username'), 'service_user', $cfg['service_user'])
	.$field(_('Service account password'), 'service_password', '', array('type' => 'password', 'placeholder' => $secret_ph('service_password')))
	.'<p class="ma-cfg-hint">'.$e(_('Use an active FrontAccounting account for this company with customer and supplier payment permissions. The password is stored encrypted. Callbacks and the existing scheduled-task runner use this account.')).'</p>'
	.'<button class="ma-btn ma-btn-secondary" type="submit" name="RETRY" value="1">'.$e(_('Process queued callbacks')).'</button>');
$payout_banks = str_replace('name="bank_account_id"', 'name="b2c_bank_account_id"', $banks);
$payout_banks = preg_replace('/ selected/', '', $payout_banks);
$payout_banks = str_replace('value="'.(int)$cfg['b2c_bank_account_id'].'"', 'value="'.(int)$cfg['b2c_bank_account_id'].'" selected', $payout_banks);
$payout_banks = str_replace(_('Bank account that receives M-Pesa payments'), _('Bank account holding B2C funds'), $payout_banks);
echo $card(_('Payouts (B2C) - optional'),
	'<p class="ma-cfg-hint">'.$e(_('Paying suppliers or staff from M-Pesa needs a separate B2C shortcode with an initiator account. A Till cannot send money out. Leave this off until you have those details.')).'</p>'
	.$payout_banks
	.$switch('b2c_enabled', (int)$cfg['b2c_enabled'] === 1, _('Enable payouts'), _('Adds the Payouts tab actions. Every payout still needs a second person to approve it.'))
	.'<div class="ma-gw-grid">'.$field(_('B2C shortcode'), 'b2c_shortcode', $cfg['b2c_shortcode']).$field(_('Initiator name'), 'b2c_initiator', $cfg['b2c_initiator'])
	.$field(_('Initiator password'), 'b2c_password', '', array('type' => 'password', 'placeholder' => $secret_ph('b2c_password')))
	.$field(_('Largest single payout (KES)'), 'payout_limit', $cfg['payout_limit']).$field(_('Largest total per day (KES)'), 'payout_daily_limit', $cfg['payout_daily_limit']).'</div>'
	.'<label>'.$e(_('Safaricom certificate (PEM)')).'<textarea name="b2c_cert" rows="4" placeholder="'.$e(trim((string)$cfg['b2c_cert']) !== '' ? _('Stored. Paste a new certificate to replace it.') : '-----BEGIN CERTIFICATE-----').'"></textarea></label>');

echo '<div class="ma-cfg-actions"><button class="ma-btn ma-btn-primary" type="submit" name="SAVE" value="1">'.$e(_('Save configuration')).'</button>'
	.'<button class="ma-btn ma-btn-secondary" type="submit" name="TEST" value="1">'.$e(_('Test connection')).'</button>'
	.'<span class="ma-cfg-hint">'.$e(_('Test connection uses the saved credentials. Save first if you changed them.')).'</span></div>';
echo '</div>'; end_form();
end_page();
