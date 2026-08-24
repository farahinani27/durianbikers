---
name: catalogue-data-quality
description: Live durianbikers.com catalogue facts from the Aug 2026 audit — 5,283 products, ~1,450 broken Shopify imports, empty short_description, no fitment attribute
metadata:
  type: project
---

Audit of the live WooCommerce catalogue, 2026-08-17. None of this is derivable
from this repo — there is no local WP install, so re-deriving it means querying
production again.

- **5,283 published products.** Too many to dump into any LLM prompt. Anything
  that needs the catalogue must retrieve, not enumerate.
- **2,612 are variable products.** Parent `regular_price` is empty on ALL of
  them by design — real prices live on the variations. Use
  `$product->get_price_html()` or `get_variation_prices()`; never read the
  parent price and conclude "no price".
- **~1,450 products (~27%) are broken Shopify import artifacts.** Variable
  products with a single junk `Option1: ["Default Title"]` attribute, ZERO
  variations, empty price, `outofstock`. Mostly Shinko tyres. Detect with
  `$product->is_type('variable') && empty($product->get_children())`. They are
  listed but unorderable — treat as "unavailable", not as "we don't carry it".
- **`short_description` is 0% populated store-wide** (0 of 783 sampled). Do not
  read it. `description` is 98.9% filled and substantial — use that.
- **No structured fitment/model attribute exists.** The only real attribute is
  `Size` (plus the junk `Option1`). Fitment is only answerable from free text in
  the name/description, so nothing automated may assert that a part fits a bike.
  Wrong fitment on a motorcycle part is a safety and returns problem.
- **Zero products are on sale; dimensions and weight are 0% populated.** Any
  feature answering discount or physical-measurement questions has no data.

Taxonomies (public, confirmed via `/wp-json/wp/v2/taxonomies`):
- `product_cat` — 60 terms
- `product_tag` — 497 terms
- `product_brand` — 21 terms. This is the NATIVE WooCommerce brand taxonomy
  (merged into core in WC 9.4; store runs 10.9.4), not a third-party plugin.
  `pwb-brand`, `yith_product_brand`, `berocket_brand`, `pa_brand` do NOT exist.
- `pa_car-brand`, `pa_car-type`, `pa_color`, `pa_parts-type` are registered but
  unused theme-demo leftovers. Do not offer them as filters anywhere.

**Why:** captured while building the chatbot plugin; the data-quality problems
directly shaped its retrieval design and its refusal rules.

**How to apply:** before writing any product-facing integration, assume the
catalogue is large, that a quarter of it is unbuyable, that descriptions are the
only prose available, and that fitment cannot be answered programmatically.
See [[chatbot-plugin-architecture]] and [[stock-sync-constraints]].
