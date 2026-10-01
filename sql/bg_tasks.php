<?php
require_once __DIR__.'/../includes/migration_policy.inc';
/**********************************************************************
    Background task queue - Moodle-cron-inspired pattern: one master
    cron entry point (cron/run_scheduled_tasks.php) instead of a
    crontab line per feature, with all real scheduling/retry state kept
    in this table rather than the crontab. Core-level, not
    Communications-specific - Communications is the first consumer
    (queued SMS/Email/WhatsApp sends, see communications/includes/db/
    comm_send_db.inc), registered via includes/bg_tasks.inc's handler
    registry so a second consumer is a small additive change, not a new
    one-off script.
***********************************************************************/

function migrate_bg_tasks()
{
    migration_require_authorized();
	if (!function_exists('fa_core_collation')) {
		function fa_core_collation()
		{
			static $collation = null;
			if ($collation === null) {
				$row = db_fetch(db_query("SELECT TABLE_COLLATION AS c FROM information_schema.TABLES
					WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '".TB_PREF."stock_master'",
					"Cannot read core table collation"));
				$collation = ($row && $row['c']) ? $row['c'] : 'utf8mb4_general_ci';
			}
			return $collation;
		}
	}
	$collate = " COLLATE ".fa_core_collation();

	db_query("CREATE TABLE IF NOT EXISTS ".TB_PREF."bg_tasks (
		id int(11) NOT NULL AUTO_INCREMENT,
		task_type varchar(60) NOT NULL,
		payload text NOT NULL,
		status enum('pending','running','done','failed') NOT NULL DEFAULT 'pending',
		attempts int(11) NOT NULL DEFAULT 0,
		max_attempts int(11) NOT NULL DEFAULT 5,
		next_attempt_at datetime NOT NULL,
		last_error text DEFAULT NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY (id),
		KEY status_next (status, next_attempt_at)
	) ENGINE=InnoDB".$collate, 'Cannot create bg_tasks table');
}
