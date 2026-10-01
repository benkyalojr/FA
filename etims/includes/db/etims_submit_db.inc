<?php
/**********************************************************************
    eTIMS - performs and/or queues the actual KRA stamping of a sales
    invoice or credit note.

    The hook in sales/includes/db/sales_invoice_db.inc /
    sales_credit_db.inc runs AFTER commit_transaction() - the sale is
    already durable no matter what happens next - and calls
    etims_stamp_now_or_queue(): try stamping synchronously, right there,
    with a short timeout (SYNC_STAMP_TIMEOUT), so the common case (KRA/
    Stanbest responds normally) leaves the invoice already stamped by
    the time the screen reloads, no ~60s wait for the next cron tick.
    Any failure - timeout, network error, an unregistered item, Stanbest
    down - falls back to the background task queue (same queue
    Communications' SMS/Email/WhatsApp sends already use - see
    includes/bg_tasks.inc, cron/run_scheduled_tasks.php) with its normal
    retry/backoff. A sale is never blocked or slowed by more than
    SYNC_STAMP_TIMEOUT regardless of what Stanbest does.

    etims_submissions is the domain source of truth for "was this
    invoice stamped, and with what KRA data" - it only ever flips to
    'stamped' on real success. Live attempt/retry state for a queued
    (not synchronously stamped) submission lives on the linked bg_tasks
    row (bg_task_id); the inquiry screen joins the two rather than
    duplicating status tracking.
***********************************************************************/

// Kept short deliberately - this runs inline in the sales invoice/credit
// note request when the synchronous attempt is tried. Long enough for a
// normal API round trip, short enough that a slow/hanging Stanbest
// doesn't leave a cashier staring at a spinner.
define('ETIMS_SYNC_STAMP_TIMEOUT', 8);

// ST_SALESINVOICE/ST_CUSTCREDIT come from here - already loaded via
// includes/session.inc in every normal web request, but NOT by the
// minimal CLI/cron bootstrap (scripts/supplier_types_cli.inc) that
// cron/run_scheduled_tasks.php uses, and this file is the first bg_tasks
// consumer that actually needs FA transaction-type constants (unlike
// Communications' comm_send_db.inc). Safe no-op include_once when
// already loaded.
include_once($path_to_root . '/includes/types.inc');
include_once($path_to_root . '/includes/bg_tasks.inc');
include_once(dirname(__FILE__) . '/etims_config_db.inc');
include_once(dirname(__FILE__) . '/etims_client_db.inc');
include_once(dirname(__FILE__) . '/etims_item_mapping_db.inc');
include_once($path_to_root . '/sales/includes/db/cust_trans_db.inc');
include_once($path_to_root . '/sales/includes/db/cust_trans_details_db.inc');

// KRA's own fixed rate table (not merchant-configurable - every example
// in the Stanbest docs uses these same constants regardless of business).
function etims_tax_rate($tax_ty_cd)
{
	switch ($tax_ty_cd) {
		case 'B': return 16;
		case 'E': return 8;
		default: return 0; // A (exempt), C (zero-rated), D (non-VAT)
	}
}

// Queues (or re-queues) stamping for one FA transaction. Safe to call
// more than once for the same trans_no - re-arms the existing row/task
// rather than creating a duplicate (mirrors Payroll's staff-purchase
// hook idempotency).
function etims_queue_stamp($trans_type, $trans_no)
{
	$existing = db_fetch(db_query("SELECT id, status FROM " . TB_PREF . "etims_submissions
		WHERE trans_type=" . db_escape($trans_type) . " AND trans_no=" . db_escape($trans_no),
		'Cannot check eTIMS submission'));

	if ($existing && $existing['status'] === 'stamped')
		return; // already stamped - never re-submit a successfully stamped document

	etims_ensure_submission_row($trans_type, $trans_no);

	$task_id = bg_task_enqueue('etims_stamp', array('trans_type' => $trans_type, 'trans_no' => $trans_no));

	db_query("UPDATE " . TB_PREF . "etims_submissions SET bg_task_id=" . db_escape($task_id) . ", updated_at=NOW()
		WHERE trans_type=" . db_escape($trans_type) . " AND trans_no=" . db_escape($trans_no),
		'Cannot update eTIMS submission');
}

// Manual re-arm from the inquiry screen for a submission whose bg_task
// has already exhausted its retries.
function etims_retry_stamp($submission_id)
{
	$row = db_fetch(db_query("SELECT trans_type, trans_no FROM " . TB_PREF . "etims_submissions
		WHERE id=" . (int)$submission_id, 'Cannot get eTIMS submission'));
	if ($row)
		etims_queue_stamp($row['trans_type'], $row['trans_no']);
}

// Builds the full FiscalReceipt (or CreditNote) body for one FA
// transaction. Throws if any line's stock item isn't registered with
// KRA yet - caught by bg_task_etims_stamp(), which lets the queue's own
// retry/backoff handle it (registering the missing item and retrying
// is a normal, expected recovery path here, not a fatal error).
function etims_build_fiscal_payload($trans_type, $trans_no)
{
	$cfg = etims_get_config();
	$header = get_customer_trans($trans_no, $trans_type);
	if (!$header)
		throw new Exception("Transaction $trans_type/$trans_no not found");

	$details = get_customer_trans_details($trans_type, $trans_no);

	$now = date('YmdHis');
	$sales_dt = date('Ymd', strtotime($header['tran_date']));

	$item_list = array();
	$totals = array('A' => array(0, 0, 0), 'B' => array(0, 0, 0), 'C' => array(0, 0, 0),
		'D' => array(0, 0, 0), 'E' => array(0, 0, 0)); // [taxblAmt, taxAmt, totAmt] per category
	$seq = 1;
	$grand_total = 0;

	while ($line = db_fetch($details)) {
		$map = etims_get_item_map($line['stock_id']);
		if (!$map || !$map['is_registered'])
			throw new Exception("Stock item '{$line['stock_id']}' is not registered with KRA yet - map and register it under Setup > eTIMS Item Mapping.");

		$tax_cd = $map['tax_ty_cd'];
		$rate = etims_tax_rate($tax_cd);
		$line_total = round((float)$line['quantity'] * (float)$line['FullUnitPrice'], 2);
		if ($rate > 0) {
			$taxbl = round($line_total / (1 + $rate / 100), 2);
			$tax = round($line_total - $taxbl, 2);
		} else {
			$taxbl = $line_total;
			$tax = 0;
		}

		$item_list[] = array(
			'itemSeq' => $seq,
			// Must be a real JSON number, not a string - db_fetch()
			// returns every column as a PHP string (mysqli's default
			// fetch mode), and unlike FiscalReceipt (which tolerates a
			// quoted "75563"), Stanbest's CreditNote endpoint rejects a
			// string itemId outright with an unhelpful generic "Credit
			// Note not created. Try again" - confirmed by testing both
			// forms directly against the sandbox.
			'itemId' => (int)$map['kra_item_id'],
			'itemCd' => $map['kra_item_cd'],
			'itemClsCd' => $map['item_cls_cd'],
			'itemNm' => mb_substr($line['StockDescription'], 0, 200),
			'itemNmDef' => mb_substr($line['StockDescription'], 0, 200),
			'bcd' => '',
			'pkgUnitCd' => $map['pkg_unit_cd'] !== '' ? $map['pkg_unit_cd'] : 'NT',
			'pkg' => 1,
			'qtyUnitCd' => $map['qty_unit_cd'] !== '' ? $map['qty_unit_cd'] : 'U',
			'qty' => (float)$line['quantity'],
			'prc' => (float)$line['FullUnitPrice'],
			'splyAmt' => $line_total,
			'dcRt' => 0,
			'dcAmt' => 0,
			'taxTyCd' => $tax_cd,
			'taxblAmt' => $taxbl,
			'taxAmt' => $tax,
			'totAmt' => $line_total,
		);

		$totals[$tax_cd][0] += $taxbl;
		$totals[$tax_cd][1] += $tax;
		$totals[$tax_cd][2] += $line_total;
		$grand_total += $line_total;
		$seq++;
	}

	if (!$item_list)
		throw new Exception("Transaction $trans_type/$trans_no has no line items to stamp");

	$cust_tin = trim((string)$header['tax_id']);

	$body = array(
		'bhfId' => $cfg['bhf_id'],
		'cfmDt' => $now,
		'custEmail' => '',
		'custId' => '0',
		'custMblNo' => '',
		'custNm' => mb_substr((string)$header['DebtorName'], 0, 19),
		'custTin' => $cust_tin,
		'custType' => $cust_tin !== '' ? 'NEW' : 'NONE',
		'invcNo' => '0',
		'itemList' => $item_list,
		'modrId' => 'Admin',
		'modrNm' => 'Admin',
		'orgInvcNo' => 0,
		'pmtTyCd' => '02', // Cash/bank - not tracked per-invoice by FA today
		'prchrAcptcYn' => 'N',
		'rcptTyCd' => 'S',
		'receipt' => array(
			'rptNo' => 1,
			'rcptPbctDt' => $now,
			'trdeNm' => '',
			'topMsg' => '',
			'btmMsg' => '',
			'prchrAcptcYn' => 'N',
			'custTin' => $cust_tin,
			'custMblNo' => '',
		),
		'regrId' => 'Admin',
		'regrNm' => 'Admin',
		'remark' => (string)$header['reference'],
		'salesDt' => $sales_dt,
		'salesSttsCd' => '02',
		'salesTyCd' => 'N',
		'stockRlsDt' => $now,
		'tin' => get_company_pref('gst_no'),
		'totAmt' => round($grand_total, 2),
		'totItemCnt' => count($item_list),
		// FA's own reference (e.g. "002/2026"), not the bare trans_no -
		// trdInvcNo is meant to BE the trader's real invoice number, and a
		// bare small integer restarting from 1 is unsafe on a long-lived
		// or shared Stanbest account (confirmed: this sandbox tenant
		// already had 1500+ unrelated sales going back to 2023, and
		// trdInvcNo="2" collided with one of them - "Sale with this
		// Trader Invoice Number already exists"). Prefixed by doc type
		// since FA numbers invoices and credit notes as separate
		// sequences - "001/2026" can legitimately exist as both.
		'trdInvcNo' => ($trans_type == ST_CUSTCREDIT ? 'CN-' : 'INV-') . $header['reference'],
	);

	foreach (array('A', 'B', 'C', 'D', 'E') as $cat) {
		$body['taxblAmt' . $cat] = round($totals[$cat][0], 2);
		$body['taxAmt' . $cat] = round($totals[$cat][1], 2);
		$body['taxRt' . $cat] = etims_tax_rate($cat);
	}
	$body['totTaxblAmt'] = round(array_sum(array_column($totals, 0)), 2);
	$body['totTaxAmt'] = round(array_sum(array_column($totals, 1)), 2);

	if ($trans_type == ST_CUSTCREDIT) {
		// orgInvcNo must be Stanbest's OWN invoice number (the invc_no
		// their FiscalReceipt response assigned, e.g. 210271) for the
		// specific invoice this credit note actually credits - not FA's
		// own trans_no, and not just "this customer's most recent
		// invoice" (the previous version of this code did both of those
		// wrong, confirmed by Stanbest's own "Original invoice N was not
		// found for this business" rejection). The real link is FA's own
		// cust_allocations row (written by write_credit_note()'s
		// credit_sales_invoice_allocate() call), not src_docs - that's
		// only available on the in-memory credit_note object at post
		// time, not re-derivable from trans_no alone on a later retry.
		$alloc = db_fetch(db_query("SELECT trans_no_to FROM " . TB_PREF . "cust_allocations
			WHERE trans_type_from=" . db_escape(ST_CUSTCREDIT) . " AND trans_no_from=" . db_escape($trans_no) . "
			AND trans_type_to=" . db_escape(ST_SALESINVOICE) . " ORDER BY id LIMIT 1",
			'Cannot find original invoice allocation for credit note'));
		if (!$alloc)
			throw new Exception("Credit note $trans_no is not allocated against a sales invoice - cannot determine the original invoice to credit.");

		$orig_sub = db_fetch(db_query("SELECT invc_no FROM " . TB_PREF . "etims_submissions
			WHERE trans_type=" . db_escape(ST_SALESINVOICE) . " AND trans_no=" . db_escape($alloc['trans_no_to']) . "
			AND status='stamped'", 'Cannot get original invoice eTIMS submission'));
		if (!$orig_sub || !$orig_sub['invc_no'])
			throw new Exception("The original invoice (trans_no {$alloc['trans_no_to']}) has not been stamped with KRA yet - stamp it before crediting.");

		$body['orgInvcNo'] = (int)$orig_sub['invc_no'];
	}

	return $body;
}

// The one place that actually talks to Stanbest and records the result -
// shared by the synchronous attempt (etims_stamp_now_or_queue()) and the
// background queue's retry (bg_task_etims_stamp()). Throws on any
// failure; never writes a 'failed' status itself, since a thrown
// exception is exactly what tells each caller "this attempt didn't
// work" and they handle that differently (fall back to the queue vs.
// let bg_tasks' own retry/backoff run).
function etims_do_stamp($trans_type, $trans_no, $timeout = 30)
{
	$cfg = etims_get_config();
	$body = etims_build_fiscal_payload($trans_type, $trans_no);

	$result = $trans_type == ST_CUSTCREDIT
		? etims_submit_credit_note($cfg, $body, $timeout)
		: etims_submit_fiscal_receipt($cfg, $body, $timeout);

	if (!$result['ok'])
		throw new Exception(etims_error_message($result));

	// Both /sales/ and /credit responses nest the actual stamped record
	// under a "sale" key (confirmed against the live sandbox - not
	// documented, and NOT the same shape as the GetAllSales listing rows,
	// which are flat) - never at the top level of the response.
	$data = isset($result['data']['sale']) ? $result['data']['sale'] : $result['data'];
	db_query("UPDATE " . TB_PREF . "etims_submissions SET
		status='stamped',
		trd_invc_no=" . db_escape(isset($data['trdInvcNo']) ? $data['trdInvcNo'] : $body['trdInvcNo']) . ",
		invc_no=" . db_escape(isset($data['invcNo']) ? $data['invcNo'] : null, !isset($data['invcNo'])) . ",
		rcpt_sign=" . db_escape(isset($data['rcptSign']) ? $data['rcptSign'] : null, !isset($data['rcptSign'])) . ",
		intrl_data=" . db_escape(isset($data['intrlData']) ? $data['intrlData'] : null, !isset($data['intrlData'])) . ",
		cur_rcpt_no=" . db_escape(isset($data['curRcptNo']) ? $data['curRcptNo'] : null, !isset($data['curRcptNo'])) . ",
		tot_rcpt_no=" . db_escape(isset($data['totRcptNo']) ? $data['totRcptNo'] : null, !isset($data['totRcptNo'])) . ",
		sdc_date_time=" . db_escape(isset($data['sdcDateTime']) ? $data['sdcDateTime'] : null, !isset($data['sdcDateTime'])) . ",
		short_url=" . db_escape(isset($data['short_url']) ? $data['short_url'] : null, !isset($data['short_url'])) . ",
		long_url=" . db_escape(isset($data['long_url']) ? $data['long_url'] : null, !isset($data['long_url'])) . ",
		stamped_at=NOW(), updated_at=NOW()
		WHERE trans_type=" . db_escape($trans_type) . " AND trans_no=" . db_escape($trans_no),
		'Cannot record eTIMS stamp result');
}

// Creates the etims_submissions row up front (status='pending', no
// bg_task yet) if one doesn't already exist - both the synchronous path
// and etims_queue_stamp() need this, so a submission is always visible
// on the inquiry screen even if the very first attempt fails before a
// queue row exists.
function etims_ensure_submission_row($trans_type, $trans_no)
{
	$doc_kind = $trans_type == ST_CUSTCREDIT ? 'credit_note' : 'invoice';
	db_query("INSERT INTO " . TB_PREF . "etims_submissions (trans_type, trans_no, doc_kind, status, created_at, updated_at)
		SELECT " . db_escape($trans_type) . ", " . db_escape($trans_no) . ", " . db_escape($doc_kind) . ", 'pending', NOW(), NOW()
		FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM " . TB_PREF . "etims_submissions
			WHERE trans_type=" . db_escape($trans_type) . " AND trans_no=" . db_escape($trans_no) . ")",
		'Cannot create eTIMS submission row');
}

// Called from the sales-invoice/credit-note hook, AFTER
// commit_transaction() - the sale is already durable regardless of what
// happens here. Tries to stamp right away with a short timeout; on any
// failure (timeout, network error, unregistered item, Stanbest down)
// falls back to the background queue exactly as before. Never throws -
// a stamping problem must never surface as a failure of the sale itself.
function etims_stamp_now_or_queue($trans_type, $trans_no)
{
	// A modified/re-saved invoice runs through write_sales_invoice() -
	// and this hook - again even when it was already successfully
	// stamped on an earlier save. Without this check, re-saving an
	// already-stamped invoice would submit a second, conflicting
	// FiscalReceipt for the same trans_no to KRA.
	$existing = db_fetch(db_query("SELECT status FROM " . TB_PREF . "etims_submissions
		WHERE trans_type=" . db_escape($trans_type) . " AND trans_no=" . db_escape($trans_no),
		'Cannot check eTIMS submission'));
	if ($existing && $existing['status'] === 'stamped')
		return;

	etims_ensure_submission_row($trans_type, $trans_no);
	db_query("UPDATE " . TB_PREF . "etims_submissions SET submitted_at=NOW(), updated_at=NOW()
		WHERE trans_type=" . db_escape($trans_type) . " AND trans_no=" . db_escape($trans_no));

	try {
		etims_do_stamp($trans_type, $trans_no, ETIMS_SYNC_STAMP_TIMEOUT);
		return; // stamped inline - nothing to queue
	} catch (Exception $e) {
		db_query("UPDATE " . TB_PREF . "etims_submissions SET updated_at=NOW()
			WHERE trans_type=" . db_escape($trans_type) . " AND trans_no=" . db_escape($trans_no));
	}

	etims_queue_stamp($trans_type, $trans_no);
}

// The bg_tasks handler (registered below) - the retry path once a
// synchronous attempt (or a previous retry) has failed. Throws on any
// failure so the queue's own backoff/retry takes over; uses the normal
// (longer) timeout since nothing is waiting on this request.
function bg_task_etims_stamp(array $payload)
{
	$trans_type = (int)$payload['trans_type'];
	$trans_no = (int)$payload['trans_no'];

	if (!etims_stamping_active())
		throw new Exception('eTIMS stamping is currently switched off');

	db_query("UPDATE " . TB_PREF . "etims_submissions SET submitted_at=NOW(), updated_at=NOW()
		WHERE trans_type=" . db_escape($trans_type) . " AND trans_no=" . db_escape($trans_no));

	etims_do_stamp($trans_type, $trans_no);
}

bg_task_register('etims_stamp', 'bg_task_etims_stamp');
