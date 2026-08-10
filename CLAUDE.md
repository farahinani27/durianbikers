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
- `.env` currently points at **staging**. Confirm the host before any
  write; never assume it is live. Never print or commit the password.
- When I ask to "update the website", use these creds automatically —
  no need to ask me where they are.

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

1. Going live: replacing the durianbikers.com WordPress install with
   the staging site. Plesk currently holds two installs — durianbikers.com
   ("Bloggers Unite", no SSL yet) and staging.durianbikers.com as a
   subdomain under it.
2. Switching the payment gateway from test mode to live mode.
3. Checking stock sync against the Qbee stock masterlist spreadsheet.

Because a go-live is in progress, be conservative. Do not make changes
that are not directly requested. Flag anything that could affect
checkout or payments before touching it.
