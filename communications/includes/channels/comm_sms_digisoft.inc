<?php
/**********************************************************************
    Communications - SMS channel adapter, DigiSoft Solutions gateway
    (digisms.digisoftsolutions.co.ke). Ported request shape from nyala's
    sms/includes/db/sms_configuration_db.inc, the only real, production
    SMS integration found across the v1 clients: JSON POST (the gateway
    rejects form-encoded bodies with a 1006 "Invalid request format"
    error), no trailing slash on the URL (a trailing-slash POST gets a
    307 redirect that drops the JSON content-type along the way).
***********************************************************************/

include_once(dirname(__FILE__) . '/../comm_http.inc');

function comm_sms_default_send_url()
{
	return 'https://digisms.digisoftsolutions.co.ke/api/services/sendsms';
}

function comm_sms_default_balance_url()
{
	return 'https://digisms.digisoftsolutions.co.ke/api/services/getbalance';
}

// $cfg is a comm_gateway_configs row for channel='sms' (see
// communications/includes/db/comm_gateway_config_db.inc).
function comm_sms_digisoft_send($mobile, $message, $cfg)
{
	if (!$cfg || empty($cfg['api_key']) || empty($cfg['sender_id']) || empty($cfg['sender_name']))
		return array('ok' => false, 'response' => 'SMS gateway is not configured');

	$mobile = comm_format_phone($mobile);
	if ($mobile === '')
		return array('ok' => false, 'response' => 'No mobile number');

	$url = !empty($cfg['send_url']) ? rtrim($cfg['send_url'], '/') : comm_sms_default_send_url();
	$result = comm_http_post_json($url, array(
		'apikey' => $cfg['api_key'],
		'partnerID' => $cfg['sender_id'],
		'message' => $message,
		'shortcode' => $cfg['sender_name'],
		'mobile' => $mobile,
	), array(), false);

	if (!$result['ok'])
		return array('ok' => false, 'response' => $result['error']);

	$details = isset($result['data']['responses'][0]) ? $result['data']['responses'][0] : null;
	if (!$details)
		return array('ok' => false, 'response' => 'Invalid gateway response');

	$status = isset($details['response-code']) ? (string)$details['response-code'] : '';
	$desc = isset($details['response-description']) ? $details['response-description'] : '';
	return array('ok' => $status === '200', 'response' => $desc !== '' ? $desc : $status);
}

function comm_sms_digisoft_balance($cfg)
{
	if (!$cfg || empty($cfg['api_key']) || empty($cfg['sender_id']))
		return array('ok' => false, 'message' => _('Enter partner ID and API key, then save.'));

	$url = !empty($cfg['balance_url']) ? rtrim($cfg['balance_url'], '/') : comm_sms_default_balance_url();
	$result = comm_http_post_json($url, array(
		'apikey' => $cfg['api_key'],
		'partnerID' => $cfg['sender_id'],
	), array(), false);

	if (!$result['ok'])
		return array('ok' => false, 'message' => _('Balance request failed: ') . $result['error']);

	$data = $result['data'];
	if (isset($data['credit']))
		return array('ok' => true, 'balance' => floatval($data['credit']));

	$message = _('Unable to fetch balance.');
	if (!empty($data['response-description']))
		$message = $data['response-description'];
	elseif (!empty($data['message']))
		$message = $data['message'];
	return array('ok' => false, 'message' => $message);
}
