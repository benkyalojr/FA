<?php
/**********************************************************************
    M-Pesa payouts (B2C): paying a supplier from M-Pesa.
    request -> a second person approves -> sent to Safaricom -> result callback ->
    only on success is the Supplier Payment posted in FrontAccounting.
    Switched off until a B2C shortcode and initiator are configured. Daily and
    per-payout limits apply. Needs the full FrontAccounting environment.
***********************************************************************/
include_once(dirname(__FILE__).'/mpesa_http.inc');
include_once(dirname(__FILE__).'/mpesa_post.inc');

function mpesa_load_purchasing()
{
    mpesa_load_fa();
    global $path_to_root;
    if (!function_exists('get_supplier') || !function_exists('write_supp_payment')) include_once $path_to_root.'/purchasing/includes/purchasing_db.inc';
}

function mpesa_payout_get($id)
{
	return db_fetch(db_query("SELECT * FROM ".TB_PREF."mpesa_payouts WHERE id=".(int)$id, 'Cannot get payout'));
}

function mpesa_payout_update($id, array $fields)
{
	$sets = array();
	foreach ($fields as $col => $val) $sets[] = "`$col`=".db_escape($val, $val === null);
	$sets[] = "updated_at=NOW()";
	db_query("UPDATE ".TB_PREF."mpesa_payouts SET ".implode(', ', $sets)." WHERE id=".(int)$id, 'Cannot update payout');
}

// Money already committed today (requested, approved, sent or paid) - counts toward the daily limit.
function mpesa_payout_today_total()
{
	return (float)db_fetch(db_query("SELECT COALESCE(SUM(amount),0) FROM ".TB_PREF."mpesa_payouts WHERE DATE(created_at)=CURDATE() AND status IN ('requested','approved','sent','success')"))[0];
}

// Returns array(ok, message).
function mpesa_payout_request($supplier_id, $phone, $amount, $reason, $user_id)
{
	$cfg = mpesa_get_config();
	if (!mpesa_b2c_ready($cfg)) return array(false, 'Payouts are not set up. Add the B2C details in M-Pesa Configuration.');
	$msisdn = mpesa_msisdn($phone);
	$amount = round((float)$amount, 2);
	if ($msisdn === '') return array(false, 'Enter a valid Safaricom number.');
	if ($amount < 10) return array(false, 'The smallest payout is 10.');
	if ($amount > (float)$cfg['payout_limit']) return array(false, sprintf('A single payout cannot exceed %s.', number_format($cfg['payout_limit'], 2)));
	if (mpesa_payout_today_total() + $amount > (float)$cfg['payout_daily_limit'])
		return array(false, sprintf('This would take today\'s payouts over the daily limit of %s.', number_format($cfg['payout_daily_limit'], 2)));
	if (!db_fetch(db_query("SELECT 1 FROM ".TB_PREF."suppliers WHERE supplier_id=".(int)$supplier_id." AND !inactive")))
		return array(false, 'Choose a supplier.');
	db_query("INSERT INTO ".TB_PREF."mpesa_payouts (status, supplier_id, phone, amount, reason, requested_by, bank_account_id, created_at, updated_at) VALUES ('requested', ".(int)$supplier_id.", "
		.db_escape($msisdn).", ".db_escape($amount).", ".db_escape(substr(trim($reason), 0, 200)).", ".(int)$user_id.", ".(int)$cfg['b2c_bank_account_id'].", NOW(), NOW())", 'Cannot record payout request');
	return array(true, 'Payout requested. A second person must approve it before it is sent.');
}

// Approve and send. The approver must not be the requester.
function mpesa_payout_approve($id, $approver_id)
{
	$cfg = mpesa_get_config();
	$p = mpesa_payout_get($id);
	if (!$p || $p['status'] !== 'requested') return array(false, 'This payout is no longer waiting for approval.');
	if ((int)$p['requested_by'] === (int)$approver_id) return array(false, 'A payout must be approved by someone other than the person who requested it.');
	if (!mpesa_b2c_ready($cfg)) return array(false, 'Payouts are switched off.');
	db_query("UPDATE ".TB_PREF."mpesa_payouts SET status='approved', approved_by=".(int)$approver_id.", updated_at=NOW() WHERE id=".(int)$id." AND status='requested'");
	if (db_num_affected_rows() !== 1) return array(false, 'This payout was just handled by someone else.');
	$originator = 'PO'.$id.'-'.substr(bin2hex(random_bytes(6)), 0, 12);
	$r = mpesa_b2c_send($cfg, $originator, $p['phone'], $p['amount'], $p['reason'] !== '' ? $p['reason'] : 'Supplier payment');
	if (!$r['ok'] || ($r['data']['ResponseCode'] ?? '') !== '0') {
		$why = $r['error'] !== '' ? $r['error'] : ($r['data']['ResponseDescription'] ?? 'Request refused');
		mpesa_payout_update($id, array('status' => 'failed', 'result_desc' => $why, 'originator_conversation_id' => $originator));
		return array(false, 'Safaricom did not accept the payout: '.$why);
	}
	mpesa_payout_update($id, array('status' => 'sent', 'originator_conversation_id' => $r['data']['OriginatorConversationID'] ?? $originator,
		'conversation_id' => $r['data']['ConversationID'] ?? null));
	return array(true, 'Payout sent to Safaricom. The result arrives in a moment.');
}

// Approve (and send) or reject; throws when the decision is not allowed (for example approving your own request).
function mpesa_payout_decide($id, $user_id, $approve)
{
	list($ok, $message) = $approve ? mpesa_payout_approve($id, $user_id) : mpesa_payout_reject($id, $user_id);
	if (!$ok) throw new RuntimeException($message);
	return $message;
}

function mpesa_payout_reject($id, $user_id)
{
	db_query("UPDATE ".TB_PREF."mpesa_payouts SET status='rejected', approved_by=".(int)$user_id.", updated_at=NOW() WHERE id=".(int)$id." AND status='requested'");
	return db_num_affected_rows() === 1 ? array(true, 'Payout rejected.') : array(false, 'This payout is no longer waiting for approval.');
}

// Ask Safaricom to reverse a paid-out transaction (money comes back when it succeeds).
function mpesa_payout_reverse($id)
{
	$cfg = mpesa_get_config();
	$p = mpesa_payout_get($id);
	if (!$p || $p['status'] !== 'success' || $p['receipt'] === '') return array(false, 'Only a completed payout can be reversed.');
	$r = mpesa_reverse($cfg, $p['receipt'], $p['amount'], 'Reverse payout '.$id);
	if (!$r['ok'] || ($r['data']['ResponseCode'] ?? '') !== '0')
		return array(false, 'Safaricom did not accept the reversal: '.($r['error'] !== '' ? $r['error'] : ($r['data']['ResponseDescription'] ?? '')));
	return array(true, 'Reversal requested. The payout is marked reversed when Safaricom confirms.');
}

// A payout succeeded at Safaricom: record that and post the Supplier Payment from the B2C account,
// all or nothing. If posting fails everything is rolled back and an exception is thrown, so the payout
// stays "sent" and the stored callback is retried; a repeated callback never posts twice.
function mpesa_payout_complete($id, $receipt, $desc, array $payload)
{
	global $Refs;
	mpesa_load_purchasing();
	begin_transaction();
	try {
		db_query("UPDATE ".TB_PREF."mpesa_payouts SET status='success', receipt=".db_escape($receipt).", result_desc=".db_escape($desc).", raw=".db_escape(json_encode($payload)).", payment_no=-1, updated_at=NOW()
			WHERE id=".(int)$id." AND status IN ('sent','approved') AND payment_no IS NULL");
		if (db_num_affected_rows() !== 1) { commit_transaction(); return null; }   // already completed by an earlier delivery
		$p = mpesa_payout_get($id);
		$bank = (int)($p['bank_account_id'] ?: mpesa_get_config()['b2c_bank_account_id']);
		if ($bank <= 0) throw new RuntimeException('No bank account is set for payouts.');
		$ref = $Refs->get_next(ST_SUPPAYMENT);
		$no = write_supp_payment(0, $p['supplier_id'], $bank, sql2date(date('Y-m-d')), $ref, $p['amount'], 0,
			trim('M-Pesa payout '.$receipt.' '.$p['reason']), 0, $p['amount']);
		if ((int)$no <= 0) throw new RuntimeException('The supplier payment could not be posted.');
		db_query("UPDATE ".TB_PREF."mpesa_payouts SET payment_no=".(int)$no." WHERE id=".(int)$id);
		commit_transaction();
		return (int)$no;
	} catch (Throwable $e) {
		cancel_transaction();
		throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
	}
}

// Result callbacks of payouts and reversals (called from mpesa_process_inbox_row()).
function mpesa_handle_b2c($kind, array $payload)
{
	$res = $payload['Result'] ?? null;
	if (!$res) return;
	$orig = (string)($res['OriginatorConversationID'] ?? '');
	$conv = (string)($res['ConversationID'] ?? '');
	$code = (int)($res['ResultCode'] ?? -1);
	$desc = (string)($res['ResultDesc'] ?? '');
	$params = array();
	foreach ($res['ResultParameters']['ResultParameter'] ?? array() as $i) $params[$i['Key']] = $i['Value'] ?? null;

	if ($kind === 'b2ctimeout') {   // Safaricom could not process in time: the outcome is unknown, so do not fail it
		$p = $orig !== '' ? db_fetch(db_query("SELECT id, status FROM ".TB_PREF."mpesa_payouts WHERE originator_conversation_id=".db_escape($orig))) : null;
		if ($p && $p['status'] === 'sent') mpesa_payout_update($p['id'], array('result_desc' => 'Safaricom reported a timeout. Check the M-Pesa statement before paying again.'));
		return;
	}
	if ($kind === 'reversal') {   // reversal result: find the payout by the original receipt
		$orig_receipt = (string)($params['OriginalTransactionID'] ?? $params['TransactionID'] ?? '');
		$p = $orig_receipt !== '' ? db_fetch(db_query("SELECT * FROM ".TB_PREF."mpesa_payouts WHERE receipt=".db_escape($orig_receipt))) : null;
		if ($p && $code === 0) {
			mpesa_payout_update($p['id'], array('status' => 'reversed', 'result_desc' => 'Reversed by Safaricom'));
			if ($p['payment_no'] > 0) {
				global $path_to_root;
				include_once($path_to_root.'/admin/db/voiding_db.inc');
				$msg = void_transaction(ST_SUPPAYMENT, (int)$p['payment_no'], sql2date(date('Y-m-d')), 'M-Pesa payout reversed '.$p['receipt']);
				if (is_string($msg) && $msg !== '') mpesa_payout_update($p['id'], array('result_desc' => 'Reversed, but the supplier payment was not voided: '.$msg));
			}
		}
		return;
	}
	$p = ($orig !== '' ? db_fetch(db_query("SELECT * FROM ".TB_PREF."mpesa_payouts WHERE originator_conversation_id=".db_escape($orig))) : null)
		?: ($conv !== '' ? db_fetch(db_query("SELECT * FROM ".TB_PREF."mpesa_payouts WHERE conversation_id=".db_escape($conv))) : null);
	if (!$p) throw new RuntimeException('Payout result for an unknown request '.$orig);
	if (in_array($p['status'], array('success', 'reversed'), true)) return;   // repeat of a delivery already handled
	if ($code === 0)
		mpesa_payout_complete((int)$p['id'], (string)($params['TransactionReceipt'] ?? $res['TransactionID'] ?? ''), $desc, $payload);
	else
		mpesa_payout_update($p['id'], array('status' => 'failed', 'result_desc' => $desc, 'raw' => json_encode($payload)));
}
