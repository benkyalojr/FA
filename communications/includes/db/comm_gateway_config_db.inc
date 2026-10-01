<?php
/**********************************************************************
    Communications - gateway settings data layer. One comm_gateway_configs
    row per channel that needs real credentials: sms, email (SMTP), and
    whatsapp.
***********************************************************************/

function comm_get_gateway_config($channel)
{
	$sql = "SELECT * FROM " . TB_PREF . "comm_gateway_configs WHERE channel=" . db_escape($channel);
	return db_fetch(db_query($sql, 'Cannot get gateway config'));
}

// $fields is a flat array of column => value for the given channel's own
// columns only (callers build this from their own form fields, see
// communications/manage/gateway_settings.php).
function comm_save_gateway_config($channel, array $fields)
{
	$existing = comm_get_gateway_config($channel);

	// db_escape()'s $nullify flag must be passed explicitly for any null
	// value, or it silently becomes '' instead of SQL NULL - no current
	// caller passes a null field, but this loop is generic across every
	// channel's own column set, so it's hardened here rather than trusted
	// to every future caller.
	if ($existing) {
		$sets = array();
		foreach ($fields as $col => $val)
			$sets[] = "`$col`=" . db_escape($val, $val === null);
		db_query("UPDATE " . TB_PREF . "comm_gateway_configs SET " . implode(', ', $sets) . "
			WHERE channel=" . db_escape($channel), 'Cannot update gateway config');
	} else {
		$fields['channel'] = $channel;
		$cols = array();
		$vals = array();
		foreach ($fields as $col => $val) {
			$cols[] = "`$col`";
			$vals[] = db_escape($val, $val === null);
		}
		db_query("INSERT INTO " . TB_PREF . "comm_gateway_configs (" . implode(', ', $cols) . ")
			VALUES (" . implode(', ', $vals) . ")", 'Cannot insert gateway config');
	}
}
