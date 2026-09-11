# Chatbot Add-to-Cart Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a customer buy from the chat — state what they want, be asked for anything missing, confirm a summary, and have the item added to the same cart the website shows.

**Architecture:** The free lookup engine gains a small conversation state machine. A `pending` order object travels in the request (like conversation history already does) rather than living on the server; nothing it carries is trusted, because product, variation, stock, quantity and price are all re-read from WooCommerce at the moment of adding. A new `DB_Chat_Cart_Writer` owns every cart write and every validation.

**Tech Stack:** PHP 8.3, WordPress 7.1, WooCommerce 10.9.4, plain JS (no build step). Tests are PHP harnesses run through `wp eval-file` over SSH against the live catalogue.

**Spec:** `docs/superpowers/specs/2026-09-11-chatbot-add-to-cart-design.md`

## Global Constraints

- Prefix everything `db_chat_` / `DB_Chat_`. Text domain `durianbikers-chatbot`.
- PHP 8.0 floor; lint every changed file with `/opt/homebrew/opt/php@8.3/bin/php -l` (8.3.33 = the server's exact version). Bare `php` on this Mac is 8.5.9 and proves nothing.
- **No test file may be placed in the plugin directory.** Deployment copies `includes/`, so harnesses live in the session scratchpad only.
- **Never trust the browser at add time.** Product, variation, stock, quantity and price are re-read from WooCommerce.
- **Never assume a variation.** A single in-stock variation is *stated in the confirmation*, never silently chosen.
- Live store, real payments. Chat is staff-gated on the private `chatbot-test` page (id 59679). Any cart line created by a test is removed by that same test.
- Deploy one file at a time: `ssh -i ~/.ssh/durianbikers_vps -p 8288 durianbi@103.233.1.74 "cat > <remote path>" < <local path>`, then verify `md5` matches both ends and lint remotely.
- WP-CLI on that host needs `-d memory_limit=512M` or it dies in litespeed-cache files.
- Remote WP root: `/var/www/vhosts/durianbikers.com/staging.durianbikers.com`

Shell shorthand used throughout:

```bash
SSH="ssh -i $HOME/.ssh/durianbikers_vps -p 8288 -o BatchMode=yes durianbi@103.233.1.74"
WPROOT=/var/www/vhosts/durianbikers.com/staging.durianbikers.com
WP="/opt/plesk/php/8.3/bin/php -d memory_limit=512M /usr/local/bin/wp --path=$WPROOT"
PLUG=$WPROOT/wp-content/plugins/durianbikers-chatbot
SCRATCH=/private/tmp/claude-501/-Users-superfly2026-sites-durianbikers/33a62eb6-8bc3-4efe-bd0a-a5fb95e120e4/scratchpad
```

---

## File Structure

| File | Responsibility |
|---|---|
| `includes/class-cart-writer.php` (create) | The only thing that validates and writes to the cart |
| `includes/class-pending-order.php` (create) | The half-finished order: shape, parsing, expiry |
| `includes/class-lookup-engine.php` (modify) | Vocabulary, answer-matching, flow, reply copy |
| `includes/class-chat-endpoint.php` (modify) | Carries `pending` in and out; loads the cart session |
| `assets/chatbot.js` (modify) | Echoes `pending` back; refreshes cart fragments |
| `durianbikers-chatbot.php` (modify) | Requires the two new classes |

Task order is deliberate: the riskiest unknown (does the cart work inside our REST route?) is proved in Task 1, before any conversation code is built on top of it.

---

### Task 1: Cart writer — prove the cart works, and own every validation

**Files:**
- Create: `durianbikers-chatbot/includes/class-cart-writer.php`
- Modify: `durianbikers-chatbot/durianbikers-chatbot.php` (require the new file)
- Test: `$SCRATCH/t1-cart-writer.php` (scratchpad only — never in the plugin)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `DB_Chat_Cart_Writer::add( int $product_id, int $variation_id, int $quantity ): array`
    returns `array( 'ok' => bool, 'code' => string, 'message' => string, 'cart_url' => string, 'cart_total' => string, 'item_count' => int )`
  - `DB_Chat_Cart_Writer::describe( int $product_id, int $variation_id ): array`
    returns `array( 'name' => string, 'label' => string, 'price' => string, 'url' => string )` — used by Task 6 to build the confirmation summary.
  - `DB_Chat_Cart_Writer::in_stock_variations( int $product_id ): array`
    returns a list of `array( 'variation_id' => int, 'label' => string, 'price' => string )`, in-stock only — used by Task 5.

- [ ] **Step 1: Write the failing test**

Create `$SCRATCH/t1-cart-writer.php`:

```php
<?php
// Run with: wp eval-file. Adds a real line to a real cart, then empties it.
$product_id = 58024; // MT Helmet THUNDER 4 SV (YAMANAKA 2025 A5 GLOSS)
$pass = 0; $fail = 0;
function ck( $label, $ok, &$pass, &$fail ) {
	printf( "%s %s\n", $ok ? 'PASS' : 'FAIL', $label );
	$ok ? $pass++ : $fail++;
}

$writer = new DB_Chat_Cart_Writer();

// 1. in_stock_variations returns only in-stock sizes
$sizes = $writer->in_stock_variations( $product_id );
ck( 'in_stock_variations returns at least one size', ! empty( $sizes ) , $pass, $fail );
$all_in_stock = true;
foreach ( $sizes as $s ) {
	$v = wc_get_product( $s['variation_id'] );
	if ( ! $v || ! $v->is_in_stock() ) { $all_in_stock = false; }
}
ck( 'every returned size is actually in stock', $all_in_stock, $pass, $fail );

$variation_id = (int) $sizes[0]['variation_id'];

// 2. rejects a variation that belongs to a different product
$other = $writer->in_stock_variations( 58402 );
$foreign = ! empty( $other ) ? (int) $other[0]['variation_id'] : 0;
$r = $writer->add( $product_id, $foreign, 1 );
ck( 'rejects a variation from another product', false === $r['ok'], $pass, $fail );

// 3. rejects a nonsense quantity
$r = $writer->add( $product_id, $variation_id, 0 );
ck( 'rejects quantity 0', false === $r['ok'], $pass, $fail );

// 4. the happy path actually writes a cart line
$before = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
$r = $writer->add( $product_id, $variation_id, 2 );
ck( 'add() reports ok', true === $r['ok'], $pass, $fail );
ck( 'cart gained 2 items', WC()->cart->get_cart_contents_count() === $before + 2, $pass, $fail );
ck( 'cart_url returned', '' !== $r['cart_url'], $pass, $fail );

// 5. describe() gives the confirmation wording
$d = $writer->describe( $product_id, $variation_id );
ck( 'describe returns a product name', '' !== $d['name'], $pass, $fail );
ck( 'describe returns a variation label', '' !== $d['label'], $pass, $fail );

// CLEAN UP - this is a live store.
WC()->cart->empty_cart();
ck( 'cart emptied after test', 0 === WC()->cart->get_cart_contents_count(), $pass, $fail );

echo "\n==== $pass passed, $fail failed ====\n";
```

- [ ] **Step 2: Run it to verify it fails**

```bash
$SSH "cat > ~/claude-uploads/t1-cart-writer.php" < $SCRATCH/t1-cart-writer.php
$SSH "$WP eval-file ~/claude-uploads/t1-cart-writer.php"
```

Expected: fatal, `Class "DB_Chat_Cart_Writer" not found`.

- [ ] **Step 3: Write the implementation**

Create `durianbikers-chatbot/includes/class-cart-writer.php`:

```php
<?php
/**
 * The only place the chatbot writes to a cart.
 *
 * Everything the browser sent is re-read from WooCommerce here. A pending
 * order arrives from the customer's own browser, so its product id, variation
 * id and quantity are claims, not facts.
 *
 * @package DurianBikers_Chatbot
 */

defined( 'ABSPATH' ) || exit;

class DB_Chat_Cart_Writer {

	/** Never add more than this in one go, whatever was asked for. */
	const MAX_QUANTITY = 20;

	/**
	 * In-stock variations of a variable product.
	 *
	 * Out-of-stock sizes are never offered to a customer — they pick from what
	 * they can actually have.
	 *
	 * @param int $product_id Parent product.
	 * @return array<int,array<string,mixed>>
	 */
	public function in_stock_variations( $product_id ) {
		$product = wc_get_product( (int) $product_id );

		if ( ! $product instanceof WC_Product_Variable ) {
			return array();
		}

		$out = array();

		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );

			if ( ! $variation instanceof WC_Product_Variation || ! $variation->is_in_stock() ) {
				continue;
			}

			$label = trim( (string) $variation->get_attribute_summary() );
			$label = '' !== $label ? preg_replace( '/^[^:]{1,20}:\s*/u', '', $label ) : $variation->get_name();

			$out[] = array(
				'variation_id' => (int) $variation->get_id(),
				'label'        => (string) $label,
				'price'        => wp_strip_all_tags( (string) $variation->get_price_html() ),
			);
		}

		return $out;
	}

	/**
	 * Human wording for the confirmation summary.
	 *
	 * @param int $product_id   Parent product.
	 * @param int $variation_id Chosen variation, 0 for a simple product.
	 * @return array<string,string>
	 */
	public function describe( $product_id, $variation_id ) {
		$product = wc_get_product( (int) $product_id );

		if ( ! $product instanceof WC_Product ) {
			return array(
				'name'  => '',
				'label' => '',
				'price' => '',
				'url'   => '',
			);
		}

		$label = '';
		$price = wp_strip_all_tags( (string) $product->get_price_html() );

		if ( $variation_id > 0 ) {
			$variation = wc_get_product( (int) $variation_id );

			if ( $variation instanceof WC_Product_Variation ) {
				$label = trim( (string) $variation->get_attribute_summary() );
				$label = preg_replace( '/^[^:]{1,20}:\s*/u', '', $label );
				$price = wp_strip_all_tags( (string) $variation->get_price_html() );
			}
		}

		return array(
			'name'  => wp_strip_all_tags( (string) $product->get_name() ),
			'label' => (string) $label,
			'price' => (string) $price,
			'url'   => (string) $product->get_permalink(),
		);
	}

	/**
	 * Validate and add. Returns a result array, never throws.
	 *
	 * @param int $product_id   Parent product.
	 * @param int $variation_id Variation, 0 for simple products.
	 * @param int $quantity     How many.
	 * @return array<string,mixed>
	 */
	public function add( $product_id, $variation_id, $quantity ) {
		$product_id   = (int) $product_id;
		$variation_id = (int) $variation_id;
		$quantity     = (int) $quantity;

		$check = $this->validate( $product_id, $variation_id, $quantity );

		if ( is_wp_error( $check ) ) {
			return $this->failure( $check->get_error_code(), $check->get_error_message() );
		}

		if ( ! $this->load_cart() ) {
			return $this->failure( 'db_chat_no_cart', 'The cart is not available right now.' );
		}

		try {
			$added = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id );
		} catch ( Throwable $e ) {
			DB_Chat_Plugin::log_error( 'Cart add failed: ' . $e->getMessage() );

			return $this->failure( 'db_chat_cart_failed', 'I could not add that to your cart.' );
		}

		if ( ! $added ) {
			return $this->failure( 'db_chat_cart_refused', 'WooCommerce would not accept that item.' );
		}

		return array(
			'ok'         => true,
			'code'       => '',
			'message'    => '',
			'cart_url'   => (string) wc_get_cart_url(),
			'cart_total' => wp_strip_all_tags( (string) WC()->cart->get_cart_total() ),
			'item_count' => (int) WC()->cart->get_cart_contents_count(),
		);
	}

	/**
	 * Every rule, applied to freshly read WooCommerce data.
	 *
	 * @param int $product_id   Parent product.
	 * @param int $variation_id Variation, 0 for simple.
	 * @param int $quantity     How many.
	 * @return true|WP_Error
	 */
	private function validate( $product_id, $variation_id, $quantity ) {
		if ( $quantity < 1 || $quantity > self::MAX_QUANTITY ) {
			return new WP_Error( 'db_chat_bad_quantity', 'That quantity is not something I can add.' );
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product || 'publish' !== $product->get_status() ) {
			return new WP_Error( 'db_chat_no_product', 'I cannot find that product any more.' );
		}

		if ( ! $product->is_purchasable() ) {
			return new WP_Error( 'db_chat_not_purchasable', 'That product cannot be bought online.' );
		}

		if ( $product instanceof WC_Product_Variable ) {
			if ( $variation_id < 1 ) {
				return new WP_Error( 'db_chat_no_variation', 'I still need to know which option you want.' );
			}

			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof WC_Product_Variation ) {
				return new WP_Error( 'db_chat_no_variation', 'That option does not exist.' );
			}

			// The variation must belong to THIS product, not merely exist.
			if ( (int) $variation->get_parent_id() !== $product_id ) {
				return new WP_Error( 'db_chat_wrong_variation', 'That option does not belong to that product.' );
			}

			if ( ! $variation->is_in_stock() ) {
				return new WP_Error( 'db_chat_out_of_stock', 'That option is out of stock.' );
			}

			if ( ! $variation->has_enough_stock( $quantity ) ) {
				return new WP_Error( 'db_chat_not_enough_stock', 'We do not have that many in stock.' );
			}

			return true;
		}

		if ( ! $product->is_in_stock() || ! $product->has_enough_stock( $quantity ) ) {
			return new WP_Error( 'db_chat_out_of_stock', 'That is out of stock.' );
		}

		return true;
	}

	/**
	 * Make sure a cart exists in this request.
	 *
	 * REST requests do not boot the cart the way a page load does.
	 *
	 * @return bool
	 */
	private function load_cart() {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}

		if ( null === WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		return null !== WC()->cart;
	}

	/**
	 * Uniform failure shape.
	 *
	 * @param string $code    Error code.
	 * @param string $message Customer-facing text.
	 * @return array<string,mixed>
	 */
	private function failure( $code, $message ) {
		return array(
			'ok'         => false,
			'code'       => (string) $code,
			'message'    => (string) $message,
			'cart_url'   => '',
			'cart_total' => '',
			'item_count' => 0,
		);
	}
}
```

In `durianbikers-chatbot.php`, next to the other `require_once` calls for `includes/`, add:

```php
require_once DB_CHAT_PATH . 'includes/class-cart-writer.php';
```

- [ ] **Step 4: Lint, deploy, run the test**

```bash
/opt/homebrew/opt/php@8.3/bin/php -l durianbikers-chatbot/includes/class-cart-writer.php
/opt/homebrew/opt/php@8.3/bin/php -l durianbikers-chatbot/durianbikers-chatbot.php
$SSH "cat > $PLUG/includes/class-cart-writer.php" < durianbikers-chatbot/includes/class-cart-writer.php
$SSH "cat > $PLUG/durianbikers-chatbot.php" < durianbikers-chatbot/durianbikers-chatbot.php
$SSH "$WP eval-file ~/claude-uploads/t1-cart-writer.php"
```

Expected: `10 passed, 0 failed`, ending with the cart emptied.

**If `wc_load_cart()` does not produce a cart here, stop and report it before continuing** — the whole feature rests on this and the rest of the plan assumes it works.

- [ ] **Step 5: Commit**

```bash
git add durianbikers-chatbot/includes/class-cart-writer.php durianbikers-chatbot/durianbikers-chatbot.php
git commit -m "feat: add cart writer with full server-side revalidation"
```

---

### Task 2: Purchase vocabulary — fix "i want", add confirm/decline words

**Files:**
- Modify: `durianbikers-chatbot/includes/class-lookup-engine.php`
- Test: `$SCRATCH/t2-vocabulary.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `DB_Chat_Lookup_Engine::purchase_soft_terms(): string[]` — words that mean buying *only when a product was actually found*.
  - `DB_Chat_Lookup_Engine::confirm_terms(): string[]`, `DB_Chat_Lookup_Engine::decline_terms(): string[]`
  - `$filters['purchase_soft']` (bool) set by `build_query()`.

**Why soft terms exist:** `want` and `nak` are stopwords because they appear in every kind of question ("nak tanya", "I want to know your delivery time"). Promoting them to plain purchase terms would put a buying note on half the conversations. A soft term only counts as buying intent when the same message also produced a product match.

- [ ] **Step 1: Write the failing test**

Create `$SCRATCH/t2-vocabulary.php`:

```php
<?php
$e = new DB_Chat_Lookup_Engine( new DB_Chat_Product_Index() );
$pass = 0; $fail = 0;
function ck( $l, $ok, &$p, &$f ) { printf( "%s %s\n", $ok ? 'PASS' : 'FAIL', $l ); $ok ? $p++ : $f++; }

$buying = function ( $msg ) use ( $e ) {
	$r = $e->answer( $msg );
	$reply = (string) ( $r['reply'] ?? '' );
	return ( false !== stripos( $reply, 'cannot place an order' ) || false !== stripos( $reply, 'tidak boleh membuat pesanan' ) );
};

ck( '"i want yamanaka for 2pcs" reads as buying', $buying( 'i want yamanaka for 2pcs' ), $pass, $fail );
ck( '"nak yamanaka 2 pcs" reads as buying',      $buying( 'nak yamanaka 2 pcs' ), $pass, $fail );
ck( '"saya mahu beli helmet" reads as buying',   $buying( 'saya mahu beli helmet' ), $pass, $fail );

// Soft terms must NOT fire when no product was found.
ck( '"i want to know your delivery time" is NOT buying', ! $buying( 'i want to know your delivery time' ), $pass, $fail );
ck( '"nak tanya pasal penghantaran" is NOT buying',      ! $buying( 'nak tanya pasal penghantaran' ), $pass, $fail );

// Confirmation vocabulary exists and is separate.
$c = DB_Chat_Lookup_Engine::confirm_terms();
$d = DB_Chat_Lookup_Engine::decline_terms();
ck( 'confirm terms include yes and ya', in_array( 'yes', $c, true ) && in_array( 'ya', $c, true ), $pass, $fail );
ck( 'decline terms include no and tidak', in_array( 'no', $d, true ) && in_array( 'tidak', $d, true ), $pass, $fail );
ck( 'confirm and decline do not overlap', array() === array_intersect( $c, $d ), $pass, $fail );

echo "\n==== $pass passed, $fail failed ====\n";
```

- [ ] **Step 2: Run it to verify it fails**

```bash
$SSH "cat > ~/claude-uploads/t2-vocabulary.php" < $SCRATCH/t2-vocabulary.php
$SSH "$WP eval-file ~/claude-uploads/t2-vocabulary.php"
```

Expected: the first three assertions FAIL, and `confirm_terms()` is an unknown method (fatal). Fix the fatal first by adding the methods, then re-run to see the behavioural failures.

- [ ] **Step 3: Write the implementation**

In `class-lookup-engine.php`, beside the existing `purchase_terms()`:

```php
	/**
	 * Words that mean buying ONLY when a product was actually found.
	 *
	 * "want" and "nak" are stopwords for good reason — "I want to know your
	 * delivery time", "nak tanya pasal penghantaran". Promoting them to plain
	 * purchase terms would stamp a buying note on half of all conversations.
	 * They count only when the same message also matched a product, which is
	 * what separates "i want yamanaka" from "i want to know".
	 *
	 * @return string[]
	 */
	public static function purchase_soft_terms() {
		$terms = array( 'want', 'wants', 'need', 'get', 'take', 'nak', 'mahu', 'hendak', 'ambil', 'amik' );

		/**
		 * Filter the weak purchase words.
		 *
		 * @param string[] $terms Lowercase words.
		 */
		return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'db_chat_purchase_soft_terms', $terms ) ) ) );
	}

	/**
	 * Words that accept the confirmation summary.
	 *
	 * @return string[]
	 */
	public static function confirm_terms() {
		$terms = array( 'yes', 'yeah', 'yep', 'ok', 'okay', 'confirm', 'confirmed', 'sure', 'go ahead', 'add',
			'ya', 'yer', 'betul', 'boleh', 'setuju', 'sah', 'teruskan' );

		/**
		 * Filter the confirmation words.
		 *
		 * @param string[] $terms Lowercase words.
		 */
		return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'db_chat_confirm_terms', $terms ) ) ) );
	}

	/**
	 * Words that reject the confirmation summary.
	 *
	 * @return string[]
	 */
	public static function decline_terms() {
		$terms = array( 'no', 'nope', 'cancel', 'stop', 'nevermind', 'never mind', 'dont', 'do not',
			'tidak', 'tak', 'jangan', 'batal', 'tak jadi' );

		/**
		 * Filter the decline words.
		 *
		 * @param string[] $terms Lowercase words.
		 */
		return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'db_chat_decline_terms', $terms ) ) ) );
	}
```

In `blank_filters()` add `'purchase_soft' => false,`.

In `build_query()`, directly after the existing purchase-terms loop:

```php
		/*
		 * SOFT PURCHASE. Flagged but NOT stripped — "want" and "nak" are
		 * already stopwords, and removing them twice would be harmless but
		 * confusing. Whether this counts as buying is decided later, once we
		 * know whether the search actually found a product.
		 */
		if ( ! $filters['purchase'] ) {
			foreach ( self::purchase_soft_terms() as $term ) {
				if ( false !== strpos( $text, ' ' . $term . ' ' ) ) {
					$filters['purchase_soft'] = true;
					break;
				}
			}
		}
```

In the notes block (where `note_purchase` is appended), change the condition so a soft term counts only with results:

```php
		$is_purchase = $filters['purchase'] || ( $filters['purchase_soft'] && ! empty( $records ) );

		if ( $is_purchase ) {
			$notes[] = $filters['quantity'] > 0
				? $this->text( $language, 'note_purchase_qty', $filters['quantity'] )
				: $this->text( $language, 'note_purchase' );
		}
```

- [ ] **Step 4: Lint, deploy, run the test**

```bash
/opt/homebrew/opt/php@8.3/bin/php -l durianbikers-chatbot/includes/class-lookup-engine.php
$SSH "cat > $PLUG/includes/class-lookup-engine.php" < durianbikers-chatbot/includes/class-lookup-engine.php
$SSH "$WP eval-file ~/claude-uploads/t2-vocabulary.php"
```

Expected: `8 passed, 0 failed`.

- [ ] **Step 5: Re-run the earlier regression suites**

```bash
$SSH "$WP eval-file ~/claude-uploads/purchase-test.php"
$SSH "$WP eval-file ~/claude-uploads/rank-test.php"
```

Expected: still `30 passed, 0 failed` and `4 passed, 0 failed`. If "i want to know your delivery time" now shows a buying note, the soft-term gate is wrong — fix before committing.

- [ ] **Step 6: Commit**

```bash
git add durianbikers-chatbot/includes/class-lookup-engine.php
git commit -m "feat: recognise 'i want'/'nak' as buying only when a product matched"
```

---

### Task 3: The pending order object

**Files:**
- Create: `durianbikers-chatbot/includes/class-pending-order.php`
- Modify: `durianbikers-chatbot/durianbikers-chatbot.php` (require it)
- Test: `$SCRATCH/t3-pending.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `DB_Chat_Pending_Order::STAGE_PRODUCT` = `'ask_product'`, `::STAGE_VARIATION` = `'ask_variation'`, `::STAGE_CONFIRM` = `'ask_confirm'`
  - `DB_Chat_Pending_Order::from_request( $raw ): ?DB_Chat_Pending_Order`
  - `->to_array(): array`, `->is_expired(): bool`
  - public readable state via `->stage`, `->product_id`, `->variation_id`, `->quantity`, `->offered`

- [ ] **Step 1: Write the failing test**

Create `$SCRATCH/t3-pending.php`:

```php
<?php
$pass = 0; $fail = 0;
function ck( $l, $ok, &$p, &$f ) { printf( "%s %s\n", $ok ? 'PASS' : 'FAIL', $l ); $ok ? $p++ : $f++; }

ck( 'garbage returns null', null === DB_Chat_Pending_Order::from_request( 'not an array' ), $pass, $fail );
ck( 'empty array returns null', null === DB_Chat_Pending_Order::from_request( array() ), $pass, $fail );
ck( 'unknown stage returns null', null === DB_Chat_Pending_Order::from_request( array( 'stage' => 'nonsense', 'product_id' => 1, 'created' => time() ) ), $pass, $fail );

$good = array(
	'stage'        => DB_Chat_Pending_Order::STAGE_VARIATION,
	'product_id'   => 58024,
	'variation_id' => 0,
	'quantity'     => 2,
	'offered'      => array( 101, 102 ),
	'created'      => time(),
);
$p = DB_Chat_Pending_Order::from_request( $good );
ck( 'valid payload parses', $p instanceof DB_Chat_Pending_Order, $pass, $fail );
ck( 'quantity survives', 2 === $p->quantity, $pass, $fail );
ck( 'not expired when fresh', false === $p->is_expired(), $pass, $fail );
ck( 'round-trips through to_array', 58024 === (int) $p->to_array()['product_id'], $pass, $fail );

$old = $good;
$old['created'] = time() - 4000;
ck( 'expires after the TTL', DB_Chat_Pending_Order::from_request( $old )->is_expired(), $pass, $fail );

$neg = $good;
$neg['quantity'] = -5;
ck( 'negative quantity clamps to 1', 1 === DB_Chat_Pending_Order::from_request( $neg )->quantity, $pass, $fail );

$huge = $good;
$huge['quantity'] = 9999;
ck( 'absurd quantity is clamped', DB_Chat_Pending_Order::from_request( $huge )->quantity <= 20, $pass, $fail );

echo "\n==== $pass passed, $fail failed ====\n";
```

- [ ] **Step 2: Run it to verify it fails**

```bash
$SSH "cat > ~/claude-uploads/t3-pending.php" < $SCRATCH/t3-pending.php
$SSH "$WP eval-file ~/claude-uploads/t3-pending.php"
```

Expected: fatal, `Class "DB_Chat_Pending_Order" not found`.

- [ ] **Step 3: Write the implementation**

Create `durianbikers-chatbot/includes/class-pending-order.php`:

```php
<?php
/**
 * A half-finished order, carried between messages.
 *
 * This arrives from the customer's browser, so every field is a claim. It is
 * sanitised here and verified again against WooCommerce by
 * DB_Chat_Cart_Writer before anything is added. Nothing in this class may
 * assume the values are honest.
 *
 * @package DurianBikers_Chatbot
 */

defined( 'ABSPATH' ) || exit;

class DB_Chat_Pending_Order {

	const STAGE_PRODUCT   = 'ask_product';
	const STAGE_VARIATION = 'ask_variation';
	const STAGE_CONFIRM   = 'ask_confirm';

	/** Seconds a half-finished order stays alive. */
	const TTL = 900;

	/** Hard ceiling, mirroring DB_Chat_Cart_Writer::MAX_QUANTITY. */
	const MAX_QUANTITY = 20;

	/** @var string */
	public $stage = '';

	/** @var int */
	public $product_id = 0;

	/** @var int */
	public $variation_id = 0;

	/** @var int */
	public $quantity = 1;

	/** @var array<int,mixed> Ids or labels the bot actually offered. */
	public $offered = array();

	/** @var int */
	public $created = 0;

	/**
	 * Parse whatever the browser sent.
	 *
	 * @param mixed $raw Untrusted input.
	 * @return DB_Chat_Pending_Order|null
	 */
	public static function from_request( $raw ) {
		if ( ! is_array( $raw ) || empty( $raw['stage'] ) ) {
			return null;
		}

		$stage = (string) $raw['stage'];

		if ( ! in_array( $stage, array( self::STAGE_PRODUCT, self::STAGE_VARIATION, self::STAGE_CONFIRM ), true ) ) {
			return null;
		}

		$order               = new self();
		$order->stage        = $stage;
		$order->product_id   = isset( $raw['product_id'] ) ? absint( $raw['product_id'] ) : 0;
		$order->variation_id = isset( $raw['variation_id'] ) ? absint( $raw['variation_id'] ) : 0;
		$order->quantity     = isset( $raw['quantity'] ) ? absint( $raw['quantity'] ) : 1;
		$order->created      = isset( $raw['created'] ) ? absint( $raw['created'] ) : 0;

		if ( $order->quantity < 1 ) {
			$order->quantity = 1;
		}

		if ( $order->quantity > self::MAX_QUANTITY ) {
			$order->quantity = self::MAX_QUANTITY;
		}

		if ( isset( $raw['offered'] ) && is_array( $raw['offered'] ) ) {
			$order->offered = array_slice( array_values( $raw['offered'] ), 0, 12 );
		}

		return $order;
	}

	/**
	 * Fresh pending order.
	 *
	 * @param string $stage   One of the STAGE_ constants.
	 * @param array  $state   product_id / variation_id / quantity / offered.
	 * @return DB_Chat_Pending_Order
	 */
	public static function create( $stage, array $state ) {
		$order               = new self();
		$order->stage        = (string) $stage;
		$order->product_id   = isset( $state['product_id'] ) ? absint( $state['product_id'] ) : 0;
		$order->variation_id = isset( $state['variation_id'] ) ? absint( $state['variation_id'] ) : 0;
		$order->quantity     = isset( $state['quantity'] ) ? max( 1, absint( $state['quantity'] ) ) : 1;
		$order->offered      = isset( $state['offered'] ) && is_array( $state['offered'] ) ? array_values( $state['offered'] ) : array();
		$order->created      = time();

		return $order;
	}

	/**
	 * Too old to still be the same conversation?
	 *
	 * @return bool
	 */
	public function is_expired() {
		return $this->created < 1 || ( time() - $this->created ) > self::TTL;
	}

	/**
	 * Shape handed back to the browser.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		return array(
			'stage'        => $this->stage,
			'product_id'   => $this->product_id,
			'variation_id' => $this->variation_id,
			'quantity'     => $this->quantity,
			'offered'      => $this->offered,
			'created'      => $this->created,
		);
	}
}
```

Add to `durianbikers-chatbot.php`:

```php
require_once DB_CHAT_PATH . 'includes/class-pending-order.php';
```

- [ ] **Step 4: Lint, deploy, run**

```bash
/opt/homebrew/opt/php@8.3/bin/php -l durianbikers-chatbot/includes/class-pending-order.php
$SSH "cat > $PLUG/includes/class-pending-order.php" < durianbikers-chatbot/includes/class-pending-order.php
$SSH "cat > $PLUG/durianbikers-chatbot.php" < durianbikers-chatbot/durianbikers-chatbot.php
$SSH "$WP eval-file ~/claude-uploads/t3-pending.php"
```

Expected: `10 passed, 0 failed`.

- [ ] **Step 5: Commit**

```bash
git add durianbikers-chatbot/includes/class-pending-order.php durianbikers-chatbot/durianbikers-chatbot.php
git commit -m "feat: add pending-order value object for multi-turn buying"
```

---

### Task 4: Answer-matching — treat a reply as an answer, not a new search

**Files:**
- Modify: `durianbikers-chatbot/includes/class-lookup-engine.php`
- Test: `$SCRATCH/t4-answer-matching.php`

**Interfaces:**
- Consumes: `DB_Chat_Pending_Order` (Task 3), `DB_Chat_Cart_Writer::in_stock_variations()` (Task 1).
- Produces:
  - `DB_Chat_Lookup_Engine::match_offered_product( string $message, array $offered_ids ): int`
  - `DB_Chat_Lookup_Engine::match_offered_variation( string $message, array $offered ): int`

This is the fix for the defect in the spec §2: answering "which one?" with a full product title currently re-runs the search.

- [ ] **Step 1: Write the failing test**

Create `$SCRATCH/t4-answer-matching.php`:

```php
<?php
$e = new DB_Chat_Lookup_Engine( new DB_Chat_Product_Index() );
$pass = 0; $fail = 0;
function ck( $l, $ok, &$p, &$f ) { printf( "%s %s\n", $ok ? 'PASS' : 'FAIL', $l ); $ok ? $p++ : $f++; }

$offered = array( 58024, 58402 ); // the two Yamanakas

ck( 'full title picks the right product',
	58024 === $e->match_offered_product( 'MT Helmet THUNDER 4 SV (YAMANAKA 2025 A5 GLOSS)', $offered ), $pass, $fail );
ck( 'distinguishing fragment works',
	58402 === $e->match_offered_product( 'the 26 season one', $offered ), $pass, $fail );
ck( 'ordinal "the first one" picks the first offered',
	58024 === $e->match_offered_product( 'the first one', $offered ), $pass, $fail );
ck( 'unrelated message matches nothing',
	0 === $e->match_offered_product( 'do you ship to sabah', $offered ), $pass, $fail );

$sizes = array(
	array( 'variation_id' => 111, 'label' => 'L' ),
	array( 'variation_id' => 112, 'label' => 'XL' ),
);
ck( 'bare size letter matches', 111 === $e->match_offered_variation( 'L', $sizes ), $pass, $fail );
ck( 'size in a sentence matches', 112 === $e->match_offered_variation( 'i take XL please', $sizes ), $pass, $fail );
ck( 'XL is not matched by L',     112 === $e->match_offered_variation( 'xl', $sizes ), $pass, $fail );
ck( 'unoffered size matches nothing', 0 === $e->match_offered_variation( 'XXXL', $sizes ), $pass, $fail );

echo "\n==== $pass passed, $fail failed ====\n";
```

- [ ] **Step 2: Run it to verify it fails**

```bash
$SSH "cat > ~/claude-uploads/t4-answer-matching.php" < $SCRATCH/t4-answer-matching.php
$SSH "$WP eval-file ~/claude-uploads/t4-answer-matching.php"
```

Expected: fatal, `Call to undefined method ... match_offered_product()`.

- [ ] **Step 3: Write the implementation**

Add to `class-lookup-engine.php`:

```php
	/**
	 * Which of the products we just offered is the customer naming?
	 *
	 * Scored against the OFFERED products only. This is the difference between
	 * a conversation and four disconnected searches: when the bot has just
	 * asked "which one?", the answer must be read as an answer.
	 *
	 * @param string $message  Customer's reply.
	 * @param int[]  $offered  Product ids previously offered, in the order shown.
	 * @return int Product id, or 0 when nothing matched clearly.
	 */
	public function match_offered_product( $message, array $offered ) {
		$offered = array_values( array_filter( array_map( 'absint', $offered ) ) );

		if ( empty( $offered ) ) {
			return 0;
		}

		$normalized = $this->normalize( $message );

		// "the first one" / "the second one" / "yang pertama".
		$ordinals = array( 1 => array( 'first', 'pertama', '1st' ), 2 => array( 'second', 'kedua', '2nd' ), 3 => array( 'third', 'ketiga', '3rd' ) );

		foreach ( $ordinals as $nth => $words ) {
			foreach ( $words as $word ) {
				if ( false !== strpos( ' ' . $normalized . ' ', ' ' . $word . ' ' ) && isset( $offered[ $nth - 1 ] ) ) {
					return (int) $offered[ $nth - 1 ];
				}
			}
		}

		$message_tokens = $this->answer_tokens( $normalized );

		if ( empty( $message_tokens ) ) {
			return 0;
		}

		$best       = 0;
		$best_score = 0.0;
		$runner_up  = 0.0;

		foreach ( $offered as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$title_tokens = $this->answer_tokens( $this->normalize( $product->get_name() ) );

			if ( empty( $title_tokens ) ) {
				continue;
			}

			$matched = count( array_intersect( $message_tokens, $title_tokens ) );
			$score   = $matched / count( $message_tokens );

			if ( $score > $best_score ) {
				$runner_up  = $best_score;
				$best_score = $score;
				$best       = $product_id;
			} elseif ( $score > $runner_up ) {
				$runner_up = $score;
			}
		}

		/*
		 * Two guards. A weak best match means they probably changed the
		 * subject; a best that barely beats the runner-up means the answer did
		 * not actually distinguish between the options. Either way, do not
		 * guess — the caller asks again.
		 */
		if ( $best_score < 0.34 || ( $best_score - $runner_up ) < 0.10 ) {
			return 0;
		}

		return (int) $best;
	}

	/**
	 * Which offered variation is the customer naming?
	 *
	 * Matched whole-token so "L" never matches "XL".
	 *
	 * @param string $message Customer's reply.
	 * @param array  $offered Rows of variation_id + label, as produced by
	 *                        DB_Chat_Cart_Writer::in_stock_variations().
	 * @return int Variation id, or 0.
	 */
	public function match_offered_variation( $message, array $offered ) {
		$tokens = $this->answer_tokens( $this->normalize( $message ) );

		if ( empty( $tokens ) ) {
			return 0;
		}

		foreach ( $offered as $row ) {
			if ( ! isset( $row['variation_id'], $row['label'] ) ) {
				continue;
			}

			$label_tokens = $this->answer_tokens( $this->normalize( (string) $row['label'] ) );

			if ( empty( $label_tokens ) ) {
				continue;
			}

			// Every word of the label must be present, as a whole token.
			if ( count( array_intersect( $label_tokens, $tokens ) ) === count( $label_tokens ) ) {
				return (int) $row['variation_id'];
			}
		}

		return 0;
	}

	/**
	 * Tokens for answer matching.
	 *
	 * Unlike tokenise() in the product index, single characters are KEPT:
	 * "L", "S" and "M" are sizes, and dropping them would make it impossible
	 * to answer a size question with one letter.
	 *
	 * @param string $normalized Already normalised text.
	 * @return string[]
	 */
	private function answer_tokens( $normalized ) {
		$tokens = preg_split( '/[^\p{L}\p{N}]+/u', (string) $normalized, -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $tokens ) ? array_values( array_unique( $tokens ) ) : array();
	}
```

- [ ] **Step 4: Lint, deploy, run**

```bash
/opt/homebrew/opt/php@8.3/bin/php -l durianbikers-chatbot/includes/class-lookup-engine.php
$SSH "cat > $PLUG/includes/class-lookup-engine.php" < durianbikers-chatbot/includes/class-lookup-engine.php
$SSH "$WP eval-file ~/claude-uploads/t4-answer-matching.php"
```

Expected: `8 passed, 0 failed`.

- [ ] **Step 5: Commit**

```bash
git add durianbikers-chatbot/includes/class-lookup-engine.php
git commit -m "feat: match a reply against what the bot just offered"
```

---

### Task 5: The flow — ask product, ask size, ask to confirm

**Files:**
- Modify: `durianbikers-chatbot/includes/class-lookup-engine.php`
- Test: `$SCRATCH/t5-flow.php`

**Interfaces:**
- Consumes: Tasks 1, 3, 4.
- Produces: `DB_Chat_Lookup_Engine::answer( $message, array $history = array(), $pending = null )` — third parameter added, and the returned array gains a `pending` key (array or null).

- [ ] **Step 1: Write the failing test**

Create `$SCRATCH/t5-flow.php`:

```php
<?php
$e = new DB_Chat_Lookup_Engine( new DB_Chat_Product_Index() );
$pass = 0; $fail = 0;
function ck( $l, $ok, &$p, &$f ) { printf( "%s %s\n", $ok ? 'PASS' : 'FAIL', $l ); $ok ? $p++ : $f++; }

// Turn 1: ambiguous product, quantity given.
$r1 = $e->answer( 'i want yamanaka for 2pcs' );
ck( 'turn 1 returns a pending order', ! empty( $r1['pending'] ), $pass, $fail );
ck( 'turn 1 is asking which product', DB_Chat_Pending_Order::STAGE_PRODUCT === ( $r1['pending']['stage'] ?? '' ), $pass, $fail );
ck( 'turn 1 kept the quantity', 2 === ( $r1['pending']['quantity'] ?? 0 ), $pass, $fail );
ck( 'turn 1 offered both helmets', 2 === count( $r1['pending']['offered'] ?? array() ), $pass, $fail );

// Turn 2: customer answers with the full title.
$r2 = $e->answer( 'MT Helmet THUNDER 4 SV (YAMANAKA 2025 A5 GLOSS)', array(), $r1['pending'] );
ck( 'turn 2 moved on to asking the size', DB_Chat_Pending_Order::STAGE_VARIATION === ( $r2['pending']['stage'] ?? '' ), $pass, $fail );
ck( 'turn 2 resolved the product', 58024 === ( $r2['pending']['product_id'] ?? 0 ), $pass, $fail );
ck( 'turn 2 did NOT re-list products', false === stripos( (string) $r2['reply'], 'here is what i found' ), $pass, $fail );
ck( 'turn 2 offered at least one size', ! empty( $r2['pending']['offered'] ), $pass, $fail );

// Every offered size must be in stock.
$all_in = true;
foreach ( (array) $r2['pending']['offered'] as $row ) {
	$v = wc_get_product( (int) $row['variation_id'] );
	if ( ! $v || ! $v->is_in_stock() ) { $all_in = false; }
}
ck( 'only in-stock sizes were offered', $all_in, $pass, $fail );

// Turn 3: customer picks a size -> confirmation.
$size = (string) $r2['pending']['offered'][0]['label'];
$r3   = $e->answer( $size, array(), $r2['pending'] );
ck( 'turn 3 asks for confirmation', DB_Chat_Pending_Order::STAGE_CONFIRM === ( $r3['pending']['stage'] ?? '' ), $pass, $fail );
ck( 'turn 3 recorded the variation', ( $r3['pending']['variation_id'] ?? 0 ) > 0, $pass, $fail );
ck( 'turn 3 restates the quantity', false !== strpos( (string) $r3['reply'], '2' ), $pass, $fail );

// Off-topic mid-flow abandons the pending order.
$r4 = $e->answer( 'do you ship to sabah', array(), $r2['pending'] );
ck( 'off-topic abandons the pending order', empty( $r4['pending'] ), $pass, $fail );

// An expired pending order is ignored.
$stale = $r2['pending'];
$stale['created'] = time() - 4000;
$r5 = $e->answer( 'L', array(), $stale );
ck( 'expired pending order is dropped', empty( $r5['pending'] ) || DB_Chat_Pending_Order::STAGE_CONFIRM !== $r5['pending']['stage'], $pass, $fail );

echo "\n==== $pass passed, $fail failed ====\n";
```

- [ ] **Step 2: Run it to verify it fails**

```bash
$SSH "cat > ~/claude-uploads/t5-flow.php" < $SCRATCH/t5-flow.php
$SSH "$WP eval-file ~/claude-uploads/t5-flow.php"
```

Expected: the `pending` key is absent everywhere; most assertions FAIL.

- [ ] **Step 3: Add the reply copy**

In `templates()`, English block:

```php
			'buy_which_product'  => __( 'Which one would you like?', 'durianbikers-chatbot' ),
			'buy_which_size'     => __( 'Which size? Available now:', 'durianbikers-chatbot' ),
			'buy_only_size'      => __( 'That one is only available in %s right now.', 'durianbikers-chatbot' ),
			/* translators: 1: product name; 2: option label; 3: quantity; 4: price. */
			'buy_confirm'        => __( '%1$s — %2$s, %3$d of them, %4$s. Add that to your cart?', 'durianbikers-chatbot' ),
			/* translators: 1: product name; 2: quantity; 3: price. */
			'buy_confirm_simple' => __( '%1$s, %2$d of them, %3$s. Add that to your cart?', 'durianbikers-chatbot' ),
			'buy_cancelled'      => __( 'No problem, I have not added anything.', 'durianbikers-chatbot' ),
			'buy_unclear'        => __( 'Sorry, I did not catch which one you meant.', 'durianbikers-chatbot' ),
```

Malay block:

```php
			'buy_which_product'  => 'Yang mana satu anda mahu?',
			'buy_which_size'     => 'Saiz apa? Yang ada sekarang:',
			'buy_only_size'      => 'Yang itu hanya ada dalam saiz %s buat masa ini.',
			/* translators: 1: product name; 2: option label; 3: quantity; 4: price. */
			'buy_confirm'        => '%1$s — %2$s, %3$d unit, %4$s. Masukkan ke dalam troli?',
			/* translators: 1: product name; 2: quantity; 3: price. */
			'buy_confirm_simple' => '%1$s, %2$d unit, %3$s. Masukkan ke dalam troli?',
			'buy_cancelled'      => 'Baik, saya tidak masukkan apa-apa.',
			'buy_unclear'        => 'Maaf, saya tidak pasti yang mana satu anda maksudkan.',
```

- [ ] **Step 4: Write the flow**

Change the signature and add the pending branch at the very top of `answer()`:

```php
	public function answer( $message, array $history = array(), $pending = null ) {
		$this->pending_out = null;

		$resumed = $this->resume_pending( $message, $pending );

		if ( null !== $resumed ) {
			return $resumed;
		}

		// ... existing body unchanged ...
	}
```

Add the flow methods (Task 6 fills in the confirm branch):

```php
	/** @var array<string,mixed>|null Pending order handed back to the browser. */
	private $pending_out = null;

	/**
	 * Continue a buying conversation, if one is genuinely in progress.
	 *
	 * Returns null when there is nothing to resume, or when the customer has
	 * clearly moved on — a customer who changes the subject must never be
	 * trapped in a loop, so we answer their actual question instead.
	 *
	 * @param string $message Customer's message.
	 * @param mixed  $pending Raw pending order from the browser.
	 * @return array<string,mixed>|null
	 */
	private function resume_pending( $message, $pending ) {
		$order = DB_Chat_Pending_Order::from_request( $pending );

		if ( null === $order || $order->is_expired() ) {
			return null;
		}

		$language = self::detect_language( $this->normalize( $message ) );

		if ( DB_Chat_Pending_Order::STAGE_PRODUCT === $order->stage ) {
			$chosen = $this->match_offered_product( $message, (array) $order->offered );

			if ( $chosen < 1 ) {
				return null; // Not an answer. Fall through to a normal reply.
			}

			return $this->ask_for_variation( $chosen, $order->quantity, $language );
		}

		if ( DB_Chat_Pending_Order::STAGE_VARIATION === $order->stage ) {
			$variation = $this->match_offered_variation( $message, (array) $order->offered );

			if ( $variation < 1 ) {
				return null;
			}

			return $this->ask_for_confirmation( $order->product_id, $variation, $order->quantity, $language );
		}

		// STAGE_CONFIRM is handled in Task 6.
		return $this->handle_confirmation( $message, $order, $language );
	}

	/**
	 * Ask which size, offering only what is in stock.
	 *
	 * @param int    $product_id Product.
	 * @param int    $quantity   Quantity carried from earlier.
	 * @param string $language   Reply language.
	 * @return array<string,mixed>
	 */
	private function ask_for_variation( $product_id, $quantity, $language ) {
		$writer  = new DB_Chat_Cart_Writer();
		$sizes   = $writer->in_stock_variations( $product_id );
		$details = $writer->describe( $product_id, 0 );

		if ( empty( $sizes ) ) {
			// Simple product, or nothing in stock: straight to confirmation,
			// where the cart writer will refuse anything unbuyable.
			return $this->ask_for_confirmation( $product_id, 0, $quantity, $language );
		}

		if ( 1 === count( $sizes ) ) {
			/*
			 * One size left. Not chosen silently — it is named in the
			 * confirmation, which the customer still has to accept.
			 */
			return $this->ask_for_confirmation( $product_id, (int) $sizes[0]['variation_id'], $quantity, $language );
		}

		$lines = array( $details['name'], '', $this->text( $language, 'buy_which_size' ) );

		foreach ( $sizes as $row ) {
			$lines[] = "\xE2\x80\xA2 " . $row['label'];
		}

		$this->pending_out = DB_Chat_Pending_Order::create(
			DB_Chat_Pending_Order::STAGE_VARIATION,
			array(
				'product_id' => $product_id,
				'quantity'   => $quantity,
				'offered'    => $sizes,
			)
		)->to_array();

		return array(
			'reply'    => implode( "\n", $lines ),
			'products' => $this->resolve_cards( array( $details['name'] ) ),
			'pages'    => array(),
			'pending'  => $this->pending_out,
		);
	}

	/**
	 * Show the summary and wait for a yes.
	 *
	 * @param int    $product_id   Product.
	 * @param int    $variation_id Variation, 0 for simple products.
	 * @param int    $quantity     Quantity.
	 * @param string $language     Reply language.
	 * @return array<string,mixed>
	 */
	private function ask_for_confirmation( $product_id, $variation_id, $quantity, $language ) {
		$writer  = new DB_Chat_Cart_Writer();
		$details = $writer->describe( $product_id, $variation_id );
		$qty     = max( 1, (int) $quantity );

		$reply = '' !== $details['label']
			? $this->text( $language, 'buy_confirm', $details['name'], $details['label'], $qty, $details['price'] )
			: $this->text( $language, 'buy_confirm_simple', $details['name'], $qty, $details['price'] );

		$this->pending_out = DB_Chat_Pending_Order::create(
			DB_Chat_Pending_Order::STAGE_CONFIRM,
			array(
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'quantity'     => $qty,
			)
		)->to_array();

		return array(
			'reply'    => $reply,
			'products' => $this->resolve_cards( array( $details['name'] ) ),
			'pages'    => array(),
			'pending'  => $this->pending_out,
		);
	}
```

In the normal product-reply path, when the message was a purchase and results were found, open the flow instead of only adding a note:

```php
		if ( $is_purchase && ! empty( $records ) ) {
			if ( 1 === count( $listed ) ) {
				return $this->ask_for_variation( $this->product_id_for( $listed[0] ), max( 1, (int) $filters['quantity'] ), $language );
			}

			$this->pending_out = DB_Chat_Pending_Order::create(
				DB_Chat_Pending_Order::STAGE_PRODUCT,
				array(
					'quantity' => max( 1, (int) $filters['quantity'] ),
					'offered'  => array_map( array( $this, 'product_id_for' ), $listed ),
				)
			)->to_array();

			$notes[] = $this->text( $language, 'buy_which_product' );
		}
```

Add the helper that turns a listed record into an id (the index already keeps the products it returned):

```php
	/**
	 * Product id for a record the index just returned.
	 *
	 * @param array $record Product record.
	 * @return int
	 */
	private function product_id_for( array $record ) {
		if ( isset( $record['id'] ) ) {
			return absint( $record['id'] );
		}

		$id = isset( $record['url'] ) ? url_to_postid( (string) $record['url'] ) : 0;

		return absint( $id );
	}
```

Finally, every `return` in `answer()` must carry the key, so add `'pending' => $this->pending_out,` to the returned array in the normal path.

- [ ] **Step 5: Lint, deploy, run**

```bash
/opt/homebrew/opt/php@8.3/bin/php -l durianbikers-chatbot/includes/class-lookup-engine.php
$SSH "cat > $PLUG/includes/class-lookup-engine.php" < durianbikers-chatbot/includes/class-lookup-engine.php
$SSH "$WP eval-file ~/claude-uploads/t5-flow.php"
```

Expected: `14 passed, 0 failed`.

If `product_id_for()` returns 0, the product index is not exposing an id — add `'id' => $product->get_id()` to the record in `class-product-index.php::format_for_model()` rather than relying on `url_to_postid()`, and re-run.

- [ ] **Step 6: Re-run every earlier suite**

```bash
for t in rank-test purchase-test t2-vocabulary t4-answer-matching; do $SSH "$WP eval-file ~/claude-uploads/$t.php" | tail -2; done
```

Expected: all still passing.

- [ ] **Step 7: Commit**

```bash
git add durianbikers-chatbot/includes/class-lookup-engine.php
git commit -m "feat: ask which product, then which in-stock size, then confirm"
```

---

### Task 6: Confirmation and the cart write

**Files:**
- Modify: `durianbikers-chatbot/includes/class-lookup-engine.php`
- Test: `$SCRATCH/t6-confirm.php`

**Interfaces:**
- Consumes: Tasks 1, 3, 5.
- Produces: `handle_confirmation()` completing the flow; reply gains `cart` = `array( 'added' => bool, 'url' => string, 'total' => string, 'count' => int )`.

- [ ] **Step 1: Write the failing test**

Create `$SCRATCH/t6-confirm.php`:

```php
<?php
$e = new DB_Chat_Lookup_Engine( new DB_Chat_Product_Index() );
$w = new DB_Chat_Cart_Writer();
$pass = 0; $fail = 0;
function ck( $l, $ok, &$p, &$f ) { printf( "%s %s\n", $ok ? 'PASS' : 'FAIL', $l ); $ok ? $p++ : $f++; }

$sizes    = $w->in_stock_variations( 58024 );
$variation = (int) $sizes[0]['variation_id'];

$confirm_state = array(
	'stage'        => DB_Chat_Pending_Order::STAGE_CONFIRM,
	'product_id'   => 58024,
	'variation_id' => $variation,
	'quantity'     => 2,
	'offered'      => array(),
	'created'      => time(),
);

if ( WC()->cart ) { WC()->cart->empty_cart(); }

// "no" must not write anything.
$r = $e->answer( 'no', array(), $confirm_state );
ck( 'decline adds nothing', 0 === WC()->cart->get_cart_contents_count(), $pass, $fail );
ck( 'decline clears the pending order', empty( $r['pending'] ), $pass, $fail );

// "yes" writes the line.
$r = $e->answer( 'yes', array(), $confirm_state );
ck( 'confirm reports the cart', ! empty( $r['cart']['added'] ), $pass, $fail );
ck( 'cart holds 2 items', 2 === WC()->cart->get_cart_contents_count(), $pass, $fail );
ck( 'reply carries a cart link', false !== strpos( (string) ( $r['cart']['url'] ?? '' ), 'cart' ), $pass, $fail );
ck( 'pending cleared after adding', empty( $r['pending'] ), $pass, $fail );

WC()->cart->empty_cart();

// A tampered variation id must be refused.
$tampered = $confirm_state;
$tampered['variation_id'] = 999999999;
$r = $e->answer( 'yes', array(), $tampered );
ck( 'tampered variation refused', empty( $r['cart']['added'] ), $pass, $fail );
ck( 'nothing was added', 0 === WC()->cart->get_cart_contents_count(), $pass, $fail );

// A variation belonging to the OTHER product must be refused.
$other = $w->in_stock_variations( 58402 );
$cross = $confirm_state;
$cross['variation_id'] = (int) $other[0]['variation_id'];
$r = $e->answer( 'yes', array(), $cross );
ck( 'cross-product variation refused', empty( $r['cart']['added'] ), $pass, $fail );

WC()->cart->empty_cart();
ck( 'cart emptied after test', 0 === WC()->cart->get_cart_contents_count(), $pass, $fail );

echo "\n==== $pass passed, $fail failed ====\n";
```

- [ ] **Step 2: Run it to verify it fails**

```bash
$SSH "cat > ~/claude-uploads/t6-confirm.php" < $SCRATCH/t6-confirm.php
$SSH "$WP eval-file ~/claude-uploads/t6-confirm.php"
```

Expected: fatal or failures — `handle_confirmation()` does not exist yet.

- [ ] **Step 3: Write the implementation**

Add the templates. English:

```php
			/* translators: 1: quantity; 2: product name; 3: cart total. */
			'buy_added'          => __( 'Added %1$d × %2$s to your cart. Your cart is now %3$s.', 'durianbikers-chatbot' ),
			'buy_failed'         => __( 'I could not add that to your cart: %s', 'durianbikers-chatbot' ),
```

Malay:

```php
			/* translators: 1: quantity; 2: product name; 3: cart total. */
			'buy_added'          => 'Sudah masukkan %1$d × %2$s ke dalam troli anda. Jumlah troli sekarang %3$s.',
			'buy_failed'         => 'Saya tidak dapat masukkan ke dalam troli: %s',
```

Then:

```php
	/**
	 * The customer answered the confirmation.
	 *
	 * Anything that is not a clear yes is treated as NOT a yes. On a store
	 * taking real payments, an ambiguous reply must never become a purchase.
	 *
	 * @param string                 $message  Customer's reply.
	 * @param DB_Chat_Pending_Order  $order    The pending order.
	 * @param string                 $language Reply language.
	 * @return array<string,mixed>|null
	 */
	private function handle_confirmation( $message, DB_Chat_Pending_Order $order, $language ) {
		$normalized = ' ' . $this->normalize( $message ) . ' ';

		foreach ( self::decline_terms() as $term ) {
			if ( false !== strpos( $normalized, ' ' . $term . ' ' ) ) {
				$this->pending_out = null;

				return array(
					'reply'    => $this->text( $language, 'buy_cancelled' ),
					'products' => array(),
					'pages'    => array(),
					'pending'  => null,
				);
			}
		}

		$confirmed = false;

		foreach ( self::confirm_terms() as $term ) {
			if ( false !== strpos( $normalized, ' ' . $term . ' ' ) ) {
				$confirmed = true;
				break;
			}
		}

		if ( ! $confirmed ) {
			// Neither yes nor no — answer whatever they actually asked.
			return null;
		}

		$writer  = new DB_Chat_Cart_Writer();
		$details = $writer->describe( $order->product_id, $order->variation_id );
		$result  = $writer->add( $order->product_id, $order->variation_id, $order->quantity );

		$this->pending_out = null;

		if ( empty( $result['ok'] ) ) {
			return array(
				'reply'    => $this->text( $language, 'buy_failed', $result['message'] ),
				'products' => array(),
				'pages'    => array(),
				'pending'  => null,
				'cart'     => array( 'added' => false, 'url' => '', 'total' => '', 'count' => 0 ),
			);
		}

		return array(
			'reply'    => $this->text( $language, 'buy_added', $order->quantity, $details['name'], $result['cart_total'] ),
			'products' => array(),
			'pages'    => array(),
			'pending'  => null,
			'cart'     => array(
				'added' => true,
				'url'   => $result['cart_url'],
				'total' => $result['cart_total'],
				'count' => $result['item_count'],
			),
		);
	}
```

- [ ] **Step 4: Lint, deploy, run**

```bash
/opt/homebrew/opt/php@8.3/bin/php -l durianbikers-chatbot/includes/class-lookup-engine.php
$SSH "cat > $PLUG/includes/class-lookup-engine.php" < durianbikers-chatbot/includes/class-lookup-engine.php
$SSH "$WP eval-file ~/claude-uploads/t6-confirm.php"
```

Expected: `10 passed, 0 failed`, cart empty at the end.

- [ ] **Step 5: Commit**

```bash
git add durianbikers-chatbot/includes/class-lookup-engine.php
git commit -m "feat: add to cart on explicit confirmation only"
```

---

### Task 7: Wire it through the REST route and the widget

**Files:**
- Modify: `durianbikers-chatbot/includes/class-chat-endpoint.php`
- Modify: `durianbikers-chatbot/assets/chatbot.js`
- Test: `$SCRATCH/t7-endpoint.sh` (a curl script, run from this Mac)

**Interfaces:**
- Consumes: Tasks 5, 6.
- Produces: the `/chat` route accepts a `pending` object and returns `pending` plus `cart`.

- [ ] **Step 1: Write the failing test**

Create `$SCRATCH/t7-endpoint.sh`:

```bash
#!/bin/bash
# Anonymous calls only prove the plumbing; the gate still returns 503 offline
# for non-staff. What we assert here is that the route ACCEPTS a pending
# object without erroring, and echoes the key back.
BASE=https://durianbikers.com/wp-json/durianbikers/v1
N=$(curl -s "$BASE/session" | python3 -c "import sys,json;print(json.load(sys.stdin)['nonce'])")
OUT=$(curl -s -X POST "$BASE/chat" -H "Content-Type: application/json" -H "X-WP-Nonce: $N" \
  --data-raw '{"message":"L","pending":{"stage":"ask_variation","product_id":58024,"variation_id":0,"quantity":2,"offered":[],"created":'"$(date +%s)"'}}')
echo "$OUT"
echo "$OUT" | grep -q "db_chat_offline" && echo "PASS route accepted the pending payload (gate still closed for guests)" \
  || echo "CHECK: unexpected response - read it above"
```

- [ ] **Step 2: Run it to verify it fails**

```bash
bash $SCRATCH/t7-endpoint.sh
```

Expected: a validation error about an unknown parameter, or the pending object being silently ignored.

- [ ] **Step 3: Write the implementation**

In `class-chat-endpoint.php`, add `pending` to the `/chat` route `args`:

```php
					'pending' => array(
						'required'          => false,
						'type'              => 'object',
						'validate_callback' => static function ( $value ) {
							return is_array( $value ) || null === $value;
						},
					),
```

Where the lookup engine is called, pass it through and return what comes back:

```php
			$result = $this->lookup_engine->answer( $message, $history, $request->get_param( 'pending' ) );
```

Add to the response payload, beside `reply`/`products`/`pages`:

```php
			'pending' => isset( $result['pending'] ) ? $result['pending'] : null,
			'cart'    => isset( $result['cart'] ) ? $result['cart'] : null,
```

In `assets/chatbot.js`:

```js
	/*
	 * The half-finished order lives here between messages, exactly like the
	 * conversation history. The server never trusts it — it re-reads the
	 * product, the variation, the stock and the price before adding anything.
	 */
	var pendingOrder = null;
```

Include it in the POST body:

```js
			body: JSON.stringify({
				message: message,
				history: historyForRequest(),
				pending: pendingOrder
			}),
```

And after a successful reply:

```js
			pendingOrder = data.pending || null;

			if (data.cart && data.cart.added) {
				// Update the header cart icon; the theme supplies the fragment.
				if (window.jQuery) {
					window.jQuery(document.body).trigger('wc_fragment_refresh');
				}
			}
```

- [ ] **Step 4: Lint, deploy, run**

```bash
/opt/homebrew/opt/php@8.3/bin/php -l durianbikers-chatbot/includes/class-chat-endpoint.php
node --check durianbikers-chatbot/assets/chatbot.js 2>/dev/null || echo "node not installed - skip JS syntax check"
$SSH "cat > $PLUG/includes/class-chat-endpoint.php" < durianbikers-chatbot/includes/class-chat-endpoint.php
$SSH "cat > $PLUG/assets/chatbot.js" < durianbikers-chatbot/assets/chatbot.js
bash $SCRATCH/t7-endpoint.sh
```

Expected: PASS — the route accepts the payload and the gate still closes it to guests.

- [ ] **Step 5: Manual check as staff**

Open `https://durianbikers.com/my/chatbot-test/` signed in, and run the whole conversation: "i want yamanaka for 2pcs" → pick the product → pick a size → "yes". Confirm the header cart icon updates, then empty the cart.

- [ ] **Step 6: Commit**

```bash
git add durianbikers-chatbot/includes/class-chat-endpoint.php durianbikers-chatbot/assets/chatbot.js
git commit -m "feat: carry the pending order through the chat route and widget"
```

---

### Task 8: Chinese

**Files:**
- Modify: `durianbikers-chatbot/includes/class-lookup-engine.php`
- Test: `$SCRATCH/t8-chinese.php`

**Interfaces:**
- Consumes: Tasks 2, 5, 6.
- Produces: `DB_Chat_Lookup_Engine::chinese_terms(): array<string,string[]>` keyed `purchase` / `confirm` / `decline`; `detect_language()` returns `'zh'` for Han-script messages.

**Why this is separate:** Chinese has no spaces, so the token splitter every other language relies on cannot see word boundaries. Chinese terms are matched as substrings instead.

- [ ] **Step 1: Write the failing test**

Create `$SCRATCH/t8-chinese.php`:

```php
<?php
$e = new DB_Chat_Lookup_Engine( new DB_Chat_Product_Index() );
$pass = 0; $fail = 0;
function ck( $l, $ok, &$p, &$f ) { printf( "%s %s\n", $ok ? 'PASS' : 'FAIL', $l ); $ok ? $p++ : $f++; }

ck( 'Han script detected as zh', 'zh' === DB_Chat_Lookup_Engine::detect_language( '我要买头盔' ), $pass, $fail );
ck( 'English still detected as en', 'en' === DB_Chat_Lookup_Engine::detect_language( 'i want a helmet' ), $pass, $fail );

$r = $e->answer( '我要买 yamanaka 2个' );
ck( 'Chinese buy request finds products', ! empty( $r['products'] ), $pass, $fail );
ck( 'Chinese buy request opens the flow', ! empty( $r['pending'] ), $pass, $fail );
ck( 'quantity 2 read from 2个', 2 === ( $r['pending']['quantity'] ?? 0 ), $pass, $fail );

$sizes = ( new DB_Chat_Cart_Writer() )->in_stock_variations( 58024 );
$state = array(
	'stage'        => DB_Chat_Pending_Order::STAGE_CONFIRM,
	'product_id'   => 58024,
	'variation_id' => (int) $sizes[0]['variation_id'],
	'quantity'     => 1,
	'offered'      => array(),
	'created'      => time(),
);
if ( WC()->cart ) { WC()->cart->empty_cart(); }
$r = $e->answer( '确认', array(), $state );
ck( '确认 confirms the order', ! empty( $r['cart']['added'] ), $pass, $fail );
WC()->cart->empty_cart();

$r = $e->answer( '不要', array(), $state );
ck( '不要 cancels', empty( $r['cart']['added'] ), $pass, $fail );
ck( 'nothing in the cart after cancelling', 0 === WC()->cart->get_cart_contents_count(), $pass, $fail );

echo "\n==== $pass passed, $fail failed ====\n";
```

- [ ] **Step 2: Run it to verify it fails**

```bash
$SSH "cat > ~/claude-uploads/t8-chinese.php" < $SCRATCH/t8-chinese.php
$SSH "$WP eval-file ~/claude-uploads/t8-chinese.php"
```

Expected: language detection returns `en`; most assertions FAIL.

- [ ] **Step 3: Write the implementation**

```php
	/**
	 * Chinese vocabulary, matched as substrings.
	 *
	 * Chinese is written without spaces, so the token splitter every other
	 * language uses cannot find word boundaries here. These are matched with
	 * mb_strpos against the raw message instead.
	 *
	 * @return array<string,string[]>
	 */
	public static function chinese_terms() {
		$terms = array(
			'purchase' => array( '买', '購', '购', '要买', '想买', '下单', '訂購', '订购' ),
			'confirm'  => array( '确认', '確認', '好', '好的', '是', '对', '可以', '要' ),
			'decline'  => array( '不要', '不用', '取消', '算了', '不' ),
			'unit'     => array( '个', '個', '件', '双', '雙' ),
		);

		/**
		 * Filter the Chinese vocabulary.
		 *
		 * @param array<string,string[]> $terms Terms by role.
		 */
		return (array) apply_filters( 'db_chat_chinese_terms', $terms );
	}

	/**
	 * Does this message contain Han characters?
	 *
	 * @param string $text Raw message.
	 * @return bool
	 */
	public static function is_chinese( $text ) {
		return 1 === preg_match( '/\p{Han}/u', (string) $text );
	}
```

In `detect_language()`, before the existing Malay/English decision:

```php
		if ( self::is_chinese( $normalized ) ) {
			return 'zh';
		}
```

Add a `zh` block to `templates()` mirroring every key in the English block. Where confirm/decline/purchase are tested, check Chinese first:

```php
		if ( self::is_chinese( $message ) ) {
			foreach ( self::chinese_terms()['decline'] as $term ) {
				if ( false !== mb_strpos( $message, $term ) ) {
					// ... cancel path ...
				}
			}
		}
```

**Ordering matters:** `不要` contains `要`, which is a confirm term. Always test decline terms before confirm terms, and match the longest term first.

Quantity: add a Chinese pass to `build_query()`:

```php
		if ( 0 === $filters['quantity'] && self::is_chinese( $normalized ) ) {
			foreach ( self::chinese_terms()['unit'] as $unit ) {
				if ( preg_match( '/([0-9]{1,3})\s*' . preg_quote( $unit, '/' ) . '/u', $normalized, $m ) ) {
					$filters['quantity'] = (int) $m[1];
					break;
				}
			}
		}
```

- [ ] **Step 4: Lint, deploy, run**

```bash
/opt/homebrew/opt/php@8.3/bin/php -l durianbikers-chatbot/includes/class-lookup-engine.php
$SSH "cat > $PLUG/includes/class-lookup-engine.php" < durianbikers-chatbot/includes/class-lookup-engine.php
$SSH "$WP eval-file ~/claude-uploads/t8-chinese.php"
```

Expected: `8 passed, 0 failed`.

- [ ] **Step 5: Commit**

```bash
git add durianbikers-chatbot/includes/class-lookup-engine.php
git commit -m "feat: Chinese buying, confirmation and quantity vocabulary"
```

---

### Task 9: Full regression pass and documentation

**Files:**
- Modify: `durianbikers-chatbot/DEPLOY.md`
- Test: every harness, run together

- [ ] **Step 1: Run every suite**

```bash
for t in rank-test purchase-test t2-vocabulary t3-pending t4-answer-matching t5-flow t6-confirm t8-chinese; do
  echo "--- $t"; $SSH "$WP eval-file ~/claude-uploads/$t.php" | tail -2
done
$SSH "$WP eval-file ~/claude-uploads/t1-cart-writer.php" | tail -2
```

Expected: zero failures anywhere.

- [ ] **Step 2: Confirm the cart is empty on the live store**

```bash
$SSH "$WP eval 'wc_load_cart(); echo WC()->cart->get_cart_contents_count();'"
```

Expected: `0`. Any leftover line is a test that failed to clean up — empty it and find out which.

- [ ] **Step 3: Update DEPLOY.md**

Replace the "No add-to-cart in the chat" bullet under **What this bot cannot do, by design** with an accurate description: chat can now add to cart after an explicit confirmation, it never chooses a variation for the customer, it only offers in-stock options, and it still cannot check out, apply coupons, or change an existing cart.

- [ ] **Step 4: Commit**

```bash
git add durianbikers-chatbot/DEPLOY.md
git commit -m "docs: describe chat add-to-cart in the deploy runbook"
```

---

## Self-Review Notes

- **Spec coverage.** §2 defect 1 → Task 4/5; §2 defect 2 → Task 2; §3 confirm gate → Task 6; §3 languages → Tasks 2 and 8; §3 in-stock only → Tasks 1 and 5; §4 flow and abandon/expiry → Tasks 3 and 5; §5 pending in request → Tasks 3 and 7; §6 validation → Task 1; §7 cart mechanics → Tasks 1 and 7; §9 testing → every task, gathered in Task 9; §11 cart-in-REST risk → proved first, in Task 1.
- **Names are consistent** across tasks: `in_stock_variations()`, `describe()`, `add()`, `match_offered_product()`, `match_offered_variation()`, `resume_pending()`, `ask_for_variation()`, `ask_for_confirmation()`, `handle_confirmation()`, `product_id_for()`, `pending_out`.
- **Known soft spot:** Task 5 Step 5 flags that `product_id_for()` may need `format_for_model()` to expose an id. That is a real possibility, and the remedy is written into the step rather than left to be discovered.
