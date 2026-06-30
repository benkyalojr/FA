<?php
/**
 * Location Transfer — prefill / commit / consume
 *
 * Routes:
 *   GET  /api/inventory/transfers/prefill
 *   POST /api/inventory/transfers
 *   GET  /api/inventory/transfers/{id}
 *
 * FA screen : inventory/transfers.php  (?NewTransfer=1)
 * Write fn  : add_stock_transfer()    (inventory/includes/db/items_transfer_db.inc)
 * Stock     : Two stock_moves per line (−source, +destination). No GL.
 */
class TransferController
{
    // ── Prefill ──────────────────────────────────────────────────────────

    public static function prefill(Request $req, $params = array())
    {
        FaTransaction::auth($req);
        FaTransaction::include_inventory();

        $from_location = $req->q('from_location', null);
        $to_location   = $req->q('to_location', null);
        $date          = avogs_date($req);

        // Load all active locations
        $loc_res = db_query(
            "SELECT loc_code, location_name FROM " . TB_PREF . "locations WHERE inactive=0 ORDER BY loc_code"
        );
        $locations = array();
        $first_loc = null;
        while ($r = db_fetch($loc_res)) {
            if ($first_loc === null) $first_loc = $r['loc_code'];
            $locations[] = array('code' => $r['loc_code'], 'name' => $r['location_name']);
        }
        if (!$from_location) $from_location = $first_loc ?? '';
        if (!$to_location)   $to_location   = $first_loc ?? '';

        // Catalog with QOH at the source location
        $res = db_query(
            "SELECT s.stock_id, s.description, s.units, s.mb_flag
             FROM " . TB_PREF . "stock_master s
             WHERE s.inactive = 0 AND s.mb_flag != 'D'
             ORDER BY s.stock_id"
        );
        $catalog = array();
        while ($r = db_fetch($res)) {
            $catalog[] = array(
                'stock_id'      => $r['stock_id'],
                'description'   => $r['description'],
                'units'         => $r['units'],
                'qoh_at_source' => FaTransaction::qoh($r['stock_id'], $from_location, $date),
            );
        }

        Response::json(array(
            'trans_type' => ST_LOCTRANSFER,
            'defaults' => array(
                'from_location' => $from_location,
                'to_location'   => $to_location,
                'document_date' => $date,
                'reference'     => FaTransaction::next_ref(ST_LOCTRANSFER),
            ),
            'locations' => $locations,
            'catalog'   => $catalog,
        ));
    }

    // ── Commit ───────────────────────────────────────────────────────────

    public static function create(Request $req, $params = array())
    {
        FaTransaction::auth($req);
        FaTransaction::include_inventory();

        $body = $req->body;

        $from_location = $body['from_location'] ?? '';
        $to_location   = $body['to_location']   ?? '';
        $date          = $body['document_date'] ?? date('Y-m-d');
        $memo          = $body['memo']          ?? '';

        if (!$from_location || !$to_location) {
            FaTransaction::validation_error('from_location and to_location are required.');
        }
        if ($from_location === $to_location) {
            FaTransaction::validation_error('from_location and to_location must be different.', array(
                'to_location' => 'Must differ from from_location',
            ));
        }
        if (!FaTransaction::valid_date($date)) {
            FaTransaction::validation_error('Invalid document_date.');
        }
        $fa_date = FaTransaction::to_fa_date($date);

        $lines = isset($body['lines']) ? $body['lines'] : array();
        if (empty($lines)) {
            FaTransaction::validation_error('At least one line is required.');
        }

        $ref = FaTransaction::resolve_ref(isset($body['reference']) ? $body['reference'] : null, ST_LOCTRANSFER);

        // Build the items array expected by add_stock_transfer()
        $items = array();
        $line_out = array();
        $ln = 0;
        foreach ($lines as $line) {
            $stock_id = $line['stock_id'] ?? '';
            $qty      = (float) ($line['quantity'] ?? 0);

            if (!$stock_id || $qty <= 0) {
                FaTransaction::validation_error('Invalid line.', array(
                    "lines[$ln].stock_id" => $stock_id ? 'ok' : 'required',
                    "lines[$ln].quantity" => $qty > 0  ? 'ok' : 'must be > 0',
                ));
            }

            $obj           = new stdClass();
            $obj->stock_id = $stock_id;
            $obj->quantity = $qty;
            $items[] = $obj;

            $line_out[] = array(
                'stock_id' => $stock_id,
                'quantity' => $qty,
            );
            $ln++;
        }

        $transfer_no = add_stock_transfer($items, $from_location, $to_location, $fa_date, $ref, $memo);

        Response::json(array(
            'transfer_no'   => (int) $transfer_no,
            'trans_type'    => ST_LOCTRANSFER,
            'reference'     => $ref,
            'from_location' => $from_location,
            'to_location'   => $to_location,
            'document_date' => $date,
            'stock_moves'   => FaTransaction::stock_moves($transfer_no, ST_LOCTRANSFER),
            'lines'         => $line_out,
        ), 201);
    }

    // ── Consume ──────────────────────────────────────────────────────────

    public static function show(Request $req, $params = array())
    {
        FaTransaction::auth($req);
        FaTransaction::include_inventory();

        $transfer_no = (int) ($params['id'] ?? 0);

        // Header info lives in stock_moves (paired rows per line)
        $moves = FaTransaction::stock_moves($transfer_no, ST_LOCTRANSFER);
        if (empty($moves)) {
            Response::error('Transfer not found.', 404);
        }

        // Reference from refs table
        $ref_row = db_fetch(db_query(
            "SELECT reference FROM " . TB_PREF . "refs
             WHERE id=" . (int) $transfer_no . " AND type=" . ST_LOCTRANSFER
        ));
        $reference = $ref_row ? $ref_row['reference'] : '';

        // Date from first move
        $first_move = db_fetch(db_query(
            "SELECT tran_date, loc_code FROM " . TB_PREF . "stock_moves
             WHERE trans_no=" . (int) $transfer_no . " AND type=" . ST_LOCTRANSFER . " AND qty<0 LIMIT 1"
        ));
        $document_date  = $first_move ? $first_move['tran_date'] : '';
        $from_location  = $first_move ? $first_move['loc_code']  : '';

        // Consolidate paired moves into lines
        $lines = array();
        $negatives = array_filter($moves, function ($m) { return $m['qty'] < 0; });
        foreach ($negatives as $m) {
            $lines[] = array(
                'stock_id'      => $m['stock_id'],
                'from_location' => $m['location'],
                'quantity'      => abs($m['qty']),
            );
        }

        // Destination location from a positive move
        $pos_move = db_fetch(db_query(
            "SELECT loc_code FROM " . TB_PREF . "stock_moves
             WHERE trans_no=" . (int) $transfer_no . " AND type=" . ST_LOCTRANSFER . " AND qty>0 LIMIT 1"
        ));
        $to_location = $pos_move ? $pos_move['loc_code'] : '';

        Response::json(array(
            'transfer_no'   => (int) $transfer_no,
            'trans_type'    => ST_LOCTRANSFER,
            'reference'     => $reference,
            'from_location' => $from_location,
            'to_location'   => $to_location,
            'document_date' => $document_date,
            'lines'         => $lines,
            'stock_moves'   => $moves,
        ));
    }
}
