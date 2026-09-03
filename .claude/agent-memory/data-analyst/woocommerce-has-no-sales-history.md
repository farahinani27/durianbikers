---
name: woocommerce-has-no-sales-history
description: The live WooCommerce store has effectively zero order history (2 refunded orders) — any sales/brand/velocity question must use the Shopify export instead
metadata:
  type: project
---

As of 2026-09-01 the live WooCommerce store contains **2 orders total**, both fully refunded.
Verified via `GET /wc/v3/orders?per_page=100&status=any&after=2000-01-01` (x-WP-Total by status:
any=2, processing/completed/on-hold/pending/cancelled/failed/checkout-draft=0, refunded=2).

- `58471` 2026-08-18, RM11.10, HJC I91 XXL at RM1.10 + RM10 ship, MasterCard — a **live-payments smoke test**
  (RM1.10 is not a real price; go-live gateway flip was 2026-08-14).
- `59136` 2026-08-30, RM69.95, R&G Headlight Shield HLS0155CL RM59.95 + RM10 ship, FPX, real JB customer,
  refunded 2026-09-01. Possibly a genuine order that was refunded — worth asking the operator why.

Net revenue since go-live = **RM 0.00**. `prices_include_tax: false`, so WC totals are ex-tax.

**Why:** the Shopify→WooCommerce cutover was Option A (no data copy) — order history was never migrated,
only exported to CSV. See [[golive-woocommerce-aug2026]] in the user memory store.

**How to apply:** never answer "how is X selling / what should I reorder" from `/wc/v3/orders` yet.
Say plainly that WooCommerce has no sales history and pivot to [[shopify-orders-export]].
Re-check the order count at the start of any sales question — once real orders start landing,
WooCommerce becomes the right source and this memory goes stale.
