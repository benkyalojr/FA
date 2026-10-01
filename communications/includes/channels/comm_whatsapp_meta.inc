<?php
/**********************************************************************
    Communications - WhatsApp channel adapter, Meta Cloud API. No v1
    client has any WhatsApp integration - fully greenfield.

    Known limitation, not solved in this slice: Meta only allows a
    free-text `text` message as a *reply* within a 24-hour
    customer-service window. Any business-initiated message outside that
    window (which is what most of this module's notification events
    are) needs a pre-approved message *template* registered in Meta
    Business Manager - an external, account-side step outside this
    codebase. This adapter only implements the free-text mode; template
    messages are a follow-up once templates exist on the account.
***********************************************************************/

include_once(dirname(__FILE__) . '/../comm_http.inc');

// $cfg is a comm_gateway_configs row for channel='whatsapp'.
function comm_whatsapp_meta_send($to, $message, $cfg)
{
	if (!$cfg || empty($cfg['phone_number_id']) || empty($cfg['access_token']))
		return array('ok' => false, 'response' => 'WhatsApp gateway is not configured');

	$phone = comm_format_phone($to);
	if ($phone === '')
		return array('ok' => false, 'response' => 'No mobile number');

	$url = 'https://graph.facebook.com/v20.0/' . rawurlencode($cfg['phone_number_id']) . '/messages';
	$result = comm_http_post_json($url, array(
		'messaging_product' => 'whatsapp',
		'to' => $phone,
		'type' => 'text',
		'text' => array('body' => $message),
	), array('Authorization: Bearer ' . $cfg['access_token']));

	if (!$result['ok'])
		return array('ok' => false, 'response' => $result['error']);

	if (isset($result['data']['error'])) {
		$err = $result['data']['error'];
		return array('ok' => false, 'response' => isset($err['message']) ? $err['message'] : 'WhatsApp API error');
	}

	$msg_id = isset($result['data']['messages'][0]['id']) ? $result['data']['messages'][0]['id'] : null;
	return array('ok' => true, 'response' => $msg_id ? ('Sent (' . $msg_id . ')') : 'Sent');
}
