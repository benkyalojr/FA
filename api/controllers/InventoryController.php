<?php
class InventoryController
{
    public static function index(Request $req)
    {
        $auth = Auth::requireUser($req);
        $store = avogs_store($req, $auth);
        $date = avogs_date($req);
        $shift = $req->q('shift', 'morning');

        // Expected opening from handover (else defaults).
        $h = Db::row("SELECT * FROM " . Db::t('handover') . " WHERE store_code = " . Db::esc($store) . " AND shift_key = " . Db::esc($shift));
        $def = avogs_default_expected();
        $expAvo = $h ? (int) $h['avo'] : $def['avocado'];
        $expJuice = $h ? (int) $h['juice'] : $def['juice'];
        $expSmoothie = $h ? (int) $h['smoothie'] : $def['smoothie'];
        $expGinger = $h ? (int) $h['ginger'] : $def['ginger'];
        $expH = array('HNY-250G' => $h ? (int) $h['h250'] : $def['honey_250'],
                      'HNY-450G' => $h ? (int) $h['h450'] : $def['honey_450'],
                      'HNY-900G' => $h ? (int) $h['h900'] : $def['honey_900']);

        // Sold quantities this shift/day.
        $soldRows = Db::rows("SELECT l.stock_id, SUM(l.qty) q
            FROM " . Db::t('sale_lines') . " l JOIN " . Db::t('sales') . " s ON s.id = l.sale_id
            WHERE s.store_code = " . Db::esc($store) . " AND DATE(s.trans_date) = " . Db::esc($date) . " AND s.shift_key = " . Db::esc($shift) . "
            GROUP BY l.stock_id");
        $sold = array(); $soldAvo = 0;
        foreach ($soldRows as $r) {
            $sold[$r['stock_id']] = (int) $r['q'];
            if (strpos($r['stock_id'], 'AVO-') === 0) $soldAvo += (int) $r['q'];
        }

        // Avocado wastage today.
        $wasteAvo = (int) Db::val("SELECT COALESCE(SUM(qty),0) FROM " . Db::t('wastage') . "
            WHERE store_code = " . Db::esc($store) . " AND DATE(created_at) = " . Db::esc($date) . " AND product LIKE '%vocado%'");

        $pool = max(0, $expAvo - $soldAvo - $wasteAvo);

        $items = array();
        $soldOf = function ($sid) use ($sold) { return isset($sold[$sid]) ? $sold[$sid] : 0; };
        $items[] = array('stock_id' => 'BVG-JUICE', 'available' => max(0, $expJuice - $soldOf('BVG-JUICE')));
        $items[] = array('stock_id' => 'BVG-SMOOTHIE', 'available' => max(0, $expSmoothie - $soldOf('BVG-SMOOTHIE')));
        $items[] = array('stock_id' => 'BVG-GINGER', 'available' => max(0, $expGinger - $soldOf('BVG-GINGER')));
        foreach ($expH as $sid => $exp) {
            $items[] = array('stock_id' => $sid, 'available' => max(0, $exp - $soldOf($sid)));
        }

        Response::json(array(
            'store' => $store,
            'date' => $date,
            'shift' => $shift,
            'avocado_pool' => $pool,
            'items' => $items,
        ));
    }
}
