<?php
/**********************************************************************
    Background task queue - handler registry and enqueue helper. A
    module that wants background processing calls bg_task_register()
    once (e.g. from its own includes/db file) and bg_task_enqueue()
    wherever it used to do the slow work inline. cron/
    run_scheduled_tasks.php is the actual runner that drains the queue.
***********************************************************************/

function bg_task_register($task_type, $handler_function)
{
	global $bg_task_handlers;
	if (!isset($bg_task_handlers))
		$bg_task_handlers = array();
	$bg_task_handlers[$task_type] = $handler_function;
}

function bg_task_handlers()
{
	global $bg_task_handlers;
	return isset($bg_task_handlers) ? $bg_task_handlers : array();
}

function bg_task_enqueue($task_type, array $payload, $max_attempts = 5)
{
	db_query("INSERT INTO " . TB_PREF . "bg_tasks
		(task_type, payload, status, attempts, max_attempts, next_attempt_at, created_at, updated_at)
		VALUES (" . db_escape($task_type) . ", " . db_escape(json_encode($payload)) . ", 'pending', 0,
		" . db_escape((int)$max_attempts) . ", NOW(), NOW(), NOW())",
		'Cannot enqueue background task');
	return db_insert_id();
}

// Decode a stored payload, tolerant of the (possibly repeated)
// html_specials_encode() that FA's real web-session db_escape() applies
// before bg_task_enqueue() stores it (" becomes &quot;, etc.) - a plain
// json_decode() of that raw column silently returns null. Same class of
// bug already hit and fixed for manufacturing/includes/db/qa_common.inc::
// qa_decode_param_values(); this is that same fix for the queue's payload
// column. The CLI runner (cron/run_scheduled_tasks.php) is the only
// reader, but a row can just as easily have been enqueued from a normal
// web request, so decoding must always assume the encoding happened.
function bg_task_decode_payload($json)
{
	if ($json === null || $json === '')
		return array();
	if (is_array($json))
		return $json;
	if (!is_string($json))
		return array();

	$decoded = $json;
	for ($i = 0; $i < 3; $i++) {
		$next = html_entity_decode($decoded, ENT_QUOTES, 'UTF-8');
		if ($next === $decoded)
			break;
		$decoded = $next;
	}
	$vals = json_decode($decoded, true);
	if (!is_array($vals))
		$vals = json_decode(stripslashes($decoded), true);
	return is_array($vals) ? $vals : array();
}
