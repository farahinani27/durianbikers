# Chatbot AI-Orchestrator — Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Claude the always-on conversation orchestrator (with a code-enforced grounding check and a demoted, read-only keyword fallback), and build the regression test harness — without touching cart or checkout.

**Architecture:** The endpoint's existing `ai` mode already sends every message to Claude with tools. Phase 1 wraps that path in a new `DB_Chat_Orchestrator` that (a) gates on API-key + budget and silently falls back to the read-only keyword engine when AI is unavailable, (b) adds two read tools (`get_product`, `request_human`) and a warmer, honesty-first system prompt, and (c) runs every Claude reply through `DB_Chat_Grounding` so no invented price/product reaches a customer. A pure-PHP + `wp eval-file` test harness locks the behaviour in.

**Tech Stack:** Vanilla PHP 8.3 (no Composer/npm), WordPress/WooCommerce native APIs, Anthropic Messages API via the existing `class-claude-client.php`. Tests: hand-rolled PHP assert lib run with `php` (pure units) and `wp eval-file` on the server (WooCommerce/real-Claude).

**Spec:** `docs/superpowers/specs/2026-09-15-chatbot-ai-orchestrator-redesign-design.md`

## Global Constraints

- Staff/test-page only; do not go customer-facing. Chatway untouched. (`db_chat_test_key` gate stays.)
- Never modify the checkout page, payment gateway, prices, stock, or orders. Phase 1 adds **no** cart/checkout tools.
- Personal data (name/phone/email/address) never goes to Claude and is not written to chat logs; Phase 1 adds a card/long-number scrub as the first layer.
- Safety is enforced in **code**, not prompt text. The prompt is defence in depth only.
- Identity is never a tool input; `get_my_orders` reads only the current user's own orders via `get_current_user_id()`.
- Model default is Haiku 4.5 (`claude-haiku-4-5-20251001`, already `DB_CHAT_DEFAULT_MODEL`). Hard monthly cap `db_chat_ai_monthly_cap_myr` (default RM100) with **silent fallback** to the keyword engine on no-key / over-budget / timeout / error.
- Reuse, don't rebuild: `class-cart-writer.php` (read methods), `class-lookup-engine.php` (fallback + search backends), `class-content-index.php` (policies), `class-order-lookup.php` (orders), `class-handoff.php` / handoff endpoint (human), `class-product-index.php` (search).
- Prefix everything `db_chat_` / `DB_Chat_`. Text domain `durianbikers-chatbot`. Escape output, sanitize input.
- Deploy: `.bak-<stamp>` backup + `php -l` / `node --check` + md5 verify + LiteSpeed purge + pre-flight health check (`/my/`, `/gb/`, `/my/shop/`, checkout reachable). SSH gate lift/restore per approved posture. No git push and no go-live without asking. API key never printed or committed.

---

## File map (Phase 1)

- Create `includes/class-grounding.php` — `DB_Chat_Grounding` (pure): fact collection + reply verification.
- Create `includes/class-pii.php` — `DB_Chat_PII` (pure): scrub card/long-number patterns from text.
- Create `includes/class-orchestrator.php` — `DB_Chat_Orchestrator`: availability gate, Claude run, grounding, silent fallback.
- Modify `includes/class-claude-client.php` — add `get_product` + `request_human` tool schemas, allowlist, budget-round text (keep pattern); expose `chat()` unchanged shape.
- Modify `includes/class-product-index.php` — add `get_product_detail( int $id ): array` (whitelisted variations, reuses cart-writer read methods).
- Modify `includes/class-chat-endpoint.php` — extend `get_tool_executor()` for the new tools; route `ai` mode through the orchestrator; raise history cap; scrub PII into `build_messages`.
- Modify `includes/class-claude-client.php::build_system_prompt()` — new tone/honesty/grounding prompt.
- Create `tests/lib/assert.php`, `tests/lib/wp-shims.php` — tiny assert lib + minimal WP function shims for pure units.
- Create `tests/unit/test-grounding.php`, `tests/unit/test-pii.php`, `tests/unit/test-prompt.php` — pure `php` units.
- Create `tests/conversations/*.json` — scenario files (seed with known bugs).
- Create `tests/run-hard.php`, `tests/run-graded.php` — server runners (`wp eval-file`).

---

## Task 1: Grounding module (pure PHP)

**Files:**
- Create: `durianbikers-chatbot/includes/class-grounding.php`
- Test: `durianbikers-chatbot/tests/unit/test-grounding.php`, `durianbikers-chatbot/tests/lib/assert.php`

**Interfaces:**
- Produces:
  - `DB_Chat_Grounding::collect_facts( array $tool_results ): array` → `['prices'=>float[], 'names'=>string[]]`. `$tool_results` is a flat list of decoded tool-result arrays (each may contain `price`/`name` keys or a `results` list of them).
  - `DB_Chat_Grounding::verify( string $reply, array $facts ): array` → `['ok'=>bool, 'bad_prices'=>string[]]`. `$facts` is the shape from `collect_facts`. `ok` is false when the reply contains a money amount not present (±RM0.01) in `$facts['prices']`.

- [ ] **Step 1: Create the assert lib**

`tests/lib/assert.php`:
```php
<?php
$GLOBALS['t_pass'] = 0; $GLOBALS['t_fail'] = 0;
function t_ok($cond, $name) {
    if ($cond) { $GLOBALS['t_pass']++; echo "PASS: $name\n"; }
    else { $GLOBALS['t_fail']++; echo "FAIL: $name\n"; }
}
function t_eq($a, $b, $name) { t_ok($a === $b, $name . " (got " . var_export($a, true) . ")"); }
function t_done() {
    echo "\n{$GLOBALS['t_pass']} passed, {$GLOBALS['t_fail']} failed\n";
    exit($GLOBALS['t_fail'] > 0 ? 1 : 0);
}
```

- [ ] **Step 2: Write the failing test**

`tests/unit/test-grounding.php`:
```php
<?php
require __DIR__ . '/../lib/assert.php';
require __DIR__ . '/../../includes/class-grounding.php';

$facts = DB_Chat_Grounding::collect_facts([
    ['name' => 'HJC Helmet C10', 'price' => 'RM 469.00'],
    ['results' => [['name' => 'NHK R1S', 'price' => 'RM236.00']]],
]);
t_ok(in_array(469.0, $facts['prices'], true), 'collect price 469');
t_ok(in_array(236.0, $facts['prices'], true), 'collect nested price 236');

$ok = DB_Chat_Grounding::verify('The C10 is RM469, in stock.', $facts);
t_ok($ok['ok'] === true, 'grounded price passes');

$bad = DB_Chat_Grounding::verify('That helmet is RM999.', $facts);
t_ok($bad['ok'] === false && in_array('RM999', $bad['bad_prices'], true), 'ungrounded price fails');

$none = DB_Chat_Grounding::verify('Sure, want full-face or open-face?', $facts);
t_ok($none['ok'] === true, 'reply with no price passes');
t_done();
```

- [ ] **Step 3: Run it — expect failure**

Run: `php durianbikers-chatbot/tests/unit/test-grounding.php`
Expected: FAIL ("Class DB_Chat_Grounding not found").

- [ ] **Step 4: Implement `class-grounding.php`**

```php
<?php
defined( 'ABSPATH' ) || defined( 'DB_CHAT_TEST' ) || exit;

final class DB_Chat_Grounding {

	/** Pull every price and product name a tool result surfaced. */
	public static function collect_facts( array $tool_results ) {
		$prices = array();
		$names  = array();
		$walk = function ( $node ) use ( &$walk, &$prices, &$names ) {
			if ( ! is_array( $node ) ) {
				return;
			}
			if ( isset( $node['price'] ) && is_scalar( $node['price'] ) ) {
				$v = self::price_value( (string) $node['price'] );
				if ( null !== $v ) { $prices[] = $v; }
			}
			if ( isset( $node['name'] ) && is_scalar( $node['name'] ) ) {
				$names[] = strtolower( trim( (string) $node['name'] ) );
			}
			foreach ( $node as $child ) {
				if ( is_array( $child ) ) { $walk( $child ); }
			}
		};
		foreach ( $tool_results as $r ) { $walk( $r ); }
		return array( 'prices' => array_values( array_unique( $prices, SORT_NUMERIC ) ), 'names' => $names );
	}

	/** Reject a reply that states a money amount no tool returned. */
	public static function verify( $reply, array $facts ) {
		$prices = isset( $facts['prices'] ) && is_array( $facts['prices'] ) ? $facts['prices'] : array();
		$bad    = array();
		// Match "RM 469", "RM469.00", "469 ringgit", "$469".
		if ( preg_match_all( '/(?:RM|\$)\s?\d[\d,]*(?:\.\d{1,2})?|\d[\d,]*(?:\.\d{1,2})?\s?(?:ringgit)/i', (string) $reply, $m ) ) {
			foreach ( $m[0] as $hit ) {
				$v = self::price_value( $hit );
				if ( null === $v ) { continue; }
				$grounded = false;
				foreach ( $prices as $p ) {
					if ( abs( $p - $v ) < 0.01 ) { $grounded = true; break; }
				}
				if ( ! $grounded ) { $bad[] = trim( $hit ); }
			}
		}
		return array( 'ok' => empty( $bad ), 'bad_prices' => array_values( array_unique( $bad ) ) );
	}

	/** First numeric value in a price-like string, thousands-separator aware. */
	private static function price_value( $text ) {
		if ( ! preg_match( '/([0-9][0-9.,]*)/', (string) $text, $mm ) ) { return null; }
		$n = str_replace( ',', '', $mm[1] );
		$n = rtrim( $n, '.' );
		return is_numeric( $n ) ? (float) $n : null;
	}
}
```

- [ ] **Step 5: Run — expect pass**

Run: `php durianbikers-chatbot/tests/unit/test-grounding.php`
Expected: PASS (4/4).

- [ ] **Step 6: Commit**

```bash
git add durianbikers-chatbot/includes/class-grounding.php durianbikers-chatbot/tests/
git commit -m "feat(chatbot): grounding module rejects ungrounded prices (Phase 1)"
```

---

## Task 2: PII scrub (pure PHP)

**Files:**
- Create: `durianbikers-chatbot/includes/class-pii.php`
- Test: `durianbikers-chatbot/tests/unit/test-pii.php`

**Interfaces:**
- Produces: `DB_Chat_PII::scrub( string $text ): string` — replaces card-like/long digit runs (13–19 digits, optional spaces/dashes) with `[redacted]`, leaving normal prices/quantities/model codes intact.

- [ ] **Step 1: Write the failing test**

`tests/unit/test-pii.php`:
```php
<?php
require __DIR__ . '/../lib/assert.php';
require __DIR__ . '/../../includes/class-pii.php';

t_eq(DB_Chat_PII::scrub('my card is 4111 1111 1111 1111 ok'), 'my card is [redacted] ok', 'scrub spaced card');
t_eq(DB_Chat_PII::scrub('4111-1111-1111-1111'), '[redacted]', 'scrub dashed card');
t_eq(DB_Chat_PII::scrub('the C10 is RM469 in size L'), 'the C10 is RM469 in size L', 'keep price/qty');
t_eq(DB_Chat_PII::scrub('model MT/1308C62A5106/L'), 'model MT/1308C62A5106/L', 'keep model code');
t_done();
```

- [ ] **Step 2: Run — expect failure** (`php tests/unit/test-pii.php` → class not found).

- [ ] **Step 3: Implement `class-pii.php`**

```php
<?php
defined( 'ABSPATH' ) || defined( 'DB_CHAT_TEST' ) || exit;

final class DB_Chat_PII {
	/** Redact card-like sequences: 13–19 digits, optionally grouped by spaces/dashes. */
	public static function scrub( $text ) {
		$text = (string) $text;
		// Grouped (spaces/dashes) 13–19 digit runs.
		$text = preg_replace( '/\b(?:\d[ -]?){13,19}\b/', '[redacted]', $text );
		return $text;
	}
}
```

- [ ] **Step 4: Run — expect pass** (`php tests/unit/test-pii.php` → 4/4).

- [ ] **Step 5: Commit**

```bash
git add durianbikers-chatbot/includes/class-pii.php durianbikers-chatbot/tests/unit/test-pii.php
git commit -m "feat(chatbot): PII scrub for card-like sequences (Phase 1)"
```

---

## Task 3: `get_product` tool (variations, sizes, stock, price)

**Files:**
- Modify: `durianbikers-chatbot/includes/class-product-index.php` (add `get_product_detail`)
- Modify: `durianbikers-chatbot/includes/class-claude-client.php` (schema in `get_tools()`, allowlist in `run_tool()`, budget-round text)
- Modify: `durianbikers-chatbot/includes/class-chat-endpoint.php` (`get_tool_executor()` dispatch)
- Test: `durianbikers-chatbot/tests/server/test-get-product.php` (run via `wp eval-file`)

**Interfaces:**
- Consumes: `DB_Chat_Cart_Writer::in_stock_variations( int $product_id ): array`, `::describe( int $product_id, int $variation_id ): array` (existing read methods).
- Produces: `DB_Chat_Product_Index::get_product_detail( array $input ): array` where `$input = ['product_id'=>int]`; returns `['name'=>string,'url'=>string,'variations'=>[['label'=>string,'size'=>string,'price'=>string,'in_stock'=>bool], ...]]`, whitelisted only. New tool name const `DB_Chat_Claude_Client::TOOL_PRODUCT = 'get_product'`.

- [ ] **Step 1: Write the failing server test**

`tests/server/test-get-product.php`:
```php
<?php
// Run: wp eval-file tests/server/test-get-product.php
require __DIR__ . '/../lib/assert.php';
$pi = new DB_Chat_Product_Index();
// 59463 = "HJC Helmet C10" (variable, has sizes) on live.
$d = $pi->get_product_detail(['product_id' => 59463]);
t_ok(!empty($d['name']), 'has name');
t_ok(is_array($d['variations']) && count($d['variations']) > 0, 'has variations');
$first = $d['variations'][0];
t_ok(isset($first['size'], $first['price'], $first['in_stock']), 'variation whitelisted keys');
t_ok(!isset($d['sku']) && !isset($first['sku']), 'no sku/raw fields leaked');
t_done();
```

- [ ] **Step 2: Run — expect failure**

Run (server): `wp eval-file durianbikers-chatbot/tests/server/test-get-product.php`
Expected: FAIL (method `get_product_detail` not defined).

- [ ] **Step 3: Implement `get_product_detail` in `class-product-index.php`**

```php
/**
 * Whitelisted variation detail for one product. Reuses the cart-writer's
 * read-only variation methods so there is one source of truth for stock.
 *
 * @param array $input { product_id:int }
 * @return array { name, url, variations:[{label,size,price,in_stock}] }
 */
public function get_product_detail( array $input ) {
	$id = isset( $input['product_id'] ) ? (int) $input['product_id'] : 0;
	if ( $id <= 0 || ! function_exists( 'wc_get_product' ) ) {
		return array( 'name' => '', 'url' => '', 'variations' => array() );
	}
	$product = wc_get_product( $id );
	if ( ! $product instanceof WC_Product || 'publish' !== $product->get_status() ) {
		return array( 'name' => '', 'url' => '', 'variations' => array() );
	}
	$out = array(
		'name'       => wp_strip_all_tags( $product->get_name() ),
		'url'        => esc_url_raw( (string) $product->get_permalink() ),
		'variations' => array(),
	);
	if ( class_exists( 'DB_Chat_Cart_Writer' ) ) {
		$writer = new DB_Chat_Cart_Writer();
		foreach ( $writer->in_stock_variations( $id ) as $vid ) {
			$info = $writer->describe( $id, (int) $vid );
			if ( ! is_array( $info ) ) { continue; }
			$out['variations'][] = array(
				'label'    => isset( $info['label'] ) ? wp_strip_all_tags( (string) $info['label'] ) : '',
				'size'     => isset( $info['size'] ) ? (string) $info['size'] : '',
				'price'    => isset( $info['price'] ) ? (string) $info['price'] : '',
				'in_stock' => true,
			);
		}
	}
	return $out;
}
```
> If `in_stock_variations`/`describe` return different key names, adapt the mapping to the real keys (read `class-cart-writer.php:44` and `:77` first). Keep only `label/size/price/in_stock` in the output.

- [ ] **Step 4: Add the tool schema + allowlist in `class-claude-client.php`**

Add const near `TOOL_PRODUCTS` (line ~57): `const TOOL_PRODUCT = 'get_product';`
In `get_tools()` (line ~465), append a schema entry (same shape as `search_products`):
```php
array(
	'name'         => self::TOOL_PRODUCT,
	'description'  => 'Get sizes, per-size stock and price for ONE product you already found with search_products. Input the product_id from a search result. Use before telling a customer a specific size is available.',
	'input_schema' => array(
		'type'       => 'object',
		'properties' => array(
			'product_id' => array( 'type' => 'integer', 'description' => 'The id from a search_products result.' ),
		),
		'required'   => array( 'product_id' ),
	),
),
```
In `run_tool()` allowlist (line ~341) add `self::TOOL_PRODUCT` to the `in_array` list, and in the budget-round text (line ~243) add it to the "do not call again" list.

- [ ] **Step 5: Dispatch in the endpoint `get_tool_executor()`**

In `class-chat-endpoint.php::get_tool_executor()` (line ~1148), add a branch:
```php
if ( DB_Chat_Claude_Client::TOOL_PRODUCT === $name ) {
	return $this->product_index->get_product_detail( is_array( $input ) ? $input : array() );
}
```

- [ ] **Step 6: Deploy the 3 files + run the server test — expect pass**

Deploy per Global Constraints (backup, php -l, md5, purge). Run: `wp eval-file .../tests/server/test-get-product.php` → 4/4.

- [ ] **Step 7: Commit**

```bash
git add durianbikers-chatbot/includes/class-product-index.php durianbikers-chatbot/includes/class-claude-client.php durianbikers-chatbot/includes/class-chat-endpoint.php durianbikers-chatbot/tests/server/test-get-product.php
git commit -m "feat(chatbot): get_product tool for sizes/stock/price (Phase 1)"
```

---

## Task 4: `request_human` tool

**Files:**
- Modify: `durianbikers-chatbot/includes/class-claude-client.php` (schema + allowlist)
- Modify: `durianbikers-chatbot/includes/class-chat-endpoint.php` (`get_tool_executor()` dispatch; return hours text)
- Test: `durianbikers-chatbot/tests/server/test-request-human.php`

**Interfaces:**
- Consumes: the handoff path — set the current conversation to `waiting` via `DB_Chat_Conversation_Store::set_status()` + `DB_Chat_Handoff_Alert::send()` (used by the handoff endpoint today), and `DB_Chat_Handoff` hours/copy.
- Produces: tool name `DB_Chat_Claude_Client::TOOL_HUMAN = 'request_human'`; executor returns `['handoff'=>true, 'message'=>string]` (human-readable hours/next-step). The endpoint sets `$escalate = true` when this tool ran, so `finalize_response` marks the reply for the widget exactly as the current `last_wants_human` path does.

- [ ] **Step 1: Write the failing server test**

`tests/server/test-request-human.php`:
```php
<?php
require __DIR__ . '/../lib/assert.php';
$S = 'DB_Chat_Conversation_Store';
$c = $S::ensure('', ['lang'=>'en','page_url'=>'rh-test']);
$GLOBALS['DB_CHAT_TEST_CONV_ID'] = (int) $c['id'];   // executor reads the active conversation id
$ep = db_chat()->endpoint_for_tests();               // small accessor added in Step 3
$res = $ep->run_tool_for_tests('request_human', ['reason' => 'complaint']);
t_ok(!empty($res['handoff']), 'returns handoff flag');
t_ok(is_string($res['message']) && $res['message'] !== '', 'returns next-step text');
t_eq($S::get((int)$c['id'])['status'], $S::STATUS_WAITING, 'conversation set to waiting');
$S::delete_conversation((int)$c['id']);
t_done();
```
> `endpoint_for_tests()` / `run_tool_for_tests()` are thin test-only accessors (guard with `defined('DB_CHAT_TEST')` or a capability check) so the tool can be exercised without a full REST round-trip. If you prefer, exercise via a real `/chat` call with a stubbed client instead and assert the same three things.

- [ ] **Step 2: Run — expect failure.**

- [ ] **Step 3: Implement** the tool: const + schema (input `{reason: string}`) + allowlist in `class-claude-client.php`; in `get_tool_executor()` add a branch that reads the active conversation id (the endpoint already has it in `handle_request`; pass it into the executor closure), sets it `waiting`, calls the alert, and returns `['handoff'=>true, 'message'=> DB_Chat_Handoff::next_step_text()]` (reuse existing hours/copy — read `class-handoff.php`). Set an endpoint flag so `finalize_response(..., $escalate=true)`.

- [ ] **Step 4: Deploy + run test — expect pass.**

- [ ] **Step 5: Commit** `feat(chatbot): request_human tool triggers handoff (Phase 1)`.

---

## Task 5: Honesty-first system prompt (tone, EN/MS, grounding, one-question)

**Files:**
- Modify: `durianbikers-chatbot/includes/class-claude-client.php::build_system_prompt()` (line ~537)
- Test: `durianbikers-chatbot/tests/unit/test-prompt.php`

**Interfaces:**
- Consumes: existing `build_system_prompt( $static_context, $page_context )` signature (unchanged).
- Produces: same signature; new content string. Add a public/testable static `DB_Chat_Claude_Client::prompt_directives(): string` returning the fixed directive block (so it can be asserted without WP), which `build_system_prompt` concatenates with the dynamic context.

- [ ] **Step 1: Write the failing test**

`tests/unit/test-prompt.php`:
```php
<?php
define('DB_CHAT_TEST', 1);
require __DIR__ . '/../lib/assert.php';
require __DIR__ . '/../lib/wp-shims.php';       // defines __(), esc_*, etc. as pass-throughs
require __DIR__ . '/../../includes/class-claude-client.php';
$p = DB_Chat_Claude_Client::prompt_directives();
foreach (['one question','never claim to be a person','AI assistant','match','only state prices','a human'] as $needle) {
    t_ok(stripos($p, $needle) !== false, "prompt mentions: $needle");
}
t_done();
```
> `wp-shims.php` defines the WP functions the class file references at parse time as no-ops returning their first arg, so the class can be `require`d under plain `php`.

- [ ] **Step 2: Run — expect failure** (method/needles missing).

- [ ] **Step 3: Implement `prompt_directives()`** with the tone guide from spec §9 (warm/short/natural; match language incl. rojak; one question at a time; honest-about-bot, never a person; facts-only comparisons; **only state a price/stock/product that a tool returned this turn — if unknown, say you'll check or offer a human**; hand off on the §8 triggers). Include the 5 EN + 5 MS example replies verbatim from spec §9. Have `build_system_prompt()` prepend `prompt_directives()` to the existing dynamic taxonomy/page context.

- [ ] **Step 4: Run — expect pass.**

- [ ] **Step 5: Commit** `feat(chatbot): honesty-first tone/grounding system prompt (Phase 1)`.

---

## Task 6: Orchestrator — availability gate, grounding, silent fallback

**Files:**
- Create: `durianbikers-chatbot/includes/class-orchestrator.php`
- Modify: `durianbikers-chatbot/durianbikers-chatbot.php` (require + instantiate, pass deps)
- Test: `durianbikers-chatbot/tests/unit/test-orchestrator.php` (stubbed client, pure) + `tests/server/test-orchestrator.php` (real, smoke)

**Interfaces:**
- Consumes: `DB_Chat_Claude_Client::chat()` and `get_last_usage()`; `DB_Chat_Grounding::collect_facts/verify`; `DB_Chat_Lookup_Engine::answer()`; `DB_Chat_AI_Budget` (cap/spend); `DB_Chat_Claude_Client::has_api_key()`.
- Produces: `DB_Chat_Orchestrator::answer( string $message, array $history, callable $tool_executor, string $context, string $pages ): array` → `['reply'=>string,'products'=>array,'pages'=>array,'source'=>'ai'|'fallback','grounding'=>'ok'|'rewritten'|'blocked']`. `can_use_ai(): bool`.
  - Behaviour: if `!can_use_ai()` → return `DB_Chat_Lookup_Engine::answer()` result with `source=fallback`. Else call `chat()`; collect facts from the tool results seen this call (the client exposes them — see Step 3); run `verify`; if not ok, re-call `chat()` once with an appended corrective user turn; if still not ok, return the safe line + `grounding=blocked` and set escalate.

- [ ] **Step 1: Write the failing pure test** (`tests/unit/test-orchestrator.php`)

```php
<?php
define('DB_CHAT_TEST', 1);
require __DIR__ . '/../lib/assert.php';
require __DIR__ . '/../lib/wp-shims.php';
require __DIR__ . '/../../includes/class-grounding.php';
require __DIR__ . '/../../includes/class-orchestrator.php';
require __DIR__ . '/stubs.php';   // FakeClient, FakeLookup, FakeBudget (test doubles)

// AI unavailable → falls back to keyword engine.
$o = new DB_Chat_Orchestrator(new FakeClient([]), new FakeLookup('fallback reply'), new FakeBudget(false));
$r = $o->answer('hi', [], function(){return [];}, '', '');
t_eq($r['source'], 'fallback', 'uses fallback when AI unavailable');
t_eq($r['reply'], 'fallback reply', 'fallback text');

// AI available, grounded reply passes through.
$client = new FakeClient(['The C10 is RM469.'], [['name'=>'HJC C10','price'=>'RM469.00']]);
$o2 = new DB_Chat_Orchestrator($client, new FakeLookup('x'), new FakeBudget(true));
$r2 = $o2->answer('price?', [], function(){return [];}, '', '');
t_eq($r2['source'], 'ai', 'uses AI when available');
t_eq($r2['grounding'], 'ok', 'grounded reply ok');

// AI invents a price → one rewrite; if still bad → blocked + safe line.
$client2 = new FakeClient(['It is RM999.', 'It is RM999 still.'], [['name'=>'HJC C10','price'=>'RM469.00']]);
$o3 = new DB_Chat_Orchestrator($client2, new FakeLookup('x'), new FakeBudget(true));
$r3 = $o3->answer('price?', [], function(){return [];}, '', '');
t_eq($r3['grounding'], 'blocked', 'ungrounded price blocked after rewrite');
t_ok(stripos($r3['reply'], 'RM999') === false, 'blocked reply hides bad price');
t_done();
```
Also create `tests/unit/stubs.php` with `FakeClient` (returns queued replies + a fixed tool-results array via a `last_tool_results()` accessor), `FakeLookup` (`answer()` returns a fixed array), `FakeBudget` (`can_spend()` boolean).

- [ ] **Step 2: Run — expect failure.**

- [ ] **Step 3: Implement `class-orchestrator.php`.**

Constructor takes `($client, $lookup_engine, $budget)`. `can_use_ai()` = `$client->has_api_key() && $budget->can_spend()`. `answer()`:
1. If `!can_use_ai()` → `$res = $lookup->answer($message,$history); $res['source']='fallback'; return $res;`
2. `$reply = $client->chat($this->build_messages($history,$message), $context, $tool_executor, $pages);` (build_messages here is a thin helper; the endpoint may pass pre-built messages instead — keep one builder).
3. `$facts = DB_Chat_Grounding::collect_facts( $client->last_tool_results() );`
4. `$check = DB_Chat_Grounding::verify($reply, $facts);` if ok → return with `source=ai, grounding=ok`.
5. Not ok → append corrective user turn ("A draft named a price no tool returned: {bad}. Restate using only tool facts; if unknown, say you'll check.") and `chat()` once more; re-verify. If ok → `grounding=rewritten`. If still bad → reply = safe line ("Let me double-check that for you — one moment."), `grounding=blocked`, set `escalate=true`.
6. Resolve `products`/`pages` from the same `resolve_products`/page matcher the endpoint uses today (pass them in or call the index).

> **Client change required:** `class-claude-client.php` must expose the decoded tool results of the last `chat()` (add `private $last_tool_results` populated in the tool loop, and `public function last_tool_results(): array`). Add this in Task 6 (it's the client's data). Keep it side-effect-free.

- [ ] **Step 4: Run pure test — expect pass.**

- [ ] **Step 5: Server smoke** (`tests/server/test-orchestrator.php`): with the real client + a throwaway "do you sell car?" → assert reply is non-empty and contains no RM price (grounded decline). Run via `wp eval-file`.

- [ ] **Step 6: Commit** `feat(chatbot): orchestrator with grounding + silent fallback (Phase 1)`.

---

## Task 7: History depth + PII scrub in message building

**Files:**
- Modify: `durianbikers-chatbot/includes/class-chat-endpoint.php` (`build_messages`, history cap)
- Test: `durianbikers-chatbot/tests/unit/test-history.php`

**Interfaces:**
- Consumes: `DB_Chat_PII::scrub()`.
- Produces: history cap raised from 10 to 20 in the sanitizer; every user/assistant content string passes through `DB_Chat_PII::scrub()` before it enters the messages array sent to Claude.

- [ ] **Step 1: Write the failing test** — build a 15-entry history + a message containing `4111 1111 1111 1111`; assert all 15 survive (not truncated to 10) and the card digits are `[redacted]` in the built messages. (Extract the history-sanitising + message-building into a static/testable helper `DB_Chat_Chat_Endpoint::sanitize_history(array $raw): array` and `::build_messages(array $history, string $message): array` so they run under `php` with shims.)
- [ ] **Step 2: Run — expect failure** (cap still 10 / no scrub).
- [ ] **Step 3: Implement** — raise cap to 20; wrap each content in `DB_Chat_PII::scrub()`.
- [ ] **Step 4: Run — expect pass.**
- [ ] **Step 5: Commit** `feat(chatbot): 20-turn context + PII scrub before Claude (Phase 1)`.

---

## Task 8: Hard-check conversation runner + seed scenarios

**Files:**
- Create: `durianbikers-chatbot/tests/run-hard.php` (server runner)
- Create: `durianbikers-chatbot/tests/conversations/*.json` (seed scenarios)
- Test: the runner IS the test; a green run is the deliverable.

**Interfaces:**
- Consumes: `DB_Chat_Orchestrator` with a **stub client** that replays a scenario's scripted `turns[].tool_calls` and `turns[].reply`, and the **real** tool executor (read-only WooCommerce). Hard assertions per turn from the scenario.
- Produces: a runner run via `wp eval-file tests/run-hard.php` that loads every `conversations/*.json`, drives the orchestrator turn-by-turn, and asserts the hard checks, printing pass/fail counts.

**Scenario format** (`conversations/off-topic-car.json`):
```json
{
  "name": "off-topic: do you sell car",
  "lang": "en",
  "turns": [
    { "user": "do you sell car?",
      "stub_tool_results": [],
      "stub_reply": "We don't do cars — we're a motorcycle parts shop. Looking for something for your bike?",
      "assert": { "no_ungrounded_price": true, "no_order_created": true, "reply_language": "en" } }
  ]
}
```

- [ ] **Step 1: Author the seed scenarios** — one JSON per known bug (human-request-returns-T&C, under-RMx, jet-engines, advice/maintenance, invented-price, tak-nak/不要, bare yes/no, vague "the red one"/"yang tadi", language-switch, rojak, typo, off-topic, rude/frustrated, "are you a bot?"). Aim ≥ 20 in Phase 1 (browse/cart/checkout scenarios are added in Phases 2–3). Each carries hard `assert` keys only (graded keys come in Task 9).
- [ ] **Step 2: Implement `run-hard.php`** — for each scenario, construct the orchestrator with a `ScenarioClient` (returns the turn's `stub_reply` and exposes `stub_tool_results` via `last_tool_results()`), run `answer()`, and evaluate asserts: `no_ungrounded_price` → `DB_Chat_Grounding::verify(reply, collect_facts(stub_tool_results))['ok']`; `no_order_created` → a WC order-count before/after is unchanged; `reply_language` → cheap heuristic or skip in hard layer (belongs to graded). Print `PASS/FAIL` per scenario + totals; exit non-zero on any fail.
- [ ] **Step 3: Run — expect green** (`wp eval-file tests/run-hard.php`). Fix scenarios/asserts until green.
- [ ] **Step 4: Commit** `test(chatbot): hard-check conversation runner + seed scenarios (Phase 1)`.

---

## Task 9: Graded runner + rubric (real Claude, budget-guarded)

**Files:**
- Create: `durianbikers-chatbot/tests/run-graded.php`
- Create: `durianbikers-chatbot/tests/conversations/*.graded.json` (graded expectations layered onto scenarios)
- Test: a graded run prints per-scenario scores; deliverable is the runner + a baseline run.

**Interfaces:**
- Consumes: the **real** orchestrator (real Claude Haiku) + a Claude "judge" call scoring each conversation against the rubric.
- Produces: `run-graded.php` runnable via `wp eval-file`, guarded by a `--max-spend` check against `DB_Chat_AI_Budget`; prints per-scenario pass/fail on rubric items (natural tone, same language, one-question-at-a-time, answered-what-was-asked, honest-about-bot) + a total, and the RM spent.

- [ ] **Step 1: Define the rubric** (5 boolean items above) and the judge prompt (a fixed grader instruction; the judge returns JSON `{tone:bool,language:bool,one_question:bool,answered:bool,honest:bool}`).
- [ ] **Step 2: Implement `run-graded.php`** — run each scenario's user turns through the real orchestrator, collect the assistant replies, send the transcript + rubric to the judge (Haiku), parse the JSON, tally. Stop early if projected spend would exceed the passed cap. Print totals + RM spent (from `get_last_usage()` costed via `DB_Chat_AI_Budget`).
- [ ] **Step 3: Baseline run** — run once on the server; record the pass rate. Do not block on a perfect score yet; this establishes the tone baseline. (Cost ≈ RM10.)
- [ ] **Step 4: Commit** `test(chatbot): graded conversation runner + rubric (Phase 1)`.

---

## Task 10: Wire the orchestrator into the endpoint + test-page default; deploy

**Files:**
- Modify: `durianbikers-chatbot/includes/class-chat-endpoint.php` (route `ai` mode through `DB_Chat_Orchestrator`, keep `finalize_response`/persistence shell; wire `escalate` from `request_human`/grounding-blocked)
- Modify: `durianbikers-chatbot/durianbikers-chatbot.php` (instantiate orchestrator with deps)
- Test: server integration smoke + pre-flight health check.

**Interfaces:**
- Consumes: `DB_Chat_Orchestrator::answer()`; existing `finalize_response()`, `resolve_products`, conversation store.
- Produces: in `handle_request()`, when `get_engine()` is `ENGINE_AI` (mode `ai`), call the orchestrator instead of the inline Claude block; keep the `ENGINE_LOOKUP` branch as the explicit-lookup path. `escalate = ($result['grounding']==='blocked') || $orchestrator_requested_human`.

- [ ] **Step 1: Write the failing server integration test** (`tests/server/test-endpoint-ai.php`): set mode `ai`, POST-style call the handler for "do you sell car?" (build a `WP_REST_Request` like the existing smoke scripts), assert 200, non-empty reply, no RM price in reply, `products` array present. Expect failure before wiring.
- [ ] **Step 2: Wire** the orchestrator into `handle_request()` for `ai` mode; instantiate it in the bootstrap with `($client, $lookup_engine, $ai_budget)`.
- [ ] **Step 3: Run the integration test — expect pass.**
- [ ] **Step 4: Set the test page to `ai` mode** (option `db_chat_mode = ai`) via `wp option update` on the server — this is the switch that makes Claude the orchestrator on the test page. Keep it staff/test-gated. Confirm the budget cap option is set (RM100).
- [ ] **Step 5: Deploy + verify** — backup, `php -l`, md5, purge; run `run-hard.php` (green) and the integration smoke; pre-flight health check (`/my/`, `/gb/`, `/my/shop/`, checkout 200). Then a manual test on the private link: "do you sell car?", "helmet under 300", "bro ada stock size L?", "are you a bot?", a frustrated line → handoff.
- [ ] **Step 6: Commit** `feat(chatbot): Claude orchestrator is the ai-mode brain on the test page (Phase 1)`.

---

## Self-review notes (author)

- **Spec coverage:** §3 architecture → Tasks 6,10. §4 read tools → Tasks 3,4 (+ existing search_products/search_pages/get_my_orders reused). §5.1 grounding → Tasks 1,6. §5.5 PII → Tasks 2,7. §9 tone → Task 5. §10 suite → Tasks 8,9. §11 keep-working → Task 10 wires `ai` mode without touching widget/inbox; cart/checkout tools are Phases 2–3 (out of scope here, as intended). §14 cost/budget → Task 6 gate.
- **Deferred to Phase 2/3 (not gaps):** cart tools (`view_cart`, `propose/commit_cart_change`), checkout tools, `/gb` shipping guard, full checkout PII capture. The PII scrub (Task 2/7) is the Phase-1 layer; the full "never to Claude" capture lands with the checkout form in Phase 3.
- **Test reality:** pure units run with `php` locally; WooCommerce/real-Claude parts run via `wp eval-file` on the server (no local WP/phpunit). Add the `DB_CHAT_TEST` guard + `wp-shims.php` so class files parse under plain `php`.
- **Existing-code touchpoints to read before editing:** `class-cart-writer.php:44,77` (variation key names), `class-claude-client.php:465,537` (tool + prompt builders), `class-chat-endpoint.php:680-735,1148` (AI branch + executor), `class-handoff.php` (hours/copy). If a real signature differs from this plan, follow the code and note it in the task report.
