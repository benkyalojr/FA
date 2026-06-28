<?php
/**
 * One-shot installer/seeder for the AVO'Gs API.
 *   php api/tools/install.php
 *
 * - Creates the avogs_* tables (only what FA lacks: shifts, handover, tokens,
 *   uploads, ops/finance/sales records).
 * - Seeds MASTER DATA into FA-native tables:
 *     stores     -> 0_locations
 *     customers  -> 0_debtors_master (+ 0_cust_branch)
 *     catalogue  -> 0_stock_master (+ 0_loc_stock, 0_prices)
 *     app user   -> 0_users
 * - Seeds a little demo sales data so endpoints return content immediately.
 */
require dirname(__DIR__) . '/bootstrap.php';

function out($m) { fwrite(STDOUT, $m . "\n"); }

$P = TB_PREF;
$home = get_company_currency();                       // e.g. USD (home currency)
$acc = array(
    'sales' => get_company_pref('default_inv_sales_act'),
    'inv'   => get_company_pref('default_inventory_act'),
    'cogs'  => get_company_pref('default_cogs_act'),
    'adj'   => get_company_pref('default_adj_act'),
    'wip'   => get_company_pref('default_wip_act'),
);

// 1) Custom tables -----------------------------------------------------------
$schema = file_get_contents(dirname(__DIR__) . '/sql/avogs_schema.sql');
// Strip "-- ..." comment lines so stray semicolons in prose don't split statements.
$clean = preg_replace('/^\s*--.*$/m', '', $schema);
$n = 0;
foreach (explode(';', $clean) as $stmt) {
    if (stripos($stmt, 'CREATE TABLE') !== false) {
        db_query(trim($stmt), 'schema create failed');
        $n++;
    }
}
out("Custom tables ensured: $n");

// 2) Stores -> FA locations (loc_code is varchar(5)) -------------------------
$stores = array(
    array('KW', 'Kahawa Wendani'),
    array('KS', 'Kahawa Sukari'),
    array('JUJA', 'Juja'),
    array('TRM', 'TRM'),
    array('KASA', 'Kasarani'),
);
foreach ($stores as $s) {
    db_query("INSERT INTO {$P}locations (loc_code, location_name, delivery_address, contact)
        VALUES (" . db_escape($s[0]) . ", " . db_escape($s[1]) . ", '', '')
        ON DUPLICATE KEY UPDATE location_name=VALUES(location_name)", 'loc seed');
}
out("Stores (0_locations): " . count($stores) . " seeded.");

// 3) Customers -> FA debtors (+ branch) --------------------------------------
$customers = array(
    array(1, 'CASH SALES'),
    array(2, 'Mama Mboga Grocers'),
    array(3, 'Greenfield Hotel'),
    array(4, 'Juja Fresh Market'),
    array(5, 'Kasarani Eatery'),
);
foreach ($customers as $c) {
    db_query("INSERT INTO {$P}debtors_master
        (debtor_no, name, debtor_ref, address, tax_id, curr_code, sales_type, dimension_id, dimension2_id,
         credit_status, payment_terms, discount, pymt_discount, credit_limit, notes, inactive)
        VALUES (" . (int) $c[0] . ", " . db_escape($c[1]) . ", " . db_escape('C' . $c[0]) . ", '', '', "
        . db_escape($home) . ", 1, 0, 0, 1, 4, 0, 0, 0, '', 0)
        ON DUPLICATE KEY UPDATE name=VALUES(name)", 'debtor seed');
    // one branch per customer (only if missing)
    $has = (int) db_num_rows(db_query("SELECT branch_code FROM {$P}cust_branch WHERE debtor_no = " . (int) $c[0]));
    if (!$has) {
        db_query("INSERT INTO {$P}cust_branch
            (debtor_no, br_name, branch_ref, br_address, area, salesman, default_location, tax_group_id,
             sales_account, sales_discount_account, receivables_account, payment_discount_account,
             default_ship_via, br_post_address, group_no, notes, bank_account, inactive)
            VALUES (" . (int) $c[0] . ", " . db_escape($c[1]) . ", " . db_escape('C' . $c[0]) . ", '', 1, 1, 'DEF', 1,
            '', '', '', '', 1, '', 0, '', '', 0)", 'branch seed');
    }
}
out("Customers (0_debtors_master + 0_cust_branch): " . count($customers) . " seeded.");

// 4) Catalogue -> FA stock items + prices ------------------------------------
$catalog = array(); // stock_id, description, sales_type_id, price
$retail = array('S1' => 20, 'S2' => 25, 'S3' => 30, 'S4' => 35, 'S5' => 40, 'S6' => 45, 'S7' => 50);
foreach ($retail as $l => $p) { $catalog[] = array("AVO-RT-$l", "Retail Avocado $l", 1, $p); }
$wholesale = array('S1' => 15, 'S2' => 20, 'S3' => 25, 'S4' => 28, 'S5' => 32, 'S6' => 36, 'S7' => 40);
foreach ($wholesale as $l => $p) { $catalog[] = array("AVO-WS-$l", "Wholesale Avocado $l", 2, $p); }
$honey = array('250G' => 250, '450G' => 450, '900G' => 900);
foreach ($honey as $l => $p) { $catalog[] = array("HNY-$l", "Honey " . strtolower($l), 1, $p); }
$catalog[] = array('BVG-JUICE', 'Fresh Juice 300ml', 1, 70);
$catalog[] = array('BVG-SMOOTHIE', 'Smoothie 300ml', 1, 100);
$catalog[] = array('BVG-GINGER', 'Ginger Shot 50ml', 1, 50);

foreach ($catalog as $e) {
    list($sid, $desc, $stype, $price) = $e;
    db_query("INSERT INTO {$P}stock_master
        (stock_id, category_id, tax_type_id, description, long_description, units, mb_flag,
         sales_account, cogs_account, inventory_account, adjustment_account, wip_account,
         dimension_id, dimension2_id, no_sale, no_purchase, editable, inactive)
        VALUES (" . db_escape($sid) . ", 1, 1, " . db_escape($desc) . ", '', 'each', 'B', "
        . db_escape($acc['sales']) . ", " . db_escape($acc['cogs']) . ", " . db_escape($acc['inv']) . ", "
        . db_escape($acc['adj']) . ", " . db_escape($acc['wip']) . ", 0, 0, 0, 0, 1, 0)
        ON DUPLICATE KEY UPDATE description=VALUES(description)", 'item seed');
    // stock at every location
    db_query("INSERT IGNORE INTO {$P}loc_stock (loc_code, stock_id)
        SELECT loc_code, " . db_escape($sid) . " FROM {$P}locations", 'locstock seed');
    // price (home currency)
    $has = (int) db_num_rows(db_query("SELECT id FROM {$P}prices WHERE stock_id = " . db_escape($sid) . " AND sales_type_id = " . (int) $stype . " AND curr_abrev = " . db_escape($home)));
    if (!$has) {
        db_query("INSERT INTO {$P}prices (stock_id, sales_type_id, curr_abrev, price)
            VALUES (" . db_escape($sid) . ", " . (int) $stype . ", " . db_escape($home) . ", " . (int) $price . ")", 'price seed');
    } else {
        db_query("UPDATE {$P}prices SET price = " . (int) $price . " WHERE stock_id = " . db_escape($sid) . " AND sales_type_id = " . (int) $stype . " AND curr_abrev = " . db_escape($home), 'price upd');
    }
}
out("Catalogue (0_stock_master + 0_prices): " . count($catalog) . " items seeded.");

// 5) App user -> FA 0_users (clone admin's settings) -------------------------
// login: manager / avogs1234  (email manager@avogs.co.ke)
db_query("INSERT INTO {$P}users
    (user_id, password, real_name, role_id, phone, email, language, date_format, date_sep, tho_sep, dec_sep,
     theme, page_size, prices_dec, qty_dec, rates_dec, percent_dec, show_gl, show_codes, show_hints, query_size,
     graphic_links, pos, print_profile, rep_popup, sticky_doc_date, startup_tab, transaction_days,
     save_report_selections, use_date_picker, def_print_destination, def_print_orientation, inactive)
    SELECT 'manager', MD5('avogs1234'), 'Store Manager', role_id, '', 'manager@avogs.co.ke', language, date_format,
     date_sep, tho_sep, dec_sep, theme, page_size, prices_dec, qty_dec, rates_dec, percent_dec, show_gl, show_codes,
     show_hints, query_size, graphic_links, pos, print_profile, rep_popup, sticky_doc_date, startup_tab,
     transaction_days, save_report_selections, use_date_picker, def_print_destination, def_print_orientation, 0
    FROM {$P}users WHERE user_id = 'admin'
    ON DUPLICATE KEY UPDATE password = MD5('avogs1234'), inactive = 0", 'user seed');
out("App user (0_users): manager / avogs1234");

// 6) Demo sales (custom ledger) ---------------------------------------------
$existing = (int) db_num_rows(db_query("SELECT id FROM " . Db::t('sales') . " WHERE store_code = 'TRM' AND DATE(trans_date) = " . db_escape(date('Y-m-d'))));
if (!$existing) {
    avogs_seed_sale('TRM', 'morning', 1, 'CASH SALES', 'Cash', array(array('AVO-RT-S3', 10, 30, 0), array('BVG-JUICE', 2, 70, 0)));
    avogs_seed_sale('TRM', 'morning', 2, 'Mama Mboga Grocers', 'M-Pesa', array(array('AVO-WS-S2', 15, 20, 50)));
    avogs_seed_sale('TRM', 'morning', 1, 'CASH SALES', 'Cash', array(array('HNY-250G', 2, 250, 0), array('BVG-SMOOTHIE', 1, 100, 0)));
    out("Demo: 3 sales seeded for TRM today.");
} else {
    out("Demo: TRM already has sales today; skipped.");
}

out("Done.");

function avogs_seed_sale($store, $shift, $custId, $custName, $pay, $lines)
{
    $P = TB_PREF;
    $subtotal = 0; $discount = 0; $units = 0;
    foreach ($lines as $l) { $subtotal += $l[1] * $l[2]; $discount += $l[3]; $units += $l[1]; }
    $total = $subtotal - $discount;
    $seq = (int) Db::val("SELECT COUNT(*) FROM " . Db::t('sales') . " WHERE store_code = " . db_escape($store) . " AND DATE(trans_date) = " . db_escape(date('Y-m-d'))) + 1;
    $ref = 'INV-' . date('ymd') . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);
    $now = date('Y-m-d H:i:s');
    $saleId = Db::exec("INSERT INTO " . Db::t('sales') . "
        (reference, store_code, shift_key, customer_id, customer_name, payment_method, trans_date, subtotal, discount, total, units, comments)
        VALUES (" . db_escape($ref) . ", " . db_escape($store) . ", " . db_escape($shift) . ", " . (int) $custId . ", "
        . db_escape($custName) . ", " . db_escape($pay) . ", " . db_escape($now) . ", "
        . (int) $subtotal . ", " . (int) $discount . ", " . (int) $total . ", " . (int) $units . ", '')");
    foreach ($lines as $l) {
        $row = Db::row("SELECT description FROM {$P}stock_master WHERE stock_id = " . db_escape($l[0]));
        $name = $row ? $row['description'] : $l[0];
        Db::exec("INSERT INTO " . Db::t('sale_lines') . " (sale_id, stock_id, name, qty, unit_price, discount)
            VALUES (" . (int) $saleId . ", " . db_escape($l[0]) . ", " . db_escape($name) . ", " . (int) $l[1] . ", " . (int) $l[2] . ", " . (int) $l[3] . ")");
    }
}
