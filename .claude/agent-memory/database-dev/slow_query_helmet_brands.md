---
name: slow-query-helmet-brands
description: N+1 product-count query in the helmet_brands shortcode on brand/category landing pages
metadata:
  type: project
---

The child theme does NOT override the WooCommerce archive/loop templates — `motormania-child/woocommerce/` only contains single-product and cart overrides. So the query cost on the "listing" / brand-category landing pages (helmet-brands, all-category, accessories-brands) comes from the shortcodes in `motormania-child/functions.php`, not the core shop loop.

Profiled cost ranking of those shortcodes (code-level, no live DB available):

- **helmet_brands_shortcode (HIGH)** — runs `get_posts( post_type=product, posts_per_page=-1, fields=ids, tax_query AND[product_brand, product_cat] )` INSIDE a `foreach` over every brand, purely to `count()` the results. N+1: one unbounded product query per brand, each a double INNER JOIN on wp_term_relationships. Should be a `COUNT` or a cached transient, not fetch-all-IDs-then-count.
- **selected_brands / all_category / custom_shop_by_brands (LOW)** — read the precomputed `$term->count` column (no live count query) and do per-item `get_term_meta` + `wp_get_attachment_image_url`, which are object-cached. Cheap.

**Why:** archive page reported slow on mobile during Aug 2026 go-live; asked for a code-level query profile.
**How to apply:** if asked to optimize these pages, the fix is replacing the helmet_brands fetch-all-IDs with a count and/or wrapping shortcode output in a transient. Confirm whether a persistent object cache (Redis/Memcached) exists on Exabytes/Plesk staging — without one, every one of these queries re-runs each request. See [[persistent-object-cache-unknown]].
