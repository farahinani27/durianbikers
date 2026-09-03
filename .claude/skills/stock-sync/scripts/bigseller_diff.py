#!/usr/bin/env python3
"""
Diff one masterlist tab against a BigSeller inventory export, by SKU.

Replaces the manual loop of searching each SKU in BigSeller and retyping the
number. Reads the masterlist LIVE (never an export -- hidden rows are the whole
problem, see read_masterlist.py) and reads a BigSeller Inventory List export off
disk, then emits two files:

  <out>/<date>-<tab>-diff.md            human review: what changed and why
  <out>/<date>-<tab>-bigseller-import.csv   the deltas, ready to paste/import

WRITES NOTHING ANYWHERE. It produces files for the operator to review and upload.
BigSeller has no usable API (private/paid), and stock is the operator's call.

BigSeller moves stock by adjustment, not by absolute quantity, so the import file
carries a DELTA column: positive = stock in, negative = stock out.

Usage:
    # 1. In BigSeller: Inventory > Inventory List > Import & Export > Export
    # 2. Then:
    python3 .claude/skills/stock-sync/scripts/bigseller_diff.py \
        --tab 'DP BRAKES' --bigseller ~/Downloads/inventory.xlsx

    # If a column is guessed wrong, name it explicitly:
    #   --sheet-sku-col 'PART NO.' --sheet-stock-col 'STOCK LEVEL'
    #   --bs-sku-col 'Merchant SKU' --bs-stock-col 'Available Stock'
"""

import argparse
import csv
import datetime as dt
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import read_masterlist as ml

# Header candidates, most specific first. Matching is case/space-insensitive.
SHEET_SKU_HINTS = ("part no", "part number", "partno", "sku", "code", "item code",
                   "product code", "model no")
SHEET_STOCK_HINTS = ("stock level", "stock qty", "stock", "qty", "quantity", "balance")
BS_SKU_HINTS = ("merchant sku", "msku", "sku", "seller sku", "product code", "item code")
BS_STOCK_HINTS = ("available stock", "available", "sellable", "stock qty", "on hand",
                  "total stock", "stock", "qty", "quantity")

# Tabs that cannot be matched by SKU at all.
NO_SKU_TABS = {"HELMETS": "references/helmets.md -- SKUs live in mis-anchored cell "
                          "notes, 68% point at the wrong helmet. Match on "
                          "BRAND+SERIES+COLOUR instead; this script cannot."}


def norm(text):
    return re.sub(r"[^a-z0-9]+", " ", str(text or "").lower()).strip()


def norm_sku(text):
    """SKUs differ only by case and surrounding whitespace between the two systems."""
    return str(text or "").strip().upper()


def to_qty(value):
    """Stock values are floats in the sheet. A \\d+ regex silently drops them."""
    s = str(value or "").strip().replace(",", "")
    if not s:
        return None
    try:
        return int(float(s))
    except ValueError:
        return None


def pick_column(headers, hints, override=None):
    """Return (index, name) or None. Exact header match wins over substring."""
    if override:
        want = norm(override)
        for i, h in enumerate(headers):
            if norm(h) == want:
                return i, headers[i]
        return None
    for hint in hints:                       # most specific hint first
        for i, h in enumerate(headers):
            if norm(h) == hint:
                return i, headers[i]
    for hint in hints:
        for i, h in enumerate(headers):
            if hint in norm(h):
                return i, headers[i]
    return None


def locate_header(grid, sku_hints, stock_hints, sku_override, stock_override, what):
    """Find the header row. Both the masterlist and BigSeller exports carry title
    and filter rows above the real headers, so the first row is not it."""
    for i, row in enumerate(grid[:15]):
        sku = pick_column(row, sku_hints, sku_override)
        qty = pick_column(row, stock_hints, stock_override)
        if sku and qty and sku[0] != qty[0]:
            return i, sku, qty
    seen = [r for r in grid[:15] if any(r)]
    sys.exit("Could not find a SKU + stock header row in the %s.\n"
             "First rows seen:\n%s\n"
             "Name the columns explicitly with the --*-col flags."
             % (what, "\n".join("  %s" % r for r in seen)))


def read_bigseller(path):
    """Return the raw grid. Handles the xlsx and csv shapes BigSeller exports."""
    ext = os.path.splitext(path)[1].lower()
    if ext in (".xlsx", ".xlsm"):
        try:
            import openpyxl
        except ImportError:
            sys.exit("Missing openpyxl. Run: python3 -m pip install --user openpyxl")
        ws = openpyxl.load_workbook(path, read_only=True, data_only=True).active
        grid = [["" if c is None else str(c).strip() for c in row]
                for row in ws.iter_rows(values_only=True)]
    else:
        with open(path, newline="", encoding="utf-8-sig") as fh:
            grid = [[c.strip() for c in row] for row in csv.reader(fh)]

    return grid


def main():
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--tab", required=True, help="masterlist tab, e.g. 'DP BRAKES'")
    ap.add_argument("--bigseller", required=True, help="BigSeller Inventory List export")
    ap.add_argument("--out", default="sync", help="output directory (default: sync/)")
    ap.add_argument("--range", dest="cell_range", default="A:M")
    ap.add_argument("--carry", default="", help="0-indexed cols where blank = same as above")
    ap.add_argument("--stop-at", default=None)
    ap.add_argument("--sheet-sku-col", default=None)
    ap.add_argument("--sheet-stock-col", default=None)
    ap.add_argument("--bs-sku-col", default=None)
    ap.add_argument("--bs-stock-col", default=None)
    ap.add_argument("--sheet-id", default=os.environ.get("QBEE_SHEET_ID", ml.DEFAULT_SHEET_ID))
    args = ap.parse_args()

    if args.tab.strip().upper() in NO_SKU_TABS:
        sys.exit("%s cannot be matched by SKU. See %s"
                 % (args.tab, NO_SKU_TABS[args.tab.strip().upper()]))

    # --- masterlist, live ---------------------------------------------------
    carry = [int(c) for c in args.carry.split(",") if c.strip() != ""]
    result = ml.read_tab(ml.sheets_client(), args.sheet_id, args.tab, args.cell_range,
                         carry, args.stop_at, False, 0)
    rows = result["rows"]
    if not rows:
        sys.exit("Tab %r returned no active rows." % args.tab)

    h_i, (s_sku_i, s_sku_name), (s_qty_i, s_qty_name) = locate_header(
        [r["cells"] for r in rows], SHEET_SKU_HINTS, SHEET_STOCK_HINTS,
        args.sheet_sku_col, args.sheet_stock_col, "masterlist tab %r" % args.tab)

    sheet, sheet_dups = {}, {}
    for r in rows[h_i + 1:]:
        cells = r["cells"]
        if max(s_sku_i, s_qty_i) >= len(cells):
            continue
        sku, qty = norm_sku(cells[s_sku_i]), to_qty(cells[s_qty_i])
        if not sku or qty is None:
            continue
        if sku in sheet:
            sheet_dups.setdefault(sku, [sheet[sku]["row"]]).append(r["row"])
        sheet[sku] = {"qty": qty, "row": r["row"]}

    # --- BigSeller export ---------------------------------------------------
    bs_grid = read_bigseller(args.bigseller)
    b_i, (b_sku_i, b_sku_name), (b_qty_i, b_qty_name) = locate_header(
        bs_grid, BS_SKU_HINTS, BS_STOCK_HINTS,
        args.bs_sku_col, args.bs_stock_col, "BigSeller export")

    bigseller, bs_dups = {}, {}
    for row in bs_grid[b_i + 1:]:
        if max(b_sku_i, b_qty_i) >= len(row):
            continue
        sku, qty = norm_sku(row[b_sku_i]), to_qty(row[b_qty_i])
        if not sku or qty is None:
            continue
        if sku in bigseller:                 # multi-warehouse lines: sum, and flag
            bs_dups[sku] = bs_dups.get(sku, 1) + 1
            bigseller[sku] += qty
        else:
            bigseller[sku] = qty

    # --- diff ---------------------------------------------------------------
    changes, matched = [], 0
    for sku, info in sorted(sheet.items()):
        if sku not in bigseller:
            continue
        matched += 1
        current, target = bigseller[sku], info["qty"]
        if current != target:
            changes.append({"sku": sku, "current": current, "target": target,
                            "delta": target - current, "row": info["row"]})

    missing_in_bs = sorted(s for s in sheet if s not in bigseller)
    missing_in_sheet = sorted(s for s in bigseller if s not in sheet)
    changes.sort(key=lambda c: -abs(c["delta"]))

    # --- output -------------------------------------------------------------
    os.makedirs(args.out, exist_ok=True)
    stamp = dt.date.today().isoformat()
    slug = re.sub(r"[^a-z0-9]+", "-", args.tab.lower()).strip("-")
    md_path = os.path.join(args.out, "%s-%s-diff.md" % (stamp, slug))

    # BigSeller's Manual Stock-In only ADDS. Negative deltas are a separate
    # Stock-Out list, so the two directions cannot share one file.
    written = []
    for label, subset in (("stock-in", [c for c in changes if c["delta"] > 0]),
                          ("stock-out", [c for c in changes if c["delta"] < 0])):
        if not subset:
            continue
        path = os.path.join(args.out, "%s-%s-%s.csv" % (stamp, slug, label))
        with open(path, "w", newline="", encoding="utf-8") as fh:
            w = csv.writer(fh)
            w.writerow(["SKU", "Qty", "BigSeller now", "Masterlist target"])
            for c in subset:
                w.writerow([c["sku"], abs(c["delta"]), c["current"], c["target"]])
        written.append(path)

    with open(md_path, "w", encoding="utf-8") as fh:
        fh.write("# BigSeller stock diff - %s\n\n" % args.tab)
        fh.write("Generated %s. Masterlist read live; BigSeller from `%s`.\n\n"
                 % (stamp, os.path.basename(args.bigseller)))
        fh.write("Columns used - masterlist `%s` / `%s`, BigSeller `%s` / `%s`. "
                 "**Check these are right before uploading anything.**\n\n"
                 % (s_sku_name, s_qty_name, b_sku_name, b_qty_name))
        fh.write("Upload via Inventory > Manual Stock-In and Manual Stock-Out. Leave "
                 "**\"Stock-In Immediately\" unticked** so the list lands pending and "
                 "you commit it with one click after review.\n\n")
        fh.write("| | count |\n|---|---|\n")
        fh.write("| masterlist active SKUs | %d |\n" % len(sheet))
        fh.write("| BigSeller SKUs | %d |\n" % len(bigseller))
        fh.write("| matched | %d |\n" % matched)
        fh.write("| **need adjusting** | **%d** |\n" % len(changes))
        fh.write("| - stock IN | %d |\n" % len([c for c in changes if c["delta"] > 0]))
        fh.write("| - stock OUT | %d |\n" % len([c for c in changes if c["delta"] < 0]))
        fh.write("| in masterlist, not in BigSeller | %d |\n" % len(missing_in_bs))
        fh.write("| in BigSeller, not in masterlist | %d |\n\n" % len(missing_in_sheet))

        if changes:
            fh.write("## Adjustments\n\n")
            fh.write("| SKU | BigSeller now | Masterlist | Adjustment | sheet row |\n")
            fh.write("|---|---:|---:|---:|---:|\n")
            for c in changes:
                fh.write("| `%s` | %d | %d | %+d | %d |\n"
                         % (c["sku"], c["current"], c["target"], c["delta"], c["row"]))
            fh.write("\n")
        else:
            fh.write("## Adjustments\n\nNone - BigSeller already matches the masterlist.\n\n")

        if missing_in_bs:
            fh.write("## In masterlist, not in BigSeller (%d)\n\n"
                     "Add to the Inventory List first, or they are discontinued.\n\n"
                     % len(missing_in_bs))
            fh.write("".join("- `%s` (row %d, qty %d)\n"
                             % (s, sheet[s]["row"], sheet[s]["qty"]) for s in missing_in_bs))
            fh.write("\n")
        if missing_in_sheet:
            fh.write("## In BigSeller, not in masterlist (%d)\n\n"
                     "Not touched. Other brands' tabs, or dropped from the sheet.\n\n"
                     % len(missing_in_sheet))
            fh.write("".join("- `%s` (qty %d)\n" % (s, bigseller[s]) for s in missing_in_sheet))
            fh.write("\n")
        if sheet_dups:
            fh.write("## Duplicate SKUs in the masterlist (%d) - last row won\n\n"
                     % len(sheet_dups))
            fh.write("".join("- `%s` rows %s\n" % (s, r) for s, r in sorted(sheet_dups.items())))
            fh.write("\n")
        if bs_dups:
            fh.write("## SKUs on multiple BigSeller lines (%d) - quantities summed\n\n"
                     % len(bs_dups))
            fh.write("".join("- `%s` x%d lines\n" % (s, n) for s, n in sorted(bs_dups.items())))
            fh.write("\n")

    sys.stderr.write(
        "tab=%s hidden_skipped=%d | sheet=%d bigseller=%d matched=%d "
        "adjust=%d only_sheet=%d only_bigseller=%d\n"
        % (args.tab, result["skipped_hidden"], len(sheet), len(bigseller),
           matched, len(changes), len(missing_in_bs), len(missing_in_sheet)))
    sys.stderr.write("cols: sheet[%s|%s] bigseller[%s|%s]\n"
                     % (s_sku_name, s_qty_name, b_sku_name, b_qty_name))
    print(md_path)
    for path in written:
        print(path)


if __name__ == "__main__":
    main()
