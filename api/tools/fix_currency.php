<?php
/**
 * Align seeded master data with FA's home currency.
 *
 * Run once from the command line:
 *   php api/tools/fix_currency.php
 *
 * Symptom this fixes:
 *   "Cannot retrieve exchange rate for currency USD as of …"
 *   on sales/purchase screens when curr_default is KES but customers/prices
 *   were seeded in USD (common after changing company home currency, or if
 *   install.php ran before curr_default was set to KES).
 *
 * What it does:
 *   - Sets all debtors_master.curr_code to the company home currency
 *   - Sets all prices.curr_abrev to the company home currency
 *   Does NOT convert amounts — AVO'Gs prices are already integer KSh values.
 */
require dirname(__DIR__) . '/bootstrap.php';

function out($m) { fwrite(STDOUT, $m . "\n"); }

$P = TB_PREF;
$home = get_company_currency();

out("=== AVO'Gs / FA currency alignment ===\n");
out("Company home currency: {$home}");

$res = db_query("SELECT COUNT(*) AS n FROM {$P}debtors_master WHERE curr_code != " . db_escape($home), 'count debtors');
$row = db_fetch_assoc($res);
$debtors = (int) $row['n'];

$res = db_query("SELECT COUNT(*) AS n FROM {$P}prices WHERE curr_abrev != " . db_escape($home), 'count prices');
$row = db_fetch_assoc($res);
$prices = (int) $row['n'];

out("Customers not in {$home}: {$debtors}");
out("Price rows not in {$home}: {$prices}");

if ($debtors === 0 && $prices === 0) {
    out("\nNothing to fix — all master data already uses {$home}.");
    exit(0);
}

db_query(
    "UPDATE {$P}debtors_master SET curr_code = " . db_escape($home)
    . " WHERE curr_code != " . db_escape($home),
    'update debtors'
);
db_query(
    "UPDATE {$P}prices SET curr_abrev = " . db_escape($home)
    . " WHERE curr_abrev != " . db_escape($home),
    'update prices'
);

out("\n✓ Updated customers and prices to {$home}.");
out("  Refresh the sales screen — the USD exchange-rate warning should be gone.");
out("\nIf you genuinely need foreign-currency customers, revert individual");
out("customers in Sales → Customers and add rates under GL → Currencies → Exchange Rates.\n");
