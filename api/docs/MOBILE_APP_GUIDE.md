# AVO'Gs Mobile App — Frontend Developer Guide

**API version:** 2.3.1 (retail)  
**Base URL:** `https://<fa-host>/api`  
**Local dev:** `http://localhost:8090/api`  
**Interactive docs:** `/api/docs/` (Swagger UI)  
**OpenAPI spec:** `/api/docs/openapi.yaml`  
**Last updated:** 2026-07-07

This guide tells the mobile team **what to build**, **which endpoints to call**, and **in what order**. It reflects the live retail API — not legacy B2B flows.

---

## Table of contents

1. [Retail scope](#1-retail-scope)
2. [Quick start](#2-quick-start)
3. [App screens → API map](#3-app-screens--api-map)
4. [Conventions](#4-conventions)
5. [Authentication](#5-authentication)
6. [Master data & pricing](#6-master-data--pricing)
7. [Transaction pattern](#7-transaction-pattern)
8. [Screen: Direct sales invoice](#8-screen-direct-sales-invoice)
9. [Screen: Customer payment](#9-screen-customer-payment)
10. [Screen: Supplier invoice](#10-screen-supplier-invoice)
11. [Screen: Inventory adjustment](#11-screen-inventory-adjustment)
12. [Dynamic line entry](#12-dynamic-line-entry)
13. [Error handling](#13-error-handling)
14. [Optional: shifts & checklists](#14-optional-shifts--checklists)
15. [Testing & QA](#15-testing--qa)
16. [Route reference](#16-route-reference)

---

## 1. Retail scope

The API supports **small retail** integrated with FrontAccounting (FA). Customers and suppliers are **read-only** from the app; the app creates **documents** only.

### Supported transaction types

| Mobile screen | API prefix | FA equivalent |
|---------------|------------|---------------|
| Direct sale / POS invoice | `/sales/invoices` | Sales → Direct invoice |
| Customer payment (settle credit) | `/sales/payments` | Sales → Customer payment |
| Supplier invoice (stock in) | `/purchasing/invoices` | Purchasing → Direct supplier invoice |
| Stock adjustment / wastage | `/inventory/adjustments` | Inventory → Adjustment |

### Not available (404)

These were removed intentionally. **Do not call them.**

| Removed | Use instead |
|---------|-------------|
| `/sales/orders/*` | `/sales/invoices/*` |
| `/sales/deliveries/*` | `/sales/invoices/*` (direct sale includes stock out) |
| `/purchasing/orders/*` | `/purchasing/invoices/*` |
| `/inventory/transfers/*` | FA desktop only |

---

## 2. Quick start

### 2.1 Login

```http
POST /api/auth/login
Content-Type: application/json

{
  "identifier": "apiuser",
  "password": "apiuser"
}
```

**Response (200):**

```json
{
  "token": "51dcc99b45977f7c473fa3d3a21b07f7c826c2df",
  "user": {
    "id": 3,
    "login": "apiuser",
    "name": "API User",
    "role_id": 2
  },
  "allowed_stores": ["Default", "Juja", "Kahawa Sukari"]
}
```

Store `token` securely. Send it on every request:

```http
Authorization: Bearer <token>
```

### 2.2 Discover API

```http
GET /api/
```

Returns version, scope (`retail`), and transaction path prefixes.

### 2.3 Typical session bootstrap

After login, load reference data in parallel:

```text
GET /stores
GET /customers
GET /suppliers
GET /sales-types          ← customer price categories
GET /payment-methods      ← UI labels only (Cash, M-Pesa, …)
```

Pick defaults:

- **Walk-in customer:** usually `customer_id: 1` (CASH SALES)
- **Location:** first store’s `code` from `/stores` (e.g. `DEF`)
- **Date:** today as `YYYY-MM-DD` (optional `?date=` on prefill endpoints)

---

## 3. App screens → API map

| # | App screen | Load (prefill) | Submit | View saved |
|---|------------|----------------|--------|------------|
| 1 | **POS / Sale** | `GET /sales/invoices/prefill` | `POST /sales/invoices` | `GET /sales/invoices/{id}` |
| 2 | **Receive payment** | `GET /sales/payments/prefill` | `POST /sales/payments` | `GET /sales/payments/{id}` |
| 3 | **Supplier delivery** | `GET /purchasing/invoices/prefill` | `POST /purchasing/invoices` | `GET /purchasing/invoices/{id}` |
| 4 | **Stock adjust / wastage** | `GET /inventory/adjustments/prefill` | `POST /inventory/adjustments` | `GET /inventory/adjustments/{id}` |

**Read-only pickers** (no writes):

| Picker | Endpoint |
|--------|----------|
| Customer list | `GET /customers` |
| Customer detail + branches | `GET /customers/{id}` |
| Supplier list | `GET /suppliers` |
| Item list (no prices) | `GET /items?location=DEF` |
| Price matrix (by category) | `GET /prices?sales_type_id=1` |
| Customer resolved prices | `GET /customers/{id}/prices` |
| Supplier purchase prices | `GET /suppliers/{id}/prices` or `GET /purchasing-data?supplier_id=1` |

---

## 4. Conventions

| Topic | Rule |
|-------|------|
| Format | JSON request + response, `Content-Type: application/json` |
| Auth | Bearer token except `POST /auth/login` |
| Dates | `YYYY-MM-DD` strings |
| Money | Decimal numbers in document currency (e.g. KES), not integer cents |
| Discount | `discount_percent` on lines: `10` = 10% |
| Location | FA location **code** (e.g. `DEF`), not display name |
| Item code | `stock_id` string (e.g. `AVO-RT-S1`) |
| References | Optional on create; server auto-generates if omitted |
| Idempotency | Not built-in — avoid double-tap submit; use UI loading state |

### Query parameters (common)

| Param | Used on | Meaning |
|-------|---------|---------|
| `customer_id` | Sales prefill | Debtor id |
| `supplier_id` | Purchasing prefill | Supplier id |
| `location` | Prefill, items | Stock location code |
| `date` | Prefill, prices | Document date (defaults to today) |
| `stock_id` | Filters | Single item |

---

## 5. Authentication

| Method | Path | Body | Response |
|--------|------|------|----------|
| POST | `/auth/login` | `{ identifier, password }` | 200 + token |
| POST | `/auth/logout` | — | 204 |

**401** on expired/invalid token → redirect to login.

The app PIN (if used) is **client-side only**; the server never sees it. Only the FA username/password (or API user) is validated at login.

---

## 6. Master data & pricing

Understanding pricing prevents wrong UI assumptions.

### 6.1 Customer categories (`sales_types`)

FA stores **selling prices per item × customer category × currency**, not per individual customer.

```http
GET /api/sales-types
```

```json
[
  { "id": 1, "name": "Retail", "tax_included": true, "factor": 1.0 }
]
```

Each customer has `sales_type_id` on `GET /customers` / `GET /customers/{id}`.

### 6.2 Price matrix (admin view)

Same data as FA **Inventory → Sales Pricing**:

```http
GET /api/prices?stock_id=AVO-RT-S1
GET /api/prices?sales_type_id=1
```

### 6.3 Customer-specific prices (for invoicing)

Resolved price for each sellable item using the customer’s category + currency + FA fallbacks:

```http
GET /api/customers/1/prices
GET /api/customers/1/prices?stock_id=AVO-RT-S1
```

Each row:

| Field | Meaning |
|-------|---------|
| `list_price` | Explicit row in price matrix (may be `null`) |
| `unit_price` | **Use this on invoices** — full FA `get_price()` resolution |

### 6.4 Supplier purchase prices

Same as FA **Inventory → Purchasing Data**:

```http
GET /api/purchasing-data?stock_id=AVO-RT-S1
GET /api/suppliers/1/prices
```

| Field | Meaning |
|-------|---------|
| `price` | Supplier list price (their UOM) |
| `unit_price` | **Use on supplier invoices** — `price ÷ conversion_factor` |

Only items with rows in `purch_data` appear on `/suppliers/{id}/prices`. Prefill catalog lists all purchasable items with `supplier_price` from FA.

### 6.5 What to use on each screen

| Screen | Price source |
|--------|--------------|
| POS / sales invoice | Prefill `catalog[].unit_price` or `/customers/{id}/prices` |
| Supplier invoice | Prefill `catalog[].supplier_price` or `/purchasing-data` |
| Adjustment | `material_cost` / `standard_cost` — not selling price |

---

## 7. Transaction pattern

Every transaction screen follows the same **three-step** flow:

```text
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│  1. PREFILL │ ──► │  2. COMMIT  │ ──► │  3. CONSUME │
│  GET …/     │     │  POST …     │     │  GET …/{id} │
│  prefill    │     │             │     │  (optional) │
└─────────────┘     └─────────────┘     └─────────────┘
```

1. **Prefill** — defaults, next reference, catalog with prices & QOH  
2. **Commit** — user confirms; POST creates real FA document  
3. **Consume** — read back for receipt / sync (optional)

**UI recommendation:** On screen open, call prefill once. Bind form fields to `defaults`. Populate line picker from `catalog`. On submit, POST body = defaults + user-edited lines.

---

## 8. Screen: Direct sales invoice

**FA:** cash-and-carry sale. Creates sales order + delivery + invoice + stock out + GL in one POST.

### 8.1 Load form

```http
GET /api/sales/invoices/prefill?customer_id=1&location=DEF
Authorization: Bearer <token>
```

**Response shape:**

```json
{
  "trans_type": 10,
  "defaults": {
    "customer_id": 1,
    "branch_id": 1,
    "sales_type": 1,
    "sales_type_name": "Retail",
    "currency": "KES",
    "default_discount_percent": 0,
    "location": "DEF",
    "document_date": "2026-07-07",
    "due_date": "2026-07-07",
    "reference": "SI-2026/0001",
    "deliver_to": "CASH SALES",
    "delivery_address": "",
    "freight_cost": 0
  },
  "catalog": [
    {
      "stock_id": "AVO-RT-S1",
      "description": "Avocado Retail Size 1",
      "units": "each",
      "unit_price": 75.0,
      "qoh": 42.0,
      "is_kit": false
    }
  ]
}
```

### 8.2 Submit sale

```http
POST /api/sales/invoices
Content-Type: application/json
Authorization: Bearer <token>

{
  "customer_id": 1,
  "branch_id": 1,
  "location": "DEF",
  "document_date": "2026-07-07",
  "reference": "SI-2026/0001",
  "lines": [
    {
      "stock_id": "AVO-RT-S1",
      "quantity": 2,
      "unit_price": 75.0,
      "discount_percent": 0
    }
  ]
}
```

**Required:** `customer_id`, `location`, at least one line with `stock_id` + `quantity` > 0.

**Optional:** `unit_price` (defaults from catalog if 0), `discount_percent`, `comments`, `freight_cost`, `due_date`.

**Response (201):**

```json
{
  "invoice_no": 3,
  "trans_type": 10,
  "reference": "SI-2026/0001",
  "total": 150.0,
  "gl_posted": true,
  "auto_created": {
    "sales_order_no": 5,
    "delivery_no": 4
  },
  "lines": [ … ]
}
```

### 8.3 Show invoice (receipt)

```http
GET /api/sales/invoices/3
```

Returns header, lines, taxes, `alloc` (amount paid), `stock_moves`.

### 8.4 UI notes

- Default customer **CASH SALES** (`id: 1`) for walk-in POS.
- Show **QOH** from catalog; block or warn if quantity > QOH when negative stock is disallowed in FA.
- `branch_id` can be omitted — server picks customer’s first branch.
- Payment method (Cash / M-Pesa) is **UI-only** unless you also POST a customer payment for credit sales.

---

## 9. Screen: Customer payment

**FA:** allocate payment against open AR invoices.

### 9.1 Load form

```http
GET /api/sales/payments/prefill?customer_id=1
```

**Response highlights:**

```json
{
  "defaults": {
    "customer_id": 1,
    "branch_id": 1,
    "currency": "KES",
    "document_date": "2026-07-07",
    "reference": "CP-2026/0001",
    "bank_account": 1
  },
  "open_documents": [
    {
      "trans_type": 10,
      "trans_no": 3,
      "reference": "SI-2026/0001",
      "document_date": "2026-07-07",
      "amount": 150.0,
      "allocated": 0.0,
      "balance": 150.0
    }
  ],
  "bank_accounts": [
    { "id": 1, "name": "Cash", "currency": "KES" }
  ]
}
```

### 9.2 Submit payment

```http
POST /api/sales/payments

{
  "customer_id": 1,
  "branch_id": 1,
  "bank_account": 1,
  "document_date": "2026-07-07",
  "amount": 150.0,
  "allocations": [
    {
      "trans_type": 10,
      "trans_no": 3,
      "amount": 150.0
    }
  ]
}
```

**Required:** `customer_id`, `bank_account`, `amount` > 0.

**Allocations:** optional but typical — link payment to invoice(s). `trans_type: 10` = sales invoice.

### 9.3 UI notes

- List `open_documents` as checkboxes with balance due.
- Sum of allocations should not exceed `amount`.
- For pure cash POS, you often **skip** this screen (invoice alone is enough for cash customers).

---

## 10. Screen: Supplier invoice

**FA:** direct supplier invoice — GRN receive + AP invoice + stock in in one step.

### 10.1 Load form

```http
GET /api/purchasing/invoices/prefill?supplier_id=1&location=DEF
```

```json
{
  "defaults": {
    "supplier_id": 1,
    "supplier_name": "RUTO FARM",
    "currency": "KES",
    "location": "DEF",
    "document_date": "2026-07-07",
    "reference": "PI-2026/0001"
  },
  "catalog": [
    {
      "stock_id": "AVO-RT-S1",
      "description": "Avocado Retail Size 1",
      "supplier_price": 50.0,
      "qoh_at_location": 40.0
    }
  ]
}
```

### 10.2 Submit

```http
POST /api/purchasing/invoices

{
  "supplier_id": 1,
  "supplier_ref": "RUTO-INV-2026-0042",
  "location": "DEF",
  "document_date": "2026-07-07",
  "lines": [
    {
      "stock_id": "AVO-RT-S1",
      "quantity": 50,
      "unit_price": 50.0
    }
  ]
}
```

**Required:**

| Field | Notes |
|-------|-------|
| `supplier_id` | From picker |
| `supplier_ref` | **Supplier’s own invoice number** — unique per supplier |
| `location` | Where stock is received |
| `lines` | At least one line |

`unit_price` optional if FA has purchasing data (defaults via `get_purchase_price`).

### 10.3 UI notes

- Always show a field **“Supplier invoice #”** mapped to `supplier_ref`.
- Duplicate `supplier_ref` for same supplier → **422**.
- Scanning flow: supplier → lines → supplier ref → submit.

---

## 11. Screen: Inventory adjustment

**FA:** stock increase/decrease (wastage, stocktake, spoilage).

### 11.1 Load form

```http
GET /api/inventory/adjustments/prefill?location=DEF
```

Catalog includes `qoh` and `material_cost` per item.

### 11.2 Submit

```http
POST /api/inventory/adjustments

{
  "location": "DEF",
  "document_date": "2026-07-07",
  "memo": "3 avocados spoiled",
  "lines": [
    {
      "stock_id": "AVO-RT-S1",
      "quantity": -3,
      "standard_cost": 50.0
    }
  ]
}
```

**Quantity sign:**

| Sign | Effect |
|------|--------|
| `> 0` | Stock increase |
| `< 0` | Stock decrease (wastage) |
| `0` | Rejected |

`standard_cost` optional — defaults to item `material_cost`.

---

## 12. Dynamic line entry

When the user adds a line **after** prefill (barcode scan, search), avoid reloading the full catalog:

```http
GET /api/items/AVO-RT-S1/context?customer_id=1&location=DEF
GET /api/items/AVO-RT-S1/context?supplier_id=1&location=DEF
```

**Response:**

```json
{
  "stock_id": "AVO-RT-S1",
  "description": "Avocado Retail Size 1",
  "units": "each",
  "material_cost": 50.0,
  "qoh": 42.0,
  "unit_price": 75.0,
  "currency": "KES",
  "sales_type_id": 1,
  "supplier_price": 50.0
}
```

Include `customer_id` **or** `supplier_id` depending on screen.

---

## 13. Error handling

### 13.1 Error shape

```json
{
  "error": {
    "code": "validation_failed",
    "message": "Human-readable summary",
    "fields": {
      "supplier_ref": "Duplicate for this supplier"
    }
  }
}
```

`fields` only on validation errors (422).

### 13.2 Status codes

| Code | Meaning | App action |
|------|---------|------------|
| 200 / 201 | Success | Continue |
| 204 | Success, no body | Continue |
| 401 | Unauthorized | Re-login |
| 404 | Not found | Show “not found” |
| 409 | Duplicate reference | Suggest new reference / retry prefill |
| 422 | Validation | Show `message` + field errors |
| 500 | Server error | Show generic error, log details |

### 13.3 Common validation failures

| Message | Fix |
|---------|-----|
| Unknown `customer_id` | Refresh customer list |
| `supplier_ref` required | Add supplier invoice number field |
| Duplicate `supplier_ref` | User must enter unique supplier ref |
| `quantity` must be > 0 | Validate before submit |
| No exchange rate | FA admin: add currency rate for date |
| Insufficient stock | Reduce quantity or allow negative stock in FA |

---

## 14. Optional: shifts & checklists

Legacy AVO'Gs operational endpoints (shadow tables, not FA GL):

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/shifts/current` | Active shift for store |
| POST | `/shifts/open` | Open shift |
| POST | `/shifts/{id}/close` | Close shift |
| GET | `/checklists/{mode}` | Checklist template (`morning-open`, `evening-open`, …) |
| GET | `/deliveries`, `/supplies`, `/expenses`, `/wastage` | Store ops logs |
| POST | `/uploads` | Multipart photo upload (`file` field) |
| GET | `/reports/sales-trend?days=7` | Chart data from shadow sales table |

Wire these only if the Flutter app still uses shift/checklist screens.

---

## 15. Testing & QA

### Automated smoke test (backend)

```bash
python3 api/tools/test_all_endpoints.py
```

Expect **68/68 pass** against local `:8090` with user `apiuser`.

### Manual Swagger

1. Open `http://localhost:8090/api/docs/`
2. **Authorize** with token from `POST /auth/login`
3. Try prefill endpoints first, then POST

### Frontend checklist

- [ ] Login stores token; 401 clears session
- [ ] POS uses `/sales/invoices/prefill` not `/sales/orders/prefill`
- [ ] Supplier screen collects `supplier_ref`
- [ ] Prices shown match `unit_price` from prefill catalog
- [ ] Submit disabled while request in flight
- [ ] Receipt screen calls `GET /sales/invoices/{id}`

---

## 16. Route reference

### Auth

| Method | Path |
|--------|------|
| POST | `/auth/login` |
| POST | `/auth/logout` |

### Master data (GET, read-only)

| Path |
|------|
| `/` |
| `/stores` |
| `/sales-types` |
| `/customers`, `/customers/{id}`, `/customers/{id}/prices` |
| `/suppliers`, `/suppliers/{id}`, `/suppliers/{id}/prices` |
| `/items`, `/items/{stock_id}`, `/items/{stock_id}/context` |
| `/prices`, `/purchasing-data` |
| `/catalog` (legacy grouped list) |
| `/payment-methods` |

### Retail transactions

| Method | Path |
|--------|------|
| GET | `/sales/invoices/prefill` |
| POST | `/sales/invoices` |
| GET | `/sales/invoices/{id}` |
| GET | `/sales/payments/prefill` |
| POST | `/sales/payments` |
| GET | `/sales/payments/{id}` |
| GET | `/purchasing/invoices/prefill` |
| POST | `/purchasing/invoices` |
| GET | `/purchasing/invoices/{id}` |
| GET | `/inventory/adjustments/prefill` |
| POST | `/inventory/adjustments` |
| GET | `/inventory/adjustments/{id}` |

---

## End-to-end example: POS sale

```text
1. POST /auth/login
2. GET  /sales/invoices/prefill?customer_id=1&location=DEF
3. User adds lines from catalog (or GET /items/{id}/context on scan)
4. POST /sales/invoices  { customer_id, location, lines }
5. GET  /sales/invoices/{invoice_no}  → show receipt
```

## End-to-end example: Receive stock from supplier

```text
1. GET /suppliers
2. GET /purchasing/invoices/prefill?supplier_id=1&location=DEF
3. User enters lines + supplier invoice number
4. POST /purchasing/invoices  { supplier_id, supplier_ref, location, lines }
5. GET /purchasing/invoices/{invoice_no}
```

---

**Questions?** Use Swagger at `/api/docs/` or run the smoke test. For FA business rules (tax, stock negative, fiscal year), behaviour matches the FA desktop screens listed above.
