# M-Pesa (Safaricom Daraja) - Till

Where: **Banking > M-Pesa** (sub-tabs Transactions, Needs Review, Payouts, Reconciliation) and
**Banking > Settings > M-Pesa Configuration**. Code lives in `mpesa/`.

## Set-up
1. Take a backup, then create the tables: open M-Pesa Configuration and press **Initialize / update M-Pesa**
   (needs the "Run database migrations" right), or run `php scripts/migrate_mpesa.php <company>`.
2. In M-Pesa Configuration enter the Daraja key, secret and passkey, the Till number and the store / head-office
   number, choose the KES bank account that receives M-Pesa payments, and the FrontAccounting user the callbacks run as
   (a dedicated user that can enter customer payments and allocations; stored encrypted).
3. Press **Test connection**, then **Register Till addresses with Safaricom**.
4. Give each person who works with M-Pesa the new rights in Setup > Access Setup (view, request STK Push, assign
   unmatched payments, request / approve payouts, M-Pesa configuration).
5. Cron, every minute: `* * * * * php /path/to/cron/run_scheduled_tasks.php 0` (it starts `cron/mpesa_jobs.php` when
   callbacks are waiting). To also query unanswered STK requests run `php cron/mpesa_jobs.php` every minute.

## Callback addresses (public, contain a secret; the word "mpesa" is refused by Daraja)
`https://<host>/pay/hook/<secret>/{stk|c2b|c2bv|b2c|b2ctimeout|reversal}` - served by `index.php` -> `mpesa/hook.php`.
nginx needs nothing special (`try_files ... /index.php`); Cloudflare must not challenge Safaricom's servers.

## Flow
* Invoice > **Request M-Pesa**: STK Push to the customer's phone; the result callback posts a Customer Payment into the
  M-Pesa bank account, allocated to that invoice, with the Till fee as a bank charge (percent and cap are settings).
* Till receipts nobody asked for: matched by invoice number in the reference, or by an unambiguous customer phone number;
  otherwise they wait in **Needs Review**: assign to a customer (posts an unallocated payment), then allocate it to
  invoices on FrontAccounting's allocation screen. Safaricom now sends phone numbers hashed, so expect most to need review.
* Every callback is stored first (`mpesa_inbox`) and processed afterwards; failures are retried. Receipts are unique,
  so a repeated callback never posts twice.
* **Payouts** (B2C) need a B2C shortcode, initiator and Safaricom certificate (a Till cannot send money out): request,
  a *different* user approves, it is sent; the Supplier Payment is posted from the B2C bank account only when Safaricom
  confirms. Reversal voids the supplier payment.
* **Reconciliation**: import the portal's statement CSV (Receipt No., Completion Time, Details, Paid In, Withdrawn;
  a second row with the same receipt is read as that transaction's charge).

## Tests
`php tests/mpesa.php` - posting, rollback, idempotency, statement import, payouts, permissions, in a throw-away table prefix.
`mpesa/config.example.php` is only a fallback service account for headless runs.
