---
name: helmets-reconciliation-aug2026
description: HELMETS tab reconciliation (Aug 2026) — 89 SKU'd masterlist products (BELL, NHK, HJC, 978 units), only 5 match WC (5.6% coverage); 84 SKU's missing from store
metadata:
  type: project
---

# HELMETS Inventory Reconciliation (Aug 2026)

## Critical Finding: SKU-Level Match Rate

**Only 5 of 89 masterlist SKU'd products match WooCommerce (5.6% match rate).**

- Masterlist SKU'd products: 89 (BELL 16 units + 600 more, NHK 101 units, HJC, others = 978 units total)
- WC helmet products with SKU: 49 (mostly accessories, some NHK, HJC)
- **Matched by SKU:** 5 products (4 NHK, 1 HJC) — all out of stock in both systems
- **Unmatched:** 84 masterlist SKU's have zero presence in WC
- **Gap:** 84 SKU's representing ~900 units of master inventory not in WC store

## Masterlist (HELMETS tab, Excel rows 1–2816, with SKU cell notes)

Products with SKU cell notes on MODEL/COLOUR column: **89 products total**
- **Total stock:** 978 units (46 SKU's in-stock, 43 out of stock)
- **Brands:** BELL (largest brand: 16+ SKUs with stock), NHK (101 units in stock), HJC, SHOEI, others
- **Top in-stock products:**
  1. BELL MX-9 ADVENTURE GLOSS WHITE: 52 units
  2. BELL BULLITT MATTE BLACK: 98 units
  3. BELL BULLITT SE BARRACUDA: 30 units
- **Price range:** RM284–RM5,750
- **SKU format:** e.g., `B/BUL/MB` (BELL), `NHK/S1GP/S/ALON16/BG` (NHK), `HJC/I31/TEV/MC3SF` (HJC)

## WooCommerce (durianbikers.com)

- **Total products queried:** 49 helmet-related with SKUs
- **Brands present:** NHK (4 SKU's), HJC (1 SKU), MT, SIMPSON, accessories
- **Stock status:** All matched SKU's show 0 stock in WC (despite varying ML stock)
- **Pricing:** When available, matches or differs by factor

## SKU-Level Reconciliation Result

**Matched by SKU: 5 products**
1. NHK/S1GP/S/ALON16/BG — ✓ Price match (RM284), Stock: ML 0 → WC 0 ✓
2. NHK/S1GP/S/MIHA/BG — ✓ Price match (RM284), Stock: ML 0 → WC 0 ✓
3. NHK/K5R/Alonso#12 — ✓ Price match (RM449), Stock: ML 0 → WC 0 ✓
4. NHK/K5R/TRIANGLE — ✗ Stock mismatch: ML 3 → WC 0 **[UPDATE STOCK]**
5. HJC/I31/TEV/MC3SF — ✗ Price mismatch: ML RM1,299 → WC 799 **[UPDATE PRICE]**

**Unmatched (ML only): 84 products**
- BELL brand dominates: 16 SKUs (B/BUL/MB, B/MX9/ADV/WHITE, etc.) with ~400+ units total
- These products are in masterlist but completely absent from WC store

## Business Impact

1. **Severe Catalog Gap:** 84 masterlist SKU's (900+ units) have zero WC presence
2. **Price Sync Required:** 1 matched product has price mismatch (RM1,299 vs 799)
3. **Stock Sync Required:** 1 matched product has stock mismatch (3 vs 0 units for NHK K5R/TRIANGLE)
4. **Inventory Risk:** BELL line (52–98 unit SKUs) completely missing from store

## Action Required

1. **Immediate:** Update 2 matched products:
   - HJC/I31/TEV/MC3SF: sync price from RM1,299 to 799
   - NHK/K5R/TRIANGLE: sync stock from 3 units to WC

2. **Strategic Decision:** Handle 84 unmatched SKU's:
   - Option A: Add BELL, missing HJC/SHOEI SKUs to WC as new products
   - Option B: Verify SKU format is correct (maybe WC uses different mapping)
   - Option C: Mark as discontinued if intentionally excluded

3. **Data Quality:** Investigate why masterlist SKU's don't match WC
   - Is SKU mapping layer missing?
   - Did Shopify→WC migration lose SKU data?
   - Need manual SKU mapping table?

## How to Access Results

**CSV Report:** `/private/tmp/claude-501/scratchpad/helmets_final_sku_reconciliation.csv`
- 90 rows: 5 matched + 84 unmatched
- Columns: Status, SKU, ML Product, WC Product, prices, stock, action needed
- Filter by Status = UNMATCHED to find products to add to WC

**JSON Data:** Available in scratchpad for programmatic access:
- `sku_products_masterlist.json` — 89 masterlist SKU'd products
- `wc_helmets_all_detailed.json` — 49 WC helmet products with variations

## Parsing Rules (Excel file with SKU notes)

**Excel HELMETS sheet, rows 1–2816** (skip 2817+ OUT OF STOCK RANGE):
- Column A: BRAND, B: SERIES, C: MODEL/COLOUR, D: PRICE, E–K: STOCK (XS, S, M, L, XL, XXL, XXXL)
- **Filter rule:** Include row only if Column C (MODEL/COLOUR) has cell comment with SKU
- Cell notes format: Text like `B/BUL/MB` or `NHK/S1GP/S/ALON16/BG`
- Use openpyxl to read: `ws.cell(row, col).comment.text`
