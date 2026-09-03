# Intentional price overrides

WooCommerce prices that **deliberately differ** from the masterlist. The operator set these on
purpose; they are not drift and not sheet typos.

**Check this file during Step 3, before classifying any price difference.** A SKU listed here
is not a decision to be re-litigated — it must not appear in the "needs a decision" list, and
it must not be counted in the price-differs bucket that drives the approval gate.

Its `STATUS` still reads `NOT MATCH ❌`, because the two numbers genuinely differ and hiding
that would be a lie. What changes is the cause bucket (`override`, not `price`) and the fact
that it needs no action.

**Never "correct" one of these towards the masterlist.** The site price is the intended one.

| SKU | Item | Masterlist | WooCommerce | Noted | Note |
|---|---|---|---|---|---|
| `MT/THUNDER/4RSV/DANI/B5/G` | MT Thunder 4 R SV — Danish B5 Gloss (Graphic Color) | 699.00 | **749.00** | 2026-08-21 | Operator set RM749 on WooCommerce deliberately. |
| `MT/THUNDER/4RSV/PURE/A1/GB` | MT Thunder 4 R SV — Pure A1 Gloss Black (Solid Color) | 650.00 | **749.00** | 2026-08-24 | Operator set RM749 deliberately — whole R SV family levelled to one price. |
| `MT/THUNDER/4RSV/WT/B13/G` | MT Thunder 4 R SV — Wind Tunnel B13 Gloss (Graphic Color) | 699.00 | **749.00** | 2026-08-24 | As above. |
| `MT/THUNDER/4RSV/RELE/C15/G` | MT Thunder 4 R SV — Relevant C5 Gloss (Graphic Color) | 699.00 | **749.00** | 2026-08-24 | As above. SKU reads `C15`, sheet and store name read `C5`. |
| `MT/THUNDER/4RSV/YAMA/C5/G` | MT Thunder 4 R SV — Yamanaka 26 Season C5 Gloss (Graphic Color) | 699.00 | **749.00** | 2026-08-24 | As above. |

All five MT THUNDER **4 R SV** colourways now sit at RM749 on the site. This does **not** extend to
the non-R `THUNDER 4 SV` family (RM599, sheet and site agree) — those are a different product and
carry no override.

## Adding an entry

Only on an explicit statement from the operator that a site price is intentional. Never infer
one from a repeated difference — a difference that recurs every run is equally consistent with
a sync that has never been done.

Record the SKU exactly as it appears on the WooCommerce product. Never a substring or a family
prefix: `BLP` is not `LP`, and a prefix match here would silently exempt unrelated products
from price checks.

If the masterlist later changes to agree with the site, delete the row — a stale override
suppresses a real future difference.
