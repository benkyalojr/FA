<?php
/**
 * Backfill 0_item_codes from 0_stock_master.
 *
 *   php api/tools/fix_item_codes.php
 *
 * FA sales/purchase item combos join item_codes, not stock_master alone.
 * If items exist in Inventory but the sales Item dropdown is empty, this
 * is usually the cause (also fixed in api/tools/install.php for new seeds).
 */
require dirname(__DIR__) . '/bootstrap.php';

function out($m) { fwrite(STDOUT, $m . "\n"); }

$P = TB_PREF;

$before = (int) db_fetch_assoc(db_query("SELECT COUNT(*) AS n FROM {$P}item_codes", 'count before'))['n'];
$stock = (int) db_fetch_assoc(db_query(
    "SELECT COUNT(*) AS n FROM {$P}stock_master WHERE mb_flag != 'F'",
    'stock count'
))['n'];

out("=== Backfill item_codes from stock_master ===\n");
out("item_codes before: {$before}");
out("stock_master rows: {$stock}");

db_query(
    "INSERT INTO {$P}item_codes (item_code, stock_id, description, category_id, quantity, is_foreign, inactive)
     SELECT s.stock_id, s.stock_id, s.description, s.category_id, 1, 0, s.inactive
     FROM {$P}stock_master s
     LEFT JOIN {$P}item_codes i ON i.item_code = s.stock_id
     WHERE i.item_code IS NULL AND s.mb_flag != 'F'",
    'backfill item_codes'
);

$after = (int) db_fetch_assoc(db_query("SELECT COUNT(*) AS n FROM {$P}item_codes", 'count after'))['n'];
$added = $after - $before;

out("\nitem_codes after:  {$after}");
out("Rows added:        {$added}");

if ($added > 0) {
    out("\n✓ Done. Refresh sales_order_entry — Item dropdown should populate.");
} elseif ($before === 0 && $stock === 0) {
    out("\nNo stock items in database. Run: php api/tools/install.php");
} else {
    out("\nNothing to add — item_codes already in sync with stock_master.");
}

out('');
