<?php
// Runtime checks stay read-only: the tables are created by the deployment migration
// (php scripts/run_migrations.php <company> --apply, or scripts/migrate_mpesa.php).
const MPESA_SCHEMA_VERSION = 2;

function mpesa_schema_ready()
{
	$row = db_fetch(db_query("SELECT value FROM ".TB_PREF."sys_prefs WHERE name='mpesa_schema_version'", 'Cannot check M-Pesa setup version'));
	return $row && (int)$row['value'] >= MPESA_SCHEMA_VERSION;
}

function mpesa_setup_message()
{
	$company = isset($_SESSION['wa_current_user']->cur_con) ? (int)$_SESSION['wa_current_user']->cur_con : 0;
	return _('Open Banking > M-Pesa Configuration and initialize or update M-Pesa to continue.');
}

function mpesa_require_setup()
{
	if (mpesa_schema_ready()) return true;
	display_error(mpesa_setup_message());
	end_page();
	return false;
}
