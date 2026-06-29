# AVO'Gs API — Mobile Integration Handover

**Audience:** AVO'Gs mobile app team
**Status:** Phase 1 (functional, FA-backed reference data + app data store). Sales/expenses/wastage are persisted in dedicated tables; GL/stock posting into FrontAccounting core is a documented Phase 2 hook.
**Last updated:** 2026-06-28

---

## 1. Overview

The API is a small REST layer that runs **inside FrontAccounting (FA)**. On every request it bootstraps FA headlessly, so it has FA's database connection and business functions available.

- **Reference data** (stores, customers, catalog) is read **live from FA's own tables**.
- **App data** (shifts, checklists, sales, deliveries, supplies, expenses, wastage, uploads) is stored in custom `0_avogs_*` tables.
- Several write endpoints contain a **`PHASE 2 HOOK`** comment marking where the data will additionally be posted into FA core (invoices → `debtor_trans`/GL, expenses → GL, wastage → `stock_moves`). The API contract below will **not change** when those hooks are implemented.

All amounts are **integer Kenyan Shillings (KSh)** — no decimals, no cents.

---

## 2. Base URL & environments

| Environment | Base URL |
|---|---|
| Local dev | `http://localhost:8080/api` |

- All endpoint paths below are **relative to the base URL**, e.g. `POST /auth/login` → `http://localhost:8080/api/auth/login`.
- The API tolerates an optional `/index.php` segment and a trailing slash; `/api/stores`, `/api/index.php/stores`, and `/api/stores/` all resolve identically.
- Dev note: under the PHP built-in server the API is served via `api/router.php`; under Apache via `api/.htaccess`. The mobile team only needs the base URL.

---

## 3. Conventions

- **Content type:** requests with a body send `Content-Type: application/json`. Responses are always `application/json; charset=utf-8`. (The one exception is file upload — see §9.)
- **CORS:** enabled for all origins (`Access-Control-Allow-Origin: *`).
- **Money:** integers, KSh. e.g. `"total": 18650` means KSh 18,650.
- **Dates:** `YYYY-MM-DD`. **Timestamps:** ISO-ish `YYYY-MM-DDTHH:MM:SS` (local server time, Africa/Nairobi).
- **Store selection:** most endpoints act on a single store. Resolution order:
  1. `store` query param or body field (FA location code, e.g. `DEF`, `TRM`, `JUJA`)
  2. the store bound to the auth token at login
  3. the first active FA location as a fallback
- **Shift:** `morning` or `evening` (see §10 for managing definitions).

---

## 4. Authentication

Token-based (Bearer). Credentials are validated against FA's users; tokens live in a custom table with a **30-day TTL**.

### `POST /auth/login`
```json
{ "identifier": "admin", "password": "password", "store": "TRM" }
```
- `identifier` — FA login **or** email. `store` is optional (binds the token to a store).

**200**
```json
{
  "token": "f3a9...c1",
  "user": { "id": 1, "login": "admin", "name": "Administrator", "role_id": 2 },
  "allowed_stores": ["Juja", "Kahawa Sukari", "TRM", "..."]
}
```
- **401** `invalid credentials`, **422** if fields missing.

### Authenticated requests
Send the token on every other endpoint:
```
Authorization: Bearer <token>
```
Missing/expired → **401** `{"error":{"code":"unauthorized","message":"..."}}`.

### `POST /auth/logout`
Invalidates the current token. Returns **204** (no body).

---

## 5. Error format

Non-2xx responses use a consistent envelope:
```json
{ "error": { "code": "validation_error", "message": "At least one line item is required." } }
```
| HTTP | code |
|---|---|
| 400 | `bad_request` |
| 401 | `unauthorized` |
| 403 | `forbidden` |
| 404 | `not_found` |
| 409 | `conflict` |
| 422 | `validation_error` |
| 500 | `server_error` |

---

## 6. Reference data

All require `Authorization`.

### `GET /stores`
```json
[ { "code": "TRM", "name": "TRM" }, { "code": "JUJA", "name": "Juja" } ]
```

### `GET /customers`
Default walk-in customer is **CASH SALES** (`id: 1`).
```json
[ { "id": 1, "branch_id": 1, "name": "CASH SALES" } ]
```

### `GET /catalog`
```json
[
  { "stock_id": "AVO-RT-S1", "name": "Avocado Retail S1", "group": "retail", "index": 0, "price": 50, "unit": "pc" },
  { "stock_id": "HNY-250G",  "name": "Honey 250g",        "group": "honey",  "index": 0, "price": 350, "unit": "jar" },
  { "stock_id": "BVG-JUICE", "name": "Avocado Juice",     "group": "beverage","index": 0, "price": 150, "unit": "btl" }
]
```
- `group` ∈ `retail | wholesale | honey | beverage | other`; ordered by group then index. See §11 for the `stock_id` scheme.

### `GET /payment-methods`
```json
[ "Cash", "M-Pesa", "Card", "Credit" ]
```

### `GET /shifts/definitions`
Shift types managed in FA (see §10). Drives the app's shift options.
```json
[
  { "key": "morning", "name": "Morning Shift", "start": "07:00", "end": "14:00" },
  { "key": "evening", "name": "Evening Shift", "start": "14:00", "end": "21:00" }
]
```

---

## 7. Shifts & checklists

### `GET /checklists/{mode}`
`mode` ∈ `morning-open | evening-open | morning-close | evening-close`.
```json
{
  "title": "Morning Opening",
  "sub": "Confirm stock & cash, then set up the shop",
  "mode": "morning-open",
  "sections": [
    { "title": "Opening Stock Confirmation",
      "items": [ { "label": "Confirm Opening Stock", "special": "stock" } ] },
    { "title": "Shop Setup",
      "items": [
        { "label": "Arrived & Opened shop on time" },
        { "label": "Made morning calls and deliveries",
          "input": { "type": "text", "placeholder": "Note the calls made & deliveries planned" } }
      ] }
  ]
}
```
**Item contract:**
- `special`: `stock` / `cash` (morning) or `stock-ev` / `cash-ev` (evening) → the app renders its stock/cash confirmation widget, pre-filled from `GET /shifts/current`.`expected`.
- `input.type`: `text` | `number` | `auto` (`auto` = app fills it from computed sales, read-only).
- Items with neither `special` nor `input` are simple checkbox confirmations (some prompt for a photo in-app).
- **404** `Unknown checklist mode`.

### `GET /shifts/current?store=TRM`
Whether a shift is open, plus the **expected** opening figures (from the previous shift's handover, else system defaults).
```json
{
  "active": true,
  "shift_id": 12,
  "shift": "morning",
  "store": "TRM",
  "opened_at": "2026-06-28T07:28:00",
  "expected": {
    "avocado": 210, "till": 2000, "float": 3000,
    "juice": 14, "smoothie": 9, "ginger": 22,
    "honey_250": 6, "honey_450": 4, "honey_900": 3
  }
}
```
- When no shift is open, `active=false`, `shift_id=null`, and `shift` is inferred from the clock (morning before 14:00, else evening).

### `POST /shifts/open`
```json
{
  "store": "TRM", "shift": "morning",
  "opening_stock": 320, "opening_till": 2000, "opening_float": 3000,
  "stock_discrepancy": false, "cash_discrepancy": false,
  "notes": { "calls": "Called 3 regulars", "pending": "1 wholesale order" },
  "photo_ids": ["upl_ab12cd", "upl_ef34gh"]
}
```
- `notes` is a free-form object (checklist text inputs keyed however the app likes).
- `photo_ids` are ids returned by `POST /uploads`.

**201** `{ "shift_id": 13, "status": "open" }`

### `POST /shifts/{id}/close`
```json
{
  "cash_counted": 18650,
  "notes": { "wastage": "5 overripe" },
  "photo_ids": [],
  "handover": {
    "avo": 210, "till": 2000, "float": 3000,
    "juice": 14, "smoothie": 9, "ginger": 22,
    "h250": 6, "h450": 4, "h900": 3
  }
}
```
- `handover` becomes the **next** shift's `expected` figures (morning→evening, evening→morning).

**200** `{ "shift_id": 13, "status": "closed" }` — **404** if the shift id doesn't exist.

> The opening data feeds the FA **"Shift Check-in (Opening) Inquiry"** and the closing/handover data feeds the **"Shift Check-out (Closing) Inquiry"** under *Items & Inventory* in FA.

---

## 8. Sales

### `POST /sales/invoices`
```json
{
  "store": "TRM", "shift": "morning", "customer_id": 1,
  "payment": "Cash", "comments": "",
  "items": [
    { "stock_id": "AVO-RT-S1", "quantity": 4, "unit_price": 50, "discount": 0 },
    { "stock_id": "BVG-JUICE", "quantity": 2, "discount_percent": 10 }
  ]
}
```
- `unit_price` optional (defaults to catalog price). Discount per line is either `discount` (absolute KSh) **or** `discount_percent` (%). `reference` optional (auto-generated `INV-YYMMDD-NNN`).
- Unknown `stock_id` or empty `items` → **422**.

**201**
```json
{ "invoice_no": 41, "reference": "INV-260628-001", "total": 470, "trans_date": "2026-06-28T11:02:00" }
```

### `GET /sales/invoices?store=TRM&date=2026-06-28&shift=morning`
`date` defaults to today; `shift` optional. Most recent first.
```json
[
  {
    "invoice_no": 41, "reference": "INV-260628-001",
    "customer": "CASH SALES", "payment_method": "Cash", "shift": "morning",
    "time": "2026-06-28T11:02:00", "units": 6, "discount": 30, "total": 470,
    "lines": [ { "stock_id": "AVO-RT-S1", "name": "Avocado Retail S1", "qty": 4, "unit_price": 50, "discount": 0 } ]
  }
]
```

### `GET /sales/summary?store=TRM&date=2026-06-28`
```json
{
  "date": "2026-06-28",
  "day": { "total": 18650, "units": 240, "retail": 9000, "wholesale": 6000, "honey": 2150, "beverage": 1500 },
  "shift": { "morning": { "total": 18650, "units": 240 }, "evening": { "total": 0, "units": 0 } },
  "beverage_split": { "juice": 900, "smoothie": 450, "ginger": 150 },
  "discount_total": 320
}
```

---

## 9. Operations, finance & uploads

All list endpoints are scoped to the resolved store, newest first. Creates return `201 { "id": N }`; deletes return `204`.

### Deliveries — `GET/POST /deliveries`, `DELETE /deliveries/{id}`
POST body: `{ "customer", "location", "type", "qdesc", "amount", "pay" }` (`pay` e.g. `pending`/`paid`).
List item: `{ "id", "customer", "location", "type", "qdesc", "amount", "pay", "time" }`.

### Supplies — `GET/POST /supplies`, `DELETE /supplies/{id}`
POST body: `{ "type", "qty", "desc", "income", "date" }` (`date` defaults today).
List item: `{ "id", "type", "qty", "desc", "income", "date" }`.

### Expenses — `GET/POST /expenses`, `DELETE /expenses/{id}`
POST body: `{ "category", "amount", "desc" }`.
List item: `{ "id", "category", "amount", "desc", "time" }`.

### Wastage — `GET/POST /wastage`, `DELETE /wastage/{id}`
POST body: `{ "product", "qty", "reason", "duration", "loss" }`.
List item: `{ "id", "product", "qty", "reason", "duration", "loss", "time" }`.
> Wastage with product matching "avocado" reduces the available avocado pool in `GET /inventory`.

### `POST /uploads` (multipart)
Send `multipart/form-data` with a single file field named **`file`** (checklist photos).
**201** `{ "upload_id": "upl_ab12cd", "url": "http://localhost:8080/api/storage/upl_ab12cd.jpg" }`
- Use the returned `upload_id` in `photo_ids` on shift open/close. **422** if the `file` field is missing.

---

## 10. Inventory & reports

### `GET /inventory?store=TRM&date=2026-06-28&shift=morning`
Computed availability = expected opening (from handover) − sold this shift − avocado wastage today.
```json
{
  "store": "TRM", "date": "2026-06-28", "shift": "morning",
  "avocado_pool": 174,
  "items": [
    { "stock_id": "BVG-JUICE", "available": 12 },
    { "stock_id": "HNY-250G", "available": 6 }
  ]
}
```
- `avocado_pool` is the shared piece count behind all retail/wholesale avocado SKUs; beverages/honey are tracked per `stock_id`.

### `GET /reports/sales-trend?store=TRM&days=7`
`days` 1–60 (default 7). Continuous series (zero-filled).
```json
{ "days": [ { "date": "2026-06-22", "total": 0, "avocado": 0, "honey": 0, "beverage": 0 } ] }
```

---

## 11. Data dictionary

**`stock_id` scheme** (drives catalog grouping & report categories):
| Prefix | Group | Example | Unit |
|---|---|---|---|
| `AVO-RT-S{1..7}` | retail avocado | `AVO-RT-S3` | pc |
| `AVO-WS-S{1..7}` | wholesale avocado | `AVO-WS-S2` | pc |
| `HNY-{250G,450G,900G}` | honey | `HNY-450G` | jar |
| `BVG-{JUICE,SMOOTHIE,GINGER}` | beverage | `BVG-SMOOTHIE` | btl |

**Report category** (`/reports/sales-trend`, `/sales/summary`): `AVO-*`→avocado, `HNY-*`→honey, `BVG-*`→beverage, else other.

**Enums:** `shift` = `morning|evening` · `mode` = `{morning,evening}-{open,close}` · payment = `Cash|M-Pesa|Card|Credit`.

---

## 12. Quick start (curl)

```bash
BASE=http://localhost:8080/api

# 1. login
TOKEN=$(curl -s $BASE/auth/login -H 'Content-Type: application/json' \
  -d '{"identifier":"admin","password":"password","store":"TRM"}' | jq -r .token)

# 2. reference data
curl -s $BASE/catalog -H "Authorization: Bearer $TOKEN" | jq .

# 3. record a sale
curl -s $BASE/sales/invoices -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"store":"TRM","shift":"morning","customer_id":1,"payment":"Cash",
       "items":[{"stock_id":"AVO-RT-S1","quantity":4}]}' | jq .

# 4. today's summary
curl -s "$BASE/sales/summary?store=TRM" -H "Authorization: Bearer $TOKEN" | jq .
```

---

## 13. Endpoint index

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/auth/login` | – | Get a token |
| POST | `/auth/logout` | ✓ | Invalidate token |
| GET | `/stores` | ✓ | FA locations |
| GET | `/customers` | ✓ | FA debtors (default CASH SALES) |
| GET | `/catalog` | ✓ | Sellable items + price |
| GET | `/payment-methods` | ✓ | Payment options |
| GET | `/shifts/definitions` | ✓ | Shift types |
| GET | `/checklists/{mode}` | ✓ | Checklist template |
| GET | `/shifts/current` | ✓ | Open shift + expected figures |
| POST | `/shifts/open` | ✓ | Start a shift |
| POST | `/shifts/{id}/close` | ✓ | Close a shift + handover |
| POST | `/sales/invoices` | ✓ | Create sale |
| GET | `/sales/invoices` | ✓ | List sales |
| GET | `/sales/summary` | ✓ | Daily/shift totals |
| GET | `/inventory` | ✓ | Computed availability |
| GET/POST | `/deliveries` · DELETE `/deliveries/{id}` | ✓ | Deliveries |
| GET/POST | `/supplies` · DELETE `/supplies/{id}` | ✓ | Supplies (GRN) |
| GET/POST | `/expenses` · DELETE `/expenses/{id}` | ✓ | Expenses |
| GET/POST | `/wastage` · DELETE `/wastage/{id}` | ✓ | Wastage |
| POST | `/uploads` | ✓ | Upload a photo |
| GET | `/reports/sales-trend` | ✓ | Sales trend series |

---

## 14. Notes for production (Phase 2)

- **GL/stock posting:** sales, expenses, and wastage currently persist to `0_avogs_*` tables only. The `PHASE 2 HOOK` comments mark where they will also post into FA core (`write_sales_invoice`, GL quick entries, `stock_moves`). API responses won't change.
- **Service account:** the API logs into FA as `admin` (see `api/config.php`). Provision a dedicated, least-privilege FA user before go-live.
- **Token store:** tokens are opaque strings in `0_avogs_api_tokens` with a 30-day expiry. There is currently no refresh endpoint — re-login on 401.
- **Store scoping:** binding the token to a store at login is recommended so clients don't have to pass `store` on every call.
- **HTTPS:** terminate TLS in front of FA for any non-local deployment.
