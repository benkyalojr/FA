# Open Shop (Check-in) — Mobile Integration

Short guide for consuming the check-in API and how photos are stored.

**Base URL:** `http://<host>/api`  
**Auth:** `Authorization: Bearer <token>` on every call (from `POST /auth/login`).

---

## Overview

Open Shop is a **multi-step wizard on the device** with **one save at the end**. Stock counts, cash, photos, and comments are stored for FA inquiries/reports only — they do **not** update FA inventory or GL.

| When | Endpoint |
|------|----------|
| Start | `GET /shifts/current` |
| Load wizard | `GET /shifts/checkin/prefill` |
| Upload each photo | `POST /uploads` |
| Finish Open Shop | `POST /shifts/checkin` |
| Read back | `GET /shifts/{id}/checkin` |

---

## Close Shop (check-out)

| When | Endpoint |
|------|----------|
| Prefill | `GET /shifts/checkout/prefill` |
| Upload photos | `POST /uploads` |
| **Finish Close Shop** | `POST /shifts/checkout` |
| Legacy | `POST /shifts/{shift_id}/close` |
| Read back | `GET /shifts/{id}/checkout` |

`shift_id` in the close body is optional — if omitted, the API uses the open shift for the store (from prefill's `shift_id`).

```json
POST /api/shifts/checkout
{
  "shift_id": 21,
  "cash_counted": 8500,
  "handover": { "avo": 50, "till": 2000, "float": 500, "juice": 8, "smoothie": 4, "ginger": 3, "h250": 2, "h450": 1, "h900": 1 },
  "photos": { "shop_closed": "upl_abc", "cash_count": "upl_def" },
  "comments": { "wastage": "3 overripe", "closing_notes": "Locked up" }
}
```

Response: `{ "success": true, "message": "Shop closed successfully.", "shift_id": 21, ... }`

---

## 1. Check for an open shift

```http
GET /api/shifts/current?store=DEF
Authorization: Bearer <token>
```

```json
{ "active": false, "shift_id": null, "shift": "morning", "store": "DEF" }
```

If `active` is `true`, close the existing shift before starting a new check-in.

---

## 2. Load prefill data

```http
GET /api/shifts/checkin/prefill?store=DEF&shift=morning
Authorization: Bearer <token>
```

Use the response to build the wizard:

| Field | Use |
|-------|-----|
| `stock_items[]` | One row per inventory item. Show `expected_qty`; user enters `actual_qty`. |
| `cash.expected_till` / `expected_float` | Hint values from last handover. User confirms actual till/float. |
| `photo_slots[]` | Three slots: `shop_opening`, `juice_station`, `arrangement`. |
| `comment_fields[]` | `calls_deliveries`, `pending_orders` — free text. |

`expected_qty` is FA quantity-on-hand at the store location at today's date.

---

## 3. Upload photos (before final save)

Photos are uploaded **individually** during the wizard. Each upload is saved immediately to disk and the database; the check-in record only stores the returned **`upload_id`** references.

```http
POST /api/uploads
Authorization: Bearer <token>
Content-Type: multipart/form-data

file=<binary image data>
```

**Request:** single field named `file` (JPEG, PNG, etc.).

**Response `201`:**

```json
{
  "upload_id": "upl_a1b2c3d4e5f6",
  "url": "http://localhost:8090/api/storage/upl_a1b2c3d4e5f6.jpg"
}
```

Keep a map on the device:

```json
{
  "shop_opening": "upl_a1b2c3d4e5f6",
  "juice_station": "upl_…",
  "arrangement": "upl_…"
}
```

Pass this map in the final check-in body as `photos`.

### How images are saved (server-side)

```
App                          API                         Storage
 │                            │                              │
 │  POST /uploads (file)      │                              │
 ├───────────────────────────►│  write api/storage/upl_xxx.jpg
 │                            │  INSERT 0_avogs_uploads       │
 │◄───────────────────────────┤  (upload_id, path, url)      │
 │  { upload_id, url }        │                              │
 │                            │                              │
 │  POST /shifts/checkin      │                              │
 │  { photos: { slot: id } }  │                              │
 ├───────────────────────────►│  INSERT 0_avogs_shifts       │
 │                            │  photos_json = { slot: id }  │
 │◄───────────────────────────┤                              │
```

1. **File on disk** — `api/storage/<upload_id>.<ext>` (configurable via `upload_dir` in `api/config.php`).
2. **Upload registry** — row in `0_avogs_uploads` with `upload_id`, filesystem `path`, public `url`, and `created_at`.
3. **Check-in link** — on `POST /shifts/checkin`, the `photos` object is stored as JSON in `0_avogs_shifts.photos_json` (slot key → `upload_id`). No file is copied again.
4. **Serving** — images are read at `GET /api/storage/<filename>` (static file from `api/storage/`).
5. **FA desktop** — Check-in Inquiry → **View** resolves each `upload_id` via `0_avogs_uploads.url` and shows clickable links.

Photos are optional. Omit a slot or send an empty `photos` object if the user skips images.

---

## 4. Submit Open Shop (single save)

Call this once when the user taps **Open Shop**.

```http
POST /api/shifts/checkin
Authorization: Bearer <token>
Content-Type: application/json
```

```json
{
  "store": "DEF",
  "shift": "morning",
  "cash": {
    "till": 2000,
    "float": 500
  },
  "stock_counts": [
    {
      "stock_id": "AVO-RT-S1",
      "description": "Avocado Retail S1",
      "units": "each",
      "expected_qty": 12,
      "actual_qty": 11
    }
  ],
  "photos": {
    "shop_opening": "upl_a1b2c3d4e5f6",
    "juice_station": "upl_b2c3d4e5f6a1",
    "arrangement": "upl_c3d4e5f6a1b2"
  },
  "comments": {
    "calls_deliveries": "Called supplier, delivery at 2pm",
    "pending_orders": "2 wholesale orders pending pickup"
  }
}
```

**Response `201`:**

```json
{
  "shift_id": 42,
  "status": "open",
  "stock_discrepancy": true,
  "cash_discrepancy": false,
  "stock_counts": [ "..." ],
  "photos": { "..." },
  "comments": { "..." }
}
```

Store `shift_id` for sales and close-shift flows. `stock_discrepancy` / `cash_discrepancy` are set when actual values differ from expected.

---

## 5. Read back a check-in

```http
GET /api/shifts/42/checkin
Authorization: Bearer <token>
```

Returns the same shape as the submit response, including `stock_counts` with `variance` per line.

---

## Minimal client sequence

```text
1. GET  /shifts/current
2. GET  /shifts/checkin/prefill?store=DEF&shift=morning
3. [user fills stock, cash, comments on device]
4. POST /uploads                    (×1 per photo slot)
5. POST /shifts/checkin             (all data + upload_ids)
```

---

## Errors

| Status | Meaning |
|--------|---------|
| `401` | Missing or expired token |
| `409` | Shift already open for this store |
| `422` | Invalid body (e.g. missing `file` on upload) |

---

## FA staff view

**Inventory → Inquiries → Shift Check-in (Opening) Inquiry** — filter by date/store, click **View** for stock lines, comments, and photo links.
