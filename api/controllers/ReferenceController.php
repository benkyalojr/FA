<?php
class ReferenceController
{
    /** Stores = FA stock locations. */
    public static function stores(Request $req)
    {
        Auth::requireUser($req);
        $rows = Db::rows("SELECT loc_code, location_name FROM " . TB_PREF . "locations WHERE inactive = 0 ORDER BY location_name");
        $out = array();
        foreach ($rows as $r) {
            $out[] = array('code' => $r['loc_code'], 'name' => $r['location_name']);
        }
        Response::json($out);
    }

    /** Customers = FA debtors (+ default branch). Read-only. */
    public static function customers(Request $req)
    {
        Auth::requireUser($req);
        $rows = Db::rows("SELECT d.debtor_no, d.name, d.curr_code, d.sales_type, d.discount,
                MIN(b.branch_code) AS branch_code
            FROM " . TB_PREF . "debtors_master d
            LEFT JOIN " . TB_PREF . "cust_branch b ON b.debtor_no = d.debtor_no AND !b.inactive
            WHERE d.inactive = 0
            GROUP BY d.debtor_no, d.name, d.curr_code, d.sales_type, d.discount
            ORDER BY d.debtor_no");
        $out = array();
        foreach ($rows as $r) {
            $out[] = array(
                'id'               => (int) $r['debtor_no'],
                'branch_id'        => (int) $r['branch_code'],
                'name'             => $r['name'],
                'currency'         => $r['curr_code'],
                'sales_type_id'    => (int) $r['sales_type'],
                'discount_percent' => round((float) $r['discount'] * 100, 2),
            );
        }
        Response::json($out);
    }

    /** Customer categories (sales types) used on inventory/prices.php. Read-only. */
    public static function salesTypes(Request $req)
    {
        Auth::requireUser($req);
        FaTransaction::include_sales();

        $out = array();
        $res = get_all_sales_types();
        while ($r = db_fetch($res)) {
            $out[] = array(
                'id'           => (int) $r['id'],
                'name'         => $r['sales_type'],
                'tax_included' => (bool) $r['tax_included'],
                'factor'       => (float) $r['factor'],
            );
        }
        Response::json($out);
    }

    /** Single customer with all branches. Read-only. */
    public static function customerShow(Request $req, $params = array())
    {
        Auth::requireUser($req);
        FaTransaction::include_sales();

        $id = (int) (isset($params['id']) ? $params['id'] : 0);
        $cust = get_customer_to_order($id);
        if (!$cust) {
            Response::error('Customer not found.', 404);
        }

        $branches = array();
        $res = db_query(
            "SELECT branch_code, br_name, br_address, default_location, inactive
             FROM " . TB_PREF . "cust_branch
             WHERE debtor_no = " . (int) $id . " ORDER BY branch_code"
        );
        while ($b = db_fetch($res)) {
            $branches[] = array(
                'id'                => (int) $b['branch_code'],
                'name'              => $b['br_name'],
                'address'           => $b['br_address'],
                'default_location'  => $b['default_location'],
                'inactive'          => (bool) $b['inactive'],
            );
        }

        Response::json(array(
            'id'               => $id,
            'name'             => $cust['name'],
            'address'          => $cust['address'],
            'currency'         => $cust['curr_code'],
            'sales_type_id'    => (int) $cust['salestype'],
            'sales_type'       => $cust['sales_type'],
            'discount_percent' => round((float) $cust['discount'] * 100, 2),
            'payment_terms'    => (int) $cust['payment_terms'],
            'credit_available' => (float) $cust['cur_credit'],
            'tax_included'     => (bool) $cust['tax_included'],
            'branches'         => $branches,
        ));
    }

    /** Suppliers = FA suppliers. Read-only. */
    public static function suppliers(Request $req)
    {
        Auth::requireUser($req);
        $rows = Db::rows("SELECT supplier_id, supp_name, curr_code, gst_no
            FROM " . TB_PREF . "suppliers
            WHERE inactive = 0
            ORDER BY supp_name");
        $out = array();
        foreach ($rows as $r) {
            $out[] = array(
                'id'       => (int) $r['supplier_id'],
                'name'     => $r['supp_name'],
                'currency' => $r['curr_code'],
                'tax_id'   => $r['gst_no'],
            );
        }
        Response::json($out);
    }

    /** Single supplier. Read-only. */
    public static function supplierShow(Request $req, $params = array())
    {
        Auth::requireUser($req);

        $id = (int) (isset($params['id']) ? $params['id'] : 0);
        $r = db_fetch(db_query(
            "SELECT s.supplier_id, s.supp_name, s.curr_code, s.gst_no, s.supp_ref,
                    s.address, s.tax_group_id, s.payment_terms, s.tax_included,
                    pt.terms AS payment_terms_name
             FROM " . TB_PREF . "suppliers s
             LEFT JOIN " . TB_PREF . "payment_terms pt ON pt.terms_indicator = s.payment_terms
             WHERE s.supplier_id = " . (int) $id . " AND !s.inactive"
        ));
        if (!$r) {
            Response::error('Supplier not found.', 404);
        }

        Response::json(array(
            'id'                  => (int) $r['supplier_id'],
            'name'                => $r['supp_name'],
            'reference'           => $r['supp_ref'],
            'currency'            => $r['curr_code'],
            'tax_id'              => $r['gst_no'],
            'address'             => $r['address'],
            'tax_group_id'        => (int) $r['tax_group_id'],
            'payment_terms'       => (int) $r['payment_terms'],
            'payment_terms_name'  => $r['payment_terms_name'],
            'tax_included'        => (bool) $r['tax_included'],
        ));
    }

    /** Catalogue = FA stock items + default price (legacy mobile helper). */
    public static function catalog(Request $req)
    {
        Auth::requireUser($req);
        $rows = Db::rows("SELECT s.stock_id, s.description, s.units,
                (SELECT p.price FROM " . TB_PREF . "prices p WHERE p.stock_id = s.stock_id ORDER BY p.sales_type_id LIMIT 1) AS price
            FROM " . TB_PREF . "stock_master s
            WHERE s.inactive = 0 AND s.no_sale = 0
            ORDER BY s.stock_id");
        $out = array();
        foreach ($rows as $r) {
            $gi = avogs_group_index($r['stock_id']);
            $out[] = array(
                'stock_id' => $r['stock_id'],
                'name' => $r['description'],
                'group' => $gi['group'],
                'index' => $gi['index'],
                'price' => (int) $r['price'],
                'unit' => avogs_unit_for($gi['group']),
            );
        }
        // Stable order: group, then index.
        usort($out, function ($a, $b) {
            $order = array('retail' => 0, 'wholesale' => 1, 'honey' => 2, 'beverage' => 3, 'other' => 4);
            $ga = $order[$a['group']]; $gb = $order[$b['group']];
            if ($ga !== $gb) return $ga - $gb;
            return $a['index'] - $b['index'];
        });
        Response::json($out);
    }

    public static function paymentMethods(Request $req)
    {
        Auth::requireUser($req);
        Response::json(array('Cash', 'M-Pesa', 'Card', 'Credit'));
    }

    /** FA payment terms (cash vs credit) for direct sales and AR. */
    public static function paymentTerms(Request $req)
    {
        Auth::requireUser($req);
        Response::json(FaTransaction::list_payment_terms());
    }

    /** Shift definitions managed in FA maintenance (Items & Inventory). */
    public static function shifts(Request $req)
    {
        Auth::requireUser($req);
        $rows = Db::rows("SELECT shift_key, name, start_time, end_time
            FROM " . TB_PREF . "avogs_shift_defs
            WHERE inactive = 0
            ORDER BY sort_order, name");
        $out = array();
        foreach ($rows as $r) {
            $out[] = array(
                'key' => $r['shift_key'],
                'name' => $r['name'],
                'start' => $r['start_time'] ? substr($r['start_time'], 0, 5) : null,
                'end' => $r['end_time'] ? substr($r['end_time'], 0, 5) : null,
            );
        }
        Response::json($out);
    }
}
