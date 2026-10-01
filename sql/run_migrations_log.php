<?php
require_once __DIR__.'/../includes/migration_policy.inc';
// Deployment history written by scripts/run_migrations.php; screens only read it.
function migrate_run_migrations_log()
{
    migration_require_authorized();
	db_query("CREATE TABLE IF NOT EXISTS ".TB_PREF."migration_log (
		name varchar(191) NOT NULL,
		status varchar(10) NOT NULL,
		message text,
		seconds decimal(10,3) NOT NULL DEFAULT 0,
		ran_at datetime NOT NULL,
		PRIMARY KEY (name)
	) ENGINE=InnoDB", 'Cannot create migration_log');
}
