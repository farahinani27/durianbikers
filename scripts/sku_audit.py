#!/usr/bin/env python3
"""
Read-only SKU integrity audit for the WooCommerce catalogue.

Phase 0 of the AutoCount sync: establishes whether SKU can serve as the join
key between WooCommerce and AutoCount. Issues GET requests only -- it never
writes to the store.

Usage:
    set -a; . ./.env; set +a
    python3 scripts/sku_audit.py fetch      # pull catalogue + variations to cache
    python3 scripts/sku_audit.py analyse    # run checks, write CSVs
    python3 scripts/sku_audit.py all        # both

Environment: WP_BASE_URL, WP_API_USER, WP_APP_PASSWORD
Output dir:  $SKU_AUDIT_OUT (default ./sku-audit)
"""

import base64
import csv
import json
import os
import re
import sys
import time
import unicodedata
import urllib.error
import urllib.parse
import urllib.request
from collections import Counter, defaultdict

BASE = os.environ.get("WP_BASE_URL", "").rstrip("/")
USER = os.environ.get("WP_API_USER", "")
PASS = os.environ.get("WP_APP_PASSWORD", "")
OUT = os.environ.get("SKU_AUDIT_OUT", "sku-audit")

PRODUCT_FIELDS = (
    "id,name,sku,type,status,catalog_visibility,manage_stock,"
    "stock_quantity,stock_status,parent_id,lang,translations"
)
VARIATION_FIELDS = "id,sku,manage_stock,stock_quantity,stock_status,attributes"

DELAY = 0.12          # polite gap between requests, seconds
RETRIES = 7           # ~2 min of recovery window against a slow production box
TIMEOUT = 25          # fail fast; a long timeout just burns the retry budget
BACKOFF_CAP = 30      # seconds -- 1,2,4,8,16,30,30
CHECKPOINT = 100      # save the variations cache every N parents


def _auth_header():
    if not (BASE and USER and PASS):
        sys.exit("Missing WP_BASE_URL / WP_API_USER / WP_APP_PASSWORD -- source .env first")
    token = base64.b64encode(f"{USER}:{PASS}".encode()).decode()
    return {"Authorization": f"Basic {token}", "User-Agent": "durianbikers-sku-audit/1.0"}


def get(path, **params):
    """GET a WC REST endpoint. Returns (parsed_json, response_headers)."""
    url = f"{BASE}/wp-json/{path}?{urllib.parse.urlencode(params)}"
    req = urllib.request.Request(url, headers=_auth_header())
    for attempt in range(RETRIES):
        try:
            with urllib.request.urlopen(req, timeout=TIMEOUT) as r:
                return json.load(r), dict(r.headers)
        except urllib.error.HTTPError as e:
            if e.code in (429, 500, 502, 503, 504) and attempt < RETRIES - 1:
                time.sleep(min(2 ** attempt, BACKOFF_CAP))
                continue
            raise
        except OSError:
            # OSError covers urllib.error.URLError, socket.timeout and
            # TimeoutError. socket.timeout is NOT a TimeoutError on Python 3.9
            # -- it only became an alias in 3.10 -- so naming TimeoutError
            # alone lets a read timeout escape every retry. This box is 3.9.
            if attempt < RETRIES - 1:
                time.sleep(min(2 ** attempt, BACKOFF_CAP))
                continue
            raise
    raise RuntimeError(f"exhausted retries for {path}")


# ---------------------------------------------------------------- fetch

def fetch():
    os.makedirs(OUT, exist_ok=True)

    # Reusing the cached catalogue skips ~2 minutes and 55 more chances to fail
    # before the variations stage even begins.
    prod_path = f"{OUT}/raw_products.json"
    if os.path.exists(prod_path) and os.environ.get("REFETCH_PRODUCTS") != "1":
        with open(prod_path) as f:
            products = json.load(f)
        print(f"reusing cached catalogue: {len(products)} products "
              f"(set REFETCH_PRODUCTS=1 to force a re-pull)", flush=True)
        _fetch_variations(products)
        return

    _, hdr = get("wc/v3/products", per_page=1, page=1, status="any", _fields="id")
    total = int(hdr.get("X-WP-Total", 0))
    pages = -(-total // 100)
    print(f"catalogue: {total} products across {pages} pages", flush=True)

    products = []
    for page in range(1, pages + 1):
        batch, _ = get("wc/v3/products", per_page=100, page=page, status="any",
                       orderby="id", order="asc", _fields=PRODUCT_FIELDS)
        products.extend(batch)
        if page % 10 == 0 or page == pages:
            print(f"  products page {page}/{pages}  ({len(products)} fetched)", flush=True)
        time.sleep(DELAY)

    with open(f"{OUT}/raw_products.json", "w") as f:
        json.dump(products, f)
    print(f"cached {len(products)} products -> {OUT}/raw_products.json", flush=True)

    _fetch_variations(products)


def _fetch_variations(products):
    types = Counter(p.get("type") for p in products)
    print("type breakdown:", dict(types), flush=True)

    parents = [p["id"] for p in products if p.get("type") == "variable"]
    print(f"variable parents needing a variations call: {len(parents)}", flush=True)

    # Resume: a crash mid-loop used to discard every completed call. The cache
    # is now written every CHECKPOINT parents and reloaded on start, so a re-run
    # picks up where it stopped instead of replaying ~2,800 requests.
    var_path = f"{OUT}/raw_variations.json"
    variations = {}
    if os.path.exists(var_path):
        try:
            with open(var_path) as f:
                variations = json.load(f)
            print(f"resuming -- {len(variations)} parents already cached", flush=True)
        except json.JSONDecodeError:
            print("existing variations cache unreadable, starting over", flush=True)

    def _save_variations():
        tmp = var_path + ".tmp"
        with open(tmp, "w") as f:
            json.dump(variations, f)
        os.replace(tmp, var_path)   # atomic; a crash mid-write cannot corrupt

    todo = [pid for pid in parents if str(pid) not in variations]
    print(f"variations still to fetch: {len(todo)}", flush=True)

    for i, pid in enumerate(todo, 1):
        try:
            rows, _ = get(f"wc/v3/products/{pid}/variations", per_page=100, page=1,
                          status="any", _fields=VARIATION_FIELDS)
            variations[str(pid)] = rows
        except urllib.error.HTTPError as e:
            variations[str(pid)] = {"error": e.code}
        except OSError as e:
            # Retries already exhausted inside get(). Record and keep going --
            # one unreachable parent must not cost the whole run.
            variations[str(pid)] = {"error": f"network: {e}"}
        if i % CHECKPOINT == 0 or i == len(todo):
            _save_variations()
            print(f"  variations {i}/{len(todo)} (checkpointed)", flush=True)
        time.sleep(DELAY)

    _save_variations()
    print(f"cached variations for {len(variations)} parents", flush=True)


# ---------------------------------------------------------------- helpers

INVISIBLE = dict.fromkeys(map(ord, " ​‌‍﻿"), " ")
DASHES = dict.fromkeys(map(ord, "‐‑‒–—―−"), "-")


def norm(sku):
    """Aggressive normalisation -- two SKUs colliding here look identical to a
    human but will not match on an exact join."""
    s = unicodedata.normalize("NFKC", sku or "")
    s = s.translate(INVISIBLE).translate(DASHES)
    s = re.sub(r"[\s_.]+", "", s)
    return s.upper()


def oddities(sku):
    """Characteristics that break an exact string join with AutoCount."""
    flags = []
    if sku != sku.strip():
        flags.append("leading/trailing space")
    if any(ord(c) > 127 for c in sku):
        flags.append("non-ascii char")
    if re.search(r"[‐-―−]", sku):
        flags.append("unicode dash")
    if " " in sku:
        flags.append("non-breaking space")
    if re.search(r"\s{2,}", sku):
        flags.append("double space")
    if re.search(r"\s", sku.strip()):
        flags.append("internal whitespace")
    return flags


def admin_url(pid):
    return f"{BASE}/wp-admin/post.php?post={pid}&action=edit"


def write_csv(name, header, rows):
    path = f"{OUT}/{name}"
    with open(path, "w", newline="") as f:
        w = csv.writer(f)
        w.writerow(header)
        w.writerows(rows)
    print(f"  {len(rows):>6}  {name}")
    return len(rows)


# ---------------------------------------------------------------- analyse

def analyse():
    products = json.load(open(f"{OUT}/raw_products.json"))
    variations = json.load(open(f"{OUT}/raw_variations.json"))

    by_id = {p["id"]: p for p in products}
    counts = {}

    # every sellable unit: simple/external products, plus variations of variable ones
    units = []          # (kind, id, parent_id, name, sku, manage_stock, qty, stock_status, status)
    for p in products:
        if p.get("type") == "variable":
            continue
        units.append(("product", p["id"], None, p.get("name", ""), p.get("sku") or "",
                      p.get("manage_stock"), p.get("stock_quantity"),
                      p.get("stock_status"), p.get("status")))
    for pid, rows in variations.items():
        if isinstance(rows, dict):
            continue
        parent = by_id.get(int(pid), {})
        for v in rows:
            units.append(("variation", v["id"], int(pid), parent.get("name", ""),
                          v.get("sku") or "", v.get("manage_stock"),
                          v.get("stock_quantity"), v.get("stock_status"),
                          parent.get("status")))

    print(f"\nsellable units: {len(units)}  "
          f"({sum(1 for u in units if u[0]=='product')} products + "
          f"{sum(1 for u in units if u[0]=='variation')} variations)\n")
    print("issue counts:")

    # 1 --- empty SKU
    rows = [(u[0], u[1], u[2] or "", u[8], u[3], admin_url(u[2] or u[1]))
            for u in units if not u[4].strip()]
    counts["missing_sku"] = write_csv(
        "01_missing_sku.csv",
        ["kind", "id", "parent_id", "status", "name", "edit_url"], rows)

    # 2 --- exact duplicate SKU
    exact = defaultdict(list)
    for u in units:
        if u[4].strip():
            exact[u[4]].append(u)
    rows = []
    for sku, group in sorted(exact.items()):
        if len(group) > 1:
            for u in group:
                rows.append([sku, len(group), u[0], u[1], u[2] or "", u[8], u[3],
                             admin_url(u[2] or u[1])])
    counts["duplicate_sku"] = write_csv(
        "02_duplicate_sku.csv",
        ["sku", "occurrences", "kind", "id", "parent_id", "status", "name", "edit_url"], rows)

    # 3 --- near-duplicate: different strings, same normalised form
    near = defaultdict(set)
    for sku in exact:
        near[norm(sku)].add(sku)
    rows = []
    for key, variants in sorted(near.items()):
        if len(variants) > 1:
            for sku in sorted(variants):
                for u in exact[sku]:
                    rows.append([key, sku, "|".join(oddities(sku)) or "case/spacing only",
                                 u[0], u[1], u[2] or "", u[3], admin_url(u[2] or u[1])])
    counts["near_duplicate_sku"] = write_csv(
        "03_near_duplicate_sku.csv",
        ["normalised", "actual_sku", "why_it_differs", "kind", "id", "parent_id",
         "name", "edit_url"], rows)

    # 4 --- SKU strings that will not survive an exact join
    rows = []
    for sku, group in sorted(exact.items()):
        flags = oddities(sku)
        if flags:
            u = group[0]
            rows.append([sku, repr(sku), "|".join(flags), len(group), u[1], u[3],
                         admin_url(u[2] or u[1])])
    counts["hazardous_sku"] = write_csv(
        "04_hazardous_sku_strings.csv",
        ["sku", "repr", "problems", "occurrences", "sample_id", "sample_name", "edit_url"], rows)

    # 5 --- variable products with no variations
    rows = []
    for p in products:
        if p.get("type") != "variable":
            continue
        rows_v = variations.get(str(p["id"]))
        if isinstance(rows_v, dict) or not rows_v:
            rows.append([p["id"], p.get("sku") or "", p.get("status"),
                         p.get("catalog_visibility"), p.get("name", ""), admin_url(p["id"])])
    counts["variable_no_variations"] = write_csv(
        "05_variable_with_zero_variations.csv",
        ["id", "parent_sku", "status", "visibility", "name", "edit_url"], rows)

    # 6 --- parent has a SKU but its variations do not
    rows = []
    for pid, rows_v in variations.items():
        if isinstance(rows_v, dict) or not rows_v:
            continue
        blank = [v for v in rows_v if not (v.get("sku") or "").strip()]
        if blank:
            p = by_id.get(int(pid), {})
            rows.append([pid, p.get("sku") or "", len(blank), len(rows_v),
                         p.get("status"), p.get("name", ""), admin_url(pid)])
    counts["variations_without_sku"] = write_csv(
        "06_variations_without_sku.csv",
        ["parent_id", "parent_sku", "variations_missing_sku", "variations_total",
         "status", "name", "edit_url"], rows)

    # 7 --- stock not tracked, so there is nothing for a sync to write into
    rows = [[u[0], u[1], u[2] or "", u[4], u[7], u[8], u[3], admin_url(u[2] or u[1])]
            for u in units if not u[5] and u[8] == "publish"]
    counts["stock_not_managed"] = write_csv(
        "07_stock_not_managed.csv",
        ["kind", "id", "parent_id", "sku", "stock_status", "status", "name", "edit_url"], rows)

    # 8 --- quantity contradicts stock status
    rows = []
    for u in units:
        if not u[5]:
            continue
        qty, st = u[6], u[7]
        if qty is None:
            continue
        if (st == "instock" and qty <= 0) or (st == "outofstock" and qty > 0):
            rows.append([u[0], u[1], u[2] or "", u[4], qty, st, u[8], u[3],
                         admin_url(u[2] or u[1])])
    counts["stock_contradiction"] = write_csv(
        "08_stock_status_contradiction.csv",
        ["kind", "id", "parent_id", "sku", "quantity", "stock_status", "status",
         "name", "edit_url"], rows)

    # 9 --- WPML translation groups sharing one SKU
    langs = Counter(p.get("lang") for p in products if p.get("lang"))
    rows = []
    if langs:
        for sku, group in sorted(exact.items()):
            if len(group) < 2:
                continue
            ids = {u[1] for u in group if u[0] == "product"}
            group_langs = {by_id[i].get("lang") for i in ids if i in by_id}
            if len(group_langs) > 1:
                rows.append([sku, len(group), "|".join(sorted(str(l) for l in group_langs)),
                             "|".join(str(i) for i in sorted(ids))])
    counts["wpml_shared_sku"] = write_csv(
        "09_wpml_translation_shared_sku.csv",
        ["sku", "occurrences", "languages", "product_ids"], rows)

    # ------------------------------------------------ join-readiness
    flagged = set()
    for u in units:
        sku = u[4].strip()
        if not sku or len(exact.get(u[4], [])) > 1 or oddities(u[4]) \
                or len(near[norm(u[4])]) > 1:
            flagged.add((u[0], u[1]))
    join_ready = len(units) - len(flagged)

    summary = {
        "base_url": BASE,
        "products_total": len(products),
        "sellable_units": len(units),
        "type_breakdown": dict(Counter(p.get("type") for p in products)),
        "status_breakdown": dict(Counter(p.get("status") for p in products)),
        "languages": dict(langs) or "no WPML lang field returned by the API",
        "issues": counts,
        "join_ready_units": join_ready,
        "join_ready_pct": round(100 * join_ready / len(units), 1) if units else 0,
    }
    with open(f"{OUT}/summary.json", "w") as f:
        json.dump(summary, f, indent=2)

    print(f"\njoin-ready units: {join_ready}/{len(units)} "
          f"({summary['join_ready_pct']}%)")
    print(f"summary -> {OUT}/summary.json")


if __name__ == "__main__":
    cmd = sys.argv[1] if len(sys.argv) > 1 else "all"
    if cmd in ("fetch", "all"):
        fetch()
    if cmd in ("analyse", "analyze", "all"):
        analyse()
