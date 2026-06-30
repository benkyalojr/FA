<?php
/**
 * Purchase Order — prefill / commit / consume
 *
 * Routes:
 *   GET  /api/purchasing/orders/prefill
 *   POST /api/purchasing/orders
 *   GET  /api/purchasing/orders/{id}
 *
 * FA screen : purchasing/po_entry_items.php  (?NewOrder=Yes)
 * Write fn  : add_po()    (purchasing/includes/db/po_db.inc)
 * Stock     : None at PO stage; stock moves happen at GRN receive.
 */
class PurchaseOrderController
{
    // ── Prefill ──────────────────────────────────────────────────────────

    public static function prefill(Request $req, $params = array())
    {
        FaTransaction::auth($req);
        FaTransaction::include_purchasing();
        FaTransaction::include_inventory();

        $supplier_id = (int) $req->q('supplier_id', 0);
        $location    = $req->q('location', null);
        $date        = avogs_date($req);

        if (!$supplier_id) {
            // Default to first active supplier
            $s = db_fetch(db_query(
                "SELECT supplier_id FROM " . TB_PREF . "suppliers WHERE inactive=0 ORDER BY supplier_id LIMIT 1"
            ));
            $supplier_id = $s ? (int) $s['supplier_id'] : 0;
        }

        // Load supplier defaults using FA's own function
        $po = new purch_order();
        get_supplier_details_to_order($po, $supplier_id);

        if (!$location) {
            $loc = db_fetch(db_query(
                "SELECT loc_code FROM " . TB_PREF . "locations WHERE inactive=0 ORDER BY loc_code LIMIT 1"
            ));
            $location = $loc ? $loc['loc_code'] : '';
        }

        // Delivery address defaults to the location's delivery address
        $loc_row = db_fetch(db_query(
            "SELECT location_name, delivery_address FROM " . TB_PREF . "locations WHERE loc_code=" . db_escape($location)
        ));
        $delivery_address = $loc_row ? $loc_row['delivery_address'] : '';

        $catalog = FaTransaction::purchase_catalog($supplier_id, $location, $date);

        Response::json(array(
            'trans_type' => ST_PURCHORDER,
            'defaults' => array(
                'supplier_id'       => $supplier_id,
                'supplier_name'     => $po->supplier_name,
                'currency'          => $po->curr_code,
                'location'          => $location,
                'document_date'     => $date,
                'reference'         => FaTransaction::next_ref(ST_PURCHORDER),
                'delivery_address'  => $delivery_address,
                'tax_included'      => (bool) $po->tax_included,
                'dimension_id'      => (int) ($po->dimension ?? 0),
                'dimension2_id'     => (int) ($po->dimension2 ?? 0),
            ),
            'catalog' => $catalog,
        ));
    }

    // ── Commit ───────────────────────────────────────────────────────────

    public static function create(Request $req, $params = array())
    {
        FaTransaction::auth($req);
        FaTransaction::include_purchasing();

        $body = $req->body;

        $date = isset($body['document_date']) ? $body['document_date'] : date('Y-m-d');
        if (!FaTransaction::valid_date($date)) {
            FaTransaction::validation_error('Invalid document_date.');
        }
        $fa_date = FaTransaction::to_fa_date($date);

        $supplier_id = (int) ($body['supplier_id'] ?? 0);
        if (!$supplier_id) {
            FaTransaction::validation_error('supplier_id is required.', array('supplier_id' => 'Required'));
        }

        $lines = isset($body['lines']) ? $body['lines'] : array();
        if (empty($lines)) {
            FaTransaction::validation_error('At least one line is required.');
        }

        $ref = FaTransaction::resolve_ref(isset($body['reference']) ? $body['reference'] : null, ST_PURCHORDER);

        // Build purch_order object
        $po = new purch_order();
        get_supplier_details_to_order($po, $supplier_id);

        $po->reference        = $ref;
        $po->orig_order_date  = $fa_date;
        $po->Location         = $body['location'] ?? '';
        $po->delivery_address = $body['delivery_address'] ?? '';
        $po->Comments         = $body['comments']         ?? '';
        $po->supp_ref         = $body['supplier_ref']     ?? '';
        $po->dimension        = (int) ($body['dimension_id']  ?? 0);
        $po->dimension2       = (int) ($body['dimension2_id'] ?? 0);
        $po->prep_amount      = (float) ($body['prep_amount'] ?? 0);

        $line_out = array();
        $ln = 0;
        foreach ($lines as $line) {
            $stock_id    = $line['stock_id']                ?? '';
            $qty         = (float) ($line['quantity']        ?? 0);
            $price       = (float) ($line['unit_price']      ?? 0);
            $req_del     = isset($line['required_delivery_date'])
                ? FaTransaction::to_fa_date($line['required_delivery_date'])
                : $fa_date;
            $description = $line['description']             ?? '';

            if (!$stock_id || $qty <= 0) {
                FaTransaction::validation_error('Invalid line.', array(
                    "lines[$ln].stock_id" => $stock_id ? 'ok' : 'required',
                    "lines[$ln].quantity" => $qty > 0  ? 'ok' : 'must be > 0',
                ));
            }

            // Look up description if not provided
            if (!$description) {
                $sm = db_fetch(db_query(
                    "SELECT description FROM " . TB_PREF . "stock_master WHERE stock_id=" . db_escape($stock_id)
                ));
                $description = $sm ? $sm['description'] : $stock_id;
            }

            $po->add_to_order($ln, $stock_id, $qty, $description, $price, '', $req_del, 0, 0);
            $line_out[] = array(
                'stock_id'               => $stock_id,
                'description'            => $description,
                'quantity'               => $qty,
                'unit_price'             => $price,
                'required_delivery_date' => $req_del,
                'line_total'             => round($qty * $price, 2),
            );
            $ln++;
        }

        $order_no = add_po($po);

        Response::json(array(
            'order_no'   => (int) $order_no,
            'trans_type' => ST_PURCHORDER,
            'reference'  => $ref,
            'supplier_id'=> $supplier_id,
            'total'      => round($po->get_trans_total(), 2),
            'lines'      => $line_out,
        ), 201);
    }

    // ── Consume ──────────────────────────────────────────────────────────

    public static function show(Request $req, $params = array())
    {
        FaTransaction::auth($req);
        FaTransaction::include_purchasing();

        $order_no = (int) ($params['id'] ?? 0);

        $po = new purch_order();
        read_po($order_no, $po);

        if (!$po->order_no) {
            Response::error('Purchase order not found.', 404);
        }

        $lines = array();
        foreach ($po->line_items as $item) {
            $lines[] = array(
                'stock_id'               => $item->stock_id,
                'description'            => $item->item_description,
                'quantity_ordered'       => (float) $item->quantity,
                'qty_received'           => (float) $item->qty_received,
                'qty_invoiced'           => (float) $item->qty_inv,
                'unit_price'             => (float) $item->price,
                'required_delivery_date' => $item->req_del_date,
                'line_total'             => round((float) $item->quantity * (float) $item->price, 2),
            );
        }

        Response::json(array(
            'order_no'         => (int) $po->order_no,
            'trans_type'       => ST_PURCHORDER,
            'reference'        => $po->reference,
            'supplier_id'      => (int) $po->supplier_id,
            'supplier_name'    => $po->supplier_name,
            'currency'         => $po->curr_code,
            'document_date'    => $po->orig_order_date,
            'location'         => $po->Location,
            'delivery_address' => $po->delivery_address,
            'comments'         => $po->Comments,
            'total'            => round($po->get_trans_total(), 2),
            'lines'            => $lines,
        ));
    }
}
