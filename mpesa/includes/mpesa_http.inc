<?php
/**********************************************************************
    M-Pesa - Safaricom Daraja client. Plain curl, no library. Every call returns
    array('ok'=>bool, 'http_code'=>int, 'data'=>array|null, 'error'=>string).
    The base URL can be pointed at a local mock for testing by defining the constant or the
    environment variable MPESA_BASE_URL_OVERRIDE (never set in production).
***********************************************************************/
include_once(dirname(__FILE__).'/db/mpesa_config_db.inc');

// 2547XXXXXXXX from 07.., 01.., 7.., +254.., 254..; '' when it cannot be a Kenyan mobile number.
function mpesa_msisdn($phone)
{
	$d = preg_replace('/\D+/', '', (string)$phone);
	if (preg_match('/^254[17][0-9]{8}$/', $d)) return $d;
	if (preg_match('/^0[17][0-9]{8}$/', $d)) return '254'.substr($d, 1);
	if (strlen($d) === 9 && in_array($d[0], array('7', '1'), true)) return '254'.$d;
	return '';
}

function mpesa_http($method, $url, array $headers = array(), $body = null, $timeout = 30)
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(array('Content-Type: application/json', 'Accept: application/json'), $headers));
	if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, mpesa_base_override() === '');
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
	curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
	$response = curl_exec($ch);
	$err = curl_error($ch);
	$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	$data = is_string($response) && $response !== '' ? json_decode($response, true) : null;
	$ok = $response !== false && $code >= 200 && $code < 300 && is_array($data);
	return array('ok' => $ok, 'http_code' => $code, 'data' => is_array($data) ? $data : null,
		'error' => $ok ? '' : ($err !== '' ? $err : (is_array($data) ? mpesa_error_text($data) : 'HTTP '.$code.' '.substr((string)$response, 0, 200))));
}

function mpesa_error_text(array $data)
{
	foreach (array('errorMessage', 'ResponseDescription', 'ResultDesc', 'error_description', 'error') as $k)
		if (!empty($data[$k]) && is_string($data[$k])) return $data[$k];
	return 'Request failed';
}

function mpesa_base_override()
{
	if (defined('MPESA_BASE_URL_OVERRIDE')) return rtrim(MPESA_BASE_URL_OVERRIDE, '/');
	$env = getenv('MPESA_BASE_URL_OVERRIDE');
	return $env ? rtrim($env, '/') : '';
}

function mpesa_url($cfg, $path)
{
	return (mpesa_base_override() !== '' ? mpesa_base_override() : mpesa_base_url($cfg)).$path;
}

function mpesa_whole_amount($amount)
{
	if (!is_numeric($amount) || !is_finite((float)$amount) || $amount < 1 || abs((float)$amount - round((float)$amount)) > 0.000001)
		throw new InvalidArgumentException('Enter a positive amount in whole Kenya shillings.');
	return (int)$amount;
}

// OAuth token, cached in the config row until shortly before it expires.
function mpesa_token($cfg = null, $force = false)
{
	$cfg = $cfg ?: mpesa_get_config();
	if (!$force && !empty($cfg['access_token']) && !empty($cfg['token_expires_at']) && strtotime($cfg['token_expires_at']) > time() + 60) {
		$t = mpesa_decrypt($cfg['access_token']);
		if ($t !== '') return array('ok' => true, 'token' => $t);
	}
	$key = mpesa_secret($cfg, 'consumer_key');
	$secret = mpesa_secret($cfg, 'consumer_secret');
	if ($key === '' || $secret === '') return array('ok' => false, 'error' => 'Consumer key and secret are not saved.');
	$r = mpesa_http('GET', mpesa_url($cfg, '/oauth/v1/generate?grant_type=client_credentials'), array('Authorization: Basic '.base64_encode($key.':'.$secret)));
	if (!$r['ok'] || empty($r['data']['access_token']))
		return array('ok' => false, 'error' => $r['error'] !== '' ? $r['error'] : 'No access token returned.');
	$ttl = max(60, (int)($r['data']['expires_in'] ?? 3599));
	db_query("UPDATE ".TB_PREF."mpesa_config SET access_token=".db_escape(mpesa_encrypt($r['data']['access_token'])).", token_expires_at=DATE_ADD(NOW(), INTERVAL ".(int)$ttl." SECOND) WHERE id=1");
	return array('ok' => true, 'token' => $r['data']['access_token']);
}

function mpesa_call($cfg, $path, array $body, $timeout = 30)
{
	$t = mpesa_token($cfg);
	if (!$t['ok']) return array('ok' => false, 'http_code' => 0, 'data' => null, 'error' => $t['error']);
	$r = mpesa_http('POST', mpesa_url($cfg, $path), array('Authorization: Bearer '.$t['token']), $body, $timeout);
	if (!$r['ok'] && $r['http_code'] === 401) {          // token revoked early: refresh once
		$t = mpesa_token($cfg, true);
		if ($t['ok']) $r = mpesa_http('POST', mpesa_url($cfg, $path), array('Authorization: Bearer '.$t['token']), $body, $timeout);
	}
	return $r;
}

function mpesa_stk_password($cfg, $timestamp)
{
	return base64_encode($cfg['store_number'].mpesa_secret($cfg, 'passkey').$timestamp);
}

// STK Push on a Till ("Buy Goods"): BusinessShortCode is the store / head-office number, PartyB the Till.
function mpesa_stk_push($cfg, $phone, $amount, $account_ref, $description)
{
	$ts = date('YmdHis');
	return mpesa_call($cfg, '/mpesa/stkpush/v1/processrequest', array(
		'BusinessShortCode' => $cfg['store_number'], 'Password' => mpesa_stk_password($cfg, $ts), 'Timestamp' => $ts,
		'TransactionType' => 'CustomerBuyGoodsOnline', 'Amount' => mpesa_whole_amount($amount), 'PartyA' => $phone, 'PartyB' => $cfg['till_number'],
		'PhoneNumber' => $phone, 'CallBackURL' => mpesa_hook_url('stk', $cfg),
		'AccountReference' => substr($account_ref, 0, 12), 'TransactionDesc' => substr($description, 0, 13)));
}

function mpesa_stk_query($cfg, $checkout_request_id)
{
	$ts = date('YmdHis');
	return mpesa_call($cfg, '/mpesa/stkpushquery/v1/query', array(
		'BusinessShortCode' => $cfg['store_number'], 'Password' => mpesa_stk_password($cfg, $ts), 'Timestamp' => $ts,
		'CheckoutRequestID' => $checkout_request_id));
}

// Register where Safaricom sends Till (C2B) confirmations. Validation is optional (needs enabling by Safaricom).
function mpesa_c2b_register($cfg)
{
	return mpesa_call($cfg, '/mpesa/c2b/v2/registerurl', array(
		'ShortCode' => $cfg['till_number'], 'ResponseType' => 'Completed',
		'ConfirmationURL' => mpesa_hook_url('c2b', $cfg), 'ValidationURL' => mpesa_hook_url('c2bv', $cfg)));
}

// Daraja wants the initiator password RSA-encrypted with Safaricom's public certificate.
function mpesa_security_credential($cfg)
{
	$cert = trim((string)$cfg['b2c_cert']);
	$pw = mpesa_secret($cfg, 'b2c_password');
	if ($cert === '' || $pw === '') return '';
	$pub = openssl_pkey_get_public($cert);
	if (!$pub || !openssl_public_encrypt($pw, $out, $pub, OPENSSL_PKCS1_PADDING)) return '';
	return base64_encode($out);
}

function mpesa_b2c_send($cfg, $originator_id, $phone, $amount, $remarks)
{
	return mpesa_call($cfg, '/mpesa/b2c/v3/paymentrequest', array(
		'OriginatorConversationID' => $originator_id, 'InitiatorName' => $cfg['b2c_initiator'], 'SecurityCredential' => mpesa_security_credential($cfg),
		'CommandID' => 'BusinessPayment', 'Amount' => mpesa_whole_amount($amount), 'PartyA' => $cfg['b2c_shortcode'], 'PartyB' => $phone,
		'Remarks' => substr($remarks, 0, 100), 'QueueTimeOutURL' => mpesa_hook_url('b2ctimeout', $cfg), 'ResultURL' => mpesa_hook_url('b2c', $cfg),
		'Occassion' => ''));
}

function mpesa_reverse($cfg, $receipt, $amount, $remarks)
{
	return mpesa_call($cfg, '/mpesa/reversal/v1/request', array(
		'Initiator' => $cfg['b2c_initiator'], 'SecurityCredential' => mpesa_security_credential($cfg), 'CommandID' => 'TransactionReversal',
		'TransactionID' => $receipt, 'Amount' => mpesa_whole_amount($amount), 'ReceiverParty' => $cfg['b2c_shortcode'], 'RecieverIdentifierType' => '11',
		'ResultURL' => mpesa_hook_url('reversal', $cfg), 'QueueTimeOutURL' => mpesa_hook_url('b2ctimeout', $cfg),
		'Remarks' => substr($remarks, 0, 100), 'Occasion' => ''));
}
