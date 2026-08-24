#!/usr/bin/env python3
"""
Read one tab of the Qbee stock masterlist LIVE via the Google Sheets API.

No export, no download, no file on disk -- it reads the sheet directly and prints
rows. Use this instead of exporting a workbook.

Why the API and not a CSV or the rendered page:
  - Hidden rows are the whole problem. On HELMETS ~2,326 of ~2,687 rows are hidden
    (= discontinued) and must be skipped. A CSV loses that state and every hidden row
    reads as active. This endpoint returns it explicitly as rowMetadata.hiddenByUser.
  - It also keeps the TRUE sheet row number on every record, so any oddity can be
    traced back to a cell.

Auth: the service account key at .secrets/google-service-account.json
      (override with $QBEE_GOOGLE_SA_JSON).
      The sheet must be shared with that account's client_email.

READ-ONLY BY CONSTRUCTION: the only scope requested is spreadsheets.readonly, so
this cannot modify the masterlist even if the account is granted Editor.

Usage:
    python3 read_masterlist.py --list
    python3 read_masterlist.py HELMETS --carry 0,1 --stop-at 'OUT OF STOCK RANGE'
    python3 read_masterlist.py HELMETS --format json
"""

import argparse
import csv
import json
import os
import sys
import warnings

warnings.filterwarnings("ignore", category=FutureWarning)

try:
    from google.oauth2 import service_account
    from googleapiclient.discovery import build
except ImportError:
    sys.exit("Missing deps. Run:\n"
             "  python3 -m pip install --user google-api-python-client google-auth")

SCOPES = ["https://www.googleapis.com/auth/spreadsheets.readonly"]

# [ALERT] QBEEmotor - STOCKS MASTERLIST. An identifier, not a secret.
DEFAULT_SHEET_ID = "1e0N3f-G_qlFPkafSoDOf-iT01nz5Ir09un1lzROG7xE"

FIELDS = ("sheets(properties(title,gridProperties/rowCount),"
          "data(startRow,rowMetadata(hiddenByUser,hiddenByFilter),"
          "rowData(values(formattedValue))))")


def repo_root():
    here = os.path.abspath(os.path.dirname(__file__))
    while here != os.path.dirname(here):
        if os.path.isdir(os.path.join(here, ".claude")):
            return here
        here = os.path.dirname(here)
    return os.getcwd()


def sheets_client():
    root = repo_root()
    key = (os.environ.get("QBEE_GOOGLE_SA_JSON")
           or os.path.join(root, ".secrets", "google-service-account.json"))
    if not os.path.isfile(key):
        sys.exit("No service-account key at %s\n"
                 "Place the JSON key there, and share the sheet with its client_email." % key)
    creds = service_account.Credentials.from_service_account_file(key, scopes=SCOPES)
    return build("sheets", "v4", credentials=creds, cache_discovery=False)


def clean(value):
    return "" if value is None else str(value).strip()


def read_tab(svc, sheet_id, tab, cell_range, carry_cols, stop_at, include_hidden, max_col):
    rng = "%s!%s" % (tab, cell_range)
    resp = svc.spreadsheets().get(spreadsheetId=sheet_id, ranges=[rng],
                                  includeGridData=True, fields=FIELDS).execute()
    sheets = resp.get("sheets") or []
    if not sheets:
        sys.exit("Tab %r returned no data." % tab)

    data = (sheets[0].get("data") or [{}])[0]
    start = data.get("startRow", 0)                 # 0-indexed offset of the range
    meta = data.get("rowMetadata") or []
    grid = data.get("rowData") or []

    rows, carried = [], {}
    hidden_count = 0
    stopped_at = None

    for i, row in enumerate(grid):
        sheet_row = start + i + 1                   # 1-indexed, the real row number
        rm = meta[i] if i < len(meta) else {}
        is_hidden = bool(rm.get("hiddenByUser") or rm.get("hiddenByFilter"))

        cells = [clean((v or {}).get("formattedValue")) for v in (row.get("values") or [])]
        if max_col:
            cells = cells[:max_col]

        if stop_at and any(stop_at.upper() in c.upper() for c in cells if c):
            stopped_at = sheet_row
            break

        if is_hidden:
            hidden_count += 1
            if not include_hidden:
                continue

        # Emptiness must be judged BEFORE carrying. Carrying populates BRAND/SERIES,
        # so a blank spacer row would otherwise survive as a phantom item -- that is
        # what turned 443 real rows into 490.
        if not any(cells):
            continue

        for col in carry_cols:
            while len(cells) <= col:
                cells.append("")
            if cells[col]:
                carried[col] = cells[col]
            elif col in carried:
                cells[col] = carried[col]

        rows.append({"row": sheet_row, "hidden": is_hidden, "cells": cells})

    return {"tab": tab, "rows": rows, "skipped_hidden": hidden_count,
            "stopped_at_row": stopped_at}


def main():
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("tab", nargs="?")
    ap.add_argument("--list", action="store_true", help="list tab names and exit")
    ap.add_argument("--sheet-id", default=os.environ.get("QBEE_SHEET_ID", DEFAULT_SHEET_ID))
    ap.add_argument("--range", dest="cell_range", default="A:M")
    ap.add_argument("--carry", default="", help="0-indexed cols where blank = same as above")
    ap.add_argument("--stop-at", default=None, help="stop at first row containing this text")
    ap.add_argument("--include-hidden", action="store_true",
                    help="keep hidden rows (they are discontinued -- normally skip)")
    ap.add_argument("--max-col", type=int, default=0)
    ap.add_argument("--format", choices=["csv", "json"], default="csv")
    args = ap.parse_args()

    svc = sheets_client()

    if args.list:
        meta = svc.spreadsheets().get(spreadsheetId=args.sheet_id,
                                      fields="properties/title,sheets/properties/title").execute()
        sys.stderr.write("workbook: %s\n" % meta["properties"]["title"])
        for s in meta.get("sheets", []):
            print(s["properties"]["title"])
        return

    if not args.tab:
        ap.error("tab is required (or use --list)")

    carry = [int(c) for c in args.carry.split(",") if c.strip() != ""]
    result = read_tab(svc, args.sheet_id, args.tab, args.cell_range,
                      carry, args.stop_at, args.include_hidden, args.max_col)

    if args.format == "json":
        json.dump(result, sys.stdout, indent=2)
        sys.stdout.write("\n")
    else:
        out = csv.writer(sys.stdout)
        for r in result["rows"]:
            out.writerow([r["row"]] + r["cells"])

    sys.stderr.write("tab=%s active_rows=%d hidden_skipped=%d stopped_at_row=%s\n"
                     % (result["tab"], len(result["rows"]), result["skipped_hidden"],
                        result["stopped_at_row"]))


if __name__ == "__main__":
    main()
