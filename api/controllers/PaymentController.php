<?php
/**
 * Customer Payment — prefill / commit / consume
 *
 * Routes:
 *   GET  /api/sales/invoices/pending     — pending (unpaid) invoices for a customer
 *   GET  /api/sales/payments/prefill     — payment form + open documents to allocate
 *   POST /api/sales/payments
 *   GET  /api/sales/payments/{id}
 *
 * FA screen : sales/customer_payments.php
 * Write fns : write_customer_payment() + allocation::write()
 *
 * Pay-later flow (Direct Sales only):
 *   1. POST /sales/invoices with on_credit:true (or payment_terms = credit term)
 *      → creates AR invoice with balance_due > 0
 *   2. GET  /sales/invoices/pending?customer_id=X  (or payments/prefill)
 *   3. POST /sales/payments with allocations[] to settle
 */
class PaymentController
{
    // ── Pending invoices only ────────────────────────────────────────────

    public static function pendingInvoices(Request $req, $params = array())
    {
        FaTransaction::auth($req);

        $customer_id = (int) $req->q('customer_id', 0);
        if (!$customer_id) {
            FaTransaction::validation_error('customer_id is required.', array('customer_id' => 'Required'));
        }

        $open = FaTransaction::open_customer_documents($customer_id, true);

        Response::json(array(
            'customer_id'       => $customer_id,
            'pending_invoices'  => $open['documents'],
            'invoice_count'     => $open['invoice_count'],
            'total_outstanding' => $open['total_outstanding'],
        ));
    }

    // ── Prefill ──────────────────────────────────────────────────────────

    public static function prefill(Request $req, $params = array())
    {
        FaTransaction::auth($req);
        FaTransaction::include_payment();

        $customer_id  = (int) $req->q('customer_id', 0);
        $branch_id    = (int) $req->q('branch_id', 0);
        $bank_account = (int) $req->q('bank_account', 0);
        $allocate_to  = (int) $req->q('allocate_to', 0);
        $date         = avogs_date($req);

        if (!$customer_id) {
            FaTransaction::validation_error('customer_id is required.', array('customer_id' => 'Required'));
        }
        if (!$branch_id) {
            $branch_id = FaTransaction::default_branch_id($customer_id);
        }

        $cust_row = db_fetch(db_query(
            "SELECT name, curr_code FROM " . TB_PREF . "debtors_master WHERE debtor_no=" . (int) $customer_id
        ));

        if (!$bank_account) {
            $ba = db_fetch(db_query(
                "SELECT id FROM " . TB_PREF . "bank_accounts WHERE inactive=0 ORDER BY id LIMIT 1"
            ));
            $bank_account = $ba ? (int) $ba['id'] : 0;
        }

        $ba_res = get_bank_accounts(false);
        $bank_accounts = array();
        while ($r = db_fetch($ba_res)) {
            $bank_accounts[] = array(
                'id'       => (int) $r['id'],
                'name'     => $r['bank_account_name'],
                'currency' => $r['bank_curr_code'],
            );
        }

        $open = FaTransaction::open_customer_documents($customer_id, false);
        $open_docs = $open['documents'];
        $pending = FaTransaction::open_customer_documents($customer_id, true);

        $selected = null;
        if ($allocate_to) {
            foreach ($open_docs as $doc) {
                if ((int) $doc['trans_type'] === ST_SALESINVOICE && (int) $doc['trans_no'] === $allocate_to) {
                    $selected = $doc;
                    break;
                }
            }
        }

        $suggested_amount = $selected ? (float) $selected['balance'] : $open['total_outstanding'];

        Response::json(array(
            'trans_type' => ST_CUSTPAYMENT,
            'defaults' => array(
                'customer_id'   => $customer_id,
                'branch_id'     => $branch_id,
                'currency'      => $cust_row ? $cust_row['curr_code'] : '',
                'document_date' => $date,
                'reference'     => FaTransaction::next_ref(ST_CUSTPAYMENT),
                'bank_account'  => $bank_account,
                'amount'        => $suggested_amount > 0 ? $suggested_amount : null,
                'dimension_id'  => 0,
                'dimension2_id' => 0,
            ),
            'open_documents'    => $open_docs,
            'pending_invoices'  => $pending['documents'],
            'invoice_count'     => $pending['invoice_count'],
            'total_outstanding' => $open['total_outstanding'],
            'selected_document' => $selected,
            'bank_accounts'     => $bank_accounts,
        ));
    }

    // ── Commit ───────────────────────────────────────────────────────────

    public static function create(Request $req, $params = array())
    {
        FaTransaction::auth($req);

        $body = $req->body;
        $result = FaTransaction::create_customer_payment($body);

        $payment_no = $result['payment_no'];
        $bank_row = db_fetch(db_query(
            "SELECT id FROM " . TB_PREF . "bank_trans
             WHERE trans_no=" . (int) $payment_no . " AND type=" . ST_CUSTPAYMENT
        ));

        Response::json(array(
            'payment_no'    => $payment_no,
            'trans_type'    => ST_CUSTPAYMENT,
            'reference'     => $result['reference'],
            'customer_id'   => (int) ($body['customer_id'] ?? 0),
            'amount'        => (float) ($body['amount'] ?? 0),
            'discount'      => (float) ($body['discount'] ?? 0),
            'bank_charge'   => (float) ($body['bank_charge'] ?? 0),
            'gl_posted'     => true,
            'bank_trans_no' => $bank_row ? (int) $bank_row['id'] : null,
            'allocations'   => $result['allocations'],
        ), 201);
    }

    // ── Consume ──────────────────────────────────────────────────────────

    public static function show(Request $req, $params = array())
    {
        FaTransaction::auth($req);
        FaTransaction::include_payment();

        $payment_no = (int) ($params['id'] ?? 0);
        $header     = FaTransaction::debtor_trans($payment_no, ST_CUSTPAYMENT);
        if (!$header) {
            Response::error('Payment not found.', 404);
        }

        $alloc_res = db_query(
            "SELECT a.trans_type_to, a.trans_no_to, a.amt,
                    t.reference, t.tran_date
             FROM " . TB_PREF . "cust_allocations a
             LEFT JOIN " . TB_PREF . "debtor_trans t
                ON t.trans_no = a.trans_no_to AND t.type = a.trans_type_to
             WHERE a.trans_no_from=" . (int) $payment_no . " AND a.trans_type_from=" . ST_CUSTPAYMENT
        );
        $allocations = array();
        while ($r = db_fetch($alloc_res)) {
            $allocations[] = array(
                'trans_type' => (int) $r['trans_type_to'],
                'trans_no'   => (int) $r['trans_no_to'],
                'allocated'  => (float) $r['amt'],
                'reference'  => $r['reference'],
                'date'       => $r['tran_date'],
            );
        }

        $bank_row = db_fetch(db_query(
            "SELECT bt.id, bt.amount, ba.bank_curr_code
             FROM " . TB_PREF . "bank_trans bt
             LEFT JOIN " . TB_PREF . "bank_accounts ba ON ba.id = bt.bank_act
             WHERE bt.trans_no=" . (int) $payment_no . " AND bt.type=" . ST_CUSTPAYMENT
        ));

        $gl_res = db_query(
            "SELECT g.account, a.account_name, SUM(g.amount) AS total
             FROM " . TB_PREF . "gl_trans g
             JOIN " . TB_PREF . "chart_master a ON a.account_code = g.account
             WHERE g.type_no=" . (int) $payment_no . " AND g.type=" . ST_CUSTPAYMENT . "
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
            'payment_no'    => (int) $header['trans_no'],
            'trans_type'    => ST_CUSTPAYMENT,
            'reference'     => $header['reference'],
            'customer_id'   => (int) $header['debtor_no'],
            'branch_id'     => (int) $header['branch_code'],
            'document_date' => $header['tran_date'],
            'amount'        => (float) $header['ov_amount'],
            'discount'      => (float) $header['ov_discount'],
            'currency'      => $header['curr_code'],
            'alloc'         => (float) $header['alloc'],
            'bank_trans_no' => $bank_row ? (int) $bank_row['id'] : null,
            'bank_currency' => $bank_row ? $bank_row['bank_curr_code'] : null,
            'allocations'   => $allocations,
            'gl_summary'    => $gl,
        ));
    }
}
