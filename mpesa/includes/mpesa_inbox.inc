<?php
/**********************************************************************
    M-Pesa - processing the stored callbacks (STK Push results and Till receipts).
    Called right after a callback arrives and again by cron/mpesa_jobs.php for any
    that failed. Every step is safe to repeat: receipts are unique and posting
    claims the row before it writes anything.
***********************************************************************/
include_once(dirname(__FILE__).'/mpesa_post.inc');
include_once(dirname(__FILE__).'/mpesa_payout.inc');

// "20260901123456" -> "2026-09-01 12:34:56"
function mpesa_parse_time($s)
{
	$s = preg_replace('/\D/', '', (string)$s);
	return strlen($s) === 14 ? substr($s, 0, 4).'-'.substr($s, 4, 2).'-'.substr($s, 6, 2).' '.substr($s, 8, 2).':'.substr($s, 10, 2).':'.substr($s, 12, 2) : date('Y-m-d H:i:s');
}

// Customers whose contact phone ends with the same 9 digits as $msisdn (empty when Safaricom sent it hashed).
function mpesa_customers_by_phone($msisdn)
{
	$d = preg_replace('/\D/', '', (string)$msisdn);
	if (strlen($d) < 9 || !ctype_digit(substr((string)$msisdn, -9))) return array();
	$tail = db_escape(substr($d, -9));
	$clean = "RIGHT(REPLACE(REPLACE(REPLACE(%s,'+',''),' ',''),'-',''),9)";
	$res = db_query("SELECT DISTINCT x.debtor_no FROM (
			SELECT c.entity_id AS debtor_no, p.phone, p.phone2 FROM ".TB_PREF."crm_contacts c JOIN ".TB_PREF."crm_persons p ON p.id=c.person_id AND !p.inactive WHERE c.type='customer'
			UNION ALL SELECT b.debtor_no, p.phone, p.phone2 FROM ".TB_PREF."crm_contacts c JOIN ".TB_PREF."crm_persons p ON p.id=c.person_id AND !p.inactive
				JOIN ".TB_PREF."cust_branch b ON b.branch_code=c.entity_id WHERE c.type='cust_branch') x
		WHERE ".sprintf($clean, 'x.phone')."=$tail OR ".sprintf($clean, 'x.phone2')."=$tail", 'phone match');
	$out = array();
	while ($r = db_fetch($res)) $out[] = (int)$r['debtor_no'];
	return array_values(array_unique($out));
}

// An invoice named by a bill reference ("002/2026" or an invoice number): array(debtor_no, type, no) or null.
function mpesa_invoice_by_reference($ref)
{
	$ref = trim((string)$ref);
	if ($ref === '') return null;
	$r = db_fetch(db_query("SELECT t.debtor_no, t.type, t.trans_no FROM ".TB_PREF."debtor_trans t LEFT JOIN ".TB_PREF."voided v ON v.type=t.type AND v.id=t.trans_no
		WHERE t.type=".ST_SALESINVOICE." AND v.id IS NULL AND (t.reference=".db_escape($ref)." OR (t.trans_no=".db_escape((int)$ref)." AND ".db_escape($ref)." REGEXP '^[0-9]+$')) LIMIT 1", 'invoice by reference'));
	return $r ? array((int)$r['debtor_no'], (int)$r['type'], (int)$r['trans_no']) : null;
}

// The one open invoice of a customer whose balance equals the amount, if exactly one does.
function mpesa_single_invoice_of_amount($debtor_no, $amount)
{
	$res = db_query("SELECT t.type, t.trans_no, (t.ov_amount+t.ov_gst+t.ov_freight+t.ov_freight_tax+t.ov_discount-t.alloc) AS due FROM ".TB_PREF."debtor_trans t
		LEFT JOIN ".TB_PREF."voided v ON v.type=t.type AND v.id=t.trans_no WHERE t.debtor_no=".(int)$debtor_no." AND t.type=".ST_SALESINVOICE." AND v.id IS NULL
		HAVING ABS(due-".db_escape($amount).") < 0.005", 'invoice by amount');
	$rows = array();
	while ($r = db_fetch($res)) $rows[] = array((int)$r['type'], (int)$r['trans_no']);
	return count($rows) === 1 ? $rows[0] : null;
}

// ---- STK Push result ------------------------------------------------------
function mpesa_handle_stk(array $payload)
{
	$cb = $payload['Body']['stkCallback'] ?? null;
	if (!$cb || empty($cb['CheckoutRequestID'])) throw new RuntimeException('Not an STK callback.');
	$tx = mpesa_tx_by_checkout($cb['CheckoutRequestID']);
	$code = (int)($cb['ResultCode'] ?? -1);
	$desc = (string)($cb['ResultDesc'] ?? '');

	if ($code !== 0) {   // declined, cancelled by the customer, timed out...
		if ($tx && in_array($tx['status'], array('pending'), true))
			mpesa_tx_update($tx['id'], array('status' => $code === 1032 ? 'cancelled' : 'failed', 'result_code' => $code, 'result_desc' => $desc, 'raw' => json_encode($payload)));
		return;
	}
	$meta = array();
	foreach ($cb['CallbackMetadata']['Item'] ?? array() as $i) $meta[$i['Name']] = $i['Value'] ?? null;
	$receipt = (string)($meta['MpesaReceiptNumber'] ?? '');
	if ($receipt === '') throw new RuntimeException('STK callback without a receipt number.');
	$existing = mpesa_tx_by_receipt($receipt);
	if ($existing && (!$tx || $existing['id'] != $tx['id'])) return;   // a repeat of a receipt already stored
	$fields = array('status' => 'received', 'receipt' => $receipt, 'amount' => (float)($meta['Amount'] ?? 0), 'phone' => (string)($meta['PhoneNumber'] ?? ''),
		'trans_time' => mpesa_parse_time($meta['TransactionDate'] ?? ''), 'result_code' => 0, 'result_desc' => $desc, 'raw' => json_encode($payload));
	if ($tx) {
		if (abs((float)$tx['amount'] - (float)$fields['amount']) > .005 && $tx['status'] === 'pending') {
			$fields['status'] = 'review'; $fields['note'] = 'Confirmed amount differs from the STK request';
			mpesa_tx_update($tx['id'], $fields); return;
		}
		if (in_array($tx['status'], array('posted'), true)) return;
		mpesa_tx_update($tx['id'], $fields);
		$id = (int)$tx['id'];
	} else {   // callback can arrive before the request response saves its checkout ID
		throw new RuntimeException('STK request not registered yet; callback retained for retry.');
	}
	$tx = mpesa_tx_get($id);
	if ($tx['debtor_no']) {
		$target = $tx['target_type'] && $tx['target_no'] ? array((int)$tx['target_type'], (int)$tx['target_no']) : null;
		list($ok, $msg) = mpesa_post_payment($id, (int)$tx['debtor_no'], $target, null);
		if (!$ok) mpesa_tx_update($id, array('status' => 'review', 'note' => $msg));
	} else
		mpesa_tx_update($id, array('status' => 'review', 'note' => 'No customer on the request'));
}

// ---- Till (C2B) receipt ----------------------------------------------------
function mpesa_handle_c2b(array $p)
{
	$receipt = (string)($p['TransID'] ?? '');
	if ($receipt === '') throw new RuntimeException('Not a C2B confirmation.');
	$existing = mpesa_tx_by_receipt($receipt);
	if ($existing && $existing['status'] !== 'received') return; // already posted or reviewed
	$cfg = mpesa_get_config();
	if (isset($p['BusinessShortCode']) && (string)$p['BusinessShortCode'] !== (string)$cfg['till_number']) throw new RuntimeException('Receipt is for a different Till.');
	if (!is_numeric($p['TransAmount'] ?? null) || (float)$p['TransAmount'] <= 0) throw new RuntimeException('Receipt amount must be positive.');
	$name = trim(($p['FirstName'] ?? '').' '.($p['MiddleName'] ?? '').' '.($p['LastName'] ?? ''));
	if (!$existing) db_query("INSERT INTO ".TB_PREF."mpesa_transactions (kind, status, phone, payer_name, amount, receipt, account_ref, trans_time, raw, created_at, updated_at) VALUES ('c2b','received',"
		.db_escape($p['MSISDN'] ?? '').", ".db_escape($name).", ".db_escape((float)($p['TransAmount'] ?? 0)).", ".db_escape($receipt).", ".db_escape($p['BillRefNumber'] ?? '').", "
		.db_escape(mpesa_parse_time($p['TransTime'] ?? '')).", ".db_escape(json_encode($p)).", NOW(), NOW())");
	$id = $existing ? (int)$existing['id'] : (int)db_insert_id();
	if ($id <= 0 || !mpesa_tx_by_receipt($receipt)) throw new RuntimeException('Could not store the Till receipt '.$receipt.'; it stays in the inbox for a retry.');
	$amount = (float)($p['TransAmount'] ?? 0);

	// 1. the payer typed an invoice number / reference
	if (($inv = mpesa_invoice_by_reference($p['BillRefNumber'] ?? '')) && mpesa_invoice_outstanding($inv[1], $inv[2]) > 0) {
		list($ok, $msg) = mpesa_post_payment($id, $inv[0], array($inv[1], $inv[2]), null);
		if (!$ok) mpesa_tx_update($id, array('status' => 'review', 'note' => $msg));
		return;
	}
	// 2. exactly one customer owns the paying phone number (only possible when the number is not hashed)
	$customers = mpesa_customers_by_phone($p['MSISDN'] ?? '');
	if (count($customers) === 1) {
		$target = mpesa_single_invoice_of_amount($customers[0], $amount);
		list($ok, $msg) = mpesa_post_payment($id, $customers[0], $target, null);
		if (!$ok) mpesa_tx_update($id, array('status' => 'review', 'note' => $msg));
		return;
	}
	mpesa_tx_update($id, array('status' => 'review', 'note' => count($customers) > 1 ? 'Phone number belongs to several customers' : 'No customer could be matched'));
}

// Process one stored callback; returns null on success or the error text.
function mpesa_process_inbox_row(array $row)
{
	$lock = 'mpesa_inbox_'.md5(TB_PREF);
	$got = db_fetch(db_query('SELECT GET_LOCK('.db_escape($lock).', 0) AS got'));
	if (!$got || !(int)$got['got']) return 'Callback processing is busy.';
	try {
		$payload = json_decode($row['body'], true);
		if (!is_array($payload)) $payload = json_decode(html_entity_decode($row['body'], ENT_QUOTES, 'UTF-8'), true);
		if (!is_array($payload)) throw new RuntimeException('Unreadable callback body.');
		switch ($row['kind']) {
			case 'stk': mpesa_handle_stk($payload); break;
			case 'c2b': mpesa_handle_c2b($payload); break;
			case 'b2c': case 'reversal': case 'b2ctimeout':
				require_once dirname(__FILE__).'/mpesa_payout.inc';
				mpesa_handle_b2c($row['kind'], $payload);
				break;
			default: break;   // validation pings etc.
		}
		mpesa_inbox_done($row['id']);
		return null;
	} catch (Throwable $e) {
		mpesa_inbox_done($row['id'], $e->getMessage());
		return $e->getMessage();
	} finally { db_query('SELECT RELEASE_LOCK('.db_escape($lock).')'); }
}

function mpesa_process_pending_inbox($limit = 50)
{
	$res = db_query("SELECT * FROM ".TB_PREF."mpesa_inbox WHERE processed_at IS NULL AND attempts < 8 ORDER BY id LIMIT ".(int)$limit, 'pending callbacks');
	$n = 0;
	while ($row = db_fetch($res)) { mpesa_process_inbox_row($row); $n++; }
	return $n;
}
