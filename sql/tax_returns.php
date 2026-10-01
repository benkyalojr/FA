<?php
require_once __DIR__.'/../includes/migration_policy.inc';
/**********************************************************************
    Tax returns - one row per filed tax period (taxes/tax_periods.php). The
    output/input/due figures are a snapshot taken when the return is filed, so
    later changes to the books do not rewrite a return that was submitted.
***********************************************************************/

function migrate_tax_returns()
{
    migration_require_authorized();
	$row = db_fetch(db_query("SELECT TABLE_COLLATION AS c FROM information_schema.TABLES
		WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '".TB_PREF."stock_master'", "Cannot read core table collation"));
	$collate = " COLLATE ".(($row && $row['c']) ? $row['c'] : 'utf8mb4_general_ci');

	db_query("CREATE TABLE IF NOT EXISTS ".TB_PREF."tax_returns (
		id int(11) NOT NULL AUTO_INCREMENT,
		period_start date NOT NULL,
		period_end date NOT NULL,
		filed_date date NOT NULL,
		output_tax double NOT NULL DEFAULT 0,
		input_tax double NOT NULL DEFAULT 0,
		amount_due double NOT NULL DEFAULT 0,
		payments double NOT NULL DEFAULT 0,
		reference varchar(60) NOT NULL DEFAULT '',
		memo varchar(255) NOT NULL DEFAULT '',
		filed_by smallint(6) DEFAULT NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY period (period_start, period_end)
	) ENGINE=InnoDB".$collate, 'Cannot create tax_returns table');
}
