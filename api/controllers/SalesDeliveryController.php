<?php
/**
 * Sales Delivery (direct) — prefill / commit / consume
 *
 * Routes:
 *   GET  /api/sales/deliveries/prefill
 *   POST /api/sales/deliveries
 *   GET  /api/sales/deliveries/{id}
 *   POST /api/sales/orders/{id}/deliveries   ← fulfil existing SO (optional extension)
 *
 * FA screen : sales/sales_order_entry.php  (?NewDelivery=0)
 * Write fn  : Cart::write() → write_sales_delivery()
 * Stock     : Yes — decrements QOH at the delivery location.
 *             No AR invoice unless invoiced separately.
 *
 * Document chain (direct delivery):
 *   POST /api/sales/deliveries
 *     → auto sales order  (ref "auto")
 *     → delivery note     (debtor_trans type 13 + stock_moves)
 */
class SalesDeliveryController
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

        // For delivery notes QOH is enforced — annotate catalog with low_stock flag.
        $raw_catalog = FaTransaction::catalog(
            $location, $date,
            $cust['curr_code'], $cust['salestype'], $cust['factor']
        );
        $catalog = array();
        foreach ($raw_catalog as $item) {
            $item['low_stock'] = ($item['qoh'] <= 0);
            $catalog[] = $item;
        }

        Response::json(array(
            'trans_type' => ST_CUSTDELIVERY,
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
                'reference'                 => FaTransaction::next_ref(ST_CUSTDELIVERY),
                'deliver_to'                => $cust['name'],
                'delivery_address'          => $cust['address'],
                'ship_via'                  => $branch ? (int) $branch['default_ship_via'] : 1,
                'freight_cost'              => 0,
            ),
            'catalog' => $catalog,
        ));
    }

    // ── Commit — direct delivery ──────────────────────────────────────────

    public static function create(Request $req, $params = array())
    {
        FaTransaction::auth($req);
        FaTransaction::include_sales();

        $body = $req->body;

        $date = isset($body['document_date']) ? $body['document_date'] : date('Y-m-d');
        if (!FaTransaction::valid_date($date)) {
            FaTransaction::validation_error('Invalid document_date.');
        }

        $ref  = FaTransaction::resolve_ref(isset($body['reference']) ? $body['reference'] : null, ST_CUSTDELIVERY);
        $cart = FaTransaction::build_sales_cart(ST_CUSTDELIVERY, $body);
        $cart->reference = $ref;

        $line_out = FaTransaction::add_cart_lines($cart, isset($body['lines']) ? $body['lines'] : array());

        // Cart::write() for a direct delivery:
        //   1. Detects no src_docs → makes parent SO (ref "auto")
        //   2. Reads parent SO and converts to child → write_sales_delivery()
        $delivery_no = $cart->write(1);

        // Resolve auto-created parent SO
        $auto_so = null;
        $dn_row  = FaTransaction::debtor_trans($delivery_no, ST_CUSTDELIVERY);
        if ($dn_row) {
            $auto_so = (int) $dn_row['order_'];
        }

        Response::json(array(
            'delivery_no'  => (int) $delivery_no,
            'trans_type'   => ST_CUSTDELIVERY,
            'reference'    => $ref,
            'auto_created' => array('sales_order_no' => $auto_so),
            'stock_moves'  => FaTransaction::stock_moves($delivery_no, ST_CUSTDELIVERY),
            'lines'        => $line_out,
        ), 201);
    }

    // ── Commit — fulfil from existing sales order ─────────────────────────

    /**
     * POST /api/sales/orders/{id}/deliveries
     *
     * Loads the parent sales order into a Cart (prepare_child=true) and commits
     * a partial or full delivery against it.
     */
    public static function createFromOrder(Request $req, $params = array())
    {
        FaTransaction::auth($req);
        FaTransaction::include_sales();

        $order_no = (int) ($params['id'] ?? 0);
        $body     = $req->body;

        // Load parent SO into a Cart prepared for a child delivery
        $cart = new Cart(ST_CUSTDELIVERY, $order_no, true);
        if (!$cart->customer_id) {
            Response::error('Sales order not found.', 404);
        }

        $date = isset($body['document_date']) ? $body['document_date'] : date('Y-m-d');
        if (!FaTransaction::valid_date($date)) {
            FaTransaction::validation_error('Invalid document_date.');
        }

        $ref = FaTransaction::resolve_ref(isset($body['reference']) ? $body['reference'] : null, ST_CUSTDELIVERY);
        $cart->reference     = $ref;
        $cart->document_date = FaTransaction::to_fa_date($date);
        if (isset($body['comments'])) $cart->Comments = $body['comments'];

        // Update quantities on existing cart lines from request body
        if (!empty($body['lines']) && is_array($body['lines'])) {
            foreach ($body['lines'] as $l) {
                $stock_id = $l['stock_id'] ?? '';
                $qty      = (float) ($l['quantity'] ?? 0);
                foreach ($cart->line_items as $ln => $item) {
                    if ($item->stock_id === $stock_id) {
                        $cart->line_items[$ln]->qty_dispatched = $qty;
                        break;
                    }
                }
            }
        }

        $delivery_no = $cart->write(1);

        Response::json(array(
            'delivery_no' => (int) $delivery_no,
            'trans_type'  => ST_CUSTDELIVERY,
            'reference'   => $ref,
            'order_no'    => $order_no,
            'stock_moves' => FaTransaction::stock_moves($delivery_no, ST_CUSTDELIVERY),
        ), 201);
    }

    // ── Consume ──────────────────────────────────────────────────────────

    public static function show(Request $req, $params = array())
    {
        FaTransaction::auth($req);
        FaTransaction::include_sales();

        $delivery_no = (int) ($params['id'] ?? 0);
        $header      = FaTransaction::debtor_trans($delivery_no, ST_CUSTDELIVERY);
        if (!$header) {
            Response::error('Delivery note not found.', 404);
        }

        $detail_res = FaTransaction::debtor_trans_details($delivery_no, ST_CUSTDELIVERY);
        $lines = array();
        while ($l = db_fetch($detail_res)) {
            $qty_invoiced_row = db_fetch(db_query(
                "SELECT SUM(d.quantity) AS qty_invoiced
                 FROM " . TB_PREF . "debtor_trans_details d
                 JOIN " . TB_PREF . "debtor_trans t ON t.trans_no = d.debtor_trans_no AND t.type = " . ST_SALESINVOICE . "
                 WHERE t.order_ = " . (int) $header['order_'] . "
                   AND d.stock_id = " . db_escape($l['stock_id'])
            ));
            $qty   = (float) $l['quantity'];
            $price = (float) $l['unit_price'];
            $disc  = (float) $l['discount_percent'];
            $lines[] = array(
                'stock_id'         => $l['stock_id'],
                'description'      => $l['description'],
                'units'            => $l['units'],
                'quantity'         => $qty,
                'qty_invoiced'     => $qty_invoiced_row ? (float) $qty_invoiced_row['qty_invoiced'] : 0,
                'unit_price'       => $price,
                'discount_percent' => $disc * 100,
                'line_total'       => round($qty * $price * (1 - $disc), 2),
            );
        }

        Response::json(array(
            'delivery_no'   => (int) $header['trans_no'],
            'trans_type'    => ST_CUSTDELIVERY,
            'reference'     => $header['reference'],
            'customer_id'   => (int) $header['debtor_no'],
            'branch_id'     => (int) $header['branch_code'],
            'document_date' => $header['tran_date'],
            'sales_order'   => (int) $header['order_'],
            'currency'      => $header['curr_code'],
            'lines'         => $lines,
            'stock_moves'   => FaTransaction::stock_moves($delivery_no, ST_CUSTDELIVERY),
        ));
    }
}
