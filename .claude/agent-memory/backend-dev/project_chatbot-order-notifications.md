---
name: chatbot-order-notifications
description: Owner order-alert half of durianbikers-chatbot — at-most-once idempotency, checkout-safety rules, settings-screen traps (API key constant, page cache, ryan-chat)
metadata:
  type: project
---

The order-notification + settings half of the chatbot plugin: `class-order-notifier.php`,
`class-settings.php`, `includes/notifiers/*`. Plugin-wide context in
[[chatbot-plugin-architecture]]; this covers only the decisions specific to this half.

## Checkout safety — the constraint that shaped the design

The order hook runs inside a real customer's checkout on a store taking live payments, so the
notifier is written defensively rather than conventionally:

- `blocking => false`, `timeout => 2`, `redirection => 0` are forced inside
  `DB_Chat_Abstract_Notifier::post_non_blocking()`, not set per channel — a new notifier cannot
  accidentally make itself blocking.
- **Consequence worth remembering:** no channel can ever report real delivery success. `send()`
  returning true means "handed to the HTTP layer". Meta/Telegram/Slack errors are invisible at call
  time and only surface in their own dashboards. Do not build retry logic on the return value.
- `wp_mail()` is the one channel that cannot be made non-blocking, so it is deliberately ordered
  **last** in `get_notifiers()` — a slow SMTP plugin then cannot delay the others.

## Idempotency is at-most-once, on purpose

The `_db_chat_notified` order meta flag is written **before** dispatch, not after.

**Why:** assume every checkout gets retried after a timeout. Writing the flag first means a crash
mid-dispatch loses one alert; writing it after would re-send on every retry. A missed owner alert is
recoverable from wp-admin, a duplicated one is noise forever. Do not "fix" this to at-least-once.

## Settings-screen traps

- **API key constant.** The client reads `DB_CHAT_ANTHROPIC_API_KEY` from wp-config first, option
  second. So the settings field disables itself when the constant is set and offers to clear any
  stale DB copy. A disabled input is not submitted, `options.php` then calls
  `update_option($opt, null)`, and `sanitize_secret()` treats null as "unchanged" — that is what
  stops a save from wiping the key. Anything answering "do we have a key?" must check both sources
  (`DB_Chat_Settings::has_api_key()`), never the option alone.
- **Page cache.** Greeting, suggestions, the nine copy overrides, and the `db_chat_enabled` /
  `db_chat_api_key` enqueue gate are all baked into cached storefront HTML (see
  [[litespeed-cache-nonces]]). Logged-in admins bypass the cache, so edits look live to the owner
  while visitors keep the old text or keep not seeing the widget at all.
  **The auto-purge deliberately does NOT live in this class** — it is
  `register_cache_purge_hooks()` / `purge_page_cache()` in the bootstrap, whose static guard
  collapses a 13-option save into one purge. That guard cannot suppress a purge fired from
  `class-settings.php`, so adding one here double-purges every save. If a new localized option is
  added, extend the bootstrap's list rather than purging from the settings class. The UI keeps a
  "purge the cache" note anyway, for anyone editing options outside the settings screen.
- **`ryan-chat`** ("Ryan Chat for WooCommerce" v1.0.0) is installed but inactive on the server.
  `DB_Chat_Settings::maybe_render_conflict_notice()` warns if it is ever activated. Read-only —
  never deactivate another plugin programmatically.

WhatsApp channel quirks live in [[whatsapp-cloud-api-quirks]].
