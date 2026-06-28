# AVO'Gs Mobile App — API Endpoints to Develop

This document specifies the REST endpoints the **AVO'Gs Flutter app** needs from the
FrontAccounting (FA) backend. It is derived from the app's in-memory data models
(`lib/domain/`) and state operations (`lib/state/app_store.dart`), which are currently
mocked and need to be backed by real FA data.

The app is **UI-first**: every endpoint below already has a corresponding screen and a
`// TODO: wire to FrontAccounting` marker in the client. The shapes below match what the
client already builds/consumes, so wiring is mostly a matter of standing these up.

---

## 1. Conventions

| Item | Value |
|------|-------|
| Base URL | `https://<fa-host>/api/v1` (suggested — a custom FA REST module under `modules/`) |
| Format | JSON request + response (`Content-Type: application/json`) |
| Auth | `Authorization: Bearer <token>` on every endpoint except `POST /auth/login` |
| Money | All amounts are **integer KSh** (no decimals) to match the client |
| Dates | ISO‑8601 (`2026-06-28T09:15:00+03:00`); business day key is `yyyy-MM-dd` |
| Shift key | `"morning"` or `"evening"` |
| Errors | `{ "error": { "code": "string", "message": "human readable" } }` with proper HTTP status |

### Standard error codes
`unauthorized` (401), `forbidden` (403), `not_found` (404), `validation_error` (422),
`stock_unavailable` (409), `server_error` (500).

### FA mapping note
Where relevant, each section notes the underlying FrontAccounting module/tables so the
implementer can reuse existing FA business logic (`sales/`, `purchasing/`, `inventory/`,
`gl/`) rather than writing raw SQL.

---

## 2. Authentication & Session

The app does first-time provisioning with an email/username + password, then sets a local
4-digit PIN. The PIN unlocks the app on each launch; the server must validate credentials
on provisioning and (optionally) on each shift login.

### `POST /auth/login`
First-time device provisioning / credential validation.

Request:
```json
{ "identifier": "store.manager@avogs.co.ke", "password": "••••••••" }
```
Response `200`:
```json
{
  "token": "jwt-or-opaque-token",
  "user": { "id": 12, "name": "Jane M.", "role": "attendant" },
  "allowed_stores": ["Kahawa Wendani", "Juja", "TRM"]
}
```
> The client stores the token; the PIN is hashed locally and never sent. If you want
> server-side PIN validation per session, add `POST /auth/pin/verify` returning `{ "valid": true }`.

### `POST /auth/logout`
Invalidate the current token. Response `204`.

---

## 3. Reference Data (catalog / customers / stores)

These currently live in `lib/domain/catalog.dart` as hard-coded constants and must come
from FA.

### `GET /stores`
List of store locations (FA dimensions or branches).
```json
[
  { "code": "kahawa-wendani", "name": "Kahawa Wendani" },
  { "code": "juja", "name": "Juja" },
  { "code": "trm", "name": "TRM" }
]
```

### `GET /customers`
Predefined debtors. **`CASH SALES` (id 1) is the default walk-in customer.**
FA mapping: `debtors_master` + `cust_branch`.
```json
[
  { "id": 1, "branch_id": 1, "name": "CASH SALES" },
  { "id": 2, "branch_id": 1, "name": "Mama Mboga Grocers" },
  { "id": 3, "branch_id": 1, "name": "Greenfield Hotel" }
]
```

### `GET /catalog`
All sellable items with FA `stock_id`s, grouped exactly as the client expects.
FA mapping: `stock_master` (+ `prices`). Groups used by the client: `retail`, `wholesale`,
`honey`, `beverage`. `index` is the item's position within its group (used for inventory mapping).

```json
[
  { "stock_id": "AVO-RT-S3", "name": "Retail Avocado S3", "group": "retail", "index": 2, "price": 30, "unit": "pc" },
  { "stock_id": "AVO-WS-S2", "name": "Wholesale Avocado S2", "group": "wholesale", "index": 1, "price": 20, "unit": "pc" },
  { "stock_id": "HNY-250G", "name": "Honey 250g", "group": "honey", "index": 0, "price": 250, "unit": "jar" },
  { "stock_id": "BVG-JUICE", "name": "Fresh Juice 300ml", "group": "beverage", "index": 0, "price": 70, "unit": "btl" }
]
```

### `GET /payment-methods`
```json
["Cash", "M-Pesa", "Card", "Credit"]
```

---

## 4. Inventory / Stock Availability

The POS blocks overselling using `availableFor(item)` — opening stock minus what's already
sold this shift minus wastage. Avocado (retail+wholesale) is **one shared pool of pieces**.

### `GET /inventory?store={code}&date={yyyy-MM-dd}&shift={morning|evening}`
Current sellable quantities per item for the active shift.
FA mapping: `stock_moves` aggregated to on-hand per location.
```json
{
  "store": "trm",
  "date": "2026-06-28",
  "shift": "morning",
  "avocado_pool": 100,
  "items": [
    { "stock_id": "HNY-250G", "available": 10 },
    { "stock_id": "BVG-JUICE", "available": 20 }
  ]
}
```

---

## 5. Shifts, Checklists & Handover

The app runs an **opening** checklist (confirm stock, confirm cash, shop-setup photos,
calls/orders notes) and a **closing** checklist (count remaining stock, set tomorrow's
float/till, expenses, etc.). The closing counts become the next shift's opening
("handover").

### `GET /checklists/{mode}`
Returns the checklist template. `mode` ∈ `morning-open`, `evening-open`, `morning-close`,
`evening-close`. Lets the templates be backend-driven instead of hard-coded in
`lib/domain/checklists.dart`.
```json
{
  "mode": "morning-open",
  "title": "Morning Opening",
  "sub": "Confirm stock & cash, then set up the shop",
  "sections": [
    { "title": "Opening Stock Confirmation", "items": [ { "label": "Confirm Opening Stock", "special": "stock" } ] },
    { "title": "Cash Confirmation", "items": [ { "label": "Confirm Opening Cash", "special": "cash" } ] },
    { "title": "Shop Setup", "items": [
      { "label": "Arrived & Opened shop on time" },
      { "label": "Made morning calls and deliveries", "input": { "type": "text", "placeholder": "Note the calls made" } }
    ] }
  ]
}
```

### `GET /shifts/current?store={code}`
Returns the in-progress shift (so re-login resumes without redoing the opening checklist),
plus the **expected** handover figures the opening checklist validates against.
```json
{
  "active": true,
  "shift_id": 8842,
  "shift": "morning",
  "store": "trm",
  "opened_at": "2026-06-28T07:02:00+03:00",
  "expected": {
    "avocado": 100, "till": 2000, "float": 500,
    "juice": 20, "smoothie": 10, "ginger": 10,
    "honey_250": 10, "honey_450": 8, "honey_900": 5
  }
}
```

### `POST /shifts/open`
Submit the opening checklist. Records confirmed opening stock/cash, flags discrepancies
(tolerances: **avocado ±10 pcs, cash ±KSh 100**), stores notes, and attaches photo ids
(see §9).
```json
{
  "store": "trm",
  "shift": "morning",
  "opening_stock": 98,
  "opening_till": 2000,
  "opening_float": 500,
  "stock_discrepancy": true,
  "cash_discrepancy": false,
  "notes": { "Made morning calls and deliveries": "Called Greenfield, 120pcs at noon" },
  "photo_ids": ["upl_8f1c", "upl_77ab"]
}
```
Response `201`: `{ "shift_id": 8842, "status": "open" }`

### `POST /shifts/{shift_id}/close`
Submit the closing checklist. The `handover` values seed the next shift's expected stock
(`applyClosingHandover` keys: `avo`, `till`, `float`, `juice`, `smoothie`, `ginger`,
`h250`, `h450`, `h900`).
```json
{
  "handover": { "avo": 12, "till": 0, "float": 1500, "juice": 6, "smoothie": 4, "ginger": 5, "h250": 8, "h450": 6, "h900": 4 },
  "cash_counted": 9400,
  "notes": { "Left handover notes for evening shift": "Fridge #2 acting up" },
  "photo_ids": []
}
```
Response `200`: `{ "shift_id": 8842, "status": "closed" }`

---

## 6. Sales (POS)

The cart is committed as a FrontAccounting **direct sales invoice**. The client already
produces the exact payload via `SaleInvoice.toFaInvoice()`.

### `POST /sales/invoices`
FA mapping: `sales/includes/sales_order_ui.inc` / direct-invoice flow → `debtor_trans`,
`debtor_trans_details`, `stock_moves`.
Request (as built by the client):
```json
{
  "customer_id": 1,
  "branch_id": 1,
  "reference": "INV-260628-004",
  "trans_date": "2026-06-28T11:20:00+03:00",
  "payment": "m-pesa",
  "payment_terms": "cash",
  "sales_type_id": 1,
  "shift": "morning",
  "items": [
    { "stock_id": "AVO-RT-S3", "description": "Retail Avocado S3", "quantity": 10, "unit_price": 30, "discount_percent": 0 },
    { "stock_id": "BVG-JUICE", "description": "Fresh Juice 300ml", "quantity": 2, "unit_price": 70, "discount_percent": 0 }
  ],
  "comments": "Walk-in cash sale"
}
```
Response `201`:
```json
{ "invoice_no": 5521, "reference": "INV-260628-004", "total": 440, "trans_date": "2026-06-28T11:20:00+03:00" }
```
- Validate stock availability server-side; return `409 stock_unavailable` with the offending `stock_id`s if oversold.
- Server should own the authoritative `reference` (the client's `INV-yyMMdd-NNN` is provisional).

### `GET /sales/invoices?store={code}&date={yyyy-MM-dd}&shift={shift}`
Today's sales list (most recent first) for the Sales tab.
```json
[
  { "invoice_no": 5521, "reference": "INV-260628-004", "customer": "CASH SALES", "payment_method": "M-Pesa",
    "shift": "morning", "time": "2026-06-28T11:20:00+03:00", "units": 12, "discount": 0, "total": 440,
    "lines": [ { "stock_id": "AVO-RT-S3", "name": "Retail Avocado S3", "qty": 10, "unit_price": 30, "discount": 0 } ] }
]
```

### `GET /sales/summary?store={code}&date={yyyy-MM-dd}`
Aggregates for dashboard/finance/closing (matches `dayTotals` / `shiftTotals` /
`dayBeverageSplit`). All values integer KSh; quantities are counts.
```json
{
  "date": "2026-06-28",
  "day": { "total": 12400, "units": 210, "retail": 5400, "wholesale": 4200, "honey": 1200, "beverage": 1600 },
  "shift": { "morning": { "total": 8000, "units": 140 }, "evening": { "total": 4400, "units": 70 } },
  "beverage_split": { "juice": 900, "smoothie": 500, "ginger": 200 },
  "discount_total": 150
}
```

---

## 7. Operations (Deliveries & Supplies)

### Deliveries — `GET / POST / DELETE /deliveries`
Wholesale/retail deliveries to customers. FA mapping: sales orders / delivery notes.
`POST` body:
```json
{ "customer": "Greenfield Hotel", "location": "Juja Town", "type": "Wholesale Avocado",
  "qdesc": "120 pcs", "amount": 3600, "pay": "partial", "time": "2026-06-28T09:15:00+03:00" }
```
`pay` ∈ `pending | partial | full`. `GET` returns the list; `DELETE /deliveries/{id}` removes one.

### Supplies — `GET / POST / DELETE /supplies`
Incoming stock / farmer supply. FA mapping: `purchasing/` (purchase orders / GRN).
`POST` body:
```json
{ "type": "avocado", "qty": 500, "desc": "Morning delivery from farm", "income": 18000, "date": "2026-06-28" }
```
`type` ∈ `avocado | honey | juice | smoothie | ginger`.

---

## 8. Finance (Expenses) & Wastage

### Expenses — `GET / POST / DELETE /expenses`
FA mapping: `gl/` payments / `gl_trans`.
`POST` body:
```json
{ "category": "Transport", "amount": 300, "desc": "Boda delivery to Juja", "time": "2026-06-28T11:05:00+03:00" }
```
Net cash on the dashboard = `sales day total − total expenses` (client-computed; no endpoint needed).

### Wastage — `GET / POST / DELETE /wastage`
Spoilage / write-offs. Subtracts from avocado availability. FA mapping: inventory
adjustments (`stock_moves` negative / `inventory/` adjustment).
`POST` body:
```json
{ "product": "Avocado", "qty": 4, "reason": "Overripe", "duration": "3 days", "loss": 120, "time": "2026-06-28T12:20:00+03:00" }
```
`reason` ∈ `Overripe | Damaged | Rejected | Expired | Dead stock | Other`.

---

## 9. Media Uploads (checklist photos)

Opening checklist steps capture photos (shop opened, juice station, shelves).

### `POST /uploads`
`multipart/form-data` with a `file` field (JPEG). Response `201`:
```json
{ "upload_id": "upl_8f1c", "url": "https://<fa-host>/uploads/upl_8f1c.jpg" }
```
The returned `upload_id`s are referenced in `POST /shifts/open` / `close`.

---

## 10. Reports

### `GET /reports/sales-trend?store={code}&days=7`
Per-day series for the Reports line graph (Avocado / Honey / Beverages lines) and the
dashboard 7-day chart.
```json
{
  "days": [
    { "date": "2026-06-22", "total": 9800, "avocado": 7200, "honey": 1000, "beverage": 1600 },
    { "date": "2026-06-23", "total": 11200, "avocado": 8400, "honey": 1200, "beverage": 1600 }
  ]
}
```

---

## 11. Endpoint Summary

| # | Method | Path | Purpose | FA module |
|---|--------|------|---------|-----------|
| 1 | POST | `/auth/login` | Provision / validate credentials | access |
| 2 | POST | `/auth/logout` | Invalidate token | access |
| 3 | GET | `/stores` | Store locations | dimensions/company |
| 4 | GET | `/customers` | Debtors (default CASH SALES) | sales |
| 5 | GET | `/catalog` | Sellable items + stock_ids | inventory |
| 6 | GET | `/payment-methods` | Payment options | sales |
| 7 | GET | `/inventory` | Sellable quantities per item | inventory |
| 8 | GET | `/checklists/{mode}` | Checklist templates | (custom) |
| 9 | GET | `/shifts/current` | Resume in-progress shift + expected | (custom) |
| 10 | POST | `/shifts/open` | Submit opening checklist | (custom) |
| 11 | POST | `/shifts/{id}/close` | Submit closing checklist + handover | (custom) |
| 12 | POST | `/sales/invoices` | Create direct sales invoice | sales |
| 13 | GET | `/sales/invoices` | List shift/day sales | sales |
| 14 | GET | `/sales/summary` | Aggregated totals | sales/reporting |
| 15 | GET/POST/DELETE | `/deliveries` | Deliveries | sales |
| 16 | GET/POST/DELETE | `/supplies` | Incoming supply | purchasing |
| 17 | GET/POST/DELETE | `/expenses` | Expenses | gl |
| 18 | GET/POST/DELETE | `/wastage` | Spoilage / write-offs | inventory |
| 19 | POST | `/uploads` | Checklist photos | (custom) |
| 20 | GET | `/reports/sales-trend` | Trend series | reporting |

---

## 12. Implementation Notes for FrontAccounting

- Expose these under a custom module (`modules/avogs_api/`) that bootstraps FA's session/DB
  layer, then dispatches to existing business functions (`add_direct_invoice`,
  `write_customer_delivery`, `add_supp_invoice`, `add_gl_trans`, stock adjustment helpers)
  so accounting stays consistent — avoid raw SQL where an FA function exists.
- Token auth: issue an opaque token mapped to an FA user, or front the API with a small
  JWT layer. Map app `store` → FA location/dimension; scope all queries by it.
- Shifts & checklists have **no native FA table** — add a small custom schema
  (`avogs_shifts`, `avogs_shift_checklist`, `avogs_handover`) under `modules/avogs_api/sql/`.
- The avocado **shared-pool** rule and discrepancy **tolerances** (±10 pcs / ±KSh 100) are
  business rules the server should enforce, not just the client.
