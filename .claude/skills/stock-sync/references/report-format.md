# Report format — the output contract

**Every reconciliation run ends in this table.** The format is fixed by the operator. Do not
redesign it, do not substitute a prose summary, and do not invent extra status values.

## The rule that matters most

**Always report the FULL reconciliation — every active row, matched or not.**

Not just the diffs. Not "the top 20 deltas". Not "the 34 mismatches, the rest were fine". The
operator reads this table to confirm coverage as much as to find problems, so a row that is in
sync must still appear, with `MATCH ✅`. Truncating to the interesting rows destroys the point
of the table.

If the table is long, that is expected — HELMETS is ~361 active rows. Write it to a file in
`reports/` and give the operator the path alongside it. Never silently shorten it.

## Columns

| # | Column | Source | Notes |
|---|---|---|---|
| 1 | SKU | **WooCommerce** | Never the sheet — see "SKU source" below |
| 2 | BRANDS | masterlist col A | carried down when blank |
| 3 | SERIES | masterlist col B | carried down when blank |
| 4 | MODEL / COLOUR | masterlist col C | identifies the row; BRAND+SERIES alone repeats |
| 5 | PRICE (MASTERLIST) | masterlist col D | parse `RM1,485` → `1485.00` |
| 6 | PRICE (WOOCOMMERCE) | WC product/variation | |
| 7–13 | STOCK XS … XXXL | both | rendered `WC→ML` |
| 14 | STATUS | computed | `MATCH ✅` or `NOT MATCH ❌` |

Size columns are always all seven, in order: **XS, S, M, L, XL, XXL, XXXL**. A size the product
does not come in stays in the table as `—`; do not drop the column.

### SKU source

The HELMETS tab has **no SKU column**, and the SKUs that exist as cell notes on column C are
68% mis-anchored (verified twice). So the SKU printed here is the SKU of the **matched
WooCommerce product** — it is a lookup result, not sheet data.

- No site match → SKU cell is `—`.
- **Never** synthesise, infer or reconstruct a SKU to fill the cell. Project hard rule.

### Stock cells: `WC→ML`

Read as "the site says this, the masterlist says that". WooCommerce first, masterlist second,
because the direction of any fix is WC → corrected to → ML.

- Both sides equal: `2→2`. Still printed in full; do not collapse to `2` or blank.
- Differs: `3→2`.
- Size not offered on either side: `—`.
- Size exists on site but the sheet cell is blank: blank means zero → `3→0`.
- No site match at all: `—→2` (masterlist value only).

### PRICE columns

Both as plain numbers to 2dp, no `RM` prefix — the currency is uniform and the prefix makes the
column harder to scan. Empty variable product (zero variations) has no price → `—`.

### STATUS

`MATCH ✅` only when **price matches AND every compared size matches**. Anything else is
`NOT MATCH ❌`. Two values only — the operator asked for a binary flag.

Sizes rendered `—` on both sides are not compared and cannot cause a mismatch.

## What goes with the table

The binary STATUS deliberately loses *why* a row failed. Do not push that detail into the
table — put it in a short section underneath:

1. **Counts** — total active rows, `MATCH ✅`, `NOT MATCH ❌`, and a breakdown of the ❌ rows
   by cause (stock only / price only / both / not on site / no variations / ambiguous).
2. **Unresolved rows** — ambiguous matches, empty variable products, and anything the run could
   not decide, with the sheet row number so it can be traced back to a cell.
3. **Run footer** — audit timestamp, workbook filename and its export date, the `reports/` path,
   and while still read-only an explicit **"nothing has been written"** line.

## Rendering

`scripts/render_report.py` emits exactly this table from reconciled JSON. Use it rather than
hand-assembling the markdown — hand-built tables drift in column order, arrow direction and
status logic between runs.

```bash
python3 .claude/skills/stock-sync/scripts/render_report.py reconciled.json \
    --out reports/$(date +%F)-helmets-report.md
```

## Other tabs

The core is tab-agnostic: identity columns, then `PRICE (MASTERLIST)` / `PRICE (WOOCOMMERCE)`,
then stock as `WC→ML`, then `STATUS`. What changes per tab is the identity columns and whether
stock is per-size or a single total.

- Tabs with a real SKU column: SKU comes from the sheet and is the join key; still print the WC
  SKU if they disagree, and flag it.
- Tabs without per-size stock: collapse columns 7–13 to one `STOCK (WC→ML)` column.
