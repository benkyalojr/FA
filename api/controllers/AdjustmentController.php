<?php
/**
 * Inventory Adjustment — prefill / commit / consume
 *
 * Routes:
 *   GET  /api/inventory/adjustments/prefill
 *   POST /api/inventory/adjustments
 *   GET  /api/inventory/adjustments/{id}
 *
 * FA screen : inventory/adjustments.php  (?NewAdjustment=1)
 * Write fn  : add_stock_adjustment()    (inventory/includes/db/items_adjust_db.inc)
 * Stock     : Signed stock_moves (+ increase, − decrease) + GL via std cost.
 *
 * Quantity sign convention:
 *   > 0  stock increase
 *   < 0  stock decrease (QOH check applies when negative stock disallowed)
 *   = 0  rejected
 *
 * Note: this controller also powers the wastage endpoint (Phase 3):
 *   POST /api/wastage  →  negative add_stock_adjustment()
 */
class AdjustmentController
{
    // ── Prefill ──────────────────────────────────────────────────────────

    public static function prefill(Request $req, $params = array())
    {
        FaTransaction::auth($req);
        FaTransaction::include_inventory();

        $location = $req->q('location', null);
        $date     = avogs_date($req);

        if (!$location) {
            $loc = db_fetch(db_query(
                "SELECT loc_code FROM " . TB_PREF . "locations WHERE inactive=0 ORDER BY loc_code LIMIT 1"
            ));
            $location = $loc ? $loc['loc_code'] : '';
        }

        $res = db_query(
            "SELECT s.stock_id, s.description, s.units, s.mb_flag, s.material_cost
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
                'qoh'           => FaTransaction::qoh($r['stock_id'], $location, $date),
                'material_cost' => (float) $r['material_cost'],
            );
        }

        Response::json(array(
            'trans_type' => ST_INVADJUST,
            'defaults' => array(
                'location'      => $location,
                'document_date' => $date,
                'reference'     => FaTransaction::next_ref(ST_INVADJUST),
            ),
            'catalog' => $catalog,
        ));
    }

    // ── Commit ───────────────────────────────────────────────────────────

    public static function create(Request $req, $params = array())
    {
        FaTransaction::auth($req);
        FaTransaction::include_inventory();

        $body = $req->body;

        $location = $body['location'] ?? '';
        $date     = $body['document_date'] ?? date('Y-m-d');
        $memo     = $body['memo'] ?? '';

        if (!$location) {
            FaTransaction::validation_error('location is required.');
        }
        if (!FaTransaction::valid_date($date)) {
            FaTransaction::validation_error('Invalid document_date.');
        }
        $fa_date = FaTransaction::to_fa_date($date);

        $lines = isset($body['lines']) ? $body['lines'] : array();
        if (empty($lines)) {
            FaTransaction::validation_error('At least one line is required.');
        }

        $ref = FaTransaction::resolve_ref(isset($body['reference']) ? $body['reference'] : null, ST_INVADJUST);

        // Build items array expected by add_stock_adjustment()
        $items    = array();
        $line_out = array();
        $ln       = 0;

        foreach ($lines as $line) {
            $stock_id  = $line['stock_id'] ?? '';
            $qty       = (float) ($line['quantity']      ?? 0);
            $std_cost  = (float) ($line['standard_cost'] ?? 0);

            if (!$stock_id || $qty == 0) {
                FaTransaction::validation_error('Invalid line.', array(
                    "lines[$ln].stock_id" => $stock_id ? 'ok' : 'required',
                    "lines[$ln].quantity" => ($qty != 0) ? 'ok' : 'must not be zero',
                ));
            }

            // Default standard cost to material_cost when not supplied
            if ($std_cost == 0) {
                $sm = db_fetch(db_query(
                    "SELECT material_cost FROM " . TB_PREF . "stock_master WHERE stock_id=" . db_escape($stock_id)
                ));
                if ($sm) $std_cost = (float) $sm['material_cost'];
            }

            $obj                = new stdClass();
            $obj->stock_id      = $stock_id;
            $obj->quantity      = $qty;
            $obj->standard_cost = $std_cost;
            $items[] = $obj;

            $line_out[] = array(
                'stock_id'      => $stock_id,
                'quantity'      => $qty,
                'standard_cost' => $std_cost,
            );
            $ln++;
        }

        $adj_no = add_stock_adjustment($items, $location, $fa_date, $ref, $memo);

        Response::json(array(
            'adjustment_no' => (int) $adj_no,
            'trans_type'    => ST_INVADJUST,
            'reference'     => $ref,
            'location'      => $location,
            'document_date' => $date,
            'gl_posted'     => true,
            'stock_moves'   => FaTransaction::stock_moves($adj_no, ST_INVADJUST),
            'lines'         => $line_out,
        ), 201);
    }

    // ── Consume ──────────────────────────────────────────────────────────

    public static function show(Request $req, $params = array())
    {
        FaTransaction::auth($req);
        FaTransaction::include_inventory();

        $adj_no = (int) ($params['id'] ?? 0);

        $moves = FaTransaction::stock_moves($adj_no, ST_INVADJUST);
        if (empty($moves)) {
            Response::error('Adjustment not found.', 404);
        }

        $ref_row = db_fetch(db_query(
            "SELECT reference FROM " . TB_PREF . "refs
             WHERE id=" . (int) $adj_no . " AND type=" . ST_INVADJUST
        ));
        $reference = $ref_row ? $ref_row['reference'] : '';

        $first = db_fetch(db_query(
            "SELECT tran_date, loc_code FROM " . TB_PREF . "stock_moves
             WHERE trans_no=" . (int) $adj_no . " AND type=" . ST_INVADJUST . " LIMIT 1"
        ));
        $document_date = $first ? $first['tran_date'] : '';
        $location      = $first ? $first['loc_code']  : '';

        // GL summary (inventory account ↔ adjustment account)
        $gl_res = db_query(
            "SELECT g.account, a.account_name, SUM(g.amount) AS total
             FROM " . TB_PREF . "gl_trans g
             JOIN " . TB_PREF . "chart_master a ON a.account_code = g.account
             WHERE g.type_no=" . (int) $adj_no . " AND g.type=" . ST_INVADJUST . "
             GROUP BY g.account, a.account_name"
        );
        $gl = array();
        while ($r = db_fetch($gl_res)) {
            $gl[] = array(
                'account'      => $r['account'],
                'account_name' => $r['account_name'],
                'amount'       => (float) $r['total'],
            );
        }

        Response::json(array(
            'adjustment_no' => (int) $adj_no,
            'trans_type'    => ST_INVADJUST,
            'reference'     => $reference,
            'location'      => $location,
            'document_date' => $document_date,
            'stock_moves'   => $moves,
            'gl_summary'    => $gl,
        ));
    }
}
