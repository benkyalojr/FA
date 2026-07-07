<?php
/**
 * Sales/purchase combo dropdown diagnostics.
 *
 *   php api/tools/diagnose_combos.php
 *
 * Checks master data and company prefs that FA uses to populate dropdowns
 * (customers, items, locations, etc.) on sales_order_entry and similar screens.
 */
require dirname(__DIR__) . '/bootstrap.php';

function out($m) { fwrite(STDOUT, $m . "\n"); }

global $db_connections;
$comp = isset($_SESSION['wa_current_user']) ? $_SESSION['wa_current_user']->cur_con : 0;
$P = isset($db_connections[$comp]['tbpref']) ? $db_connections[$comp]['tbpref'] : TB_PREF;

function count_rows($sql, $label)
{
    $row = db_fetch_assoc(db_query($sql, $label));
    return (int) $row['n'];
}

out("=== FA combo / dropdown diagnostics ===\n");
out("Table prefix: {$P}\n");

$checks = array(
    'Customers (debtors_master, active)' =>
        "SELECT COUNT(*) AS n FROM {$P}debtors_master WHERE !inactive",
    'Customer branches (cust_branch, active)' =>
        "SELECT COUNT(*) AS n FROM {$P}cust_branch WHERE !inactive",
    'Stock items (stock_master, sellable)' =>
        "SELECT COUNT(*) AS n FROM {$P}stock_master WHERE !inactive AND !no_sale AND mb_flag != 'F'",
    'Item codes (item_codes) — required for sales Item combo' =>
        "SELECT COUNT(*) AS n FROM {$P}item_codes WHERE !inactive",
    'Sellable item_codes join (what sales_items_list uses)' =>
        "SELECT COUNT(*) AS n FROM {$P}stock_master s, {$P}item_codes i
         WHERE i.stock_id = s.stock_id AND !i.inactive AND !s.inactive AND !s.no_sale AND s.mb_flag != 'F'",
    'Locations (non fixed-asset, active)' =>
        "SELECT COUNT(*) AS n FROM {$P}locations WHERE !inactive AND fixed_asset = 0",
    'Sales types (price lists)' =>
        "SELECT COUNT(*) AS n FROM {$P}sales_types",
    'Payment terms' =>
        "SELECT COUNT(*) AS n FROM {$P}payment_terms",
    'Shippers' =>
        "SELECT COUNT(*) AS n FROM {$P}shippers",
);

$problems = array();

foreach ($checks as $label => $sql) {
    $n = count_rows($sql, $label);
    $flag = ($n === 0) ? ' *** EMPTY ***' : '';
    out(sprintf("%-55s %5d%s", $label . ':', $n, $flag));
    if ($n === 0) {
        $problems[] = $label;
    }
}

$stock = count_rows("SELECT COUNT(*) AS n FROM {$P}stock_master", 'stock total');
$codes = count_rows("SELECT COUNT(*) AS n FROM {$P}item_codes", 'codes total');
if ($stock > 0 && $codes === 0) {
    out("\n*** Likely Item dropdown bug: stock_master has rows but item_codes is empty.");
    out("    Run: php api/tools/fix_item_codes.php");
}

out("\nCompany prefs (search-only mode makes combos look empty until you type *):");
$searchPrefs = array('no_item_list', 'no_customer_list', 'no_supplier_list');
$searchOn = 0;
foreach (array_merge($searchPrefs, array('curr_default')) as $pref) {
    $v = get_company_pref($pref);
    out("  {$pref}: " . ($v === null || $v === '' ? '(not set)' : $v));
    if (in_array($pref, $searchPrefs, true) && (string) $v === '1') {
        $searchOn++;
    }
}
if ($searchOn > 0) {
    out("\n*** Search-only list mode is ON ({$searchOn}/3). Customer/Item/Supplier combos");
    out("    show almost nothing until you type * in the search box and click lookup,");
    out("    or run: php api/tools/fix_combo_search_prefs.php");
    out("    or uncheck them in Setup → Company Setup.");
}

$home = get_company_currency();
out("\nHome currency: {$home}");

$badCust = count_rows(
    "SELECT COUNT(*) AS n FROM {$P}debtors_master WHERE curr_code != " . db_escape($home),
    'foreign customers'
);
$badPrice = count_rows(
    "SELECT COUNT(*) AS n FROM {$P}prices WHERE curr_abrev != " . db_escape($home),
    'foreign prices'
);
if ($badCust || $badPrice) {
    out("Customers/prices in foreign currency: {$badCust} / {$badPrice}");
    out("  Run: php api/tools/fix_currency.php");
}

$missingRate = count_rows(
    "SELECT COUNT(DISTINCT d.curr_code) AS n FROM {$P}debtors_master d
     LEFT JOIN {$P}exchange_rates e ON e.curr_code = d.curr_code AND e.date_ <= CURDATE()
     WHERE d.curr_code != " . db_escape($home) . " AND e.rate_buy IS NULL",
    'missing rates'
);
if ($missingRate) {
    out("Foreign currencies without exchange rates: {$missingRate}");
}

out("\nSession (AJAX combos fail if session is unstable behind a proxy):");
out("  Run: php api/tools/diagnose_session.php");

if ($problems) {
    out("\n--- Action ---");
    if (strpos(implode(' ', $problems), 'item_codes') !== false || ($stock > 0 && $codes === 0)) {
        out("  php api/tools/fix_item_codes.php");
    }
    if (count($problems) > 2) {
        out("  php api/tools/install.php   # seed demo master data if this is a fresh DB");
    }
    out("  git pull origin APIs          # includes session.save_path fix for PHP 8");
    out("  ensure tmp/ is writable for session files");
} else {
    out("\nMaster data looks OK. If dropdowns are still empty in the browser:");
    out("  1. Hard-refresh; check DevTools → Network for failed JsHttpRequest POSTs");
    out("  2. If search prefs are 1, type * in the combo search box");
    out("  3. Run php api/tools/diagnose_session.php on the server");
}

out('');
