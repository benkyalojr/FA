<?php
/**
 * Direct Supplier Invoice — prefill / commit / consume
 *
 * Routes:
 *   GET  /api/purchasing/invoices/prefill
 *   POST /api/purchasing/invoices
 *   GET  /api/purchasing/invoices/{id}
 *
 * FA screen : purchasing/po_entry_items.php  (?NewInvoice=Yes)
 * Write fn  : add_direct_supp_trans()  (purchasing/includes/purchasing_db.inc)
 * Stock     : Yes — auto GRN receive + AP invoice in one transaction
 */
class SupplierInvoiceController
{
    private static function due_date_from_terms(purch_order $po, $document_date)
    {
        if (!empty($po->terms['day_in_following_month'])) {
            return add_days(end_month($document_date), $po->terms['day_in_following_month']);
        }
        return add_days($document_date, $po->terms['days_before_due']);
    }

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
            $s = db_fetch(db_query(
                "SELECT supplier_id FROM " . TB_PREF . "suppliers WHERE inactive=0 ORDER BY supplier_id LIMIT 1"
            ));
            $supplier_id = $s ? (int) $s['supplier_id'] : 0;
        }

        $po = new purch_order();
        $po->trans_type = ST_SUPPINVOICE;
        get_supplier_details_to_order($po, $supplier_id);

        if (!$location) {
            $loc = db_fetch(db_query(
                "SELECT loc_code FROM " . TB_PREF . "locations WHERE inactive=0 ORDER BY loc_code LIMIT 1"
            ));
            $location = $loc ? $loc['loc_code'] : '';
        }

        $fa_date = FaTransaction::to_fa_date($date);
        $po->orig_order_date = $fa_date;
        $po->due_date = self::due_date_from_terms($po, $fa_date);

        $loc_row = db_fetch(db_query(
            "SELECT location_name, delivery_address FROM " . TB_PREF . "locations WHERE loc_code=" . db_escape($location)
        ));

        Response::json(array(
            'trans_type' => ST_SUPPINVOICE,
            'defaults' => array(
                'supplier_id'      => $supplier_id,
                'supplier_name'    => $po->supplier_name,
                'currency'         => $po->curr_code,
                'location'         => $location,
                'document_date'    => $date,
                'due_date'         => FaTransaction::to_iso_date($po->due_date),
                'reference'        => FaTransaction::next_ref(ST_SUPPINVOICE),
                'delivery_address' => $loc_row ? $loc_row['delivery_address'] : '',
                'tax_included'     => (bool) $po->tax_included,
                'dimension_id'     => (int) ($po->dimension ?? 0),
                'dimension2_id'    => (int) ($po->dimension2 ?? 0),
            ),
            'catalog' => FaTransaction::purchase_catalog($supplier_id, $location, $date),
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
        FaTransaction::assert_fiscal_year($date);
        $fa_date = FaTransaction::to_fa_date($date);

        $supplier_id = (int) ($body['supplier_id'] ?? 0);
        if (!$supplier_id) {
            FaTransaction::validation_error('supplier_id is required.', array('supplier_id' => 'Required'));
        }

        $supplier_ref = trim((string) ($body['supplier_ref'] ?? ''));
        if ($supplier_ref === '') {
            FaTransaction::validation_error(
                "supplier_ref is required (the supplier's own invoice number).",
                array('supplier_ref' => 'Required')
            );
        }

        if (is_reference_already_there($supplier_id, $supplier_ref)) {
            FaTransaction::validation_error(
                'This supplier invoice reference has already been entered.',
                array('supplier_ref' => 'Duplicate for this supplier')
            );
        }

        $location = trim((string) ($body['location'] ?? ''));
        if ($location === '') {
            FaTransaction::validation_error('location is required.', array('location' => 'Required'));
        }

        $lines = isset($body['lines']) ? $body['lines'] : array();
        if (empty($lines)) {
            FaTransaction::validation_error('At least one line is required.');
        }

        $ref = FaTransaction::resolve_ref(isset($body['reference']) ? $body['reference'] : null, ST_SUPPINVOICE);

        $po = new purch_order();
        $po->trans_type = ST_SUPPINVOICE;
        $po->order_no = 0;
        get_supplier_details_to_order($po, $supplier_id);

        if (!db_has_currency_rates($po->curr_code, $fa_date, true)) {
            FaTransaction::validation_error(
                'No exchange rate for supplier currency on document_date.',
                array('document_date' => 'Missing exchange rate for ' . $po->curr_code)
            );
        }

        $po->reference        = $ref;
        $po->orig_order_date  = $fa_date;
        $po->due_date         = isset($body['due_date'])
            ? FaTransaction::to_fa_date($body['due_date'])
            : self::due_date_from_terms($po, $fa_date);
        $po->Location         = $location;
        $po->delivery_address = $body['delivery_address'] ?? '';
        $po->Comments         = $body['comments'] ?? '';
        $po->supp_ref         = $supplier_ref;
        $po->dimension        = (int) ($body['dimension_id'] ?? 0);
        $po->dimension2       = (int) ($body['dimension2_id'] ?? 0);
        $po->prep_amount      = (float) ($body['prep_amount'] ?? 0);

        $line_out = array();
        $ln = 0;
        foreach ($lines as $line) {
            $stock_id    = $line['stock_id'] ?? '';
            $qty         = (float) ($line['quantity'] ?? 0);
            $price       = (float) ($line['unit_price'] ?? 0);
            $description = $line['description'] ?? '';

            if (!$stock_id || $qty <= 0) {
                FaTransaction::validation_error('Invalid line.', array(
                    "lines[$ln].stock_id"  => $stock_id ? 'ok' : 'required',
                    "lines[$ln].quantity"  => $qty > 0 ? 'ok' : 'must be > 0',
                ));
            }

            if (!$description) {
                $sm = db_fetch(db_query(
                    "SELECT description FROM " . TB_PREF . "stock_master WHERE stock_id=" . db_escape($stock_id)
                ));
                $description = $sm ? $sm['description'] : $stock_id;
            }

            if ($price <= 0) {
                $price = (float) get_purchase_price($supplier_id, $stock_id);
            }

            $po->add_to_order($ln, $stock_id, $qty, $description, $price, '', $fa_date, 0, 0);
            $line_out[] = array(
                'stock_id'    => $stock_id,
                'description' => $description,
                'quantity'    => $qty,
                'unit_price'  => $price,
                'line_total'  => round($qty * $price, 2),
            );
            $ln++;
        }

        $invoice_no = add_direct_supp_trans($po);
        if (!$invoice_no) {
            Response::error('Failed to create supplier invoice.', 500);
        }

        $header = db_fetch(db_query(
            "SELECT ov_amount, ov_gst, ov_discount FROM " . TB_PREF . "supp_trans
             WHERE trans_no=" . (int) $invoice_no . " AND type=" . ST_SUPPINVOICE
        ));

        Response::json(array(
            'invoice_no'   => (int) $invoice_no,
            'trans_type'   => ST_SUPPINVOICE,
            'reference'    => $ref,
            'supplier_ref' => $supplier_ref,
            'supplier_id'  => $supplier_id,
            'document_date'=> $date,
            'due_date'     => FaTransaction::to_iso_date($po->due_date),
            'location'     => $location,
            'total'        => $header
                ? round((float) $header['ov_amount'] + (float) $header['ov_gst'], 2)
                : round($po->get_trans_total(), 2),
            'tax'          => $header ? (float) $header['ov_gst'] : 0,
            'gl_posted'    => true,
            'lines'        => $line_out,
        ), 201);
    }

    // ── Consume ──────────────────────────────────────────────────────────

    public static function show(Request $req, $params = array())
    {
        FaTransaction::auth($req);
        FaTransaction::include_purchasing();

        $invoice_no = (int) ($params['id'] ?? 0);
        $row = db_fetch(db_query(
            "SELECT t.*, s.supp_name
             FROM " . TB_PREF . "supp_trans t
             JOIN " . TB_PREF . "suppliers s ON s.supplier_id = t.supplier_id
             WHERE t.trans_no=" . (int) $invoice_no . " AND t.type=" . ST_SUPPINVOICE
        ));

        if (!$row) {
            Response::error('Supplier invoice not found.', 404);
        }

        $lines = array();
        $res = db_query(
            "SELECT i.stock_id, i.description, i.quantity, i.unit_price, i.unit_tax
             FROM " . TB_PREF . "supp_invoice_items i
             WHERE i.supp_trans_type=" . ST_SUPPINVOICE . "
               AND i.supp_trans_no=" . (int) $invoice_no . "
               AND i.stock_id != ''"
        );
        while ($line = db_fetch($res)) {
            $qty = (float) $line['quantity'];
            $price = (float) $line['unit_price'];
            $lines[] = array(
                'stock_id'    => $line['stock_id'],
                'description' => $line['description'],
                'quantity'    => $qty,
                'unit_price'  => $price,
                'line_total'  => round($qty * $price, 2),
            );
        }

        Response::json(array(
            'invoice_no'   => (int) $invoice_no,
            'trans_type'   => ST_SUPPINVOICE,
            'reference'    => $row['reference'],
            'supplier_ref' => $row['supp_reference'],
            'supplier_id'  => (int) $row['supplier_id'],
            'supplier_name'=> $row['supp_name'],
            'currency'     => $row['curr_code'],
            'document_date'=> FaTransaction::to_iso_date(sql2date($row['tran_date'])),
            'due_date'     => FaTransaction::to_iso_date(sql2date($row['due_date'])),
            'amount'       => (float) $row['ov_amount'],
            'tax'          => (float) $row['ov_gst'],
            'alloc'        => (float) $row['alloc'],
            'lines'        => $lines,
        ));
    }
}
