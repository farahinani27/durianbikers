# Chatbot Cart Tools — Phase 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let Claude view and change the customer's cart — add, change quantity, remove — behind a code-enforced propose→confirm→commit gate, all through `class-cart-writer.php`, with NO order creation and NO payment.

**Architecture:** Three new READ/STAGE/COMMIT tools. `propose_cart_change` validates and STAGES a change server-side (never mutates); the widget shows a confirm card; only `commit_cart_change` mutates, and only after CODE re-checks the customer's current message is a clear affirmative (not a refusal — reusing the lookup engine's 不要/"tak nak" traps) and re-runs the cart-writer's in-stock/quantity validation. `view_cart` reads `WC()->cart`. Builds on the live Phase-1 orchestrator; the widget/inbox/checkout are untouched.

**Tech Stack:** Vanilla PHP 8.3 (no Composer/npm), WooCommerce `WC()->cart` via the existing `class-cart-writer.php`, Anthropic tool use via `class-claude-client.php`. Tests: hand-rolled PHP asserts run with `php` (pure units) and `wp eval-file` on the server (WooCommerce-dependent). No local WP/phpunit.

**Spec:** `docs/superpowers/specs/2026-09-15-chatbot-ai-orchestrator-redesign-design.md` (Phase 2 in §4 tool table + §5.2 cart safety + §5.3 no-order/no-payment; §11 keep-working; §12 Phase 2; §13 deploy).

## Global Constraints

- Staff/test-page only; not customer-facing. Chatway untouched. `db_chat_test_key` gate stays.
- **NEVER** create an order, submit checkout, choose/submit a payment method, mark paid, apply a coupon, change a price/stock, or touch the gateway. Phase 2 stops at cart mutation.
- All cart mutation goes through `class-cart-writer.php` — no tool writes `WC()->cart` directly except through it. Keep the existing in-stock-only + quantity + validate guards.
- Two-step: the tool PROPOSES (item, size, qty, price) and only COMMITS after the customer clearly confirms; keep the refusal-before-confirmation guards ("tak nak", 不要, "no", "jangan", "batal") and the negative/zero-quantity guard, enforced in CODE not the prompt.
- Model = Haiku (`claude-haiku-4-5-20251001`); hard RM100/mo budget cap with silent keyword fallback. Prefix `DB_Chat_`/`db_chat_`, text domain `durianbikers-chatbot`, escape/sanitize.
- Protect the admin cart with the snapshot/restore helper during any cart test (see [[chatbot-add-to-cart-sep2026]]).
- Deploy: staff test page only via ssh-stdin — `.bak` backup + `php -l` + md5 + LiteSpeed purge + pre-flight health check (`/my/`, `/gb/`, `/my/shop/`, checkout 200). Lift/restore the ssh deny gate. No git push, no go-live, without asking.
- Controller/implementer split (as in Phase 1): implementers do CODE + local `php -l`/`php` unit + write the server test + commit locally; the CONTROLLER performs all server deploy + `wp eval-file` smoke (ssh is operator-authorized to the controller only).

---

## File map (Phase 2)

- Create `includes/class-confirm.php` — `DB_Chat_Confirm` (pure): is_affirmative / is_refusal, reusing the existing yes/no + 不要/"tak nak" trap logic.
- Modify `includes/class-cart-writer.php` — add `contents()`, `update()`, `remove()` (reuse `validate()`/`load_cart()`); keep `add()`.
- Create `includes/class-cart-proposal.php` — `DB_Chat_Cart_Proposal` (server-side transient store, keyed by conversation, TTL 15 min).
- Modify `includes/class-claude-client.php` — add `TOOL_CART_VIEW`/`TOOL_CART_PROPOSE`/`TOOL_CART_COMMIT` consts + schemas + allowlist + budget-round text.
- Modify `includes/class-chat-endpoint.php` — thread the current customer message into `get_tool_executor()`; dispatch the 3 cart tools; the orchestrator passes the executor already.
- Modify `includes/class-claude-client.php::prompt_directives()` — add the propose→confirm→commit cart guidance.
- Modify `assets/chatbot.js` — render a cart-change confirm card (Yes/No) + a cart view from tool results; reuse existing card/button UI.
- Modify `tests/run-hard.php` + `tests/conversations/*.json` — cart hard-check scenarios (unchanged-without-yes; refusal blocks; negative-qty refused; out-of-stock refused).
- Create `tests/unit/test-confirm.php`; `tests/server/test-cart-writer.php`, `tests/server/test-cart-tools.php`.

---

## Task 1: DB_Chat_Confirm — affirmative/refusal detection (pure)

**Files:**
- Create: `durianbikers-chatbot/includes/class-confirm.php`
- Test: `durianbikers-chatbot/tests/unit/test-confirm.php`

**Interfaces:**
- Produces:
  - `DB_Chat_Confirm::is_refusal( string $text ): bool` — true when the message declines (EN "no/nope/don't/cancel", MS "tak/tak nak/jangan/batal/tak mau", ZH "不要/不用/不了"). Checks refusal FIRST (the 不要-contains-要 and "tak nak"-contains-"nak" traps).
  - `DB_Chat_Confirm::is_affirmative( string $text ): bool` — true when the message clearly agrees (EN "yes/yeah/ok/sure/add it/go ahead", MS "ya/boleh/nak/setuju/teruskan/sah", ZH "要/好/可以/加"), AND `! is_refusal()`. Returns false for anything ambiguous.

- [ ] **Step 1: Read the existing logic to port.** Read `class-lookup-engine.php` around lines 1378 (the malay yes/no word list) and 4360–4400 (the "decline before confirm" logic: Chinese 不要 checked before 要; Malay "tak nak" negation particle before the yes word). DB_Chat_Confirm must reproduce that ORDER (refusal wins) so a refusal is never read as a yes.

- [ ] **Step 2: Write the failing test** `tests/unit/test-confirm.php`:
```php
<?php
define( 'DB_CHAT_TEST', 1 );
require __DIR__ . '/../lib/assert.php';
require __DIR__ . '/../../includes/class-confirm.php';

// Affirmatives.
foreach ( array( 'yes', 'Yes please', 'ok add it', 'sure', 'go ahead', 'ya', 'boleh', 'nak', 'teruskan', '好', '要', '可以' ) as $y ) {
    t_ok( DB_Chat_Confirm::is_affirmative( $y ) === true && DB_Chat_Confirm::is_refusal( $y ) === false, "affirmative: $y" );
}
// Refusals — including the traps (contain a 'yes' token).
foreach ( array( 'no', 'nope', "don't", 'cancel', 'tak', 'tak nak', 'jangan', 'batal', 'tak mau', '不要', '不用', '不了' ) as $n ) {
    t_ok( DB_Chat_Confirm::is_refusal( $n ) === true && DB_Chat_Confirm::is_affirmative( $n ) === false, "refusal: $n" );
}
// Ambiguous → neither affirmative.
foreach ( array( 'how much is it', 'what colours', 'maybe later', 'hmm' ) as $a ) {
    t_ok( DB_Chat_Confirm::is_affirmative( $a ) === false, "ambiguous not-affirmative: $a" );
}
t_done();
```

- [ ] **Step 3: Run — expect FAIL** (`php durianbikers-chatbot/tests/unit/test-confirm.php`, class not found).

- [ ] **Step 4: Implement `class-confirm.php`.** Pure, deterministic, no WP. Normalise: lowercase, trim. `is_refusal()` matches whole-token EN/MS refusals via a word-boundary check and ZH refusals via `mb_strpos` substring (不要/不用/不了/别/勿). `is_affirmative()` returns false if `is_refusal()`, else matches whole-token EN/MS affirmatives + ZH substrings (要/好/可以/加/对/行) — but ZH 要 only counts when NOT preceded by 不 (guard the 不要 trap). Keep it tight: match SPECIFIC tokens only, so "how much" / "maybe" are neither.
```php
<?php
defined( 'ABSPATH' ) || defined( 'DB_CHAT_TEST' ) || exit;

final class DB_Chat_Confirm {

	public static function is_refusal( $text ) {
		$t = mb_strtolower( trim( (string) $text ) );
		if ( '' === $t ) { return false; }
		// Chinese refusals (substring — no spaces).
		foreach ( array( '不要', '不用', '不了', '不想', '别', '勿' ) as $zh ) {
			if ( false !== mb_strpos( $t, $zh ) ) { return true; }
		}
		// EN/MS refusals, whole-token.
		$words = preg_split( '/[^a-z\x{00c0}-\x{024f}]+/u', $t, -1, PREG_SPLIT_NO_EMPTY );
		$words = is_array( $words ) ? $words : array();
		$refuse = array( 'no', 'nope', 'nah', 'dont', 'cancel', 'stop', 'tak', 'jangan', 'batal', 'usah', 'takmau', 'taknak' );
		// Multi-word MS negations ("tak nak", "tak mau", "tak jadi").
		if ( preg_match( '/\btak\s+(nak|mau|jadi|payah)\b/u', $t ) ) { return true; }
		foreach ( $words as $w ) {
			if ( in_array( $w, $refuse, true ) ) { return true; }
		}
		return false;
	}

	public static function is_affirmative( $text ) {
		$t = mb_strtolower( trim( (string) $text ) );
		if ( '' === $t || self::is_refusal( $t ) ) { return false; }
		// Chinese affirmatives (substring), but 要 must not be part of 不要 (already excluded by is_refusal).
		foreach ( array( '好', '可以', '加入', '要', '对', '行' ) as $zh ) {
			if ( false !== mb_strpos( $t, $zh ) ) { return true; }
		}
		$words = preg_split( '/[^a-z\x{00c0}-\x{024f}]+/u', $t, -1, PREG_SPLIT_NO_EMPTY );
		$words = is_array( $words ) ? $words : array();
		$yes = array( 'yes', 'yeah', 'yep', 'yup', 'ok', 'okay', 'sure', 'add', 'confirm', 'proceed', 'ya', 'yer', 'boleh', 'nak', 'setuju', 'sah', 'teruskan', 'ok' );
		foreach ( $words as $w ) {
			if ( in_array( $w, $yes, true ) ) { return true; }
		}
		return false;
	}
}
```
> Tune the word lists against the test until green; the ORDER (refusal first) is the load-bearing safety property. If a test case fails, fix the lists — do not weaken the refusal-first rule.

- [ ] **Step 5: Run — expect PASS.**

- [ ] **Step 6: Commit** `feat(chatbot): DB_Chat_Confirm affirmative/refusal detection (Phase 2)`.

---

## Task 2: cart-writer view/update/remove

**Files:**
- Modify: `durianbikers-chatbot/includes/class-cart-writer.php`
- Test: `durianbikers-chatbot/tests/server/test-cart-writer.php` (controller runs)

**Interfaces:**
- Consumes: existing private `validate($product_id,$variation_id,$quantity)`, `load_cart()`, `plain()`, `failure()`; existing `add()`.
- Produces:
  - `DB_Chat_Cart_Writer::contents(): array` → `['items'=>[['key'=>string,'name'=>string,'label'=>string,'qty'=>int,'line_price'=>string,'product_id'=>int,'variation_id'=>int], ...], 'count'=>int, 'total'=>string, 'cart_url'=>string]` (all whitelisted; from `WC()->cart->get_cart()`).
  - `DB_Chat_Cart_Writer::update( string $cart_item_key, int $quantity ): array` → same result shape as `add()`; quantity 0 removes; uses `WC()->cart->set_quantity()`; re-`validate()`s stock for the item's product/variation before increasing; refuses negative.
  - `DB_Chat_Cart_Writer::remove( string $cart_item_key ): array` → `WC()->cart->remove_cart_item()`; result shape as `add()`.

- [ ] **Step 1: Write the failing server test** `tests/server/test-cart-writer.php` (controller runs via `wp eval-file`): load cart, `contents()` → shape check; `add(59463, <in-stock variation>, 1)` then `contents()['count']>=1`; `update($key,2)` → qty 2; `update($key,0)` → removed; `remove()` on a re-added item → gone; assert whitelisted keys only (no raw meta). Use the snapshot/restore helper to protect the admin cart. Print Y/N.

- [ ] **Step 2: Run — expect FAIL** (methods missing).

- [ ] **Step 3: Implement `contents()`, `update()`, `remove()`** in `class-cart-writer.php`, reusing `load_cart()`/`validate()`/`plain()`/`failure()`. `update()` maps `$cart_item_key` → the cart line, re-`validate()`s the product/variation/new-qty (so an out-of-stock or over-stock increase is refused), then `WC()->cart->set_quantity($key,$qty,true)`; `$qty<=0` → `remove()`. Never touch price/coupon/order. Return the `add()` result shape.

- [ ] **Step 4: Deploy (controller) + run the server test — expect PASS.**

- [ ] **Step 5: Commit** `feat(chatbot): cart-writer contents/update/remove (Phase 2)`.

---

## Task 3: DB_Chat_Cart_Proposal — server-side staged change

**Files:**
- Create: `durianbikers-chatbot/includes/class-cart-proposal.php`
- Test: `durianbikers-chatbot/tests/server/test-cart-proposal.php` (controller runs)

**Interfaces:**
- Produces:
  - `DB_Chat_Cart_Proposal::stage( string $conversation_id, array $proposal ): string` — stores `$proposal` (`action`∈add|update|remove, product_id, variation_id, quantity, name, label, price) in a transient `db_chat_cart_prop_<sha1(conversation_id)>`, TTL 900s, returns a short `proposal_id` (random hex) also stored inside. One staged proposal per conversation (a new stage replaces the old).
  - `DB_Chat_Cart_Proposal::get( string $conversation_id ): ?array` — the staged proposal, or null (expired/none).
  - `DB_Chat_Cart_Proposal::clear( string $conversation_id ): void` — delete the transient.
- `$conversation_id` is the widget's conversation public_id (32 hex) or, when absent, a per-session token the endpoint already has; the endpoint passes whatever id it uses so proposals are namespaced per conversation.

- [ ] **Step 1: Write the failing server test** — stage a proposal for a fake conversation id, `get()` returns it with a `proposal_id`, `clear()` → `get()` null. Print Y/N.
- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Implement** with `set_transient`/`get_transient`/`delete_transient`; sanitize ints; whitelist keys. No PII, no price mutation.
- [ ] **Step 4: Deploy + run test — expect PASS.**
- [ ] **Step 5: Commit** `feat(chatbot): cart-change proposal store (Phase 2)`.

---

## Task 4: The three cart tools (view / propose / commit) — code-enforced gate

**Files:**
- Modify: `durianbikers-chatbot/includes/class-claude-client.php` (consts + schemas + allowlist + budget text)
- Modify: `durianbikers-chatbot/includes/class-chat-endpoint.php` (thread current message into `get_tool_executor`; dispatch the 3 tools)
- Test: `durianbikers-chatbot/tests/server/test-cart-tools.php` (controller runs)

**Interfaces:**
- Consumes: `DB_Chat_Cart_Writer::contents/add/update/remove`, `DB_Chat_Cart_Proposal::stage/get/clear`, `DB_Chat_Confirm::is_affirmative/is_refusal`, `DB_Chat_Product_Index::get_product_detail`.
- Produces consts `TOOL_CART_VIEW='view_cart'`, `TOOL_CART_PROPOSE='propose_cart_change'`, `TOOL_CART_COMMIT='commit_cart_change'`; `get_tool_executor( int $conversation_id = 0, string $current_message = '', string $conversation_public_id = '' )` (extend the Phase-1 signature; keep existing callers working with defaults).

**Executor behaviour (the safety core — enforced in CODE):**
- `view_cart` → `DB_Chat_Cart_Writer::contents()` (read-only).
- `propose_cart_change` `{action, product_id, variation_id?, quantity?}` → VALIDATE via a dry-run (`cart-writer` validate path or describe) WITHOUT mutating; on invalid (out-of-stock, bad variation, qty≤0 for add/update) return an error block telling Claude why; on valid, `DB_Chat_Cart_Proposal::stage()` and return `{proposal:true, action, name, label, qty, price, proposal_id}`. **Never mutates the cart.**
- `commit_cart_change` `{proposal_id?}` → (1) `$p = DB_Chat_Cart_Proposal::get($conversation_public_id)`; if none/expired → return `{committed:false, reason:'nothing_staged'}`. (2) **If `! DB_Chat_Confirm::is_affirmative($current_message)` OR `DB_Chat_Confirm::is_refusal($current_message)` → clear the proposal and return `{committed:false, reason:'not_confirmed'}` — DO NOT mutate.** (3) else call the matching `cart-writer` method (add/update/remove), clear the proposal, return `{committed:true, cart:<contents>}`. Optional `proposal_id` must match the staged one or it's `nothing_staged`.

- [ ] **Step 1: Write the failing server test** `tests/server/test-cart-tools.php` (controller runs). Build the executor from the endpoint with a known conversation id + current message. Assert, using the snapshot/restore helper:
  - propose(add, 59463, <in-stock var>, 1) → cart COUNT UNCHANGED, returns proposal + proposal_id.
  - commit with current_message "no thanks" → committed:false reason not_confirmed, cart UNCHANGED, proposal cleared.
  - re-propose, commit with current_message "yes please" → committed:true, cart count +1.
  - propose(add, 59463, <out-of-stock var>, 1) → error, no proposal.
  - propose(update, qty 0) after an add → on commit(yes) the item is removed.
  - propose(add, ..., -1) → refused (qty≤0), no proposal.
  Print Y/N per assertion; restore the cart at the end.

- [ ] **Step 2: Run — expect FAIL.**

- [ ] **Step 3: Implement** the consts + schemas (below) + allowlist + budget text in `class-claude-client.php`; thread `$current_message` + `$conversation_public_id` through `get_tool_executor()` (update the orchestrator/endpoint call site to pass `$message` and the conversation public_id) and add the three dispatch branches enforcing the gate above.
  Schemas (append to `get_tools()`):
```php
array( 'name' => self::TOOL_CART_VIEW,
  'description' => 'Show what is currently in the customer\'s cart (items, sizes, quantities, line prices, total). Read-only. Use when they ask "what\'s in my cart" or before checkout.',
  'input_schema' => array( 'type'=>'object', 'properties'=>new stdClass() ) ),
array( 'name' => self::TOOL_CART_PROPOSE,
  'description' => 'PROPOSE a cart change for the customer to confirm — this does NOT change the cart. action is "add", "update" (change quantity) or "remove". Give product_id from a search result, variation_id for a sized item, and quantity. The customer will see a confirm card; only call commit_cart_change AFTER they clearly say yes.',
  'input_schema' => array( 'type'=>'object', 'properties'=>array(
    'action'=>array('type'=>'string','enum'=>array('add','update','remove')),
    'product_id'=>array('type'=>'integer'),
    'variation_id'=>array('type'=>'integer','description'=>'The in-stock variation id (size), from get_product. Omit for a simple product.'),
    'quantity'=>array('type'=>'integer','description'=>'How many (for add/update). Use 0 with update to remove.'),
  ), 'required'=>array('action','product_id') ) ),
array( 'name' => self::TOOL_CART_COMMIT,
  'description' => 'COMMIT the change you proposed with propose_cart_change — ONLY after the customer has clearly confirmed (said yes) in their latest message. The system re-checks their words and will refuse if they declined. Returns the updated cart.',
  'input_schema' => array( 'type'=>'object', 'properties'=>array(
    'proposal_id'=>array('type'=>'string','description'=>'The proposal_id returned by propose_cart_change.'),
  ), 'required'=>array() ) ),
```
  Add all three to the `run_tool()` allowlist and the budget-round "do not call again" text.

- [ ] **Step 4: Deploy (controller) + run the server test — expect PASS (esp. cart UNCHANGED on "no").**

- [ ] **Step 5: Commit** `feat(chatbot): view/propose/commit cart tools, confirm-gated (Phase 2)`.

---

## Task 5: System prompt — the propose→confirm→commit flow

**Files:**
- Modify: `durianbikers-chatbot/includes/class-claude-client.php::prompt_directives()`
- Test: `durianbikers-chatbot/tests/unit/test-prompt.php` (extend)

**Interfaces:** Consumes/Produces the existing `prompt_directives()` string.

- [ ] **Step 1: Extend the prompt test** to assert the block now mentions "confirm" and "propose_cart_change" and "commit_cart_change" (string needles).
- [ ] **Step 2: Run — expect FAIL.**
- [ ] **Step 3: Add a CART section** to `prompt_directives()`:
```
CART — YOU CAN ADD, CHANGE AND REMOVE ITEMS (never take payment)
- To put something in the cart, call propose_cart_change (action add) with the product_id and the in-stock variation_id (size) — check the size with get_product first. This only PROPOSES; the customer sees a confirm card.
- Wait for the customer to clearly say yes. Only then call commit_cart_change. If they say no / tak nak / 不要, do not commit — the system also blocks it.
- Use view_cart to show the cart; update (with quantity, 0 = remove) or remove to change it — always propose then confirm.
- You can help them all the way to the checkout page, but you NEVER take card or bank details, never choose or submit a payment method, and never place the order. Payment happens only on the checkout page.
```
- [ ] **Step 4: Run — expect PASS.**
- [ ] **Step 5: Commit** `feat(chatbot): cart propose/confirm/commit prompt guidance (Phase 2)`.

---

## Task 6: Widget — confirm card + cart view

**Files:**
- Modify: `durianbikers-chatbot/assets/chatbot.js`
- (Manual/browser verification by the controller; no unit runner for the widget.)

**Interfaces:** Consumes the REST `/chat` response, which now may carry `cart` (from a committed change / view_cart) and a `proposal` (from propose). Reuse the existing product-card + tap-button rendering.

- [ ] **Step 1:** When a `/chat` response includes a `proposal` object, render a **confirm card** below the reply: the item name + size + qty + price and two tap buttons — **"Yes, add it"** / **"No"** — that send that literal text as the next message (so the customer's confirming words reach the server for `DB_Chat_Confirm`). Reuse `db-chat-escalate`-style card markup + the existing button handler.
- [ ] **Step 2:** When a response includes a `cart` object (items/total), render a compact **cart summary** line/card (items × qty, total, a "View cart" link to `cart_url`). Reuse the product-card container styles.
- [ ] **Step 3:** `node --check assets/chatbot.js`. Controller deploys + browser-verifies: search → size → "add to cart" → confirm card → "Yes, add it" → cart shows +1; then "actually remove it" → confirm → gone; and a "no" path leaves the cart unchanged.
- [ ] **Step 4: Commit** `feat(chatbot): widget cart confirm card + cart summary (Phase 2)`.

> The endpoint must include `proposal`/`cart` in the `/chat` response when the tools returned them — fold that into Task 4's finalize path (surface `proposal` from the last propose tool result and `cart` from a commit/view). Note this in the Task 4 report so Task 6 has the fields.

---

## Task 7: Cart hard-check scenarios + integration deploy

**Files:**
- Modify: `durianbikers-chatbot/tests/run-hard.php` (or add a cart runner) + `tests/conversations/*.json`
- Controller: deploy all Phase-2 files, purge, health check, browser-verify.

**Interfaces:** The hard runner drives the executor/proposal/confirm logic deterministically where possible (DB_Chat_Confirm is pure; the propose/commit gate can be unit-tested with a stubbed cart-writer).

- [ ] **Step 1:** Add pure hard-checks for the SAFETY invariants that don't need WooCommerce: `DB_Chat_Confirm` refusal-first (reuse Task 1 cases in the hard runner), and a stubbed commit path proving `commit` with a refusal/ambiguous current_message does NOT call the cart-writer mutate (inject a fake cart-writer that records calls). Assert: refusal → zero mutate calls; affirmative + staged proposal → exactly one mutate call.
- [ ] **Step 2:** Run `php durianbikers-chatbot/tests/run-hard.php` — all green (Phase-1 + new cart safety checks).
- [ ] **Step 3 (controller):** Deploy every Phase-2 file (new classes first, then client/endpoint/js), boot-check, run all server smokes (cart-writer, cart-proposal, cart-tools) with the snapshot/restore helper, purge LiteSpeed, pre-flight health check, then browser-verify the full add→confirm→cart and remove flows + the "no" path. Keep `db_chat_mode=ai`.
- [ ] **Step 4: Commit** `test(chatbot): cart safety hard-checks + Phase 2 integration (Phase 2)`.

---

## Self-review notes (author)

- **Spec coverage:** §4 cart tools → Tasks 2,3,4 (view_cart, propose_cart_change, commit_cart_change; the spec's "add/update/remove" fold into the propose `action`). §5.2 propose-then-confirm + refusal/negative-qty/in-stock guards → Tasks 1,2,4 (code-enforced, tested). §5.3 no order/payment → constraint + no such tool exists. §11 keep-working: widget/inbox/checkout untouched (Task 6 only ADDS confirm-card rendering; lookup/hybrid branches untouched). §12 Phase 2 → this plan.
- **Safety is code, not prompt:** the commit gate re-checks the customer's literal current message via `DB_Chat_Confirm` (refusal-first) AND re-runs cart-writer `validate()` — a compromised or over-eager model still cannot add on a "no", add an out-of-stock size, or add a negative qty. Task 7 step 1 proves "no → zero mutations" with a recording stub.
- **No order/payment path exists:** only view/propose/commit + the Phase-1 read tools; no wc_create_order/checkout/gateway anywhere.
- **Deferred to Phase 3 (not gaps):** set_checkout_details, get_checkout_summary, get_checkout_link, /gb shipping guard, the "Proceed to payment" hand-off — all Phase 3, gated on operator go-ahead.
- **Type consistency:** `contents()`/`add()`/`update()`/`remove()` all return the same result shape; `proposal`/`cart` are the two response keys the widget (Task 6) consumes and the endpoint (Task 4) emits; `get_tool_executor()` signature extended once (Task 4) with defaults so Phase-1 callers keep working.
