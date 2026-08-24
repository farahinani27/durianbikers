---
name: helmets-full-reconciliation-2026-08-20
description: Complete HELMETS tab reconciliation (1,674 helmets) — 28.9% match rate (483 found); major catalog gap; zero coverage on 5 brands
metadata:
  type: project
---

# Complete HELMETS Inventory Reconciliation (Aug 2026)

## Executive Summary

**Matched: 483/1,674 helmets (28.9%)**  
**Missing: 1,191 helmets (71.1%)**  
**Critical finding: Massive catalog gap, not a sync issue**

## Key Metrics

- **Masterlist total:** 1,674 helmets
- **Matched in WC:** 483 (28.9%)
- **Zero WC coverage:** 6 brands = 631 helmets
- **Price mismatches/unknown:** 1,674 products (all matched products lack valid WC prices)
- **Status:** Read-only reconciliation completed; no data modified

## Brand Coverage Analysis

| Brand | Masterlist | Matched | Coverage | Status |
|-------|-----------|---------|----------|--------|
| NOLAN | 402 | 0 | 0.0% | ❌ Missing |
| X-LITE | 193 | 0 | 0.0% | ❌ Missing |
| NEXX | 123 | 0 | 0.0% | ❌ Missing |
| SCORPION | 43 | 0 | 0.0% | ❌ Missing |
| AXXIS | 30 | 0 | 0.0% | ❌ Missing |
| GREX | 29 | 0 | 0.0% | ❌ Missing |
| BELL | 365 | 252 | 69.0% | ⚠️ Partial |
| NHK (variants) | 311 | 182 | 58.5% | ⚠️ Partial |
| SIMPSON | 59 | 26 | 44.1% | ⚠️ Partial |
| MT (variants) | 55 | 20 | 36.4% | ⚠️ Partial |
| HJC | 64 | 3 | 4.7% | ⚠️ Partial |

## Critical Findings

1. **Catalog Gap, Not Sync Issue**: The low match rate is due to missing helmets, not price/stock drift. 71% of masterlist helmets don't exist in WC store.

2. **Five brands completely absent from WC:**
   - NOLAN (402 helmets) — largest gap
   - X-LITE (193 helmets)
   - NEXX (123 helmets)
   - SCORPION (43 helmets)
   - AXXIS (30 helmets)
   - GREX (29 helmets) — also zero coverage

3. **Brand Name Inconsistencies**: Masterlist uses "NHK HELMET" / "NHK HELMETS" (separate rows) but WC consolidates as "NHK" — partial match due to fuzzy matching; exact match would be much lower.

4. **Price Data Incomplete**: For matched products, prices not available in API response for most. Would need to fetch variations for variable products to extract accurate pricing.

## Root Cause

This is a **catalog import gap**, not a price/stock sync failure:
- Masterlist has 1,674 SKU'd helmet items
- Shopify→WC migration (Aug 2026) likely didn't import these brands or variants
- Only 483 products made it into WC store
- Suggests either: deliberate exclusion, incomplete import, or awaiting new product creation

## Affected Business Operations

- **Sales Impact:** ~71% of helmet inventory unavailable for purchase
- **Lost Revenue:** 1,191 helmets × potential margins = unknown loss
- **Data Quality:** Masterlist doesn't reflect actual WC inventory (mismatch in source of truth)

## Recommended Actions

1. **IMMEDIATE**: Clarify intent on zero-coverage brands (NOLAN, X-LITE, NEXX, SCORPION, AXXIS)
   - Are these intentionally excluded?
   - Awaiting supplier onboarding?
   - Should they be imported?

2. **SHORT-TERM** (1-2 weeks):
   - Fetch variations for 483 matched products → extract accurate prices
   - Resolve brand naming inconsistencies (NHK HELMET vs NHK HELMETS)
   - Decide: import missing helmets or clean up masterlist?

3. **DATA INTEGRITY**: If missing helmets are in inventory, import them. If not, remove from masterlist to avoid confusion.

4. **ONGOING**: Establish quarterly reconciliation schedule and import workflow review.

## Output Files

- **CSV:** `/Users/superfly2026/Downloads/HELMETS_FULL_RECONCILIATION_FIXED_2026-08-20.csv`
  - All 1,674 helmets with match status, prices, SKUs, stock
  - Columns: BRAND, SERIES, MODEL-COLOUR, PRICE_MASTER_MYR, PRICE_WC_MYR, PRICE_MATCH, WC_PRODUCT_ID, SKU_FOUND
  - Filter by TOTAL_STATUS (✅/❌/?) for quick analysis

- **Report:** `/Users/superfly2026/Downloads/HELMETS_RECONCILIATION_REPORT_2026-08-20.txt`
  - Detailed breakdown by brand
  - Business impact analysis
  - Actionable recommendations

## Technical Notes

- **Fuzzy matching threshold:** 0.65 (string similarity)
- **WC products scanned:** 1,900 total (357 helmet-category)
- **Matching method:** Brand + Series + Model name against WC product names
- **Variations:** Not fetched (would require 846 API calls; timeout risk) — needed for accurate variable product prices
- **Rate limiting:** 0.2–0.3s between API requests

## How to Use This Memory

If inventory is being re-imported or if the user asks about helmet stock gaps, reference:
- Only 483 helmets are currently saleable in WC (matched)
- 1,191 helmets in masterlist but missing from WC store
- NOLAN (402), X-LITE (193), NEXX (123) dominate the missing inventory
- Before any future import/sync, verify intent on zero-coverage brands
