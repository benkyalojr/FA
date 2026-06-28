<?php
class OperationsController
{
    // ---- deliveries ----
    public static function listDeliveries(Request $req)
    {
        $auth = Auth::requireUser($req);
        $store = avogs_store($req, $auth);
        $rows = Db::rows("SELECT * FROM " . Db::t('deliveries') . " WHERE store_code = " . Db::esc($store) . " ORDER BY id DESC");
        $out = array();
        foreach ($rows as $r) {
            $out[] = array(
                'id' => (int) $r['id'], 'customer' => $r['customer'], 'location' => $r['location'],
                'type' => $r['type'], 'qdesc' => $r['qdesc'], 'amount' => (int) $r['amount'],
                'pay' => $r['pay'], 'time' => str_replace(' ', 'T', $r['created_at']),
            );
        }
        Response::json($out);
    }

    public static function createDelivery(Request $req)
    {
        $auth = Auth::requireUser($req);
        $store = avogs_store($req, $auth);
        $now = date('Y-m-d H:i:s');
        $id = Db::exec("INSERT INTO " . Db::t('deliveries') . "
            (store_code, customer, location, type, qdesc, amount, pay, created_at) VALUES ("
            . Db::esc($store) . ", " . Db::esc((string) $req->input('customer', '')) . ", " . Db::esc((string) $req->input('location', '')) . ", "
            . Db::esc((string) $req->input('type', '')) . ", " . Db::esc((string) $req->input('qdesc', '')) . ", "
            . (int) $req->input('amount', 0) . ", " . Db::esc((string) $req->input('pay', 'pending')) . ", " . Db::esc($now) . ")");
        Response::json(array('id' => (int) $id), 201);
    }

    public static function deleteDelivery(Request $req, $params)
    {
        Auth::requireUser($req);
        Db::exec("DELETE FROM " . Db::t('deliveries') . " WHERE id = " . (int) $params['id']);
        Response::json(null, 204);
    }

    // ---- supplies ----
    public static function listSupplies(Request $req)
    {
        $auth = Auth::requireUser($req);
        $store = avogs_store($req, $auth);
        $rows = Db::rows("SELECT * FROM " . Db::t('supplies') . " WHERE store_code = " . Db::esc($store) . " ORDER BY id DESC");
        $out = array();
        foreach ($rows as $r) {
            $out[] = array(
                'id' => (int) $r['id'], 'type' => $r['type'], 'qty' => (int) $r['qty'],
                'desc' => $r['descr'], 'income' => (int) $r['income'], 'date' => $r['supply_date'],
            );
        }
        Response::json($out);
    }

    public static function createSupply(Request $req)
    {
        $auth = Auth::requireUser($req);
        $store = avogs_store($req, $auth);
        $date = $req->input('date', date('Y-m-d'));
        $id = Db::exec("INSERT INTO " . Db::t('supplies') . "
            (store_code, type, qty, descr, income, supply_date) VALUES ("
            . Db::esc($store) . ", " . Db::esc((string) $req->input('type', '')) . ", " . (int) $req->input('qty', 0) . ", "
            . Db::esc((string) $req->input('desc', '')) . ", " . (int) $req->input('income', 0) . ", " . Db::esc($date) . ")");
        Response::json(array('id' => (int) $id), 201);
    }

    public static function deleteSupply(Request $req, $params)
    {
        Auth::requireUser($req);
        Db::exec("DELETE FROM " . Db::t('supplies') . " WHERE id = " . (int) $params['id']);
        Response::json(null, 204);
    }
}
