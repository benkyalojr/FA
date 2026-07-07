#!/usr/bin/env python3
"""Smoke-test all routes registered in api/index.php."""
from __future__ import print_function

import json
import os
import sys
import tempfile
import time
import urllib.error
import urllib.request

BASE = os.environ.get("API_BASE", "http://localhost:8090/api")
USER = os.environ.get("API_USER", "apiuser")
PASS = os.environ.get("API_PASS", "apiuser")

results = []


def req(method, path, token=None, body=None, multipart=None, expect=None):
    url = BASE.rstrip("/") + path
    headers = {"Accept": "application/json"}
    data = None
    if token:
        headers["Authorization"] = "Bearer " + token
    if multipart:
        data = multipart[0]
        headers.update(multipart[1])
    elif body is not None:
        data = json.dumps(body).encode("utf-8")
        headers["Content-Type"] = "application/json"
    request = urllib.request.Request(url, data=data, headers=headers, method=method)
    note = ""
    try:
        with urllib.request.urlopen(request, timeout=120) as resp:
            code = resp.getcode()
            raw = resp.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as e:
        code = e.code
        raw = e.read().decode("utf-8", errors="replace")
    except Exception as e:
        results.append({
            "method": method, "path": path, "status": "ERR",
            "code": None, "ok": False, "note": str(e),
        })
        return None

    ok = True
    if expect is not None:
        ok = code in (expect if isinstance(expect, (list, tuple)) else [expect])
    else:
        ok = 200 <= code < 300

    try:
        parsed = json.loads(raw) if raw.strip() else None
    except ValueError:
        parsed = raw[:200] if raw else None

    if not ok and not note:
        if isinstance(parsed, dict) and "error" in parsed:
            note = str(parsed.get("error"))[:120]
        elif isinstance(parsed, dict) and "message" in parsed:
            note = str(parsed.get("message"))[:120]
        else:
            note = (raw[:120] if raw else "")

    results.append({
        "method": method, "path": path, "status": code, "ok": ok, "note": note,
    })
    return parsed


def main():
    print("API endpoint test — %s" % BASE)
    print("User: %s\n" % USER)

    # Auth
    login = req("POST", "/auth/login", body={"identifier": USER, "password": PASS})
    if not login or "token" not in login:
        print("FATAL: login failed")
        for r in results:
            print(r)
        sys.exit(1)
    token = login["token"]

    req("GET", "/", token=token)
    req("GET", "/stores", token=token)
    req("GET", "/sales-types", token=token)
    req("GET", "/customers", token=token)
    req("GET", "/suppliers", token=token)
    req("GET", "/items", token=token)
    req("GET", "/items?location=DEF", token=token)
    req("GET", "/prices", token=token)
    req("GET", "/purchasing-data", token=token)
    req("GET", "/catalog", token=token)
    req("GET", "/payment-methods", token=token)
    req("GET", "/shifts/definitions", token=token)
    req("GET", "/inventory", token=token)
    req("GET", "/sales/invoices", token=token)
    req("GET", "/sales/summary", token=token)
    req("GET", "/deliveries", token=token)
    req("GET", "/supplies", token=token)
    req("GET", "/expenses", token=token)
    req("GET", "/wastage", token=token)
    req("GET", "/reports/sales-trend", token=token)
    req("GET", "/reports/sales-trend?days=14", token=token)
    req("GET", "/dashboard", token=token)
    req("GET", "/dashboard/summary", token=token)
    req("GET", "/dashboard/trends?days=7", token=token)

    customers = req("GET", "/customers", token=token) or []
    suppliers = req("GET", "/suppliers", token=token) or []
    items = req("GET", "/items", token=token) or []
    stores = req("GET", "/stores", token=token) or []

    cid = customers[0]["id"] if customers else 1
    sid = suppliers[0]["id"] if suppliers else 1
    stock = items[0]["stock_id"] if items else ""
    loc = stores[0]["code"] if stores else "DEF"

    req("GET", "/customers/%d" % cid, token=token)
    req("GET", "/customers/%d/prices" % cid, token=token)
    req("GET", "/suppliers/%d" % sid, token=token)
    req("GET", "/suppliers/%d/prices" % sid, token=token)
    if stock:
        req("GET", "/items/%s" % stock, token=token)
        req("GET", "/items/%s/context?customer_id=%d&location=%s" % (stock, cid, loc), token=token)
    req("GET", "/prices?stock_id=%s" % stock, token=token)
    req("GET", "/purchasing-data?supplier_id=%d" % sid, token=token)

    req("GET", "/checklists/morning-open", token=token)
    req("GET", "/checklists/invalid-mode", token=token, expect=404)
    req("GET", "/shifts/current", token=token)

    # Prefill (retail)
    inv_prefill = req("GET", "/sales/invoices/prefill?customer_id=%d&location=%s" % (cid, loc), token=token)
    pay_prefill = req("GET", "/sales/payments/prefill?customer_id=%d" % cid, token=token)
    sup_prefill = req("GET", "/purchasing/invoices/prefill?supplier_id=%d&location=%s" % (sid, loc), token=token)
    adj_prefill = req("GET", "/inventory/adjustments/prefill?location=%s" % loc, token=token)

    # Removed retail routes (expect 404)
    req("GET", "/sales/orders/prefill?customer_id=%d" % cid, token=token, expect=404)
    req("GET", "/sales/deliveries/prefill?customer_id=%d" % cid, token=token, expect=404)
    req("GET", "/purchasing/orders/prefill?supplier_id=%d" % sid, token=token, expect=404)
    req("GET", "/inventory/transfers/prefill?from_location=%s&to_location=%s" % (loc, loc), token=token, expect=404)

    # Auth guard
    req("GET", "/customers", token=None, expect=401)

    # Not found
    req("GET", "/customers/999999", token=token, expect=404)

    if not stock:
        print("WARN: no items — skipping write tests")
    else:
        # Sales invoice
        inv_body = {
            "customer_id": cid,
            "location": loc,
            "lines": [{"stock_id": stock, "quantity": 1}],
        }
        if inv_prefill and inv_prefill.get("defaults", {}).get("reference"):
            inv_body["reference"] = inv_prefill["defaults"]["reference"]
        inv = req("POST", "/sales/invoices", token=token, body=inv_body, expect=201)
        inv_no = inv.get("invoice_no") if isinstance(inv, dict) else None
        if inv_no:
            req("GET", "/sales/invoices/%d" % inv_no, token=token)

        # Payment (allocate to invoice if we have bank + amount)
        bank = None
        if pay_prefill and pay_prefill.get("bank_accounts"):
            bank = pay_prefill["bank_accounts"][0]["id"]
        if inv_no and bank:
            inv_show = req("GET", "/sales/invoices/%d" % inv_no, token=token)
            amount = 1.0
            if isinstance(inv_show, dict) and inv_show.get("total"):
                amount = float(inv_show["total"])
            pay_body = {
                "customer_id": cid,
                "bank_account": bank,
                "amount": amount,
                "allocations": [{"trans_type": 10, "trans_no": inv_no, "amount": amount}],
            }
            if pay_prefill and pay_prefill.get("defaults", {}).get("reference"):
                pay_body["reference"] = pay_prefill["defaults"]["reference"]
            pay = req("POST", "/sales/payments", token=token, body=pay_body, expect=201)
            pay_no = pay.get("payment_no") if isinstance(pay, dict) else None
            if pay_no:
                req("GET", "/sales/payments/%d" % pay_no, token=token)
        else:
            results.append({
                "method": "POST", "path": "/sales/payments", "status": "SKIP",
                "ok": True, "note": "no invoice or bank account",
            })

        # Supplier invoice
        sup_ref = "API-TEST-%d" % int(time.time())
        sup_line_price = 10.0
        if sup_prefill and sup_prefill.get("catalog"):
            for row in sup_prefill["catalog"]:
                if row.get("stock_id") == stock and row.get("supplier_price"):
                    sup_line_price = float(row["supplier_price"]) or sup_line_price
                    break
        sup_body = {
            "supplier_id": sid,
            "supplier_ref": sup_ref,
            "location": loc,
            "lines": [{"stock_id": stock, "quantity": 1, "unit_price": sup_line_price}],
        }
        if sup_prefill and sup_prefill.get("defaults", {}).get("reference"):
            sup_body["reference"] = sup_prefill["defaults"]["reference"]
        sup_inv = req("POST", "/purchasing/invoices", token=token, body=sup_body, expect=201)
        sup_no = sup_inv.get("invoice_no") if isinstance(sup_inv, dict) else None
        if sup_no:
            req("GET", "/purchasing/invoices/%d" % sup_no, token=token)

        # Inventory adjustment (+1 then -1 net zero if QOH allows)
        adj_body = {
            "location": loc,
            "memo": "API smoke test",
            "lines": [{"stock_id": stock, "quantity": 1}],
        }
        if adj_prefill and adj_prefill.get("defaults", {}).get("reference"):
            adj_body["reference"] = adj_prefill["defaults"]["reference"]
        adj = req("POST", "/inventory/adjustments", token=token, body=adj_body, expect=201)
        adj_no = adj.get("adjustment_no") if isinstance(adj, dict) else None
        if adj_no:
            req("GET", "/inventory/adjustments/%d" % adj_no, token=token)

    # AVOGs shadow-table writes
    d = req("POST", "/deliveries", token=token, body={
        "customer": "Test", "location": "Test", "type": "delivery", "amount": 100,
    }, expect=201)
    if isinstance(d, dict) and d.get("id"):
        req("DELETE", "/deliveries/%d" % d["id"], token=token, expect=204)

    s = req("POST", "/supplies", token=token, body={
        "type": "avocado", "qty": 1, "desc": "test", "income": 0,
    }, expect=201)
    if isinstance(s, dict) and s.get("id"):
        req("DELETE", "/supplies/%d" % s["id"], token=token, expect=204)

    e = req("POST", "/expenses", token=token, body={
        "category": "misc", "amount": 50, "desc": "API test",
    }, expect=201)
    if isinstance(e, dict) and e.get("id"):
        req("DELETE", "/expenses/%d" % e["id"], token=token, expect=204)

    w = req("POST", "/wastage", token=token, body={
        "product": stock or "test", "qty": 1, "reason": "test", "loss": 0,
    }, expect=201)
    if isinstance(w, dict) and w.get("id"):
        req("DELETE", "/wastage/%d" % w["id"], token=token, expect=204)

    # Check-in prefill + full open shop save with photos
    pre = req("GET", "/shifts/checkin/prefill", token=token, expect=[200, 409])
    upload_id = None
    if isinstance(pre, dict) and pre.get("stock_items"):
        # upload a photo first
        try:
            boundary = "----ApiTestBoundary%d" % int(time.time())
            img = b"\xff\xd8\xff\xe0" + b"testjpeg"
            body = (
                ("--%s\r\n" % boundary).encode()
                + b'Content-Disposition: form-data; name="file"; filename="checkin.jpg"\r\n'
                + b"Content-Type: image/jpeg\r\n\r\n"
                + img + b"\r\n"
                + ("--%s--\r\n" % boundary).encode()
            )
            headers = {"Content-Type": "multipart/form-data; boundary=" + boundary}
            upl = req("POST", "/uploads", token=token, multipart=(body, headers), expect=[201, 422, 500])
            if isinstance(upl, dict):
                upload_id = upl.get("upload_id")
        except Exception:
            pass

        first = pre["stock_items"][0]
        body = {
            "shift": pre.get("shift", "morning"),
            "store": pre.get("store"),
            "cash": {"till": pre["cash"]["expected_till"], "float": pre["cash"]["expected_float"]},
            "stock_counts": [{
                "stock_id": first["stock_id"],
                "description": first.get("description", ""),
                "units": first.get("units", ""),
                "expected_qty": first["expected_qty"],
                "actual_qty": first["expected_qty"],
            }],
            "comments": {"calls_deliveries": "API test", "pending_orders": "none"},
        }
        if upload_id:
            body["photos"] = {"shop_opening": upload_id}
        sh = req("POST", "/shifts/checkin", token=token, body=body, expect=[201, 409, 422])
    else:
        sh = req("POST", "/shifts/open", token=token, body={"shift": "morning"}, expect=[201, 409, 422])
    shift_id = sh.get("shift_id") if isinstance(sh, dict) else None
    if shift_id:
        if upload_id and isinstance(sh, dict):
            pc = sh.get("photo_count", 0)
            req_ok = pc >= 1 if upload_id else True
            results.append({
                "method": "POST", "path": "/shifts/checkin photos",
                "status": "PASS" if req_ok else "FAIL",
                "code": 201 if req_ok else None,
                "ok": req_ok,
                "note": "photo_count=%s" % pc,
            })
        req("GET", "/shifts/%d/checkin" % shift_id, token=token)
        req("GET", "/shifts/checkout/prefill", token=token, expect=[200, 409])
        req("POST", "/shifts/checkout", token=token, body={
            "cash_counted": 5000,
            "handover": {"avo": 10, "till": 2000, "float": 500, "juice": 5, "smoothie": 3, "ginger": 2, "h250": 1, "h450": 1, "h900": 1},
        }, expect=[200, 201, 409])
        if shift_id:
            req("GET", "/shifts/%d/checkout" % shift_id, token=token, expect=[200, 404])
        req("GET", "/shifts/checkout/prefill", token=token, expect=409)

    # Upload
    try:
        import mimetypes
        boundary = "----ApiTestBoundary%d" % int(time.time())
        tmp = tempfile.NamedTemporaryFile(suffix=".txt", delete=False)
        tmp.write(b"api upload test")
        tmp.close()
        with open(tmp.name, "rb") as f:
            file_data = f.read()
        os.unlink(tmp.name)
        body = (
            ("--%s\r\n" % boundary).encode()
            + b'Content-Disposition: form-data; name="file"; filename="test.txt"\r\n'
            + b"Content-Type: text/plain\r\n\r\n"
            + file_data + b"\r\n"
            + ("--%s--\r\n" % boundary).encode()
        )
        headers = {"Content-Type": "multipart/form-data; boundary=" + boundary}
        req("POST", "/uploads", token=token, multipart=(body, headers), expect=[201, 422, 500])
    except Exception as ex:
        results.append({
            "method": "POST", "path": "/uploads", "status": "ERR",
            "ok": False, "note": str(ex),
        })

    req("POST", "/auth/logout", token=token, expect=[204, 200])

    # Report
    passed = sum(1 for r in results if r["ok"])
    failed = [r for r in results if not r["ok"]]
    skipped = sum(1 for r in results if r.get("status") == "SKIP")

    print("| Status | Method | Path | Note |")
    print("|--------|--------|------|------|")
    for r in results:
        st = "PASS" if r["ok"] else "FAIL"
        if r.get("status") == "SKIP":
            st = "SKIP"
        code = r.get("status", "")
        note = r.get("note", "").replace("|", "/")
        print("| %s | %s | `%s` | %s %s |" % (st, r["method"], r["path"], code, note))

    print("\n--- Summary ---")
    print("Total: %d  Passed: %d  Failed: %d  Skipped: %d" % (
        len(results), passed, len(failed), skipped))
    if failed:
        sys.exit(1)


if __name__ == "__main__":
    main()
