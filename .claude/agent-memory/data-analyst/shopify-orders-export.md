---
name: shopify-orders-export
description: Location, shape and parsing traps of the Shopify orders CSV — the only real sales history the business has
metadata:
  type: reference
---

`/Users/superfly2026/Downloads/shopify28-8-2026_orders_export_1.csv` — exported 2026-08-28 before the
Shopify plan was cancelled. 1,789 line rows / 1,513 orders, **2021-03-27 → 2026-06-17**.
Also present: `/Users/superfly2026/Downloads/wc-product-export-28-8-2026-1787910964407.csv`.

⚠️ **This file is the ONLY sales history the business has** and it lives in `~/Downloads`, outside the
repo and outside backup. The Shopify store deactivates **2026-09-21** (data retrievable 2yr after).
Recommended to the operator: copy it into the project. Do not re-export to `~/Downloads`.

**Parsing traps (all confirmed the hard way):**
- Order-level fields (`Currency`, `Financial Status`, `Total`, `Subtotal`, `Cancelled at`, `Refunded Amount`,
  `Source`) appear **only on the first line row of each order**. `Created at` is on *every* row — so
  forward-filling keyed on `Created at` silently does nothing. Group by `Name` and take the first
  non-blank value per field.
- **Currency is USD (1,450 orders) and AUD (63) — never MYR.** Shopify was the international business.
  Do not mix with WooCommerce MYR figures.
- `Lineitem name == "Tip"` are **customer tips, not sales** — 29 rows, USD 276. Exclude from brand rankings
  or they land in a phantom "(no vendor set)" brand.
- `Vendor` is the brand but is split by helmet type: `Bell Helmets (Full Face)` / `(Open Face)` / `(Off Road)` /
  `(Flip Up / Modular)` are all Bell. Strip the trailing parenthetical to get the brand root — 42 raw
  vendor strings collapse to ~23 brands.
- `Vendor` is **blank on 60 line rows** and mislabelled `Durian Bikers` (the house brand) on 3 third-party
  products, incl. a USD 1,211 Muc Off valve bulk line that otherwise fabricates a fake top-10 brand.
  Attribute those by keyword on `Lineitem name`.
- `Source`: `205641` (726) and `web` (674) are the storefronts, `sell-on-amazon` (99) is a real
  separate channel worth splitting out.

**Standard exclusions used for the brand ranking** (state these every time): drop `Cancelled at` non-blank,
`Financial Status` in (pending, voided, refunded), non-USD, and Tip rows. 1,789 → 1,481 rows.
Keep `partially_refunded` (gross, not netted down — a known slight overstatement).
