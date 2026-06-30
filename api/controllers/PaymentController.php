<?php
/**
 * Customer Payment — prefill / commit / consume
 *
 * Routes:
 *   GET  /api/sales/payments/prefill
 *   POST /api/sales/payments
 *   GET  /api/sales/payments/{id}
 *
 * FA screen : sales/customer_payments.php
 * Write fns : write_customer_payment() + allocation::write()
 * Stock     : None
 *
 * Prefill query parameters:
 *   customer_id, branch_id, bank_account, date
 *   allocate_to (optional invoice trans_no to pre-select)
 */
class PaymentController
{
    // ── Prefill ──────────────────────────────────────────────────────────

    public static function prefill(Request $req, $params = array())
    {
        FaTransaction::auth($req);
        FaTransaction::include_payment();

        $customer_id  = (int) $req->q('customer_id', 0);
        $branch_id    = (int) $req->q('branch_id', 0);
        $bank_account = (int) $req->q('bank_account', 0);
        $date         = avogs_date($req);

        if (!$customer_id) {
            $cr = db_fetch(db_query(
                "SELECT debtor_no FROM " . TB_PREF . "debtors_master WHERE inactive=0 ORDER BY debtor_no LIMIT 1"
            ));
            $customer_id = $cr ? (int) $cr['debtor_no'] : 0;
        }
        if (!$branch_id) {
            $branch_id = FaTransaction::default_branch_id($customer_id);
        }

        // Customer name + currency
        $cust_row = db_fetch(db_query(
            "SELECT name, curr_code FROM " . TB_PREF . "debtors_master WHERE debtor_no=" . (int) $customer_id
        ));

        // Default bank account
        if (!$bank_account) {
            $ba = db_fetch(db_query(
                "SELECT id FROM " . TB_PREF . "bank_accounts WHERE inactive=0 ORDER BY id LIMIT 1"
            ));
            $bank_account = $ba ? (int) $ba['id'] : 0;
        }

        // All bank accounts
        $ba_res = get_bank_accounts(false);
        $bank_accounts = array();
        while ($r = db_fetch($ba_res)) {
            $bank_accounts[] = array(
                'id'       => (int) $r['id'],
                'name'     => $r['bank_account_name'],
                'currency' => $r['bank_curr_code'],
            );
        }

        // Open invoices / credits for this customer (allocatable)
        $alloc_res = get_allocatable_to_cust_transactions($customer_id);
        $open_docs = array();
        while ($r = db_fetch($alloc_res)) {
            $balance = (float) $r['Total'] - (float) $r['alloc'];
            if ($balance <= 0) continue;
            $open_docs[] = array(
                'trans_type'    => (int) $r['type'],
                'trans_no'      => (int) $r['trans_no'],
                'reference'     => $r['reference'],
                'document_date' => $r['tran_date'],
                'due_date'      => $r['due_date'],
                'amount'        => (float) $r['Total'],
                'allocated'     => (float) $r['alloc'],
                'balance'       => round($balance, 2),
            );
        }

        Response::json(array(
            'trans_type' => ST_CUSTPAYMENT,
            'defaults' => array(
                'customer_id'   => $customer_id,
                'branch_id'     => $branch_id,
                'currency'      => $cust_row ? $cust_row['curr_code'] : '',
                'document_date' => $date,
                'reference'     => FaTransaction::next_ref(ST_CUSTPAYMENT),
                'bank_account'  => $bank_account,
                'dimension_id'  => 0,
                'dimension2_id' => 0,
            ),
            'open_documents' => $open_docs,
            'bank_accounts'  => $bank_accounts,
        ));
    }

    // ── Commit ───────────────────────────────────────────────────────────

    public static function create(Request $req, $params = array())
    {
        FaTransaction::auth($req);
        FaTransaction::include_payment();

        $body = $req->body;

        $customer_id  = (int) ($body['customer_id']  ?? 0);
        $branch_id    = (int) ($body['branch_id']    ?? 0);
        $bank_account = (int) ($body['bank_account'] ?? 0);
        $date         = $body['document_date'] ?? date('Y-m-d');
        $amount       = (float) ($body['amount']      ?? 0);
        $discount     = (float) ($body['discount']    ?? 0);
        $bank_charge  = (float) ($body['bank_charge'] ?? 0);
        $bank_amount  = (float) ($body['bank_amount'] ?? $amount);
        $memo         = $body['memo']          ?? '';
        $dim1         = (int) ($body['dimension_id']  ?? 0);
        $dim2         = (int) ($body['dimension2_id'] ?? 0);

        if (!$customer_id) {
            FaTransaction::validation_error('customer_id is required.', array('customer_id' => 'Required'));
        }
        if (!$bank_account) {
            FaTransaction::validation_error('bank_account is required.', array('bank_account' => 'Required'));
        }
        if ($amount <= 0) {
            FaTransaction::validation_error('amount must be > 0.', array('amount' => 'Must be positive'));
        }
        if (!FaTransaction::valid_date($date)) {
            FaTransaction::validation_error('Invalid document_date.');
        }
        $fa_date = FaTransaction::to_fa_date($date);

        $ref = FaTransaction::resolve_ref(isset($body['reference']) ? $body['reference'] : null, ST_CUSTPAYMENT);

        // 1. Write the payment
        $payment_no = write_customer_payment(
            0, $customer_id, $branch_id, $bank_account,
            $fa_date, $ref, $amount, $discount, $memo,
            0, $bank_charge, $bank_amount, $dim1, $dim2
        );

        // 2. Apply allocations
        $allocations_in  = isset($body['allocations']) ? $body['allocations'] : array();
        $allocations_out = array();

        if (!empty($allocations_in)) {
            $alloc = new allocation(ST_CUSTPAYMENT, $payment_no, $customer_id, PT_CUSTOMER);
            $alloc->amount = $amount;

            foreach ($allocations_in as $a) {
                $alloc_type   = (int) ($a['trans_type'] ?? ST_SALESINVOICE);
                $alloc_no     = (int) ($a['trans_no']   ?? 0);
                $alloc_amount = (float) ($a['amount']   ?? 0);

                // Find the matching item in alloc->allocs and set current_allocated
                foreach ($alloc->allocs as &$alloc_item) {
                    if ($alloc_item->type == $alloc_type && $alloc_item->type_no == $alloc_no) {
                        $alloc_item->current_allocated = min($alloc_amount, $alloc_item->amount - $alloc_item->amount_allocated);
                        break;
                    }
                }
                unset($alloc_item);

                $allocations_out[] = array(
                    'trans_type' => $alloc_type,
                    'trans_no'   => $alloc_no,
                    'allocated'  => $alloc_amount,
                );
            }

            $alloc->write();
        }

        // Fetch bank_trans_no
        $bank_row = db_fetch(db_query(
            "SELECT id FROM " . TB_PREF . "bank_trans
             WHERE trans_no=" . (int) $payment_no . " AND type=" . ST_CUSTPAYMENT
        ));

        Response::json(array(
            'payment_no'    => (int) $payment_no,
            'trans_type'    => ST_CUSTPAYMENT,
            'reference'     => $ref,
            'customer_id'   => $customer_id,
            'amount'        => $amount,
            'discount'      => $discount,
            'bank_charge'   => $bank_charge,
            'gl_posted'     => true,
            'bank_trans_no' => $bank_row ? (int) $bank_row['id'] : null,
            'allocations'   => $allocations_out,
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

        // Allocations
        $alloc_res = db_query(
            "SELECT a.trans_type_from, a.trans_no_from, a.amt,
                    t.reference, t.tran_date
             FROM " . TB_PREF . "cust_allocations a
             LEFT JOIN " . TB_PREF . "debtor_trans t ON t.trans_no=a.trans_no_to AND t.type=a.trans_type_to
             WHERE a.trans_no_from=" . (int) $payment_no . " AND a.trans_type_from=" . ST_CUSTPAYMENT
        );
        $allocations = array();
        while ($r = db_fetch($alloc_res)) {
            $allocations[] = array(
                'trans_type' => (int) $r['trans_type_from'],
                'trans_no'   => (int) $r['trans_no_from'],
                'allocated'  => (float) $r['amt'],
                'reference'  => $r['reference'],
                'date'       => $r['tran_date'],
            );
        }

        // Bank transaction
        $bank_row = db_fetch(db_query(
            "SELECT id, amount, bank_curr_code FROM " . TB_PREF . "bank_trans
             WHERE trans_no=" . (int) $payment_no . " AND type=" . ST_CUSTPAYMENT
        ));

        // GL summary
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
