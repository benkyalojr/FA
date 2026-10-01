<?php
/**********************************************************************
    Communications - send engine. comm_send_notification() is the entry
    point every flow hook (milk collection is wired; payslip ready,
    sales invoice, approval request/decision are next) calls.

    Every send checks both gates before doing anything:
      1. comm_should_send()      - org-level: is this event/channel
                                    combination turned on at all?
      2. comm_recipient_allowed() - person-level: has this specific
                                    recipient opted out?
    Gating and template rendering happen synchronously here (cheap,
    DB-only). The actual gateway call does not - it's handed to the
    background task queue (see includes/bg_tasks.inc,
    cron/run_scheduled_tasks.php) via bg_task_comm_send() below,
    because a single approval action can post up to 1000 collections in
    one batch (farmer_collection_decide()'s own cap), and 1000
    synchronous HTTP calls to an SMS gateway inside one web request would
    blow past any reasonable request timeout. comm_send_raw() (the
    Gateway Settings test-send buttons) is the one exception - it stays
    synchronous, since instant feedback is the entire point of a test
    button.
***********************************************************************/

include_once(dirname(__FILE__) . '/comm_log_db.inc');
include_once(dirname(__FILE__) . '/comm_gateway_config_db.inc');
include_once(dirname(__FILE__) . '/../../../includes/bg_tasks.inc');

function comm_should_send($event_key, $channel)
{
	if (!in_array($channel, array('sms', 'email', 'whatsapp'), true))
		return false;

	if (!get_company_pref('use_communications_' . $channel))
		return false;

	$sql = "SELECT is_enabled FROM " . TB_PREF . "comm_notification_rules
		WHERE event_key=" . db_escape($event_key) . " AND channel=" . db_escape($channel);
	$row = db_fetch(db_query($sql, 'Cannot check notification rule'));
	return $row && (int)$row['is_enabled'] === 1;
}

function comm_recipient_allowed($entity_type, $entity_id, $channel)
{
	if (!in_array($channel, array('sms', 'email', 'whatsapp'), true))
		return false;

	$col = $channel . '_opt_in';
	$sql = "SELECT `$col` AS opt_in FROM " . TB_PREF . "comm_recipient_prefs
		WHERE entity_type=" . db_escape($entity_type) . " AND entity_id=" . db_escape((int)$entity_id);
	$row = db_fetch(db_query($sql, 'Cannot check recipient preference'));
	if (!$row)
		return true; // no override row = opted in
	return (int)$row['opt_in'] === 1;
}

function comm_render_template($event_key, $channel, array $placeholders)
{
	$sql = "SELECT subject, body FROM " . TB_PREF . "comm_templates
		WHERE event_key=" . db_escape($event_key) . " AND channel=" . db_escape($channel) . "
		AND is_active=1";
	$row = db_fetch(db_query($sql, 'Cannot load notification template'));
	if (!$row)
		return null;

	$search = array();
	$replace = array();
	foreach ($placeholders as $key => $value) {
		$search[] = '{' . $key . '}';
		$replace[] = (string)$value;
	}

	return array(
		'subject' => $row['subject'] !== null ? str_replace($search, $replace, $row['subject']) : null,
		'body' => str_replace($search, $replace, $row['body']),
	);
}

function comm_dispatch($channel, $to, $subject, $body)
{
	global $path_to_root;

	switch ($channel) {
		case 'sms':
			include_once($path_to_root . '/communications/includes/channels/comm_sms_digisoft.inc');
			return comm_sms_digisoft_send($to, $body, comm_get_gateway_config('sms'));
		case 'email':
			include_once($path_to_root . '/communications/includes/channels/comm_email_core.inc');
			return comm_email_core_send($to, $subject, $body);
		case 'whatsapp':
			include_once($path_to_root . '/communications/includes/channels/comm_whatsapp_meta.inc');
			return comm_whatsapp_meta_send($to, $body, comm_get_gateway_config('whatsapp'));
		default:
			return array('ok' => false, 'response' => 'Unknown channel');
	}
}

/*
	$recipient = array('type' => 'supplier'|'employee'|'customer'|'user', 'id' => int,
		'phone' => string|null, 'email' => string|null). Contact lookup differs by
	entity (suppliers/customers go through 0_crm_persons, employees/users have
	phone/email directly on the row) so resolving it is left to the caller.

	$related is an optional array('trans_type' => int, 'trans_no' => int) for
	traceability back to the transaction that triggered the notification.

	Returns an array of channel => status ('queued'|'failed'|'skipped_gate'|
	'skipped_optout') for every channel that had contact info to try - 'queued'
	means gating passed and a background task was created; the eventual
	sent/failed outcome shows up in comm_log once cron/run_scheduled_tasks.php
	processes it, not synchronously in this return value.
*/
function comm_send_notification($event_key, array $recipient, array $placeholders, $related = null)
{
	$entity_type = $recipient['type'];
	$entity_id = $recipient['id'];
	$entity_name = isset($recipient['name']) ? $recipient['name'] : null;
	$phone = isset($recipient['phone']) ? $recipient['phone'] : null;
	$email = isset($recipient['email']) ? $recipient['email'] : null;

	$addresses = array('sms' => $phone, 'email' => $email, 'whatsapp' => $phone);
	$results = array();

	foreach ($addresses as $channel => $address) {
		if (empty($address))
			continue;

		if (!comm_should_send($event_key, $channel)) {
			// Channel is switched off, or this event's rule isn't checked
			// on Notification Rules - nothing was attempted, so don't log
			// it. Per explicit direction: the delivery log is for real
			// attempts (sent/failed) and recipient opt-outs, not every
			// channel that isn't even wired up.
			$results[$channel] = 'skipped_gate';
			continue;
		}

		if (!comm_recipient_allowed($entity_type, $entity_id, $channel)) {
			comm_log_write($event_key, $channel, $entity_type, $entity_id, $address, null, null,
				'skipped_optout', null, $related, $entity_name);
			$results[$channel] = 'skipped_optout';
			continue;
		}

		$tpl = comm_render_template($event_key, $channel, $placeholders);
		if (!$tpl) {
			comm_log_write($event_key, $channel, $entity_type, $entity_id, $address, null, null,
				'failed', 'No active template for this event/channel', $related, $entity_name);
			$results[$channel] = 'failed';
			continue;
		}

		// Log as queued now; the real gateway call is a background task
		// (bg_task_comm_send() below) rather than happening inline here -
		// see the file header for why.
		comm_log_write($event_key, $channel, $entity_type, $entity_id, $address, $tpl['subject'], $tpl['body'],
			'queued', null, $related, $entity_name);
		bg_task_enqueue('comm_send', array(
			'comm_log_id' => db_insert_id(),
			'channel' => $channel,
			'to' => $address,
			'subject' => $tpl['subject'],
			'body' => $tpl['body'],
		));
		$results[$channel] = 'queued';
	}

	return $results;
}

// Bypasses templates/gating AND the background queue entirely - backs
// the Gateway Settings test-send buttons only, where instant feedback is
// the whole point. No real party - there's nothing to name.
function comm_send_raw($channel, $to, $subject, $body)
{
	$send = comm_dispatch($channel, $to, $subject, $body);
	comm_log_write('test_send', $channel, null, null, $to, $subject, $body,
		$send['ok'] ? 'sent' : 'failed', $send['response'], null, null);
	return $send;
}

// The background-task handler for a queued send - run by
// cron/run_scheduled_tasks.php, never called directly from a web
// request. Updates the comm_log row comm_send_notification() already
// wrote (status 'queued' -> 'sent'/'failed') rather than writing a new
// one, and throws on failure so the runner's retry/backoff takes over;
// comm_log keeps reflecting the most recent attempt's real outcome.
function bg_task_comm_send($payload)
{
	$comm_log_id = isset($payload['comm_log_id']) ? (int)$payload['comm_log_id'] : 0;
	$channel = isset($payload['channel']) ? $payload['channel'] : '';
	$to = isset($payload['to']) ? $payload['to'] : '';
	$subject = isset($payload['subject']) ? $payload['subject'] : null;
	$body = isset($payload['body']) ? $payload['body'] : '';

	$send = comm_dispatch($channel, $to, $subject, $body);

	db_query("UPDATE " . TB_PREF . "comm_log SET
		status=" . db_escape($send['ok'] ? 'sent' : 'failed') . ",
		provider_response=" . db_escape($send['response'], true) . "
		WHERE id=" . db_escape($comm_log_id),
		'Cannot update queued communications log entry');

	if (!$send['ok'])
		throw new RuntimeException(is_string($send['response']) ? $send['response'] : 'Send failed');
}

bg_task_register('comm_send', 'bg_task_comm_send');
