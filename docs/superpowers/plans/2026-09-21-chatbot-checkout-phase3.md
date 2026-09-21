# Chatbot Checkout-to-Payment (Phase 3) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the chatbot carry a customer from a filled cart to a "Proceed to payment" button — capturing delivery details into the WooCommerce session only, never creating an order or touching payment.

**Architecture:** Four new read/session tools (`set_checkout_details`, `get_checkout_summary`, `get_checkout_link`, `check_shipping_country`) added behind the existing orchestrator + code-gate pattern from Phase 2. All personal data is written **only** to `WC()->customer` (the checkout session) and is **never** sent to Claude: capture is via a widget form (guest) or a tap-confirm card (logged-in); tools return booleans/status and a widget-only address string, not PII values; Claude's message history is PII-scrubbed. A hard wall in code means no tool can create an order, submit checkout, choose/submit payment, mark paid, apply coupons, change prices, or touch the gateway — the flow stops at filling the session + handing over `wc_get_checkout_url()`.

**Tech Stack:** WordPress/WooCommerce plugin (PHP 8.3, no Composer), vanilla-JS widget, Anthropic Messages API (Haiku 4.5) via `class-claude-client.php`. Tests: pure-PHP units (`php tests/unit/*.php`) + server smokes (`wp eval-file`) + browser verification on the staff test page.

**Spec:** `docs/superpowers/specs/2026-09-15-chatbot-ai-orchestrator-redesign-design.md` (§5.3 hard wall, §6 checkout flow, §7 PII handling, §13 deploy safety)

## Global Constraints

- **HARD WALL (§5.3):** No tool may create an order, submit checkout, choose/submit a payment method, mark paid, apply coupons, change prices, or touch gateway settings. Checkout tools stop at filling `WC()->customer` + returning `wc_get_checkout_url()`. Verified by an explicit test that asserts no order exists after the full flow.
- **PII NEVER TO CLAUDE (§7):** Name/phone/email/address are written only to `WC()->customer`. Tool results returned into Claude's context contain booleans/field-status only — never a PII value. The summary's address line is delivered to the widget via a response key, not passed back into Claude's messages. History is PII-scrubbed (address/phone/email/card patterns) before the messages array is built.
- **Card/bank scrub:** Card-pattern text is scrubbed by `DB_Chat_PII::scrub` before it reaches logs OR Claude; the bot never repeats card/bank details and tells the customer to enter payment only on the checkout page.
- **Prefix/domain:** class/const prefix `DB_Chat_`, option prefix `db_chat_`, text domain `durianbikers-chatbot`. No new dependencies.
- **Deterministic where money-adjacent:** follow Phase 2's lesson — do not rely on the model to call a commit-style tool for an irreversible step. The "Proceed to payment" button is a widget action from `get_checkout_link`; there is no auto-advance to payment.
- **Handoff-coupled (accepted for v1):** checkout tools require a conversation public_id (handoff on), same as the cart tools. Ships with handoff on.
- **Deploy (§13):** staff/test page only; `.bak-<stamp>` backups + `php -l`/`node --check` + md5 verify + LiteSpeed purge; pre-flight health check (`/my/`, `/gb/`, `/my/shop/`, checkout reachable 200); snapshot/restore the admin cart around any cart/checkout test; ssh gate lift/restore; never push git or go customer-facing without asking; API key never printed/committed.
- **Controller/subagent split (as Phase 2):** implementers write code + local `php -l`/unit tests + a server smoke script + commit locally. The CONTROLLER does all ssh deploy, `wp eval-file` smokes, and browser verification (ssh is authorized to the controller only).

---

## File Structure

- **Create `includes/class-checkout-writer.php`** — `DB_Chat_Checkout_Writer`: the only place chat writes checkout details to `WC()->customer`; sanitises + validates; returns field-status booleans (never values). Also holds the read helpers `summary()` (items+shipping+total+address string) and `shipping_country_served()`. Mirrors `class-cart-writer.php`'s "re-read from WooCommerce, return whitelisted shape" discipline.
- **Modify `includes/class-claude-client.php`** — register the 4 tools + descriptions + allowlist + budget text; add a CHECKOUT section to `prompt_directives()`.
- **Modify `includes/class-chat-endpoint.php`** — dispatch the 4 tools in `get_tool_executor()`; surface `checkout` (summary+address string) and `checkout_link` response keys from the tool results (like Phase 2's `cart`/`proposal`); accept a structured `checkout_details` request param (the guest form / tap-confirm payload) and route it to the writer deterministically (like `cart_confirm`); mark checkout turns no-store.
- **Modify `includes/class-pii.php`** — extend `scrub()` to also redact email, phone, and long address-like strings (currently card-only), used for history sent to Claude and for logs.
- **Modify `includes/class-messages.php`** (context builder) — run `DB_Chat_PII::scrub` over history before the messages array reaches Claude. (If scrub is already applied there, extend it; if not, add it.)
- **Modify `assets/chatbot.js`** — logged-in tap-confirm card; guest inline checkout form (reuse `openContactStep`/`submitHandoff` pattern) POSTing `checkout_details`; checkout summary card; "Proceed to payment" button from `checkout_link`.
- **Modify `durianbikers-chatbot.php`** — `require_once` the new class.
- **Create `tests/unit/test-checkout-writer.php`** — pure sanitisation/validation + "returns no PII values" assertions.
- **Create server smokes** `~/claude-uploads/t-checkout-*.php` (controller runs via `wp eval-file`): writer round-trip into `WC()->customer`, summary shape, no-order-created assertion, `/gb` country check.
- **Grow** `tests/run-hard.php` with checkout hard-checks (PII-never-in-tool-result; hard-wall predicate).

---

## Task 1: `DB_Chat_Checkout_Writer` — set details into `WC()->customer` (PII boundary)

**Files:**
- Create: `durianbikers-chatbot/includes/class-checkout-writer.php`
- Test: `durianbikers-chatbot/tests/unit/test-checkout-writer.php`

**Interfaces:**
- Produces:
  - `DB_Chat_Checkout_Writer::sanitize_details(array $raw): array` — returns a normalised map with keys `first_name,last_name,email,phone,address_1,address_2,city,state,postcode,country`, each a sanitised string ('' if absent/invalid). Pure; no WC calls.
  - `->set_details(array $raw): array` — sanitises, writes to `WC()->customer` billing **and** shipping, `->save()`, returns `array{ ok:bool, fields:array<string,bool> }` where `fields` is which fields are now non-empty. **Never returns any value string.**
  - `->has_required_for_checkout(): array{ ok:bool, missing:array<string> }` — checks the session has the WooCommerce-required billing fields for MY/GB (first_name,last_name,address_1,city,postcode,country,phone,email).

**Safety property:** the return shape contains only booleans and field *names*, never a captured value. The test asserts this.

- [ ] **Step 1: Write the failing test** (`test-checkout-writer.php`)

```php
<?php
require __DIR__ . '/stubs.php';                       // WC()/wc_* stubs used by the other unit tests
require __DIR__ . '/../../includes/class-pii.php';
require __DIR__ . '/../../includes/class-checkout-writer.php';
require __DIR__ . '/../lib/assert.php';

// sanitize is pure and strips control/tags, trims, caps length.
$s = DB_Chat_Checkout_Writer::sanitize_details([
    'first_name' => "  Farah\n", 'email' => 'a@b.com', 'phone' => '+60 12-345 6789',
    'address_1' => '<b>12 Jalan</b>', 'country' => 'my', 'junk' => 'x',
]);
t_ok($s['first_name'] === 'Farah', 'trims name');
t_ok($s['email'] === 'a@b.com', 'keeps email');
t_ok(strpos($s['address_1'], '<') === false, 'strips tags from address');
t_ok($s['country'] === 'MY', 'uppercases country');
t_ok(!array_key_exists('junk', $s), 'drops unknown keys');

// set_details returns booleans only — NEVER a value.
$w = new DB_Chat_Checkout_Writer();
$res = $w->set_details(['first_name' => 'Farah', 'email' => 'a@b.com']);
t_ok($res['ok'] === true, 'set ok');
t_ok($res['fields']['first_name'] === true, 'first_name flagged set');
t_ok($res['fields']['phone'] === false, 'absent field flagged not-set');
$flat = json_encode($res);
t_ok(strpos($flat, 'Farah') === false && strpos($flat, 'a@b.com') === false,
     'set_details result contains NO PII values');
t_done();
```

- [ ] **Step 2: Run to verify it fails**

Run: `php durianbikers-chatbot/tests/unit/test-checkout-writer.php`
Expected: FAIL ("class DB_Chat_Checkout_Writer not found").

- [ ] **Step 3: Implement `class-checkout-writer.php`**

Key methods (full sanitiser; `set_details` writes billing+shipping and returns booleans only):

```php
<?php
defined( 'ABSPATH' ) || exit;

class DB_Chat_Checkout_Writer {

	const FIELDS = array( 'first_name','last_name','email','phone','address_1','address_2','city','state','postcode','country' );
	const REQUIRED = array( 'first_name','last_name','email','phone','address_1','city','postcode','country' );

	public static function sanitize_details( array $raw ) {
		$out = array();
		foreach ( self::FIELDS as $f ) {
			$v = isset( $raw[ $f ] ) && is_scalar( $raw[ $f ] ) ? (string) $raw[ $f ] : '';
			$v = trim( wp_strip_all_tags( $v ) );
			$v = mb_substr( $v, 0, 120 );
			if ( 'email' === $f ) {
				$v = is_email( $v ) ? sanitize_email( $v ) : '';
			} elseif ( 'country' === $f || 'state' === $f ) {
				$v = strtoupper( preg_replace( '/[^A-Za-z]/', '', $v ) );
			}
			$out[ $f ] = $v;
		}
		return $out;
	}

	public function set_details( array $raw ) {
		$clean = self::sanitize_details( $raw );
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return array( 'ok' => false, 'fields' => array_fill_keys( self::FIELDS, false ) );
		}
		$c = WC()->customer;
		foreach ( $clean as $f => $v ) {
			if ( '' === $v ) { continue; }
			$billing  = 'set_billing_' . $f;
			$shipping = 'set_shipping_' . $f;
			if ( is_callable( array( $c, $billing ) ) )  { $c->{$billing}( $v ); }
			// email/phone are billing-only in WooCommerce shipping; guard by callable check
			if ( is_callable( array( $c, $shipping ) ) ) { $c->{$shipping}( $v ); }
		}
		$c->save();
		$fields = array();
		foreach ( self::FIELDS as $f ) { $fields[ $f ] = '' !== $clean[ $f ]; }
		return array( 'ok' => true, 'fields' => $fields );
	}

	public function has_required_for_checkout() {
		$missing = array();
		$c = function_exists( 'WC' ) ? WC()->customer : null;
		foreach ( self::REQUIRED as $f ) {
			$getter = 'get_billing_' . $f;
			$val = $c && is_callable( array( $c, $getter ) ) ? (string) $c->{$getter}() : '';
			if ( '' === trim( $val ) ) { $missing[] = $f; }
		}
		return array( 'ok' => empty( $missing ), 'missing' => $missing );
	}
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php durianbikers-chatbot/tests/unit/test-checkout-writer.php`
Expected: PASS (all assertions).

- [ ] **Step 5: Commit**

```bash
git add durianbikers-chatbot/includes/class-checkout-writer.php durianbikers-chatbot/tests/unit/test-checkout-writer.php
git commit -m "feat(chatbot): checkout-writer — set details to WC()->customer, booleans only (Phase 3)"
```

---

## Task 2: `summary()` + `shipping_country_served()` reads

**Files:**
- Modify: `durianbikers-chatbot/includes/class-checkout-writer.php`
- Test: `durianbikers-chatbot/tests/unit/test-checkout-writer.php` (extend)

**Interfaces:**
- Produces:
  - `->summary(): array{ items:array<{name,label,qty,line_price}>, shipping:string, total:string, address_line:string, cart_url:string }` — items reuse `DB_Chat_Cart_Writer::contents()`; `shipping`/`total` are formatted MYR strings from WooCommerce after `WC()->cart->calculate_shipping()`; `address_line` is a single human string built from `WC()->customer` for the WIDGET to render (endpoint keeps it out of Claude's context).
  - `->shipping_country_served( string $country ): array{ served:bool, zone:string }` — true if a shipping zone with at least one enabled method matches a package shipped to `$country`.

**Interfaces consumed:** `DB_Chat_Cart_Writer::contents()` from Phase 2 (`{items:[{key,name,label,qty,line_price,...}], count, total, cart_url}`).

- [ ] **Step 1: Write the failing test** — assert `summary()` returns an `address_line` string and MYR-formatted `total`; assert `shipping_country_served('XX')` for an unserved code returns `served:false`. (Uses the WC stubs; the deep WooCommerce shipping calc is exercised in the server smoke, Task 9.)

```php
$w = new DB_Chat_Checkout_Writer();
$sum = $w->summary();
t_ok(array_key_exists('address_line', $sum) && is_string($sum['address_line']), 'summary has address_line string');
t_ok(array_key_exists('total', $sum) && is_string($sum['total']), 'summary total is a string');
$srv = $w->shipping_country_served('ZZ');
t_ok(isset($srv['served']) && $srv['served'] === false, 'unknown country not served');
```

- [ ] **Step 2: Run to verify it fails** — `php durianbikers-chatbot/tests/unit/test-checkout-writer.php` → FAIL (method not defined).

- [ ] **Step 3: Implement** `summary()` and `shipping_country_served()`:

```php
public function summary() {
	$cart = ( new DB_Chat_Cart_Writer() )->contents(); // items/count/total/cart_url
	$shipping = '';
	if ( function_exists( 'WC' ) && WC()->cart ) {
		WC()->cart->calculate_shipping();
		WC()->cart->calculate_totals();
		$shipping = wp_strip_all_tags( (string) WC()->cart->get_cart_shipping_total() );
	}
	return array(
		'items'        => isset( $cart['items'] ) ? $cart['items'] : array(),
		'shipping'     => $shipping,
		'total'        => isset( $cart['total'] ) ? (string) $cart['total'] : '',
		'address_line' => $this->address_line(),
		'cart_url'     => isset( $cart['cart_url'] ) ? (string) $cart['cart_url'] : '',
	);
}

private function address_line() {
	$c = function_exists( 'WC' ) ? WC()->customer : null;
	if ( ! $c ) { return ''; }
	$parts = array_filter( array(
		trim( $c->get_shipping_first_name() . ' ' . $c->get_shipping_last_name() ),
		$c->get_shipping_address_1(), $c->get_shipping_city(),
		$c->get_shipping_postcode(), $c->get_shipping_country(),
	) );
	return wp_strip_all_tags( implode( ', ', $parts ) );
}

public function shipping_country_served( $country ) {
	$country = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $country ) );
	if ( '' === $country || ! class_exists( 'WC_Shipping_Zones' ) ) {
		return array( 'served' => false, 'zone' => '' );
	}
	$package = array( 'destination' => array( 'country' => $country, 'state' => '', 'postcode' => '' ) );
	$zone    = WC_Shipping_Zones::get_zone_matching_package( $package );
	$methods = $zone ? $zone->get_shipping_methods( true ) : array(); // enabled only
	return array( 'served' => ! empty( $methods ), 'zone' => $zone ? $zone->get_zone_name() : '' );
}
```

- [ ] **Step 4: Run to verify it passes.**
- [ ] **Step 5: Commit** — `feat(chatbot): checkout-writer — summary + shipping-country reads (Phase 3)`.

---

## Task 3: Register the four tools + executor dispatch (PII stays out of Claude)

**Files:**
- Modify: `durianbikers-chatbot/includes/class-claude-client.php` (consts, schemas, allowlist, budget line)
- Modify: `durianbikers-chatbot/includes/class-chat-endpoint.php` (dispatch + response keys)
- Modify: `durianbikers-chatbot/durianbikers-chatbot.php` (`require_once` the writer)
- Test: `durianbikers-chatbot/tests/unit/test-checkout-tools.php` (new; stub executor, assert PII-free tool results)

**Interfaces (tool contracts — results returned to Claude carry NO PII values):**
- `set_checkout_details` → `{ ok:bool, fields:{<name>:bool}, missing:[<name>] }` (from writer). **No values.** Actual capture is the widget payload (Task 6), not the model.
- `get_checkout_summary` → to Claude: `{ ready:bool, item_count:int, has_address:bool, shipping:string, total:string }`. The `address_line` and full items go to the **widget** via the `checkout` response key, NOT into the tool result Claude sees.
- `get_checkout_link` → `{ url:string }` (the checkout URL is not PII).
- `check_shipping_country` → `{ served:bool, zone:string }`.

**Tool consts + schemas** (append near the cart tool consts):

```php
const TOOL_CHECKOUT_DETAILS = 'set_checkout_details';
const TOOL_CHECKOUT_SUMMARY = 'get_checkout_summary';
const TOOL_CHECKOUT_LINK    = 'get_checkout_link';
const TOOL_SHIPPING_COUNTRY = 'check_shipping_country';
```

Schema/description highlights (add to the `tools()` array):
- `set_checkout_details`: description — "Record that the customer's delivery details have been captured for checkout. You do NOT pass the details themselves — the customer enters them in a form or taps to confirm a saved address, and the system writes them to their checkout session. Call this to advance the flow; the result tells you which fields are now set and which are still missing." input_schema: empty object.
- `get_checkout_summary`: "Get a short checkout summary (item count, whether a delivery address is set, shipping cost, total). The customer sees the itemised summary and address; you only see counts and totals." input_schema: empty object.
- `get_checkout_link`: "Get the URL of the checkout page ('Proceed to payment'). The customer pays there; you never take payment. Only call once the summary is confirmed." input_schema: empty object.
- `check_shipping_country`: "Check whether we ship to a country before promising delivery (for the international /gb store). action input: country (ISO-2)." input_schema: `{country: string, required:[country]}`.

**Executor dispatch** (in `get_tool_executor`'s closure, alongside the cart branches):

```php
if ( DB_Chat_Claude_Client::TOOL_CHECKOUT_DETAILS === $name ) {
	return $this->tool_checkout_details_status(); // reads writer->has_required_for_checkout(); NO values
}
if ( DB_Chat_Claude_Client::TOOL_CHECKOUT_SUMMARY === $name ) {
	return $this->tool_checkout_summary(); // returns Claude-safe counts; stashes widget summary via $this
}
if ( DB_Chat_Claude_Client::TOOL_CHECKOUT_LINK === $name ) {
	return array( 'url' => (string) wc_get_checkout_url() );
}
if ( DB_Chat_Claude_Client::TOOL_SHIPPING_COUNTRY === $name ) {
	$w = new DB_Chat_Checkout_Writer();
	return $w->shipping_country_served( isset( $input['country'] ) ? (string) $input['country'] : '' );
}
```

`tool_checkout_summary()` builds the full `summary()`, returns Claude-safe fields to the model, and records the widget-facing `checkout` payload (items + address_line + shipping + total + url) on `$this` so `handle_request` can attach it to the response — exactly how Phase 2 surfaces `cart`/`proposal` from `last_tool_results()`, but here the address string deliberately does NOT go through the tool-result-to-Claude path.

- [ ] **Step 1–2:** Write `test-checkout-tools.php`: stub the executor, call each tool, assert the returned array (what Claude sees) contains no PII value and matches the contracts. Run → fails.
- [ ] **Step 3:** Implement consts, schemas, allowlist entries (add all four to the `in_array(...)` allowlists at the two guard sites), the budget "do not call ... again" line, the dispatch branches, the private `tool_checkout_*` methods, and the `checkout`/`checkout_link` response-key surfacing in `handle_request`. `require_once` the writer in the main plugin file.
- [ ] **Step 4:** Run unit tests → pass; `php -l` all changed PHP.
- [ ] **Step 5: Commit** — `feat(chatbot): register checkout tools + executor dispatch, PII-free results (Phase 3)`.

---

## Task 4: Deterministic checkout-details capture from the widget

**Files:**
- Modify: `durianbikers-chatbot/includes/class-chat-endpoint.php` (`register_routes` arg + short-circuit in `handle_request`)
- Test: extend `test-checkout-tools.php`

**Why deterministic:** like Phase 2's `cart_confirm`, the customer's real PII must reach `WC()->customer` in code, not via the model. The widget POSTs `checkout_details` (guest form values) or `checkout_confirm_address:true` (logged-in tap-confirm). `handle_request` writes them via `DB_Chat_Checkout_Writer` **before** the model runs and never puts the values in the messages array.

**Interfaces:**
- Request param `checkout_details` (array|null): `{first_name,last_name,email,phone,address_1,address_2,city,state,postcode,country}` from the guest form.
- Request param `checkout_confirm_address` (bool|null): logged-in customer tapped "Deliver to saved address ✓" — copy their saved WC billing/shipping into the session (already there for logged-in; this just marks it confirmed).

**Short-circuit** (add near the Phase 2 `cart_confirm` block in `handle_request`, guarded by `null !== $conversation`):

```php
$checkout_in = $this->read_checkout_details( $request ); // whitelists + returns array|null
if ( null !== $checkout_in && null !== $conversation ) {
	$writer = new DB_Chat_Checkout_Writer();
	$writer->set_details( $checkout_in ); // values go ONLY to WC()->customer
	// Fall through to the normal turn; the model will call get_checkout_summary next.
	// Replace the raw message the browser sent for this turn with a neutral marker
	// so no PII is echoed into history / logs.
	$message = '[checkout details provided]';
}
```

`read_checkout_details` returns the sanitised map only when the payload is an array with at least one known field; it never returns free text. The neutral `$message` replacement guarantees PII entered in the form is not stored in conversation history or logs.

- [ ] **Step 1–2:** Test: POST-shaped input with a name/email → assert (a) writer received it, (b) the history/message passed onward is the neutral marker, not the PII. Run → fails.
- [ ] **Step 3:** Implement `read_checkout_details` + the short-circuit + the `checkout_details`/`checkout_confirm_address` REST args (optional, array/bool validated).
- [ ] **Step 4:** Unit tests pass; `php -l`.
- [ ] **Step 5: Commit** — `feat(chatbot): deterministic checkout-details capture -> WC session, PII never to Claude (Phase 3)`.

---

## Task 5: History PII scrub before Claude + card scrub on every turn

**Files:**
- Modify: `durianbikers-chatbot/includes/class-pii.php` (`scrub` also redacts email/phone/address-ish)
- Modify: `durianbikers-chatbot/includes/class-messages.php` (scrub history before building the messages array — extend if already present)
- Test: `durianbikers-chatbot/tests/unit/test-pii.php` (extend)

**Interfaces:**
- `DB_Chat_PII::scrub( string ): string` — now also redacts: emails (`\S+@\S+\.\S+`), phone-like runs (7+ digits with spaces/dashes/`+`), and long digit+word address strings are left as-is EXCEPT card patterns (kept from today). Conservative: never over-redact ordinary product text (e.g. "RM653.00", "size 42") — test both keeps and redactions.

- [ ] **Step 1: Failing test** — assert `scrub('reach me at a@b.com or +60 12-345 6789')` redacts the email and phone; assert `scrub('the Shinko 705 is RM420.00 in size 120/80-17')` is unchanged (no false redaction).
- [ ] **Step 2:** Run → fails.
- [ ] **Step 3:** Extend `scrub` with the email + phone patterns (keep the existing card pattern). In `class-messages.php`, ensure every history entry text passes through `DB_Chat_PII::scrub` before the array reaches `DB_Chat_Claude_Client`.
- [ ] **Step 4:** `php durianbikers-chatbot/tests/unit/test-pii.php` → pass; full unit suite green.
- [ ] **Step 5: Commit** — `feat(chatbot): scrub email/phone from history to Claude; card scrub unchanged (Phase 3)`.

---

## Task 6: Prompt — checkout flow guidance + hard wall

**Files:**
- Modify: `durianbikers-chatbot/includes/class-claude-client.php` (`prompt_directives()`)
- Test: `durianbikers-chatbot/tests/unit/test-prompt.php` (extend needles)

**Add a CHECKOUT section** covering §6 steps and the hard wall. Content (verbatim intent):

```
CHECKOUT — YOU GUIDE THEM TO THE PAYMENT PAGE; YOU NEVER TAKE PAYMENT
- When the cart has items and the customer wants to check out, help them to the "Proceed to payment" button. You never take card or bank numbers, never choose or submit a payment method, and never place the order — payment happens only on the checkout page.
- Delivery details: you do NOT ask the customer to type their address to you and you never repeat personal details back. The system captures them — a saved-address tap for signed-in customers, a short form for guests — and writes them to their checkout session. If details are still missing, tell the customer you'll pop up a quick form / ask them to confirm their saved address; then call set_checkout_details to continue.
- Call get_checkout_summary to show the itemised summary, delivery address and total, and ask "all correct?". You only see counts and totals; the customer sees the address.
- On a clear yes, call get_checkout_link and the customer taps "Proceed to payment". Then STOP — do not describe the payment step further.
- International (/gb): before promising delivery abroad, call check_shipping_country. If we do not ship there, say so and offer a human. Never promise a delivery you cannot confirm.
- If a customer pastes card or bank numbers, do not repeat them; tell them to enter payment only on the secure checkout page.
```

- [ ] **Step 1:** Add needles to `test-prompt.php`: `never take card`, `Proceed to payment`, `get_checkout_summary`, `check_shipping_country`, `enter payment only on`. Run → fails (needles absent).
- [ ] **Step 2–3:** Add the CHECKOUT block; run tests.
- [ ] **Step 4:** `test-prompt.php` green; directive block still substantial.
- [ ] **Step 5: Commit** — `feat(chatbot): checkout prompt guidance + hard wall (Phase 3)`.

---

## Task 7: Widget — hybrid capture, summary card, "Proceed to payment"

**Files:**
- Modify: `durianbikers-chatbot/assets/chatbot.js`
- (No new file; reuse `openContactStep`/`submitHandoff`/`el()` patterns.)

**Behaviour, driven by response keys:**
- Response `checkout.needs === 'address'` with `checkout.mode === 'guest'` → render an inline checkout form (name, phone, email, address_1, city, postcode, country) reusing the handoff-form markup; on submit POST `{checkout_details:{...}}` through the existing `send`/`postChat` path (like `cart_confirm`), NOT as free text.
- `checkout.mode === 'logged_in'` → render a tap-confirm card: "Deliver to <checkout.address_line>?" with ✓ (POST `{checkout_confirm_address:true}`) / ✗ (ask to edit on the account page).
- Response `checkout.summary` → render the itemised summary card (items, shipping, total, `address_line`) with an "all correct?" affordance.
- Response `checkout_link.url` → render a **"Proceed to payment"** button that opens `url` in the same tab (the WooCommerce checkout page). This is the terminal action; no auto-advance.
- All rendered PII (address line, form values) uses `textContent`/`el()` — never `innerHTML` — and is never persisted to sessionStorage (same rule as the Phase 2 proposal/cart).

**Wiring:** extend `postChat`/`send`/`lastAttemptExtra` to forward `checkout_details` and `checkout_confirm_address` (mirroring the `cart_confirm` change from Phase 2).

- [ ] **Step 1:** `node --check durianbikers-chatbot/assets/chatbot.js` baseline.
- [ ] **Step 2–4:** Implement the four render paths + the `send`/`postChat` forwarding; `node --check` clean; confirm no PII written to `saveState`/`restoreState` (grep the diff).
- [ ] **Step 5: Commit** — `feat(chatbot): widget checkout — hybrid capture, summary, Proceed to payment (Phase 3)`.

---

## Task 8: Checkout hard-checks (local, pure) + no-store on checkout turns

**Files:**
- Modify: `durianbikers-chatbot/tests/run-hard.php`
- Modify: `durianbikers-chatbot/includes/class-chat-endpoint.php` (mark checkout turns no-store; already `prevent_caching` per request — assert the checkout path keeps it)

**Hard-checks (pure, run before every deploy):**
- `DB_Chat_Checkout_Writer::sanitize_details` strips tags / trims / drops unknown keys (reuse Task 1 cases).
- `set_details` result contains no PII value (assert over a corpus of value-shaped inputs).
- `DB_Chat_PII::scrub` redacts email/phone and does NOT redact price/size strings (reuse Task 5 corpus).
- Hard-wall predicate: assert the checkout tool result maps for `get_checkout_summary`/`set_checkout_details` never contain an `order_id`/`payment`/`paid` key (structural guard against a future edit leaking one).

- [ ] **Step 1–4:** Add the block; `php durianbikers-chatbot/tests/run-hard.php` all green (Phase 1+2+3 checks).
- [ ] **Step 5: Commit** — `test(chatbot): checkout hard-checks + no-store assertion (Phase 3)`.

---

## Task 9: Integration deploy + full-flow browser verification (CONTROLLER)

**Files:** deploy all Phase-3 files; controller-only.

- [ ] **Step 1 (controller): Deploy** each Phase-3 file (new class first, then endpoint/client/pii/messages/js/main) via ssh-stdin with `.bak-<stamp>`, md5 verify, server `php -l`, boot-check (all new classes + methods load; `db_chat_mode=ai`, `handoff=1`). Purge LiteSpeed. Pre-flight health: `/my/`, `/gb/`, `/my/shop/`, checkout URL all HTTP 200.
- [ ] **Step 2 (controller): Server smokes** (`wp eval-file`, admin-cart snapshot/restore around them):
  - checkout-writer round-trip: `set_details` → `WC()->customer` getters return the values; `summary()` shows an address_line + MYR total; **assert `wc_get_orders()` count is unchanged** (no order created).
  - `shipping_country_served('MY')` true; an unserved country false; `('GB'/international)` per the real zones.
- [ ] **Step 3 (controller): Browser full flow** on the staff test page (guest):
  1. Add an item (Phase 2 add flow) → cart has 1.
  2. "I want to check out" → bot runs the flow; guest form appears → fill test details → submit.
  3. `get_checkout_summary` → summary card with items + address + total; "all correct?" → yes.
  4. "Proceed to payment" button appears → tap → **WooCommerce checkout page opens with the session pre-filled** (verify the billing fields are populated). **Do NOT enter payment; do NOT place the order.**
  5. **Assert PII never reached Claude:** inspect the network `/chat` requests — the `history`/`message` sent up carry the neutral marker, not the typed address/email/phone.
  6. **Assert no order created:** `wp eval` `wc_get_orders()` count unchanged before/after.
  - Repeat the logged-in tap-confirm path if a staff test login is available; otherwise note it as covered by code + the guest path and defer.
  - **/gb:** ask about delivery to an unserved country → bot declines + offers a human.
- [ ] **Step 4 (§15 risk): Guest session-prefill check.** If the checkout page does NOT show the guest's chat-entered details (session-prefill unreliable), fall back per §6: keep logged-in tap-confirm, and for guests show a "we'll fill what we can — please confirm on the checkout page" note. Record the outcome in the ledger; if the fallback is needed, adjust the widget copy (small follow-up).
- [ ] **Step 5: Commit** — `test(chatbot): Phase 3 checkout integration + full-flow verification (Phase 3)`. Restore ssh gate.

---

## Test suite growth (§10)

Add to the graded/hard suites: a full add→checkout→summary→proceed conversation; a guest-form path; a `/gb` unserved-country decline; a "customer pastes a card number" turn (assert scrubbed + not repeated + directed to the checkout page); a "place the order for me" turn (assert the bot explains it can't and points to the payment page).

## Self-review notes (author)

- **Spec coverage:** §5.3 hard wall → Global Constraints + Task 8 predicate + Task 9 no-order assertion. §6 steps 1–5 → Tasks 1–4, 6, 7, 9. §7 PII → Tasks 1 (booleans-only), 4 (deterministic capture + neutral marker), 5 (history scrub), 7 (widget-rendered address, no sessionStorage). §6 /gb → Tasks 2, 6, 9. "Proceed to payment" terminal → Tasks 3 (`get_checkout_link`), 7 (button).
- **Open risk (§15):** guest session-prefill — verified in Task 9 Step 4 with the spec's fallback.
- **Type consistency:** `summary()` shape is produced in Task 2 and consumed in Tasks 3/7; `set_details` return `{ok,fields}` in Task 1 consumed in Task 3; response keys `checkout`/`checkout_link` set in Task 3 consumed in Task 7.
