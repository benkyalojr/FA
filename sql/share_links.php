<?php
require_once __DIR__.'/../includes/migration_policy.inc';
/**********************************************************************
    Public share links - one unguessable token per shared document, used by
    share/index.php to show a read-only copy without signing in. Links can be
    revoked or given an expiry date; views are counted.
***********************************************************************/

function migrate_share_links()
{
    migration_require_authorized();
	$row = db_fetch(db_query("SELECT TABLE_COLLATION AS c FROM information_schema.TABLES
		WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '".TB_PREF."stock_master'", "Cannot read core table collation"));
	$collate = " COLLATE ".(($row && $row['c']) ? $row['c'] : 'utf8mb4_general_ci');

	db_query("CREATE TABLE IF NOT EXISTS ".TB_PREF."share_links (
		id int(11) NOT NULL AUTO_INCREMENT,
		token varchar(64) NOT NULL,
		doc_type smallint(6) NOT NULL,
		trans_no int(11) NOT NULL,
		created_by smallint(6) DEFAULT NULL,
		created_at datetime NOT NULL,
		expires_at datetime DEFAULT NULL,
		revoked tinyint(1) NOT NULL DEFAULT 0,
		views int(11) NOT NULL DEFAULT 0,
		last_viewed datetime DEFAULT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY token (token),
		KEY doc (doc_type, trans_no)
	) ENGINE=InnoDB".$collate, 'Cannot create share_links table');
}
