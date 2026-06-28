<?php
class ReportController
{
    public static function salesTrend(Request $req)
    {
        $auth = Auth::requireUser($req);
        $store = avogs_store($req, $auth);
        $days = (int) $req->q('days', 7);
        if ($days < 1) { $days = 7; }
        if ($days > 60) { $days = 60; }

        $start = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $rows = Db::rows("SELECT DATE(s.trans_date) d, l.stock_id, SUM((l.qty*l.unit_price)-l.discount) amt
            FROM " . Db::t('sales') . " s JOIN " . Db::t('sale_lines') . " l ON l.sale_id = s.id
            WHERE s.store_code = " . Db::esc($store) . " AND DATE(s.trans_date) >= " . Db::esc($start) . "
            GROUP BY DATE(s.trans_date), l.stock_id");

        // Seed every day in range with zeros so the chart has continuous points.
        $byDate = array();
        for ($i = 0; $i < $days; $i++) {
            $key = date('Y-m-d', strtotime('-' . ($days - 1 - $i) . ' days'));
            $byDate[$key] = array('date' => $key, 'total' => 0, 'avocado' => 0, 'honey' => 0, 'beverage' => 0);
        }
        foreach ($rows as $r) {
            $key = $r['d'];
            if (!isset($byDate[$key])) { continue; }
            $amt = (int) $r['amt'];
            $cat = avogs_category_of($r['stock_id']);
            $byDate[$key]['total'] += $amt;
            if ($cat === 'avocado') $byDate[$key]['avocado'] += $amt;
            elseif ($cat === 'honey') $byDate[$key]['honey'] += $amt;
            elseif ($cat === 'beverage') $byDate[$key]['beverage'] += $amt;
        }
        Response::json(array('days' => array_values($byDate)));
    }
}
