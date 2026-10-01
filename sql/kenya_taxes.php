<?php
require_once __DIR__.'/../includes/migration_policy.inc';
/**********************************************************************
    Kenyan VAT set-up for an existing company: VAT 16%, Zero Rated and Exempt.
    Additive and idempotent - it only adds what is missing (matched by name) and
    never changes or deletes tax types, groups or item tax types that already
    exist or are in use. Switching customers, suppliers and items over to the new
    groups / item tax types is left to the user.

      Tax types       VAT 16% (16), Zero Rated (0)
      Tax groups      VAT (VAT 16% + Zero Rated), Tax Exempt (no tax)
      Item tax types  Standard Rated (16%)  - exempt from the Zero Rated tax
                      Zero Rated            - exempt from VAT 16%
                      Exempt                - fully exempt (no tax rows at all)
    (A Zero Rated item still reports a 0% line on the tax return; an Exempt item
    does not appear on it - the distinction Kenyan VAT requires.)
***********************************************************************/

function migrate_kenya_taxes()
{
    migration_require_authorized();
	$account = db_fetch(db_query("SELECT sales_gl_code, purchasing_gl_code FROM ".TB_PREF."tax_types ORDER BY id LIMIT 1", 'tax account'));
	$sales = $account ? $account['sales_gl_code'] : '2150';
	$purch = $account ? $account['purchasing_gl_code'] : '2150';

	$tax_id = function ($name, $rate) use ($sales, $purch) {
		$r = db_fetch(db_query("SELECT id FROM ".TB_PREF."tax_types WHERE name=".db_escape($name), 'tax type'));
		if ($r) return (int)$r['id'];
		db_query("INSERT INTO ".TB_PREF."tax_types (rate, sales_gl_code, purchasing_gl_code, name, inactive) VALUES ("
			.db_escape($rate).", ".db_escape($sales).", ".db_escape($purch).", ".db_escape($name).", 0)", 'add tax type');
		return (int)db_insert_id();
	};
	$vat = $tax_id('VAT 16%', 16);
	$zero = $tax_id('Zero Rated', 0);

	$group_id = function ($name) {
		$r = db_fetch(db_query("SELECT id FROM ".TB_PREF."tax_groups WHERE name=".db_escape($name), 'tax group'));
		if ($r) return array((int)$r['id'], false);
		db_query("INSERT INTO ".TB_PREF."tax_groups (name, inactive) VALUES (".db_escape($name).", 0)", 'add tax group');
		return array((int)db_insert_id(), true);
	};
	list($vat_group, $created) = $group_id('VAT');
	if ($created)
		foreach (array($vat, $zero) as $t)
			db_query("INSERT INTO ".TB_PREF."tax_group_items (tax_group_id, tax_type_id, tax_shipping) VALUES ($vat_group, $t, 1)", 'add tax group item');
	$group_id('Tax Exempt');

	$item_type = function ($name, $exempt, $exempt_from) {
		$r = db_fetch(db_query("SELECT id FROM ".TB_PREF."item_tax_types WHERE name=".db_escape($name), 'item tax type'));
		if ($r) return;
		db_query("INSERT INTO ".TB_PREF."item_tax_types (name, exempt, inactive) VALUES (".db_escape($name).", ".(int)$exempt.", 0)", 'add item tax type');
		$id = (int)db_insert_id();
		foreach ($exempt_from as $t)
			db_query("INSERT INTO ".TB_PREF."item_tax_type_exemptions (item_tax_type_id, tax_type_id) VALUES ($id, $t)", 'add exemption');
	};
	$item_type('Standard Rated (16%)', 0, array($zero));
	$item_type('Zero Rated', 0, array($vat));
	$item_type('Exempt', 1, array());
}
