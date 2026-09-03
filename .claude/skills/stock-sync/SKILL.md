---
name: stock-sync
description: Reconcile one tab of the Qbee stock masterlist against the WooCommerce store — stock and price. Use when asked to sync, reconcile, audit or check a masterlist tab (HELMETS, SPAREPARTS, BMC, R&G, BARKBUSTERS, DP BRAKES, SHINKO, …) against the sheet, or when asked why site stock disagrees with the masterlist.
---

# Stock sync — masterlist ↔ WooCommerce

Reconcile **one tab at a time**. The masterlist is the source of truth; WooCommerce is
corrected to match it, never the other way round.

## Step 0 — Load the tab reference FIRST

Every tab has a different column layout, join key and set of traps. Read the matching file in
`references/` before touching data:

| Tab | Reference | Status |
|---|---|---|
| *(all tabs)* | `references/report-format.md` | **the output contract — always read this too** |
| *(all tabs)* | `references/price-overrides.md` | intentional site-vs-sheet price differences — check before flagging |
| HELMETS | `references/helmets.md` | **mapped** — audited 2026-08-19, writes never applied |
| SPAREPARTS | — | not yet mapped |
| BMC | — | not yet mapped |
| R&G | — | not yet mapped (reconciled 2026-08-13, notes in memory) |
| BARKBUSTERS | — | not yet mapped (reconciled 2026-08-13, notes in memory) |
| DP BRAKES | — | not yet mapped (reconciled 2026-08-13, notes in memory) |
| SHINKO | — | not yet mapped |

If the tab has no reference file, work from `references/_template.md` and **write the new
reference file at the end of the run** — that is how this skill grows. Do not guess a layout
from another tab; they genuinely differ.

The workbook has ~38 tabs, several of them stale duplicates (`SHINKO (OLD)`, `BMC (Old)`,
`SCORPION (OLD)`, `Shinko (Old 2)` / `(Old 3)`). Confirm the tab name with the operator if the
active one is not obvious; `scripts/read_tab.py --list` prints them all.

## Step 1 — Read the masterlist tab (live, read-only)

**Read the sheet directly. Do not export it, do not download a workbook, do not work from a
file in `~/Downloads`.**

```bash
python3 .claude/skills/stock-sync/scripts/read_masterlist.py HELMETS \
    --carry 0,1 --stop-at 'OUT OF STOCK RANGE' --max-col 12
```

`scripts/read_masterlist.py` reads one tab through the Google Sheets API using the
`helmets-sync-agent` service account key at `.secrets/google-service-account.json`.

- **Read-only by construction** — the only scope requested is `spreadsheets.readonly`, so it
  cannot modify the masterlist regardless of what the account is granted in Drive.
- It returns **true hidden-row state** (`rowMetadata.hiddenByUser`) and the **real sheet row
  number** on every record, so anything odd traces back to a cell.
- `--list` prints the tab names.

Never substitute a CSV or the rendered page. Hidden rows are the whole problem — on HELMETS
2,326 of 2,687 rows are hidden, and any source that loses that state reports ~8× too many
active items.

> `scripts/read_tab.py` is the older xlsx-based reader, kept only for offline spot-checks.
> ⚠️ It carries BRAND/SERIES *before* testing a row for emptiness, so blank spacer rows survive
> as phantom items — it over-counted HELMETS by 116 rows. `read_masterlist.py` fixes that
> ordering. Prefer the live reader.

Universal read rules:

- **Hidden rows are archived/discontinued — skip them.** Never count them as active stock.
- **Stock values are floats.** Cast `int(float(v))`; a `\d+` regex silently drops them.
- **Blank BRAND/SERIES means "same as the row above".** Carry the last non-blank value forward.
- **Normalise brand spellings** before matching (`MT` / `MT HELMET` / `MT HELMETS`).

## Daily BigSeller check (masterlist ↔ BigSeller)

Separate from the WooCommerce reconciliation below. This replaces searching each SKU in
BigSeller by hand:

```bash
# 1. BigSeller: Inventory > Inventory List > Import & Export > Export
# 2. Then, from the repo root:
python3 .claude/skills/stock-sync/scripts/bigseller_diff.py \
    --tab 'DP BRAKES' --bigseller ~/Downloads/inventory.xlsx
```

Writes `sync/<date>-<tab>-diff.md` (review) plus `-stock-in.csv` and `-stock-out.csv`.
**It writes to no system** — the operator reviews and uploads.

- BigSeller adjusts stock by **delta**, not absolute quantity, and Manual Stock-In only
  *adds* — so the two directions are separate files, uploaded via Inventory > Manual
  Stock-In and Manual Stock-Out.
- Leave **"Stock-In Immediately" unticked**: the list lands pending and is committed
  with one click after review. That is the approval gate.
- It auto-detects the SKU and stock columns on both sides and prints which it chose —
  check that line before uploading. Override with `--sheet-sku-col` / `--bs-stock-col` etc.
- SKUs on several BigSeller warehouse lines are **summed**, and listed in the report.
- HELMETS is refused by design — it has no SKU column (see `references/helmets.md`).

## Step 2 — Query WooCommerce (read-only)

```bash
set -a; . ./.env; set +a
curl -u "$WP_API_USER:$WP_APP_PASSWORD" "$WP_BASE_URL/wp-json/wc/v3/products?per_page=100&page=1"
```

- `$WP_BASE_URL` is **live production** (`https://durianbikers.com`). There is no staging
  sandbox — `staging.durianbikers.com` 301-redirects to live and is the same install.
- Never print or commit `$WP_APP_PASSWORD`.
- **Brand id alone under-covers.** Combine `?brand=<id>` with a `search=` stem and dedupe by
  product id. Use the *shortest* stem that catches singular and plural spellings.
- Expect false positives from `search=` — check the product name before including one.
- **Fetch variations with error checking.** A silently failed variation fetch looks exactly
  like "product has no variations" and has already produced one false alarm (74 phantom
  "stranded" products). Retry, and assert the response parsed before concluding zero.

## Step 3 — Match and diff

Match on **exact SKU** unless the tab reference says otherwise (HELMETS cannot — see its
reference).

- **Never substring-match SKUs.** Prefix families are distinct products: `BLP` (blanking
  plate, ~RM30) is not `LP` (tail tidy, ~RM1000). Three products were mis-aligned this way and
  were caught only by the price gap.
- **Never invent, rename or infer a SKU.** Project hard rule — ask the operator.
- Verify a suspicious alignment by **product name + reference price**, not string similarity.
- **Duplicate code in a tab** → use the first/fuller row, unless the operator says otherwise.
- **Empty variable products** (zero variations) show no price. Flag them; they are not price
  matches and cannot receive stock until variations exist.
- **Check `references/price-overrides.md` before classifying any price difference.** Some site
  prices differ from the masterlist deliberately. A listed SKU keeps `NOT MATCH ❌` (the numbers
  do differ) but is bucketed `override`, kept out of the price-differs count and out of the
  needs-a-decision list. Never correct one towards the masterlist.

Classify every row:

| Class | Meaning |
|---|---|
| IN SYNC | price and stock both match |
| STOCK DIFF | price matches, stock differs — the routine, safe-to-fix case |
| PRICE DIFF | stock matches, price differs — hold for approval |
| BOTH DIFFER | hold for approval |
| NOT ON SITE | in masterlist, no product on site |
| NO VARIATIONS | variable product with zero variations — cannot sync |
| AMBIGUOUS | several sheet rows claim the same product — disambiguate manually |

**"NOT ON SITE" with zero stock = discontinued. Skip it.** Absence from the store is
intentional, not an oversight. Report for awareness; do not create it. Only a **sellable** gap
(stock > 0) is worth raising as a product to create.

**Surplus on the site is expected.** The masterlist is a stocklist subset, not the full
catalogue. "On site, not in masterlist" is not an error list.

## Step 4 — Report

**The output format is fixed by the operator. Read `references/report-format.md` and follow it
exactly** — column order, the `WC→ML` stock cells, and a binary `MATCH ✅` / `NOT MATCH ❌`
status. Do not redesign it and do not substitute a prose summary.

**Always report the full reconciliation — every active row, matched included.** Not just the
diffs, not a top-N. A long table is the expected outcome (HELMETS is ~361 rows): write it to
`reports/` and give the operator the path. Never silently truncate it.

The Step 3 classes above still drive the work, but they do not appear in the table — they
become the NOT MATCH breakdown and the unresolved list underneath it.

Render with `scripts/render_report.py` rather than hand-assembling markdown; hand-built tables
drift in column order, arrow direction and status logic between runs.

Each run is a **fresh point-in-time snapshot**. The masterlist changes daily, Mon–Sat —
re-read it, never act on a previous run's diff list. Check the export's date against the
sheet's last-modified time before trusting it; a stale export silently misreports hidden rows.

## Step 5 — Approval gate

Stop here. The operator approves specific items before any write. Auto-updating is never
correct on this store — writes hit production and checkout takes real money.

Flag rather than auto-fix:

- price variance > 50% (usually a sheet typo — one helmet read RM1 vs RM1,099)
- stock delta > 200 units on one item (possible digit transposition)
- anything touching checkout, gateway or pricing structure

## Step 6 — Write (only after explicit approval)

`.claude/settings.json` denies `Bash(curl * --data *)`, which blocks every REST write. That
guardrail is intentional.

1. Narrate that you are lifting it, Edit `settings.json` to remove that one line.
2. Run the approved write — `POST /wp-json/wc/v3/products/batch` for stock, or
   `PUT /products/<id>/variations/<vid>` for per-size stock.
3. Immediately Edit the line back, and say so.

Do **not** evade the guardrail by swapping `--data` for `-d` or rerouting through python.

Log every change as `SKU: field old → new ✓`. If any call fails, stop and report what
succeeded and what did not — do not continue down the list.

## Step 7 — Verify

Re-query the products you wrote and confirm zero remaining diffs. Confirm no regression on
items that were already in sync.

## After the run

- Save a memory: tab name, date, counts, diffs pushed, any new gotcha.
- Create or update `references/<tab>.md` with what you learned about the layout.
- Put audit *results* in `reports/`, dated — never in this file or a tab reference. Results go
  stale within a day and will mislead the next run.
