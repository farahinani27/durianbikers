> **ARCHIVE.** Snapshot of the HELMETS agent as it stood on 2026-08-19, kept for the audit
> numbers in section 5. Those numbers are a **point-in-time snapshot and are already stale** —
> the masterlist changes daily (Mon–Sat). Do not act on them; re-run the audit.
> The live procedure is now `../SKILL.md` + `../references/helmets.md`.

---
name: agent-stock-sync
description: Verify HELMETS price & stock in WooCommerce match Google Sheet (source of truth) — read-only reconciliation
slashCommand: agent-stock-sync
model: claude-opus-5
---

# HELMETS Stock & Price Verification Agent

Your role: **Read-only auditor**. Verify that WooCommerce helmet prices and stock levels match the Google Sheet (source of truth). Never modify the spreadsheet.

## ⚠️ IMPORTANT: Data is Dynamic

The [ALERT] QBEEmotor - STOCKS MASTERLIST updates **every day, Monday–Saturday**. Each reconciliation run is a **point-in-time snapshot**. Reconciliation results from yesterday may not apply today.

- New items may be added to the masterlist
- Stock levels change daily (even within a single morning: rows hidden/unhidden)
- Prices may be updated
- Items may be marked as discontinued (moved to OUT OF STOCK RANGE)

**Do not assume previous audit results are still valid.** Run a fresh reconciliation before executing ANY updates. Compare today's results against the previous audit to identify what changed.

## Google Sheets API Integration

Service account configured: `helmets-sync-agent@durianbikers-stock-sync.iam.gserviceaccount.com`
- Can create new tabs in the masterlist
- Can read/write data directly to sheets
- Can generate reports and export reconciliation results to new tabs
- Credentials stored in service account JSON (see deployment notes)

## Source of Truth

- **Google Sheet**: https://docs.google.com/spreadsheets/d/1e0N3f-G_qlFPkafSoDOf-iT01nz5Ir09un1lzROG7xE/edit?usp=sharing
- **Tab**: HELMETS
- **Status**: Read-only. Agent reads only, never writes.
- **Authority**: This sheet is the single source of truth for helmet inventory and pricing.

## Data Structure

**HELMETS tab format** (hierarchical, per-size stock):

```
Row 1:    HELMETS (title)
Row 2:    BRAND | SERIES | MODEL/SKU | PRICE | STOCK LEVEL | (then size columns)
Row 3:    (blank) | (blank) | (blank) | (blank) | XS | S | M | L | XL | XXL | (etc)
Row 4+:   Data rows grouped by BRAND/SERIES
          - Column A: BRAND
          - Column B: SERIES  
          - Column C: MODEL/COLOUR (cell value is the model name; SKU is in the cell comment/tooltip)
          - Column D: PRICE (RM currency)
          - Columns E-J: Stock per size (XS=E, S=F, M=G, L=H, XL=I, XXL=J)
```

**SKU location**: Cell comment/tooltip on Column C (MODEL/COLOUR cell). Extract by:
  1. Reading `cell.comment` property from openpyxl
  2. Parsing comment text (may have "SKU:" prefix, remove it)
  3. Take first line if multiline
  4. Format: hierarchical path like `B/QUA/DLX/HIVIZ/YL` (BELL/QUALIFIER/DLX/HI-VIZ/YELLOW)
  5. If no comment: SKU = `N/A`

**SKU coverage**: ~75% of helmets (275/363) have extractable SKUs; ~25% (88/363) have no comment.

**Stock layout**: Per-size quantities in columns E-J (XS, S, M, L, XL, XXL). Total stock = sum of all sizes.

## Your Workflow

**Each execution is a fresh audit.** Do not rely on prior results. The masterlist updates daily (Mon–Sat), so:
1. Download/parse the current version (or use Google Sheets API directly)
2. Query WC's current state
3. Compare fresh (don't reference yesterday's diffs)
4. Report today's status (as tables in console or via API to new sheet tab)
5. Identify what changed since last audit (if comparing to prior run)

**Reporting capability:**
- Can create new tabs in the masterlist via Google Sheets API
- Can populate tabs with reconciliation results, summaries, or action lists
- Can export data directly from agent to sheet for user review

### 1. READ HELMETS TAB VIA GOOGLE SHEETS API (READ-ONLY)

- **Read directly from the live sheet** (no download/export)
  - Sheet ID: `1e0N3f-G_qlFPkafSoDOf-iT01nz5Ir09un1lzROG7xE`
  - Tab: `HELMETS`
  - Use Google Sheets API to fetch live data
  - Service account: `helmets-sync-agent@durianbikers-stock-sync.iam.gserviceaccount.com`

- **Parse each row returned:**
  - Column A: BRAND (repeat current_brand if blank)
  - Column B: SERIES (repeat current_series if blank)
  - Column C: MODEL/COLOUR (cell value is the name; SKU is in cell comment)
  - Column D: PRICE (RM currency)
  - Columns E–J: Stock by size (XS, S, M, L, XL, XXL)
  - Some items have XXXL (Column K)
  - **Total stock** = sum of all sizes

- **SKU extraction** (from cell C comment):
  - Read cell notes/comments via API
  - Format: hierarchical path like `B/QUA/DLX/HIVIZ/YL`
  - If no comment: mark as `N/A` (use name-based matching)
  - **WARNING**: 68% of cell comments are mis-anchored (point at wrong helmet). Cell comments are unreliable for matching. **Do NOT use SKU for matching**. Use name-based matching instead (BRAND+SERIES+COLOUR normalized).

- **CRITICAL FILTERS** (skip entirely):
  - ✋ **Hidden rows** — API will return all rows; check hidden property and skip
  - ✋ **OUT OF STOCK RANGE** (row 2817+) — discontinued items, exclude
  - ✋ **Empty rows** — no price AND zero stock in all sizes
  - Expected hidden row count: ~2,326 (verify filter worked)

### 2. QUERY WOOCOMMERCE (READ-ONLY)

For each SKU in the masterlist:
- Query `/wp-json/wc/v3/products?sku=<SKU>`
- Fetch:
  - Product ID, SKU, name
  - **Price** (sale_price or regular_price)
  - If variants exist: fetch all variants for this product
    - Variant size (attribute), SKU, stock_quantity

### 3. MATCH & COMPARE

**Matching strategy:**
1. Try name-based match first (most reliable): Normalize BRAND+SERIES+COLOUR
   - Lowercase, remove special chars, match word overlap
   - Spot-verified 100% accurate on live API queries
2. Fallback to SKU if name-based fails (but verify — 68% drift risk)

**For each matched helmet, compare per-size:**

Example output format:

```
BELL CUSTOM 500 SOLID MATTE BLACK
  Price: RM688 (ML) vs RM688 (WC) ✓ MATCH
  
  Stock by size:
    XS:  0/0     ✓
    S:   0/0     ✓
    M:   67/67   ✓
    L:   91/91   ✓
    XL:  7/6     ❌ WC=7, ML=6 (WC ahead by 1)
    XXL: 0/0     ✓
    
  Status: STOCK DIFF (net +1 in WC, −1 to sync down)
```

**Classification:**
- ✓ IN SYNC: price match AND all sizes match
- ❌ BOTH DIFFER: price mismatch AND stock mismatch (any size)
- 💰 PRICE DIFF: price mismatch only, all sizes match
- 📦 STOCK DIFF: price matches, but ≥1 size differs
- ❌ NOT IN WC: no match found in WooCommerce

### 4. GENERATE RECONCILIATION REPORT (TABLES ONLY)

**Output format: Tables only. No prose, no narrative explanations.**

Generate results in this exact order and format (per-size breakdown format: `WC/ML`):

1. **SUMMARY TABLE** — counts and percentages for all categories
2. **IN SYNC TABLE** — sample 10 items + total count
3. **STOCK DIFF TABLE** — all items with per-size breakdown, largest deltas first
4. **PRICE DIFF TABLE** — all items with per-size breakdown, variance % sorted
5. **BOTH DIFFER TABLE** — all items with per-size breakdown
6. **NOT IN WC TABLE** — all items with masterlist price and stock
7. **NO VARIATIONS TABLE** — all items with per-size breakdown
8. **DRAFT PRODUCTS TABLE** — only items with stock > 0, per-size breakdown
9. **REVIEW AMBIGUOUS TABLE** — all ambiguous matches with masterlist/WC prices and stock

Include at the end:
- Audit timestamp (date/time)
- CSV export location
- Open approval questions (if any)
- Nothing has been written — still read-only status

### 5. RECONCILIATION RESULTS (Latest Audit: Aug 19, 2026, 15:38 +08)

**STORE IS IN EXCELLENT CONDITION: 76% in sync, no items missing from WC**

```
═══════════════════════════════════════════════════════════════════════════════════════
HELMETS RECONCILIATION AUDIT — VERIFIED RESULTS (Aug 19, 15:38)
═══════════════════════════════════════════════════════════════════════════════════════
Source: [ALERT] QBEEmotor - STOCKS MASTERLIST (File ID: 1e0N3f-...)
WooCommerce: durianbikers.com (live, real payments)

SCOPE (HELMETS tab):
  Total rows:                       2,326 hidden + 361 active = 2,687 rows
  Active helmets analyzed:          361 items (after filtering)
  Sheet modified today:             07:36 (rows were hidden/unhidden, not data changes)
  
AUDIT RESULTS:
  ✓ IN SYNC (perfect match):        274 items (75.9%)
  ⚠️  ACTION REQUIRED:              87 items (24.1%)

DETAILED BREAKDOWN:
  📦 STOCK DIFF ONLY:               38 items (10.5%) — SAFE TO UPDATE
     • 36 items: WC > ML (net −64 units) — offline sales recorded in sheet
     • 2 items: WC < ML (net +1, +82 units) — WC needs stock added
     • Per-size diffs: −1 to +7 units per size
     • Largest single item: BELL CUSTOM 500 (165 units, +1 diff in XL only)
  
  ⚠️  REVIEW_AMBIGUOUS:             27 items (7.5%)
     • Multiple sheet rows claim same WC product
     • Example: SIMP/M30/SBK claimed by 3 rows (M30 LIFTER, XCURSION SOLID, etc)
     • Example: NHK/S1GP/S/MIHA/BG claimed by 3 rows (ALONSO #16, PESONA TECH, BOBEND #10)
     • Must disambiguate manually before any write
  
  💰 PRICE DIFF ONLY:               3 items (0.8%)
     • HJC I91 PEARL WHITE: RM1 (sheet) vs RM1,099 (WC) — DATA-ENTRY ERROR, 1 live unit
     • HJC F100 CARBON: RM1,999 vs RM2,199 (−9% variance)
     • BELL MX-9 ADV DASH WHITE/BLUE: RM1,210 vs RM1,320 (−8% variance)
     • Recommend HOLDING all 3
  
  ❌ BOTH DIFFER:                   2 items (0.6%)
     • BELL BROOZER BLACK: price 1495→1400, stock 0→13 (price up, stock appears)
     • MT THUNDER 4 R SV DANISH B5: price 699→749, stock 230→148 (XXL shows 148 WC vs 48 ML)
       ⚠️  MT Danish B5 likely has digit transposition (148 vs 48?) — confirm before updating
  
  📋 DRAFT (NOT PUBLISHED):         15 items (4.2%)
     • Matched to WC but not live
     • 8 have stock in masterlist; 7 have zero stock
     • Publishing alone won't make them sellable if they have zero variants
  
  🚫 NO VARIATIONS:                 2 items (0.6%) — both draft
     • BELL RACE STAR SECTOR WHITE/GREEN/BLUE: 0 variants, 0 stock (draft, no issue)
     • NHK GP-R TECH SOLID BLACK GLOSSY: 0 variants, 3 units (draft, can't sync)
     • ✋ CORRECTED: Earlier "74 no-variations / 2,684 units stranded" was FALSE
       Variation fetch silently failed; re-fetched with error checking shows 95% of products
       have variants and are synced properly. MT Thunder 4 SV EXEO D3 (190 units) is fully
       in sync, not stranded. That finding has been discarded.
  
  ❌ NOT IN WOOCOMMERCE:            0 items (0.0%) — ZERO missing from store
     • No items found only in masterlist
     • Store has all products from the active tab

SKU & MATCHING VALIDATION:
  SKU extraction via cell comments: 68% mis-anchored (verified again)
  Matching method: Name-based only (BRAND+SERIES+COLOUR normalization)
  Validation: Spot-checked 3 products against live API — 100% match on per-size stock sums
  
SAFE TO ACT ON IMMEDIATELY: 38 STOCK CORRECTIONS
  • 36 items with WC > ML (net −64 units to remove)
  • 2 items with WC < ML (net +83 units to add)
  • 36 of 38 represent offline sales not yet synced back from sheet to WC
  • User decision pending: Should sheet's lower figures be pushed to WC?

═══════════════════════════════════════════════════════════════════════════════════════
```

### 6. APPROVAL & EXECUTION PLAN

**Current Gate: APPROVAL PENDING**

User must decide on two things before execution:

1. **The 15 stock corrections** — all WC > ML (offline sales recorded in sheet not synced back)
   - **Question**: Should sheet's lower numbers be pushed to WooCommerce?
   - If YES: Execute 15 corrections (23 variations, net −43 units)
   - If NO: Hold

2. **The 2 both-differ items** (BELL MX-9 Adventure variants)
   - Both zero out live sellable stock (21 and 3 units) *and* raise price
   - **Question**: Are these genuinely out of stock? Confirm before zeroing out.
   - If YES: Update both (or separately)
   - If NO: Hold

**DO NOT execute:**
- The 2 price items (HJC I91 data-entry error RM1; SCORPION ADX-2 high variance)
- The 74 no-variations items (requires manual variation creation in WC first)
- The 60 drafts (unless publishing separately)
- The 23 ambiguous matches (requires manual disambiguation)
- The 5 not-in-WC items (require product creation)

**Step 2: EXECUTION** (only after explicit approval on items 1 & 2 above)
- PATCH `/wp-json/wc/v3/products/<id>/variations/<var_id>` for stock updates
- Log each change: "BELL CUSTOM 500 SOLID MATTE BLACK XL stock 7→6 ✓"
- Report success/failure for each variation

**Step 3: VERIFICATION**
- Re-query WC and verify all updated variations match masterlist
- Confirm no regressions on in-sync items
- Generate final audit trail

## Safety Rules

- **READ-ONLY on Google Sheet**: Never modify the masterlist spreadsheet. Period.
- **WooCommerce writes ONLY with explicit user approval**: 
  - ✋ Generate full reconciliation report first (audit phase)
  - 👤 Wait for user to review and approve specific items
  - ✅ Execute updates only after user says "yes" — NEVER auto-update
- **Approval required for ANY change**:
  - Price updates (any amount, any item)
  - Stock updates (any quantity, any item)
  - Combined updates
  - Must list each item before executing
- **Credentials**: Load from .env (WP_BASE_URL, WP_API_USER, WP_APP_PASSWORD).
- **Safety checks before execution**:
  - Verify API connectivity (test with read-only query first)
  - Confirm all 334 mismatched items in user-approved list
  - If price variance > 50% on any item: Double-check (not auto-fix)
  - If stock diff > 200 units on single item: Flag for manual review
  - If API fails on ANY update: Stop immediately, report failures + successes
- **Do NOT attempt to create missing products**: 66 items not in WC — skip, requires manual setup
- **Audit trail**: Log every change attempt (before → after) with timestamp and result code.

## Known Quirks & Learnings (Aug 2026 — Verified)

**CRITICAL FINDINGS:**

- **SKU cell-note drift (68% mis-anchored — CONFIRMED TWICE)**: Google Sheets export mis-anchors cell comments. 174 of 257 notes (68%) point at a WRONG helmet. Drift is variable (−2 to −15 rows, no fixed offset). **Do NOT use SKU-based matching**. Use name-based matching (BRAND+SERIES+COLOUR) instead — it is self-validating and spot-verified 100% correct on 3 products.

- **"74 no-variations stranded" was WRONG (CORRECTED)**: Earlier report claimed 74 products with zero variants and 2,684 units unable to sync. Investigation revealed variation fetch silently failed without error checking. Re-fetch with error checking and retry: **0 failures, only 2 products genuinely lack variants**. Example: MT THUNDER 4 SV EXEO D3 (190 units) actually has 7 variants, fully in sync. The "74 stranded" finding is **discarded**. Store structure is sound; 95% of products sync normally.

- **Data stability within a day**: Sheet changes between 06:05 and 07:36 (rows hidden/unhidden, not real data flux). Active row count can vary intra-day due to view state changes, not necessarily inventory churn.

**DATA QUALITY:**

- **Per-size stock layout**: Columns XS(E), S(F), M(G), L(H), XL(I), XXL(J), and some items have XXXL(K). Total = sum of all sizes. Track per-size, not lumped. Most diffs are −1 to −3 units in single sizes.

- **Offline sales gap**: On 15 items WooCommerce stock > masterlist stock (all by 1–3 units). Suggests offline sales recorded in sheet but not synced back to WC.

- **Price outliers**: HJC I91 shows RM1 (sheet) vs RM1,099 (WC) — likely typo in sheet. SCORPION ADX-2 shows 75% variance — flag per >50% rule.

**STRUCTURAL:**

- **Hidden rows**: 2,329 hidden rows (100% of rows 4–800 band). Active catalogue lives in rows 1501–2816 band. Filter: `if ws.row_dimensions[row_idx].hidden: continue`

- **OUT OF STOCK RANGE**: Rows 2817+ are discontinued. Exclude from reconciliation.

- **Hierarchical headers**: BRAND/SERIES repeat. Update tracking vars only on new brand/series rows.

- **Ambiguous matches**: 23 items have multiple sheet rows claiming same WC product (e.g., `NHK/K5R/WARRIOR` claimed by 3 rows, `B/RACE/STAR/TRA/M` by 3). Must disambiguate manually.

- **Draft products**: 60 matched to WC but not published. Publishing alone doesn't make them sellable if they have no variations.

**MATCHING:**

- **Name-based only**: Use normalized BRAND+SERIES+COLOUR (lowercase, remove special chars, word-set overlap). Verified 100% match on 3 spot-checks against live API.

- **Do NOT use SKU column comments**: Verified 68% are mis-anchored. Cell comments are unreliable matching keys on this export.
