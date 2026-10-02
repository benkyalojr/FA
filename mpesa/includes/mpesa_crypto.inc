<?php
/**********************************************************************
    M-Pesa - encryption of stored secrets (consumer key/secret, passkey,
    initiator password). AES-256-GCM with a random per-installation key kept in
    company/<n>/mpesa.key (outside the web root's reach: nginx denies company/
    and the file is mode 0600). A database dump on its own therefore does not
    reveal the secrets; restoring it to another server means re-entering them.
***********************************************************************/

function mpesa_key_path()
{
	global $path_to_root;
	$company = isset($_SESSION['wa_current_user']->cur_con) ? (int)$_SESSION['wa_current_user']->cur_con
		: (isset($GLOBALS['supplier_company_id']) ? (int)$GLOBALS['supplier_company_id'] : 0);
	return rtrim($path_to_root, '/').'/company/'.$company.'/mpesa.key';
}

function mpesa_key($create = true)
{
	$path = mpesa_key_path();
	if (is_readable($path)) {
		$key = trim((string)file_get_contents($path));
		if (preg_match('/^[0-9a-f]{64}$/i', $key)) return hex2bin($key);
		throw new RuntimeException('The M-Pesa encryption key is invalid; restore its backup.');
	}
	if (!$create) return null;
	$dir = dirname($path);
	if (!is_dir($dir) || !is_writable($dir))
		throw new RuntimeException('Cannot store the M-Pesa encryption key: '.$dir.' is not writable by PHP.');
	$key = random_bytes(32);
    $previous = umask(0077);
    $handle = @fopen($path, 'x');
    umask($previous);
    if (!$handle) {
        // A concurrent first save may have created the key. Never replace it.
        $stored = @file_get_contents($path);
        if (is_string($stored) && preg_match('/^[0-9a-f]{64}$/i', trim($stored))) return hex2bin(trim($stored));
        throw new RuntimeException('Cannot create the M-Pesa encryption key.');
    }
    try {
        if (fwrite($handle, bin2hex($key)) !== 64 || !fflush($handle)) throw new RuntimeException('Cannot write the M-Pesa encryption key.');
    } finally { fclose($handle); }
    return $key;
}

// Returns 'v1:<base64(iv|tag|cipher)>', or '' for an empty value.
function mpesa_encrypt($plain)
{
	$plain = (string)$plain;
	if ($plain === '') return '';
	$iv = random_bytes(12);
	$tag = '';
	$cipher = openssl_encrypt($plain, 'aes-256-gcm', mpesa_key(true), OPENSSL_RAW_DATA, $iv, $tag);
	if ($cipher === false) throw new RuntimeException('M-Pesa encryption failed.');
	return 'v1:'.base64_encode($iv.$tag.$cipher);
}

// Decrypts a stored value; '' for empty or when it cannot be decrypted (key missing/changed).
function mpesa_decrypt($stored)
{
	$stored = (string)$stored;
	if ($stored === '' || strpos($stored, 'v1:') !== 0) return '';
	$raw = base64_decode(substr($stored, 3), true);
	$key = mpesa_key(false);
	if ($raw === false || strlen($raw) < 29 || $key === null) return '';
	$plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
	return $plain === false ? '' : $plain;
}

function mpesa_mask($stored)
{
	return $stored === null || $stored === '' ? _('Not set') : _('Stored (encrypted)');
}
