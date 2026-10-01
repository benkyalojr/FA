<?php
require_once __DIR__.'/../includes/migration_policy.inc';
// Additive, repeatable migration.
function migrate_credit_note_reasons()
{
    migration_require_authorized();
	db_query("CREATE TABLE IF NOT EXISTS ".TB_PREF."credit_note_reasons (
		id int(11) NOT NULL AUTO_INCREMENT,
		reason_description char(100) NOT NULL DEFAULT '',
		inactive tinyint(1) NOT NULL DEFAULT 0,
		PRIMARY KEY (id), UNIQUE KEY reason_description (reason_description)
	) ENGINE=InnoDB", 'Cannot create credit note reasons');
}
