<?php
require_once __DIR__.'/../includes/migration_policy.inc';
/**********************************************************************
    Communications - per-channel toggles, category setup.communications.
    Mirrors gl_module_setup.php/payroll_module_setup.php - one screen
    holding every Communications sub-toggle, separate from the top-level
    use_communications optional-module toggle on
    admin/company_preferences.php.

    Each channel is off by default even after the module itself is
    enabled - turning one on is a deliberate step once its gateway is
    configured under Gateway Settings (email needs no gateway of its
    own, it reuses the company email/bcc_email prefs).
***********************************************************************/
function migrate_communications_module()
{
    migration_require_authorized();
	foreach (array(
		'use_communications_sms' => '0',
		'use_communications_email' => '0',
		'use_communications_whatsapp' => '0',
	) as $pref => $default) {
		db_query("INSERT INTO ".TB_PREF."sys_prefs (name, category, type, length, value)
			SELECT ".db_escape($pref).", 'setup.communications', 'tinyint', 1, ".db_escape($default)."
			FROM DUAL WHERE NOT EXISTS
			(SELECT 1 FROM ".TB_PREF."sys_prefs WHERE name=".db_escape($pref).")",
			'Cannot seed Communications feature preference '.$pref);
	}
}
