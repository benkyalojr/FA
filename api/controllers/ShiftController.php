<?php
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
        $open = Db::row("SELECT * FROM " . Db::t('shifts') . " WHERE store_code = " . Db::esc($store) . " AND status = 'open' ORDER BY id DESC LIMIT 1");

        $hour = (int) date('G');
        $shiftKey = ($hour >= 6 && $hour < 14) ? 'morning' : 'evening';
        if ($open) {
            $shiftKey = $open['shift_key'];
        }

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

    public static function open(Request $req)
    {
        $auth = Auth::requireUser($req);
        $store = avogs_store($req, $auth);
        $shift = $req->input('shift', 'morning');
        $notes = json_encode($req->input('notes', new stdClass()));
        $photos = json_encode($req->input('photo_ids', array()));
        $now = date('Y-m-d H:i:s');

        $id = Db::exec("INSERT INTO " . Db::t('shifts') . "
            (store_code, shift_key, status, opened_at, opened_by, opening_stock, opening_till, opening_float, stock_discrepancy, cash_discrepancy, notes, photo_ids)
            VALUES (" . Db::esc($store) . ", " . Db::esc($shift) . ", 'open', " . Db::esc($now) . ", " . Db::esc($auth['user_id']) . ", "
            . (int) $req->input('opening_stock', 0) . ", " . (int) $req->input('opening_till', 0) . ", " . (int) $req->input('opening_float', 0) . ", "
            . ((int) (bool) $req->input('stock_discrepancy', false)) . ", " . ((int) (bool) $req->input('cash_discrepancy', false)) . ", "
            . Db::esc($notes) . ", " . Db::esc($photos) . ")");

        Response::json(array('shift_id' => (int) $id, 'status' => 'open'), 201);
    }

    public static function close(Request $req, $params)
    {
        $auth = Auth::requireUser($req);
        $shiftId = (int) $params['id'];
        $shift = Db::row("SELECT * FROM " . Db::t('shifts') . " WHERE id = " . $shiftId);
        if (!$shift) {
            Response::error('Shift not found.', 404);
        }
        $now = date('Y-m-d H:i:s');
        $notes = json_encode($req->input('notes', new stdClass()));
        $photos = json_encode($req->input('photo_ids', array()));
        $cashCounted = (int) $req->input('cash_counted', 0);

        Db::exec("UPDATE " . Db::t('shifts') . " SET status = 'closed', closed_at = " . Db::esc($now) . ", closed_by = " . Db::esc($auth['user_id']) . ",
            cash_counted = " . $cashCounted . ", notes = " . Db::esc($notes) . ", photo_ids = " . Db::esc($photos) . "
            WHERE id = " . $shiftId);

        // Closing counts become the next shift's expected handover.
        $nextShift = ($shift['shift_key'] === 'morning') ? 'evening' : 'morning';
        $h = $req->input('handover', array());
        $g = function ($k) use ($h) { return isset($h[$k]) ? (int) $h[$k] : 0; };
        $store = $shift['store_code'];
        Db::exec("INSERT INTO " . Db::t('handover') . "
            (store_code, shift_key, avo, till, flt, juice, smoothie, ginger, h250, h450, h900, updated_at)
            VALUES (" . Db::esc($store) . ", " . Db::esc($nextShift) . ", "
            . $g('avo') . ", " . $g('till') . ", " . $g('float') . ", " . $g('juice') . ", " . $g('smoothie') . ", " . $g('ginger') . ", "
            . $g('h250') . ", " . $g('h450') . ", " . $g('h900') . ", " . Db::esc($now) . ")
            ON DUPLICATE KEY UPDATE avo=VALUES(avo), till=VALUES(till), flt=VALUES(flt), juice=VALUES(juice),
            smoothie=VALUES(smoothie), ginger=VALUES(ginger), h250=VALUES(h250), h450=VALUES(h450), h900=VALUES(h900), updated_at=VALUES(updated_at)");

        Response::json(array('shift_id' => $shiftId, 'status' => 'closed'));
    }
}
