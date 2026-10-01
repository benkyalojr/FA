<?php
/**********************************************************************
    Communications - notification rules data layer (the org-level gate:
    does this event fire on this channel at all).
***********************************************************************/

function get_comm_rules()
{
	return db_query("SELECT * FROM " . TB_PREF . "comm_notification_rules ORDER BY event_key, channel",
		'Cannot fetch notification rules');
}

function set_comm_rule($event_key, $channel, $is_enabled)
{
	db_query("UPDATE " . TB_PREF . "comm_notification_rules SET is_enabled=" . db_escape($is_enabled) . "
		WHERE event_key=" . db_escape($event_key) . " AND channel=" . db_escape($channel),
		'Cannot update notification rule');
}
