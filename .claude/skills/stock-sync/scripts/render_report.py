#!/usr/bin/env python3
"""
Render the fixed reconciliation table from reconciled JSON.

The output format is an operator-fixed contract -- see ../references/report-format.md.
Hand-assembled tables drift between runs (column order, arrow direction, status logic),
so build the records and let this render them.

Input: a JSON array of records, one per ACTIVE masterlist row. Full reconciliation --
include matched rows too, not just the diffs.

    [
      {
        "row": 412,                       # sheet row number, for tracing
        "sku": "MT/TH4/BK/M",             # from WooCommerce; null if no site match
        "brand": "MT",
        "series": "THUNDER 4",
        "model": "BLACK GLOSS",
        "price_ml": 1485,                 # accepts "RM1,485" too
        "price_wc": 1485,                 # null if no match / no variations
        "stock": {"S": {"wc": 3, "ml": 2}, "M": {"wc": 1, "ml": 1}},
        "cause": "stock",                 # optional, for the breakdown under the table
        "note": "3 sheet rows claim this product"
      }
    ]

Sizes absent from "stock" render as an em dash and are not compared.
A size present with a null masterlist value counts as 0 (blank sheet cell = no stock).

Read-only. Touches neither the workbook nor the store.

Usage:
    python3 render_report.py reconciled.json
    python3 render_report.py reconciled.json --out reports/2026-08-20-helmets-report.md
"""

import argparse
import json
import sys

SIZES = ["XS", "S", "M", "L", "XL", "XXL", "XXXL"]
DASH = "—"
ARROW = "→"

IDENT_COLUMNS = ["SKU", "BRANDS", "SERIES", "MODEL / COLOUR",
                 "PRICE (MASTERLIST)", "PRICE (WOOCOMMERCE)"]


def columns(collapsed=False):
    """Per-size columns by default; one total column for tabs without per-size stock.

    report-format.md: "Tabs without per-size stock: collapse columns 7-13 to one
    STOCK (WC->ML) column."
    """
    stock_cols = (["STOCK (WC%sML)" % ARROW] if collapsed
                  else ["%s (WC%sML)" % (s, ARROW) for s in SIZES])
    return IDENT_COLUMNS + stock_cols + ["STATUS"]

# Causes reported under the table. Order is the order they print in.
CAUSES = [
    ("stock", "stock differs only"),
    ("price", "price differs only"),
    ("both", "price and stock both differ"),
    ("not_on_site", "in masterlist, not on site"),
    ("no_variations", "variable product with zero variations"),
    ("ambiguous", "several sheet rows claim one product"),
    ("other", "other / unclassified"),
]


def as_price(value):
    """Accept 1485, '1485', 'RM1,485' or 'RM 1,485.00'. Return float or None."""
    if value is None or value == "":
        return None
    if isinstance(value, (int, float)):
        return float(value)
    cleaned = str(value).upper().replace("RM", "").replace(",", "").strip()
    try:
        return float(cleaned)
    except ValueError:
        return None


def fmt_price(value):
    price = as_price(value)
    return DASH if price is None else "%.2f" % price


def as_stock(value):
    if value is None or value == "":
        return None
    try:
        return int(float(value))
    except (TypeError, ValueError):
        return None


def stock_cell(entry):
    """Render one size as 'WC->ML'. Returns (text, comparable, matches)."""
    if not entry:
        return DASH, False, True

    wc = as_stock(entry.get("wc"))
    ml = as_stock(entry.get("ml"))

    if wc is None and ml is None:
        return DASH, False, True

    # A size the sheet lists but the site does not carry, or vice versa. Blank on the
    # side that exists means zero, not "unknown" -- but a missing SIDE stays a dash so
    # the reader can see the asymmetry rather than reading an invented 0.
    left = DASH if wc is None else str(wc)
    right = DASH if ml is None else str(ml)

    if wc is None or ml is None:
        # Only comparable if the present side claims stock; 0 vs absent is not a real diff.
        present = wc if wc is not None else ml
        return "%s%s%s" % (left, ARROW, right), True, (present == 0)

    return "%s%s%s" % (left, ARROW, right), True, (wc == ml)


def evaluate(record, collapsed=False):
    """Return (cells, is_match). Price must match and every compared size must match."""
    price_ml = as_price(record.get("price_ml"))
    price_wc = as_price(record.get("price_wc"))

    # Both absent -> not compared. One absent -> cannot be a match.
    if price_ml is None and price_wc is None:
        price_ok = True
    elif price_ml is None or price_wc is None:
        price_ok = False
    else:
        price_ok = abs(price_ml - price_wc) < 0.005

    stock = record.get("stock") or {}
    cells = []
    stock_ok = True
    for size in (["TOTAL"] if collapsed else SIZES):
        text, comparable, matches = stock_cell(stock.get(size))
        cells.append(text)
        if comparable and not matches:
            stock_ok = False

    return cells, (price_ok and stock_ok)


def render(records, collapsed=False):
    cols = columns(collapsed)
    lines = []
    lines.append("| " + " | ".join(cols) + " |")
    lines.append("|" + "|".join(["---"] * len(cols)) + "|")

    matched = 0
    causes = {}

    for record in records:
        stock_cells, is_match = evaluate(record, collapsed)
        if is_match:
            matched += 1
            status = "MATCH ✅"
        else:
            status = "NOT MATCH ❌"
            cause = record.get("cause") or "other"
            causes[cause] = causes.get(cause, 0) + 1

        row = [
            record.get("sku") or DASH,
            record.get("brand") or "",
            record.get("series") or "",
            record.get("model") or "",
            fmt_price(record.get("price_ml")),
            fmt_price(record.get("price_wc")),
        ] + stock_cells + [status]

        lines.append("| " + " | ".join(str(c).replace("|", "\\|") for c in row) + " |")

    total = len(records)
    summary = [
        "",
        "## Counts",
        "",
        "| | |",
        "|---|---|",
        "| Active rows reconciled | %d |" % total,
        "| MATCH ✅ | %d |" % matched,
        "| NOT MATCH ❌ | %d |" % (total - matched),
    ]
    if causes:
        summary += ["", "### NOT MATCH breakdown", "", "| Cause | Rows |", "|---|---|"]
        for key, label in CAUSES:
            if causes.get(key):
                summary.append("| %s | %d |" % (label, causes[key]))

    notes = [r for r in records if r.get("note")]
    if notes:
        summary += ["", "## Unresolved / needs a decision", "",
                    "| Sheet row | Item | Note |", "|---|---|---|"]
        for r in notes:
            item = " ".join(x for x in [r.get("brand"), r.get("series"), r.get("model")] if x)
            summary.append("| %s | %s | %s |" % (r.get("row", DASH), item, r["note"]))

    return "\n".join(lines + summary) + "\n"


def main():
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("records", help="JSON file of reconciled records, or - for stdin")
    ap.add_argument("--out", default=None, help="write to this path instead of stdout")
    ap.add_argument("--collapsed-stock", action="store_true",
                    help="one STOCK (WC->ML) column instead of seven size columns; "
                         "for tabs whose stock is a single total. Reads stock['TOTAL'].")
    args = ap.parse_args()

    source = sys.stdin if args.records == "-" else open(args.records)
    with source as fh:
        records = json.load(fh)

    if not isinstance(records, list):
        sys.exit("Expected a JSON array of records, got %s" % type(records).__name__)

    table = render(records, args.collapsed_stock)

    if args.out:
        with open(args.out, "w") as fh:
            fh.write(table)
        sys.stderr.write("wrote %s (%d rows)\n" % (args.out, len(records)))
    else:
        sys.stdout.write(table)


if __name__ == "__main__":
    main()
