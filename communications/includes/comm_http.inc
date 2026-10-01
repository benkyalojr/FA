<?php
/**********************************************************************
    Communications - shared outbound-HTTP helper. No generic HTTP client
    exists elsewhere in this codebase (the only prior cURL use,
    gl/includes/db/gl_db_rates.inc::get_extern_rate(), is a narrow GET
    scrape, not reusable for a JSON REST API) - this is a generalized
    version of nyala's sms_gateway_post() shape, shared by the SMS and
    WhatsApp channel adapters.
***********************************************************************/

function comm_http_post_json($url, array $payload, array $headers = array(), $verify_ssl = true, $timeout = 30)
{
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(array('Content-Type: application/json'), $headers));
	curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify_ssl);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
	curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
	$response = curl_exec($ch);
	$error = curl_error($ch);
	curl_close($ch);

	if ($response === false || $response === '') {
		return array('ok' => false, 'error' => $error !== '' ? $error : 'Empty response');
	}
	$data = json_decode($response, true);
	if (!is_array($data)) {
		return array('ok' => false, 'error' => 'Invalid gateway response', 'raw' => $response);
	}
	return array('ok' => true, 'data' => $data);
}

// Normalize a Kenyan phone number to a bare 254XXXXXXXXX string, regardless
// of format on the way in - 07..., +254..., 254..., or a double-prefixed
// 254254... Shared by the SMS and WhatsApp adapters (ported from nyala's
// sms_format_phone()).
function comm_format_phone($phone)
{
	$digits = preg_replace('/\D/', '', (string) $phone);
	while ($digits !== '' && (substr($digits, 0, 3) === '254' || substr($digits, 0, 1) === '0')) {
		$digits = (substr($digits, 0, 3) === '254') ? substr($digits, 3) : substr($digits, 1);
	}
	if ($digits === '') {
		return '';
	}
	return '254' . $digits;
}
