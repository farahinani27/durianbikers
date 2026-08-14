# Durian Bikers

Malaysian motorcycle parts and accessories retailer.

## Storefronts

- durianbikers.com/gb     - international store
- durianbikers.com/my  - local (Malaysia) store
- Platform: WordPress + WooCommerce
- Theme: MotorMania v1.1.9  (child theme: MotorMania Child)
- Page builder: Elementor
- Hosting / panel: Exabytes / Plesk
- PHP version: 8.3.33 (Supports 64bit values)   WordPress: 7.0.3   WooCommerce: 10.9.4

## Local development

- No local WordPress install. Code is edited locally in this repo and
  uploaded to the server; there is no local site to preview against.
- Staging site: http://staging.durianbikers.com (Plesk / Exabytes)
- Deploy: upload changed files via Plesk File Manager to
  httpdocs/wp-content/themes/motormania-child/
- No build step. CSS and JS are edited directly, not compiled.

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
