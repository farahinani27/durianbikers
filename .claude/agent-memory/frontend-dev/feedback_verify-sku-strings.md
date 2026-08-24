---
name: verify-sku-strings
description: A slash-separated string from the owner is usually ONE compound SKU, not a list — always confirm via the REST ?sku= lookup before building on it
metadata:
  type: feedback
---

When the owner answers with something like `MT/STINGER2/FD/A2`, treat it as a
single compound SKU until proven otherwise, and confirm with a read-only lookup
before designing around it:
`curl -u ... --get "$WP_BASE_URL/wp-json/wc/v3/products" --data-urlencode "sku=<string>"`.

**Why:** on 2026-08-17 that string was read as four hand-picked products and the
proposal was built as a four-item carousel. It is one product — one variable
helmet with 7 size variations — which removed the carousel entirely and made an
in-hero add-to-cart impossible. CLAUDE.md also forbids inferring SKUs, so
guessing the delimiter is doubly wrong.

**How to apply:** verify first, then re-scope. The lookup is a GET and safe
against production. The product type matters as much as the count — variable
products cannot be added to the cart from anywhere but a page with a variation
selector, so any "Add to cart" CTA outside a PDP has to become "View product".

Related: [[product-photo-constraints]]
