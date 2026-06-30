<?php
/**
 * FaTransaction — shared Phase-2 helpers for the FA transaction REST API.
 *
 * Responsibilities:
 *  - Lazy-load FA PHP include files (avoids loading everything on every request)
 *  - Resolve catalog, prices and QOH from FA tables
 *  - Build and validate Cart / purch_order objects
 *  - Emit the standard JSON error shape
 */
class FaTransaction
{
    private static $sales_loaded      = false;
    private static $purchasing_loaded = false;
    private static $inventory_loaded  = false;
    private static $payment_loaded    = false;

    /** Include FA .inc files without leaking buffered HTML into the API response. */
    private static function fa_include($path)
    {
        ob_start();
        include_once $path;
        ob_end_clean();
    }

    // ── Lazy FA include loaders ───────────────────────────────────────────

    public static function include_sales()
    {
        if (self::$sales_loaded) return;
        global $path_to_root;
        self::fa_include($path_to_root . '/sales/includes/sales_db.inc');
        self::fa_include($path_to_root . '/sales/includes/db/sales_order_db.inc');
        self::fa_include($path_to_root . '/sales/includes/db/sales_invoice_db.inc');
        self::fa_include($path_to_root . '/sales/includes/db/sales_delivery_db.inc');
        self::fa_include($path_to_root . '/includes/ui/ui_globals.inc');
        self::fa_include($path_to_root . '/sales/includes/cart_class.inc');
        self::$sales_loaded = true;
    }

    public static function include_purchasing()
    {
        if (self::$purchasing_loaded) return;
        global $path_to_root;
        self::fa_include($path_to_root . '/purchasing/includes/purchasing_db.inc');
        self::fa_include($path_to_root . '/purchasing/includes/db/suppliers_db.inc');
        self::fa_include($path_to_root . '/purchasing/includes/db/po_db.inc');
        self::fa_include($path_to_root . '/purchasing/includes/po_class.inc');
        self::$purchasing_loaded = true;
    }

    public static function include_inventory()
    {
        if (self::$inventory_loaded) return;
        global $path_to_root;
        self::fa_include($path_to_root . '/includes/db/inventory_db.inc');
        self::fa_include($path_to_root . '/inventory/includes/db/items_transfer_db.inc');
        self::fa_include($path_to_root . '/inventory/includes/db/items_adjust_db.inc');
        self::$inventory_loaded = true;
    }

    public static function include_payment()
    {
        if (self::$payment_loaded) return;
        global $path_to_root;
        self::fa_include($path_to_root . '/sales/includes/db/payment_db.inc');
        self::fa_include($path_to_root . '/sales/includes/db/custalloc_db.inc');
        self::fa_include($path_to_root . '/includes/ui/allocation_cart.inc');
        self::fa_include($path_to_root . '/gl/includes/db/gl_db_bank_accounts.inc');
        self::$payment_loaded = true;
    }

    // ── Price / QOH helpers ───────────────────────────────────────────────

    /**
     * Customer-specific unit price for a stock item.
     * Mirrors the resolution order used by FA's get_price().
     */
    public static function sales_price($stock_id, $currency, $sales_type_id, $factor, $date)
    {
        return get_price($stock_id, $currency, $sales_type_id, $factor, self::to_fa_date($date));
    }

    /**
     * Quantity on hand at a location on a given date.
     * Source of truth: stock_moves (not loc_stock).
     */
    public static function qoh($stock_id, $location, $date)
    {
        return (float) get_qoh_on_date($stock_id, $location, self::to_fa_date($date));
    }

    /**
     * Full item catalog with optional customer price and QOH.
     */
    public static function catalog($location, $date, $currency = null, $sales_type_id = null, $factor = null)
    {
        $res = db_query(
            "SELECT s.stock_id, s.description, s.units, s.mb_flag, s.material_cost
             FROM " . TB_PREF . "stock_master s
             WHERE s.inactive = 0 AND s.mb_flag != 'D'
             ORDER BY s.stock_id"
        );
        $items = array();
        while ($r = db_fetch($res)) {
            $price = null;
            if ($currency !== null && $sales_type_id !== null) {
                $price = (float) get_price($r['stock_id'], $currency, $sales_type_id, $factor, $date);
            }
            $items[] = array(
                'stock_id'    => $r['stock_id'],
                'description' => $r['description'],
                'units'       => $r['units'],
                'mb_flag'     => $r['mb_flag'],
                'unit_price'  => $price,
                'qoh'         => self::qoh($r['stock_id'], $location, $date),
                'is_kit'      => ($r['mb_flag'] === 'K'),
            );
        }
        return $items;
    }

    /**
     * Purchasing catalog with supplier prices and QOH.
     */
    public static function purchase_catalog($supplier_id, $location, $date)
    {
        $res = db_query(
            "SELECT s.stock_id, s.description, s.units, s.mb_flag
             FROM " . TB_PREF . "stock_master s
             WHERE s.inactive = 0 AND s.mb_flag != 'D'
             ORDER BY s.stock_id"
        );
        $items = array();
        while ($r = db_fetch($res)) {
            $items[] = array(
                'stock_id'        => $r['stock_id'],
                'description'     => $r['description'],
                'units'           => $r['units'],
                'supplier_price'  => (float) get_purchase_price($supplier_id, $r['stock_id']),
                'qoh_at_location' => self::qoh($r['stock_id'], $location, $date),
            );
        }
        return $items;
    }

    // ── Reference helpers ─────────────────────────────────────────────────

    /** Next auto-generated reference string for a transaction type. */
    public static function next_ref($trans_type)
    {
        global $Refs;
        return $Refs->get_next($trans_type);
    }

    /** Returns true if the reference has not been used for this trans_type yet. */
    public static function ref_is_new($ref, $trans_type)
    {
        return (bool) is_new_reference($ref, $trans_type);
    }

    // ── Date helpers ──────────────────────────────────────────────────────

    public static function valid_date($date)
    {
        return $date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
    }

    /**
     * Convert an API ISO date (YYYY-MM-DD) to FA's user display format.
     * FA write functions call date2sql() which expects display format, not ISO.
     */
    public static function to_fa_date($iso_date)
    {
        if (!$iso_date) {
            return Today();
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso_date)) {
            return sql2date($iso_date);
        }
        return $iso_date;
    }

    /** Convert FA display/SQL date to ISO for JSON responses. */
    public static function to_iso_date($fa_date)
    {
        if (!$fa_date) {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fa_date)) {
            return $fa_date;
        }
        $sql = date2sql($fa_date);
        return $sql ? $sql : $fa_date;
    }

    /** Reject API dates that fall outside an open FA fiscal year. */
    public static function assert_fiscal_year($iso_date)
    {
        if (!is_date_in_fiscalyear(self::to_fa_date($iso_date))) {
            self::validation_error(
                'document_date is not in an open fiscal year. Open or add a fiscal year in FA Setup.',
                array('document_date' => 'Outside open fiscal year')
            );
        }
    }

    // ── Validation error response ─────────────────────────────────────────

    /**
     * Emit HTTP 422 with the standard error shape and exit.
     */
    public static function validation_error($message, $fields = array())
    {
        $body = array('error' => array(
            'code'    => 'validation_failed',
            'message' => $message,
            'fields'  => $fields,
        ));
        Response::json($body, 422);
        // Response::json calls exit, but be explicit for static analysis.
        exit;
    }

    // ── Cart builder (sales orders, invoices, deliveries) ─────────────────

    /**
     * Load customer + branch defaults and build a configured Cart object
     * for any sales document type (ST_SALESORDER, ST_SALESINVOICE, ST_CUSTDELIVERY).
     *
     * The caller must still:
     *   1. Add line items via add_cart_lines()
     *   2. Call $cart->write() to persist
     */
    public static function build_sales_cart($trans_type, $body)
    {
        self::include_sales();

        $customer_id = (int) ($body['customer_id'] ?? 0);
        $branch_id   = (int) ($body['branch_id']   ?? 0);

        $cust = get_customer_to_order($customer_id);
        if (!$cust) {
            self::validation_error('Unknown customer_id.', array('customer_id' => 'Not found'));
        }

        $branch_res = get_branch_to_order($customer_id, $branch_id);
        $branch     = db_fetch($branch_res);

        $cart = new Cart($trans_type);

        $cart->set_customer(
            $customer_id,
            $cust['name'],
            $cust['curr_code'],
            $cust['discount'],
            $cust['payment_terms']
        );

        if ($branch) {
            $cart->set_branch(
                $branch_id,
                $branch['tax_group_id'],
                $branch['tax_group_name']
            );
        }

        $cart->set_sales_type(
            $cust['salestype'],
            $cust['sales_type'],
            $cust['tax_included'],
            $cust['factor']
        );

        $location = $body['location'] ?? ($branch ? $branch['default_location'] : '');
        $loc_row = db_fetch(db_query(
            "SELECT loc_code, location_name FROM " . TB_PREF . "locations WHERE loc_code=" . db_escape($location)
        ));
        if ($loc_row) {
            $cart->set_location($loc_row['loc_code'], $loc_row['location_name']);
        }

        $cart->set_delivery(
            (int) ($body['ship_via'] ?? ($branch ? (int) $branch['default_ship_via'] : 1)),
            $body['deliver_to']       ?? $cust['name'],
            $body['delivery_address'] ?? $cust['address'],
            (float) ($body['freight_cost'] ?? 0)
        );

        $date = self::to_fa_date($body['document_date'] ?? date('Y-m-d'));
        $cart->document_date = $date;
        $cart->due_date      = self::to_fa_date($body['delivery_date'] ?? ($body['document_date'] ?? date('Y-m-d')));
        $cart->Comments      = $body['comments']      ?? '';
        $cart->cust_ref      = $body['cust_ref']      ?? '';
        $cart->dimension_id  = (int) ($body['dimension_id']  ?? $cust['dimension_id']  ?? 0);
        $cart->dimension2_id = (int) ($body['dimension2_id'] ?? $cust['dimension2_id'] ?? 0);
        $cart->prep_amount   = (float) ($body['prep_amount'] ?? 0);

        return $cart;
    }

    /**
     * Append request lines to a Cart. Validates stock_id + quantity presence.
     */
    public static function add_cart_lines(Cart $cart, $lines)
    {
        if (empty($lines) || !is_array($lines)) {
            self::validation_error('At least one line is required.');
        }
        $ln = 0;
        $out = array();
        foreach ($lines as $line) {
            $stock_id = isset($line['stock_id']) ? trim($line['stock_id']) : '';
            $qty      = (float) ($line['quantity']         ?? 0);
            $price    = (float) ($line['unit_price']       ?? 0);
            $disc_pct = (float) ($line['discount_percent'] ?? 0);
            $disc     = $disc_pct / 100;
            $desc     = isset($line['description']) ? $line['description'] : null;

            if (!$stock_id || $qty <= 0) {
                self::validation_error('Invalid line.', array(
                    "lines[$ln].stock_id"  => $stock_id  ? 'ok' : 'required',
                    "lines[$ln].quantity"  => $qty > 0   ? 'ok' : 'must be > 0',
                ));
            }

            $cart->add_to_cart($ln, $stock_id, $qty, $price, $disc, 0, 0, $desc);
            $out[] = array(
                'stock_id'         => $stock_id,
                'quantity'         => $qty,
                'unit_price'       => $price,
                'discount_percent' => $disc_pct,
                'line_total'       => round($qty * $price * (1 - $disc), 2),
            );
            $ln++;
        }
        return $out;
    }

    /**
     * Validate that a reference is unique and return it (or auto-generate one).
     */
    public static function resolve_ref($ref, $trans_type)
    {
        if (!$ref) {
            return self::next_ref($trans_type);
        }
        if (!self::ref_is_new($ref, $trans_type)) {
            Response::json(array('error' => array(
                'code'    => 'conflict',
                'message' => 'Duplicate reference: ' . $ref,
            )), 409);
            exit;
        }
        return $ref;
    }

    /** Require auth and return $auth array. */
    public static function auth(Request $req)
    {
        return Auth::requireUser($req);
    }

    // ── Shared first-branch lookup ────────────────────────────────────────

    public static function default_branch_id($customer_id)
    {
        $r = db_fetch(db_query(
            "SELECT branch_code FROM " . TB_PREF . "cust_branch
             WHERE debtor_no=" . (int) $customer_id . " AND inactive=0
             ORDER BY branch_code LIMIT 1"
        ));
        return $r ? (int) $r['branch_code'] : 0;
    }

    // ── Debtor_trans / stock_moves read helpers ───────────────────────────

    /** Read a debtor_trans header row. */
    public static function debtor_trans($trans_no, $type)
    {
        return db_fetch(db_query(
            "SELECT * FROM " . TB_PREF . "debtor_trans
             WHERE trans_no=" . (int) $trans_no . " AND type=" . (int) $type
        ));
    }

    /** Read debtor_trans_details rows for a transaction. */
    public static function debtor_trans_details($trans_no, $type)
    {
        return db_query(
            "SELECT d.*, s.description AS item_desc, s.units
             FROM " . TB_PREF . "debtor_trans_details d
             LEFT JOIN " . TB_PREF . "stock_master s ON s.stock_id = d.stock_id
             WHERE d.debtor_trans_no=" . (int) $trans_no . " AND d.debtor_trans_type=" . (int) $type
        );
    }

    /** Read stock_moves for a transaction. */
    public static function stock_moves($trans_no, $type)
    {
        $res = db_query(
            "SELECT stock_id, loc_code, qty, standard_cost
             FROM " . TB_PREF . "stock_moves
             WHERE trans_no=" . (int) $trans_no . " AND type=" . (int) $type
        );
        $rows = array();
        while ($r = db_fetch($res)) {
            $rows[] = array(
                'stock_id'      => $r['stock_id'],
                'location'      => $r['loc_code'],
                'qty'           => (float) $r['qty'],
                'standard_cost' => (float) $r['standard_cost'],
            );
        }
        return $rows;
    }
}
