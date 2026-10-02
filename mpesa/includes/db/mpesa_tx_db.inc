<?php
/**********************************************************************
    M-Pesa - incoming transactions (STK Push requests and Till receipts) and
    the raw callback inbox.
***********************************************************************/

function mpesa_tx_get($id)
{
	return db_fetch(db_query("SELECT * FROM ".TB_PREF."mpesa_transactions WHERE id=".(int)$id, 'Cannot get M-Pesa transaction'));
}

function mpesa_tx_by_checkout($checkout_request_id)
{
	return db_fetch(db_query("SELECT * FROM ".TB_PREF."mpesa_transactions WHERE checkout_request_id=".db_escape($checkout_request_id), 'Cannot find STK request'));
}

function mpesa_tx_by_receipt($receipt)
{
	return db_fetch(db_query("SELECT * FROM ".TB_PREF."mpesa_transactions WHERE receipt=".db_escape($receipt), 'Cannot find M-Pesa receipt'));
}

function mpesa_tx_update($id, array $fields)
{
	$sets = array();
	foreach ($fields as $col => $val) $sets[] = "`$col`=".db_escape($val, $val === null);
	$sets[] = "updated_at=NOW()";
	db_query("UPDATE ".TB_PREF."mpesa_transactions SET ".implode(', ', $sets)." WHERE id=".(int)$id, 'Cannot update M-Pesa transaction');
}

// A new STK Push request, recorded before it is sent so the callback can always find it.
function mpesa_tx_create_stk($debtor_no, $target_type, $target_no, $phone, $amount, $account_ref, $requested_by)
{
	db_query("INSERT INTO ".TB_PREF."mpesa_transactions (kind, status, phone, amount, account_ref, debtor_no, target_type, target_no, requested_by, created_at, updated_at)
		VALUES ('stk', 'pending', ".db_escape($phone).", ".db_escape($amount).", ".db_escape($account_ref).", ".db_escape($debtor_no, $debtor_no === null).", "
		.db_escape($target_type, $target_type === null).", ".db_escape($target_no, $target_no === null).", ".db_escape($requested_by, $requested_by === null).", NOW(), NOW())", 'Cannot record STK request');
	return (int)db_insert_id();
}

// Page of transactions for the list screen. $f: status, kind, search, from, to (SQL dates).
function mpesa_tx_list(array $f, $limit, $offset, &$total)
{
	$where = array('1=1');
	if (!empty($f['status'])) $where[] = 't.status='.db_escape($f['status']);
	if (!empty($f['kind'])) $where[] = 't.kind='.db_escape($f['kind']);
	if (!empty($f['from'])) $where[] = 't.created_at >= '.db_escape($f['from'].' 00:00:00');
	if (!empty($f['to'])) $where[] = 't.created_at <= '.db_escape($f['to'].' 23:59:59');
	if (!empty($f['search'])) {
		$like = db_escape('%'.$f['search'].'%');
		$where[] = "(t.receipt LIKE $like OR t.phone LIKE $like OR t.payer_name LIKE $like OR d.name LIKE $like OR t.account_ref LIKE $like)";
	}
	$w = implode(' AND ', $where);
	$total = (int)db_fetch(db_query("SELECT COUNT(*) FROM ".TB_PREF."mpesa_transactions t LEFT JOIN ".TB_PREF."debtors_master d ON d.debtor_no=t.debtor_no WHERE $w"))[0];
	return db_query("SELECT t.*, d.name AS customer FROM ".TB_PREF."mpesa_transactions t LEFT JOIN ".TB_PREF."debtors_master d ON d.debtor_no=t.debtor_no
		WHERE $w ORDER BY t.id DESC LIMIT ".(int)$limit." OFFSET ".(int)$offset, 'Cannot list M-Pesa transactions');
}

// ---- callback inbox (raw payloads, stored before anything else is done) ----

function mpesa_inbox_add($kind, $body, $remote_addr)
{
	db_query("INSERT INTO ".TB_PREF."mpesa_inbox (kind, body, remote_addr, received_at) VALUES (".db_escape($kind).", ".db_escape($body).", ".db_escape($remote_addr).", NOW())", 'Cannot store callback');
	return (int)db_insert_id();
}

function mpesa_inbox_done($id, $error = null)
{
	if ($error === null)
		db_query("UPDATE ".TB_PREF."mpesa_inbox SET processed_at=NOW(), attempts=attempts+1, error=NULL WHERE id=".(int)$id);
	else
		db_query("UPDATE ".TB_PREF."mpesa_inbox SET attempts=attempts+1, error=".db_escape(substr($error, 0, 500))." WHERE id=".(int)$id);
}
