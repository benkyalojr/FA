<?php
class SalesController
{
    public static function create(Request $req)
    {
        $auth = Auth::requireUser($req);
        $store = avogs_store($req, $auth);
        $shift = $req->input('shift', 'morning');
        $items = $req->input('items', array());
        if (!is_array($items) || count($items) === 0) {
            Response::error('At least one line item is required.', 422);
        }

        $customerId = (int) $req->input('customer_id', 1);
        $custRow = Db::row("SELECT name FROM " . TB_PREF . "debtors_master WHERE debtor_no = " . (int) $customerId);
        $customerName = $custRow ? $custRow['name'] : 'CASH SALES';
        $payment = $req->input('payment', $req->input('payment_method', 'Cash'));
        $comments = (string) $req->input('comments', '');

        // Validate items against the catalog and compute totals.
        $subtotal = 0; $discount = 0; $units = 0; $lines = array();
        foreach ($items as $it) {
            $stockId = isset($it['stock_id']) ? $it['stock_id'] : null;
            $cat = $stockId ? Db::row("SELECT s.stock_id, s.description AS name,
                (SELECT p.price FROM " . TB_PREF . "prices p WHERE p.stock_id = s.stock_id ORDER BY p.sales_type_id LIMIT 1) AS price
                FROM " . TB_PREF . "stock_master s WHERE s.stock_id = " . Db::esc($stockId)) : null;
            if (!$cat) {
                Response::error('Unknown stock_id: ' . $stockId, 422);
            }
            $qty = max(0, (int) (isset($it['quantity']) ? $it['quantity'] : 0));
            $unitPrice = isset($it['unit_price']) ? (int) $it['unit_price'] : (int) $cat['price'];
            // discount may arrive as percent (FA payload) or absolute KSh.
            $lineDiscount = 0;
            if (isset($it['discount'])) {
                $lineDiscount = (int) $it['discount'];
            } elseif (isset($it['discount_percent'])) {
                $lineDiscount = (int) round(($qty * $unitPrice) * ((float) $it['discount_percent'] / 100));
            }
            $gross = $qty * $unitPrice;
            $subtotal += $gross;
            $discount += $lineDiscount;
            $units += $qty;
            $lines[] = array(
                'stock_id' => $cat['stock_id'], 'name' => $cat['name'],
                'qty' => $qty, 'unit_price' => $unitPrice, 'discount' => $lineDiscount,
            );
        }
        $total = max(0, $subtotal - $discount);

        $date = date('Y-m-d');
        $seq = (int) Db::val("SELECT COUNT(*) FROM " . Db::t('sales') . " WHERE store_code = " . Db::esc($store) . " AND DATE(trans_date) = " . Db::esc($date)) + 1;
        $reference = $req->input('reference', null);
        if (!$reference) {
            $reference = 'INV-' . date('ymd') . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);
        }
        $now = date('Y-m-d H:i:s');

        begin_transaction();
        $saleId = Db::exec("INSERT INTO " . Db::t('sales') . "
            (reference, store_code, shift_key, customer_id, customer_name, payment_method, trans_date, subtotal, discount, total, units, comments)
            VALUES (" . Db::esc($reference) . ", " . Db::esc($store) . ", " . Db::esc($shift) . ", " . (int) $customerId . ", "
            . Db::esc($customerName) . ", " . Db::esc($payment) . ", " . Db::esc($now) . ", "
            . (int) $subtotal . ", " . (int) $discount . ", " . (int) $total . ", " . (int) $units . ", " . Db::esc($comments) . ")");
        foreach ($lines as $l) {
            Db::exec("INSERT INTO " . Db::t('sale_lines') . " (sale_id, stock_id, name, qty, unit_price, discount)
                VALUES (" . (int) $saleId . ", " . Db::esc($l['stock_id']) . ", " . Db::esc($l['name']) . ", "
                . (int) $l['qty'] . ", " . (int) $l['unit_price'] . ", " . (int) $l['discount'] . ")");
        }
        commit_transaction();

        // PHASE 2 HOOK: also post a FrontAccounting direct invoice here via
        // write_sales_invoice() so the sale hits debtor_trans + GL. The data is
        // already FA-shaped (stock_id, customer_id, unit_price, discount_percent).

        Response::json(array(
            'invoice_no' => (int) $saleId,
            'reference' => $reference,
            'total' => (int) $total,
            'trans_date' => $now,
        ), 201);
    }

    public static function index(Request $req)
    {
        $auth = Auth::requireUser($req);
        $store = avogs_store($req, $auth);
        $date = avogs_date($req);
        $shift = $req->q('shift', null);

        $where = "store_code = " . Db::esc($store) . " AND DATE(trans_date) = " . Db::esc($date);
        if ($shift) {
            $where .= " AND shift_key = " . Db::esc($shift);
        }
        $sales = Db::rows("SELECT * FROM " . Db::t('sales') . " WHERE $where ORDER BY trans_date DESC, id DESC");
        $out = array();
        foreach ($sales as $s) {
            $lineRows = Db::rows("SELECT stock_id, name, qty, unit_price, discount FROM " . Db::t('sale_lines') . " WHERE sale_id = " . (int) $s['id']);
            $linesOut = array();
            foreach ($lineRows as $l) {
                $linesOut[] = array(
                    'stock_id' => $l['stock_id'], 'name' => $l['name'],
                    'qty' => (int) $l['qty'], 'unit_price' => (int) $l['unit_price'], 'discount' => (int) $l['discount'],
                );
            }
            $out[] = array(
                'invoice_no' => (int) $s['id'],
                'reference' => $s['reference'],
                'customer' => $s['customer_name'],
                'payment_method' => $s['payment_method'],
                'shift' => $s['shift_key'],
                'time' => str_replace(' ', 'T', $s['trans_date']),
                'units' => (int) $s['units'],
                'discount' => (int) $s['discount'],
                'total' => (int) $s['total'],
                'lines' => $linesOut,
            );
        }
        Response::json($out);
    }

    public static function summary(Request $req)
    {
        $auth = Auth::requireUser($req);
        $store = avogs_store($req, $auth);
        $date = avogs_date($req);
        $base = " FROM " . Db::t('sales') . " s WHERE s.store_code = " . Db::esc($store) . " AND DATE(s.trans_date) = " . Db::esc($date);

        $dayTotal = (int) Db::val("SELECT COALESCE(SUM(total),0)" . $base);
        $dayUnits = (int) Db::val("SELECT COALESCE(SUM(units),0)" . $base);
        $discountTotal = (int) Db::val("SELECT COALESCE(SUM(discount),0)" . $base);

        // Category split from sale lines.
        $catRows = Db::rows("SELECT l.stock_id, SUM((l.qty*l.unit_price)-l.discount) AS amt
            FROM " . Db::t('sale_lines') . " l JOIN " . Db::t('sales') . " s ON s.id = l.sale_id
            WHERE s.store_code = " . Db::esc($store) . " AND DATE(s.trans_date) = " . Db::esc($date) . "
            GROUP BY l.stock_id");
        $retail = 0; $wholesale = 0; $honey = 0; $beverage = 0;
        $juice = 0; $smoothie = 0; $ginger = 0;
        foreach ($catRows as $c) {
            $amt = (int) $c['amt'];
            $sid = $c['stock_id'];
            if (strpos($sid, 'AVO-RT-') === 0) $retail += $amt;
            elseif (strpos($sid, 'AVO-WS-') === 0) $wholesale += $amt;
            elseif (strpos($sid, 'HNY-') === 0) $honey += $amt;
            elseif (strpos($sid, 'BVG-') === 0) {
                $beverage += $amt;
                if ($sid === 'BVG-JUICE') $juice += $amt;
                elseif ($sid === 'BVG-SMOOTHIE') $smoothie += $amt;
                elseif ($sid === 'BVG-GINGER') $ginger += $amt;
            }
        }

        $shiftRows = Db::rows("SELECT shift_key, SUM(total) total, SUM(units) units" . $base . " GROUP BY shift_key");
        $shifts = array('morning' => array('total' => 0, 'units' => 0), 'evening' => array('total' => 0, 'units' => 0));
        foreach ($shiftRows as $sr) {
            $shifts[$sr['shift_key']] = array('total' => (int) $sr['total'], 'units' => (int) $sr['units']);
        }

        Response::json(array(
            'date' => $date,
            'day' => array('total' => $dayTotal, 'units' => $dayUnits, 'retail' => $retail, 'wholesale' => $wholesale, 'honey' => $honey, 'beverage' => $beverage),
            'shift' => $shifts,
            'beverage_split' => array('juice' => $juice, 'smoothie' => $smoothie, 'ginger' => $ginger),
            'discount_total' => $discountTotal,
        ));
    }
}
