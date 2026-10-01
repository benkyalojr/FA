<?php
require_once __DIR__.'/../includes/migration_policy.inc';
/**********************************************************************
    eTIMS Integration (KRA VAT e-invoicing, via the Stanbest/Vibranium
    "iSale" API) - schema. Lives under Setup, not as its own top-level
    app: one config row, a per-stock-item KRA registration map, and a
    per-invoice/credit-note submission log. Stamping itself runs off the
    existing background task queue (sql/bg_tasks.php,
    cron/run_scheduled_tasks.php) - see etims/includes/db/etims_submit_db.inc.
***********************************************************************/

require_once dirname(__DIR__).'/etims/includes/etims_setup.inc';

function migrate_etims()
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

	// The on/off stamping switch itself lives in sys_prefs, not on
	// etims_config - the sales-invoice/credit-note hook (sales/includes/db/
	// sales_invoice_db.inc, sales_credit_db.inc) must be able to check
	// "is stamping on" via get_company_pref() alone, cheaply and safely,
	// on every single sale in the system, including for a company that has
	// never opened the eTIMS setup screen and so has no etims_config table
	// yet at all - get_company_pref() on an unseeded key just returns null,
	// no query against a table that may not exist. Same reasoning every
	// other module's use_<mod>_<feature> hook gate already relies on.
	db_query("INSERT INTO ".TB_PREF."sys_prefs (name, category, type, length, value)
		SELECT 'use_etims_stamping', 'setup.etims', 'tinyint', 1, '0'
		FROM DUAL WHERE NOT EXISTS
		(SELECT 1 FROM ".TB_PREF."sys_prefs WHERE name='use_etims_stamping')",
		'Cannot seed eTIMS stamping preference');

	// Single-row config (id=1 always) - credentials, sandbox/live switch,
	// and branch id. access_token/token_expires_at cache the Stanbest
	// login (their JWT is long-lived, ~41 days in the sandbox - no need
	// to re-login per invoice).
	db_query("CREATE TABLE IF NOT EXISTS ".TB_PREF."etims_config (
		id int(11) NOT NULL,
		pin varchar(20) NOT NULL DEFAULT '',
		username varchar(100) NOT NULL DEFAULT '',
		password varchar(255) NOT NULL DEFAULT '',
		is_live tinyint(1) NOT NULL DEFAULT 0,
		sandbox_base_url varchar(255) NOT NULL DEFAULT 'https://vibraniumapi.stanbestgroup.com',
		live_base_url varchar(255) NOT NULL DEFAULT '',
		bhf_id varchar(2) NOT NULL DEFAULT '00',
		access_token text DEFAULT NULL,
		token_expires_at datetime DEFAULT NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY (id)
	) ENGINE=InnoDB".$collate, 'Cannot create etims_config table');

	db_query("INSERT INTO ".TB_PREF."etims_config (id, created_at, updated_at)
		SELECT 1, NOW(), NOW() FROM DUAL WHERE NOT EXISTS
		(SELECT 1 FROM ".TB_PREF."etims_config WHERE id=1)",
		'Cannot seed etims_config row');

	// One row per stock item that has (or needs) a KRA item registration.
	// taxTyCd/itemClsCd are set by whoever maintains this screen - not
	// derived from FA's own tax groups, KRA's classification list is a
	// separate vocabulary from FA's tax_types.
	db_query("CREATE TABLE IF NOT EXISTS ".TB_PREF."etims_item_map (
		id int(11) NOT NULL AUTO_INCREMENT,
		stock_id varchar(20) NOT NULL,
		kra_item_id int(11) DEFAULT NULL,
		kra_item_cd varchar(30) NOT NULL DEFAULT '',
		kra_item_cd_df varchar(30) NOT NULL DEFAULT '',
		item_cls_cd varchar(20) NOT NULL DEFAULT '',
		item_ty_cd char(1) NOT NULL DEFAULT '2',
		orgn_nat_cd varchar(5) NOT NULL DEFAULT 'KE',
		kra_item_category_id varchar(30) NOT NULL DEFAULT '',
		tax_ty_cd char(1) NOT NULL DEFAULT 'B',
		pkg_unit_cd varchar(10) NOT NULL DEFAULT 'NT',
		qty_unit_cd varchar(10) NOT NULL DEFAULT 'U',
		is_registered tinyint(1) NOT NULL DEFAULT 0,
		registered_at datetime DEFAULT NULL,
		last_error text DEFAULT NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY stock_id (stock_id)
	) ENGINE=InnoDB".$collate, 'Cannot create etims_item_map table');

	// Additive - item_ty_cd/orgn_nat_cd/kra_item_category_id were added
	// after this table first shipped (George, 2026-09-21: wakulima's own
	// eTIMS item tab exposes KRA's Item Type/Origin Nation/Category
	// fields, which had been silently hardcoded to Finished
	// Product/KE/blank here instead of made editable).
	foreach (array(
		'item_ty_cd' => "char(1) NOT NULL DEFAULT '2' AFTER item_cls_cd",
		'orgn_nat_cd' => "varchar(5) NOT NULL DEFAULT 'KE' AFTER item_ty_cd",
		'kra_item_category_id' => "varchar(30) NOT NULL DEFAULT '' AFTER orgn_nat_cd",
	) as $col => $def) {
		if (!db_num_rows(db_query("SHOW COLUMNS FROM ".TB_PREF."etims_item_map LIKE '$col'")))
			db_query("ALTER TABLE ".TB_PREF."etims_item_map ADD COLUMN $col $def",
				"Cannot add $col to etims_item_map");
	}

	// KRA's own reference code lists (Country, Packing Unit, Quantity
	// Unit, Item Type, Taxation Type, Item Classification) - confirmed via
	// a direct sandbox call that Stanbest's API has no endpoint for these
	// (their only "codes" endpoint, GetProductsClassification, returns
	// the business's own custom item categories, not a KRA reference
	// list - see etims_item_map.kra_item_category_id instead). Sourced
	// from wakulima's own production 0_etims_code_list/0_etims_item_class
	// tables (live, in-use v1 copies of KRA's official code lists), not
	// guessed - see sql/etims_code_list_data.php. Generalizes wakulima's
	// own table shapes into one, dropping columns v2 doesn't use.
	// code_name is 120 not 60 - Item Classification names run up to 100
	// chars (truncated from wakulima's own 300-char source column when
	// the seed data was generated), a 60-char column would either
	// truncate silently or hard-fail the migration under strict SQL mode.
	db_query("CREATE TABLE IF NOT EXISTS ".TB_PREF."etims_code_list (
		id int(11) NOT NULL AUTO_INCREMENT,
		cl_name varchar(30) NOT NULL,
		code varchar(10) NOT NULL,
		code_name varchar(120) NOT NULL,
		sort_order int(11) NOT NULL DEFAULT 0,
		PRIMARY KEY (id),
		UNIQUE KEY cl_code (cl_name, code)
	) ENGINE=InnoDB".$collate, 'Cannot create etims_code_list table');

	// Additive widen for a table created before code_name grew from 60 to
	// 120 (Item Classification's names needed the extra room).
	$col_len = db_fetch(db_query("SELECT CHARACTER_MAXIMUM_LENGTH AS len FROM information_schema.COLUMNS
		WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '".TB_PREF."etims_code_list' AND COLUMN_NAME = 'code_name'"));
	if ($col_len && (int)$col_len['len'] < 120)
		db_query("ALTER TABLE ".TB_PREF."etims_code_list MODIFY COLUMN code_name varchar(120) NOT NULL",
			"Cannot widen etims_code_list.code_name");

	// Seeded per cl_name, not "only if the table is empty" - so adding a
	// new category later (Item Classification, added after Country/
	// Packing Unit/Quantity Unit/Item Type/Taxation Type first shipped)
	// still gets seeded into a DB that already has the earlier ones.
	$seed = include(dirname(__FILE__).'/etims_code_list_data.php');
	foreach ($seed as $cl_name => $codes) {
		if (db_num_rows(db_query("SELECT 1 FROM ".TB_PREF."etims_code_list WHERE cl_name=".db_escape($cl_name)." LIMIT 1")))
			continue;
		$rows = array();
		$order = 0;
		foreach ($codes as $code => $code_name) {
			$rows[] = "(".db_escape($cl_name).", ".db_escape($code).", "
				.db_escape($code_name).", ".(++$order).")";
		}
		// One batched INSERT per category rather than one query per row.
		db_query("INSERT INTO ".TB_PREF."etims_code_list (cl_name, code, code_name, sort_order) VALUES "
			.implode(', ', $rows), 'Cannot seed etims_code_list category '.$cl_name);
	}

	// One row per FA invoice/credit note that has been (or is being)
	// stamped. Live retry/attempt state lives on the linked bg_tasks row
	// (bg_task_id) - this table only ever flips to 'stamped' on real
	// success, so it never goes stale relative to the queue's own retries.
	db_query("CREATE TABLE IF NOT EXISTS ".TB_PREF."etims_submissions (
		id int(11) NOT NULL AUTO_INCREMENT,
		trans_type smallint(6) NOT NULL,
		trans_no int(11) NOT NULL,
		doc_kind varchar(20) NOT NULL,
		status varchar(20) NOT NULL DEFAULT 'pending',
		bg_task_id int(11) DEFAULT NULL,
		trd_invc_no varchar(30) DEFAULT NULL,
		invc_no varchar(30) DEFAULT NULL,
		rcpt_sign varchar(50) DEFAULT NULL,
		intrl_data varchar(100) DEFAULT NULL,
		cur_rcpt_no varchar(30) DEFAULT NULL,
		tot_rcpt_no varchar(30) DEFAULT NULL,
		sdc_date_time varchar(20) DEFAULT NULL,
		short_url varchar(255) DEFAULT NULL,
		long_url text DEFAULT NULL,
		submitted_at datetime DEFAULT NULL,
		stamped_at datetime DEFAULT NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY trans (trans_type, trans_no)
	) ENGINE=InnoDB".$collate, 'Cannot create etims_submissions table');

	// Publish readiness only after every schema and seed step succeeds.
	db_query("INSERT INTO ".TB_PREF."sys_prefs (name, category, type, length, value)
		VALUES ('etims_schema_version', 'setup.etims', 'int', 11, '".ETIMS_SCHEMA_VERSION."')
		ON DUPLICATE KEY UPDATE value=VALUES(value)", 'Cannot record eTIMS schema version');
}
