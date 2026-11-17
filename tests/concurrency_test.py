#!/usr/bin/env python3
"""
Two-session HTTP concurrency & E7 live security verification (Assignments 4 & 5).

Uses two completely independent CookieJars (independent PHP sessions) and CSRF
tokens synchronized at a threading.Barrier so concurrent POSTs hit Apache/MySQL
simultaneously without PHP session lock serialization.

Usage:
  python tests/concurrency_test.py http://localhost:8090
"""
from concurrent.futures import ThreadPoolExecutor
import argparse
import html as html_lib
import http.cookiejar
import re
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Session:
    def __init__(self, base: str, name: str):
        self.base = base.rstrip("/")
        self.name = name
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.jar),
            NoRedirect(),
        )

    def request(self, path: str, data=None):
        url = self.base + path
        body_bytes = None
        if data is not None:
            body_bytes = urllib.parse.urlencode(data, doseq=True).encode("utf-8")
        req = urllib.request.Request(url, data=body_bytes, method="POST" if data is not None else "GET")
        try:
            resp = self.opener.open(req, timeout=20)
            return resp.status, dict(resp.headers), resp.read().decode("utf-8", errors="replace")
        except urllib.error.HTTPError as e:
            return e.code, dict(e.headers), e.read().decode("utf-8", errors="replace")

    def get(self, path: str):
        return self.request(path, None)

    def get_csrf(self, path: str) -> tuple[str, str]:
        status, headers, body = self.get(path)
        if status != 200:
            raise AssertionError(f"[{self.name}] GET {path} expected 200, got {status} (loc={headers.get('Location')})")
        m = re.search(r'name="_csrf_token"\s+value="([a-f0-9]+)"', body)
        if not m:
            raise AssertionError(f"[{self.name}] Missing CSRF token on {path}")
        return m.group(1), body

    def post(self, path: str, form: dict, csrf_path: str | None = None, csrf_token: str | None = None):
        token = csrf_token
        if token is None:
            token, _ = self.get_csrf(csrf_path or path)
        payload = dict(form)
        payload["_csrf_token"] = token
        return self.request(path, payload)

    def login(self, username: str, password: str):
        status, headers, body = self.post("/login", {"username": username, "password": password})
        assert status == 302 and headers.get("Location", "").startswith("/dashboard"), (
            f"[{self.name}] login {username} failed: status={status} loc={headers.get('Location')}"
        )


def expect(cond: bool, msg: str):
    if not cond:
        print(f"FAIL: {msg}", file=sys.stderr)
        sys.exit(1)
    print(f"PASS: {msg}")


def extract_id_from_location(headers: dict, pattern: str) -> int:
    loc = headers.get("Location", "")
    m = re.search(pattern, loc)
    if not m:
        raise AssertionError(f"Expected redirect matching {pattern}, got Location={loc!r}")
    return int(m.group(1))


def find_option_id(body: str, select_name: str, label_substr: str) -> int:
    m = re.search(
        rf'<select[^>]*name="{re.escape(select_name)}"[^>]*>(.*?)</select>',
        body,
        flags=re.DOTALL,
    )
    if not m:
        raise AssertionError(f"Select '{select_name}' not found")
    for val, text in re.findall(r'<option[^>]*value="(\d+)"[^>]*>(.*?)</option>', m.group(1), flags=re.DOTALL):
        clean = html_lib.unescape(re.sub(r"<[^>]+>", "", text)).strip()
        if label_substr in clean:
            return int(val)
    raise AssertionError(f"Option '{label_substr}' not found inside select '{select_name}'")


def parse_inventory_balances(body: str, sku: str) -> list[int]:
    # Extract quantity cell right before/after sku row on /inventory
    qtys = []
    for row in re.findall(r"<tr[^>]*>(.*?)</tr>", body, flags=re.DOTALL):
        if sku in row:
            cells = re.findall(r"<td[^>]*>(.*?)</td>", row, flags=re.DOTALL)
            # Column 5 (index 4): <strong>qty</strong> unit
            if len(cells) >= 5:
                m = re.search(r"<strong>([\d,\.\-]+)</strong>", cells[4])
                if m:
                    qtys.append(int(m.group(1).replace(".", "").replace(",", "")))
    return qtys


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("url", nargs="?", default="http://localhost:8090")
    parser.add_argument("--admin-user", default="admin")
    parser.add_argument("--admin-pass", default="admin123")
    args = parser.parse_args()

    tag = f"CONC{int(time.time())}"
    admin = Session(args.url, "admin")
    admin.login(args.admin_user, args.admin_pass)
    print(f"Running 2-session concurrency checks (tag={tag}) against {args.url}")

    # Discover role & permission IDs via HTTP UI
    _, access_html = admin.get_csrf("/access?tab=permissions")
    stock_manager_role_id = find_option_id(access_html, "role_id", "STOCK_MANAGER")

    def find_perm_id(code: str) -> int:
        m = re.search(rf"<small>{re.escape(code)}</small>.*?overrides\[(\d+)\]", access_html, flags=re.DOTALL)
        if not m:
            raise AssertionError(f"Permission {code} not found on /access")
        return int(m.group(1))

    issues_create_pid = find_perm_id("issues.create")
    prices_manage_pid = find_perm_id("prices.manage")

    # Create two independent user accounts via POST /access?tab=users
    user_ids = []
    usernames = [f"conc_a_{tag}", f"conc_b_{tag}"]
    for uname in usernames:
        st, hdrs, _ = admin.post(
            "/access?tab=users",
            {
                "mode": "create_user",
                "username": uname,
                "email": f"{uname}@example.com",
                "full_name": f"Concurrency Tester {uname}",
                "password": "concpass123",
                "is_active": "1",
                "role_ids[]": [str(stock_manager_role_id)],
            },
        )
        expect(st == 302, f"admin creates user {uname}")
        uid = extract_id_from_location(hdrs, r"user_id=(\d+)")
        user_ids.append(uid)

        # Grant issues.create + prices.manage so both sessions can create/post issues and manage prices
        st2, _, _ = admin.post(
            "/access?tab=permissions",
            {
                "mode": "user",
                "user_id": str(uid),
                "role_id": str(stock_manager_role_id),
                "role_ids[]": [str(stock_manager_role_id)],
                f"overrides[{issues_create_pid}]": "allow",
                f"overrides[{prices_manage_pid}]": "allow",
            },
            csrf_path=f"/access?tab=permissions&user_id={uid}",
        )
        expect(st2 == 302, f"admin assigns STOCK_MANAGER + issue/price perms to {uname}")

    sess_a = Session(args.url, "sess_a")
    sess_b = Session(args.url, "sess_b")
    sess_a.login(usernames[0], "concpass123")
    sess_b.login(usernames[1], "concpass123")
    expect(True, "two independent user sessions logged in")

    # Create isolated product CONC<tag> via POST /products/create
    csrf_prod, prod_form = admin.get_csrf("/products/create")
    cat_id = find_option_id(prod_form, "category_id", "")
    # Pick CAI unit explicitly if present, else first option
    unit_id = find_option_id(prod_form, "unit_id", "(CAI)")
    sku = f"SKU-{tag}"
    st, hdrs, body = admin.post(
        "/products/create",
        {
            "name": f"San pham {tag}",
            "category_id": str(cat_id),
            "sku": sku,
            "unit_id": str(unit_id),
            "min_stock": "1",
            "description": "Concurrency verification fixture",
            "status": "1",
        },
        csrf_token=csrf_prod,
    )
    expect(st == 302, f"admin creates product {sku}")

    _, prod_list = admin.get_csrf(f"/products?keyword={urllib.parse.quote(sku)}")
    m_pid = re.search(r'href="/products/edit\?id=(\d+)"', prod_list)
    expect(m_pid is not None, f"found product_id for {sku}")
    prod_id = int(m_pid.group(1))

    # Resolve MAIN / DEFAULT location_id & NOLOT lot_id for prod_id
    _, doc_form = admin.get_csrf("/documents/create?type=receipt")
    loc_id = find_option_id(doc_form, "lines[0][location_id]", "MAIN / DEFAULT")
    m_lot = re.search(rf'<option[^>]*data-product-id="{prod_id}"[^>]*value="(\d+)"[^>]*>\s*NOLOT', doc_form)
    expect(m_lot is not None, f"resolved NOLOT lot_id for product {prod_id}")
    lot_id = int(m_lot.group(1))

    # Set open purchase price [2025-01-01, NULL) and bounded sale price [2025-01-01, 2026-07-01)
    st_p1, _, _ = admin.post(
        "/prices",
        {
            "action": "create",
            "product_id": str(prod_id),
            "price_type": "purchase",
            "amount": "80000.00",
            "valid_from": "2025-01-01T00:00",
            "valid_to": "",
        },
    )
    expect(st_p1 == 302, "admin adds open purchase price [2025-01-01, NULL)")

    st_p2, _, _ = admin.post(
        "/prices",
        {
            "action": "create",
            "product_id": str(prod_id),
            "price_type": "sale",
            "amount": "120000.00",
            "valid_from": "2025-01-01T00:00",
            "valid_to": "2026-07-01T00:00",
        },
    )
    expect(st_p2 == 302, "admin adds bounded sale price [2025-01-01, 2026-07-01)")

    # Receive initial balance = 4
    st_r, hdr_r, _ = admin.post(
        "/documents/create",
        {
            "document_type": "receipt",
            "document_date": "2026-06-10T09:00",
            "partner_name": f"NCC {tag}",
            "note": "Initial stock 4 for concurrency test",
            "lines[0][product_id]": str(prod_id),
            "lines[0][location_id]": str(loc_id),
            "lines[0][lot_id]": str(lot_id),
            "lines[0][quantity]": "4",
        },
        csrf_path="/documents/create?type=receipt",
    )
    expect(st_r == 302, "admin creates draft receipt of qty 4")
    rec_id = extract_id_from_location(hdr_r, r"id=(\d+)")
    st_rp, _, _ = admin.post("/documents/post", {"id": str(rec_id)}, csrf_path=f"/documents/show?id={rec_id}")
    expect(st_rp == 302, "admin posts receipt so opening stock = 4")

    # ------------------------------------------------------------------
    # TEST 1: Concurrent Issue Post (Balance=4, Session A posts 4, Session B posts 4)
    # ------------------------------------------------------------------
    st_i1, hdr_i1, _ = sess_a.post(
        "/documents/create",
        {
            "document_type": "issue",
            "document_date": "2026-06-15T10:00",
            "partner_name": f"Customer A {tag}",
            "note": "Concurrent issue A qty 4",
            "lines[0][product_id]": str(prod_id),
            "lines[0][location_id]": str(loc_id),
            "lines[0][lot_id]": str(lot_id),
            "lines[0][quantity]": "4",
        },
        csrf_path="/documents/create?type=issue",
    )
    expect(st_i1 == 302, "session A creates draft issue #1 (qty 4)")
    doc_a = extract_id_from_location(hdr_i1, r"id=(\d+)")

    st_i2, hdr_i2, _ = sess_b.post(
        "/documents/create",
        {
            "document_type": "issue",
            "document_date": "2026-06-15T10:00",
            "partner_name": f"Customer B {tag}",
            "note": "Concurrent issue B qty 4",
            "lines[0][product_id]": str(prod_id),
            "lines[0][location_id]": str(loc_id),
            "lines[0][lot_id]": str(lot_id),
            "lines[0][quantity]": "4",
        },
        csrf_path="/documents/create?type=issue",
    )
    expect(st_i2 == 302, "session B creates draft issue #2 (qty 4)")
    doc_b = extract_id_from_location(hdr_i2, r"id=(\d+)")

    csrf_a, _ = sess_a.get_csrf(f"/documents/show?id={doc_a}")
    csrf_b, _ = sess_b.get_csrf(f"/documents/show?id={doc_b}")
    barrier = threading.Barrier(2)

    def run_post(sess, doc_id, token):
        barrier.wait()
        return sess.post("/documents/post", {"id": str(doc_id)}, csrf_token=token)

    with ThreadPoolExecutor(max_workers=2) as pool:
        f_a = pool.submit(run_post, sess_a, doc_a, csrf_a)
        f_b = pool.submit(run_post, sess_b, doc_b, csrf_b)
        res_a = f_a.result()
        res_b = f_b.result()

    statuses = sorted([res_a[0], res_b[0]])
    expect(statuses == [302, 422], f"concurrent issue post produces [302, 422], got {statuses}")

    winner_doc, winner_sess = (doc_a, sess_a) if res_a[0] == 302 else (doc_b, sess_b)
    loser_doc = doc_b if res_a[0] == 302 else doc_a
    _, winner_html = admin.get_csrf(f"/documents/show?id={winner_doc}")
    _, loser_html = admin.get_csrf(f"/documents/show?id={loser_doc}")
    expect("Đã ghi sổ" in winner_html, f"winning issue doc #{winner_doc} is posted")
    expect("Nháp" in loser_html and "Đã ghi sổ" not in loser_html, f"losing issue doc #{loser_doc} stays draft")

    _, inv_html = admin.get_csrf(f"/inventory?keyword={urllib.parse.quote(sku)}")
    bals = parse_inventory_balances(inv_html, sku)
    expect(bals == [0], f"inventory balance after concurrent issue is strictly [0], got {bals}")

    # Re-POST already posted doc -> rejected cleanly, stock and movements unchanged
    _, repost_check_html = winner_sess.get(f"/documents/show?id={winner_doc}")[1:]
    # Need csrf from an active GET page (e.g. /documents) since posted doc form may omit post button
    csrf_repost, _ = winner_sess.get_csrf("/documents")
    st_re, _, body_re = winner_sess.post("/documents/post", {"id": str(winner_doc)}, csrf_token=csrf_repost)
    expect(st_re == 422, f"re-posting posted doc #{winner_doc} returns 422 (got {st_re})")

    _, mov_html = admin.get_csrf(f"/inventory/movements?keyword={urllib.parse.quote(sku)}")
    expect("+4" in mov_html and "-4" in mov_html, "movement ledger records exactly +4 and -4 for concurrent fixture")

    # ------------------------------------------------------------------
    # TEST 2: Concurrent Overlapping Price Creation (POST /prices action=create)
    # ------------------------------------------------------------------
    csrf_pa, _ = sess_a.get_csrf("/prices")
    csrf_pb, _ = sess_b.get_csrf("/prices")
    barrier_p = threading.Barrier(2)

    def run_price_create(sess, token, vf, vt, amt):
        barrier_p.wait()
        return sess.post(
            "/prices",
            {
                "action": "create",
                "product_id": str(prod_id),
                "price_type": "sale",
                "amount": amt,
                "valid_from": vf,
                "valid_to": vt,
            },
            csrf_token=token,
        )

    with ThreadPoolExecutor(max_workers=2) as pool:
        fp1 = pool.submit(run_price_create, sess_a, csrf_pa, "2026-07-01T00:00", "2026-08-01T00:00", "130000.00")
        fp2 = pool.submit(run_price_create, sess_b, csrf_pb, "2026-07-15T00:00", "2026-09-01T00:00", "135000.00")
        pr1 = fp1.result()
        pr2 = fp2.result()

    expect(
        sorted([pr1[0], pr2[0]]) == [200, 302],
        f"concurrent overlapping prices give 1 saved (302) & 1 error (200), got {[pr1[0], pr2[0]]}",
    )
    err_body = pr1[2] if pr1[0] == 200 else pr2[2]
    expect("chồng lấn" in err_body, "losing overlapping price reports overlap validation error")

    # ------------------------------------------------------------------
    # TEST 3: Concurrent Atomic Price Close-and-Open (POST /prices action=close_open)
    # ------------------------------------------------------------------
    csrf_ca, _ = sess_a.get_csrf("/prices")
    csrf_cb, _ = sess_b.get_csrf("/prices")
    barrier_co = threading.Barrier(2)

    def run_close_open(sess, token, amt):
        barrier_co.wait()
        return sess.post(
            "/prices",
            {
                "action": "close_open",
                "product_id": str(prod_id),
                "price_type": "purchase",
                "amount": amt,
                "valid_from": "2026-09-01T00:00",
                "valid_to": "",
            },
            csrf_token=token,
        )

    with ThreadPoolExecutor(max_workers=2) as pool:
        fc1 = pool.submit(run_close_open, sess_a, csrf_ca, "90000.00")
        fc2 = pool.submit(run_close_open, sess_b, csrf_cb, "95000.00")
        cr1 = fc1.result()
        cr2 = fc2.result()

    expect(
        sorted([cr1[0], cr2[0]]) == [200, 302],
        f"concurrent atomic close_open gives 1 saved (302) & 1 rejected (200), got {[cr1[0], cr2[0]]}",
    )
    _, prices_list = admin.get_csrf(f"/prices?product_id={prod_id}&price_type=purchase")
    expect(prices_list.count("2026-09-01 00:00:00") == 2, "old open purchase closed at T & exactly 1 new interval opens at T")

    # ------------------------------------------------------------------
    # TEST 4: Concurrent Post vs Cancel on the Same Draft Document
    # ------------------------------------------------------------------
    st_d3, hdr_d3, _ = admin.post(
        "/documents/create",
        {
            "document_type": "receipt",
            "document_date": "2026-06-16T11:00",
            "partner_name": f"NCC Race {tag}",
            "note": "Post vs Cancel race test",
            "lines[0][product_id]": str(prod_id),
            "lines[0][location_id]": str(loc_id),
            "lines[0][lot_id]": str(lot_id),
            "lines[0][quantity]": "2",
        },
        csrf_path="/documents/create?type=receipt",
    )
    expect(st_d3 == 302, "admin creates draft receipt #3 (qty 2) for post-vs-cancel test")
    doc_3 = extract_id_from_location(hdr_d3, r"id=(\d+)")

    csrf_pa3, _ = admin.get_csrf(f"/documents/show?id={doc_3}")
    # Also ensure sess_b or another session triggers cancel simultaneously (sess_b has STOCK_MANAGER -> receipts.post, wait: receipts.create? Grant receipts.create or admin+sess_b! Wait: admin and sess_a/sess_b are separate sessions! Let admin post and sess_a cancel? Wait: check if STOCK_MANAGER has receipts.create: only STOCK_CLERK/ADMIN have receipts.create, admin HAS receipts.create!)
    # Grant admin POST /cancel and sess_a (STOCK_MANAGER -> has receipts.post!) POST /post!
    csrf_post3, _ = sess_a.get_csrf(f"/documents/show?id={doc_3}")
    csrf_cancel3, _ = admin.get_csrf(f"/documents/show?id={doc_3}")
    barrier_pc = threading.Barrier(2)

    def run_doc_action(sess, ep, token):
        barrier_pc.wait()
        return sess.post(ep, {"id": str(doc_3)}, csrf_token=token)

    with ThreadPoolExecutor(max_workers=2) as pool:
        f_post = pool.submit(run_doc_action, sess_a, "/documents/post", csrf_post3)
        f_cancel = pool.submit(run_doc_action, admin, "/documents/cancel", csrf_cancel3)
        rp3 = f_post.result()
        rc3 = f_cancel.result()

    expect(
        sorted([rp3[0], rc3[0]]) == [302, 422],
        f"concurrent post vs cancel produces [302, 422], got post={rp3[0]} cancel={rc3[0]}",
    )
    expected_bal = [2] if rp3[0] == 302 else [0]
    _, inv_after = admin.get_csrf(f"/inventory?keyword={urllib.parse.quote(sku)}")
    expect(parse_inventory_balances(inv_after, sku) == expected_bal, f"stock matches post-vs-cancel winner {expected_bal}")

    # ------------------------------------------------------------------
    # TEST 5: E7 Live Session Invalidation on Account Lock + Audit Log
    # ------------------------------------------------------------------
    expect(sess_b.get("/documents")[0] == 200, "active session B accesses /documents before lock")
    st_lock, _, _ = admin.post(
        "/access?tab=users",
        {"mode": "toggle_user", "user_id": str(user_ids[1]), "is_active": "0"},
    )
    expect(st_lock == 302, "admin locks user B")
    st_b_after, hdr_b_after, _ = sess_b.get("/documents")
    expect(
        st_b_after == 302 and hdr_b_after.get("Location") == "/login",
        f"locked user B session is invalidated immediately on next request (got {st_b_after} -> {hdr_b_after.get('Location')})",
    )
    admin.post("/access?tab=users", {"mode": "toggle_user", "user_id": str(user_ids[1]), "is_active": "1"})

    _, audit_html = admin.get_csrf("/access?tab=audit")
    expect("CREATE_USER" in audit_html and "LOCK_USER" in audit_html and "UPDATE_USER_ACCESS" in audit_html, "audit log records E7 actions")

    # ------------------------------------------------------------------
    # TEST 6: CSV Export endpoints (UTF-8 BOM & HTTP 200 text/csv)
    # ------------------------------------------------------------------
    for label, path in [
        ("products CSV", f"/products?export=csv&keyword={urllib.parse.quote(sku)}"),
        ("inventory CSV", f"/inventory?export=csv&keyword={urllib.parse.quote(sku)}"),
        ("movements CSV", f"/inventory/movements?export=csv&keyword={urllib.parse.quote(sku)}"),
    ]:
        st_csv, hdr_csv, body_csv = admin.get(path)
        ctype = hdr_csv.get("Content-Type", "")
        expect(
            st_csv == 200 and "text/csv" in ctype and body_csv.startswith("\ufeff") and sku in body_csv,
            f"{label} returns 200 text/csv with UTF-8 BOM and fixture data",
        )

    print("\nAll 2-session HTTP concurrency & E7 live security checks PASSED.")


if __name__ == "__main__":
    main()
