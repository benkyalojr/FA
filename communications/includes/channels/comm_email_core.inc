<?php
/**********************************************************************
    Communications - Email channel adapter. Sends over SMTP via a small
    hand-rolled client (comm_smtp_client.inc in this directory) per
    explicit direction to use SMTP rather than FA core's
    reporting/includes/class.mail.inc (a PHP mail() wrapper, Slice A's
    original choice). Email now has its own gateway config
    (comm_gateway_configs, channel='email': host/port/encryption/
    username/password/from), same as SMS and WhatsApp, instead of just
    reusing the company email/bcc_email prefs.
***********************************************************************/

function comm_email_core_send($to, $subject, $body)
{
	global $path_to_root;

	include_once($path_to_root . '/communications/includes/channels/comm_smtp_client.inc');
	include_once($path_to_root . '/communications/includes/db/comm_gateway_config_db.inc');

	$cfg = comm_get_gateway_config('email');
	return comm_smtp_send($cfg, $to, $subject !== null && $subject !== '' ? $subject : _('Notification'), $body);
}
