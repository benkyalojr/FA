<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Usage: php cron/run_scheduled_tasks.php [company_id]
//
// Background task runner - this codebase's equivalent of Moodle's
// admin/cli/cron.php: ONE system cron entry drains whatever's due,
// rather than a separate crontab line per background job. Add to cron:
//   * * * * * php /path/to/cron/run_scheduled_tasks.php <company_id>
//
// Lives in its own cron/ folder, not scripts/ - unlike every file in
// scripts/ (one-shot idempotent migrate_<topic>.php runners, run once
// per environment), this one runs forever on a recurring schedule, a
// different kind of thing entirely.
//
// All real per-task scheduling/retry state lives in the bg_tasks table
// (see sql/bg_tasks.php), not the crontab - a new background job
// elsewhere in the app is a bg_task_register() call, never a new cron
// line. Communications (queued SMS/Email/WhatsApp sends) is the first
// consumer - see communications/includes/db/comm_send_db.inc.
$supplier_company_id = isset($argv[1]) ? $argv[1] : 0;
require dirname(__DIR__).'/scripts/supplier_types_cli.inc';
require $path_to_root.'/includes/bg_tasks.inc';
require $path_to_root.'/sql/bg_tasks.php';
// Background-task tables must be installed by the deployment CLI.
// eTIMS schema is installed during deployment by scripts/migrate_etims.php.

// Pull in every module's task handlers - each require registers itself
// via bg_task_register() at file-bottom scope.
require $path_to_root.'/communications/includes/db/comm_send_db.inc';
require $path_to_root.'/etims/includes/db/etims_submit_db.inc';

$BATCH_SIZE = 200;

$lock = db_fetch(db_query("SELECT GET_LOCK('bg_tasks_runner', 0) AS got"));
if (!$lock || (int)$lock['got'] !== 1) {
	echo "Another run_scheduled_tasks.php is already in progress - exiting.\n";
	exit(0);
}

try {
	$handlers = bg_task_handlers();
	$result = db_query("SELECT * FROM ".TB_PREF."bg_tasks
		WHERE status='pending' AND next_attempt_at <= NOW()
		ORDER BY id LIMIT ".(int)$BATCH_SIZE, 'Cannot fetch due background tasks');

	$processed = 0;
	$failed = 0;
	while ($task = db_fetch($result)) {
		$processed++;
		db_query("UPDATE ".TB_PREF."bg_tasks SET status='running', updated_at=NOW() WHERE id=".(int)$task['id']);

		$handler = isset($handlers[$task['task_type']]) ? $handlers[$task['task_type']] : null;
		if (!$handler) {
			$failed++;
			db_query("UPDATE ".TB_PREF."bg_tasks SET status='failed',
				last_error=".db_escape('No handler registered for task_type '.$task['task_type']).",
				updated_at=NOW() WHERE id=".(int)$task['id']);
			continue;
		}

		try {
			$payload = bg_task_decode_payload($task['payload']);
			call_user_func($handler, $payload);
			// Clear any error left by an earlier retry so completed tasks do not
			// appear failed in the background-task inquiry.
			db_query("UPDATE ".TB_PREF."bg_tasks SET status='done', last_error=NULL, updated_at=NOW() WHERE id=".(int)$task['id']);
		} catch (Throwable $e) {
			$attempts = (int)$task['attempts'] + 1;
			$max_attempts = (int)$task['max_attempts'];
			if ($attempts >= $max_attempts) {
				$failed++;
				db_query("UPDATE ".TB_PREF."bg_tasks SET status='failed', attempts=".$attempts.",
					last_error=".db_escape($e->getMessage()).", updated_at=NOW() WHERE id=".(int)$task['id']);
			} else {
				// Doubling backoff capped at an hour - same shape as
				// Moodle's adhoc-task faildelay.
				$backoff_minutes = min(60, pow(2, $attempts));
				db_query("UPDATE ".TB_PREF."bg_tasks SET status='pending', attempts=".$attempts.",
					last_error=".db_escape($e->getMessage()).",
					next_attempt_at=DATE_ADD(NOW(), INTERVAL ".(int)$backoff_minutes." MINUTE),
					updated_at=NOW() WHERE id=".(int)$task['id']);
			}
		}
	}

	echo "Processed $processed task(s), $failed failed, for company $supplier_company_id.\n";
} finally {
	db_query("SELECT RELEASE_LOCK('bg_tasks_runner')");
}
