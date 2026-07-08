# Credit Sales & Customer Payments — Mobile Integration

How to sell **now, pay later** using **Direct Sales Invoice** only, then collect payment against **pending invoices**.

**Base URL:** `https://<host>/api`  
**Auth:** `Authorization: Bearer <token>` on every call (from `POST /auth/login`).

---

## Overview

AVO'Gs retail API uses a **two-step AR model** (no Sales Order screen required):

| Step | What happens | FA document |
|------|----------------|-------------|
| 1. **Direct sale on credit** | Stock out + invoice posted to customer AR | Sales Invoice (`trans_type` **10**) with `balance_due > 0` |
| 2. **Customer payment** | Cash/M-Pesa/bank received and allocated to invoice(s) | Customer Payment (`trans_type` **12**) |

Cash-at-counter sales can either:

- Use a **cash** customer / payment terms (FA may auto-settle via POS), or
- Create a credit invoice and settle immediately with a nested `payment` object on the same `POST /sales/invoices` call.

---

## Endpoint map

| When | Endpoint |
|------|----------|
| List payment term options (cash vs credit) | `GET /payment-terms` |
| Start direct sale | `GET /sales/invoices/prefill?customer_id=` |
| Post credit invoice | `POST /sales/invoices` with `on_credit: true` |
| List unpaid invoices for one customer | `GET /sales/invoices/pending?customer_id=` |
| Open payment screen (invoices + bank accounts) | `GET /sales/payments/prefill?customer_id=` |
| Pre-select one invoice on payment screen | `GET /sales/payments/prefill?customer_id=&allocate_to=` |
| Record payment | `POST /sales/payments` |
| Read invoice / payment back | `GET /sales/invoices/{id}` / `GET /sales/payments/{id}` |

Full reference: [`TRANSACTION_API.md`](TRANSACTION_API.md) §7, [`FRONTEND_API_REFERENCE.md`](FRONTEND_API_REFERENCE.md) §11.

---

## 1. Payment terms (cash vs credit)

Before building sale UI, load FA payment terms:

```http
GET /api/payment-terms
Authorization: Bearer <token>
```

```json
[
  { "id": 1, "name": "Cash only", "days_due": 0, "cash_sale": true,  "on_credit": false },
  { "id": 4, "name": "Due in 30 days", "days_due": 30, "cash_sale": false, "on_credit": true }
]
```

| Field | Use in app |
|-------|------------|
| `cash_sale: true` | Walk-in paid now — customer default or explicit term |
| `on_credit: true` | Tab / wholesale / pay later — invoice stays open until payment |

Customers also carry a default `payment_terms` on `GET /customers/{id}`.

---

## 2. Direct sale — pay later (creates pending invoice)

### Prefill

```http
GET /api/sales/invoices/prefill?customer_id=5&location=TRM
Authorization: Bearer <token>
```

Response includes `payment_terms_options[]` and `defaults.on_credit` (from customer master).

### Post invoice on credit

**Option A — shorthand (recommended for “Pay later” toggle):**

```json
POST /api/sales/invoices
{
  "customer_id": 5,
  "branch_id": 5,
  "location": "TRM",
  "on_credit": true,
  "lines": [
    { "stock_id": "AVO-RT-S3", "quantity": 10, "unit_price": 30 }
  ]
}
```

**Option B — explicit payment term id:**

```json
POST /api/sales/invoices
{
  "customer_id": 5,
  "location": "TRM",
  "payment_terms": 4,
  "lines": [ ... ]
}
```

### Response (pending AR)

```json
{
  "invoice_no": 558,
  "reference": "INV-2026-00123",
  "total": 300,
  "amount_paid": 0,
  "balance_due": 300,
  "payment_status": "pending",
  "payment_no": null,
  "gl_posted": true,
  "stock_moves": [ ... ]
}
```

| Field | Meaning |
|-------|---------|
| `payment_status: "pending"` | Customer still owes `balance_due` |
| `payment_status: "paid"` | Fully settled (cash term or nested `payment`) |
| `invoice_no` | Use in customer payment `allocations[].trans_no` |

**Important:** Use a customer with credit terms, or pass `on_credit: true`. Cash-only customers may auto-pay inside FA when POS cash account is configured.

---

## 3. Direct sale — pay now (single request)

Create invoice and receive payment in one call:

```json
POST /api/sales/invoices
{
  "customer_id": 5,
  "location": "TRM",
  "on_credit": true,
  "lines": [
    { "stock_id": "AVO-RT-S3", "quantity": 10, "unit_price": 30 }
  ],
  "payment": {
    "bank_account": 1,
    "amount": 300,
    "memo": "M-Pesa #QA123456"
  }
}
```

Response:

```json
{
  "invoice_no": 559,
  "total": 300,
  "balance_due": 0,
  "payment_status": "paid",
  "payment_no": 901
}
```

`payment.bank_account` is required (`GET /sales/payments/prefill` → `bank_accounts[]`).  
`payment.amount` defaults to full invoice balance if omitted.

---

## 4. List pending invoices (customer payments screen)

When user opens **Receive Payment** for a customer:

```http
GET /api/sales/invoices/pending?customer_id=5
Authorization: Bearer <token>
```

```json
{
  "customer_id": 5,
  "pending_invoices": [
    {
      "trans_type": 10,
      "trans_no": 558,
      "reference": "INV-2026-00123",
      "document_date": "2026-06-28",
      "due_date": "2026-07-28",
      "amount": 300,
      "allocated": 0,
      "balance": 300
    }
  ],
  "invoice_count": 1,
  "total_outstanding": 300
}
```

Show `pending_invoices[]` as a checklist. User selects which invoice(s) to settle.

Alternatively use **`GET /sales/payments/prefill`** — same invoices plus bank accounts, suggested `defaults.amount`, and optional `allocate_to`:

```http
GET /api/sales/payments/prefill?customer_id=5&allocate_to=558
```

```json
{
  "defaults": {
    "customer_id": 5,
    "amount": 300,
    "bank_account": 1,
    "reference": "RCP-2026-00100",
    ...
  },
  "pending_invoices": [ ... ],
  "open_documents": [ ... ],
  "total_outstanding": 300,
  "selected_document": { "trans_no": 558, "balance": 300, ... },
  "bank_accounts": [
    { "id": 1, "name": "Cash", "currency": "KES" },
    { "id": 2, "name": "M-Pesa", "currency": "KES" }
  ]
}
```

`customer_id` is **required** on payment prefill.

---

## 5. Record customer payment

```json
POST /api/sales/payments
{
  "customer_id": 5,
  "branch_id": 5,
  "bank_account": 1,
  "document_date": "2026-07-08",
  "amount": 300,
  "memo": "M-Pesa #QA123456",
  "allocations": [
    { "trans_type": 10, "trans_no": 558, "amount": 300 }
  ]
}
```

| Field | Notes |
|-------|--------|
| `amount` | Total received (customer currency) |
| `allocations` | Optional but typical — links payment to open invoice(s) |
| `trans_type: 10` | Always sales invoice for retail AR |
| `trans_no` | `invoice_no` from step 2 |

Partial payment example — customer pays 200 on a 300 invoice:

```json
"amount": 200,
"allocations": [{ "trans_type": 10, "trans_no": 558, "amount": 200 }]
```

Invoice keeps `balance_due: 100` until a second payment.

### Response

```json
{
  "payment_no": 901,
  "trans_type": 12,
  "reference": "RCP-2026-00100",
  "amount": 300,
  "allocations": [
    { "trans_type": 10, "trans_no": 558, "allocated": 300 }
  ],
  "gl_posted": true
}
```

---

## 6. Read back

```http
GET /api/sales/invoices/558
GET /api/sales/payments/901
```

Invoice after full payment:

```json
{
  "balance_due": 0,
  "payment_status": "paid",
  "amount_paid": 300
}
```

---

## Mobile screen flow

```
┌─────────────────────────────────────────────────────────────┐
│  DIRECT SALE                                                │
│  Toggle: [ Pay now ] [ Pay later ]                          │
│                                                             │
│  Pay later → POST /sales/invoices { on_credit: true }       │
│  Pay now   → POST /sales/invoices { payment: { ... } }      │
│              OR cash customer + cash payment_terms          │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼ (if payment_status = pending)
┌─────────────────────────────────────────────────────────────┐
│  RECEIVE PAYMENT (later)                                    │
│  1. GET /sales/invoices/pending?customer_id=X               │
│  2. User picks invoice(s), enters amount + M-Pesa ref       │
│  3. POST /sales/payments { allocations: [...] }             │
└─────────────────────────────────────────────────────────────┘
```

---

## Typical sequences

### A. Wholesale customer — tab today, pay Friday

```http
POST /sales/invoices          → invoice_no 558, payment_status pending
GET  /sales/invoices/pending  → shows 558, balance 600
POST /sales/payments          → allocates 600 to 558, payment_no 901
GET  /sales/invoices/558      → payment_status paid
```

### B. Walk-in — M-Pesa immediately

```http
POST /sales/invoices
  { lines: [...], payment: { bank_account: 2, amount: 450, memo: "MPesa..." } }
→ invoice_no 560, payment_status paid, payment_no 902
```

### C. Partial settlement

```http
POST /sales/payments
  { amount: 200, allocations: [{ trans_type: 10, trans_no: 558, amount: 200 }] }
GET /sales/invoices/558
  → balance_due: 400
```

---

## Errors & validation

| HTTP | Cause |
|------|--------|
| `422` | Missing `customer_id`, invalid lines, bad date |
| `404` | Unknown `invoice_no` / `payment_no` |
| `409` | Duplicate `reference` |

Payment prefill without `customer_id`:

```json
{ "error": { "code": "validation_failed", "message": "customer_id is required." } }
```

Sum of `allocations[].amount` must not exceed `amount`.

---

## FA desktop (for support)

| Mobile action | FA inquiry |
|---------------|------------|
| Credit invoice | Sales → Transactions → Sales invoices |
| Customer payment | Sales → Transactions → Customer payments |
| Allocation | Open payment → Allocate to invoice |

---

## Related docs

- [`MOBILE_APP_GUIDE.md`](MOBILE_APP_GUIDE.md) — §9 Customer payment, §8 Direct sales invoice  
- [`TRANSACTION_API.md`](TRANSACTION_API.md) — §6 Sales invoice, §7 Customer payment  
- [`FRONTEND_API_REFERENCE.md`](FRONTEND_API_REFERENCE.md) — request/response schemas  
- [`openapi.yaml`](openapi.yaml) — machine-readable routes  
