<?php
require_once __DIR__.'/../includes/migration_policy.inc';
/**********************************************************************
    Communications module - Slice A (config + send engine). Schema for
    SMS/Email/WhatsApp gateway settings, message templates, the two
    gates (event-level "should this fire" rule + person-level opt-in),
    and a delivery log.

    Greenfield across every v1 client except one narrow exception:
    nyala has a real, production SMS integration (DigiSoft Solutions
    gateway, digisms.digisoftsolutions.co.ke) whose request shape is
    ported as-is into this module's SMS channel adapter
    (communications/includes/channels/comm_sms_digisoft.inc). No v1
    client has WhatsApp at all.

    No hooks into milk collection/payment, payroll payslips, sales
    invoices or the approval workflows yet - every event/channel rule
    below is seeded off, so nothing sends until both the channel toggle
    (sql/communications_module.php) and the notification rule here are
    turned on, and a later slice actually calls comm_send_notification()
    from those flows.
***********************************************************************/

function migrate_communications()
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

	// One row per channel that needs gateway credentials - sms, email
	// (SMTP, per explicit direction - not FA core's mail()-based
	// class.mail.inc) and whatsapp.
	db_query("CREATE TABLE IF NOT EXISTS ".TB_PREF."comm_gateway_configs (
		id int(11) NOT NULL AUTO_INCREMENT,
		channel enum('sms','email','whatsapp') NOT NULL,
		provider varchar(50) DEFAULT NULL,
		api_key text,
		sender_id varchar(50) DEFAULT NULL,
		sender_name varchar(100) DEFAULT NULL,
		send_url varchar(255) DEFAULT NULL,
		balance_url varchar(255) DEFAULT NULL,
		phone_number_id varchar(50) DEFAULT NULL,
		access_token text,
		business_account_id varchar(50) DEFAULT NULL,
		smtp_host varchar(255) DEFAULT NULL,
		smtp_port smallint(5) unsigned DEFAULT NULL,
		smtp_encryption enum('none','ssl','tls') DEFAULT 'tls',
		smtp_username varchar(150) DEFAULT NULL,
		smtp_password text,
		smtp_from_email varchar(150) DEFAULT NULL,
		smtp_from_name varchar(150) DEFAULT NULL,
		is_active tinyint(1) NOT NULL DEFAULT 0,
		created_at timestamp NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY channel (channel)
	) ENGINE=InnoDB".$collate, 'Cannot create comm_gateway_configs table');
	communications_upgrade_gateway_configs_table();

	db_query("CREATE TABLE IF NOT EXISTS ".TB_PREF."comm_templates (
		id int(11) NOT NULL AUTO_INCREMENT,
		event_key varchar(60) NOT NULL,
		channel enum('sms','email','whatsapp') NOT NULL,
		subject varchar(255) DEFAULT NULL,
		body text NOT NULL,
		is_active tinyint(1) NOT NULL DEFAULT 1,
		PRIMARY KEY (id),
		UNIQUE KEY event_channel (event_key, channel)
	) ENGINE=InnoDB".$collate, 'Cannot create comm_templates table');

	// The org-level gate: nothing fires for an event/channel combination
	// until an admin explicitly checks it here, even if the channel
	// itself is configured and toggled on.
	db_query("CREATE TABLE IF NOT EXISTS ".TB_PREF."comm_notification_rules (
		id int(11) NOT NULL AUTO_INCREMENT,
		event_key varchar(60) NOT NULL,
		channel enum('sms','email','whatsapp') NOT NULL,
		is_enabled tinyint(1) NOT NULL DEFAULT 0,
		PRIMARY KEY (id),
		UNIQUE KEY event_channel (event_key, channel)
	) ENGINE=InnoDB".$collate, 'Cannot create comm_notification_rules table');

	// The person-level gate: no row = opted in to everything. Only
	// overrides (in practice, opt-outs) are stored.
	db_query("CREATE TABLE IF NOT EXISTS ".TB_PREF."comm_recipient_prefs (
		id int(11) NOT NULL AUTO_INCREMENT,
		entity_type enum('supplier','employee','customer','user') NOT NULL,
		entity_id int(11) NOT NULL,
		sms_opt_in tinyint(1) NOT NULL DEFAULT 1,
		email_opt_in tinyint(1) NOT NULL DEFAULT 1,
		whatsapp_opt_in tinyint(1) NOT NULL DEFAULT 1,
		updated_at timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY entity (entity_type, entity_id)
	) ENGINE=InnoDB".$collate, 'Cannot create comm_recipient_prefs table');

	db_query("CREATE TABLE IF NOT EXISTS ".TB_PREF."comm_log (
		id int(11) NOT NULL AUTO_INCREMENT,
		event_key varchar(60) NOT NULL,
		channel enum('sms','email','whatsapp') NOT NULL,
		recipient_type varchar(20) DEFAULT NULL,
		recipient_id int(11) DEFAULT NULL,
		recipient_name varchar(150) DEFAULT NULL,
		recipient_address varchar(150) DEFAULT NULL,
		subject varchar(255) DEFAULT NULL,
		body text,
		status enum('queued','sent','failed','skipped_gate','skipped_optout') NOT NULL,
		provider_response text,
		related_trans_type int(11) DEFAULT NULL,
		related_trans_no int(11) DEFAULT NULL,
		created_at timestamp NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY event_key (event_key),
		KEY created_at (created_at)
	) ENGINE=InnoDB".$collate, 'Cannot create comm_log table');
	communications_upgrade_comm_log_table();

	communications_seed_templates_and_rules();
}

// Upgrade path for a comm_log table created before recipient_name existed
// (needed to show a party's name on the SMS/Email log inquiries without a
// fragile cross-module join at query time - see communications/includes/
// db/comm_log_db.inc). Run only during an explicit migration.
function communications_upgrade_comm_log_table()
{
    migration_require_authorized();
	$res = db_query("SHOW COLUMNS FROM ".TB_PREF."comm_log LIKE 'recipient_name'",
		'Cannot inspect comm_log');
	if (!db_fetch($res))
		db_query("ALTER TABLE ".TB_PREF."comm_log ADD COLUMN recipient_name varchar(150) DEFAULT NULL AFTER recipient_id",
			'Cannot upgrade comm_log');

	// Widen status to include 'queued' (background-task send queue - see
	// communications/includes/db/comm_send_db.inc). MODIFY to the same
	// definition is a safe no-op once already widened.
	db_query("ALTER TABLE ".TB_PREF."comm_log
		MODIFY COLUMN status enum('queued','sent','failed','skipped_gate','skipped_optout') NOT NULL",
		'Cannot widen comm_log.status');
}

// Upgrade path for a comm_gateway_configs table created before Email
// switched to SMTP: widens the channel enum to include 'email' and adds
// the smtp_* columns. Run only during an explicit migration - the enum MODIFY
// is a no-op once already widened, and each column is only added if
// missing (mirrors nyala's sms_upgrade_configurations_table() pattern).
function communications_upgrade_gateway_configs_table()
{
    migration_require_authorized();
	db_query("ALTER TABLE ".TB_PREF."comm_gateway_configs
		MODIFY COLUMN channel enum('sms','email','whatsapp') NOT NULL",
		'Cannot widen comm_gateway_configs.channel');

	$columns = array(
		'smtp_host' => "varchar(255) DEFAULT NULL",
		'smtp_port' => "smallint(5) unsigned DEFAULT NULL",
		'smtp_encryption' => "enum('none','ssl','tls') DEFAULT 'tls'",
		'smtp_username' => "varchar(150) DEFAULT NULL",
		'smtp_password' => "text",
		'smtp_from_email' => "varchar(150) DEFAULT NULL",
		'smtp_from_name' => "varchar(150) DEFAULT NULL",
	);
	foreach ($columns as $column => $definition) {
		$res = db_query("SHOW COLUMNS FROM ".TB_PREF."comm_gateway_configs LIKE ".db_escape($column),
			'Cannot inspect comm_gateway_configs');
		if (!db_fetch($res))
			db_query("ALTER TABLE ".TB_PREF."comm_gateway_configs ADD COLUMN `$column` $definition",
				'Cannot upgrade comm_gateway_configs');
	}
}

// Seeds one default template and one (off-by-default) notification rule
// per event x channel - only the first time each event/channel row is
// missing, never overwrites an admin edit.
function communications_seed_templates_and_rules()
{
    migration_require_authorized();
	// [subject, body] - subject is only used for the email channel.
	$templates = array(
		'sales_invoice' => array(
			'sms' => array(null, 'Dear {customer_name}, invoice {invoice_no} for KES {amount} dated {date} has been raised.'),
			'email' => array('Sales Invoice Raised', "Dear {customer_name},\n\nInvoice {invoice_no} for KES {amount} dated {date} has been raised.\n\nThank you."),
			'whatsapp' => array(null, 'Dear {customer_name}, invoice {invoice_no} for KES {amount} dated {date} has been raised.'),
		),
		'direct_delivery' => array('sms' => array(null, 'Dear {customer_name}, delivery {delivery_no} for KES {amount} was posted on {date}.'), 'email' => array('Direct Delivery Posted', "Dear {customer_name},\n\nDelivery {delivery_no} for KES {amount} was posted on {date}."), 'whatsapp' => array(null, 'Dear {customer_name}, delivery {delivery_no} for KES {amount} was posted on {date}.')),
		'customer_payment' => array('sms' => array(null, 'Dear {customer_name}, payment {payment_no} of KES {amount} was received on {date}.'), 'email' => array('Customer Payment Received', "Dear {customer_name},\n\nPayment {payment_no} of KES {amount} was received on {date}."), 'whatsapp' => array(null, 'Dear {customer_name}, payment {payment_no} of KES {amount} was received on {date}.')),
		'user_account_credentials' => array('sms' => array(null, "Dear {name},\n\nUsername: {username}\nPassword: {temporary_password}\n\nPlease change your password after your first login."), 'email' => array('Your user account credentials', "Dear {name},\n\nUsername: {username}\nPassword: {temporary_password}\n\nPlease change your password after your first login."), 'whatsapp' => array(null, "Dear {name},\n\nUsername: {username}\nPassword: {temporary_password}\n\nPlease change your password after your first login.")),
		'user_password_reset' => array('sms' => array(null, "Dear {name},\n\nUsername: {username}\nNew password: {temporary_password}\n\nPlease change your password after logging in."), 'email' => array('Your password has been reset', "Dear {name},\n\nUsername: {username}\nNew password: {temporary_password}\n\nPlease change your password after logging in."), 'whatsapp' => array(null, "Dear {name},\n\nUsername: {username}\nNew password: {temporary_password}\n\nPlease change your password after logging in.")),
	);

	foreach ($templates as $event_key => $channels) {
		foreach ($channels as $channel => $tpl) {
			list($subject, $body) = $tpl;
			// $subject is null for sms/whatsapp - needs db_escape()'s $nullify
			// flag or it silently seeds '' instead of SQL NULL.
			db_query("INSERT INTO ".TB_PREF."comm_templates (event_key, channel, subject, body, is_active)
				SELECT ".db_escape($event_key).", ".db_escape($channel).", ".db_escape($subject, true).",
					".db_escape($body).", 1
				FROM DUAL WHERE NOT EXISTS
				(SELECT 1 FROM ".TB_PREF."comm_templates WHERE event_key=".db_escape($event_key)."
					AND channel=".db_escape($channel).")",
				'Cannot seed communications template '.$event_key.'/'.$channel);

			db_query("INSERT INTO ".TB_PREF."comm_notification_rules (event_key, channel, is_enabled)
				SELECT ".db_escape($event_key).", ".db_escape($channel).", 0
				FROM DUAL WHERE NOT EXISTS
				(SELECT 1 FROM ".TB_PREF."comm_notification_rules WHERE event_key=".db_escape($event_key)."
					AND channel=".db_escape($channel).")",
				'Cannot seed communications notification rule '.$event_key.'/'.$channel);
		}
	}
	// Account credentials and password resets must always reach the user.
	db_query("UPDATE ".TB_PREF."comm_notification_rules SET is_enabled=1
		WHERE event_key='user_account_credentials' AND channel IN ('sms','email')");
	db_query("UPDATE ".TB_PREF."comm_notification_rules SET is_enabled=1
		WHERE event_key='user_password_reset' AND channel IN ('sms','email')");
}
