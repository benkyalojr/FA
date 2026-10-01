<?php
/**********************************************************************
    Communications - delivery log data layer.
***********************************************************************/

function comm_log_write($event_key, $channel, $recipient_type, $recipient_id, $recipient_address,
	$subject, $body, $status, $provider_response, $related = null, $recipient_name = null)
{
	$related_type = ($related && isset($related['trans_type'])) ? $related['trans_type'] : null;
	$related_no = ($related && isset($related['trans_no'])) ? $related['trans_no'] : null;

	// Nullable columns (recipient_type/id/name, subject, body,
	// provider_response, related_trans_type/no are all `DEFAULT NULL`, and
	// every one of them is genuinely null on some call path - e.g. a test
	// send has no recipient, a skipped-gate attempt never rendered a body).
	// db_escape()'s default ($nullify=false) turns null into the *string*
	// '', not SQL NULL - fatal for the int columns (recipient_id,
	// related_trans_type/no) under strict SQL mode, and silently wrong for
	// the nullable varchar/text ones. Every nullable value here must pass
	// `true` explicitly.
	db_query("INSERT INTO " . TB_PREF . "comm_log
		(event_key, channel, recipient_type, recipient_id, recipient_name, recipient_address, subject, body,
		 status, provider_response, related_trans_type, related_trans_no, created_at)
		VALUES (" . db_escape($event_key) . ", " . db_escape($channel) . ", " . db_escape($recipient_type, true) . ",
		" . db_escape($recipient_id, true) . ", " . db_escape($recipient_name, true) . ", " . db_escape($recipient_address, true) . ",
		" . db_escape($subject, true) . ", " . db_escape($body, true) . ", " . db_escape($status) . ",
		" . db_escape($provider_response, true) . ",
		" . db_escape($related_type, true) . ", " . db_escape($related_no, true) . ", NOW())",
		'Cannot write communications log');
}

// $from/$to are display-format dates from date_cells() (e.g. 18/09/2026),
// not SQL format - must go through date2sql() before use, same as every
// other date-filtered inquiry in this codebase (e.g.
// transport/includes/db/transport_expenses_db.inc::get_vehicle_expenses()).
// Comparing the raw display string against `created_at` silently matched
// nothing.
function comm_log_where($event_key = null, $channel = null, $status = null, $from = null, $to = null)
{
	$where = array();
	if ($event_key) $where[] = "event_key=" . db_escape($event_key);
	if ($channel) $where[] = "channel=" . db_escape($channel);
	if ($status) $where[] = "status=" . db_escape($status);
	if (is_date($from)) $where[] = "created_at >= '" . date2sql($from) . " 00:00:00'";
	if (is_date($to)) $where[] = "created_at <= '" . date2sql($to) . " 23:59:59'";

	return $where;
}

function get_comm_log($event_key = null, $channel = null, $status = null, $from = null, $to = null, $limit = null, $offset = 0)
{
	$where = comm_log_where($event_key, $channel, $status, $from, $to);
	$sql = "SELECT * FROM " . TB_PREF . "comm_log";
	if ($where) $sql .= " WHERE " . implode(' AND ', $where);
	$sql .= " ORDER BY created_at DESC, id DESC";
	if ($limit !== null) $sql .= " LIMIT " . (int)$limit . " OFFSET " . max(0, (int)$offset);

	return db_query($sql, 'Cannot fetch communications log');
}

function get_comm_log_count($event_key = null, $channel = null, $status = null, $from = null, $to = null)
{
	$where = comm_log_where($event_key, $channel, $status, $from, $to);
	$sql = "SELECT COUNT(*) AS total FROM " . TB_PREF . "comm_log";
	if ($where) $sql .= " WHERE " . implode(' AND ', $where);
	$row = db_fetch(db_query($sql, 'Cannot count communications log'));
	return (int)$row['total'];
}

/*
	Backs the single-channel log inquiries (SMS, Email, ...). $party is
	array('type' => recipient_type, 'id' => recipient_id) or null - both
	must be set together, since recipient_id alone isn't a unique party
	(a supplier_id and an employee id can collide).
*/
function comm_channel_log_where($channel, $from = null, $to = null, $recipient_type = null, $party = null, $search = null)
{
	$where = array("channel=" . db_escape($channel));
	if (is_date($from)) $where[] = "created_at >= '" . date2sql($from) . " 00:00:00'";
	if (is_date($to)) $where[] = "created_at <= '" . date2sql($to) . " 23:59:59'";
	if ($recipient_type) $where[] = "recipient_type=" . db_escape($recipient_type);
	if ($party && !empty($party['type']) && $party['id'] !== null && $party['id'] !== '') {
		$where[] = "recipient_type=" . db_escape($party['type']) . " AND recipient_id=" . db_escape((int)$party['id']);
	}
	if ($search !== null && trim($search) !== '') {
		$term = db_escape('%' . trim($search) . '%');
		$where[] = "(recipient_name LIKE $term OR recipient_address LIKE $term OR body LIKE $term
			OR subject LIKE $term OR provider_response LIKE $term OR event_key LIKE $term OR status LIKE $term)";
	}

	return $where;
}

function get_comm_channel_log($channel, $from = null, $to = null, $recipient_type = null, $party = null, $search = null, $limit = null, $offset = 0)
{
	$where = comm_channel_log_where($channel, $from, $to, $recipient_type, $party, $search);
	$sql = "SELECT * FROM " . TB_PREF . "comm_log WHERE " . implode(' AND ', $where)
		. " ORDER BY created_at DESC, id DESC";
	if ($limit !== null) $sql .= " LIMIT " . (int)$limit . " OFFSET " . max(0, (int)$offset);
	return db_query($sql, 'Cannot fetch communications log');
}

function get_comm_channel_log_count($channel, $from = null, $to = null, $recipient_type = null, $party = null, $search = null)
{
	$where = comm_channel_log_where($channel, $from, $to, $recipient_type, $party, $search);
	$row = db_fetch(db_query("SELECT COUNT(*) AS total FROM " . TB_PREF . "comm_log WHERE " . implode(' AND ', $where),
		'Cannot count communications log'));
	return (int)$row['total'];
}

// A select-with-search combo of every party that has actually appeared in
// this channel's log - scoped per channel (an SMS-only farmer wouldn't be
// a meaningful choice on the Email log's picker, and vice versa), and
// built entirely off comm_log itself rather than a fragile live join
// across supplier/customer/employee/user tables (some of which may not
// even exist if that module isn't installed).
function comm_channel_party_picker($channel, $name, $selected = null)
{
	// combo_input() appends "WHERE $valfield=..." (or "AND (...)" if the
	// string already contains "WHERE") directly onto this SQL when
	// resolving the currently-selected option - only valid when $valfield
	// is a real column, not a SELECT-list alias (MySQL can't reference an
	// alias in that query's own WHERE clause). So this wraps the party
	// list as a derived table (making party_key/party_label real columns
	// of the outer query) and keeps the channel filter at that same outer
	// level too, so combo_input's own naive string check finds "WHERE"
	// and correctly appends its constraint with "AND" right after it - no
	// ORDER BY here, combo_input appends its own via 'order' below.
	$sql = "SELECT party_key, party_label FROM (
			SELECT DISTINCT CONCAT(recipient_type,':',recipient_id) AS party_key,
				CONCAT(recipient_name,' (',recipient_type,')') AS party_label,
				channel
			FROM " . TB_PREF . "comm_log
			WHERE recipient_type IS NOT NULL AND recipient_id IS NOT NULL
		) party_options WHERE channel=" . db_escape($channel);
	return combo_input($name, $selected, $sql, 'party_key', 'party_label', array(
		'order' => array('party_label'),
		'search_box' => true,
		'search' => array('party_label'),
		'spec_option' => _('-- Any --'),
		'spec_id' => '',
		'type' => 1, // by-name search - party_key is an opaque composite, not a value the search text would match
		'select_submit' => true,
		'async' => false,
	));
}
