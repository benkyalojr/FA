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

    /** Customers = FA debtors (+ their branch). CASH SALES is the default. */
    public static function customers(Request $req)
    {
        Auth::requireUser($req);
        $rows = Db::rows("SELECT d.debtor_no, d.name,
                MIN(b.branch_code) AS branch_code
            FROM " . TB_PREF . "debtors_master d
            LEFT JOIN " . TB_PREF . "cust_branch b ON b.debtor_no = d.debtor_no
            WHERE d.inactive = 0
            GROUP BY d.debtor_no, d.name
            ORDER BY d.debtor_no");
        $out = array();
        foreach ($rows as $r) {
            $out[] = array(
                'id' => (int) $r['debtor_no'],
                'branch_id' => (int) $r['branch_code'],
                'name' => $r['name'],
            );
        }
        Response::json($out);
    }

    /** Catalogue = FA stock items + their sales price. Group/index derived from stock_id. */
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
