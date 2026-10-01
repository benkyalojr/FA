<?php
/**********************************************************************
    Communications - message templates data layer. See
    communications/includes/comm_events.inc for the event catalog these
    rows key against.
***********************************************************************/

function get_comm_templates()
{
	return db_query("SELECT * FROM " . TB_PREF . "comm_templates ORDER BY event_key, channel",
		'Cannot fetch communications templates');
}

function get_comm_template($id)
{
	return db_fetch(db_query("SELECT * FROM " . TB_PREF . "comm_templates WHERE id=" . db_escape($id),
		'Cannot fetch communications template'));
}

// $subject is null for every non-email channel (nullable column) - must
// pass db_escape()'s $nullify flag, or null silently becomes '' instead
// of SQL NULL (this codebase's db_escape() default, unlike a naive null
// check).
function add_comm_template($event_key, $channel, $subject, $body)
{
	db_query("INSERT INTO " . TB_PREF . "comm_templates (event_key, channel, subject, body, is_active)
		VALUES (" . db_escape($event_key) . ", " . db_escape($channel) . ", " . db_escape($subject, true) . ",
		" . db_escape($body) . ", 1)", 'Cannot add communications template');
}

function update_comm_template($id, $event_key, $channel, $subject, $body, $is_active)
{
	db_query("UPDATE " . TB_PREF . "comm_templates SET
		event_key=" . db_escape($event_key) . ", channel=" . db_escape($channel) . ",
		subject=" . db_escape($subject, true) . ", body=" . db_escape($body) . ",
		is_active=" . db_escape($is_active) . "
		WHERE id=" . db_escape($id), 'Cannot update communications template');
}

function delete_comm_template($id)
{
	db_query("DELETE FROM " . TB_PREF . "comm_templates WHERE id=" . db_escape($id),
		'Cannot delete communications template');
}
