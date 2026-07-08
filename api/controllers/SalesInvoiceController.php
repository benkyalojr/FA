<?php
/**
 * Sales Invoice (direct) — prefill / commit / consume
 *
 * Routes:
 *   GET  /api/sales/invoices/prefill
 *   POST /api/sales/invoices          ← replaces shadow-table (0_avogs_sales) in Phase 2
 *   GET  /api/sales/invoices/{id}
 *
 * FA screen : sales/sales_order_entry.php  (?NewInvoice=0)
 * Write fn  : Cart::write() → write_sales_invoice()
 * Stock     : Yes — direct invoice auto-creates parent SO + delivery, then
 *             posts invoice, stock_moves and GL.
 *
 * Document chain:
 *   POST /api/sales/invoices
 *     → auto sales order  (ref "auto")
 *     → auto delivery note (stock out)
 *     → sales invoice      (debtor_trans type 10 + GL)
 */
class SalesInvoiceController
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

        $due_date = get_invoice_duedate($cust['payment_terms'], $date);

        $catalog = FaTransaction::catalog(
            $location, $date,
            $cust['curr_code'], $cust['salestype'], $cust['factor']
        );

        $payment_terms = FaTransaction::list_payment_terms();
        $default_pt = null;
        foreach ($payment_terms as $pt) {
            if ((int) $pt['id'] === (int) $cust['payment_terms']) {
                $default_pt = $pt;
                break;
            }
        }

        Response::json(array(
            'trans_type' => ST_SALESINVOICE,
            'defaults' => array(
                'customer_id'               => $customer_id,
                'branch_id'                 => $branch_id,
                'sales_type'                => (int) $cust['salestype'],
                'sales_type_name'           => $cust['sales_type'],
                'currency'                  => $cust['curr_code'],
                'default_discount_percent'  => (float) $cust['discount'],
                'payment_terms'             => (int) $cust['payment_terms'],
                'payment_terms_name'        => $default_pt ? $default_pt['name'] : null,
                'on_credit'                 => $default_pt ? !empty($default_pt['on_credit']) : false,
                'location'                  => $location,
                'document_date'             => $date,
                'due_date'                  => $due_date,
                'reference'                 => FaTransaction::next_ref(ST_SALESINVOICE),
                'deliver_to'                => $cust['name'],
                'delivery_address'          => $cust['address'],
                'ship_via'                  => $branch ? (int) $branch['default_ship_via'] : 1,
                'freight_cost'              => 0,
                'dimension_id'              => (int) ($cust['dimension_id']  ?? 0),
                'dimension2_id'             => (int) ($cust['dimension2_id'] ?? 0),
            ),
            'payment_terms_options' => $payment_terms,
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
        FaTransaction::assert_fiscal_year($date);

        $ref  = FaTransaction::resolve_ref(isset($body['reference']) ? $body['reference'] : null, ST_SALESINVOICE);
        $cart = FaTransaction::build_sales_cart(ST_SALESINVOICE, $body);
        $cart->reference = $ref;

        // Due date
        $cust_row = get_customer_to_order((int) ($body['customer_id'] ?? 0));
        $cart->due_date = isset($body['due_date'])
            ? FaTransaction::to_fa_date($body['due_date'])
            : get_invoice_duedate($cust_row ? $cust_row['payment_terms'] : 0, $cart->document_date);

        $line_out = FaTransaction::add_cart_lines($cart, isset($body['lines']) ? $body['lines'] : array());

        // Cart::write() for a direct invoice:
        //   1. Detects no src_docs → makes parent SO (ref "auto")
        //   2. Reads parent SO and converts to child → delivery (stock out)
        //   3. Calls write_sales_invoice() → debtor_trans + GL
        $invoice_no = $cart->write(1);

        // Resolve auto-created documents from debtor_trans back-links
        $auto_so  = null;
        $auto_dn  = null;
        $inv_row  = FaTransaction::debtor_trans($invoice_no, ST_SALESINVOICE);
        if ($inv_row) {
            $auto_so = (int) $inv_row['order_'];
            $dn_row = db_fetch(db_query(
                "SELECT trans_no FROM " . TB_PREF . "debtor_trans
                 WHERE type=" . ST_CUSTDELIVERY . " AND order_=" . (int) $auto_so . "
                 ORDER BY trans_no DESC LIMIT 1"
            ));
            if ($dn_row) {
                $auto_dn = (int) $dn_row['trans_no'];
            }
        }

        $payment_no = null;
        if (!empty($body['payment']) && is_array($body['payment'])) {
            $payment_no = FaTransaction::settle_invoice_payment(
                $invoice_no,
                (int) ($body['customer_id'] ?? 0),
                (int) ($body['branch_id'] ?? FaTransaction::default_branch_id((int) ($body['customer_id'] ?? 0))),
                $body['payment'],
                $date
            );
            $inv_row = FaTransaction::debtor_trans($invoice_no, ST_SALESINVOICE);
        }

        $total = FaTransaction::invoice_total($inv_row);
        $balance = FaTransaction::invoice_balance($inv_row);

        Response::json(array(
            'invoice_no'  => (int) $invoice_no,
            'trans_type'  => ST_SALESINVOICE,
            'reference'   => $ref,
            'auto_created' => array(
                'sales_order_no' => $auto_so,
                'delivery_no'    => $auto_dn,
            ),
            'total'         => round($total, 2),
            'amount_paid'     => round($total - $balance, 2),
            'balance_due'     => $balance,
            'payment_status'  => $balance <= 0 ? 'paid' : 'pending',
            'payment_no'      => $payment_no,
            'tax'             => 0,
            'gl_posted'       => true,
            'stock_moves'     => FaTransaction::stock_moves($invoice_no, ST_SALESINVOICE),
            'lines'           => $line_out,
        ), 201);
    }

    // ── Consume ──────────────────────────────────────────────────────────

    public static function show(Request $req, $params = array())
    {
        FaTransaction::auth($req);
        FaTransaction::include_sales();

        $invoice_no = (int) ($params['id'] ?? 0);
        $header     = FaTransaction::debtor_trans($invoice_no, ST_SALESINVOICE);
        if (!$header) {
            Response::error('Invoice not found.', 404);
        }

        $detail_res = FaTransaction::debtor_trans_details($invoice_no, ST_SALESINVOICE);
        $lines = array();
        while ($l = db_fetch($detail_res)) {
            $qty   = (float) $l['quantity'];
            $price = (float) $l['unit_price'];
            $disc  = (float) $l['discount_percent'];
            $lines[] = array(
                'stock_id'         => $l['stock_id'],
                'description'      => $l['description'],
                'units'            => $l['units'],
                'quantity'         => $qty,
                'unit_price'       => $price,
                'discount_percent' => $disc * 100,
                'line_total'       => round($qty * $price * (1 - $disc), 2),
            );
        }

        // Tax details
        $tax_res = db_query(
            "SELECT * FROM " . TB_PREF . "trans_tax_details
             WHERE trans_no=" . (int) $invoice_no . " AND trans_type=" . ST_SALESINVOICE
        );
        $taxes = array();
        while ($t = db_fetch($tax_res)) {
            $taxes[] = array(
                'tax_type_name' => $t['tax_type_name'],
                'rate'          => (float) $t['rate'],
                'amount'        => (float) $t['amount'],
            );
        }

        Response::json(array(
            'invoice_no'    => (int) $header['trans_no'],
            'trans_type'    => ST_SALESINVOICE,
            'reference'     => $header['reference'],
            'customer_id'   => (int) $header['debtor_no'],
            'branch_id'     => (int) $header['branch_code'],
            'document_date' => $header['tran_date'],
            'due_date'      => $header['due_date'],
            'amount'        => FaTransaction::invoice_total($header),
            'amount_paid'   => round(FaTransaction::invoice_total($header) - FaTransaction::invoice_balance($header), 2),
            'balance_due'   => FaTransaction::invoice_balance($header),
            'payment_status'=> FaTransaction::invoice_balance($header) <= 0 ? 'paid' : 'pending',
            'tax'           => (float) $header['ov_gst'],
            'freight'       => (float) $header['ov_freight'],
            'alloc'         => (float) $header['alloc'],
            'sales_order'   => (int) $header['order_'],
            'currency'      => $header['curr_code'],
            'lines'         => $lines,
            'taxes'         => $taxes,
            'stock_moves'   => FaTransaction::stock_moves($invoice_no, ST_SALESINVOICE),
        ));
    }
}
