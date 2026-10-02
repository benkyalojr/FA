<?php
require_once __DIR__.'/../includes/migration_policy.inc';
// Grants the access rights introduced with the audit trail, background
// queue, migrations, eTIMS and Communications to the built-in System
// Administrator role only. Other roles are never touched - assign the new
// rights to them in Setup > Access Setup. Additive and repeatable.
function migrate_admin_new_access()
{
	migration_require_authorized();
	if (defined('SS_SADMIN')) {
		global $security_areas;
	} else {
		include dirname(__DIR__).'/includes/access_levels.inc'; // defines $security_areas locally
	}

	$wanted = array('SA_RUNMIGRATIONS', 'SA_BGTASKS', 'SA_SYSTEMAUDIT',
		'SA_ETIMSSETUP', 'SA_ETIMSITEMS', 'SA_ETIMSVIEW', 'SA_ETIMSXREPORT', 'SA_ETIMSZREPORT',
		'SA_COMMSETUP', 'SA_COMMTEMPLATES', 'SA_COMMRULES', 'SA_COMMRECIPIENTPREFS', 'SA_COMMSEND', 'SA_COMMLOG',
		'SA_MPESASETUP', 'SA_MPESAVIEW', 'SA_MPESAREQUEST', 'SA_MPESAREVIEW', 'SA_MPESAPAYOUT', 'SA_MPESAAPPROVE');
	$result = db_query("SELECT id, sections, areas FROM ".TB_PREF."security_roles WHERE role='System Administrator'",
		'Cannot read security roles');
	while ($row = db_fetch($result)) {
		$sections = array_filter(explode(';', $row['sections']), 'strlen');
		$areas = array_filter(explode(';', $row['areas']), 'strlen');
		foreach ($wanted as $key) {
			if (!isset($security_areas[$key])) continue;
			$code = $security_areas[$key][0];
			$sections[] = $code & ~255;
			$areas[] = $code;
		}
		$sections = array_values(array_unique(array_map('intval', $sections)));
		$areas = array_values(array_unique(array_map('intval', $areas)));
		sort($sections);
		sort($areas);
		db_query("UPDATE ".TB_PREF."security_roles SET sections=".db_escape(implode(';', $sections)).
			", areas=".db_escape(implode(';', $areas))." WHERE id=".(int)$row['id'], 'Cannot update System Administrator role');
	}
}
