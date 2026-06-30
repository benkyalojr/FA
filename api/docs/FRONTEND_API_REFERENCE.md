# AVO'Gs Transaction API — Frontend Reference

**Version:** Phase 2  
**Base URL:** `https://<host>/api`  
**Updated:** 2026-06-30

This document covers every endpoint the frontend needs to create, read, and fulfil the seven FrontAccounting transaction types. Each document type follows the same three-step pattern:

1. **Prefill** `GET …/prefill` — fetch defaults, prices, and stock before the user builds the form.
2. **Commit** `POST …` — submit the completed document.
3. **Consume** `GET …/{id}` — read back the saved document for display or sync.

---

## Contents

1. [Authentication](#1-authentication)
2. [Common conventions](#2-common-conventions)
3. [Error format](#3-error-format)
4. [Sales order](#4-sales-order)
5. [Sales invoice (direct)](#5-sales-invoice-direct)
6. [Sales delivery (direct)](#6-sales-delivery-direct)
7. [Deliver from an existing order](#7-deliver-from-an-existing-order)
8. [Purchase order](#8-purchase-order)
9. [Location transfer](#9-location-transfer)
10. [Inventory adjustment](#10-inventory-adjustment)
11. [Customer payment](#11-customer-payment)
12. [Item context lookup](#12-item-context-lookup)
13. [End-to-end workflow examples](#13-end-to-end-workflow-examples)

---

## 1. Authentication

All endpoints require a bearer token. Obtain one at login:

```
POST /api/auth/login
Content-Type: application/json

{
  "identifier": "apiuser",
  "password": "apiuser"
}
```

**Response**
```json
{
  "token": "51dcc99b45977f7c473fa3d3a21b07f7c826c2df",
  "user": { "id": 3, "login": "apiuser", "name": "API User", "role_id": 2 },
  "allowed_stores": ["Default", "Juja", "Kahawa Sukari", "..."]
}
```

Include the token on every subsequent request:

```
Authorization: Bearer eyJ...
```

Token is valid until the user logs out or the session expires.

---

## 2. Common conventions

### Date format
All dates are `YYYY-MM-DD` strings. Document dates must fall within the currently open fiscal year.

### Monetary values
All amounts are in the customer's or supplier's currency (typically `KSh`). No currency conversion is applied by the frontend.

### Discount
`discount_percent` is a percentage (e.g. `10` = 10 %). The backend converts it to a factor internally.

### Pagination
Single-document GET endpoints return one object. List endpoints return arrays directly.

### `trans_type` constants

| Value | Document type |
|------:|--------------|
| 10 | Sales invoice |
| 12 | Customer payment |
| 13 | Delivery note |
| 16 | Location transfer |
| 17 | Inventory adjustment |
| 18 | Purchase order |
| 30 | Sales order |

---

## 3. Error format

All errors use the same JSON shape:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "Human-readable summary",
    "fields": {
      "lines[0].quantity": "Must be > 0",
      "customer_id": "Not found"
    }
  }
}
```

| HTTP status | `code` | Meaning |
|------------|--------|---------|
| `400` | `bad_request` | Malformed request body |
| `401` | `unauthorized` | Missing or expired token |
| `403` | `forbidden` | User lacks the required FA permission |
| `404` | `not_found` | Document ID does not exist |
| `409` | `conflict` | Duplicate reference number |
| `422` | `validation_failed` | Business-rule failure (see `fields`) |
| `500` | `server_error` | Unexpected server error |

---

## 4. Sales order

A sales order reserves stock for future delivery. It **does not** move inventory.

### 4.1 Prefill

```
GET /api/sales/orders/prefill
Authorization: Bearer <token>
```

**Query parameters**

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `customer_id` | int | 1 | Customer to load defaults for |
| `branch_id` | int | first branch | Customer branch |
| `location` | string | branch default | Stock location for QOH display |
| `date` | YYYY-MM-DD | today | Document date |

**Response `200`**
```json
{
  "trans_type": 30,
  "defaults": {
    "customer_id": 1,
    "branch_id": 1,
    "sales_type": 1,
    "sales_type_name": "Retail",
    "currency": "KSh",
    "default_discount_percent": 0,
    "payment_terms": 1,
    "location": "DEF",
    "document_date": "2026-06-30",
    "delivery_date": "2026-06-30",
    "reference": "SO-2026-00042",
    "deliver_to": "Cash Customer",
    "delivery_address": "Nairobi",
    "ship_via": 1,
    "freight_cost": 0
  },
  "catalog": [
    {
      "stock_id": "AVO-RT-S1",
      "description": "Hass Avocado S1",
      "units": "each",
      "mb_flag": "B",
      "unit_price": 120,
      "qoh": 450,
      "is_kit": false
    }
  ]
}
```

> Use `defaults` to pre-fill the form. Use `catalog` to build the item picker. `qoh` here is informational only — orders do not enforce stock availability.

---

### 4.2 Commit

```
POST /api/sales/orders
Authorization: Bearer <token>
Content-Type: application/json
```

**Request body**
```json
{
  "customer_id": 1,
  "branch_id": 1,
  "reference": "SO-2026-00042",
  "document_date": "2026-06-30",
  "delivery_date": "2026-07-02",
  "location": "DEF",
  "payment_terms": 1,
  "ship_via": 1,
  "deliver_to": "Cash Customer",
  "delivery_address": "Nairobi",
  "freight_cost": 0,
  "comments": "",
  "cust_ref": "",
  "prep_amount": 0,
  "lines": [
    {
      "stock_id": "AVO-RT-S1",
      "quantity": 10,
      "unit_price": 120,
      "discount_percent": 0,
      "description": ""
    }
  ]
}
```

**Required fields:** `customer_id`, `lines` (at least one entry with `stock_id` and `quantity > 0`).  
`reference` — if omitted the server auto-generates one.

**Response `201 Created`**
```json
{
  "order_no": 1042,
  "trans_type": 30,
  "reference": "SO-2026-00042",
  "customer_id": 1,
  "total": 1200.00,
  "lines": [
    {
      "stock_id": "AVO-RT-S1",
      "quantity": 10,
      "unit_price": 120,
      "discount_percent": 0,
      "line_total": 1200.00
    }
  ],
  "fa_tables": { "header": "sales_orders", "details": "sales_order_details" }
}
```

Store `order_no` — you will need it to create deliveries against this order.

---

### 4.3 Consume

```
GET /api/sales/orders/{order_no}
Authorization: Bearer <token>
```

**Response `200`**
```json
{
  "order_no": 1042,
  "trans_type": 30,
  "reference": "SO-2026-00042",
  "customer_id": 1,
  "branch_id": 1,
  "location": "DEF",
  "document_date": "2026-06-30",
  "delivery_date": "2026-07-02",
  "deliver_to": "Cash Customer",
  "delivery_address": "Nairobi",
  "freight_cost": 0,
  "comments": "",
  "lines": [
    {
      "stock_id": "AVO-RT-S1",
      "description": "Hass Avocado S1",
      "quantity": 10,
      "qty_sent": 0,
      "unit_price": 120,
      "discount_percent": 0,
      "line_total": 1200.00
    }
  ]
}
```

`qty_sent` shows how many units have already been delivered against this order.

---

## 5. Sales invoice (direct)

A direct invoice **creates stock movements and posts to GL** in one step. It auto-creates a parent sales order and delivery note behind the scenes.

> **Phase 2 note:** `POST /api/sales/invoices` now writes to the real FA tables (`debtor_trans`, `stock_moves`, GL). The legacy shadow-table behaviour has been replaced.

### Document chain (created automatically)
```
POST /api/sales/invoices
  → auto sales order   (reference "auto")
  → auto delivery note (stock out)
  → sales invoice      (debtor_trans + GL)
```

### 5.1 Prefill

```
GET /api/sales/invoices/prefill
Authorization: Bearer <token>
```

Same query parameters as [sales order prefill](#41-prefill).

**Additional fields in response**
```json
{
  "defaults": {
    "due_date": "2026-07-30",
    "dimension_id": 0,
    "dimension2_id": 0,
    ...
  },
  "catalog": [
    {
      "stock_id": "AVO-RT-S1",
      "qoh": 450,
      "low_stock": false,
      ...
    }
  ]
}
```

`low_stock: true` means `qoh ≤ 0`. Warn the user before committing.

---

### 5.2 Commit

```
POST /api/sales/invoices
Authorization: Bearer <token>
Content-Type: application/json
```

**Request body** — same structure as sales order, plus:
```json
{
  "due_date": "2026-07-30",
  "dimension_id": 0,
  "dimension2_id": 0,
  ...
  "lines": [
    {
      "stock_id": "AVO-RT-S1",
      "quantity": 5,
      "unit_price": 120,
      "discount_percent": 0
    }
  ]
}
```

**Validations the server enforces:**
- `document_date` must be in the open fiscal year.
- At least one line with `quantity > 0`.
- Reference must be unique.
- `quantity` must not exceed available QOH when negative stock is disallowed.

**Response `201 Created`**
```json
{
  "invoice_no": 558,
  "trans_type": 10,
  "reference": "INV-2026-00123",
  "auto_created": {
    "sales_order_no": 1043,
    "delivery_no": 412
  },
  "total": 600.00,
  "tax": 0,
  "gl_posted": true,
  "stock_moves": [
    { "stock_id": "AVO-RT-S1", "location": "DEF", "qty": -5, "standard_cost": 75 }
  ],
  "lines": [ ... ]
}
```

---

### 5.3 Consume

```
GET /api/sales/invoices/{invoice_no}
Authorization: Bearer <token>
```

**Response `200`**
```json
{
  "invoice_no": 558,
  "trans_type": 10,
  "reference": "INV-2026-00123",
  "customer_id": 1,
  "branch_id": 1,
  "document_date": "2026-06-30",
  "due_date": "2026-07-30",
  "amount": 600.00,
  "tax": 0,
  "freight": 0,
  "alloc": 0,
  "sales_order": 1043,
  "currency": "KSh",
  "lines": [
    {
      "stock_id": "AVO-RT-S1",
      "description": "Hass Avocado S1",
      "units": "each",
      "quantity": 5,
      "unit_price": 120,
      "discount_percent": 0,
      "line_total": 600.00
    }
  ],
  "taxes": [],
  "stock_moves": [ ... ]
}
```

`alloc` shows how much of this invoice has been paid. When `alloc == amount` the invoice is fully settled.

---

## 6. Sales delivery (direct)

A direct delivery **moves stock out** without raising an AR invoice. Useful for fulfilment-before-invoice workflows.

### Document chain
```
POST /api/sales/deliveries
  → auto sales order   (reference "auto")
  → delivery note      (stock_moves only, no GL invoice)
```

### 6.1 Prefill

```
GET /api/sales/deliveries/prefill
Authorization: Bearer <token>
```

Same query parameters and response shape as invoice prefill. The `low_stock` flag on each catalog item is shown because stock is enforced at delivery time.

---

### 6.2 Commit

```
POST /api/sales/deliveries
Authorization: Bearer <token>
Content-Type: application/json
```

Request body is identical to the sales invoice commit.

**Response `201 Created`**
```json
{
  "delivery_no": 413,
  "trans_type": 13,
  "reference": "DN-2026-00088",
  "auto_created": { "sales_order_no": 1044 },
  "stock_moves": [
    { "stock_id": "AVO-RT-S1", "location": "DEF", "qty": -5, "standard_cost": 75 }
  ],
  "lines": [ ... ]
}
```

---

### 6.3 Consume

```
GET /api/sales/deliveries/{delivery_no}
Authorization: Bearer <token>
```

**Response `200`**
```json
{
  "delivery_no": 413,
  "trans_type": 13,
  "reference": "DN-2026-00088",
  "customer_id": 1,
  "branch_id": 1,
  "document_date": "2026-06-30",
  "sales_order": 1044,
  "currency": "KSh",
  "lines": [
    {
      "stock_id": "AVO-RT-S1",
      "description": "Hass Avocado S1",
      "units": "each",
      "quantity": 5,
      "qty_invoiced": 0,
      "unit_price": 120,
      "discount_percent": 0,
      "line_total": 600.00
    }
  ],
  "stock_moves": [ ... ]
}
```

`qty_invoiced` shows how many units on this delivery have subsequently been invoiced.

---

## 7. Deliver from an existing order

When a sales order already exists, deliver against it partially or fully.

```
POST /api/sales/orders/{order_no}/deliveries
Authorization: Bearer <token>
Content-Type: application/json
```

**Request body**
```json
{
  "document_date": "2026-07-02",
  "reference": "DN-2026-00089",
  "comments": "Partial delivery",
  "lines": [
    { "stock_id": "AVO-RT-S1", "quantity": 4 }
  ]
}
```

Only include the lines you want to dispatch in this delivery. Unspecified lines keep their existing `qty_dispatched`. The server looks up the parent order and dispatches the quantities you provide.

**Response `201 Created`**
```json
{
  "delivery_no": 414,
  "trans_type": 13,
  "reference": "DN-2026-00089",
  "order_no": 1042,
  "stock_moves": [
    { "stock_id": "AVO-RT-S1", "location": "DEF", "qty": -4, "standard_cost": 75 }
  ]
}
```

---

## 8. Purchase order

A purchase order records a commitment to buy from a supplier. Stock is **not** moved at this stage; it moves when the goods are received (GRN).

### 8.1 Prefill

```
GET /api/purchasing/orders/prefill
Authorization: Bearer <token>
```

**Query parameters**

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `supplier_id` | int | first active | Supplier |
| `location` | string | first location | Receive-into location |
| `date` | YYYY-MM-DD | today | Document date |

**Response `200`**
```json
{
  "trans_type": 18,
  "defaults": {
    "supplier_id": 3,
    "supplier_name": "Farm Fresh Ltd",
    "currency": "KSh",
    "location": "DEF",
    "document_date": "2026-06-30",
    "reference": "PO-2026-00015",
    "delivery_address": "Warehouse, Nairobi",
    "tax_included": false,
    "dimension_id": 0,
    "dimension2_id": 0
  },
  "catalog": [
    {
      "stock_id": "AVO-RT-S1",
      "description": "Hass Avocado S1",
      "units": "each",
      "supplier_price": 80,
      "qoh_at_location": 450
    }
  ]
}
```

---

### 8.2 Commit

```
POST /api/purchasing/orders
Authorization: Bearer <token>
Content-Type: application/json
```

**Request body**
```json
{
  "supplier_id": 3,
  "reference": "PO-2026-00015",
  "document_date": "2026-06-30",
  "location": "DEF",
  "delivery_address": "Warehouse, Nairobi",
  "comments": "",
  "supplier_ref": "",
  "dimension_id": 0,
  "dimension2_id": 0,
  "prep_amount": 0,
  "lines": [
    {
      "stock_id": "AVO-RT-S1",
      "quantity": 100,
      "unit_price": 80,
      "required_delivery_date": "2026-07-05",
      "description": ""
    }
  ]
}
```

**Response `201 Created`**
```json
{
  "order_no": 215,
  "trans_type": 18,
  "reference": "PO-2026-00015",
  "supplier_id": 3,
  "total": 8000.00,
  "lines": [
    {
      "stock_id": "AVO-RT-S1",
      "description": "Hass Avocado S1",
      "quantity": 100,
      "unit_price": 80,
      "required_delivery_date": "2026-07-05",
      "line_total": 8000.00
    }
  ]
}
```

---

### 8.3 Consume

```
GET /api/purchasing/orders/{order_no}
Authorization: Bearer <token>
```

**Response `200`**
```json
{
  "order_no": 215,
  "trans_type": 18,
  "reference": "PO-2026-00015",
  "supplier_id": 3,
  "supplier_name": "Farm Fresh Ltd",
  "currency": "KSh",
  "document_date": "2026-06-30",
  "location": "DEF",
  "delivery_address": "Warehouse, Nairobi",
  "comments": "",
  "total": 8000.00,
  "lines": [
    {
      "stock_id": "AVO-RT-S1",
      "description": "Hass Avocado S1",
      "quantity_ordered": 100,
      "qty_received": 0,
      "qty_invoiced": 0,
      "unit_price": 80,
      "required_delivery_date": "2026-07-05",
      "line_total": 8000.00
    }
  ]
}
```

`qty_received` and `qty_invoiced` track fulfilment progress after GRN.

---

## 9. Location transfer

Move stock between two warehouse locations. Two stock_moves are created per line (debit source, credit destination). No GL posting.

### 9.1 Prefill

```
GET /api/inventory/transfers/prefill
Authorization: Bearer <token>
```

**Query parameters**

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `from_location` | string | first location | Source location |
| `to_location` | string | first location | Destination location |
| `date` | YYYY-MM-DD | today | Document date |

**Response `200`**
```json
{
  "trans_type": 16,
  "defaults": {
    "from_location": "DEF",
    "to_location": "SHOP2",
    "document_date": "2026-06-30",
    "reference": "TR-2026-00007"
  },
  "locations": [
    { "code": "DEF",   "name": "Main Warehouse" },
    { "code": "SHOP2", "name": "Shop 2" }
  ],
  "catalog": [
    {
      "stock_id": "AVO-RT-S1",
      "description": "Hass Avocado S1",
      "units": "each",
      "qoh_at_source": 450
    }
  ]
}
```

`qoh_at_source` is QOH at the **source** location only. The user cannot transfer more than `qoh_at_source` (when negative stock is disallowed).

---

### 9.2 Commit

```
POST /api/inventory/transfers
Authorization: Bearer <token>
Content-Type: application/json
```

**Request body**
```json
{
  "from_location": "DEF",
  "to_location": "SHOP2",
  "reference": "TR-2026-00007",
  "document_date": "2026-06-30",
  "memo": "Morning replenishment",
  "lines": [
    { "stock_id": "AVO-RT-S1", "quantity": 50 }
  ]
}
```

**Validation rules:**
- `from_location` ≠ `to_location`
- `quantity > 0` per line
- `quantity ≤ qoh_at_source` when negative stock is disallowed

**Response `201 Created`**
```json
{
  "transfer_no": 77,
  "trans_type": 16,
  "reference": "TR-2026-00007",
  "from_location": "DEF",
  "to_location": "SHOP2",
  "document_date": "2026-06-30",
  "stock_moves": [
    { "stock_id": "AVO-RT-S1", "location": "DEF",   "qty": -50, "standard_cost": 0 },
    { "stock_id": "AVO-RT-S1", "location": "SHOP2", "qty":  50, "standard_cost": 0 }
  ],
  "lines": [
    { "stock_id": "AVO-RT-S1", "quantity": 50 }
  ]
}
```

---

### 9.3 Consume

```
GET /api/inventory/transfers/{transfer_no}
Authorization: Bearer <token>
```

**Response `200`**
```json
{
  "transfer_no": 77,
  "trans_type": 16,
  "reference": "TR-2026-00007",
  "from_location": "DEF",
  "to_location": "SHOP2",
  "document_date": "2026-06-30",
  "lines": [
    { "stock_id": "AVO-RT-S1", "from_location": "DEF", "quantity": 50 }
  ],
  "stock_moves": [ ... ]
}
```

---

## 10. Inventory adjustment

Correct stock counts for spoilage, stock counts, or write-offs. Quantity can be positive (increase) or negative (decrease). GL is posted using the item's standard cost.

> **Wastage use-case:** Submit a negative adjustment to record spoilt/wasted inventory. (Phase 3 will expose this as `POST /api/wastage` too.)

### 10.1 Prefill

```
GET /api/inventory/adjustments/prefill
Authorization: Bearer <token>
```

**Query parameters**

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `location` | string | first location | Location to adjust |
| `date` | YYYY-MM-DD | today | Document date |

**Response `200`**
```json
{
  "trans_type": 17,
  "defaults": {
    "location": "DEF",
    "document_date": "2026-06-30",
    "reference": "ADJ-2026-00003"
  },
  "catalog": [
    {
      "stock_id": "AVO-RT-S1",
      "description": "Hass Avocado S1",
      "units": "each",
      "qoh": 450,
      "material_cost": 75
    }
  ]
}
```

Pre-fill `standard_cost` from `material_cost` for negative adjustments.

---

### 10.2 Commit

```
POST /api/inventory/adjustments
Authorization: Bearer <token>
Content-Type: application/json
```

**Request body**
```json
{
  "location": "DEF",
  "reference": "ADJ-2026-00003",
  "document_date": "2026-06-30",
  "memo": "Stock count variance",
  "lines": [
    {
      "stock_id": "AVO-RT-S1",
      "quantity": -3,
      "standard_cost": 75
    }
  ]
}
```

| `quantity` | Meaning |
|-----------|---------|
| `> 0` | Stock increase |
| `< 0` | Stock decrease |
| `= 0` | **Rejected** |

`standard_cost` is optional — omit it and the server defaults to the item's `material_cost`.

**Response `201 Created`**
```json
{
  "adjustment_no": 33,
  "trans_type": 17,
  "reference": "ADJ-2026-00003",
  "location": "DEF",
  "document_date": "2026-06-30",
  "gl_posted": true,
  "stock_moves": [
    { "stock_id": "AVO-RT-S1", "location": "DEF", "qty": -3, "standard_cost": 75 }
  ],
  "lines": [ ... ]
}
```

---

### 10.3 Consume

```
GET /api/inventory/adjustments/{adjustment_no}
Authorization: Bearer <token>
```

**Response `200`**
```json
{
  "adjustment_no": 33,
  "trans_type": 17,
  "reference": "ADJ-2026-00003",
  "location": "DEF",
  "document_date": "2026-06-30",
  "stock_moves": [
    { "stock_id": "AVO-RT-S1", "location": "DEF", "qty": -3, "standard_cost": 75 }
  ],
  "gl_summary": [
    { "account": "1200", "account_name": "Inventory", "amount": -225.00 },
    { "account": "5100", "account_name": "Stock Adjustment", "amount": 225.00 }
  ]
}
```

---

## 11. Customer payment

Record cash, M-Pesa, or bank receipts and allocate them to outstanding invoices.

### 11.1 Prefill

```
GET /api/sales/payments/prefill
Authorization: Bearer <token>
```

**Query parameters**

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `customer_id` | int | first customer | Customer |
| `branch_id` | int | first branch | Branch |
| `bank_account` | int | first account | Bank/cash account to receive into |
| `date` | YYYY-MM-DD | today | Document date |

**Response `200`**
```json
{
  "trans_type": 12,
  "defaults": {
    "customer_id": 5,
    "branch_id": 5,
    "currency": "KSh",
    "document_date": "2026-06-30",
    "reference": "RCP-2026-00100",
    "bank_account": 1,
    "dimension_id": 0,
    "dimension2_id": 0
  },
  "open_documents": [
    {
      "trans_type": 10,
      "trans_no": 558,
      "reference": "INV-2026-00123",
      "document_date": "2026-06-28",
      "due_date": "2026-07-28",
      "amount": 600.00,
      "allocated": 0,
      "balance": 600.00
    }
  ],
  "bank_accounts": [
    { "id": 1, "name": "Cash",   "currency": "KSh" },
    { "id": 2, "name": "M-Pesa", "currency": "KSh" }
  ]
}
```

`open_documents` lists every outstanding invoice and credit for this customer. Show these to the user so they can allocate the payment.

---

### 11.2 Commit

```
POST /api/sales/payments
Authorization: Bearer <token>
Content-Type: application/json
```

**Request body**
```json
{
  "customer_id": 5,
  "branch_id": 5,
  "bank_account": 1,
  "reference": "RCP-2026-00100",
  "document_date": "2026-06-30",
  "amount": 600.00,
  "discount": 0,
  "bank_charge": 0,
  "bank_amount": 600.00,
  "memo": "M-Pesa #QA123456",
  "dimension_id": 0,
  "dimension2_id": 0,
  "allocations": [
    { "trans_type": 10, "trans_no": 558, "amount": 600.00 }
  ]
}
```

**Fields**

| Field | Required | Notes |
|-------|----------|-------|
| `customer_id` | Yes | |
| `bank_account` | Yes | ID from `bank_accounts` in prefill |
| `amount` | Yes | Total received in customer currency |
| `bank_amount` | No | Amount in bank currency (defaults to `amount`) |
| `discount` | No | Early-payment discount in customer currency |
| `bank_charge` | No | Bank fee deducted from receipt |
| `allocations` | No | List of invoices/credits to allocate against. Each entry: `trans_type`, `trans_no`, `amount` |

To leave a payment **unallocated** (e.g. an advance), send `"allocations": []` or omit the field.

**Response `201 Created`**
```json
{
  "payment_no": 901,
  "trans_type": 12,
  "reference": "RCP-2026-00100",
  "customer_id": 5,
  "amount": 600.00,
  "discount": 0,
  "bank_charge": 0,
  "gl_posted": true,
  "bank_trans_no": 901,
  "allocations": [
    { "trans_type": 10, "trans_no": 558, "allocated": 600.00 }
  ]
}
```

---

### 11.3 Consume

```
GET /api/sales/payments/{payment_no}
Authorization: Bearer <token>
```

**Response `200`**
```json
{
  "payment_no": 901,
  "trans_type": 12,
  "reference": "RCP-2026-00100",
  "customer_id": 5,
  "branch_id": 5,
  "document_date": "2026-06-30",
  "amount": 600.00,
  "discount": 0,
  "currency": "KSh",
  "alloc": 600.00,
  "bank_trans_no": 901,
  "bank_currency": "KSh",
  "allocations": [
    {
      "trans_type": 10,
      "trans_no": 558,
      "allocated": 600.00,
      "reference": "INV-2026-00123",
      "date": "2026-06-28"
    }
  ],
  "gl_summary": [ ... ]
}
```

---

## 12. Item context lookup

Fetch price and QOH for a single item without reloading the full catalog. Useful when the user adds a line dynamically.

```
GET /api/items/{stock_id}/context
Authorization: Bearer <token>
```

**Query parameters**

| Param | Type | Notes |
|-------|------|-------|
| `customer_id` | int | Include to get selling price |
| `supplier_id` | int | Include to get purchase price |
| `location` | string | Include to get QOH |
| `date` | YYYY-MM-DD | Defaults to today |

**Example**
```
GET /api/items/AVO-RT-S1/context?customer_id=1&location=DEF&date=2026-06-30
```

**Response `200`**
```json
{
  "stock_id": "AVO-RT-S1",
  "description": "Hass Avocado S1",
  "units": "each",
  "material_cost": 75,
  "qoh": 450,
  "unit_price": 120
}
```

When `supplier_id` is also passed, a `supplier_price` field is included.

---

## 13. End-to-end workflow examples

### Workflow A — Cash sale at the shop

```
1. GET /api/sales/invoices/prefill?customer_id=1&location=DEF
   → pre-fill form with defaults and catalog

2. User picks items and quantities.

3. POST /api/sales/invoices
   { customer_id, location, lines, payment_terms, ... }
   → invoice_no: 558

4. POST /api/sales/payments
   { customer_id, bank_account: 1, amount: 600, allocations: [{ trans_type: 10, trans_no: 558, amount: 600 }] }
   → payment_no: 901, invoice fully settled

5. GET /api/sales/invoices/558   ← optional sync/display
```

---

### Workflow B — Order → partial delivery → invoice

```
1. GET /api/sales/orders/prefill?customer_id=5
2. POST /api/sales/orders { lines: [{ stock_id: "AVO-RT-S1", quantity: 20 }] }
   → order_no: 1042

3. (Next day) POST /api/sales/orders/1042/deliveries
   { lines: [{ stock_id: "AVO-RT-S1", quantity: 10 }] }
   → delivery_no: 414  (partial)

4. POST /api/sales/invoices { ... lines same 10 qty ... }
   → invoice_no: 559

5. Repeat step 3–4 for remaining 10 units.
```

---

### Workflow C — Stock replenishment

```
1. GET /api/purchasing/orders/prefill?supplier_id=3&location=DEF
2. POST /api/purchasing/orders { lines: [{ stock_id: "AVO-RT-S1", quantity: 100, unit_price: 80 }] }
   → order_no: 215
   (GRN receive happens separately in FA desktop)

3. GET /api/inventory/transfers/prefill?from_location=DEF&to_location=SHOP2
4. POST /api/inventory/transfers
   { from_location: "DEF", to_location: "SHOP2", lines: [{ stock_id: "AVO-RT-S1", quantity: 50 }] }
   → transfer_no: 77
```

---

### Workflow D — Record wastage / spoilage

```
1. GET /api/inventory/adjustments/prefill?location=DEF
2. POST /api/inventory/adjustments
   {
     location: "DEF",
     memo: "3 overripe avocados discarded",
     lines: [{ stock_id: "AVO-RT-S1", quantity: -3, standard_cost: 75 }]
   }
   → adjustment_no: 33, gl_posted: true
```

---

## Quick reference — all routes

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/sales/orders/prefill` | Sales order form defaults |
| `POST` | `/sales/orders` | Create sales order |
| `GET` | `/sales/orders/{id}` | Read sales order |
| `POST` | `/sales/orders/{id}/deliveries` | Fulfil from existing SO |
| `GET` | `/sales/invoices/prefill` | Invoice form defaults |
| `POST` | `/sales/invoices` | Create direct invoice |
| `GET` | `/sales/invoices/{id}` | Read invoice |
| `GET` | `/sales/deliveries/prefill` | Delivery form defaults |
| `POST` | `/sales/deliveries` | Create direct delivery |
| `GET` | `/sales/deliveries/{id}` | Read delivery note |
| `GET` | `/sales/payments/prefill` | Payment form defaults + open invoices |
| `POST` | `/sales/payments` | Record customer payment |
| `GET` | `/sales/payments/{id}` | Read payment |
| `GET` | `/purchasing/orders/prefill` | PO form defaults |
| `POST` | `/purchasing/orders` | Create purchase order |
| `GET` | `/purchasing/orders/{id}` | Read purchase order |
| `GET` | `/inventory/transfers/prefill` | Transfer form defaults |
| `POST` | `/inventory/transfers` | Create location transfer |
| `GET` | `/inventory/transfers/{id}` | Read transfer |
| `GET` | `/inventory/adjustments/prefill` | Adjustment form defaults |
| `POST` | `/inventory/adjustments` | Create inventory adjustment |
| `GET` | `/inventory/adjustments/{id}` | Read adjustment |
| `GET` | `/items/{stock_id}/context` | Single-item price + QOH |

---

*Questions? Contact the backend team or raise an issue in the project repository.*
