<?php
/**********************************************************************
    eTIMS - configuration + cached auth token. One row (id=1), same
    "leave blank to keep the stored secret" convention as Communications'
    comm_gateway_configs (communications/includes/db/comm_gateway_config_db.inc).
***********************************************************************/

include_once(dirname(__FILE__) . '/../etims_http.inc');

function etims_get_config()
{
	return db_fetch(db_query("SELECT * FROM " . TB_PREF . "etims_config WHERE id=1",
		'Cannot get eTIMS config'));
}

// $fields is a flat column => value array of only the columns being
// changed (password/pin/username omitted entirely when left blank on
// the form, matching comm_save_gateway_config()'s convention).
function etims_save_config(array $fields)
{
	$sets = array();
	foreach ($fields as $col => $val)
		$sets[] = "`$col`=" . db_escape($val, $val === null);
	$sets[] = "updated_at=NOW()";
	db_query("UPDATE " . TB_PREF . "etims_config SET " . implode(', ', $sets) . " WHERE id=1",
		'Cannot save eTIMS config');
}

// Cheap, migration-safe gate: checked on every sales invoice/credit note
// post in the system, including for a company that has never touched
// eTIMS at all (no etims_config table yet) - get_company_pref() on an
// unseeded key just returns null, so this never queries etims_config
// unless the sys_prefs toggle is already on, which only happens once the
// deployment migration has run at least once.
function etims_stamping_active()
{
	if (!get_company_pref('use_etims_stamping'))
		return false;
	$cfg = etims_get_config();
	return $cfg && $cfg['pin'] !== '' && $cfg['username'] !== '' && $cfg['password'] !== '';
}

function etims_base_url($cfg = null)
{
	if ($cfg === null)
		$cfg = etims_get_config();
	if (!$cfg)
		return '';
	if ((int)$cfg['is_live'] === 1)
		return rtrim($cfg['live_base_url'], '/');
	return rtrim($cfg['sandbox_base_url'], '/');
}

// Decodes the "exp" claim out of a JWT without any library - just the
// middle (payload) segment, base64url-decoded. Falls back to a
// conservative 24h if the token isn't shaped as expected, rather than
// caching something with an unknown lifetime.
function etims_jwt_expiry($jwt)
{
	$parts = explode('.', (string)$jwt);
	if (count($parts) !== 3)
		return date('Y-m-d H:i:s', time() + 86400);
	$payload = strtr($parts[1], '-_', '+/');
	$payload = str_pad($payload, strlen($payload) % 4 === 0 ? strlen($payload) : strlen($payload) + (4 - strlen($payload) % 4), '=');
	$decoded = json_decode(base64_decode($payload), true);
	if (!is_array($decoded) || empty($decoded['exp']))
		return date('Y-m-d H:i:s', time() + 86400);
	// Refresh 1 day before actual expiry, not right at the edge.
	return date('Y-m-d H:i:s', (int)$decoded['exp'] - 86400);
}

// Returns a bearer token ready to use, logging in (and caching the
// result on etims_config) if there's none cached or it's due to expire.
// Throws on login failure so bg_task_etims_stamp()'s normal catch/retry
// handles it the same as any other failure.
function etims_get_access_token()
{
	include_once(dirname(__FILE__) . '/etims_client_db.inc');

	$cfg = etims_get_config();
	if (!$cfg || $cfg['pin'] === '' || $cfg['username'] === '' || $cfg['password'] === '')
		throw new Exception('eTIMS is not configured (PIN/username/password missing)');

	if (!empty($cfg['access_token']) && !empty($cfg['token_expires_at'])
		&& strtotime($cfg['token_expires_at']) > time())
		return $cfg['access_token'];

	$result = etims_login($cfg);
	if (!$result['ok'])
		throw new Exception('eTIMS login failed: ' . etims_error_message($result));

	$token = $result['data']['access_token'];
	etims_save_config(array(
		'access_token' => $token,
		'token_expires_at' => etims_jwt_expiry($token),
	));
	return $token;
}

// Extracts a human-readable message out of an etims_http_request() result,
// whether it failed at the transport level (curl error) or the API level
// (status:false, message string or array of field errors).
function etims_error_message(array $result)
{
	if (isset($result['error']))
		return $result['error'];
	$data = isset($result['data']) ? $result['data'] : array();
	if (isset($data['message'])) {
		return is_array($data['message']) ? implode('; ', $data['message']) : (string)$data['message'];
	}
	return 'Unknown eTIMS error (HTTP ' . (isset($result['http_code']) ? $result['http_code'] : '?') . ')';
}
