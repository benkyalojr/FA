<?php
/**
 * Sales Order — prefill / commit / consume
 *
 * Routes:
 *   GET  /api/sales/orders/prefill
 *   POST /api/sales/orders
 *   GET  /api/sales/orders/{id}
 *
 * FA screen : sales/sales_order_entry.php  (?NewOrder=Yes)
 * Write fn  : add_sales_order()            (sales/includes/db/sales_order_db.inc)
 * Stock     : None (orders do not move inventory)
 */
class SalesOrderController
{
    // ── Prefill ──────────────────────────────────────────────────────────

    public static function prefill(Request $req, $params = array())
    {
        FaTransaction::auth($req);
        FaTransaction::include_sales();

        $customer_id = (int) $req->q('customer_id', 1);
        $branch_id   = (int) $req->q('branch_id', 0);
        $location    = $req->q('location', null);
        $date        = avogs_date($req);

        $cust = get_customer_to_order($customer_id);
        if (!$cust) {
            FaTransaction::validation_error('Unknown customer_id.', array('customer_id' => 'Not found'));
        }

        if (!$branch_id) {
            $branch_id = FaTransaction::default_branch_id($customer_id);
        }
        $branch_res = get_branch_to_order($customer_id, $branch_id);
        $branch     = db_fetch($branch_res);

        if (!$location) {
            $location = $branch ? $branch['default_location'] : '';
        }

        $catalog = FaTransaction::catalog(
            $location, $date,
            $cust['curr_code'], $cust['salestype'], $cust['factor']
        );

        Response::json(array(
            'trans_type' => ST_SALESORDER,
            'defaults' => array(
                'customer_id'               => $customer_id,
                'branch_id'                 => $branch_id,
                'sales_type'                => (int) $cust['salestype'],
                'sales_type_name'           => $cust['sales_type'],
                'currency'                  => $cust['curr_code'],
                'default_discount_percent'  => (float) $cust['discount'],
                'payment_terms'             => $cust['payment_terms'],
                'location'                  => $location,
                'document_date'             => $date,
                'delivery_date'             => $date,
                'reference'                 => FaTransaction::next_ref(ST_SALESORDER),
                'deliver_to'                => $cust['name'],
                'delivery_address'          => $cust['address'],
                'ship_via'                  => $branch ? (int) $branch['default_ship_via'] : 1,
                'freight_cost'              => 0,
            ),
            'catalog' => $catalog,
        ));
    }

    // ── Commit ───────────────────────────────────────────────────────────

    public static function create(Request $req, $params = array())
    {
        FaTransaction::auth($req);
        FaTransaction::include_sales();

        $body = $req->body;

        $date = isset($body['document_date']) ? $body['document_date'] : date('Y-m-d');
        if (!FaTransaction::valid_date($date)) {
            FaTransaction::validation_error('Invalid document_date.');
        }

        $ref  = FaTransaction::resolve_ref(isset($body['reference']) ? $body['reference'] : null, ST_SALESORDER);
        $cart = FaTransaction::build_sales_cart(ST_SALESORDER, $body);
        $cart->reference = $ref;

        $line_out = FaTransaction::add_cart_lines($cart, isset($body['lines']) ? $body['lines'] : array());

        $order_no = $cart->write(1);
        if ($order_no < 0) {
            Response::json(array('error' => array(
                'code'    => 'conflict',
                'message' => 'Duplicate reference rejected by FA.',
            )), 409);
        }

        Response::json(array(
            'order_no'   => (int) $order_no,
            'trans_type' => ST_SALESORDER,
            'reference'  => $ref,
            'customer_id'=> (int) ($body['customer_id'] ?? 0),
            'total'      => round($cart->get_items_total() + (float) ($body['freight_cost'] ?? 0), 2),
            'lines'      => $line_out,
            'fa_tables'  => array('header' => 'sales_orders', 'details' => 'sales_order_details'),
        ), 201);
    }

    // ── Consume ──────────────────────────────────────────────────────────

    public static function show(Request $req, $params = array())
    {
        FaTransaction::auth($req);
        FaTransaction::include_sales();

        $order_no = (int) ($params['id'] ?? 0);
        $header   = get_sales_order_header($order_no, ST_SALESORDER);
        if (!$header) {
            Response::error('Sales order not found.', 404);
        }

        $detail_res = get_sales_order_details($order_no, ST_SALESORDER);
        $lines = array();
        while ($l = db_fetch($detail_res)) {
            $qty   = (float) $l['quantity'];
            $price = (float) $l['unit_price'];
            $disc  = (float) $l['discount_percent'];
            $lines[] = array(
                'stock_id'         => $l['stk_code'],
                'description'      => $l['description'],
                'quantity'         => $qty,
                'qty_sent'         => (float) $l['qty_done'],
                'unit_price'       => $price,
                'discount_percent' => $disc * 100,
                'line_total'       => round($qty * $price * (1 - $disc), 2),
            );
        }

        Response::json(array(
            'order_no'         => (int) $header['order_no'],
            'trans_type'       => ST_SALESORDER,
            'reference'        => $header['reference'],
            'customer_id'      => (int) $header['debtor_no'],
            'branch_id'        => (int) $header['branch_code'],
            'location'         => $header['from_stk_loc'],
            'document_date'    => $header['ord_date'],
            'delivery_date'    => $header['delivery_date'],
            'deliver_to'       => $header['deliver_to'],
            'delivery_address' => $header['delivery_address'],
            'freight_cost'     => (float) $header['freight_cost'],
            'comments'         => $header['comments'],
            'lines'            => $lines,
        ));
    }
}
