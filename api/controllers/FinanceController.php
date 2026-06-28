<?php
class FinanceController
{
    // ---- expenses ----
    public static function listExpenses(Request $req)
    {
        $auth = Auth::requireUser($req);
        $store = avogs_store($req, $auth);
        $rows = Db::rows("SELECT * FROM " . Db::t('expenses') . " WHERE store_code = " . Db::esc($store) . " ORDER BY id DESC");
        $out = array();
        foreach ($rows as $r) {
            $out[] = array(
                'id' => (int) $r['id'], 'category' => $r['category'], 'amount' => (int) $r['amount'],
                'desc' => $r['descr'], 'time' => str_replace(' ', 'T', $r['created_at']),
            );
        }
        Response::json($out);
    }

    public static function createExpense(Request $req)
    {
        $auth = Auth::requireUser($req);
        $store = avogs_store($req, $auth);
        $now = date('Y-m-d H:i:s');
        $id = Db::exec("INSERT INTO " . Db::t('expenses') . "
            (store_code, category, amount, descr, created_at) VALUES ("
            . Db::esc($store) . ", " . Db::esc((string) $req->input('category', '')) . ", "
            . (int) $req->input('amount', 0) . ", " . Db::esc((string) $req->input('desc', '')) . ", " . Db::esc($now) . ")");
        // PHASE 2 HOOK: post to FA GL via add_gl_trans/quick entry for real ledger impact.
        Response::json(array('id' => (int) $id), 201);
    }

    public static function deleteExpense(Request $req, $params)
    {
        Auth::requireUser($req);
        Db::exec("DELETE FROM " . Db::t('expenses') . " WHERE id = " . (int) $params['id']);
        Response::json(null, 204);
    }

    // ---- wastage ----
    public static function listWastage(Request $req)
    {
        $auth = Auth::requireUser($req);
        $store = avogs_store($req, $auth);
        $rows = Db::rows("SELECT * FROM " . Db::t('wastage') . " WHERE store_code = " . Db::esc($store) . " ORDER BY id DESC");
        $out = array();
        foreach ($rows as $r) {
            $out[] = array(
                'id' => (int) $r['id'], 'product' => $r['product'], 'qty' => (int) $r['qty'],
                'reason' => $r['reason'], 'duration' => $r['duration'], 'loss' => (int) $r['loss'],
                'time' => str_replace(' ', 'T', $r['created_at']),
            );
        }
        Response::json($out);
    }

    public static function createWastage(Request $req)
    {
        $auth = Auth::requireUser($req);
        $store = avogs_store($req, $auth);
        $now = date('Y-m-d H:i:s');
        $id = Db::exec("INSERT INTO " . Db::t('wastage') . "
            (store_code, product, qty, reason, duration, loss, created_at) VALUES ("
            . Db::esc($store) . ", " . Db::esc((string) $req->input('product', '')) . ", " . (int) $req->input('qty', 0) . ", "
            . Db::esc((string) $req->input('reason', '')) . ", " . Db::esc((string) $req->input('duration', '')) . ", "
            . (int) $req->input('loss', 0) . ", " . Db::esc($now) . ")");
        // PHASE 2 HOOK: post a negative stock adjustment to FA (stock_moves) here.
        Response::json(array('id' => (int) $id), 201);
    }

    public static function deleteWastage(Request $req, $params)
    {
        Auth::requireUser($req);
        Db::exec("DELETE FROM " . Db::t('wastage') . " WHERE id = " . (int) $params['id']);
        Response::json(null, 204);
    }
}
