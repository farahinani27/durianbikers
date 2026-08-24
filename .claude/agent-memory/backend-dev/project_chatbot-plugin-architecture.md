---
name: chatbot-plugin-architecture
description: durianbikers-chatbot plugin — why it uses Anthropic tool-use retrieval (products + help pages), the PII boundary, option names, and the multi-agent file ownership split
metadata:
  type: project
---

`durianbikers-chatbot/` is a self-contained plugin (repo root, sibling of
`motormania-child/`). Built Aug 2026 by three agents in parallel. SPEC.md in
that directory is the source of truth for the contract.

## The load-bearing decision: retrieval, not a catalogue dump

The original spec had a `get_context()` that dumped the whole catalogue into the
system prompt. That was abandoned once the audit showed 5,283 products (see
[[catalogue-data-quality]]). Replaced with Anthropic **tool use**: one tool,
`search_products`, executed server-side via native `wc_get_products()`, capped at
3 tool rounds per customer message, 12 results per search.

**Why it matters beyond this plugin:** the model only ever sees products it
explicitly searched for. That is what makes the data guarantee structural rather
than promise-based — the model's reach is exactly the set of tools, so a prompt
cannot talk it into more. (The guarantee itself narrowed in Aug 2026 — see
"Third tool" below. The *structural* property is what survived.)

Two implementation notes that cost real thinking and would be easy to undo:
- Forcing the final answer is done by appending a text block to the last
  `tool_result` user turn ("your search budget is spent"), NOT by sending
  `tool_choice: {type:"none"}`. Avoids gambling on a parameter variant the
  pinned `anthropic-version: 2023-06-01` might reject, and keeps the
  tool_use/tool_result pairing valid.
- `wc_get_products()` has no brand argument. Brand filtering injects a
  `tax_query` through `woocommerce_product_data_store_cpt_get_products_query`
  and removes it in a `finally` block, so the filter can never leak into other
  queries on the same request.

## Second tool: `search_pages` (added Aug 2026)

Non-product questions (shipping, returns, payment, contact, FAQ) are answered from
published PAGES via `DB_Chat_Content_Index`. Round cap went 3 → 4 so one question can
use both tools.

The decisions worth keeping:

- **Allowlist of slugs, never a denylist.** The store publishes 27 pages including
  `checkout`, `cart`, `my-account`, `wishlist`, `under-construction`, `header-test`,
  `home-1-2`, `my-1`. A denylist would leak the next page someone adds; the allowlist
  makes new pages invisible by default. Filter `db_chat_content_pages`.
- **The model is never shown a URL.** Tool results carry the page TITLE and text only;
  the endpoint matches titles the model wrote back to the real `WP_Post` and returns a
  `pages:[{title,url}]` array (cap 2). Same "never trust a model URL" rule as products,
  but structural: it cannot fabricate a link it has never seen.
- Query scoring weights the customer's own words above synonym expansions. Without
  that, a section repeating a generic word ("DHL", "delivery") outranked the one
  section that actually named what they asked about ("Sabah").
- `post_status === 'publish'` and `post_password === ''` are asserted on the object,
  and re-asserted when resolving a link, not just set as query args.
- Cache busting hooks `save_post_page` AND `transition_post_status` — `wp_publish_post`
  on a scheduled page does not fire `save_post`, so a scheduled policy fix would
  otherwise stay invisible for up to the 12h TTL. Policy copy gets edited *because it
  was wrong*, so a stale cache here is a customer-facing error, not an inconvenience.
- Live gotcha: the Delivery Information page still carries stale Shopify-era copy
  (points customers at Lazada/Shopee, mentions COVID delays). The owner is replacing
  it. The plugin must NOT filter or "correct" page text — a bot that silently rewrites
  policy is far worse than one quoting a stale page.

## Third tool: `get_my_orders` (added Aug 2026) — the reversal

The owner explicitly asked for order retrieval, so the original "no code path to
orders at all" guarantee was **deliberately replaced** by a narrower one. Do not
"restore" the old rule if you find it quoted somewhere: anonymous reaches no
personal data; a signed-in customer reaches only their own orders; nobody ever
reaches another person's. See [[order-lookup-identity-from-session]] for the
enforcement pattern, which is the part worth reusing elsewhere.

## Human handoff (added Aug 2026) — the prompt lesson

`class-handoff.php` adds a customer→human WhatsApp button. The mechanics are in
SPEC.md; two decisions are not obvious from the code:

- **To make the bot offer a human, change `$contact`, do not add a new rule.**
  The system prompt already interpolates one contact string into every decline
  path (fitment, discounts, dimensions, order actions, policy gaps, tool
  failures). Repointing that one string at the button covers them all at once.
  Adding a fresh "suggest a human when stuck" instruction instead would have the
  model reaching for a person on questions its tools answer perfectly well —
  the mirror image of the [[new-tool-check-old-prompt]] failure, and just as
  invisible.
- **The model gets the button's LABEL, never the number or the URL.** Replies
  render as plain text so a model-written link is not tappable, and a
  model-written phone number one digit out sends a customer to a stranger. The
  wa.me URL is built from configuration only — no order number, no name, no
  query string at all (the emitter rejects a `?`). Prefill text is the widget's
  job, at click time.

The customer-facing `db_chat_handoff_number` is a different setting from
`db_chat_whatsapp_to` (owner order alerts via [[whatsapp-cloud-api-quirks]]).
Same number, different purpose; the settings screen says so twice because
conflating them is the easy mistake.

## Hard boundaries (do not erode)

- No `WP_User_Query`, no `get_users()`, no `$wpdb` anywhere in the chat path.
  Every product query pinned to `status=publish` + visible. `wc_get_orders()` is
  permitted in exactly one file, `class-order-lookup.php`, pinned to
  `customer_id => get_current_user_id()`.
- `stock_quantity` is never read or emitted — in/out of stock only, plus
  "unavailable" for the broken imports.
- Anthropic key is read constant-first (`DB_CHAT_ANTHROPIC_API_KEY` in
  wp-config) then the `db_chat_api_key` option. Used in exactly one place, the
  `x-api-key` header. Never localized, never in a WP_Error, never logged;
  `DB_Chat_Plugin::log_error()` runs an `sk-ant-*` redaction pass on every line.
- The plugin never uses the WooCommerce REST API or the production app
  password — it runs inside WordPress.

## Option names (agreed across agents, must stay in sync)

Core: `db_chat_enabled`, `db_chat_api_key`, `db_chat_model` (default
`claude-haiku-4-5-20251001`), `db_chat_greeting`, `db_chat_suggestions` (array),
`db_chat_logging`. Copy overrides: `db_chat_title`, `db_chat_subtitle`,
`db_chat_placeholder`, `db_chat_launcher_label`, `db_chat_offline_text`,
`db_chat_error_text`, `db_chat_rate_limit_text`, `db_chat_footnote`,
`db_chat_avatar` — `''` always means "use the JS default". Handoff:
`db_chat_handoff_number`, `db_chat_handoff_label`, `db_chat_handoff_hours`,
`db_chat_handoff_hours_text`. Channel credentials
are telegram/slack/email/whatsapp prefixed the same way. Settings group
`db_chat_settings`, page slug `db-chat-settings` under WooCommerce.

Transient `db_chat_product_index` now caches ONLY the category + brand name
lists (1h TTL), not products.

## File ownership split

- Core (bootstrap, `class-product-index.php`, `class-claude-client.php`,
  `class-chat-endpoint.php`) — chat path.
- `class-settings.php`, `class-order-notifier.php`, `includes/notifiers/*` —
  settings screen and owner order notifications. Booted via static
  `::init()` from the bootstrap, each guarded with `file_exists` +
  `class_exists` + try/catch so a broken notifier can never fatal checkout.
- `assets/chatbot.css`, `assets/chatbot.js` — vanilla, no jQuery, renders in a
  shadow root, footer-enqueued with empty deps.

**How to apply:** if asked to extend the chatbot, extend the tool, not the
prompt. Adding a second tool means re-checking the PII boundary. Never enqueue
or answer without `db_chat_enabled === '1'` AND a key present.
