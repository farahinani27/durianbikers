---
name: chatbot-free-lookup-mode
description: The Durian Bikers chatbot has a free no-API-key "lookup mode"; why it exists, what db_chat_mode means, and the decisions that must not be undone
metadata:
  type: project
---

The store owner does not want to pay for an Anthropic API key, so the chatbot plugin has a second
brain: `DB_Chat_Lookup_Engine` (`includes/class-lookup-engine.php`), deterministic keyword/intent
matching over the same product and page retrieval the Claude path uses. Added Aug 2026.

**Why:** zero running cost was the requirement, not a nice-to-have. The store's other chat plugin
(`ryan-chat`) declines non-English outright, so Bahasa Malaysia keyword support is the point of
difference and must never be dropped as "extra scope".

**How to apply:**

- `db_chat_mode` = `auto` (default: Claude if a key exists, else lookup) | `lookup` (always free,
  even with a key saved) | `ai` (always Claude; no key = the original 503 offline behaviour).
- `DB_Chat_Plugin::get_engine()` is the single source of truth for both the asset enqueue and the
  REST branch. If those ever disagree, customers get a chat box that answers 503.
- `is_enabled()` deliberately no longer requires an API key. Do not "restore" a bare
  `has_api_key()` check anywhere in the enqueue or endpoint path — that silently kills the whole
  free mode and the symptom (no widget at all) looks like a deploy failure.
- An explicit `lookup` choice never falls back to the paid API when something is broken; it
  reports engine `none` and switches the chatbot off. Falling back would spend the owner's money
  against an explicit instruction.
- Lookup mode has no order code path at all, by design. Order questions are declined. Do not add
  one "for parity" — the Claude path's `get_my_orders` boundary is the only order surface.

Never let any copy imply it understands language. The widget footnote is overridden in lookup mode
for exactly that reason. See [[verify-without-local-wordpress]] for how this was tested.
