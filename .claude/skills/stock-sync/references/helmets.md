# Tab: HELMETS

**Status:** audited 2026-08-19 (see `../reports/2026-08-19-helmets-audit.md`).
**First writes applied 2026-08-24** — MT THUNDER 4 R SV only (rows 2806–2810, 14 variations,
stock only). The rest of the tab is still unwritten.

This is the hardest tab — the only one where SKU matching does not work at all.

## Layout
Hierarchical, per-size stock. **No SKU column.**

```
Row 1     HELMETS (title)
Row 2     BRAND | SERIES | MODEL/COLOUR | PRICE | STOCK LEVEL | (size columns)
Row 3     (blank ×4) | XS | S | M | L | XL | XXL | XXXL
Row 4+    data, grouped by BRAND / SERIES
```

| Col | Field |
|---|---|
| A | BRAND (blank = repeat previous) |
| B | SERIES (blank = repeat previous) |
| C | MODEL / COLOUR |
| D | PRICE (RM, text-formatted e.g. `RM1,485`) |
| E–J | stock per size: XS, S, M, L, XL, XXL |
| K | XXXL on some items |

**Total stock = sum of all size columns.** Track per-size — most diffs are −1 to −3 units in a
single size, and each must be written to the matching *variation*, not the parent product.

```bash
python3 .claude/skills/stock-sync/scripts/read_masterlist.py HELMETS \
    --carry 0,1 --stop-at 'OUT OF STOCK RANGE' --max-col 12
```

Verified live 2026-08-21: **377 rows returned − 3 header rows = 374 data rows**, 2,326 hidden
rows skipped, banner hit at real sheet row 2817. Column L (`TOTAL STOCKS`) is a per-row
checksum — validate the sum of E–K against it.

### Sub-group label rows — 11 of them, exclude

Inside a BRAND/SERIES block the sheet inserts a bare **group label** in column C with no price
and no stock in any size: `SOLID`, `EPIC`, `SHIELD`, `PARROT`, `BIONIC`. The colour rows that
follow belong to that sub-series. Verified 2026-08-21: rows 2494, 2559, 2564, 2569, 2574, 2578,
2581, 2585, 2597, 2608, 2621.

They are **not products** — counting them inflates the active set by 11. The test is
`no price AND no stock in any size AND no TOTAL`; a row with a price but zero stock everywhere
(e.g. row 1755) *is* a real product and stays.

Do not just drop them: **carry the label down** as an optional qualifier on the rows beneath.
It is what disambiguates `AXXIS / AXXIS / B2 GLOSS GRAY` appearing twice (row 2566 under `EPIC`
→ *Gecko Epic*, row 2570 under `SHIELD` → *Gecko Shield*). Score it as a bonus, never as a
required token — SIMPSON blocks carry a `SOLID` label the store name does not repeat.

374 data rows − 2 non-product rows − 11 group labels = **361 active product rows**.

Two rows come back with no MODEL/COLOUR and are **not products** — exclude both:
- **row 2816** — grand-total row, carries `6954` in TOTAL STOCKS.
- **row 2478** — `NHK / S1GP S ALONSO #16` with an empty model cell. Ask the operator.

## Filters (skip entirely) — operator rule, not a heuristic

- **Hidden rows — skip entirely.** ~2,326 of ~2,687. Only visible rows are the active
  catalogue. `if ws.row_dimensions[i].hidden: continue`
- **Row 2817 and beyond — skip entirely.** That is the `OUT OF STOCK RANGE` banner;
  everything from it downward is discontinued. Stop there unconditionally. Match the banner
  text *and* bound the row number — if an insert above shifts the banner, the text match is
  what is correct, and a disagreement between the two means re-check before trusting either.
- Empty rows: no price *and* zero stock in every size.

Hidden-row state survives **only** in an `.xlsx` export or via the Sheets API. A CSV or a text
dump of the sheet loses it, and every hidden row then reads as active — which silently inflates
the active set nearly eightfold. Never reconcile from a CSV of this tab.

Active set ≈ 361–363 items across Bell, NHK, HJC, Simpson, Scorpion, Axxis, MT.
Nolan / X-Lite / Nexx / Grex sit entirely on hidden rows → not carried online.

## ⚠️ Matching: name-based, NOT SKU

SKUs exist only as **cell notes/tooltips on column C**, and Google's xlsx export
**mis-anchors them** — verified twice, **68% (174 of 257) point at the wrong helmet**. Drift is
variable (−2 to −15 rows, no fixed offset), so no offset correction is possible.
`read_file_content` returns no cell notes at all.

**Match on normalised BRAND + SERIES + COLOUR** — lowercase, strip special characters, word-set
overlap. Spot-verified 100% correct against the live API on 3 products.

Store names read `<Brand> Helmet <SERIES> (<COLOUR>)`, but the word `Helmet` is missing on some
and the series carries extra qualifiers the sheet omits (`Gecko`, `Bandit`, `Solid`, `Mips`).
So score **asymmetrically**: coverage of the *sheet* tokens by the store name is what matters;
reverse coverage is only a tie-break. Four normalisations were each needed to avoid a false
negative (verified 2026-08-21):

- **Adjacent-token concatenation**, both directions — sheet `R1 S` vs store `R1S`, `MX 9` vs `MX9`.
- **Strip `#`** — sheet `ALONSO #16` vs store `Alonso 16`.
- **Substring synonyms**, applied inside compound tokens, not just whole ones —
  `MATTE`→`MATT`, `GLOSSY`→`GLOSS`, `GREY`→`GRAY`. The store has a single token `BlackGlossy`;
  normalising only whole tokens leaves it unmatchable.
- **A SERIES equal to the BRAND is a placeholder** — every AXXIS row reads `AXXIS / AXXIS / …`
  and the real series (`Gecko`) exists only on the store side. Drop it from the sheet tokens.

Accept only on high sheet-token coverage **with a clear gap to the runner-up**; prefer the
`publish` product when a draft duplicate ties exactly. Anything else goes to manual review —
and before calling a row NOT ON SITE, search the *whole* store for its distinctive token, not
just the brand-filtered pool.

**Check whether a neighbouring sheet row already claims the candidate before force-matching a
near miss.** Rows 1754, 2644 and 2672 all look like fuzzy-match failures; they are not. Rows
1752/1753, 2642 and 2671 already hold those products, so the leftover row is a colourway the
store does not carry. Force-matching them would have double-counted stock.

Do **not** create a product from a note-derived SKU. To get a real SKU: read the live sheet via
the Sheets API, or have the operator paste it.

Normalise brand spellings first: `MT` / `MT HELMET` / `MT HELMETS`, and
`NHK` / `NHK HELMET` / `NHK HELMETS`.

## Traps
- **Ambiguous matches (~23–27 items).** Several sheet rows claim the same WC product — e.g.
  `SIMP/M30/SBK` claimed by 3 rows, `NHK/S1GP/S/MIHA/BG` by 3. Disambiguate manually; never
  write to an ambiguous match.
- **Broken-import duplicates.** Draft/duplicate products squat on natural SKUs (e.g.
  `SIMPS/DSB/GB/M` held by a draft dup, blocking variation creation on the live product). If a
  variation create fails with "Invalid or duplicated SKU", search that SKU and trash the draft
  dup first.
- **Variation fetch fails silently.** An earlier report claimed "74 products with no variations,
  2,684 units stranded" — the fetch had failed without error checking. Re-fetched with retries:
  only **2** genuinely lack variations. Always error-check and retry before reporting this class.
- **Drafts** matched to WC but unpublished (~15). Publishing alone does not make one sellable if
  it has no variations.
- **Price outliers are usually sheet typos** — HJC I91 read RM1 vs RM1,099 on site. Hold, ask.
- **Offline-sales gap**: on many items WC stock > masterlist by 1–3 units — sales recorded in
  the sheet but never synced back. This is the routine diff class on this tab.
- **Intra-day churn**: rows get hidden/unhidden during the morning, so the active row count
  moves without any real inventory change.

## Report

Output goes in the fixed operator format — see `report-format.md`. On this tab specifically:

- The **SKU column is populated from the matched WooCommerce product**, never from the sheet.
  There is no SKU column here and the cell-note SKUs are 68% mis-anchored (see Matching above). No site
  match → `—`, never a reconstructed SKU.
- All seven size columns (XS…XXXL) always print, as `WC→ML`, even for a helmet that only comes
  in three sizes — the unused ones are `—`.
- Report **all ~361 active rows**, `MATCH ✅` ones included.

## Operator rule
A live product whose only masterlist match is a **hidden** row and which has no SKU note is not
meant to be on WooCommerce — candidate for removal (ask first).
