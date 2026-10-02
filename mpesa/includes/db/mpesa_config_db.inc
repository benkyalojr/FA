<?php
/**********************************************************************
    M-Pesa - configuration (one row, id=1) and the cached Daraja token.
    "Leave blank to keep the stored secret", like eTIMS.
***********************************************************************/
include_once(dirname(__FILE__).'/../mpesa_crypto.inc');

const MPESA_SECRET_FIELDS = array('consumer_key', 'consumer_secret', 'passkey', 'b2c_password', 'service_password');

function mpesa_get_config()
{
	return db_fetch(db_query("SELECT * FROM ".TB_PREF."mpesa_config WHERE id=1", 'Cannot get M-Pesa config'));
}

// Decrypted value of one secret column of $cfg.
function mpesa_secret($cfg, $field)
{
	return $cfg ? mpesa_decrypt($cfg[$field]) : '';
}

// $fields: column => value, secrets given in plain text (encrypted here). Leave a secret out to keep it.
function mpesa_save_config(array $fields)
{
	$sets = array();
	foreach ($fields as $col => $val) {
		if (in_array($col, MPESA_SECRET_FIELDS, true)) $val = mpesa_encrypt($val);
		$sets[] = "`$col`=".db_escape($val, $val === null);
	}
	$sets[] = "updated_at=NOW()";
	db_query("UPDATE ".TB_PREF."mpesa_config SET ".implode(', ', $sets)." WHERE id=1", 'Cannot save M-Pesa config');
}

function mpesa_base_url($cfg = null)
{
	$cfg = $cfg ?: mpesa_get_config();
	return $cfg && (int)$cfg['is_live'] === 1 ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
}

// The public address Safaricom calls back: <base>/pay/hook/<secret>/<kind>. The path avoids the
// word "mpesa" on purpose - Daraja refuses callback URLs that contain it.
function mpesa_hook_url($kind, $cfg = null)
{
	$cfg = $cfg ?: mpesa_get_config();
	$base = trim((string)$cfg['public_url']);
	if ($base === '') {
		$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
		$base = ($https ? 'https' : 'http').'://'.($_SERVER['HTTP_HOST'] ?? 'localhost');
	}
	$company = (int)($_SESSION['wa_current_user']->cur_con ?? $GLOBALS['supplier_company_id'] ?? 0);
	return rtrim($base, '/').'/pay/hook/'.$company.'/'.$cfg['hook_secret'].'/'.$kind;
}

function mpesa_configured($cfg = null)
{
	$cfg = $cfg ?: mpesa_get_config();
	return $cfg && mpesa_secret($cfg, 'consumer_key') !== '' && mpesa_secret($cfg, 'consumer_secret') !== ''
		&& $cfg['till_number'] !== '' && $cfg['store_number'] !== '' && mpesa_secret($cfg, 'passkey') !== '' && (int)$cfg['bank_account_id'] > 0;
}

function mpesa_b2c_ready($cfg = null)
{
	$cfg = $cfg ?: mpesa_get_config();
	return $cfg && (int)$cfg['b2c_enabled'] === 1 && (int)($cfg['b2c_bank_account_id'] ?? 0) > 0 && $cfg['b2c_shortcode'] !== '' && $cfg['b2c_initiator'] !== ''
		&& mpesa_secret($cfg, 'b2c_password') !== '' && trim((string)$cfg['b2c_cert']) !== '';
}
