<?php
/**
 * Shift check-in (open shop) and check-out (close shop).
 *
 *   GET  /shifts/checkin/prefill    — stock list + expected cash + photo slots
 *   POST /shifts/checkin              — save full opening checklist (Open Shop)
 *   GET  /shifts/{id}/checkin         — read back one check-in
 *   GET  /shifts/checkout/prefill     — close wizard prefill (requires open shift)
 *   POST /shifts/checkout             — save full closing checklist (Close Shop)
 *   POST /shifts/{id}/close           — alias of checkout (legacy)
 *   GET  /shifts/{id}/checkout        — read back one check-out
 *   POST /shifts/open                 — alias of checkin (legacy)
 */
class ShiftController
{
    public static function checklist(Request $req, $params)
    {
        Auth::requireUser($req);
        $tmpl = avogs_checklist_template($params['mode']);
        if (!$tmpl) {
            Response::error('Unknown checklist mode: ' . $params['mode'], 404);
        }
        $tmpl['mode'] = $params['mode'];
        Response::json($tmpl);
    }

    public static function current(Request $req)
    {
        $auth = Auth::requireUser($req);
        $store = avogs_store($req, $auth);
        $open = self::open_shift_row($store);

        $shiftKey = self::default_shift_key($open);
        $expected = self::expectedFor($store, $shiftKey);

        Response::json(array(
            'active' => $open ? true : false,
            'shift_id' => $open ? (int) $open['id'] : null,
            'shift' => $shiftKey,
            'store' => $store,
            'opened_at' => $open ? str_replace(' ', 'T', $open['opened_at']) : null,
            'expected' => $expected,
        ));
    }

    /** Prefill data for the Open Shop / check-in wizard. */
    public static function checkinPrefill(Request $req)
    {
        $auth = Auth::requireUser($req);
        FaTransaction::include_inventory();
        $store = avogs_store($req, $auth);
        $location = $req->q('location', $store);
        $date = avogs_date($req);
        $shift = $req->q('shift', self::default_shift_key(null));

        if (self::open_shift_row($store)) {
            Response::error('A shift is already open for this store. Close it before a new check-in.', 409);
        }

        $cash = self::expectedCash($store, $shift);
        $mode = ($shift === 'evening') ? 'evening-open' : 'morning-open';
        $tmpl = avogs_checklist_template($mode);

        Response::json(array(
            'store' => $store,
            'location' => $location,
            'date' => $date,
            'shift' => $shift,
            'checklist_mode' => $mode,
            'checklist' => $tmpl,
            'cash' => array(
                'expected_till' => $cash['till'],
                'expected_float' => $cash['float'],
            ),
            'stock_items' => self::stock_items_for_checkin($location, $date),
            'photo_slots' => avogs_checkin_photo_slots(),
            'comment_fields' => array(
                array(
                    'key' => 'calls_deliveries',
                    'label' => 'Making calls and deliveries',
                    'placeholder' => 'Note the calls made and deliveries planned',
                ),
                array(
                    'key' => 'pending_orders',
                    'label' => 'Checking pending orders',
                    'placeholder' => 'Notes on pending orders',
                ),
            ),
        ));
    }

    /** Submit full Open Shop checklist (single save at end of wizard). */
    public static function checkin(Request $req)
    {
        self::saveCheckin($req);
    }

    /** Legacy alias. */
    public static function open(Request $req)
    {
        self::saveCheckin($req);
    }

    public static function checkinShow(Request $req, $params)
    {
        Auth::requireUser($req);
        $shiftId = (int) (isset($params['id']) ? $params['id'] : 0);
        $row = self::shift_payload($shiftId);
        if (!$row) {
            Response::error('Check-in not found.', 404);
        }
        Response::json($row);
    }

    /** Prefill data for the Close Shop / check-out wizard. */
    public static function checkoutPrefill(Request $req)
    {
        Auth::requireUser($req);
        FaTransaction::include_inventory();

        $auth = Auth::requireUser($req);
        $store = avogs_store($req, $auth);
        $location = $req->q('location', $store);
        $date = avogs_date($req);

        $open = self::open_shift_row($store);
        if (!$open) {
            Response::error('No shift is open for this store. Open shop before check-out.', 409);
        }

        $shift = $open['shift_key'];
        $nextShift = ($shift === 'morning') ? 'evening' : 'morning';
        $mode = ($shift === 'evening') ? 'evening-close' : 'morning-close';
        $tmpl = avogs_checklist_template($mode);
        $sales = self::shift_sales_summary($store, $shift, $open['opened_at'], $date);
        $handoverHint = self::handover_hints($store, $nextShift, $location, $date, $open);

        Response::json(array(
            'shift_id' => (int) $open['id'],
            'store' => $store,
            'location' => $location,
            'date' => $date,
            'shift' => $shift,
            'next_shift' => $nextShift,
            'checklist_mode' => $mode,
            'checklist' => $tmpl,
            'opened_at' => $open['opened_at'] ? str_replace(' ', 'T', $open['opened_at']) : null,
            'opening' => array(
                'cash' => array(
                    'till' => (int) $open['opening_till'],
                    'float' => (int) $open['opening_float'],
                ),
                'stock_total' => (int) $open['opening_stock'],
            ),
            'sales' => $sales,
            'stock_items' => self::stock_items_for_checkin($location, $date),
            'handover' => $handoverHint,
            'photo_slots' => avogs_checkout_photo_slots(),
            'comment_fields' => array(
                array(
                    'key' => 'wastage',
                    'label' => 'Wastage notes',
                    'placeholder' => 'e.g. 5 overripe avocados discarded',
                ),
                array(
                    'key' => 'closing_notes',
                    'label' => 'Closing notes',
                    'placeholder' => 'Any notes before handover',
                ),
            ),
        ));
    }

    public static function checkoutShow(Request $req, $params)
    {
        Auth::requireUser($req);
        $shiftId = (int) (isset($params['id']) ? $params['id'] : 0);
        $row = self::checkout_payload($shiftId);
        if (!$row) {
            Response::error('Check-out not found.', 404);
        }
        Response::json($row);
    }

    /**
     * Submit full Close Shop checklist (single save at end of wizard).
     * Resolves shift_id from body or the open shift for the store.
     */
    public static function checkout(Request $req)
    {
        $auth = Auth::requireUser($req);
        $store = avogs_store($req, $auth);
        $shiftId = (int) $req->input('shift_id', 0);

        if ($shiftId <= 0) {
            $open = self::open_shift_row($store);
            if (!$open) {
                Response::error('No shift is open for this store.', 409);
            }
            $shiftId = (int) $open['id'];
        }

        self::saveCheckout($req, $shiftId, $auth);
    }

    public static function close(Request $req, $params)
    {
        $auth = Auth::requireUser($req);
        $shiftId = (int) $params['id'];
        self::saveCheckout($req, $shiftId, $auth);
    }

    private static function saveCheckout(Request $req, $shiftId, $auth)
    {
        $shift = Db::row("SELECT * FROM " . Db::t('shifts') . " WHERE id = " . $shiftId);
        if (!$shift) {
            Response::error('Shift not found.', 404);
        }
        if ($shift['status'] === 'closed') {
            Response::error('Shift is already closed.', 409);
        }

        $cashCounted = (int) $req->input('cash_counted', 0);
        $cashIn = $req->input('cash', array());
        if ($cashCounted <= 0 && is_array($cashIn) && isset($cashIn['counted'])) {
            $cashCounted = (int) $cashIn['counted'];
        }

        $handoverIn = $req->input('handover', array());
        $g = function ($k) use ($handoverIn) { return isset($handoverIn[$k]) ? (int) $handoverIn[$k] : 0; };

        $comments = $req->input('comments', array());
        $notes = $req->input('notes', array());
        if (!is_array($notes)) {
            $notes = array();
        }
        if (!empty($comments['wastage'])) {
            $notes['wastage'] = $comments['wastage'];
        }
        if (!empty($comments['closing_notes'])) {
            $notes['closing_notes'] = $comments['closing_notes'];
        }

        $newClosePhotos = avogs_normalize_checkout_photos(
            $req->input('photos', array()),
            $req->input('photo_ids', null)
        );
        list($photoDetails, $photoMissing) = avogs_resolve_checkout_photos($newClosePhotos);

        $nextShift = ($shift['shift_key'] === 'morning') ? 'evening' : 'morning';
        $handoverSnapshot = array(
            'next_shift' => $nextShift,
            'avo' => $g('avo'),
            'till' => $g('till'),
            'float' => $g('float'),
            'juice' => $g('juice'),
            'smoothie' => $g('smoothie'),
            'ginger' => $g('ginger'),
            'h250' => $g('h250'),
            'h450' => $g('h450'),
            'h900' => $g('h900'),
        );
        $notes['handover_snapshot'] = $handoverSnapshot;
        if (!empty($newClosePhotos)) {
            $notes['close_photos'] = $newClosePhotos;
        }

        $now = date('Y-m-d H:i:s');
        Db::exec("UPDATE " . Db::t('shifts') . " SET status = 'closed', closed_at = " . Db::esc($now) . ", closed_by = " . Db::esc($auth['user_id']) . ",
            cash_counted = " . $cashCounted . ", notes = " . Db::json($notes) . ", photo_ids = " . Db::json(array_values($newClosePhotos)) . "
            WHERE id = " . $shiftId);
        $store = $shift['store_code'];
        Db::exec("INSERT INTO " . Db::t('handover') . "
            (store_code, shift_key, avo, till, flt, juice, smoothie, ginger, h250, h450, h900, updated_at)
            VALUES (" . Db::esc($store) . ", " . Db::esc($nextShift) . ", "
            . $g('avo') . ", " . $g('till') . ", " . $g('float') . ", " . $g('juice') . ", " . $g('smoothie') . ", " . $g('ginger') . ", "
            . $g('h250') . ", " . $g('h450') . ", " . $g('h900') . ", " . Db::esc($now) . ")
            ON DUPLICATE KEY UPDATE avo=VALUES(avo), till=VALUES(till), flt=VALUES(flt), juice=VALUES(juice),
            smoothie=VALUES(smoothie), ginger=VALUES(ginger), h250=VALUES(h250), h450=VALUES(h450), h900=VALUES(h900), updated_at=VALUES(updated_at)");

        $warnings = array();
        if ($photoMissing) {
            $warnings[] = count($photoMissing) . ' photo upload_id(s) not found.';
        }

        Response::json(array(
            'success' => true,
            'message' => 'Shop closed successfully.',
            'shift_id' => $shiftId,
            'status' => 'closed',
            'store' => $store,
            'shift' => $shift['shift_key'],
            'next_shift' => $nextShift,
            'closed_at' => str_replace(' ', 'T', $now),
            'cash_counted' => $cashCounted,
            'handover' => $handoverSnapshot,
            'photos' => $photoDetails,
            'photos_map' => $newClosePhotos,
            'photo_count' => count($photoDetails),
            'photo_missing' => $photoMissing,
            'notes' => $notes,
            'warnings' => $warnings,
        ));
    }

    private static function shift_sales_summary($store, $shiftKey, $openedAt, $date)
    {
        $openedSql = Db::escRaw($openedAt);
        $shiftTotal = (int) Db::val("SELECT COALESCE(SUM(total),0) FROM " . Db::t('sales') . "
            WHERE store_code = " . Db::esc($store) . "
              AND shift_key = " . Db::esc($shiftKey) . "
              AND trans_date >= " . $openedSql);
        $shiftCount = (int) Db::val("SELECT COUNT(*) FROM " . Db::t('sales') . "
            WHERE store_code = " . Db::esc($store) . "
              AND shift_key = " . Db::esc($shiftKey) . "
              AND trans_date >= " . $openedSql);

        $sqlDate = $date;
        if (!defined('ST_SALESINVOICE')) {
            global $path_to_root;
            if (!isset($path_to_root)) {
                $path_to_root = dirname(__DIR__, 2);
            }
            include_once $path_to_root . '/includes/types.inc';
        }
        $faRow = db_fetch(db_query(
            "SELECT COUNT(*) AS c, COALESCE(SUM(ov_amount + ov_gst + ov_freight), 0) AS amt
             FROM " . TB_PREF . "debtor_trans
             WHERE type = " . ST_SALESINVOICE . "
               AND tran_date = " . db_escape($sqlDate),
            'fa day sales'
        ));
        $dayTotal = $faRow ? (float) $faRow['amt'] : 0.0;
        $dayCount = $faRow ? (int) $faRow['c'] : 0;

        return array(
            'shift_total' => $shiftTotal,
            'shift_invoice_count' => $shiftCount,
            'day_total' => $dayTotal,
            'day_invoice_count' => $dayCount,
            'currency' => get_company_currency(),
        );
    }

    private static function handover_hints($store, $nextShift, $location, $date, $openShift)
    {
        FaTransaction::include_inventory();
        $def = avogs_default_expected();

        $avoQoh = 0;
        $res = db_query(
            "SELECT stock_id FROM " . TB_PREF . "stock_master
             WHERE stock_id LIKE 'AVO-%' AND !inactive",
            'avo qoh'
        );
        while ($r = db_fetch($res)) {
            $avoQoh += (int) round(FaTransaction::qoh($r['stock_id'], $location, $date));
        }

        $juice = (int) round(FaTransaction::qoh('BVG-JUICE', $location, $date));
        $smoothie = (int) round(FaTransaction::qoh('BVG-SMOOTHIE', $location, $date));
        $ginger = (int) round(FaTransaction::qoh('BVG-GINGER', $location, $date));
        $h250 = (int) round(FaTransaction::qoh('HNY-250G', $location, $date));
        $h450 = (int) round(FaTransaction::qoh('HNY-450G', $location, $date));
        $h900 = (int) round(FaTransaction::qoh('HNY-900G', $location, $date));

        return array(
            'shift' => $nextShift,
            'avo' => max(0, $avoQoh),
            'till' => (int) $def['till'],
            'float' => (int) $def['float'],
            'juice' => max(0, $juice),
            'smoothie' => max(0, $smoothie),
            'ginger' => max(0, $ginger),
            'h250' => max(0, $h250),
            'h450' => max(0, $h450),
            'h900' => max(0, $h900),
        );
    }

    private static function checkout_payload($shiftId)
    {
        $shift = Db::row("SELECT * FROM " . Db::t('shifts') . " WHERE id = " . (int) $shiftId);
        if (!$shift || $shift['status'] !== 'closed') {
            return null;
        }

        $nextShift = ($shift['shift_key'] === 'morning') ? 'evening' : 'morning';
        $notes = avogs_json_decode($shift['notes']);
        if (!is_array($notes)) {
            $notes = array();
        }

        $handover = null;
        if (!empty($notes['handover_snapshot']) && is_array($notes['handover_snapshot'])) {
            $handover = $notes['handover_snapshot'];
        } else {
            $h = Db::row("SELECT * FROM " . Db::t('handover') . "
                WHERE store_code = " . Db::esc($shift['store_code']) . "
                  AND shift_key = " . Db::esc($nextShift));
            if ($h) {
                $handover = array(
                    'next_shift' => $nextShift,
                    'avo' => (int) $h['avo'],
                    'till' => (int) $h['till'],
                    'float' => (int) $h['flt'],
                    'juice' => (int) $h['juice'],
                    'smoothie' => (int) $h['smoothie'],
                    'ginger' => (int) $h['ginger'],
                    'h250' => (int) $h['h250'],
                    'h450' => (int) $h['h450'],
                    'h900' => (int) $h['h900'],
                    '_from_live_table' => true,
                );
            }
        }

        $closePhotos = array();
        if (!empty($notes['close_photos']) && is_array($notes['close_photos'])) {
            $closePhotos = $notes['close_photos'];
        } else {
            $all = avogs_json_decode($shift['photos_json']);
            if (is_array($all)) {
                foreach (array('shop_closed', 'cash_count', 'stock_remaining') as $k) {
                    if (!empty($all[$k])) {
                        $closePhotos[$k] = $all[$k];
                    }
                }
            }
        }
        list($photoDetails, $photoMissing) = avogs_resolve_checkout_photos($closePhotos);

        return array(
            'success' => true,
            'shift_id' => (int) $shift['id'],
            'status' => 'closed',
            'store' => $shift['store_code'],
            'shift' => $shift['shift_key'],
            'next_shift' => $nextShift,
            'opened_at' => $shift['opened_at'] ? str_replace(' ', 'T', $shift['opened_at']) : null,
            'closed_at' => $shift['closed_at'] ? str_replace(' ', 'T', $shift['closed_at']) : null,
            'closed_by' => $shift['closed_by'],
            'cash_counted' => (int) $shift['cash_counted'],
            'opening' => array(
                'till' => (int) $shift['opening_till'],
                'float' => (int) $shift['opening_float'],
                'stock_total' => (int) $shift['opening_stock'],
            ),
            'handover' => $handover,
            'photos' => $photoDetails,
            'photo_count' => count($photoDetails),
            'photo_missing' => $photoMissing,
            'notes' => $notes,
        );
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private static function saveCheckin(Request $req)
    {
        $auth = Auth::requireUser($req);
        $store = avogs_store($req, $auth);
        $shift = $req->input('shift', 'morning');
        if (!in_array($shift, array('morning', 'evening'), true)) {
            Response::error('shift must be morning or evening.', 422);
        }

        if (self::open_shift_row($store)) {
            Response::error('A shift is already open for this store.', 409);
        }

        $cash = $req->input('cash', array());
        $till = (int) (isset($cash['till']) ? $cash['till'] : $req->input('opening_till', 0));
        $float = (int) (isset($cash['float']) ? $cash['float'] : $req->input('opening_float', 0));

        $comments = $req->input('comments', array());
        $calls = trim((string) (isset($comments['calls_deliveries']) ? $comments['calls_deliveries'] : $req->input('calls_deliveries', '')));
        $pending = trim((string) (isset($comments['pending_orders']) ? $comments['pending_orders'] : $req->input('pending_orders', '')));

        $photosMap = avogs_normalize_checkin_photos(
            $req->input('photos', array()),
            $req->input('photo_ids', null)
        );
        list($photoDetails, $photoMissing) = avogs_resolve_photos($photosMap);

        $stockLines = $req->input('stock_counts', $req->input('stock_items', array()));
        if (!is_array($stockLines)) {
            $stockLines = array();
        }

        $stockDiscrepancy = false;
        $openingAvo = 0;
        foreach ($stockLines as $line) {
            $actual = (float) (isset($line['actual_qty']) ? $line['actual_qty'] : 0);
            $expected = (float) (isset($line['expected_qty']) ? $line['expected_qty'] : 0);
            if (abs($actual - $expected) > 0.0001) {
                $stockDiscrepancy = true;
            }
            $sid = isset($line['stock_id']) ? $line['stock_id'] : '';
            if (strpos($sid, 'AVO-') === 0) {
                $openingAvo += (int) round($actual);
            }
        }

        $expectedCash = self::expectedCash($store, $shift);
        $cashDiscrepancy = ($till != $expectedCash['till'] || $float != $expectedCash['float']);

        $now = date('Y-m-d H:i:s');
        $notes = $req->input('notes', new stdClass());

        $id = Db::exec("INSERT INTO " . Db::t('shifts') . "
            (store_code, shift_key, status, opened_at, opened_by,
             opening_stock, opening_till, opening_float, stock_discrepancy, cash_discrepancy,
             notes, photo_ids, photos_json, calls_deliveries, pending_orders)
            VALUES (" . Db::esc($store) . ", " . Db::esc($shift) . ", 'open', " . Db::esc($now) . ", " . Db::esc($auth['user_id']) . ", "
            . (int) $openingAvo . ", " . $till . ", " . $float . ", "
            . ($stockDiscrepancy ? 1 : 0) . ", " . ($cashDiscrepancy ? 1 : 0) . ", "
            . Db::json($notes) . ", " . Db::json(array()) . ", "
            . Db::json($photosMap) . ", " . Db::esc($calls) . ", " . Db::esc($pending) . ")");

        self::insert_stock_lines((int) $id, $stockLines);

        Response::json(self::shift_payload((int) $id, $photoDetails, $photoMissing), 201);
    }

    private static function insert_stock_lines($shiftId, $lines)
    {
        foreach ($lines as $line) {
            $stockId = isset($line['stock_id']) ? trim($line['stock_id']) : '';
            if ($stockId === '') {
                continue;
            }
            $desc = isset($line['description']) ? $line['description'] : $stockId;
            $units = isset($line['units']) ? $line['units'] : '';
            $expected = (float) (isset($line['expected_qty']) ? $line['expected_qty'] : 0);
            $actual = (float) (isset($line['actual_qty']) ? $line['actual_qty'] : 0);
            Db::exec("INSERT INTO " . Db::t('shift_stock_lines') . "
                (shift_id, stock_id, description, units, expected_qty, actual_qty)
                VALUES (" . (int) $shiftId . ", " . Db::esc($stockId) . ", " . Db::esc($desc) . ", "
                . Db::esc($units) . ", " . $expected . ", " . $actual . ")");
        }
    }

    private static function stock_items_for_checkin($location, $date)
    {
        FaTransaction::include_inventory();
        $res = db_query(
            "SELECT DISTINCT s.stock_id, s.description, s.units
             FROM " . TB_PREF . "stock_master s
             INNER JOIN " . TB_PREF . "item_codes i ON i.stock_id = s.stock_id AND i.item_code = i.stock_id
             WHERE !s.inactive AND !i.inactive AND s.mb_flag != 'F' AND s.mb_flag != 'D'
             ORDER BY s.stock_id",
            'checkin stock items'
        );
        $items = array();
        while ($r = db_fetch($res)) {
            $gi = avogs_group_index($r['stock_id']);
            $expected = FaTransaction::qoh($r['stock_id'], $location, $date);
            $items[] = array(
                'stock_id' => $r['stock_id'],
                'description' => $r['description'],
                'units' => $r['units'],
                'unit_label' => avogs_unit_for($gi['group']),
                'group' => $gi['group'],
                'group_index' => $gi['index'],
                'expected_qty' => round((float) $expected, 2),
            );
        }
        usort($items, function ($a, $b) {
            $order = array('retail' => 0, 'wholesale' => 1, 'honey' => 2, 'beverage' => 3, 'other' => 4);
            $ga = isset($order[$a['group']]) ? $order[$a['group']] : 99;
            $gb = isset($order[$b['group']]) ? $order[$b['group']] : 99;
            if ($ga !== $gb) {
                return $ga - $gb;
            }
            return $a['group_index'] - $b['group_index'];
        });
        return $items;
    }

    private static function expectedCash($store, $shiftKey)
    {
        $h = Db::row("SELECT till, flt FROM " . Db::t('handover') . "
            WHERE store_code = " . Db::esc($store) . " AND shift_key = " . Db::esc($shiftKey));
        $def = avogs_default_expected();
        return array(
            'till' => $h ? (int) $h['till'] : (int) $def['till'],
            'float' => $h ? (int) $h['flt'] : (int) $def['float'],
        );
    }

    private static function open_shift_row($store)
    {
        return Db::row("SELECT * FROM " . Db::t('shifts') . "
            WHERE store_code = " . Db::esc($store) . " AND status = 'open'
            ORDER BY id DESC LIMIT 1");
    }

    private static function default_shift_key($open)
    {
        if ($open) {
            return $open['shift_key'];
        }
        $hour = (int) date('G');
        return ($hour >= 6 && $hour < 14) ? 'morning' : 'evening';
    }

    private static function expectedFor($store, $shiftKey)
    {
        $h = Db::row("SELECT * FROM " . Db::t('handover') . " WHERE store_code = " . Db::esc($store) . " AND shift_key = " . Db::esc($shiftKey));
        if (!$h) {
            return avogs_default_expected();
        }
        return array(
            'avocado' => (int) $h['avo'], 'till' => (int) $h['till'], 'float' => (int) $h['flt'],
            'juice' => (int) $h['juice'], 'smoothie' => (int) $h['smoothie'], 'ginger' => (int) $h['ginger'],
            'honey_250' => (int) $h['h250'], 'honey_450' => (int) $h['h450'], 'honey_900' => (int) $h['h900'],
        );
    }

    private static function shift_payload($shiftId, $photoDetails = null, $photoMissing = null)
    {
        $shift = Db::row("SELECT * FROM " . Db::t('shifts') . " WHERE id = " . (int) $shiftId);
        if (!$shift) {
            return null;
        }
        $lines = Db::rows("SELECT stock_id, description, units, expected_qty, actual_qty
            FROM " . Db::t('shift_stock_lines') . " WHERE shift_id = " . (int) $shiftId . " ORDER BY id");
        $stock = array();
        foreach ($lines as $l) {
            $stock[] = array(
                'stock_id' => $l['stock_id'],
                'description' => $l['description'],
                'units' => $l['units'],
                'expected_qty' => (float) $l['expected_qty'],
                'actual_qty' => (float) $l['actual_qty'],
                'variance' => round((float) $l['actual_qty'] - (float) $l['expected_qty'], 2),
            );
        }

        $photosMap = avogs_json_decode($shift['photos_json']);
        if (!is_array($photosMap)) {
            $photosMap = array();
        }
        if ($photoDetails === null) {
            list($photoDetails, $photoMissing) = avogs_resolve_photos($photosMap);
        }
        if ($photoMissing === null) {
            $photoMissing = array();
        }

        $warnings = array();
        if ($photoMissing) {
            $warnings[] = count($photoMissing) . ' photo upload_id(s) not found — re-upload and try again.';
        }

        return array(
            'success' => true,
            'message' => 'Shop opened successfully.',
            'shift_id' => (int) $shift['id'],
            'status' => $shift['status'],
            'store' => $shift['store_code'],
            'shift' => $shift['shift_key'],
            'opened_at' => $shift['opened_at'] ? str_replace(' ', 'T', $shift['opened_at']) : null,
            'opened_by' => $shift['opened_by'],
            'cash' => array(
                'till' => (int) $shift['opening_till'],
                'float' => (int) $shift['opening_float'],
            ),
            'opening_stock_total' => (int) $shift['opening_stock'],
            'stock_discrepancy' => (bool) $shift['stock_discrepancy'],
            'cash_discrepancy' => (bool) $shift['cash_discrepancy'],
            'stock_counts' => $stock,
            'photos' => $photoDetails,
            'photos_map' => $photosMap,
            'photo_count' => count($photoDetails),
            'photo_missing' => $photoMissing,
            'comments' => array(
                'calls_deliveries' => $shift['calls_deliveries'],
                'pending_orders' => $shift['pending_orders'],
            ),
            'warnings' => $warnings,
        );
    }
}
