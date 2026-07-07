<?php
/**
 * Retail dashboard metrics from real FA tables.
 *
 *   GET /dashboard/summary   — today's sales, units, purchases
 *   GET /dashboard/trends    — daily sales + purchases series (default 7 days)
 *   GET /dashboard           — summary + trends in one response
 */
class DashboardController
{
    /** Today snapshot + optional trend in one call (mobile dashboard). */
    public static function index(Request $req)
    {
        Auth::requireUser($req);

        $date = avogs_date($req);
        $days = self::normalize_days($req->q('days', 7));

        Response::json(array(
            'date'     => $date,
            'currency' => get_company_currency(),
            'today'    => self::today_summary($date),
            'trend'    => self::daily_trend($date, $days),
        ));
    }

    /** Today's sales amount, units sold, purchases amount. */
    public static function summary(Request $req)
    {
        Auth::requireUser($req);

        $date = avogs_date($req);
        Response::json(array(
            'date'     => $date,
            'currency' => get_company_currency(),
            'today'    => self::today_summary($date),
        ));
    }

    /** Daily sales + purchases for charts. */
    public static function trends(Request $req)
    {
        Auth::requireUser($req);

        $date = avogs_date($req);
        $days = self::normalize_days($req->q('days', 7));

        Response::json(array(
            'date'     => $date,
            'currency' => get_company_currency(),
            'days'     => $days,
            'trend'    => self::daily_trend($date, $days),
        ));
    }

    private static function normalize_days($days)
    {
        $days = (int) $days;
        if ($days < 1) {
            $days = 7;
        }
        if ($days > 60) {
            $days = 60;
        }
        return $days;
    }

    /** @return array{sales_amount:float,units_sold:float,purchases_amount:float,invoice_count:int,purchase_count:int} */
    private static function today_summary($date)
    {
        $sql_date = self::to_sql_date($date);

        $sales = db_fetch(db_query(
            "SELECT COUNT(*) AS invoice_count,
                    COALESCE(SUM(ov_amount + ov_gst + ov_freight), 0) AS sales_amount
             FROM " . TB_PREF . "debtor_trans
             WHERE type = " . ST_SALESINVOICE . "
               AND tran_date = " . db_escape($sql_date)
        ));

        $units = db_fetch(db_query(
            "SELECT COALESCE(SUM(dtd.quantity), 0) AS units_sold
             FROM " . TB_PREF . "debtor_trans_details dtd
             INNER JOIN " . TB_PREF . "debtor_trans dt
                ON dt.trans_no = dtd.debtor_trans_no
               AND dt.type = dtd.debtor_trans_type
             WHERE dt.type = " . ST_SALESINVOICE . "
               AND dt.tran_date = " . db_escape($sql_date)
        ));

        $purch = db_fetch(db_query(
            "SELECT COUNT(*) AS purchase_count,
                    COALESCE(SUM(ov_amount + ov_gst), 0) AS purchases_amount
             FROM " . TB_PREF . "supp_trans
             WHERE type = " . ST_SUPPINVOICE . "
               AND tran_date = " . db_escape($sql_date)
        ));

        return array(
            'sales_amount'      => round((float) $sales['sales_amount'], 2),
            'units_sold'        => round((float) $units['units_sold'], 2),
            'purchases_amount'  => round((float) $purch['purchases_amount'], 2),
            'invoice_count'     => (int) $sales['invoice_count'],
            'purchase_count'    => (int) $purch['purchase_count'],
        );
    }

    /** @return array<int,array{date:string,sales_amount:float,units_sold:float,purchases_amount:float}> */
    private static function daily_trend($end_date, $days)
    {
        $end_ts = strtotime($end_date);
        if ($end_ts === false) {
            $end_ts = time();
            $end_date = date('Y-m-d', $end_ts);
        }
        $start_date = date('Y-m-d', strtotime('-' . ($days - 1) . ' days', $end_ts));
        $sql_start = self::to_sql_date($start_date);
        $sql_end = self::to_sql_date($end_date);

        $sales_by_day = array();
        $res = db_query(
            "SELECT tran_date AS d,
                    COALESCE(SUM(ov_amount + ov_gst + ov_freight), 0) AS sales_amount
             FROM " . TB_PREF . "debtor_trans
             WHERE type = " . ST_SALESINVOICE . "
               AND tran_date >= " . db_escape($sql_start) . "
               AND tran_date <= " . db_escape($sql_end) . "
             GROUP BY tran_date",
            'dashboard sales amounts'
        );
        while ($r = db_fetch($res)) {
            $key = self::row_date($r['d']);
            $sales_by_day[$key] = array(
                'sales_amount' => round((float) $r['sales_amount'], 2),
                'units_sold'   => 0,
            );
        }

        $res = db_query(
            "SELECT dt.tran_date AS d,
                    COALESCE(SUM(dtd.quantity), 0) AS units_sold
             FROM " . TB_PREF . "debtor_trans_details dtd
             INNER JOIN " . TB_PREF . "debtor_trans dt
                ON dt.trans_no = dtd.debtor_trans_no
               AND dt.type = dtd.debtor_trans_type
             WHERE dt.type = " . ST_SALESINVOICE . "
               AND dt.tran_date >= " . db_escape($sql_start) . "
               AND dt.tran_date <= " . db_escape($sql_end) . "
             GROUP BY dt.tran_date",
            'dashboard sales units'
        );
        while ($r = db_fetch($res)) {
            $key = self::row_date($r['d']);
            if (!isset($sales_by_day[$key])) {
                $sales_by_day[$key] = array('sales_amount' => 0, 'units_sold' => 0);
            }
            $sales_by_day[$key]['units_sold'] = round((float) $r['units_sold'], 2);
        }

        $purch_by_day = array();
        $res = db_query(
            "SELECT tran_date AS d,
                    COALESCE(SUM(ov_amount + ov_gst), 0) AS purchases_amount
             FROM " . TB_PREF . "supp_trans
             WHERE type = " . ST_SUPPINVOICE . "
               AND tran_date >= " . db_escape($sql_start) . "
               AND tran_date <= " . db_escape($sql_end) . "
             GROUP BY tran_date",
            'dashboard purchase trend'
        );
        while ($r = db_fetch($res)) {
            $purch_by_day[self::row_date($r['d'])] = round((float) $r['purchases_amount'], 2);
        }

        $out = array();
        for ($i = 0; $i < $days; $i++) {
            $key = date('Y-m-d', strtotime($start_date . ' +' . $i . ' days'));
            $sales = isset($sales_by_day[$key]) ? $sales_by_day[$key] : array('sales_amount' => 0, 'units_sold' => 0);
            $out[] = array(
                'date'             => $key,
                'sales_amount'     => $sales['sales_amount'],
                'units_sold'       => $sales['units_sold'],
                'purchases_amount' => isset($purch_by_day[$key]) ? $purch_by_day[$key] : 0,
            );
        }

        return $out;
    }

    /** Normalize API/FA dates to SQL Y-m-d for tran_date comparisons. */
    private static function to_sql_date($date)
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }
        $sql = date2sql($date);
        return $sql ? $sql : $date;
    }

    /** @param string $db_date */
    private static function row_date($db_date)
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $db_date)) {
            return $db_date;
        }
        return FaTransaction::to_iso_date($db_date);
    }
}
