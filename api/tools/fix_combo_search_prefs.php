<?php
/**
 * Disable FA search-only combo mode (empty-looking dropdowns).
 *
 *   php api/tools/fix_combo_search_prefs.php
 *
 * When no_item_list / no_customer_list / no_supplier_list are 1, FA shows
 * search boxes instead of full dropdowns. Combos look empty until the user
 * types * and clicks the lookup icon.
 */
require dirname(__DIR__) . '/bootstrap.php';

function out($m) { fwrite(STDOUT, $m . "\n"); }

global $db_connections;
$comp = isset($_SESSION['wa_current_user']) ? $_SESSION['wa_current_user']->cur_con : 0;
$P = isset($db_connections[$comp]['tbpref']) ? $db_connections[$comp]['tbpref'] : TB_PREF;

out("=== Disable search-only combo prefs ===\n");

$names = array('no_item_list', 'no_customer_list', 'no_supplier_list');
foreach ($names as $name) {
    $before = get_company_pref($name);
    db_query(
        "UPDATE {$P}sys_prefs SET value = '0' WHERE name = " . db_escape($name),
        'update ' . $name
    );
    out("  {$name}: {$before} → 0");
}

out("\n✓ Done. Hard-refresh sales_order_entry — Customer and Item dropdowns");
out("  should list all options without typing * in the search box.\n");
