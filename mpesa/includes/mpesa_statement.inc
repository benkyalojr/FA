<?php
/**********************************************************************
    M-Pesa - reconciliation of an imported statement with what was recorded here.
    Importing itself (CSV parsing, repeat-safe storage) is in mpesa_reconcile.inc.
***********************************************************************/

// Comparison over the date range of the imported lines (or an explicit one).
function mpesa_reconcile($from = null, $to = null)
{
	$range = db_fetch(db_query("SELECT MIN(DATE(trans_time)) a, MAX(DATE(trans_time)) b FROM ".TB_PREF."mpesa_statement_lines WHERE trans_time IS NOT NULL"));
	$from = $from ?: $range['a']; $to = $to ?: $range['b'];
	if (!$from || !$to) return null;
	$f = db_escape($from.' 00:00:00'); $t = db_escape($to.' 23:59:59');
	$out = array('from' => $from, 'to' => $to);
	// money on the statement that this system does not know
	$out['missing'] = array();
	$r = db_query("SELECT l.*, t.status AS tx_status FROM ".TB_PREF."mpesa_statement_lines l LEFT JOIN ".TB_PREF."mpesa_transactions t ON t.receipt=l.receipt
		WHERE l.paid_in > 0 AND l.matched_tx_id IS NULL AND l.trans_time BETWEEN $f AND $t ORDER BY l.trans_time", 'missing');
	while ($x = db_fetch($r)) $out['missing'][] = $x;
	// recorded here, not on the statement
	$out['extra'] = array();
	$r = db_query("SELECT t.* FROM ".TB_PREF."mpesa_transactions t LEFT JOIN ".TB_PREF."mpesa_statement_lines l ON l.receipt=t.receipt
		WHERE t.status IN ('posted','review','received') AND t.receipt IS NOT NULL AND l.id IS NULL AND t.trans_time BETWEEN $f AND $t ORDER BY t.trans_time", 'extra');
	while ($x = db_fetch($r)) $out['extra'][] = $x;
	// different amount
	$out['differ'] = array();
	$r = db_query("SELECT t.*, l.paid_in FROM ".TB_PREF."mpesa_transactions t JOIN ".TB_PREF."mpesa_statement_lines l ON l.receipt=t.receipt
		WHERE ABS(t.amount - l.paid_in) > 0.005 AND l.paid_in > 0 AND l.trans_time BETWEEN $f AND $t", 'differ');
	while ($x = db_fetch($r)) $out['differ'][] = $x;
	// totals and fees
	$tot = db_fetch(db_query("SELECT COUNT(*) n, COALESCE(SUM(l.paid_in),0) paid_in, COALESCE(SUM(l.charge),0) charge,
		COALESCE(SUM(CASE WHEN l.charge>0 THEN t.fee ELSE 0 END),0) fee_posted_on_charged
		FROM ".TB_PREF."mpesa_statement_lines l LEFT JOIN ".TB_PREF."mpesa_transactions t ON t.id=l.matched_tx_id
		WHERE l.paid_in > 0 AND l.trans_time BETWEEN $f AND $t"));
	$out['lines'] = (int)$tot['n']; $out['paid_in'] = (float)$tot['paid_in']; $out['charge'] = (float)$tot['charge'];
	$out['fee_posted'] = (float)db_fetch(db_query("SELECT COALESCE(SUM(fee),0) FROM ".TB_PREF."mpesa_transactions WHERE status='posted' AND trans_time BETWEEN $f AND $t"))[0];
	return $out;
}

// Turns a statement line we never heard about into a review item, so it can be assigned to a customer.
function mpesa_statement_adopt($line_id)
{
	$l = db_fetch(db_query("SELECT * FROM ".TB_PREF."mpesa_statement_lines WHERE id=".(int)$line_id." AND matched_tx_id IS NULL AND paid_in > 0"));
	if (!$l) return false;
	if (db_fetch(db_query("SELECT 1 FROM ".TB_PREF."mpesa_transactions WHERE receipt=".db_escape($l['receipt'])))) return false;
	db_query("INSERT INTO ".TB_PREF."mpesa_transactions (kind, status, amount, receipt, trans_time, payer_name, note, created_at, updated_at) VALUES ('c2b','review', ".db_escape($l['paid_in']).", "
		.db_escape($l['receipt']).", ".db_escape($l['trans_time'], $l['trans_time'] === null).", ".db_escape(substr((string)$l['details'], 0, 120)).", 'Found on the M-Pesa statement but never reported by Safaricom', NOW(), NOW())");
	$id = (int)db_insert_id();
	return $id > 0;
}
