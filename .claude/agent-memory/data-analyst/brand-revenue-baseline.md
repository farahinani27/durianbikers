---
name: brand-revenue-baseline
description: Baseline brand ranking by revenue and units (Shopify 2021-2026) and the catalogue-vs-revenue mismatch — compare future figures against this
metadata:
  type: project
---

Brand ranking from [[shopify-orders-export]], **2021-06-10 → 2026-06-16**, USD, paid + partially_refunded,
net of line discounts, **excluding shipping and tax**. USD 314,838 / 1,532 units / 1,270 orders.

| # | brand | USD rev | %rev | units | ASP |
|---|---|---|---|---|---|
| 1 | Bell Helmets | 206,404 | 65.6% | 893 | 231 |
| 2 | X-Lite Helmets | 29,278 | 9.3% | 74 | 396 |
| 3 | Nolan Helmets | 17,642 | 5.6% | 130 | 136 |
| 4 | HEL Performance | 17,579 | 5.6% | 66 | 266 |
| 5 | Barkbusters | 7,959 | 2.5% | 80 | 99 |
| 6 | SW Motech | 7,374 | 2.3% | 50 | 147 |
| 7 | R&G | 7,315 | 2.3% | 91 | 80 |
| 8 | BMC Air Filters | 6,898 | 2.2% | 58 | 119 |

Units rank differs: Bell 893 > Nolan 130 > R&G 91 > Barkbusters 80 > X-Lite 74 > HEL 66.

**One brand is the business.** Bell = 65.6% of revenue and 58.3% of units. Helmets overall
(Bell + X-Lite + Nolan + MT + Simpson + Grex) ≈ 82% of revenue. This is a helmet retailer with a
parts sideline, not a general parts retailer — frame every analysis that way.

**Catalogue investment is inverted vs revenue** (WooCommerce `/wc/v3/products/brands`, 2026-09-01,
5,208 brand-assigned products):
- **R&G = 2,902 products (55.8% of catalogue) for 2.3% of historic revenue.** Biggest mismatch on the site.
  Note most of the ~1,450 broken zero-variation imports sit here and in Shinko — see the catalogue audit.
- Bell = 224 products (4.3%) for 65.6% of revenue.
- **X-Lite and Nolan are NOT in the WooCommerce catalogue at all** despite being #2 and #3 by revenue
  (combined 14.9%, USD 46,920). Also missing: RK Sprocket, Grex.
- Catalogue brands with zero Shopify history: HJC (129 products), Superfly (29), Healtech (28),
  Supersprox (11), Zero Gravity (7).

**Sales collapsed after 2022** — net USD by half-year: 2021H2 99k, 2022H1 78k, 2022H2 89k, 2023H1 24k,
2023H2 5k, then USD 4–8k per half-year through 2025, 1k in 2026H1. Trailing 12 months to the final
Shopify order = **4 orders, 5 units, USD 1,055, all Bell.** Any "recent" brand ranking is uncallable
on volume; the 2021-2022 peak is the only statistically meaningful window.

**How to apply:** use these as the comparison baseline once WooCommerce orders start accumulating.
Do not present the trailing-12M split as a ranking — n is far too small. Do not quote these USD figures
as if they were MYR.
