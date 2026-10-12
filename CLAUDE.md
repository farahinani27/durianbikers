# Durian Bikers

Malaysian motorcycle parts and accessories retailer.

## Storefronts

- durianbikers.com/gb     - international store
- durianbikers.com/my  - local (Malaysia) store
- Platform: WordPress + WooCommerce
- Theme: MotorMania v1.1.9  (child theme: MotorMania Child)
- Page builder: Elementor
- Hosting / panel: Exabytes / Plesk
- PHP version: 8.3.35   WordPress: 7.1.3   WooCommerce: 10.9.4
  (verified live 2026-10-12 via `wc/v3/system_status`; re-check there rather
  than trusting this line)

## Local development

- No local WordPress install. There is no local site to preview against.
- No build step. CSS and JS are edited directly, not compiled.

**⚠️ This repo is NOT the source of truth for the theme.** Corrected 2026-10-12:
the live theme is AHEAD of this repo, because people edit it directly on the
server. Measured that day, `motormania-child` differed on five files — and the
divergence is TWO-WAY, so a one-way copy in either direction destroys work:

| file | local | server |
|---|---|---|
| `functions.php` | 563 lines | **1077** |
| `style.css` | 5182 | 5442 |
| `assets/js/custom.js` | 275 | 356 |
| `footer.php` | 2 local-only lines | 2 server-only lines |
| `template-parts/header/header-2.php` | **66 local-only lines** | 26 server-only |

The repo also holds ~22 files that were never deployed (hero-banner templates,
`.glb` models, `assets/mock/`). **Always diff against the server before
uploading a theme file.** Uploading a stale `functions.php` on 2026-10-12 would
have silently wiped 514 lines of live code. A snapshot of the server's versions
of the five differing files is in `docs/server-snapshot-2026-10-12/`; merging
them is a per-file judgement call, not a mechanical sync.

### The live docroot is NOT `httpdocs`

```
/var/www/vhosts/durianbikers.com/staging.durianbikers.com/
```

`httpdocs` holds the old unused "Bloggers Unite" install (the rollback docroot).
**Uploading there does nothing, with no error message** — this cost four failed
deploy attempts on 2026-10-12. Confirm the path from `wc/v3/system_status` →
`log_directory` if ever in doubt.

### Deploy

SSH works and is the reliable route (full un-chrooted shell, WP-CLI 2.12.0):

```
ssh -i ~/.ssh/durianbikers_vps -p 8288 durianbi@103.233.1.74
```

Note port **8288**, not 22. Plesk File Manager also works but is manual and was
the source of the failed attempts above. After deploying, purge LiteSpeed Cache.

## Website updates via REST API

- Content/store changes (products, pages, media, etc.) go through the
  WordPress/WooCommerce REST API, NOT the theme files.
- Credentials live in the gitignored `.env` at the repo root:
  `WP_BASE_URL`, `WP_API_USER`, `WP_APP_PASSWORD` (a WordPress
  Application Password — an API token, not the login password).
- Source it, then call the API. Example (zsh/bash):
  `set -a; . ./.env; set +a` then
  `curl -u "$WP_API_USER:$WP_APP_PASSWORD" "$WP_BASE_URL/wp-json/wc/v3/products"`.
  PowerShell equivalent: load the vars from `.env`, then use
  `Invoke-RestMethod` with a Basic auth header.
- `.env` currently points at **live** (`WP_BASE_URL=https://durianbikers.com`).
  Since the Aug 2026 go-live, staging.durianbikers.com 301-redirects to the
  live domain, so there is no separate staging write sandbox anymore. Every
  REST API write with these creds hits **production** immediately — confirm
  the host and the exact change before any write. Never print or commit the
  password.
- When I ask to "update the website", use these creds automatically —
  no need to ask me where they are.

## Stock masterlist (Google Sheet)

- Title: [ALERT] QBEEmotor - STOCKS MASTERLIST
- Owner: admindurianbikers@gmail.com
- File ID: `1e0N3f-G_qlFPkafSoDOf-iT01nz5Ir09un1lzROG7xE`
- URL: https://docs.google.com/spreadsheets/d/1e0N3f-G_qlFPkafSoDOf-iT01nz5Ir09un1lzROG7xE/edit
- Source of truth for stock sync. One tab per brand (SHINKO, HELMETS, R&G,
  BARKBUSTERS, DP BRAKES, …); most brand tabs list PART NO. / STOCK LEVEL /
  RRP and key to the WooCommerce SKU. Exception: the HELMETS tab has no SKU
  column (BRAND / SERIES / MODEL-COLOUR / PRICE / per-size stock), so it
  can't be auto-matched by SKU.
- Reading it requires the Google Drive connector connected AND view
  permission on the sheet (share from the owner account). The link alone is
  only a pointer, not access — without both, opening it returns a
  permission error.
- The sheet is large; a full export can exceed tool limits. Export to xlsx
  and read the target tab (e.g. with openpyxl) rather than dumping the whole
  file.

## Conventions

- SKU format: No fixed convention yet. Do not generate, change, or
  infer SKUs. If a task involves SKUs, ask me first.
- Currency: MYR. SST applies where relevant.
- Shipping: DHL Express live checkout rates
- Product image sizes: Defined by the theme via add_image_size() and in
  WP Settings > Media. Never hardcode pixel dimensions — use the registered image size names.
- Naming: Follow the conventions already present in motormania-child.
  Read neighbouring files before writing new ones. Do not introduce a
  new CSS methodology or file naming scheme without asking.
  Look at motormania-child/ and tell me what CSS class naming convention and file naming pattern the existing code uses. Just report — don't change anything.

## Key customisations

All in motormania-child/functions.php (self-contained — no includes):

- Enqueues parent style.css with a bootstrap dependency
- GSAP 3.12.5 + ScrollTrigger + SplitText loaded from jsDelivr CDN
  (footer) — powers scroll/text animations
- body_class filter injecting per-page body IDs for 4 landing pages
  (helmet-brands, all-category, accessories-brands, home-metting) so
  Elementor/CSS can target them. Echoes raw HTML mid-filter — fragile.
- cart_info shortcode — cart icon with item count and subtotal
- Two AJAX cart fragments: motormania_cart_fragment_refresh (shortcode)
  and durianbikers_cart_icon_fragment (header icon)
- durianbikers_force_cart_fragments — re-enqueues wc-cart-fragments at
  priority 99 because the theme/Elementor fails to load it
- durianbikers_cart_toast_markup — "added to cart" toast, inline CSS+jQuery
- Header currency switcher widget area (WPML currency switcher)
- Brand/category shortcodes: selected_brands, all_category,
  helmet_brands, custom_shop_by_brands / shop_by_brands
- custom_shop_by_brands (old version) commented out — dead code, harmless
- Enqueues assets/js/custom.js

## SEO (added 2026-10-12)

Lives in its own mu-plugin, NOT in the theme:
`wp-content/mu-plugins/durianbikers-seo.php` (repo copy: `mu-plugins/`).

It is a mu-plugin on purpose — it cannot overwrite a theme file, needs no
activation, and is removed by deleting one file. Every constant and function is
guarded (`defined` / `function_exists`) so a stray duplicate cannot fatal.

What it does:

- **Rewrites core-sitemap `<loc>` values to the CURCY `/my/` prefix.** WordPress
  canonicals are bare (`/product/foo/`) and CURCY 302-redirects those, so the
  sitemap was submitting 5,215 redirecting URLs to Google. Idempotent against
  `/my/` and `/gb/`; leaves the site root alone (it is the "Choose Your Store"
  selector, the one bare URL CURCY does not redirect).
- **Removes the author (`users`) sitemap** — no SEO value, lists usernames.
- **Excludes six pages from the sitemap**: Woo cart/checkout/my-account (resolved
  by `wc_get_page_id()`, not slug) plus `under-construction`, `chatbot-test`,
  `hero-banner`. `/home/` is deliberately KEPT — `/my/home/` is the canonical
  storefront.
- **Holds the GA4 tag**, dormant while `DURIANBIKERS_GA4_MEASUREMENT_ID` is `''`.
  Suppressed for logged-in admins.
- **Prints a build marker** `<!-- dbk-seo build 1.0.0 -->` in `wp_head`. This is a
  deploy probe: one page fetch proves which version is live. Keep it — four
  deploys failed silently before it existed.

Store prefixes come from **CURCY (`woo-multi-currency`)**, not WPML. The redirect
to `/my/` is unconditional — it ignores IP country, `Accept-Language` and
cookies, so Googlebot gets `/my/` too. WPML is only the currency switcher widget.

Still outstanding: no SEO plugin, so no meta descriptions, no Open Graph, titles
up to 193 chars, no `hreflang` between `/my/` and `/gb/`, and the root homepage
has zero crawlable `<a href>` (the store selector navigates via `data-url` + JS).

## Known issues (do not fix during go-live)

- GSAP loads from remote CDN — should be self-hosted post-launch
- body_class filter echoes raw HTML and only covers 4 pages

## Do not touch

- wp-content/themes/motormania/ — pristine parent theme v1.1.9. All
  customisation goes in motormania-child.
- Any vendor plugin under wp-content/plugins/ (WooCommerce, Essential
  Addons, ShopEngine, WPForms) — these are licensed/updated externally
- wp-config.php
- The other 4 themes installed on the site — do not activate, modify,
  or delete without asking

## Current focus (Aug 2026)

1. ✅ Live: durianbikers.com now serves the WooCommerce store over HTTPS
   (cut over from Shopify on 2026-08-14; SSL issued via Let's Encrypt in
   Plesk). staging.durianbikers.com 301-redirects to the live domain — it
   is the same install, not a separate sandbox. The old "Bloggers Unite"
   WP install in `httpdocs` is untouched/unused (rollback docroot).
   ⚠️ Domain is still registered with Shopify — transfer it out before
   cancelling the Shopify subscription or the domain can be lost.
2. ✅ Payment gateway switched from test mode to live mode (2026-08-14).
   Checkout now takes real payments — treat anything touching checkout or
   the gateway as high-risk.
3. Checking stock sync against the Qbee stock masterlist spreadsheet.

Go-live is done and payments are live; only stock sync is still being
finalised. Stay conservative: writes now hit production and checkout
takes real money. Do not make changes that are not directly requested,
and flag anything that could affect checkout or payments before touching
it.
