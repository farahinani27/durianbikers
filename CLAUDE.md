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

- GSAP enqueued in the child theme for animations
- Per-page body IDs
- Custom cart shortcodes and AJAX cart fragments
- Brand and category shortcodes
- "added to cart" toast
- Child theme created to move all customisations out of the parent —
  parent is pristine MotorMania v1.1.9 from ThemeForest and must stay
  that way so it can be updated
- Header currency switcher widget
- Custom front-end JS
- Read motormania-child/functions.php and any included files, and list
every customisation you find — one line each, what it does. Just report.

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
