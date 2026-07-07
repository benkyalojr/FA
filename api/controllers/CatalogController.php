<?php
/**
 * Read-only catalogue endpoints for the mobile app.
 *
 * Mirrors FA screens:
 *   inventory/prices.php           → GET /prices (item × sales type × currency)
 *   inventory/purchasing_data.php  → GET /purchasing-data, GET /suppliers/{id}/prices
 *
 *   GET /items                          — stock items (no prices)
 *   GET /items/{stock_id}               — one item (+ optional QOH)
 *   GET /items/{stock_id}/context       — item + QOH + price for one customer/supplier
 *   GET /prices                         — sales price matrix (0_prices)
 *   GET /purchasing-data                — supplier purchasing rows (0_purch_data)
 *   GET /customers/{id}/prices          — resolved selling prices for a customer category
 *   GET /suppliers/{id}/prices          — purchasing data rows for one supplier
 */
class CatalogController
{
    /** Sellable stock items from FA (item_codes + stock_master). */
    public static function items(Request $req, $params = array())
    {
        FaTransaction::auth($req);
        FaTransaction::include_inventory();

        $location = $req->q('location', '');
        $date     = avogs_date($req);
        $include_qoh = ($location !== '');

        $sql = "SELECT s.stock_id, s.description, s.units, s.mb_flag, s.material_cost,
                       s.category_id, cat.description AS category_name
                FROM " . TB_PREF . "stock_master s
                INNER JOIN " . TB_PREF . "item_codes i ON i.stock_id = s.stock_id AND i.item_code = i.stock_id
                LEFT JOIN " . TB_PREF . "stock_category cat ON cat.category_id = s.category_id
                WHERE !s.inactive AND !i.inactive AND !s.no_sale AND s.mb_flag != 'F'
                ORDER BY s.stock_id";

        $out = array();
        $res = db_query($sql, 'items list');
        while ($r = db_fetch($res)) {
            $row = array(
                'stock_id'       => $r['stock_id'],
                'description'    => $r['description'],
                'units'          => $r['units'],
                'mb_flag'        => $r['mb_flag'],
                'material_cost'  => (float) $r['material_cost'],
                'category_id'    => (int) $r['category_id'],
                'category_name'  => $r['category_name'],
            );
            if ($include_qoh) {
                $row['qoh'] = FaTransaction::qoh($r['stock_id'], $location, $date);
            }
            $out[] = $row;
        }
        Response::json($out);
    }

    /** Single item; optional QOH via ?location= */
    public static function itemShow(Request $req, $params = array())
    {
        FaTransaction::auth($req);
        FaTransaction::include_inventory();

        $stock_id = isset($params['stock_id']) ? $params['stock_id'] : '';
        $location = $req->q('location', '');
        $date     = avogs_date($req);

        $r = db_fetch(db_query(
            "SELECT s.stock_id, s.description, s.units, s.mb_flag, s.material_cost,
                    s.category_id, cat.description AS category_name
             FROM " . TB_PREF . "stock_master s
             LEFT JOIN " . TB_PREF . "stock_category cat ON cat.category_id = s.category_id
             WHERE s.stock_id = " . db_escape($stock_id) . " AND !s.inactive"
        ));
        if (!$r) {
            Response::error('Item not found.', 404);
        }

        $row = array(
            'stock_id'      => $r['stock_id'],
            'description'   => $r['description'],
            'units'         => $r['units'],
            'mb_flag'       => $r['mb_flag'],
            'material_cost' => (float) $r['material_cost'],
            'category_id'   => (int) $r['category_id'],
            'category_name' => $r['category_name'],
        );
        if ($location !== '') {
            $row['qoh'] = FaTransaction::qoh($stock_id, $location, $date);
        }
        Response::json($row);
    }

    /** Sales price matrix from 0_prices (FA inventory/prices.php). */
    public static function prices(Request $req, $params = array())
    {
        FaTransaction::auth($req);

        $stock_id      = $req->q('stock_id', '');
        $sales_type_id = $req->q('sales_type_id', '');
        $currency      = $req->q('currency', '');

        $where = array('1=1');
        if ($stock_id !== '') {
            $where[] = 'p.stock_id = ' . db_escape($stock_id);
        }
        if ($sales_type_id !== '') {
            $where[] = 'p.sales_type_id = ' . (int) $sales_type_id;
        }
        if ($currency !== '') {
            $where[] = 'p.curr_abrev = ' . db_escape($currency);
        }

        $sql = "SELECT p.id, p.stock_id, sm.description AS item_name,
                       p.sales_type_id, st.sales_type AS sales_type_name,
                       p.curr_abrev AS currency, p.price
                FROM " . TB_PREF . "prices p
                LEFT JOIN " . TB_PREF . "sales_types st ON st.id = p.sales_type_id
                LEFT JOIN " . TB_PREF . "stock_master sm ON sm.stock_id = p.stock_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY p.stock_id, p.curr_abrev, p.sales_type_id";

        $out = array();
        $res = db_query($sql, 'prices list');
        while ($r = db_fetch($res)) {
            $out[] = array(
                'id'              => (int) $r['id'],
                'stock_id'        => $r['stock_id'],
                'item_name'       => $r['item_name'],
                'sales_type_id'   => (int) $r['sales_type_id'],
                'sales_type_name' => $r['sales_type_name'],
                'currency'        => $r['currency'],
                'price'           => (float) $r['price'],
            );
        }
        Response::json($out);
    }

    /** Supplier purchasing rows from 0_purch_data (FA inventory/purchasing_data.php). */
    public static function purchasingData(Request $req, $params = array())
    {
        FaTransaction::auth($req);

        $stock_id    = $req->q('stock_id', '');
        $supplier_id = (int) $req->q('supplier_id', 0);

        $where = array('1=1');
        if ($stock_id !== '') {
            $where[] = 'pd.stock_id = ' . db_escape($stock_id);
        }
        if ($supplier_id) {
            $where[] = 'pd.supplier_id = ' . (int) $supplier_id;
        }

        $sql = "SELECT pd.supplier_id, s.supp_name AS supplier_name, s.curr_code AS currency,
                       pd.stock_id, sm.description AS item_name,
                       pd.price, pd.suppliers_uom, pd.conversion_factor, pd.supplier_description
                FROM " . TB_PREF . "purch_data pd
                INNER JOIN " . TB_PREF . "suppliers s ON s.supplier_id = pd.supplier_id
                LEFT JOIN " . TB_PREF . "stock_master sm ON sm.stock_id = pd.stock_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY pd.stock_id, s.supp_name";

        $out = array();
        $res = db_query($sql, 'purchasing data list');
        while ($r = db_fetch($res)) {
            $out[] = self::formatPurchasingRow($r);
        }
        Response::json($out);
    }

    /** Resolved selling prices for a customer (category = sales type on debtors_master). */
    public static function customerPrices(Request $req, $params = array())
    {
        FaTransaction::auth($req);
        FaTransaction::include_inventory();
        FaTransaction::include_sales();

        $customer_id = (int) (isset($params['id']) ? $params['id'] : 0);
        $stock_id    = $req->q('stock_id', '');
        $date        = avogs_date($req);

        if (!$customer_id) {
            FaTransaction::validation_error('customer id is required.');
        }

        $cust = get_customer_to_order($customer_id);
        if (!$cust) {
            Response::error('Customer not found.', 404);
        }

        $items = self::sellable_items($stock_id);
        $lines = array();
        foreach ($items as $item) {
            $sid = $item['stock_id'];
            $matrix = get_stock_price_type_currency($sid, $cust['salestype'], $cust['curr_code']);
            $lines[] = array(
                'stock_id'      => $sid,
                'item_name'     => $item['description'],
                'list_price'    => $matrix ? (float) $matrix['price'] : null,
                'unit_price'    => (float) FaTransaction::sales_price(
                    $sid,
                    $cust['curr_code'],
                    $cust['salestype'],
                    $cust['factor'],
                    $date
                ),
                'currency'      => $cust['curr_code'],
                'sales_type_id' => (int) $cust['salestype'],
            );
        }

        Response::json(array(
            'customer_id'   => $customer_id,
            'customer_name' => $cust['name'],
            'currency'      => $cust['curr_code'],
            'sales_type_id' => (int) $cust['salestype'],
            'sales_type'    => $cust['sales_type'],
            'discount_percent' => round((float) $cust['discount'] * 100, 2),
            'date'          => $date,
            'prices'        => $lines,
        ));
    }

    /** Purchasing data rows for one supplier (subset of /purchasing-data). */
    public static function supplierPrices(Request $req, $params = array())
    {
        FaTransaction::auth($req);

        $supplier_id = (int) (isset($params['id']) ? $params['id'] : 0);
        $stock_id    = $req->q('stock_id', '');

        if (!$supplier_id) {
            FaTransaction::validation_error('supplier id is required.');
        }

        $sup = db_fetch(db_query(
            "SELECT supplier_id, supp_name, curr_code FROM " . TB_PREF . "suppliers
             WHERE supplier_id = " . (int) $supplier_id . " AND !inactive"
        ));
        if (!$sup) {
            Response::error('Supplier not found.', 404);
        }

        $where = array('pd.supplier_id = ' . (int) $supplier_id);
        if ($stock_id !== '') {
            $where[] = 'pd.stock_id = ' . db_escape($stock_id);
        }

        $sql = "SELECT pd.supplier_id, s.supp_name AS supplier_name, s.curr_code AS currency,
                       pd.stock_id, sm.description AS item_name,
                       pd.price, pd.suppliers_uom, pd.conversion_factor, pd.supplier_description
                FROM " . TB_PREF . "purch_data pd
                INNER JOIN " . TB_PREF . "suppliers s ON s.supplier_id = pd.supplier_id
                LEFT JOIN " . TB_PREF . "stock_master sm ON sm.stock_id = pd.stock_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY pd.stock_id";

        $lines = array();
        $res = db_query($sql, 'supplier purchasing data');
        while ($r = db_fetch($res)) {
            $lines[] = self::formatPurchasingRow($r);
        }

        Response::json(array(
            'supplier_id'   => $supplier_id,
            'supplier_name' => $sup['supp_name'],
            'currency'      => $sup['curr_code'],
            'prices'        => $lines,
        ));
    }

    /** One item with optional customer/supplier price and QOH (dynamic line entry). */
    public static function itemContext(Request $req, $params = array())
    {
        FaTransaction::auth($req);
        FaTransaction::include_sales();
        FaTransaction::include_inventory();

        $stock_id    = isset($params['stock_id']) ? $params['stock_id'] : '';
        $customer_id = (int) $req->q('customer_id', 0);
        $supplier_id = (int) $req->q('supplier_id', 0);
        $location    = $req->q('location', '');
        $date        = avogs_date($req);

        $sm = db_fetch(db_query(
            "SELECT stock_id, description, units, mb_flag, material_cost
             FROM " . TB_PREF . "stock_master WHERE stock_id=" . db_escape($stock_id)
        ));
        if (!$sm) {
            Response::error('Unknown stock_id.', 404);
        }

        $result = array(
            'stock_id'      => $sm['stock_id'],
            'description'   => $sm['description'],
            'units'         => $sm['units'],
            'material_cost' => (float) $sm['material_cost'],
            'qoh'           => $location !== '' ? FaTransaction::qoh($stock_id, $location, $date) : null,
        );

        if ($customer_id) {
            $cust = get_customer_to_order($customer_id);
            if ($cust) {
                $result['unit_price'] = (float) FaTransaction::sales_price(
                    $stock_id, $cust['curr_code'], $cust['salestype'], $cust['factor'], $date
                );
                $result['currency'] = $cust['curr_code'];
                $result['sales_type_id'] = (int) $cust['salestype'];
            }
        }

        if ($supplier_id) {
            FaTransaction::include_purchasing();
            $result['supplier_price'] = (float) get_purchase_price($supplier_id, $stock_id);
        }

        Response::json($result);
    }

    /** @return array[] */
    private static function sellable_items($stock_id_filter = '')
    {
        $where = array(
            '!s.inactive',
            '!i.inactive',
            '!s.no_sale',
            "s.mb_flag != 'F'",
        );
        if ($stock_id_filter !== '') {
            $where[] = 's.stock_id = ' . db_escape($stock_id_filter);
        }

        $items = array();
        $res = db_query(
            "SELECT DISTINCT s.stock_id, s.description
             FROM " . TB_PREF . "stock_master s
             INNER JOIN " . TB_PREF . "item_codes i ON i.stock_id = s.stock_id AND i.item_code = i.stock_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY s.stock_id",
            'sellable items'
        );
        while ($r = db_fetch($res)) {
            $items[] = array(
                'stock_id'    => $r['stock_id'],
                'description' => $r['description'],
            );
        }
        return $items;
    }

    private static function formatPurchasingRow($r)
    {
        $factor = (float) $r['conversion_factor'];
        if ($factor == 0) {
            $factor = 1;
        }

        return array(
            'supplier_id'          => (int) $r['supplier_id'],
            'supplier_name'        => $r['supplier_name'],
            'stock_id'             => $r['stock_id'],
            'item_name'            => $r['item_name'],
            'price'                => (float) $r['price'],
            'unit_price'           => (float) $r['price'] / $factor,
            'currency'             => $r['currency'],
            'suppliers_uom'        => $r['suppliers_uom'],
            'conversion_factor'    => $factor,
            'supplier_description' => $r['supplier_description'],
        );
    }
}
