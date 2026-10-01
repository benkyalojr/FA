<?php
/**********************************************************************
    Communications - minimal raw-socket SMTP client. No SMTP-capable
    library exists in this codebase (confirmed: no composer.json, no
    vendor/, no PHPMailer/SwiftMailer anywhere, and no package manager
    is used at all) - this is a small hand-rolled client rather than
    introducing composer as a first for the codebase, same "own code
    over new dependency machinery" pattern already used elsewhere (e.g.
    payslip/P9 rendering built on FrontReport's coordinate API instead
    of patching the bundled 2008 TCPDF for writeHTML()).

    Supports AUTH LOGIN over plaintext, implicit SSL (connect wrapped in
    ssl://, e.g. port 465) or STARTTLS (upgrade after EHLO, e.g. port
    587) - the three schemes covering the common providers (Gmail,
    Mailgun, SendGrid, a local Postfix/relay, ...).
***********************************************************************/

class comm_smtp_exception extends Exception {}

function comm_smtp_local_hostname()
{
	$host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']) : '';
	return $host !== '' ? $host : 'localhost';
}

// Strips CR/LF so untrusted values (recipient address, subject, from
// name/email - ultimately sourced from message templates and the
// test-send screen) can never inject extra SMTP commands or extra MIME
// headers.
function comm_smtp_sanitize_header($value)
{
	return trim(str_replace(array("\r", "\n"), '', (string)$value));
}

function comm_smtp_read_line($sock)
{
	$line = fgets($sock, 1024);
	if ($line === false)
		throw new comm_smtp_exception('Connection closed by server');
	return $line;
}

// Reads a (possibly multi-line, "250-..." continuation) SMTP reply and
// checks its status code against $expect (int or array of acceptable
// ints).
function comm_smtp_expect($sock, $expect)
{
	$expect = (array)$expect;
	$last = '';
	do {
		$last = comm_smtp_read_line($sock);
		$continues = isset($last[3]) && $last[3] === '-';
	} while ($continues);

	$code = (int)substr($last, 0, 3);
	if (!in_array($code, $expect, true))
		throw new comm_smtp_exception('Unexpected SMTP reply: ' . trim($last));
	return $last;
}

function comm_smtp_command($sock, $command, $expect)
{
	fwrite($sock, $command . "\r\n");
	return comm_smtp_expect($sock, $expect);
}

// RFC 5321 "dot-stuffing": a body line that starts with '.' must have
// the '.' doubled, or the server reads it as the end-of-DATA marker.
function comm_smtp_dot_stuff($text)
{
	$lines = explode("\n", str_replace("\r\n", "\n", (string)$text));
	foreach ($lines as &$line) {
		$line = rtrim($line, "\r");
		if (isset($line[0]) && $line[0] === '.')
			$line = '.' . $line;
	}
	unset($line);
	return implode("\r\n", $lines);
}

function comm_smtp_build_message($from_email, $from_name, $to, $subject, $body)
{
	$from_email = comm_smtp_sanitize_header($from_email);
	$from_name = comm_smtp_sanitize_header($from_name);
	$to = comm_smtp_sanitize_header($to);
	$subject = comm_smtp_sanitize_header($subject);

	$headers = array();
	$headers[] = 'From: ' . ($from_name !== '' ? $from_name . ' <' . $from_email . '>' : $from_email);
	$headers[] = 'To: <' . $to . '>';
	$headers[] = 'Subject: ' . $subject;
	$headers[] = 'Date: ' . date('r');
	$headers[] = 'Message-ID: <' . uniqid('comm.', true) . '@' . comm_smtp_local_hostname() . '>';
	$headers[] = 'MIME-Version: 1.0';
	$headers[] = 'Content-Type: text/plain; charset=UTF-8';
	$headers[] = 'Content-Transfer-Encoding: 8bit';

	return implode("\r\n", $headers) . "\r\n\r\n" . comm_smtp_dot_stuff($body);
}

/*
	$cfg is a comm_gateway_configs row for channel='email': smtp_host,
	smtp_port, smtp_encryption ('none'|'ssl'|'tls'), smtp_username,
	smtp_password, smtp_from_email, smtp_from_name.
*/
function comm_smtp_send($cfg, $to, $subject, $body)
{
	if (!$cfg || empty($cfg['smtp_host']))
		return array('ok' => false, 'response' => 'SMTP is not configured (Communications > Gateway Settings).');

	$host = $cfg['smtp_host'];
	$port = !empty($cfg['smtp_port']) ? (int)$cfg['smtp_port'] : 587;
	$encryption = !empty($cfg['smtp_encryption']) ? $cfg['smtp_encryption'] : 'tls';
	$username = (string)$cfg['smtp_username'];
	$password = (string)$cfg['smtp_password'];
	$from_email = !empty($cfg['smtp_from_email']) ? $cfg['smtp_from_email'] : (string)get_company_pref('email');
	$from_name = !empty($cfg['smtp_from_name']) ? $cfg['smtp_from_name'] : (string)get_company_pref('coy_name');
	$bcc = (string)get_company_pref('bcc_email');

	if ($from_email === '')
		return array('ok' => false, 'response' => 'No from address configured (SMTP settings or Company Preferences email).');

	$to = trim((string)$to);
	if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL))
		return array('ok' => false, 'response' => 'Invalid recipient email address');

	$transport = ($encryption === 'ssl') ? 'ssl://' . $host : $host;
	$errno = 0;
	$errstr = '';
	$sock = @fsockopen($transport, $port, $errno, $errstr, 15);
	if (!$sock)
		return array('ok' => false, 'response' => 'Connection failed: ' . $errstr);
	stream_set_timeout($sock, 15);

	try {
		comm_smtp_expect($sock, 220);
		comm_smtp_command($sock, 'EHLO ' . comm_smtp_local_hostname(), 250);

		if ($encryption === 'tls') {
			comm_smtp_command($sock, 'STARTTLS', 220);
			if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT))
				throw new comm_smtp_exception('STARTTLS negotiation failed');
			comm_smtp_command($sock, 'EHLO ' . comm_smtp_local_hostname(), 250);
		}

		if ($username !== '' && $password !== '') {
			comm_smtp_command($sock, 'AUTH LOGIN', 334);
			comm_smtp_command($sock, base64_encode($username), 334);
			comm_smtp_command($sock, base64_encode($password), 235);
		}

		comm_smtp_command($sock, 'MAIL FROM:<' . comm_smtp_sanitize_header($from_email) . '>', 250);
		comm_smtp_command($sock, 'RCPT TO:<' . comm_smtp_sanitize_header($to) . '>', array(250, 251));
		if ($bcc !== '' && filter_var($bcc, FILTER_VALIDATE_EMAIL))
			comm_smtp_command($sock, 'RCPT TO:<' . comm_smtp_sanitize_header($bcc) . '>', array(250, 251));

		comm_smtp_command($sock, 'DATA', 354);
		$message = comm_smtp_build_message($from_email, $from_name, $to, $subject, $body);
		fwrite($sock, $message . "\r\n.\r\n");
		comm_smtp_expect($sock, 250);

		fwrite($sock, "QUIT\r\n");
		fclose($sock);
		return array('ok' => true, 'response' => 'Sent');
	} catch (comm_smtp_exception $e) {
		@fwrite($sock, "QUIT\r\n");
		fclose($sock);
		return array('ok' => false, 'response' => $e->getMessage());
	}
}
