<?php
/**********************************************************************
    M-Pesa - turning money received into FrontAccounting entries.
    * A confirmed STK Push for an invoice becomes a Customer Payment into the M-Pesa
      bank account, allocated to that invoice.
    * A Till receipt nobody asked for waits for review; assigning it to a customer
      posts an unallocated Customer Payment, which is then allocated on FA's
      allocation screen.
    The M-Pesa fee is posted to the bank-charge account through write_customer_payment()'s
    $charge argument (the bank account is credited net of the fee).
    Needs the full FrontAccounting environment (web session or the headless bootstrap).
***********************************************************************/
include_once(dirname(__FILE__).'/db/mpesa_config_db.inc');
include_once(dirname(__FILE__).'/db/mpesa_tx_db.inc');

function mpesa_load_fa()
{
	global $path_to_root;
	if (function_exists('write_customer_payment') && function_exists('allocate_payment') && function_exists('get_customer') && function_exists('get_default_branch') && function_exists('get_customer_trans') && function_exists('sql2date')) return;
	foreach (array('/includes/date_functions.inc', '/includes/data_checks.inc', '/sales/includes/sales_db.inc', '/gl/includes/gl_db.inc',
			'/includes/db/allocations_db.inc', '/sales/includes/db/payment_db.inc', '/sales/includes/db/custalloc_db.inc', '/includes/references.inc', '/sales/includes/db/customers_db.inc', '/sales/includes/db/branches_db.inc') as $f)
		include_once($path_to_root.$f);
}

// Till fee: percent of the amount, capped. Rounded to 2 places; 0 when switched off.
function mpesa_fee($amount, $cfg = null)
{
	$cfg = $cfg ?: mpesa_get_config();
	if (!$cfg || !(int)$cfg['fee_enabled']) return 0.0;
	return round(min((float)$cfg['fee_cap'], (float)$amount * (float)$cfg['fee_percent'] / 100), 2);
}

// Amount of an invoice still to be paid (0 when unknown, paid or voided).
function mpesa_invoice_outstanding($type, $no)
{
	$t = get_customer_trans($no, $type);
	if (!$t || get_voided_entry($type, $no)) return 0.0;
	return max(0.0, round($t['ov_amount'] + $t['ov_gst'] + $t['ov_freight'] + $t['ov_freight_tax'] + $t['ov_discount'] - $t['alloc'], 2));
}

// Posts the Customer Payment for a received transaction. $allocate_to: array(type, no) or null.
// Returns array(ok, message). Safe to call twice: the second call finds the payment already linked.
function mpesa_post_payment($tx_id, $debtor_no, $allocate_to, $posted_by)
{
	global $Refs;
	mpesa_load_fa();
	$cfg = mpesa_get_config();
	if (!$cfg || (int)$cfg['bank_account_id'] <= 0)
		return array(false, 'Choose the M-Pesa bank account in M-Pesa Configuration first.');

	begin_transaction();
	// Claim the row so two callbacks (or a callback and a retry) cannot post the same receipt twice.
	db_query("UPDATE ".TB_PREF."mpesa_transactions SET payment_no=-1 WHERE id=".(int)$tx_id." AND payment_no IS NULL AND status IN ('received','review','pending')");
	if (db_num_affected_rows() !== 1) {
		commit_transaction();
		return array(false, 'This payment has already been posted or is being posted.');
	}
	$tx = mpesa_tx_get($tx_id);

	try {
		$customer = get_customer($debtor_no);
		if (!$customer || $customer['inactive']) throw new RuntimeException('Active customer not found.');
		$bank = get_bank_account($cfg['bank_account_id']);
		if (!$bank || $bank['inactive'] || $bank['bank_curr_code'] !== 'KES' || $customer['curr_code'] !== 'KES')
			throw new RuntimeException('M-Pesa posting requires an active KES bank account and KES customer.');
		if (empty($tx['receipt']) || $tx['amount'] <= 0) throw new RuntimeException('A confirmed receipt and positive amount are required.');
		if ($allocate_to) {
			$invoice = get_customer_trans($allocate_to[1], $allocate_to[0]);
			if (!$invoice || (int)$invoice['debtor_no'] !== (int)$debtor_no) throw new RuntimeException('Invoice belongs to another customer.');
		}
		$branch = get_default_branch($debtor_no);
		if (!$branch) throw new RuntimeException('The customer has no branch.');
		$amount = round((float)$tx['amount'], 2);
		$fee = mpesa_fee($amount, $cfg);
		$date = sql2date($tx['trans_time'] ? substr($tx['trans_time'], 0, 10) : date('Y-m-d'));
		$ref = $Refs->get_next(ST_CUSTPAYMENT);
		$memo = trim('M-Pesa '.$tx['receipt'].' '.($tx['phone'] ? '('.$tx['phone'].')' : '').' '.($tx['payer_name'] ?: ''));
		$payment_no = write_customer_payment(0, $debtor_no, $branch['branch_code'], (int)$cfg['bank_account_id'], $date, $ref, $amount, 0, $memo, 0, $fee, $amount);

		$allocated = 0.0;
		if ($allocate_to) {
			$alloc = min($amount, mpesa_invoice_outstanding($allocate_to[0], $allocate_to[1]));
			if ($alloc > 0) {
				allocate_payment(ST_CUSTPAYMENT, $payment_no, $allocate_to[0], $allocate_to[1], $debtor_no, $alloc, $date);
				$allocated = $alloc;
			}
		}
		mpesa_tx_update($tx_id, array('status' => 'posted', 'payment_no' => $payment_no, 'debtor_no' => $debtor_no, 'allocated' => $allocated,
			'fee' => $fee, 'posted_by' => $posted_by, 'note' => null));

		commit_transaction();

		if (get_company_pref('use_communications')) {   // same receipt SMS/email as a manually entered payment
			global $path_to_root;
			try {
			include_once($path_to_root.'/communications/includes/db/comm_send_db.inc');
			include_once($path_to_root.'/communications/includes/db/comm_workflow_db.inc');
			comm_workflow_customer('customer_payment', $debtor_no, $branch['branch_code'], array('payment_no' => $payment_no,
				'amount' => number_format($amount, 2), 'date' => $date), array('trans_type' => ST_CUSTPAYMENT, 'trans_no' => $payment_no));
			} catch (Throwable $notice_error) { error_log('M-Pesa receipt notification failed: '.$notice_error->getMessage()); }
		}
		return array(true, sprintf('Posted customer payment %s%s.', $ref, $allocated > 0 ? ' and allocated '.number_format($allocated, 2).' to the invoice' : ''));
	} catch (Throwable $e) {
		if (function_exists('cancel_transaction')) cancel_transaction();   // write_customer_payment() may have started one
		db_query("UPDATE ".TB_PREF."mpesa_transactions SET payment_no=NULL, note=".db_escape(substr($e->getMessage(), 0, 250))." WHERE id=".(int)$tx_id." AND payment_no=-1");
		return array(false, $e->getMessage());
	}
}

// First phone number found on a customer's contacts or its branches' contacts ('' when none).
function mpesa_customer_phone($debtor_no)
{
	$res = db_query("SELECT p.phone, p.phone2 FROM ".TB_PREF."crm_contacts c JOIN ".TB_PREF."crm_persons p ON p.id=c.person_id AND !p.inactive
			WHERE c.type='customer' AND c.entity_id=".db_escape($debtor_no)."
		UNION ALL SELECT p.phone, p.phone2 FROM ".TB_PREF."crm_contacts c JOIN ".TB_PREF."crm_persons p ON p.id=c.person_id AND !p.inactive
			JOIN ".TB_PREF."cust_branch b ON b.branch_code=c.entity_id WHERE c.type='cust_branch' AND b.debtor_no=".db_escape($debtor_no), 'customer phone');
	while ($r = db_fetch($res))
		foreach (array($r['phone'], $r['phone2']) as $ph)
			if (trim((string)$ph) !== '') return trim($ph);
	return '';
}
