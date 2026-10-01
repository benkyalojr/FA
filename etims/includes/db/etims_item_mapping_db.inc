<?php
/**********************************************************************
    eTIMS - per-stock-item KRA registration mapping. A FiscalReceipt
    line can only reference an item Stanbest already knows about
    (AddItem), so every stock item sold under eTIMS needs a row here,
    registered once before it's first invoiced.
***********************************************************************/

include_once(dirname(__FILE__) . '/etims_config_db.inc');
include_once(dirname(__FILE__) . '/etims_client_db.inc');
include_once(dirname(__FILE__) . '/../../../includes/db/inventory_db.inc');

// Matches wakulima's own get_selling_price_() - any price row for this
// item, no sales-type/currency filtering. Informational + fed to AddItem
// as dftPrc, not a source of truth for actual selling - same
// best-effort scope wakulima's own version has.
function etims_item_selling_price($stock_id)
{
	$row = db_fetch(db_query("SELECT price FROM " . TB_PREF . "prices WHERE stock_id=" . db_escape($stock_id) . " LIMIT 1",
		'Cannot get item selling price'));
	return $row ? (float)$row['price'] : 0;
}

function etims_get_item_map($stock_id)
{
	return db_fetch(db_query("SELECT * FROM " . TB_PREF . "etims_item_map WHERE stock_id=" . db_escape($stock_id),
		'Cannot get eTIMS item map'));
}

// Every active stock item, left-joined against its map row (NULL fields
// for one that has never been touched here yet). m.stock_id is
// deliberately left out of the SELECT - mysqli's associative fetch lets a
// later same-named column silently overwrite an earlier one, and for any
// unmapped item (no LEFT JOIN match) m.stock_id is NULL, which would
// clobber s.stock_id and turn every row into an "Unknown stock item".
function get_etims_item_maps()
{
	return db_query("SELECT s.stock_id, s.description,
		m.id, m.kra_item_id, m.kra_item_cd, m.kra_item_cd_df, m.item_cls_cd,
		m.item_ty_cd, m.orgn_nat_cd, m.kra_item_category_id,
		m.tax_ty_cd, m.pkg_unit_cd, m.qty_unit_cd, m.is_registered,
		m.registered_at, m.last_error
		FROM " . TB_PREF . "stock_master s
		LEFT JOIN " . TB_PREF . "etims_item_map m ON m.stock_id = s.stock_id
		WHERE s.inactive = 0
		ORDER BY s.description", 'Cannot list stock items for eTIMS mapping');
}

// Columns that carry a raw SQL NOW() rather than a quoted string value -
// applied in both the UPDATE and INSERT branches below. Missing
// registered_at from this list previously left it going through
// db_escape() in the UPDATE branch (the common path - the tab always
// Saves, creating the row, before Register can run), which quoted the
// literal text "NOW()" into a datetime column and threw
// "Incorrect datetime value: 'NOW()'" (mysqli exception 1292).
function etims_item_map_now_columns()
{
	return array('created_at', 'updated_at', 'registered_at');
}

function etims_save_item_map($stock_id, array $fields)
{
	$now_cols = etims_item_map_now_columns();
	$existing = etims_get_item_map($stock_id);
	if ($existing) {
		$sets = array();
		foreach ($fields as $col => $val)
			$sets[] = "`$col`=" . (in_array($col, $now_cols, true) && $val === 'NOW()' ? 'NOW()' : db_escape($val, $val === null));
		$sets[] = "updated_at=NOW()";
		db_query("UPDATE " . TB_PREF . "etims_item_map SET " . implode(', ', $sets) . "
			WHERE stock_id=" . db_escape($stock_id), 'Cannot update eTIMS item map');
	} else {
		$fields['stock_id'] = $stock_id;
		$fields['created_at'] = $fields['updated_at'] = 'NOW()';
		$cols = $vals = array();
		foreach ($fields as $col => $val) {
			$cols[] = "`$col`";
			$vals[] = in_array($col, $now_cols, true) && $val === 'NOW()' ? 'NOW()' : db_escape($val, $val === null);
		}
		db_query("INSERT INTO " . TB_PREF . "etims_item_map (" . implode(', ', $cols) . ")
			VALUES (" . implode(', ', $vals) . ")", 'Cannot insert eTIMS item map');
	}
}

// Registers (or re-registers) a stock item with Stanbest via AddItem,
// using whatever classification/tax-type/units are currently saved on
// its map row. Stores the KRA-assigned itemCd (which carries a suffix
// Stanbest appends to what we send, e.g. sent "KE3NTU" -> "KE3NTU0179")
// on success, or the error message on failure - both visible on the
// Item Mapping screen.
function etims_register_item_with_kra($stock_id)
{
	$map = etims_get_item_map($stock_id);
	$stock = db_fetch(db_query("SELECT stock_id, description FROM " . TB_PREF . "stock_master
		WHERE stock_id=" . db_escape($stock_id), 'Cannot get stock item'));
	if (!$stock)
		return array('ok' => false, 'error' => 'Unknown stock item');
	if (!$map || $map['item_cls_cd'] === '')
		return array('ok' => false, 'error' => 'Set a KRA Item Classification Code before registering.');

	$cfg = etims_get_config();
	$item_cd = $stock_id;
	$qty = get_qoh_on_date($stock_id);
	$price = etims_item_selling_price($stock_id);

	$body = array(
		'addInfo' => '',
		'bcd' => '',
		'btchNo' => '',
		'currentStock' => (string)$qty,
		'dftPrc' => $price,
		'grpPrcL1' => 0,
		'grpPrcL2' => 0,
		'grpPrcL3' => 0,
		'grpPrcL4' => 0,
		'isrcAplcbYn' => 'N',
		'itemCategoryId' => $map['kra_item_category_id'],
		'itemCd' => $item_cd,
		'itemCdDf' => $item_cd,
		'itemClsCd' => $map['item_cls_cd'],
		'itemNm' => mb_substr($stock['description'], 0, 200),
		'itemStdNm' => mb_substr($stock['description'], 0, 200),
		'itemTyCd' => $map['item_ty_cd'] !== '' ? $map['item_ty_cd'] : '2',
		'modrId' => 'Admin',
		'modrNm' => 'Admin',
		'orgnNatCd' => $map['orgn_nat_cd'] !== '' ? $map['orgn_nat_cd'] : 'KE',
		'pkgUnitCd' => $map['pkg_unit_cd'] !== '' ? $map['pkg_unit_cd'] : 'NT',
		'qtyUnitCd' => $map['qty_unit_cd'] !== '' ? $map['qty_unit_cd'] : 'U',
		'regrId' => 'Admin',
		'regrNm' => 'Admin',
		'taxTyCd' => $map['tax_ty_cd'] !== '' ? $map['tax_ty_cd'] : 'B',
		'useYn' => 'Y',
	);

	try {
		$result = etims_add_item($cfg, $body);
	} catch (Exception $e) {
		$result = array('ok' => false, 'error' => $e->getMessage());
	}

	if ($result['ok']) {
		$returned_item = isset($result['data']['item']) ? $result['data']['item'] : array();
		$returned_cd = isset($returned_item['itemCd']) ? $returned_item['itemCd'] : $item_cd;
		etims_save_item_map($stock_id, array(
			'kra_item_id' => isset($returned_item['id']) ? (int)$returned_item['id'] : null,
			'kra_item_cd' => $returned_cd,
			'kra_item_cd_df' => $item_cd,
			'is_registered' => 1,
			'registered_at' => 'NOW()',
			'last_error' => null,
		));
		return array('ok' => true, 'item_cd' => $returned_cd);
	}

	$message = etims_error_message($result);
	etims_save_item_map($stock_id, array('last_error' => $message));
	return array('ok' => false, 'error' => $message);
}
