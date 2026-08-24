# Memory index

- [Catalogue data quality](project_catalogue-data-quality.md) — 5,283 products, ~1,450 broken Shopify imports, no fitment attr, empty short_description, `product_brand` taxonomy
- [HELMETS full reconciliation 2026-08-20](project_helmets-full-reconciliation-2026-08-20.md) — 1,674 masterlist helmets; 483 matched in WC (28.9%); 6 brands zero coverage (NOLAN 402, X-LITE 193, NEXX 123); catalog gap, not sync issue
- [LiteSpeed cache and nonces](project_litespeed-cache-nonces.md) — 7-day cache + REST caching ON; no-cache headers are IGNORED, use `litespeed_control_set_nocache`
- [Chatbot plugin architecture](project_chatbot-plugin-architecture.md) — why durianbikers-chatbot uses Anthropic tool-use retrieval; PII boundary, option names, agent file split
- [Identity from session, never from tool input](pattern_order-lookup-identity-from-session.md) — the rule for any LLM tool touching personal data; get_my_orders enforcement pattern
- [PHP verification honesty](feedback_php-verification-honesty.md) — php 8.5.9 at /opt/homebrew/bin/php, but target the server's 8.3.33; state what lint doesn't cover
- [Chatbot order notifications](project_chatbot-order-notifications.md) — at-most-once `_db_chat_notified` flag, forced non-blocking sends, API-key-constant and page-cache traps
- [WhatsApp Cloud API quirks](integration_whatsapp-cloud-api.md) — 24h window / error 131047, phone number ID vs number, temp token expiry
- [Shared memory directory](project_shared-memory-dir.md) — parallel backend-dev sessions share this dir; merge MEMORY.md, never overwrite it
- [New tool? check the old prompt](feedback_new-tool-check-old-prompt.md) — a stale "decline this" rule silently suppresses a new tool; also verify with a stub harness, not just lint
- [Stateless flow replay](pattern_stateless-flow-replay.md) — multi-step chat state with no session store; `search_products()` drops filters when empty; chips contract + escape-chip truncation trap
- [Free lookup engine upgrades](project_lookup-engine-free-mode.md) — subject slot, unmatched log, fuzzy thresholds, order reversal, compare-never-rank; and how a second brain bypassed the first brain's log guard
