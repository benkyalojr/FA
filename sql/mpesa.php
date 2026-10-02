<?php
require_once __DIR__.'/../includes/migration_policy.inc';
/**********************************************************************
    M-Pesa (Safaricom Daraja) - schema. One config row, one table for incoming
    money (STK Push requests and Till/C2B receipts), one for payouts (B2C) and one
    for imported statement lines used by reconciliation. See mpesa/ and
    README in mpesa/README.md for the flows.
***********************************************************************/

function migrate_mpesa()
{
    migration_require_authorized();
	$row = db_fetch(db_query("SELECT TABLE_COLLATION AS c FROM information_schema.TABLES
		WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '".TB_PREF."stock_master'", "Cannot read core table collation"));
	$collate = " COLLATE ".(($row && $row['c']) ? $row['c'] : 'utf8mb4_general_ci');

	// One row (id=1). Secrets are stored encrypted (see mpesa/includes/mpesa_crypto.inc).
	db_query("CREATE TABLE IF NOT EXISTS ".TB_PREF."mpesa_config (
		id int(11) NOT NULL,
		is_live tinyint(1) NOT NULL DEFAULT 0,
		consumer_key text DEFAULT NULL,
		consumer_secret text DEFAULT NULL,
		till_number varchar(20) NOT NULL DEFAULT '',
		store_number varchar(20) NOT NULL DEFAULT '',
		passkey text DEFAULT NULL,
		hook_secret varchar(64) NOT NULL DEFAULT '',
		public_url varchar(200) NOT NULL DEFAULT '',
		bank_account_id int(11) DEFAULT NULL,
		fee_percent double NOT NULL DEFAULT 0.55,
		fee_cap double NOT NULL DEFAULT 200,
		fee_enabled tinyint(1) NOT NULL DEFAULT 1,
		b2c_enabled tinyint(1) NOT NULL DEFAULT 0,
		b2c_shortcode varchar(20) NOT NULL DEFAULT '',
		b2c_initiator varchar(60) NOT NULL DEFAULT '',
		b2c_password text DEFAULT NULL,
		b2c_cert text DEFAULT NULL,
		payout_limit double NOT NULL DEFAULT 70000,
		payout_daily_limit double NOT NULL DEFAULT 150000,
		access_token text DEFAULT NULL,
		token_expires_at datetime DEFAULT NULL,
		updated_at datetime DEFAULT NULL,
		PRIMARY KEY (id)
	) ENGINE=InnoDB".$collate, 'Cannot create mpesa_config table');
	db_query("INSERT INTO ".TB_PREF."mpesa_config (id, hook_secret, updated_at)
		SELECT 1, '".bin2hex(random_bytes(16))."', NOW() FROM DUAL
		WHERE NOT EXISTS (SELECT 1 FROM ".TB_PREF."mpesa_config WHERE id=1)", 'Cannot seed mpesa_config');

	// Every callback is stored here first (raw), then processed; anything that failed to
	// process is retried by cron/mpesa_jobs.php. Nothing Safaricom sends is ever lost.
	db_query("CREATE TABLE IF NOT EXISTS ".TB_PREF."mpesa_inbox (
		id int(11) NOT NULL AUTO_INCREMENT,
		kind varchar(20) NOT NULL,
		body mediumtext NOT NULL,
		remote_addr varchar(45) DEFAULT NULL,
		received_at datetime NOT NULL,
		processed_at datetime DEFAULT NULL,
		attempts int(11) NOT NULL DEFAULT 0,
		error varchar(500) DEFAULT NULL,
		PRIMARY KEY (id),
		KEY pending (processed_at, attempts)
	) ENGINE=InnoDB".$collate, 'Cannot create mpesa_inbox table');

	// Money in: STK Push requests we made and C2B (Till) receipts Safaricom reported.
	db_query("CREATE TABLE IF NOT EXISTS ".TB_PREF."mpesa_transactions (
		id int(11) NOT NULL AUTO_INCREMENT,
		kind enum('stk','c2b') NOT NULL,
		status enum('pending','received','review','posted','failed','cancelled') NOT NULL DEFAULT 'pending',
		phone varchar(80) DEFAULT NULL,
		payer_name varchar(120) DEFAULT NULL,
		amount double NOT NULL DEFAULT 0,
		receipt varchar(30) DEFAULT NULL,
		account_ref varchar(60) DEFAULT NULL,
		merchant_request_id varchar(60) DEFAULT NULL,
		checkout_request_id varchar(80) DEFAULT NULL,
		result_code int(11) DEFAULT NULL,
		result_desc varchar(255) DEFAULT NULL,
		debtor_no int(11) DEFAULT NULL,
		target_type smallint(6) DEFAULT NULL,
		target_no int(11) DEFAULT NULL,
		payment_no int(11) DEFAULT NULL,
		allocated double NOT NULL DEFAULT 0,
		fee double NOT NULL DEFAULT 0,
		trans_time datetime DEFAULT NULL,
		raw text DEFAULT NULL,
		note varchar(255) DEFAULT NULL,
		requested_by smallint(6) DEFAULT NULL,
		posted_by smallint(6) DEFAULT NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY receipt (receipt),
		KEY checkout (checkout_request_id),
		KEY status_kind (status, kind),
		KEY debtor (debtor_no)
	) ENGINE=InnoDB".$collate, 'Cannot create mpesa_transactions table');

	// Money out (B2C). Disabled until a B2C shortcode is configured.
	db_query("CREATE TABLE IF NOT EXISTS ".TB_PREF."mpesa_payouts (
		id int(11) NOT NULL AUTO_INCREMENT,
		status enum('requested','approved','sent','success','failed','rejected','reversed') NOT NULL DEFAULT 'requested',
		supplier_id int(11) DEFAULT NULL,
		phone varchar(40) NOT NULL,
		amount double NOT NULL,
		reason varchar(200) NOT NULL DEFAULT '',
		conversation_id varchar(80) DEFAULT NULL,
		originator_conversation_id varchar(80) DEFAULT NULL,
		receipt varchar(30) DEFAULT NULL,
		result_desc varchar(255) DEFAULT NULL,
		payment_no int(11) DEFAULT NULL,
		requested_by smallint(6) DEFAULT NULL,
		approved_by smallint(6) DEFAULT NULL,
		raw text DEFAULT NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY (id),
		KEY originator (originator_conversation_id),
		KEY status (status)
	) ENGINE=InnoDB".$collate, 'Cannot create mpesa_payouts table');

	// Lines of an imported M-Pesa statement, matched against mpesa_transactions.
	db_query("CREATE TABLE IF NOT EXISTS ".TB_PREF."mpesa_statement_lines (
		id int(11) NOT NULL AUTO_INCREMENT,
		receipt varchar(30) NOT NULL,
		trans_time datetime DEFAULT NULL,
		details varchar(255) DEFAULT NULL,
		paid_in double NOT NULL DEFAULT 0,
		withdrawn double NOT NULL DEFAULT 0,
		charge double NOT NULL DEFAULT 0,
		matched_tx_id int(11) DEFAULT NULL,
		batch varchar(40) DEFAULT NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY receipt (receipt)
	) ENGINE=InnoDB".$collate, 'Cannot create mpesa_statement_lines table');

	// Upgrade existing installations without replacing credentials or transaction history.
	foreach (array('service_user' => "varchar(60) NOT NULL DEFAULT ''", 'service_password' => 'text DEFAULT NULL', 'b2c_bank_account_id' => 'int(11) DEFAULT NULL') as $col => $definition) {
		$found = db_query("SHOW COLUMNS FROM ".TB_PREF."mpesa_config LIKE ".db_escape($col));
		if (!db_num_rows($found)) db_query("ALTER TABLE ".TB_PREF."mpesa_config ADD `$col` $definition", 'Cannot upgrade M-Pesa configuration');
	}
	$found = db_query("SHOW COLUMNS FROM ".TB_PREF."mpesa_payouts LIKE 'bank_account_id'");
	if (!db_num_rows($found)) db_query("ALTER TABLE ".TB_PREF."mpesa_payouts ADD bank_account_id int(11) DEFAULT NULL", 'Cannot upgrade payout bank account');
	$found = db_query("SHOW INDEX FROM ".TB_PREF."mpesa_payouts WHERE Key_name='receipt'");
	if (!db_num_rows($found)) db_query("ALTER TABLE ".TB_PREF."mpesa_payouts ADD UNIQUE KEY receipt (receipt)", 'Cannot protect payout receipt uniqueness');
	db_query("INSERT INTO ".TB_PREF."sys_prefs (name, category, type, length, value)
		SELECT 'mpesa_schema_version', 'setup.mpesa', 'int', 11, '2' FROM DUAL
		WHERE NOT EXISTS (SELECT 1 FROM ".TB_PREF."sys_prefs WHERE name='mpesa_schema_version')", 'Cannot seed mpesa schema version');
	db_query("UPDATE ".TB_PREF."sys_prefs SET value='2' WHERE name='mpesa_schema_version'");
}
