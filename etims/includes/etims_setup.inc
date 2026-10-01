<?php
// Runtime checks must stay read-only. Increment this version when the
// deployment migration changes; never run schema/seed work in a page request.
const ETIMS_SCHEMA_VERSION = 1;

function etims_schema_ready()
{
	// Read the marker directly so an already logged-in session sees deployment
	// immediately. One indexed lookup, without information_schema or DDL.
	$row = db_fetch(db_query("SELECT value FROM " . TB_PREF . "sys_prefs WHERE name='etims_schema_version'",
		'Cannot check eTIMS setup version'));
	return $row && (int)$row['value'] >= ETIMS_SCHEMA_VERSION;
}

function etims_setup_message()
{
	$company = isset($_SESSION['wa_current_user']->cur_con) ? (int)$_SESSION['wa_current_user']->cur_con : 0;
	return sprintf(_('eTIMS setup needs a deployment update. Ask your administrator to run: php scripts/migrate_etims.php %d'), $company);
}

function etims_require_setup()
{
	if (etims_schema_ready())
		return true;
	page(_('eTIMS setup required'));
	display_error(etims_setup_message());
	end_page();
	return false;
}
