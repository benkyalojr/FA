<?php
/**********************************************************************
    eTIMS - the business's own item categories in Stanbest (distinct
    from etims_code_list's static KRA reference data - these are live,
    account-specific, and can only be read from the API, not seeded).
    Cached into etims_code_list under cl_name='Item Category' so the
    Item Category field can use the same select2 combo mechanism as
    everything else (etims_codes_db.inc) - synced on demand via
    etims_sync_categories(), not fetched live on every page load.
***********************************************************************/

include_once(dirname(__FILE__) . '/etims_config_db.inc');
include_once(dirname(__FILE__) . '/etims_client_db.inc');

// Full replace, not an incremental upsert - a "sync" means "match
// Stanbest's current list exactly", including categories deleted there
// since the last sync.
function etims_sync_categories()
{
	$cfg = etims_get_config();

	try {
		$result = etims_get_categories($cfg);
	} catch (Exception $e) {
		return array('ok' => false, 'error' => $e->getMessage());
	}

	if (!$result['ok'])
		return array('ok' => false, 'error' => etims_error_message($result));

	$categories = isset($result['data']['categories']) ? $result['data']['categories'] : array();

	db_query("DELETE FROM " . TB_PREF . "etims_code_list WHERE cl_name='Item Category'",
		'Cannot clear cached eTIMS categories');

	$rows = array();
	$order = 0;
	foreach ($categories as $cat) {
		if (isset($cat['status']) && $cat['status'] !== 'active')
			continue;
		$rows[] = "('Item Category', " . db_escape((string)$cat['id']) . ", "
			. db_escape($cat['category']) . ", " . (++$order) . ")";
	}
	if ($rows) {
		db_query("INSERT INTO " . TB_PREF . "etims_code_list (cl_name, code, code_name, sort_order) VALUES "
			. implode(', ', $rows), 'Cannot cache eTIMS categories');
	}

	return array('ok' => true, 'count' => count($rows));
}
