# Chatbot Cards, Brand Narrowing and Human Handoff Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Before go-live, make "talk to a person" route to WhatsApp, give every product card a validated **This one** pick plus a same-tab link with the conversation remembered, and narrow brand-only questions with in-stock tap buttons.

**Architecture:** Server changes stay inside the existing classes: a `human` intent and a `chosen` pending stage in `DB_Chat_Lookup_Engine`, an optional `pick` param on the chat route, `id` on every card, and a brand-narrowing entry point inside `DB_Chat_Product_Finder` that replays its state from history like the existing finder. The widget gains a pick button, a card link, and `sessionStorage` persistence that is wiped the moment orders come up.

**Tech Stack:** PHP 8.3 (8.0 floor), WordPress 7.1, WooCommerce 10.9.4, plain JS (no build). PHP tests are harnesses run with `wp eval-file` over SSH against the live install; the widget is tested with a static harness page served locally and driven in the Browser pane.

**Spec:** `docs/superpowers/specs/2026-09-14-chatbot-cards-narrowing-handoff-design.md` (bdf6791)

## Global Constraints

- Prefix `db_chat_` / `DB_Chat_`. Text domain `durianbikers-chatbot`. English copy through `__()`; Malay copy literal (the store has no .mo file).
- Lint every changed PHP file with `/opt/homebrew/opt/php@8.3/bin/php -l`. Bare `php` on this Mac is 8.5 and proves nothing.
- **No test file is ever placed in the plugin directory.** Harnesses live in `$SCRATCH` and are uploaded to `~/claude-uploads/`.
- Every PHP harness starts with the no-network guard and unmatched logging off (block below). No harness calls the Anthropic API.
- **Never write products, stock, prices or SKUs.** Simulate draft/password/hidden products with getter filters, never by editing a product.
- Cart tests never empty the db_admin cart; the full regression snapshots it with `t21-cart-snapshot.php`.
- Live `db_chat_mode` stays `lookup`. The chatbot stays gated to the private test page. Go-live is not in this plan.
- Deploy with the safe method: backup to `~/claude-uploads/<file>.bak-<task>`, upload to `<path>.new`, remote `php -l`, `mv`, md5 both ends, then `/my/`, `/gb/`, `/my/shop/`, `/my/checkout/` must return 200 or the backup is restored automatically.
- WP-CLI on the VPS needs `-d memory_limit=512M` (1024M for Task 4 harnesses).
- Pending order TTL stays `DB_Chat_Pending_Order::TTL` (900 s). Saved widget state expires after **30 minutes**, max **100 KB**, key `db_chat_state_v1_{store}`.
- Narrowing: stop at **≤ 4** products; **at most 6 buttons**, All / Semua last; skip a facet whose largest bucket is **≥ 80 %** of the set; brands with **fewer than 5** buyable products are not narrowed.
- Commits end with `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>` and stage only the task's files.

Shell shorthand used throughout (zsh-safe — a function, not a string):

```bash
sshc () { ssh -i "$HOME/.ssh/durianbikers_vps" -p 8288 -o BatchMode=yes durianbi@103.233.1.74 "$@"; }
WPROOT=/var/www/vhosts/durianbikers.com/staging.durianbikers.com
WP="/opt/plesk/php/8.3/bin/php -d memory_limit=512M /usr/local/bin/wp --path=$WPROOT"
PLUG=$WPROOT/wp-content/plugins/durianbikers-chatbot
SCRATCH=/private/tmp/claude-501/-Users-superfly2026-sites-durianbikers/33a62eb6-8bc3-4efe-bd0a-a5fb95e120e4/scratchpad
health () { R=0; for u in /my/ /gb/ /my/shop/ /my/checkout/; do c=$(curl -s -m 30 -o /dev/null -L -w '%{http_code}' "https://durianbikers.com$u?nc=$(date +%s)"); printf '%s %s  ' $u $c; [ "$c" = 200 ] || R=1; done; echo; return $R; }
safe_deploy () {  # local-relative-to-plugin, task-tag
  local rel=$1 tag=$2 rem=$PLUG/$1 bak=claude-uploads/$(basename $1).bak-$2
  health || { echo "UNHEALTHY BEFORE DEPLOY"; return 1; }
  sshc "cp $rem $bak 2>/dev/null || true" && sshc "cat > $rem.new" < durianbikers-chatbot/$rel || return 1
  sshc "/opt/plesk/php/8.3/bin/php -l $rem.new" >/dev/null || { sshc "rm -f $rem.new"; echo "REMOTE LINT FAILED"; return 1; }
  sshc "mv $rem.new $rem" && [ "$(md5 -q durianbikers-chatbot/$rel)" = "$(sshc "md5sum $rem | cut -d' ' -f1")" ] || { echo "CHECKSUM MISMATCH"; return 1; }
  health || { sshc "cp $bak $rem"; echo "RESTORED $rel"; health; return 1; }
}
```

Every PHP harness starts with:

```php
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) { if ( false !== strpos( (string) $url, 'api.anthropic.com' ) ) { throw new RuntimeException( 'TEST TRIED TO CALL THE REAL ANTHROPIC API' ); } return $pre; }, 1, 3 );
add_filter( 'db_chat_lookup_log_unmatched', '__return_false' );
$pass = 0; $fail = 0;
function ck( $l, $ok, &$p, &$f ) { printf( "%s %s\n", $ok ? 'PASS' : 'FAIL', $l ); $ok ? $p++ : $f++; }
```

---

## File Structure

| File | Responsibility | Tasks |
|---|---|---|
| `includes/class-lookup-engine.php` | `human` intent and reply; `answer_pick()`; `chosen` stage resume | 1, 2 |
| `includes/class-handoff.php` | Accept Malaysian local WhatsApp numbers | 1 |
| `includes/class-chat-endpoint.php` | Pass `suggest_handoff` through; `pick` param | 1, 2 |
| `includes/class-pending-order.php` | `STAGE_CHOSEN` | 2 |
| `includes/class-product-index.php` | `id` on cards; public `card_for()` | 2 |
| `includes/class-product-finder.php` | `id` on finder cards; brand narrowing | 2, 4 |
| `durianbikers-chatbot.php` | `store` key in script data | 3 |
| `assets/chatbot.js`, `assets/chatbot.css` | Pick button, card link, persistence | 3 |
| `DEPLOY.md` | Runbook notes | 5 |

---

### Task 1: Human handoff — `human` intent, WhatsApp reply, local numbers

**Files:**
- Modify: `durianbikers-chatbot/includes/class-lookup-engine.php` (keywords `contact` group ~line 400; templates `handoff_none` en ~963 and ms ~1058; `answer()` switch ~1264; `resolve_intent()` ~1501)
- Modify: `durianbikers-chatbot/includes/class-handoff.php` (`normalize_number()` ~152)
- Modify: `durianbikers-chatbot/includes/class-chat-endpoint.php` (`handle_with_lookup()` payload)
- Test: `$SCRATCH/t26-human.php`

**Interfaces:**
- Produces: intent string `'human'` from `DB_Chat_Lookup_Engine::detect_intent()`; `answer()` result key `suggest_handoff => true` on a human turn when a WhatsApp number is configured; template keys `human_handoff`, `human_closed`, `human_none`; REST response key `suggest_handoff` (already honoured by `replyOffersHandoff()` in chatbot.js).
- `DB_Chat_Handoff::normalize_number( '0196141123' ) === '60196141123'`.

- [ ] **Step 1: Write the failing test**

Create `$SCRATCH/t26-human.php`:

```php
<?php
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) { if ( false !== strpos( (string) $url, 'api.anthropic.com' ) ) { throw new RuntimeException( 'TEST TRIED TO CALL THE REAL ANTHROPIC API' ); } return $pre; }, 1, 3 );
add_filter( 'db_chat_lookup_log_unmatched', '__return_false' );
add_filter( 'db_chat_is_enabled', '__return_true', 1000 );
add_filter( 'db_chat_rate_limit', '__return_zero' );
add_filter( 'db_chat_rate_limit_ip', '__return_zero' );
add_filter( 'db_chat_mode', function () { return 'lookup'; } );
$pass = 0; $fail = 0;
function ck( $l, $ok, &$p, &$f ) { printf( "%s %s\n", $ok ? 'PASS' : 'FAIL', $l ); $ok ? $p++ : $f++; }

$index  = new DB_Chat_Product_Index();
$pages  = new DB_Chat_Content_Index();
$e      = new DB_Chat_Lookup_Engine( $index, $pages );

// 11: every phrase is a human request, answered with WhatsApp, never a T&C passage.
$phrases = array( 'i want to talk to a human', 'talk to someone please', 'can i speak to someone', 'speak to a person', 'customer service', 'live agent please', 'nak cakap dengan orang', 'boleh saya bercakap dengan staf', 'khidmat pelanggan', '我要人工客服', '真人' );
foreach ( $phrases as $p ) {
	$r = $e->answer( $p );
	ck( "\"$p\" -> human + WhatsApp, no T&C", 'human' === $e->detect_intent( $p ) && ! empty( $r['suggest_handoff'] ) && false !== stripos( $r['reply'], 'whatsapp' ) && false === stripos( $r['reply'], 'reserve the right' ), $pass, $fail );
}

// 1: Malay reply is Malay.
$r = $e->answer( 'nak cakap dengan orang' );
ck( 'Malay request gets the Malay reply', false !== strpos( $r['reply'], 'pasukan kami' ), $pass, $fail );

// 2: identity wins.
ck( '"are you human" stays identity', 'identity' === $e->detect_intent( 'are you human' ), $pass, $fail );
ck( '"is this a real person" stays identity', 'identity' === $e->detect_intent( 'is this a real person' ), $pass, $fail );

// 2: contact questions unchanged.
ck( 'phone number stays contact', 'contact' === $e->detect_intent( 'what is your phone number' ), $pass, $fail );
$r = $e->answer( 'what is your phone number' );
ck( 'contact answer carries no suggest_handoff', empty( $r['suggest_handoff'] ), $pass, $fail );

// 4: open / closed / unconfigured, via the reply builder directly.
$m = new ReflectionMethod( $e, 'answer_human' );
$m->setAccessible( true );
$open   = $m->invoke( $e, 'en', true );
$closed = $m->invoke( $e, 'en', false );
ck( 'open reply has no closed line', false === stripos( $open['reply'], 'closed right now' ), $pass, $fail );
ck( 'open reply shows the opening hours', false !== stripos( $open['reply'], 'hours' ), $pass, $fail );
ck( 'closed reply adds the closed line', false !== stripos( $closed['reply'], 'closed right now' ), $pass, $fail );
add_filter( 'pre_option_db_chat_handoff_number', function () { return ''; } );
$none = $m->invoke( $e, 'en', true );
ck( 'no number: contact page, no WhatsApp, no highlight', false === stripos( $none['reply'], 'whatsapp' ) && false !== stripos( $none['reply'], 'contact' ) && empty( $none['suggest_handoff'] ), $pass, $fail );
remove_all_filters( 'pre_option_db_chat_handoff_number' );

// 6: number normalisation.
$cases = array( '0196141123' => '60196141123', '+60 19-614 1123' => '60196141123', '60196141123' => '60196141123', '011-2345 6789' => '601123456789', '0123' => '', 'abc' => '' );
foreach ( $cases as $in => $want ) {
	ck( "normalize_number($in) = '$want'", $want === DB_Chat_Handoff::normalize_number( $in ), $pass, $fail );
}

// 2: the endpoint passes the flag through, and only on human turns.
$endpoint = new DB_Chat_Chat_Endpoint( $index, new DB_Chat_Claude_Client(), $pages, new DB_Chat_Order_Lookup(), $e );
$ask = function ( $message ) use ( $endpoint ) {
	$req = new WP_REST_Request( 'POST', '/durianbikers/v1/chat' );
	$req->set_param( 'message', $message );
	$req->set_param( 'history', array() );
	$res = $endpoint->handle_request( $req );
	return is_wp_error( $res ) ? array() : $res->get_data();
};
$d = $ask( 'talk to a human' );
ck( 'REST response carries suggest_handoff', ! empty( $d['suggest_handoff'] ), $pass, $fail );
$d = $ask( 'shinko 705' );
ck( 'product answer has no suggest_handoff key', ! array_key_exists( 'suggest_handoff', $d ), $pass, $fail );

// 1: a human turn is never a hybrid miss.
$e->answer( 'talk to a human' );
ck( 'human turn is not an AI miss', false === $e->needs_ai_fallback(), $pass, $fail );

// 1: fitment still points at WhatsApp.
$r = $e->answer( 'will this fit my yamaha r15' );
ck( 'fitment reply still mentions WhatsApp', false !== stripos( $r['reply'], 'whatsapp' ), $pass, $fail );

echo "\n==== $pass passed, $fail failed ====\n";
```

- [ ] **Step 2: Run it to verify it fails**

```bash
sshc "cat > claude-uploads/t26-human.php" < $SCRATCH/t26-human.php
sshc "$WP eval-file claude-uploads/t26-human.php 2>&1 | grep -v 'Purged all caches'"
```

Expected: FAIL on the 11 phrase checks, the Malay check, the four reply-builder checks (fatal `ReflectionException: Method answer_human does not exist` stops the run there). That fatal is the expected failure.

- [ ] **Step 3: Add the `human` keyword group and trim `contact`**

In `keywords()`, replace the whole `contact` group:

```php
			/* -------- contact -------- */
			'contact'      => array(
				'contact', 'contact you', 'contact number', 'phone number', 'your number', 'email',
				'email address', 'whatsapp', 'call you', 'talk to a human', 'talk to someone',
				'speak to someone', 'speak to a person', 'customer service', 'customer support',
				'hubungi', 'nombor telefon', 'no telefon', 'nombor', 'emel', 'cakap dengan orang',
				'nak tanya orang', 'khidmat pelanggan',
			),
```

with:

```php
			/* -------- contact -------- */
			'contact'      => array(
				'contact', 'contact you', 'contact number', 'phone number', 'your number', 'email',
				'email address', 'whatsapp', 'call you',
				'hubungi', 'nombor telefon', 'no telefon', 'nombor', 'emel',
			),

			/*
			 * -------- "let me talk to a person" --------
			 * PHRASES ONLY. A bare "agent" is how dealers and salesmen are
			 * described on this business, and a bare "human" appears in "are you
			 * human", which is an identity question. Identity is resolved first.
			 */
			'human'        => array(
				'talk to a human', 'talk to a person', 'talk to someone', 'talk to staff',
				'speak to someone', 'speak to a person', 'speak to a human', 'real person please',
				'customer service', 'customer support', 'live agent',
				'nak cakap dengan orang', 'cakap dengan orang', 'cakap dengan staf',
				'bercakap dengan staf', 'nak cakap dengan manusia', 'nak tanya orang',
				'khidmat pelanggan',
			),
```

- [ ] **Step 4: Resolve `human` right after identity**

In `resolve_intent()`, replace:

```php
		if ( $score( 'identity' ) > 0 ) {
			return 'identity';
		}
```

with:

```php
		if ( $score( 'identity' ) > 0 ) {
			return 'identity';
		}

		/*
		 * A request for a person, decided before scoring for the same reason as
		 * identity: answering it with a policy passage is exactly the failure
		 * that sent "talk to a human" to the Terms & Conditions page.
		 */
		if ( $score( 'human' ) > 0 || $this->wants_human_zh( $normalized ) ) {
			return 'human';
		}
```

Add this method directly after `resolve_intent()`:

```php
	/**
	 * Chinese request for a person. Substring match: Chinese has no spaces.
	 *
	 * @param string $normalized Normalized message.
	 * @return bool
	 */
	private function wants_human_zh( $normalized ) {
		if ( ! self::is_chinese( $normalized ) ) {
			return false;
		}

		foreach ( array( '人工', '客服', '真人' ) as $term ) {
			if ( false !== mb_strpos( (string) $normalized, $term ) ) {
				return true;
			}
		}

		return false;
	}
```

- [ ] **Step 5: Route the intent and build the reply**

In `answer()`, replace:

```php
			case 'contact':
				return $this->answer_contact( $normalized, $language );
```

with:

```php
			case 'contact':
				return $this->answer_contact( $normalized, $language );

			case 'human':
				return $this->answer_human( $language );
```

Add this method directly before `answer_contact()`:

```php
	/**
	 * "Let me talk to a person."
	 *
	 * Points at the WhatsApp button only when one is configured, says when
	 * staff will reply when the shop is closed, and asks the widget to
	 * highlight the button. Quotes no page passage.
	 *
	 * @param string    $language Reply language.
	 * @param bool|null $open     Injected for tests; null reads the live schedule.
	 * @return array
	 */
	private function answer_human( $language, $open = null ) {
		$configured = false;

		if ( class_exists( 'DB_Chat_Handoff' ) ) {
			try {
				$configured = (bool) DB_Chat_Handoff::is_configured();
			} catch ( Throwable $e ) {
				$configured = false;
			}
		}

		if ( ! $configured ) {
			$reply = $this->text( $language, 'human_none' );

			return $this->compose( $reply, $language, array(), $this->resolve_pages( $reply ) );
		}

		if ( null === $open ) {
			try {
				$open = (bool) DB_Chat_Handoff::is_open();
			} catch ( Throwable $e ) {
				$open = true;
			}
		}

		$lines = array( $this->text( $language, 'human_handoff' ) );
		$hours = $this->hours_text();

		if ( '' !== $hours ) {
			$lines[] = $this->text( $language, 'contact_hours', $hours );
		}

		if ( ! $open ) {
			$lines[] = $this->text( $language, 'human_closed' );
		}

		$out                    = $this->compose( implode( "\n", $lines ), $language );
		$out['suggest_handoff'] = true;

		return $out;
	}
```

- [ ] **Step 6: Add the copy**

In the English templates, replace:

```php
			'handoff_none'       => __( 'For anything I cannot answer, please contact the shop directly.', 'durianbikers-chatbot' ),
```

with:

```php
			'handoff_none'       => __( 'For anything I cannot answer, please contact the shop directly.', 'durianbikers-chatbot' ),
			'human_handoff'      => __( 'A person can help with that. Tap the WhatsApp button below and our team will reply.', 'durianbikers-chatbot' ),
			'human_closed'       => __( "We're closed right now, so we'll reply when we open.", 'durianbikers-chatbot' ),
			'human_none'         => __( 'A person can help with that. Please contact us — the details are on our Contact Us page.', 'durianbikers-chatbot' ),
```

In the Malay templates, replace:

```php
			'handoff_none'       => 'Untuk apa-apa yang saya tidak boleh jawab, sila hubungi kedai secara terus.',
```

with:

```php
			'handoff_none'       => 'Untuk apa-apa yang saya tidak boleh jawab, sila hubungi kedai secara terus.',
			'human_handoff'      => 'Staf kami boleh bantu. Tekan butang WhatsApp di bawah dan pasukan kami akan membalas.',
			'human_closed'       => 'Kami tutup sekarang, jadi kami akan membalas bila kami buka.',
			'human_none'         => 'Staf kami boleh bantu. Sila hubungi kami — butirannya ada di halaman Contact Us kami.',
```

- [ ] **Step 7: Accept local Malaysian numbers**

In `DB_Chat_Handoff::normalize_number()`, replace:

```php
		if ( '0' === $digits[0] ) {
			return '';
		}
```

with:

```php
		/*
		 * Malaysian local form: 01x-xxx xxxx. Typed that way into the settings
		 * screen it used to normalise to '' and the WhatsApp button silently
		 * disappeared. 10 or 11 digits starting 01 become 60 + the number
		 * without its leading 0. Any other leading 0 is still refused.
		 */
		if ( 1 === preg_match( '/^01[0-9]{8,9}$/', $digits ) ) {
			$digits = '6' . $digits;
		}

		if ( '0' === $digits[0] ) {
			return '';
		}
```

- [ ] **Step 8: Pass the flag through the endpoint**

In `handle_with_lookup()`, replace:

```php
		if ( isset( $result['cart'] ) && is_array( $result['cart'] ) ) {
			$payload['cart'] = $result['cart'];
		}
```

with:

```php
		if ( isset( $result['cart'] ) && is_array( $result['cart'] ) ) {
			$payload['cart'] = $result['cart'];
		}

		// Only ever true, only on a request for a person. chatbot.js highlights
		// the WhatsApp row on it (replyOffersHandoff()).
		if ( ! empty( $result['suggest_handoff'] ) ) {
			$payload['suggest_handoff'] = true;
		}
```

- [ ] **Step 9: Lint, deploy, run**

```bash
cd /Users/superfly2026/sites/durianbikers
for f in includes/class-lookup-engine.php includes/class-handoff.php includes/class-chat-endpoint.php; do /opt/homebrew/opt/php@8.3/bin/php -l durianbikers-chatbot/$f || break; done
safe_deploy includes/class-handoff.php task1 && safe_deploy includes/class-lookup-engine.php task1 && safe_deploy includes/class-chat-endpoint.php task1
sshc "$WP eval-file claude-uploads/t26-human.php 2>&1 | grep -v 'Purged all caches'"
for t in t10-fitment t17-miss-flag t19-endpoint; do printf '%-16s ' $t; sshc "$WP eval-file claude-uploads/run-guarded.php $t 2>&1 | grep -E 'Fatal|====' | tail -1"; done
```

Expected: `==== 30 passed, 0 failed ====`; t10 `10 passed`, t17 `14 passed`, t19 `21 passed`, each `0 failed`.

- [ ] **Step 10: Commit**

```bash
git add durianbikers-chatbot/includes/class-lookup-engine.php durianbikers-chatbot/includes/class-handoff.php durianbikers-chatbot/includes/class-chat-endpoint.php
git commit -m "fix: asking for a person routes to WhatsApp, not the Terms page

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 2: Product ID on cards and the server-side "This one" pick

**Files:**
- Modify: `durianbikers-chatbot/includes/class-pending-order.php` (stage constants, `stages()`)
- Modify: `durianbikers-chatbot/includes/class-product-index.php` (`format_card()` ~1014; new `card_for()`)
- Modify: `durianbikers-chatbot/includes/class-product-finder.php` (`card()` ~1722)
- Modify: `durianbikers-chatbot/includes/class-lookup-engine.php` (`resume_pending()` ~3589; new `resume_chosen()`, `answer_pick()`, `pick_language()`; templates)
- Modify: `durianbikers-chatbot/includes/class-chat-endpoint.php` (route args; `handle_request()`; `handle_with_lookup()`)
- Test: `$SCRATCH/t27-pick.php`

**Interfaces:**
- Consumes: `DB_Chat_Cart_Writer::in_stock_variations( int ): array{variation_id,label,price}[]`, `DB_Chat_Cart_Writer::describe( int, int ): array{name,label,price,url}`.
- Produces:
  - `DB_Chat_Pending_Order::STAGE_CHOSEN = 'chosen'`.
  - Card arrays (index and finder) gain `'id' => int`.
  - `DB_Chat_Product_Index::card_for( int $product_id ): ?array`.
  - `DB_Chat_Lookup_Engine::answer_pick( int $product_id, array $history = array(), $pending = null ): array` — same shape as `answer()`.
  - REST param `pick` (optional; any scalar; a positive integer string selects a product, anything else is "couldn't find").
  - Template keys `pick_not_found`, `pick_info`, `pick_sizes`, `pick_in`, `pick_out`, `pick_buy`.

- [ ] **Step 1: Write the failing test**

Create `$SCRATCH/t27-pick.php`:

```php
<?php
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) { if ( false !== strpos( (string) $url, 'api.anthropic.com' ) ) { throw new RuntimeException( 'TEST TRIED TO CALL THE REAL ANTHROPIC API' ); } return $pre; }, 1, 3 );
add_filter( 'db_chat_lookup_log_unmatched', '__return_false' );
add_filter( 'db_chat_is_enabled', '__return_true', 1000 );
add_filter( 'db_chat_rate_limit', '__return_zero' );
add_filter( 'db_chat_rate_limit_ip', '__return_zero' );
add_filter( 'db_chat_mode', function () { return 'lookup'; } );
$pass = 0; $fail = 0;
function ck( $l, $ok, &$p, &$f ) { printf( "%s %s\n", $ok ? 'PASS' : 'FAIL', $l ); $ok ? $p++ : $f++; }

$index = new DB_Chat_Product_Index();
$pages = new DB_Chat_Content_Index();
$e     = new DB_Chat_Lookup_Engine( $index, $pages );
$VAR   = 58024; // MT Helmet THUNDER 4 SV (YAMANAKA 2025 A5 GLOSS), variable, in stock
$simple_ids = wc_get_products( array( 'type' => 'simple', 'status' => 'publish', 'stock_status' => 'instock', 'visibility' => 'visible', 'limit' => 1, 'return' => 'ids' ) );
$SIMPLE = (int) $simple_ids[0];
$pending = function ( $stage, array $extra = array() ) { return array_merge( array( 'stage' => $stage, 'product_id' => 0, 'variation_id' => 0, 'quantity' => 1, 'offered' => array(), 'created' => time(), 'language' => 'en' ), $extra ); };
$stage = function ( $r ) { return is_array( $r['pending'] ?? null ) ? (string) $r['pending']['stage'] : ''; };
$not_found = function ( $r ) { return false !== stripos( (string) $r['reply'], "couldn't find" ) && null === $r['pending']; };

wc_load_cart();
$cart_before = (int) WC()->cart->get_cart_contents_count();

// 1: cards carry the product id.
$r = $e->answer( 'shinko 705' );
ck( 'search cards carry a positive integer id', ! empty( $r['products'] ) && is_int( $r['products'][0]['id'] ?? null ) && $r['products'][0]['id'] > 0, $pass, $fail );

// 3: information pick, variable product.
$r = $e->answer_pick( $VAR );
ck( 'variable pick lists in-stock sizes and says buy', false !== strpos( $r['reply'], 'In stock:' ) && false !== stripos( $r['reply'], 'buy' ), $pass, $fail );
ck( 'variable pick returns the chosen stage for that product', 'chosen' === $stage( $r ) && $VAR === (int) $r['pending']['product_id'], $pass, $fail );
ck( 'variable pick shows exactly that product card', 1 === count( $r['products'] ) && $VAR === (int) $r['products'][0]['id'], $pass, $fail );

// 1: information pick, simple product.
$r = $e->answer_pick( $SIMPLE );
ck( 'simple pick says in stock and buy', false !== strpos( $r['reply'], 'In stock.' ) && false !== stripos( $r['reply'], 'buy' ), $pass, $fail );

// 2: pick answers "which one?".
$r = $e->answer_pick( $VAR, array(), $pending( 'ask_product', array( 'offered' => array( $VAR, $SIMPLE ), 'quantity' => 2 ) ) );
ck( 'pick in offered moves on to size or confirmation', in_array( $stage( $r ), array( 'ask_variation', 'ask_confirm' ), true ), $pass, $fail );
ck( 'pick in offered carries the quantity', 2 === (int) ( $r['pending']['quantity'] ?? 0 ), $pass, $fail );

// 1: pick not in offered -> information.
$r = $e->answer_pick( $VAR, array(), $pending( 'ask_product', array( 'offered' => array( 111, 222 ) ) ) );
ck( 'pick outside offered is an information pick', 'chosen' === $stage( $r ), $pass, $fail );

// 1: pick is never read as a size.
$r = $e->answer_pick( $VAR, array(), $pending( 'ask_variation', array( 'product_id' => $VAR ) ) );
ck( 'pick during a size question is an information pick', 'chosen' === $stage( $r ), $pass, $fail );

// 6: invalid picks.
ck( 'pick 0 is not found', $not_found( $e->answer_pick( 0 ) ), $pass, $fail );
ck( 'deleted product is not found', $not_found( $e->answer_pick( 999999999 ) ), $pass, $fail );
$only = function ( $hook, $value ) use ( $VAR ) { $f = function ( $v, $p ) use ( $VAR, $value ) { return $VAR === (int) $p->get_id() ? $value : $v; }; add_filter( $hook, $f, 10, 2 ); return $f; };
$f = $only( 'woocommerce_product_get_status', 'draft' );
ck( 'draft product is not found', $not_found( $e->answer_pick( $VAR ) ), $pass, $fail );
remove_filter( 'woocommerce_product_get_status', $f, 10 );
$f = $only( 'woocommerce_product_get_post_password', 'secret' );
ck( 'password-protected product is not found', $not_found( $e->answer_pick( $VAR ) ), $pass, $fail );
remove_filter( 'woocommerce_product_get_post_password', $f, 10 );
$f = $only( 'woocommerce_product_get_catalog_visibility', 'hidden' );
ck( 'hidden product is not found', $not_found( $e->answer_pick( $VAR ) ), $pass, $fail );
remove_filter( 'woocommerce_product_get_catalog_visibility', $f, 10 );
$variation_id = (int) ( ( new DB_Chat_Cart_Writer() )->in_stock_variations( $VAR )[0]['variation_id'] ?? 0 );
ck( 'a variation id is not a pickable product', $not_found( $e->answer_pick( $variation_id ) ), $pass, $fail );

// 5: the chosen stage.
$chosen = $pending( 'chosen', array( 'product_id' => $VAR ) );
$r = $e->answer( 'buy 2', array(), $chosen );
ck( 'chosen + "buy 2" asks size with quantity 2', in_array( $stage( $r ), array( 'ask_variation', 'ask_confirm' ), true ) && 2 === (int) $r['pending']['quantity'], $pass, $fail );
$r = $e->answer( 'nak beli', array(), $chosen );
ck( 'chosen + "nak beli" continues the purchase', in_array( $stage( $r ), array( 'ask_variation', 'ask_confirm' ), true ), $pass, $fail );
$r = $e->answer( '买', array(), $chosen );
ck( 'chosen + "买" continues the purchase', in_array( $stage( $r ), array( 'ask_variation', 'ask_confirm' ), true ), $pass, $fail );
$r = $e->answer( 'buy shinko 705', array(), $chosen );
ck( 'chosen + another product moves on', 'chosen' !== $stage( $r ) && ( empty( $r['pending'] ) || $VAR !== (int) ( $r['pending']['product_id'] ?? 0 ) ), $pass, $fail );
$r = $e->answer( 'yes', array(), $chosen );
ck( 'chosen + "yes" buys nothing and drops the stage', null === $r['pending'] && empty( $r['cart'] ), $pass, $fail );
$old = $pending( 'chosen', array( 'product_id' => $VAR, 'created' => time() - 2000 ) );
$r = $e->answer( 'buy', array(), $old );
ck( 'expired chosen stage does not continue', ! in_array( $stage( $r ), array( 'ask_variation', 'ask_confirm' ), true ), $pass, $fail );
ck( 'from_request accepts the chosen stage', null !== DB_Chat_Pending_Order::from_request( $chosen ), $pass, $fail );

// 4: endpoint.
$endpoint = new DB_Chat_Chat_Endpoint( $index, new DB_Chat_Claude_Client(), $pages, new DB_Chat_Order_Lookup(), $e );
$ask = function ( $message, $pick = null, $pend = null ) use ( $endpoint ) {
	$req = new WP_REST_Request( 'POST', '/durianbikers/v1/chat' );
	$req->set_param( 'message', $message );
	$req->set_param( 'history', array() );
	$req->set_param( 'pending', $pend );
	if ( null !== $pick ) { $req->set_param( 'pick', $pick ); }
	$res = $endpoint->handle_request( $req );
	return is_wp_error( $res ) ? array( 'error' => $res->get_error_code() ) : $res->get_data();
};
$d = $ask( 'some unrelated text', (string) $VAR );
ck( 'REST pick answers for the picked product, not the message', 'chosen' === ( $d['pending']['stage'] ?? '' ) && $VAR === (int) ( $d['pending']['product_id'] ?? 0 ), $pass, $fail );
$d = $ask( 'MT Helmet', 'abc' );
ck( 'REST junk pick is "couldn\'t find", not an error', ! isset( $d['error'] ) && false !== stripos( (string) ( $d['reply'] ?? '' ), "couldn't find" ), $pass, $fail );
$d = $ask( 'recommend', (string) $VAR );
ck( 'a pick never reaches the guided finder', ! array_key_exists( 'chips', $d ) && 'chosen' === ( $d['pending']['stage'] ?? '' ), $pass, $fail );
$d = $ask( 'shinko 705' );
ck( 'no pick: normal answer with cards', ! empty( $d['products'] ), $pass, $fail );

// 2: never a cart write, never an AI miss.
$e->answer_pick( $VAR );
ck( 'pick is never an AI miss', false === $e->needs_ai_fallback(), $pass, $fail );
ck( 'no pick wrote to the cart', $cart_before === (int) WC()->cart->get_cart_contents_count(), $pass, $fail );

echo "\n==== $pass passed, $fail failed ====\n";
```

- [ ] **Step 2: Run it to verify it fails**

```bash
sshc "cat > claude-uploads/t27-pick.php" < $SCRATCH/t27-pick.php
sshc "$WP eval-file claude-uploads/t27-pick.php 2>&1 | grep -v 'Purged all caches'"
```

Expected: `FAIL search cards carry a positive integer id`, then a fatal `Call to undefined method DB_Chat_Lookup_Engine::answer_pick()`. That is the expected failure.

- [ ] **Step 3: Add the stage**

In `class-pending-order.php`, replace:

```php
	/** Bot showed the summary and is waiting for a yes. */
	const STAGE_CONFIRM = 'ask_confirm';
```

with:

```php
	/** Bot showed the summary and is waiting for a yes. */
	const STAGE_CONFIRM = 'ask_confirm';

	/**
	 * Customer tapped "This one": a product is chosen, nothing is being bought.
	 * Only an explicit purchase message continues it, into the size question.
	 */
	const STAGE_CHOSEN = 'chosen';
```

and replace:

```php
		return array( self::STAGE_PRODUCT, self::STAGE_VARIATION, self::STAGE_CONFIRM );
```

with:

```php
		return array( self::STAGE_PRODUCT, self::STAGE_VARIATION, self::STAGE_CONFIRM, self::STAGE_CHOSEN );
```

- [ ] **Step 4: Card ids**

In `DB_Chat_Product_Index::format_card()`, replace:

```php
		return array(
			'name'     => wp_strip_all_tags( $product->get_name() ),
			'url'      => esc_url_raw( (string) $product->get_permalink() ),
			'price'    => $this->get_price_text( $product ),
```

with:

```php
		return array(
			'id'       => (int) $product->get_id(),
			'name'     => wp_strip_all_tags( $product->get_name() ),
			'url'      => esc_url_raw( (string) $product->get_permalink() ),
			'price'    => $this->get_price_text( $product ),
```

Add directly before `format_card()`'s docblock:

```php
	/**
	 * One card for a known product id, or null when it must not be shown.
	 *
	 * @param int $product_id Product id.
	 * @return array|null
	 */
	public function card_for( $product_id ) {
		return $this->format_card( (int) $product_id );
	}

```

In `DB_Chat_Product_Finder::card()`, replace:

```php
		return array(
			'name'     => wp_strip_all_tags( $product->get_name() ),
			'url'      => esc_url_raw( (string) $product->get_permalink() ),
			'price'    => $this->index->get_price_text( $product ),
```

with:

```php
		return array(
			'id'       => (int) $product->get_id(),
			'name'     => wp_strip_all_tags( $product->get_name() ),
			'url'      => esc_url_raw( (string) $product->get_permalink() ),
			'price'    => $this->index->get_price_text( $product ),
```

- [ ] **Step 5: The chosen stage can never reach the confirmation handler**

In `resume_pending()`, replace:

```php
		return $this->handle_confirmation( $message, $order, $language );
	}
```

with:

```php
		if ( DB_Chat_Pending_Order::STAGE_CHOSEN === $order->stage ) {
			return $this->resume_chosen( $message, $order, $language );
		}

		/*
		 * Only a real confirmation question may reach the handler that writes
		 * to a cart. A stage this method does not recognise must never be read
		 * as "waiting for a yes" — a stray "yes" would buy a product with no size.
		 */
		if ( DB_Chat_Pending_Order::STAGE_CONFIRM !== $order->stage ) {
			return null;
		}

		return $this->handle_confirmation( $message, $order, $language );
	}

	/**
	 * After "This one": continue only on an explicit purchase message that
	 * names no other product. Anything else drops the stage and is answered
	 * normally, the same "moving on" rule the other stages follow.
	 *
	 * @param string                $message  Customer's message.
	 * @param DB_Chat_Pending_Order $order    The chosen stage.
	 * @param string                $language Reply language.
	 * @return array|null
	 */
	private function resume_chosen( $message, DB_Chat_Pending_Order $order, $language ) {
		if ( $order->product_id < 1 ) {
			return null;
		}

		$normalized = $this->normalize( $message );

		if ( '' === $normalized ) {
			return null;
		}

		$filters = $this->blank_filters();
		$query   = trim( (string) $this->build_query( $normalized, $this->signals( $normalized ), $filters ) );

		if ( ! $filters['purchase'] && ! $filters['purchase_soft'] ) {
			return null;
		}

		// "i want 2" — a bare one- or two-digit count after a soft buy word.
		if ( 1 === preg_match( '/^[0-9]{1,2}$/', $query ) ) {
			$filters['quantity'] = (int) $query;
			$query               = '';
		}

		// build_query() hands back a recognised brand or category when nothing
		// else is left, so any non-empty residue means another product was named.
		if ( '' !== $query ) {
			return null;
		}

		return $this->ask_for_variation( $order->product_id, max( 1, (int) $filters['quantity'] ), $language );
	}
```

- [ ] **Step 6: `answer_pick()`**

Add directly after `answer()`:

```php
	/**
	 * The customer tapped "This one" on a card.
	 *
	 * The id is a claim, re-read from WooCommerce every time. While the bot is
	 * waiting for "which one?" and the tap is one of the offered products, it
	 * answers that question. Otherwise it is an information pick: price, stock
	 * and sizes, and a `chosen` stage so "buy" can continue with it. A pick
	 * never writes to a cart and is never an AI miss.
	 *
	 * @param int   $product_id Claimed product id.
	 * @param array $history    Prior turns, untrusted. Language only.
	 * @param mixed $pending    Raw pending order from the browser.
	 * @return array
	 */
	public function answer_pick( $product_id, array $history = array(), $pending = null ) {
		$this->order_turn  = false;
		$this->pending_out = null;
		$this->ai_fallback = false;

		$product_id = (int) $product_id;
		$order      = DB_Chat_Pending_Order::from_request( $pending );

		if ( null !== $order && $order->is_expired() ) {
			$order = null;
		}

		$language = ( null !== $order && '' !== $order->language ) ? $order->language : $this->pick_language( $history );
		$product  = $product_id > 0 ? wc_get_product( $product_id ) : null;

		if (
			! $product instanceof WC_Product
			|| $product instanceof WC_Product_Variation
			|| 'publish' !== $product->get_status()
			|| '' !== (string) $product->get_post_password()
			|| 'hidden' === $product->get_catalog_visibility()
		) {
			return $this->compose( $this->text( $language, 'pick_not_found' ), $language );
		}

		if ( null !== $order && DB_Chat_Pending_Order::STAGE_PRODUCT === $order->stage
			&& in_array( $product_id, array_map( 'absint', (array) $order->offered ), true ) ) {
			return $this->ask_for_variation( $product_id, $order->quantity, $language );
		}

		$writer  = new DB_Chat_Cart_Writer();
		$details = $writer->describe( $product_id, 0 );
		$lines   = array(
			'' !== $details['price']
				? $this->text( $language, 'pick_info', $details['name'], $details['price'] )
				: $details['name'] . '.',
		);

		if ( $product->is_type( 'variable' ) ) {
			$labels  = array_values( array_filter( array_map( 'strval', array_column( $writer->in_stock_variations( $product_id ), 'label' ) ) ) );
			$lines[] = empty( $labels ) ? $this->text( $language, 'pick_out' ) : $this->text( $language, 'pick_sizes', implode( ', ', $labels ) );
		} else {
			$lines[] = $product->is_in_stock() ? $this->text( $language, 'pick_in' ) : $this->text( $language, 'pick_out' );
		}

		$lines[] = $this->text( $language, 'pick_buy' );

		$this->pending_out = DB_Chat_Pending_Order::create(
			DB_Chat_Pending_Order::STAGE_CHOSEN,
			array(
				'product_id' => $product_id,
				'language'   => $language,
			)
		)->to_array();

		$card = $this->product_index->card_for( $product_id );

		return $this->compose( implode( "\n", $lines ), $language, null !== $card ? array( $card ) : array() );
	}

	/**
	 * Language for a pick: the customer's most recent own words.
	 *
	 * @param array $history Untrusted history.
	 * @return string
	 */
	private function pick_language( array $history ) {
		$turns = $this->history_user_turns( $history );

		return empty( $turns ) ? 'en' : self::detect_language( $turns[0] );
	}
```

- [ ] **Step 7: Pick copy**

English templates — after the `human_none` line added in Task 1, add:

```php
			'pick_not_found'     => __( "I couldn't find that product — can you tell me which one?", 'durianbikers-chatbot' ),
			'pick_info'          => __( '%1$s — %2$s.', 'durianbikers-chatbot' ),
			'pick_sizes'         => __( 'In stock: %s.', 'durianbikers-chatbot' ),
			'pick_in'            => __( 'In stock.', 'durianbikers-chatbot' ),
			'pick_out'           => __( 'Out of stock right now.', 'durianbikers-chatbot' ),
			'pick_buy'           => __( 'Say "buy" to add it to your cart.', 'durianbikers-chatbot' ),
```

Malay templates — after the Malay `human_none` line, add:

```php
			'pick_not_found'     => 'Saya tidak jumpa produk itu — boleh beritahu yang mana satu?',
			'pick_sizes'         => 'Ada stok: %s.',
			'pick_in'            => 'Ada stok.',
			'pick_out'           => 'Stok habis buat masa ini.',
			'pick_buy'           => 'Taip "beli" untuk masukkan ke dalam troli.',
```

- [ ] **Step 8: The `pick` param**

In `register_routes()`, replace:

```php
					'pending' => array(
						'required'          => false,
						'default'           => null,
```

with:

```php
					/*
					 * "This one" on a card. Deliberately NO default: has_param()
					 * must stay false when the widget did not send it. Any scalar
					 * is accepted so a junk value answers "couldn't find" instead
					 * of a 400 — the id is a claim either way.
					 */
					'pick'    => array(
						'required'          => false,
						'validate_callback' => static function ( $value ) {
							return null === $value || is_scalar( $value );
						},
					),
					'pending' => array(
						'required'          => false,
						'default'           => null,
```

In `handle_request()`, replace:

```php
			return $this->handle_with_lookup( $message, $history, $request->get_param( 'pending' ) );
```

with:

```php
			return $this->handle_with_lookup(
				$message,
				$history,
				$request->get_param( 'pending' ),
				$request->get_param( 'pick' )
			);
```

In `handle_with_lookup()`, replace the signature line:

```php
	private function handle_with_lookup( $message, array $history, $pending = null ) {
```

with:

```php
	private function handle_with_lookup( $message, array $history, $pending = null, $pick = null ) {
```

Replace the finder guard:

```php
		if ( class_exists( 'DB_Chat_Product_Finder' ) ) {
```

with:

```php
		// A pick names its product exactly; the guided finder has nothing to add.
		if ( null === $pick && class_exists( 'DB_Chat_Product_Finder' ) ) {
```

and replace:

```php
		try {
			$result = $this->lookup_engine->answer( $message, $history, $pending );
		} catch ( Throwable $e ) {
```

with:

```php
		try {
			$result = null !== $pick
				? $this->lookup_engine->answer_pick( self::pick_id( $pick ), $history, $pending )
				: $this->lookup_engine->answer( $message, $history, $pending );
		} catch ( Throwable $e ) {
```

Add this method directly after `handle_with_lookup()`'s closing brace:

```php
	/**
	 * A positive integer from the raw pick, or 0 for anything else.
	 *
	 * @param mixed $pick Raw value.
	 * @return int
	 */
	private static function pick_id( $pick ) {
		if ( ! is_scalar( $pick ) || 1 !== preg_match( '/^\s*([0-9]{1,10})\s*$/', (string) $pick, $m ) ) {
			return 0;
		}

		return (int) $m[1];
	}
```

- [ ] **Step 9: Lint, deploy, run**

```bash
cd /Users/superfly2026/sites/durianbikers
for f in includes/class-pending-order.php includes/class-product-index.php includes/class-product-finder.php includes/class-lookup-engine.php includes/class-chat-endpoint.php; do /opt/homebrew/opt/php@8.3/bin/php -l durianbikers-chatbot/$f || break; done
for f in includes/class-pending-order.php includes/class-product-index.php includes/class-product-finder.php includes/class-lookup-engine.php includes/class-chat-endpoint.php; do safe_deploy $f task2 || break; done
sshc "$WP eval-file claude-uploads/t27-pick.php 2>&1 | grep -v 'Purged all caches'"
for t in t3-pending t5-flow t26-human; do printf '%-14s ' $t; sshc "$WP eval-file claude-uploads/run-guarded.php $t 2>&1 | grep -E 'Fatal|====' | tail -1"; done
```

Deploy order matters: the pending-order class first (the engine references `STAGE_CHOSEN`).

Expected: `==== 28 passed, 0 failed ====`; t3 `17 passed`, t5 `19 passed`, t26 `30 passed`, each `0 failed`.

- [ ] **Step 10: Commit**

```bash
git add durianbikers-chatbot/includes/class-pending-order.php durianbikers-chatbot/includes/class-product-index.php durianbikers-chatbot/includes/class-product-finder.php durianbikers-chatbot/includes/class-lookup-engine.php durianbikers-chatbot/includes/class-chat-endpoint.php
git commit -m "feat: product id on cards and a validated This-one pick

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 3: Widget — This one button, card link, remembered conversation

**Files:**
- Modify: `durianbikers-chatbot/assets/chatbot.js`
- Modify: `durianbikers-chatbot/assets/chatbot.css`
- Modify: `durianbikers-chatbot/durianbikers-chatbot.php` (`get_script_data()` data array)
- Test: `$SCRATCH/widget-harness/{index.html,harness.js,runner.js}` (+ copies of chatbot.js/css made at run time)
- Tooling (not committed): `.claude/launch.json` entry `widget-harness`

**Interfaces:**
- Consumes: card `id` (Task 2), REST `pick` (Task 2), response `pending` stage `chosen` (Task 2).
- Produces: `dbChat.store` (`'my'`, `'gb'` or `''`); request body key `pick` (number) only on a This one tap; `sessionStorage` key `db_chat_state_v1_{store}` holding `{ v:1, savedAt, open, history, pending, transcript }`; CSS classes `.db-chat-product-main` (link) and `.db-chat-product-pick` (button). `.db-chat-product-link` no longer exists.

- [ ] **Step 1: Write the failing widget harness**

Create `$SCRATCH/widget-harness/index.html`:

```html
<!doctype html>
<html><head><meta charset="utf-8"><title>widget harness</title></head>
<body>
<pre id="out"></pre>
<script src="harness.js"></script>
<script src="chatbot.js"></script>
<script src="runner.js"></script>
</body></html>
```

Create `$SCRATCH/widget-harness/harness.js` (config + a mocked backend; no network):

```js
(function () {
	var store = new URLSearchParams(location.search).get('store') || 'my';
	window.dbChat = { ajaxUrl: location.origin + '/mock/durianbikers/v1/chat', tokenHeader: 'X-DB-Chat-Session', greeting: 'Hi', suggestions: [], store: store };
	window.__requests = [];
	window.__errors = 0;
	window.addEventListener('error', function () { window.__errors += 1; });
	var CARD = { id: 58024, name: 'MT Helmet THUNDER 4 SV (YAMANAKA 2025 A5 GLOSS)', url: location.origin + '/product/mt-thunder', price: 'RM599.00', image: '', in_stock: true };
	var NOID = { name: 'Card Without Id', url: location.origin + '/product/no-id', price: 'RM10.00', image: '', in_stock: true };
	function json(data) { return Promise.resolve(new Response(JSON.stringify(data), { status: 200, headers: { 'Content-Type': 'application/json' } })); }
	window.fetch = function (url, opts) {
		if (String(url).indexOf('/session') !== -1) { return json({ nonce: 'test-nonce' }); }
		var body = {};
		try { body = JSON.parse((opts && opts.body) || '{}'); } catch (e) { body = {}; }
		window.__requests.push(body);
		if (body.pick) {
			return json({ reply: CARD.name + ' — RM599.00.\nIn stock: M, L.\nSay "buy" to add it to your cart.', products: [CARD], pages: [], pending: { stage: 'chosen', product_id: 58024, variation_id: 0, quantity: 1, offered: [], created: Math.floor(Date.now() / 1000), language: 'en' } });
		}
		if (/order/i.test(body.message || '')) { return json({ reply: 'Please sign in to see your orders.', products: [], pages: [], pending: null }); }
		if (/yamanaka/i.test(body.message || '')) { return json({ reply: 'Here is what I found:', products: [CARD, NOID], pages: [], pending: null }); }
		return json({ reply: 'ok', products: [], pages: [], pending: body.pending || null });
	};
})();
```

Create `$SCRATCH/widget-harness/runner.js` (runs across reloads; results accumulate in `sessionStorage`):

```js
(function () {
	var out = document.getElementById('out');
	var params = new URLSearchParams(location.search);
	var KEY = 'db_chat_state_v1_' + (params.get('store') || 'my');
	var phase = sessionStorage.getItem('harness_phase') || '1';
	var pass = Number(sessionStorage.getItem('harness_pass') || 0);
	var fail = Number(sessionStorage.getItem('harness_fail') || 0);
	out.textContent = sessionStorage.getItem('harness_out') || '';
	function ck(label, ok) { out.textContent += (ok ? 'PASS ' : 'FAIL ') + label + '\n'; if (ok) { pass++; } else { fail++; } }
	function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
	function root() { var h = document.getElementById('db-chat-host'); return h ? (h.shadowRoot || h) : document; }
	function q(sel) { return root().querySelector(sel); }
	function qa(sel) { return root().querySelectorAll(sel); }
	function type(text) { q('.db-chat-input').value = text; q('.db-chat-send').click(); }
	function saved() { try { return JSON.parse(sessionStorage.getItem(KEY)); } catch (e) { return null; } }
	function keep(next) { sessionStorage.setItem('harness_pass', pass); sessionStorage.setItem('harness_fail', fail); sessionStorage.setItem('harness_out', out.textContent); sessionStorage.setItem('harness_phase', next); }
	function carry(next) { keep(next); location.reload(); }
	function finish() {
		out.textContent += '\n==== ' + pass + ' passed, ' + fail + ' failed ====\n';
		window.__result = out.textContent;
		['harness_phase', 'harness_pass', 'harness_fail', 'harness_out', 'db_chat_state_v1_my', 'db_chat_state_v1_gb'].forEach(function (k) { sessionStorage.removeItem(k); });
	}

	async function run() {
		await wait(400);
		if (phase === '1') {
			sessionStorage.removeItem(KEY);
			q('.db-chat-launcher').click(); await wait(200);
			type('show yamanaka'); await wait(500);
			var cards = qa('.db-chat-product');
			var main = cards[0] && cards[0].querySelector('a.db-chat-product-main');
			ck('card is a same-tab link to the product page', cards.length === 2 && !!main && main.getAttribute('href').indexOf('/product/mt-thunder') !== -1 && !main.hasAttribute('target'));
			ck('the old View link is gone', !q('.db-chat-product-link'));
			var pick = cards[0] && cards[0].querySelector('button.db-chat-product-pick');
			ck('card with an id has a This one button', !!pick && pick.textContent === 'This one');
			ck('card without an id has no button', !!cards[1] && !cards[1].querySelector('button.db-chat-product-pick'));
			pick.click(); await wait(500);
			var last = window.__requests[window.__requests.length - 1];
			ck('tap sends the exact product id', !!last && last.pick === 58024);
			ck('tap sends the product name as the message', !!last && last.message === 'MT Helmet THUNDER 4 SV (YAMANAKA 2025 A5 GLOSS)');
			var users = qa('.db-chat-msg-user .db-chat-bubble');
			ck('customer bubble shows the product name', users.length > 0 && users[users.length - 1].textContent === last.message);
			var s = saved();
			ck('conversation saved with transcript, pending and open state', !!s && s.v === 1 && s.open === true && s.transcript.length === 4 && !!s.pending && s.pending.stage === 'chosen');
			return carry('2');
		}
		if (phase === '2') {
			var users2 = qa('.db-chat-msg-user .db-chat-bubble');
			ck('after reload the transcript is back', users2.length === 2 && users2[1].textContent.indexOf('YAMANAKA') !== -1);
			ck('after reload the cards and pick buttons are back', qa('.db-chat-product button.db-chat-product-pick').length >= 2);
			ck('after reload the open panel reopens', q('.db-chat-root').classList.contains('is-open'));
			type('buy'); await wait(500);
			var last2 = window.__requests[window.__requests.length - 1];
			ck('restored pending order is sent with the next message', !!last2 && !!last2.pending && last2.pending.stage === 'chosen');
			type('where is my order #1042'); await wait(500);
			ck('an order question deletes the saved conversation', sessionStorage.getItem(KEY) === null);
			return carry('3');
		}
		if (phase === '3') {
			ck('after an order question nothing comes back on reload', qa('.db-chat-msg-user').length === 0);
			sessionStorage.setItem(KEY, JSON.stringify({ v: 1, savedAt: Date.now() - 31 * 60 * 1000, open: true, history: [{ role: 'user', content: 'old' }], pending: null, transcript: [{ role: 'user', text: 'old' }, { role: 'bot', text: 'old reply', products: [], pages: [], chips: [] }] }));
			return carry('4');
		}
		if (phase === '4') {
			ck('a conversation older than 30 minutes is discarded', qa('.db-chat-msg-user').length === 0 && sessionStorage.getItem(KEY) === null);
			sessionStorage.setItem(KEY, '{not json');
			return carry('5');
		}
		if (phase === '5') {
			ck('a corrupt saved conversation is ignored without errors', qa('.db-chat-msg-user').length === 0 && window.__errors === 0);
			sessionStorage.setItem('db_chat_state_v1_my', JSON.stringify({ v: 1, savedAt: Date.now(), open: true, history: [], pending: null, transcript: [{ role: 'user', text: 'kept for my' }, { role: 'bot', text: 'reply', products: [], pages: [], chips: [] }] }));
			keep('6');
			location.search = '?store=gb';
			return;
		}
		if (phase === '6') {
			ck('the gb store does not restore the my conversation', qa('.db-chat-msg-user').length === 0);
			sessionStorage.setItem('db_chat_state_v1_gb', JSON.stringify({ v: 1, savedAt: Date.now(), open: false, history: [], pending: null, transcript: [{ role: 'user', text: 'closed one' }, { role: 'bot', text: 'reply', products: [], pages: [], chips: [] }] }));
			return carry('7');
		}
		if (phase === '7') {
			ck('a saved closed conversation is restored', qa('.db-chat-msg-user').length === 1);
			ck('a closed panel stays closed after reload', !q('.db-chat-root').classList.contains('is-open'));
			return finish();
		}
	}
	run();
})();
```

Add to `.claude/launch.json` (create the file if absent, keeping `"version": "0.0.1"`):

```json
{
  "name": "widget-harness",
  "runtimeExecutable": "python3",
  "runtimeArgs": ["-m", "http.server", "8765", "--directory", "/private/tmp/claude-501/-Users-superfly2026-sites-durianbikers/33a62eb6-8bc3-4efe-bd0a-a5fb95e120e4/scratchpad/widget-harness"],
  "port": 8765
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cp durianbikers-chatbot/assets/chatbot.js durianbikers-chatbot/assets/chatbot.css $SCRATCH/widget-harness/
```

Then `preview_start` with name `widget-harness`, `navigate` to `http://localhost:8765/index.html?store=my`, wait ~6 seconds, and read the result with `javascript_tool`: `window.__result || document.getElementById('out').textContent`.

Expected: phase 1 FAILs (`card is a same-tab link…`, `the old View link is gone`, `This one button`) and the runner throws on `pick.click()` because there is no button. That is the expected failure. Before re-running, clear leftovers with `javascript_tool`: `sessionStorage.clear()`.

- [ ] **Step 3: Store key in script data**

In `get_script_data()`, replace:

```php
			'maxHistory'    => DB_Chat_Chat_Endpoint::MAX_HISTORY,
```

with:

```php
			'maxHistory'    => DB_Chat_Chat_Endpoint::MAX_HISTORY,
			// Which storefront this page belongs to ('my', 'gb', or '' on the
			// splash). Keys the saved conversation, so MYR and USD chats never
			// mix. From the live theme's store routing when present.
			'store'         => function_exists( 'db_effective_store_prefix' ) ? (string) db_effective_store_prefix() : '',
```

- [ ] **Step 4: Text, constants and state**

In `TEXT`, replace:

```js
		view: cfg.viewLabel || 'View',
```

with:

```js
		view: cfg.viewLabel || 'View',
		pick: cfg.pickLabel || 'This one',
```

Replace the state comment:

```js
	// --- STATE --- //
	// Transcript lives in memory only. The only thing that touches browser
	// storage is the rate-limit client token (sessionStorage), below.
```

with:

```js
	// --- STATE --- //
	// Browser storage holds two things, both sessionStorage (dies with the
	// tab): the rate-limit client token, and the saved conversation described
	// under CONVERSATION PERSISTENCE — which is never written once orders come up.
```

Replace:

```js
	var ordersTouched = false;
```

with:

```js
	var ordersTouched = false;
	// Rendered conversation, for restoring it on the next page of this tab.
	var transcript = [];
	var restoring = false;
	// Extra request fields for a retry of the last message (the pick id).
	var lastAttemptExtra = null;
```

- [ ] **Step 5: Orders wipe the saved conversation**

Replace:

```js
		if (ORDER_HINT.test(text)) {
			ordersTouched = true;
		}
```

with:

```js
		if (ORDER_HINT.test(text)) {
			ordersTouched = true;
			clearSavedState();
		}
```

Replace:

```js
		if (used && used.indexOf('get_my_orders') !== -1) {
			ordersTouched = true;
		}
```

with:

```js
		if (used && used.indexOf('get_my_orders') !== -1) {
			ordersTouched = true;
			clearSavedState();
		}
```

- [ ] **Step 6: Persistence functions**

Insert directly before `	function trimmed(value) {`:

```js
	// --- CONVERSATION PERSISTENCE --- //
	/*
	 * Kept in sessionStorage so tapping a product card can open the product
	 * page in the same tab without losing the chat. Per tab, per store, 30
	 * minutes, 100 KB. Never once orders come up: the moment they do, what was
	 * saved is deleted and nothing more is written for this page's lifetime.
	 * Everything read back is rendered through the same textContent builders
	 * as a live reply.
	 */
	var STATE_VERSION = 1;
	var STATE_MAX_AGE = 30 * 60 * 1000;
	var STATE_MAX_BYTES = 100000;
	var STATE_MAX_ENTRIES = MAX_HISTORY_TURNS * 2;

	function stateKey() {
		var store = typeof cfg.store === 'string' ? cfg.store.trim().toLowerCase() : '';
		if (!/^[a-z]{2,8}$/.test(store)) {
			var match = /^\/([a-z]{2})(\/|$)/.exec(window.location.pathname || '');
			store = match ? match[1] : 'default';
		}
		return 'db_chat_state_v' + STATE_VERSION + '_' + store;
	}

	function clearSavedState() {
		try {
			window.sessionStorage.removeItem(stateKey());
		} catch (e) { /* storage unavailable */ }
	}

	function saveState() {
		if (restoring) {
			return;
		}
		if (ordersTouched) {
			clearSavedState();
			return;
		}
		var entries = transcript.slice(-STATE_MAX_ENTRIES).map(function (entry, i, list) {
			// Chips are only valid on the last turn.
			if (entry.role !== 'bot' || i === list.length - 1) {
				return entry;
			}
			return { role: 'bot', text: entry.text, products: entry.products, pages: entry.pages, chips: [] };
		});
		var payload;
		try {
			payload = JSON.stringify({ v: STATE_VERSION, savedAt: Date.now(), open: isOpen, history: history, pending: pendingOrder, transcript: entries });
		} catch (e) {
			return;
		}
		if (payload.length > STATE_MAX_BYTES) {
			clearSavedState();
			return;
		}
		try {
			window.sessionStorage.setItem(stateKey(), payload);
		} catch (e) { /* private mode or quota: the chat still works in memory */ }
	}

	function readSavedState() {
		var raw = null;
		try {
			raw = window.sessionStorage.getItem(stateKey());
		} catch (e) {
			return null;
		}
		if (!raw) {
			return null;
		}
		var data = null;
		try {
			data = raw.length <= STATE_MAX_BYTES ? JSON.parse(raw) : null;
		} catch (e) {
			data = null;
		}
		var age = data && typeof data.savedAt === 'number' ? Date.now() - data.savedAt : -1;
		var valid = !!data && typeof data === 'object' && data.v === STATE_VERSION &&
			age >= 0 && age <= STATE_MAX_AGE &&
			Array.isArray(data.history) && Array.isArray(data.transcript);
		if (!valid) {
			clearSavedState();
			return null;
		}
		return data;
	}

	function restoreState() {
		var data = readSavedState();
		if (!data) {
			return;
		}
		restoring = true;
		try {
			var entries = data.transcript.slice(-STATE_MAX_ENTRIES);
			entries.forEach(function (entry, i) {
				if (!entry || typeof entry.text !== 'string' || !entry.text.trim()) {
					return;
				}
				if (entry.role === 'user') {
					addUserMessage(entry.text);
					transcript.push({ role: 'user', text: entry.text });
					return;
				}
				if (entry.role !== 'bot') {
					return;
				}
				var products = Array.isArray(entry.products) ? entry.products : [];
				var pages = Array.isArray(entry.pages) ? entry.pages : [];
				var chips = Array.isArray(entry.chips) ? entry.chips : [];
				addBotMessage(entry.text);
				addProducts(products);
				addPages(pages);
				if (i === entries.length - 1) {
					addChips(chips);
				}
				transcript.push({ role: 'bot', text: entry.text, products: products, pages: pages, chips: chips });
			});
			history = data.history.filter(function (turn) {
				return turn && (turn.role === 'user' || turn.role === 'assistant') && typeof turn.content === 'string';
			}).slice(-(MAX_HISTORY_TURNS * 2));
			pendingOrder = data.pending && typeof data.pending === 'object' ? data.pending : null;
			if (transcript.length && els.suggestions) {
				els.suggestions.hidden = true;
			}
		} finally {
			restoring = false;
		}
		if (data.open === true) {
			openPanel();
		}
	}

```

- [ ] **Step 7: Restore on build; save on open and close**

In `build()`, replace:

```js
		renderHandoff();
		addBotMessage(TEXT.greeting);
	}
```

with:

```js
		renderHandoff();
		addBotMessage(TEXT.greeting);
		restoreState();
	}
```

In `openPanel()`, replace:

```js
		document.addEventListener('keydown', onKeydown, true);
```

with:

```js
		document.addEventListener('keydown', onKeydown, true);
		saveState();
```

In `closePanel()`, replace:

```js
		document.removeEventListener('keydown', onKeydown, true);
```

with:

```js
		document.removeEventListener('keydown', onKeydown, true);
		saveState();
```

- [ ] **Step 8: The card**

Replace the whole `buildProductCard` function (from `	function buildProductCard(product) {` through its `		return card;\n	}`) with:

```js
	function buildProductCard(product) {
		var card = el('li', 'db-chat-product');
		var href = safeUrl(product.url);

		// Thumbnail and text are one link, same tab. The conversation is saved
		// on every turn, so it reopens on the product page exactly as it was.
		var main = el(href ? 'a' : 'div', 'db-chat-product-main');
		if (href) {
			main.setAttribute('href', href);
			main.addEventListener('click', function () {
				saveState();
			});
		}

		var thumb = el('div', 'db-chat-product-thumb');
		var imageSrc = safeUrl(product.image);
		if (imageSrc) {
			var img = document.createElement('img');
			img.setAttribute('src', imageSrc);
			img.setAttribute('alt', '');
			img.setAttribute('loading', 'lazy');
			img.setAttribute('decoding', 'async');
			img.addEventListener('error', function () {
				thumb.replaceChildren(icon(ICONS.image));
			});
			thumb.appendChild(img);
		} else {
			thumb.appendChild(icon(ICONS.image));
		}
		main.appendChild(thumb);

		var body = el('div', 'db-chat-product-body');
		body.appendChild(el('p', 'db-chat-product-name', product.name));

		var meta = el('div', 'db-chat-product-meta');
		// Price arrives pre-formatted in MYR from WooCommerce. Never build a
		// currency string here.
		if (typeof product.price === 'string' && product.price.trim()) {
			meta.appendChild(el('span', 'db-chat-product-price', product.price.trim()));
		}
		var inStock = product.in_stock === true;
		meta.appendChild(el(
			'span',
			'db-chat-pill ' + (inStock ? 'db-chat-pill-in' : 'db-chat-pill-out'),
			inStock ? TEXT.inStock : TEXT.outOfStock
		));
		body.appendChild(meta);
		main.appendChild(body);
		card.appendChild(main);

		// "This one": tells the bot exactly which product. A sibling of the
		// link, never inside it. Only for a real positive integer id — the
		// server re-checks the product either way.
		var id = typeof product.id === 'number' && product.id > 0 && Math.floor(product.id) === product.id ? product.id : 0;
		if (id) {
			var pick = el('button', 'db-chat-product-pick', TEXT.pick);
			pick.type = 'button';
			pick.setAttribute('aria-label', TEXT.pick + ': ' + product.name);
			pick.addEventListener('click', function () {
				send(product.name, { pick: id });
			});
			card.appendChild(pick);
		}

		return card;
	}
```

- [ ] **Step 9: Send the pick**

Replace:

```js
	function postChat(message, nonce) {
```

with:

```js
	function postChat(message, nonce, extra) {
```

Replace:

```js
			body: JSON.stringify({
				message: message,
				history: history.slice(-MAX_HISTORY_SENT),
				pending: pendingOrder
			})
```

with:

```js
			body: JSON.stringify(extra && extra.pick ? {
				message: message,
				history: history.slice(-MAX_HISTORY_SENT),
				pending: pendingOrder,
				pick: extra.pick
			} : {
				message: message,
				history: history.slice(-MAX_HISTORY_SENT),
				pending: pendingOrder
			})
```

Replace the whole `dispatch` function:

```js
	function dispatch(message, attempt) {
		return fetchNonce(attempt > 0).then(function (nonce) {
			return postChat(message, nonce);
		}).then(function (result) {
			if (attempt === 0 && isStaleNonce(result)) {
				return dispatch(message, 1);
			}
			return result;
		});
	}
```

with:

```js
	function dispatch(message, attempt, extra) {
		return fetchNonce(attempt > 0).then(function (nonce) {
			return postChat(message, nonce, extra);
		}).then(function (result) {
			if (attempt === 0 && isStaleNonce(result)) {
				return dispatch(message, 1, extra);
			}
			return result;
		});
	}
```

In `send()`: replace `	function send(rawValue) {` with `	function send(rawValue, extra) {`; replace

```js
		lastAttempt = message;
```

with

```js
		lastAttempt = message;
		lastAttemptExtra = extra && extra.pick ? { pick: extra.pick } : null;
		var sentExtra = lastAttemptExtra;
```

replace

```js
		dispatch(message, 0).then(function (result) {
			hideTyping();
			setBusy(false);
			handleResponse(result, message);
```

with

```js
		dispatch(message, 0, sentExtra).then(function (result) {
			hideTyping();
			setBusy(false);
			handleResponse(result, message, sentExtra);
```

and replace

```js
					send(lastAttempt);
```

with

```js
					send(lastAttempt, lastAttemptExtra);
```

In `handleResponse()`: replace `	function handleResponse(result, sentMessage) {` with `	function handleResponse(result, sentMessage, sentExtra) {`, replace **every** `send(sentMessage);` inside it with `send(sentMessage, sentExtra);` (there are three), and replace:

```js
		if (history.length > MAX_HISTORY_TURNS * 2) {
			history = history.slice(-(MAX_HISTORY_TURNS * 2));
		}
	}
```

with:

```js
		if (history.length > MAX_HISTORY_TURNS * 2) {
			history = history.slice(-(MAX_HISTORY_TURNS * 2));
		}

		transcript.push({ role: 'user', text: sentMessage });
		transcript.push({
			role: 'bot',
			text: reply,
			products: data && Array.isArray(data.products) ? data.products : [],
			pages: data && Array.isArray(data.pages) ? data.pages : [],
			chips: data && Array.isArray(data.chips) ? data.chips : []
		});
		if (transcript.length > STATE_MAX_ENTRIES) {
			transcript = transcript.slice(-STATE_MAX_ENTRIES);
		}
		saveState();
	}
```

- [ ] **Step 10: CSS**

Replace:

```css
.db-chat-product-link {
```

with:

```css
.db-chat-product-main {
    flex: 1 1 auto;
    min-width: 0;
    display: flex;
    align-items: stretch;
    gap: 12px;
    color: inherit;
    text-decoration: none;
    border-radius: 8px;
}

.db-chat-product-main:focus-visible {
    outline: 2px solid var(--db-chat-brand);
    outline-offset: 2px;
}

.db-chat-product-pick {
    border: 0;
    cursor: pointer;
    font-family: inherit;
```

Replace:

```css
.db-chat-product-link:hover,
.db-chat-product-link:focus {
```

with:

```css
.db-chat-product-pick:hover,
.db-chat-product-pick:focus {
```

In the mobile block, replace:

```css
    .db-chat-product-link {
        padding: 7px 12px;
    }
```

with:

```css
    .db-chat-product-pick {
        min-height: 44px;
        padding: 7px 12px;
    }
```

Leave the `/* v2 option: an "Add to cart" action … */` comment above `.db-chat-products` unchanged: the This one button never touches a cart, so the comment stays true.

- [ ] **Step 11: Run the harness to verify it passes**

```bash
node --check durianbikers-chatbot/assets/chatbot.js
cp durianbikers-chatbot/assets/chatbot.js durianbikers-chatbot/assets/chatbot.css $SCRATCH/widget-harness/
```

In the Browser pane: `javascript_tool` `sessionStorage.clear()`, then `navigate` to `http://localhost:8765/index.html?store=my`, wait ~8 seconds (seven phases reload themselves), then read `window.__result`.

Expected: `==== 19 passed, 0 failed ====`. Also `read_console_messages` with `onlyErrors: true` shows nothing from chatbot.js. Take a screenshot of phase 7's final page for the operator (`resize_window` preset `mobile`, reload the harness once more, screenshot, then preset `desktop`).

- [ ] **Step 12: Deploy**

`safe_deploy` lints with PHP, which a JS or CSS file fails. Use this variant for assets:

```bash
safe_deploy_asset () {  # plugin-relative path, task tag
  local rel=$1 tag=$2 rem=$PLUG/$1 bak=claude-uploads/$(basename $1).bak-$2
  health || { echo "UNHEALTHY BEFORE DEPLOY"; return 1; }
  sshc "cp $rem $bak" && sshc "cat > $rem.new" < durianbikers-chatbot/$rel && sshc "mv $rem.new $rem" || return 1
  [ "$(md5 -q durianbikers-chatbot/$rel)" = "$(sshc "md5sum $rem | cut -d' ' -f1")" ] || { echo "CHECKSUM MISMATCH"; return 1; }
  health || { sshc "cp $bak $rem"; echo "RESTORED $rel"; health; return 1; }
}
cd /Users/superfly2026/sites/durianbikers
/opt/homebrew/opt/php@8.3/bin/php -l durianbikers-chatbot/durianbikers-chatbot.php
safe_deploy durianbikers-chatbot.php task3 && safe_deploy_asset assets/chatbot.css task3 && safe_deploy_asset assets/chatbot.js task3
for t in t18-mode t20-copy t26-human t27-pick; do printf '%-12s ' $t; sshc "$WP eval-file claude-uploads/run-guarded.php $t 2>&1 | grep -E 'Fatal|====' | tail -1"; done
```

`safe_deploy` with a top-level path: call it as `safe_deploy durianbikers-chatbot.php task3` — `$PLUG/durianbikers-chatbot.php` is correct because `rel` is plugin-relative.

Expected: every deploy verified and healthy; t18 `13 passed`, t20 `10 passed`, t26 `30 passed`, t27 `28 passed`, each `0 failed`. The script is enqueued with `filemtime` as its version, so browsers fetch the new file without a cache purge.

Then tell the operator exactly what to try on the private test page through their secret link: search `i want yamanaka 2pc`, tap **This one**, tap a card and come back, ask `where is my order`, and reload.

- [ ] **Step 13: Commit**

```bash
git add durianbikers-chatbot/assets/chatbot.js durianbikers-chatbot/assets/chatbot.css durianbikers-chatbot/durianbikers-chatbot.php
git commit -m "feat: This one button, same-tab card link and remembered chat

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 4: Brand narrowing in the guided finder

**Files:**
- Modify: `durianbikers-chatbot/includes/class-product-finder.php` (constants after `STEP_RESULTS`; `maybe_handle()`; new NARROWING section before `Build the reply for a step.`; `text()`; file end)
- Test: `$SCRATCH/t28-narrow.php`

**Interfaces:**
- Consumes: finder privates `clean_history()`, `normalize()`, `detect_language()`, `stopwords()`, `fillers()`, `match_forms()`, `hint()`, `payload()`, `chips_from()`, `card()`, `text()`; `DB_Chat_Product_Index::get_brand_taxonomy()`; `DB_Chat_Lookup_Engine::purchase_terms()`, `purchase_soft_terms()`, `browse_terms()`, `chinese_terms()`; card `id` (Task 2).
- Produces:
  - Constants `NARROW_MIN_PRODUCTS = 5`, `NARROW_STOP = 4`, `NARROW_DOMINANT = 0.8`, `NARROW_TRANSIENT = 'db_chat_narrow_'`, `NARROW_VERSION_OPTION = 'db_chat_narrow_version'`, `NARROW_FACETS = array( 'type', 'make', 'size', 'colour' )`.
  - Public static extractors `narrow_family( string $name, string $brand ): string`, `narrow_make( string $name ): string`, `narrow_series( string $name ): string`, `narrow_size( string $name ): string`, `narrow_colour( string $name ): string`, and `bump_narrow_version(): void`.
  - A narrowing turn is a normal finder payload `{ reply, products, pages, chips }`; question first lines are stable strings (`Which {Brand} product?` / `{Brand} yang mana satu?`, `For which bike?` / `Untuk motor apa?`, `Which series?` / `Siri yang mana?`, `Which size?` / `Saiz berapa?`, `Which colour?` / `Warna apa?`), second line `{n} in stock.` / `{n} ada stok.`

**Refinement of spec §7.4, on purpose:** the count moves to the second line (`Which Barkbusters product?` then `94 in stock.`). The first line is what history replay matches, so it must not change when stock changes between two turns.

- [ ] **Step 1: Write the failing test**

Create `$SCRATCH/t28-narrow.php`:

```php
<?php
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) { if ( false !== strpos( (string) $url, 'api.anthropic.com' ) ) { throw new RuntimeException( 'TEST TRIED TO CALL THE REAL ANTHROPIC API' ); } return $pre; }, 1, 3 );
add_filter( 'db_chat_lookup_log_unmatched', '__return_false' );
add_filter( 'db_chat_is_enabled', '__return_true', 1000 );
add_filter( 'db_chat_rate_limit', '__return_zero' );
add_filter( 'db_chat_rate_limit_ip', '__return_zero' );
add_filter( 'db_chat_mode', function () { return 'lookup'; } );
$pass = 0; $fail = 0;
function ck( $l, $ok, &$p, &$f ) { printf( "%s %s\n", $ok ? 'PASS' : 'FAIL', $l ); $ok ? $p++ : $f++; }

$index = new DB_Chat_Product_Index();
$f     = new DB_Chat_Product_Finder( $index );
$call  = function ( $name, ...$args ) use ( $f ) { $m = new ReflectionMethod( $f, $name ); $m->setAccessible( true ); return $m->invokeArgs( $f, $args ); };
$first = function ( $reply ) { $lines = preg_split( '/\R/u', (string) $reply ); return trim( (string) $lines[0] ); };
$count = function ( $reply ) { $lines = preg_split( '/\R/u', (string) $reply ); return (int) preg_replace( '/\D/', '', (string) ( $lines[1] ?? '' ) ); };
$is    = function ( $x ) { return is_array( $x ) && isset( $x['reply'] ); };
$F     = 'DB_Chat_Product_Finder';

// 8: extractors.
ck( 'family: handguard kit', 'handguard kit' === $F::narrow_family( 'Barkbusters Handguard Kit fits for BMW F650GS & F800GS', 'Barkbusters' ), $pass, $fail );
ck( 'family: R&G brand words removed', 'crash protectors' === $F::narrow_family( 'R&G Crash Protectors for Yamaha MT-07', 'R-G' ), $pass, $fail );
ck( 'make: Kawasaki', 'Kawasaki' === $F::narrow_make( 'Barkbusters Handguard Kit fits for Kawasaki KLE650 Versys' ), $pass, $fail );
ck( 'make: CFMoto spelling', 'CF Moto' === $F::narrow_make( 'Barkbusters Handguard Kit fits for CFMoto 800MT' ), $pass, $fail );
ck( 'series: SR777', 'SR777' === $F::narrow_series( 'Shinko Tires SR777 Series (130/70B18WW) Heavy Duty Tire' ), $pass, $fail );
ck( 'size: 130/70B18', '130/70B18' === $F::narrow_size( 'Shinko Tires SR777 Series (130/70B18WW) Heavy Duty Tire' ), $pass, $fail );
ck( 'size: 130/80-17', '130/80-17' === $F::narrow_size( 'Shinko Tires SR777 Series (130/80-17)' ), $pass, $fail );
ck( 'colour: Blue', 'Blue' === $F::narrow_colour( 'Barkbusters JET Plastic Guard for Barkbusters Backbones (Blue)' ), $pass, $fail );

// 9: triggers.
$t = function ( $m ) use ( $call ) { $r = $call( 'narrow_trigger', $m ); return is_array( $r ) ? $r['name'] : ''; };
ck( 'trigger: barkbusters', 'Barkbusters' === $t( 'barkbusters' ), $pass, $fail );
ck( 'trigger: berapa harga barkbusters', 'Barkbusters' === $t( 'berapa harga barkbusters' ), $pass, $fail );
ck( 'trigger: shinko price', 'Shinko' === $t( 'shinko price' ), $pass, $fail );
ck( 'trigger: nak beli shinko 2', 'Shinko' === $t( 'nak beli shinko 2' ), $pass, $fail );
ck( 'not a trigger: shinko 705', '' === $t( 'shinko 705' ), $pass, $fail );
ck( 'not a trigger: barkbusters storm', '' === $t( 'barkbusters storm' ), $pass, $fail );
ck( 'not a trigger: what mt helmet models do you have', '' === $t( 'what mt helmet models do you have' ), $pass, $fail );
ck( 'not a trigger: i want yamanaka 2pc', '' === $t( 'i want yamanaka 2pc' ), $pass, $fail );
ck( 'not a trigger: a brand with under 5 products', '' === $t( 'durian bikers' ), $pass, $fail );

// 3: the opening step, Malay.
$trigger = 'berapa harga barkbusters';
$r1      = $f->maybe_handle( $trigger, array() );
ck( 'brand-only question opens with the product-type question', $is( $r1 ) && 'Barkbusters yang mana satu?' === $first( $r1['reply'] ) && empty( $r1['products'] ), $pass, $fail );
ck( 'between 3 and 6 buttons, Semua last', $is( $r1 ) && count( $r1['chips'] ) >= 3 && count( $r1['chips'] ) <= 6 && 'Semua' === end( $r1['chips'] ), $pass, $fail );
ck( 'Malay count line', $is( $r1 ) && false !== strpos( $r1['reply'], 'ada stok' ), $pass, $fail );

// 5: walk the flow by tapping the largest real option each time.
$base      = array( array( 'role' => 'user', 'content' => $trigger ), array( 'role' => 'assistant', 'content' => $r1['reply'] ) );
$history   = $base;
$r         = $r1;
$counts    = array( $count( $r1['reply'] ) );
$steps     = 0;
$made_make = false;
while ( $is( $r ) && ! empty( $r['chips'] ) && $steps < 5 ) {
	$tap = $r['chips'][0];
	if ( 'Untuk motor apa?' === $first( $r['reply'] ) ) { $made_make = true; }
	$r = $f->maybe_handle( $tap, $history );
	$steps++;
	if ( 1 === $steps ) {
		ck( 'tapping a type moves to the next step', $is( $r ) && ( ! empty( $r['chips'] ) || ! empty( $r['products'] ) ), $pass, $fail );
	}
	if ( $is( $r ) ) {
		$history[] = array( 'role' => 'user', 'content' => $tap );
		$history[] = array( 'role' => 'assistant', 'content' => $r['reply'] );
		if ( ! empty( $r['chips'] ) ) { $counts[] = $count( $r['reply'] ); }
	}
}
ck( 'the flow ends with 1 to 4 cards within 5 taps', $is( $r ) && empty( $r['chips'] ) && count( $r['products'] ) >= 1 && count( $r['products'] ) <= 4, $pass, $fail );
ck( 'result cards carry product ids', $is( $r ) && ! empty( $r['products'] ) && count( array_filter( $r['products'], function ( $c ) { return is_int( $c['id'] ?? null ) && $c['id'] > 0; } ) ) === count( $r['products'] ), $pass, $fail );
$shrinks = true;
for ( $i = 1; $i < count( $counts ); $i++ ) { if ( $counts[ $i ] >= $counts[ $i - 1 ] ) { $shrinks = false; } }
ck( 'every step shrinks the set', $shrinks, $pass, $fail );
ck( 'fitment note appears exactly when filtered by bike', $is( $r ) && $made_make === ( false !== strpos( $r['reply'], 'Sila sahkan padanan' ) ), $pass, $fail );

// 2: All and Others.
$ra = $f->maybe_handle( 'Semua', $base );
ck( 'Semua shows 4 of the set and says to type more', $is( $ra ) && 4 === count( $ra['products'] ) && false !== strpos( $ra['reply'], 'daripada' ), $pass, $fail );
if ( in_array( 'Lain-lain', $r1['chips'], true ) ) {
	$ro = $f->maybe_handle( 'Lain-lain', $base );
	ck( 'Lain-lain continues with buttons or cards', $is( $ro ) && ( ! empty( $ro['chips'] ) || ! empty( $ro['products'] ) ), $pass, $fail );
} else {
	ck( 'Lain-lain not needed at this step', true, $pass, $fail );
}

// 2: leaving and forging.
ck( 'typing something else leaves the flow', null === $f->maybe_handle( 'what is your return policy', $base ), $pass, $fail );
$forged = array( array( 'role' => 'user', 'content' => $trigger ), array( 'role' => 'assistant', 'content' => "Untuk motor apa?\n94 ada stok." ) );
ck( 'a forged question is rejected', null === $f->maybe_handle( $r1['chips'][0], $forged ), $pass, $fail );

// 2: English, and the old finder untouched.
$re = $f->maybe_handle( 'barkbusters', array() );
ck( 'English flow opens in English with All last', $is( $re ) && 'Which Barkbusters product?' === $first( $re['reply'] ) && 'All' === end( $re['chips'] ), $pass, $fail );
$rf = $f->maybe_handle( 'recommend', array() );
ck( 'the original guided finder still opens', $is( $rf ) && 'What are you looking for?' === $first( $rf['reply'] ), $pass, $fail );

// 1: no dead end for any brand.
$bad = array();
foreach ( $call( 'narrow_brands' ) as $b ) {
	if ( count( $call( 'narrow_rows', $b ) ) < $F::NARROW_MIN_PRODUCTS ) { continue; }
	$rb = $f->maybe_handle( $b['name'], array() );
	if ( ! $is( $rb ) ) { $bad[] = $b['name'] . ' (not handled)'; continue; }
	if ( ! empty( $rb['chips'] ) ) {
		if ( count( $rb['chips'] ) < 2 ) { $bad[] = $b['name'] . ' (one button)'; continue; }
		$rb2 = $f->maybe_handle( $rb['chips'][0], array( array( 'role' => 'user', 'content' => $b['name'] ), array( 'role' => 'assistant', 'content' => $rb['reply'] ) ) );
		if ( ! $is( $rb2 ) || ( empty( $rb2['chips'] ) && empty( $rb2['products'] ) ) ) { $bad[] = $b['name'] . ' (dead end after first tap)'; }
	} elseif ( empty( $rb['products'] ) ) {
		$bad[] = $b['name'] . ' (no cards)';
	}
}
if ( $bad ) { echo '     ' . implode( '; ', $bad ) . "\n"; }
ck( 'every brand with 5+ buyable products narrows with no dead end', empty( $bad ), $pass, $fail );

// 2: cache version and the REST route.
$v = (int) get_option( $F::NARROW_VERSION_OPTION, 0 );
$F::bump_narrow_version();
ck( 'saving a product bumps the cache version', $v + 1 === (int) get_option( $F::NARROW_VERSION_OPTION, 0 ), $pass, $fail );
$endpoint = new DB_Chat_Chat_Endpoint( $index, new DB_Chat_Claude_Client(), new DB_Chat_Content_Index(), new DB_Chat_Order_Lookup(), new DB_Chat_Lookup_Engine( $index, new DB_Chat_Content_Index() ) );
$req = new WP_REST_Request( 'POST', '/durianbikers/v1/chat' );
$req->set_param( 'message', 'barkbusters' );
$req->set_param( 'history', array() );
$res = $endpoint->handle_request( $req );
ck( 'the chat route answers a brand-only question with buttons', ! is_wp_error( $res ) && ! empty( $res->get_data()['chips'] ), $pass, $fail );

echo "\n==== $pass passed, $fail failed ====\n";
```

The only write this harness makes is `db_chat_narrow_version` + 1, which just retires cached narrowing rows.

- [ ] **Step 2: Run it to verify it fails**

```bash
sshc "cat > claude-uploads/t28-narrow.php" < $SCRATCH/t28-narrow.php
sshc "/opt/plesk/php/8.3/bin/php -d memory_limit=1024M /usr/local/bin/wp --path=$WPROOT eval-file claude-uploads/t28-narrow.php 2>&1 | grep -v 'Purged all caches'"
```

Expected: fatal `Call to undefined method DB_Chat_Product_Finder::narrow_family()`.

- [ ] **Step 3: Constants**

Replace:

```php
	const STEP_RESULTS  = 4;
```

with:

```php
	const STEP_RESULTS  = 4;

	/** Brands with fewer buyable products than this are listed, not narrowed. */
	const NARROW_MIN_PRODUCTS = 5;

	/** Narrowing ends once this many products or fewer remain. */
	const NARROW_STOP = 4;

	/** A facet whose largest bucket holds this share of the set does not split it. */
	const NARROW_DOMINANT = 0.8;

	/** Per-brand row cache prefix. */
	const NARROW_TRANSIENT = 'db_chat_narrow_';

	/** Bumped whenever a product changes; part of every row cache key. */
	const NARROW_VERSION_OPTION = 'db_chat_narrow_version';

	/** Facets, in the order they are asked. */
	const NARROW_FACETS = array( 'type', 'make', 'size', 'colour' );
```

- [ ] **Step 4: Give narrowing first refusal inside the finder**

In `maybe_handle()`, replace:

```php
		try {
			$state = $this->resolve_state( $message, $history );
```

with:

```php
		try {
			/*
			 * Brand narrowing first: a brand-only question ("berapa harga
			 * barkbusters") is never a guided-finder trigger, and a flow already
			 * in progress is identified the same way, by replaying history.
			 */
			$narrow = $this->narrow_state( $message, $history );

			if ( null !== $narrow ) {
				return $this->narrow_render( $narrow );
			}

			$state = $this->resolve_state( $message, $history );
```

- [ ] **Step 5: The narrowing section**

Insert directly before the docblock that begins `	/**\n	 * Build the reply for a step.`:

```php
	/*
	 * =======================================================================
	 * BRAND NARROWING — "berapa harga barkbusters" -> which one?
	 * =======================================================================
	 *
	 * Same three structural properties as the finder above: buttons are read
	 * off buyable products (no empty branches), the step is REPLAYED from
	 * history and never stored or trusted, and anything that is not an option
	 * returns null so the lookup engine answers normally.
	 *
	 * Facets come from product NAMES, not attributes: this catalogue names the
	 * bike make and the colour in the title, while its attribute names are
	 * inconsistent ("Size" on one product, "Option1" on the next).
	 */

	/**
	 * Resolve a narrowing turn, or null when this is not one.
	 *
	 * @param string $message Message.
	 * @param array  $history Untrusted history.
	 * @return array{brand:array,chosen:array,lang:string}|null
	 */
	private function narrow_state( $message, array $history ) {
		$fresh = $this->narrow_trigger( $message );

		if ( null !== $fresh ) {
			return array(
				'brand'  => $fresh,
				'chosen' => array(),
				'lang'   => $this->detect_language( $message ),
			);
		}

		$turns = $this->clean_history( $history );
		$start = null;
		$brand = null;

		for ( $i = count( $turns ) - 1; $i >= 0; $i-- ) {
			if ( 'user' !== $turns[ $i ]['role'] ) {
				continue;
			}

			$found = $this->narrow_trigger( $turns[ $i ]['content'] );

			if ( null !== $found ) {
				$start = $i;
				$brand = $found;
				break;
			}
		}

		if ( null === $start ) {
			return null;
		}

		$lang             = $this->detect_language( $turns[ $start ]['content'] );
		$chosen           = array();
		$expect_assistant = true;

		foreach ( array_slice( $turns, $start + 1 ) as $turn ) {
			$step = $this->narrow_next( $brand, $chosen );

			if ( $expect_assistant ) {
				if ( 'assistant' !== $turn['role'] || null === $step['facet'] || ! $this->narrow_is_question( $turn['content'], $step, $brand, $lang ) ) {
					return null;
				}

				$expect_assistant = false;
				continue;
			}

			if ( 'user' !== $turn['role'] ) {
				return null;
			}

			$picked = $this->narrow_match( $turn['content'], $brand, $chosen );

			if ( null === $picked ) {
				return null;
			}

			$chosen           = $picked;
			$expect_assistant = true;
		}

		if ( $expect_assistant ) {
			return null;
		}

		$picked = $this->narrow_match( $message, $brand, $chosen );

		if ( null === $picked ) {
			return null;
		}

		return array(
			'brand'  => $brand,
			'chosen' => $picked,
			'lang'   => $lang,
		);
	}

	/**
	 * The brand a bare brand question names, or null.
	 *
	 * Exactly one brand, nothing product-specific left once price words,
	 * purchase words, stopwords, fillers and a one- or two-digit quantity are
	 * removed, no browse word ("models", "series"), and enough buyable
	 * products that narrowing is worth it.
	 *
	 * @param string $message Message.
	 * @return array{slug:string,name:string,forms:string[]}|null
	 */
	private function narrow_trigger( $message ) {
		$normalized = $this->normalize( $message );

		if ( '' === $normalized || mb_strlen( $normalized ) > 80 ) {
			return null;
		}

		$haystack = ' ' . $normalized . ' ';

		foreach ( $this->narrow_browse_terms() as $term ) {
			$term = $this->normalize( $term );

			if ( '' !== $term && false !== strpos( $haystack, ' ' . $term . ' ' ) ) {
				return null;
			}
		}

		$found = array();

		foreach ( $this->narrow_brands() as $brand ) {
			foreach ( $brand['forms'] as $form ) {
				if ( false !== strpos( $haystack, ' ' . $form . ' ' ) ) {
					$found[ $brand['slug'] ] = array(
						'brand' => $brand,
						'form'  => $form,
					);
					break;
				}
			}
		}

		if ( 1 !== count( $found ) ) {
			return null;
		}

		$hit      = reset( $found );
		$residual = str_replace( ' ' . $hit['form'] . ' ', ' ', $haystack );

		foreach ( $this->narrow_chinese_fillers() as $zh ) {
			$residual = str_replace( $zh, ' ', $residual );
		}

		$strip = array_map( array( $this, 'normalize' ), array_merge( $this->stopwords(), $this->fillers(), $this->narrow_fillers() ) );
		$strip = array_values( array_filter( array_unique( $strip ) ) );

		usort(
			$strip,
			static function ( $a, $b ) {
				return strlen( $b ) <=> strlen( $a );
			}
		);

		do {
			$before = $residual;

			foreach ( $strip as $word ) {
				$residual = str_replace( ' ' . $word . ' ', ' ', $residual );
			}

			$residual = ' ' . trim( (string) preg_replace( '/\s+/u', ' ', $residual ) ) . ' ';
		} while ( $residual !== $before );

		$residual = trim( (string) preg_replace( '/(^|\s)[0-9]{1,2}(?=\s|$)/u', ' ', $residual ) );

		if ( '' !== $residual ) {
			return null;
		}

		if ( count( $this->narrow_rows( $hit['brand'] ) ) < self::NARROW_MIN_PRODUCTS ) {
			return null;
		}

		return $hit['brand'];
	}

	/**
	 * Price and purchase words that do not make a brand question specific.
	 *
	 * @return string[]
	 */
	private function narrow_fillers() {
		$words = array(
			'price', 'prices', 'how much', 'how much is', 'cost', 'sell', 'selling', 'available',
			'do you have', 'got', 'pcs', 'pc', 'unit', 'units',
			'harga', 'berapa', 'berapa harga', 'brape', 'brapa', 'kos', 'jual', 'ada', 'ada tak',
			'biji', 'pasang',
		);

		foreach ( array( 'purchase_terms', 'purchase_soft_terms' ) as $method ) {
			if ( class_exists( 'DB_Chat_Lookup_Engine' ) && is_callable( array( 'DB_Chat_Lookup_Engine', $method ) ) ) {
				$words = array_merge( $words, (array) call_user_func( array( 'DB_Chat_Lookup_Engine', $method ) ) );
			}
		}

		return array_map( 'strval', $words );
	}

	/**
	 * Chinese price and purchase words, removed by substring.
	 *
	 * @return string[]
	 */
	private function narrow_chinese_fillers() {
		$words = array( '多少钱', '价格', '价钱', '有没有', '有吗', '多少', '吗' );

		if ( class_exists( 'DB_Chat_Lookup_Engine' ) && is_callable( array( 'DB_Chat_Lookup_Engine', 'chinese_terms' ) ) ) {
			$terms = DB_Chat_Lookup_Engine::chinese_terms();
			$words = array_merge( $words, isset( $terms['purchase'] ) ? (array) $terms['purchase'] : array() );
		}

		usort(
			$words,
			static function ( $a, $b ) {
				return mb_strlen( $b ) <=> mb_strlen( $a );
			}
		);

		return $words;
	}

	/**
	 * Words that ask to see a range; those keep the engine's range listing.
	 *
	 * @return string[]
	 */
	private function narrow_browse_terms() {
		if ( class_exists( 'DB_Chat_Lookup_Engine' ) && is_callable( array( 'DB_Chat_Lookup_Engine', 'browse_terms' ) ) ) {
			return array_map( 'strval', (array) DB_Chat_Lookup_Engine::browse_terms() );
		}

		return array( 'model', 'models', 'series', 'range' );
	}

	/**
	 * Brand terms with their comparison forms, memoized per request.
	 *
	 * @return array<int,array{slug:string,name:string,forms:string[]}>
	 */
	private function narrow_brands() {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$cache    = array();
		$taxonomy = $this->index->get_brand_taxonomy();

		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return $cache;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
			)
		);

		if ( ! is_array( $terms ) ) {
			return $cache;
		}

		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$name  = trim( html_entity_decode( wp_strip_all_tags( $term->name ), ENT_QUOTES, 'UTF-8' ) );
			$forms = array_values( array_unique( array_filter( array( $this->normalize( $name ), $this->normalize( str_replace( '-', ' ', (string) $term->slug ) ) ) ) ) );

			if ( '' === $name || empty( $forms ) ) {
				continue;
			}

			$cache[] = array(
				'slug'  => (string) $term->slug,
				'name'  => $name,
				'forms' => $forms,
			);
		}

		return $cache;
	}

	/**
	 * One row per buyable product of a brand: id and facet values, NO stock.
	 *
	 * Built with id-only queries and primed caches so a 2,885-product brand
	 * stays quick, then cached for an hour under a key that includes the
	 * catalogue version (bumped on every product change). Stock and price on
	 * the final cards are re-read live by card().
	 *
	 * @param array $brand Brand from narrow_brands().
	 * @return array<int,array<string,mixed>>
	 */
	private function narrow_rows( array $brand ) {
		static $memo = array();

		$version = (int) get_option( self::NARROW_VERSION_OPTION, 0 );
		$key     = self::NARROW_TRANSIENT . md5( $brand['slug'] . '|' . $version );

		if ( isset( $memo[ $key ] ) ) {
			return $memo[ $key ];
		}

		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			$memo[ $key ] = $cached;

			return $cached;
		}

		$rows     = array();
		$taxonomy = $this->index->get_brand_taxonomy();

		if ( '' !== $taxonomy && function_exists( 'wc_get_products' ) ) {
			$slug   = $brand['slug'];
			$filter = static function ( $args ) use ( $taxonomy, $slug ) {
				if ( ! isset( $args['tax_query'] ) || ! is_array( $args['tax_query'] ) ) {
					$args['tax_query'] = array();
				}

				$args['tax_query'][] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'slug',
					'terms'    => array( $slug ),
					'operator' => 'IN',
				);

				return $args;
			};

			add_filter( 'woocommerce_product_data_store_cpt_get_products_query', $filter, 99 );

			try {
				$ids = wc_get_products(
					array(
						'status'       => 'publish',
						'visibility'   => 'visible',
						'stock_status' => 'instock',
						'limit'        => -1,
						'return'       => 'ids',
					)
				);
			} catch ( Throwable $e ) {
				$ids = array();
			} finally {
				remove_filter( 'woocommerce_product_data_store_cpt_get_products_query', $filter, 99 );
			}

			$ids = array_map( 'intval', is_array( $ids ) ? $ids : array() );

			if ( ! empty( $ids ) ) {
				_prime_post_caches( $ids, false, true );

				$leaf  = array();
				$terms = wp_get_object_terms( $ids, 'product_cat', array( 'fields' => 'all_with_object_id' ) );

				if ( is_array( $terms ) ) {
					$parents = array();

					foreach ( $terms as $term ) {
						$parents[ (int) $term->parent ] = true;
					}

					foreach ( $terms as $term ) {
						if ( ! isset( $parents[ (int) $term->term_id ] ) ) {
							$leaf[ (int) $term->object_id ] = html_entity_decode( (string) $term->name, ENT_QUOTES, 'UTF-8' );
						}
					}
				}

				foreach ( $ids as $id ) {
					$price = get_post_meta( $id, '_price', true );

					// No price: unbuyable, including every zero-variation import.
					if ( ! is_numeric( $price ) || (float) $price <= 0 ) {
						continue;
					}

					$name = trim( html_entity_decode( wp_strip_all_tags( (string) get_post_field( 'post_title', $id ) ), ENT_QUOTES, 'UTF-8' ) );

					if ( '' === $name ) {
						continue;
					}

					$rows[] = array(
						'id'     => $id,
						'cat'    => isset( $leaf[ $id ] ) ? $leaf[ $id ] : '',
						'family' => self::narrow_family( $name, $brand['name'] ),
						'make'   => self::narrow_make( $name ),
						'series' => self::narrow_series( $name ),
						'size'   => self::narrow_size( $name ),
						'colour' => self::narrow_colour( $name ),
					);
				}
			}
		}

		set_transient( $key, $rows, self::CACHE_TTL );
		$memo[ $key ] = $rows;

		return $rows;
	}

	/**
	 * The next step for a brand given what has been chosen.
	 *
	 * @param array $brand  Brand.
	 * @param array $chosen Selections so far.
	 * @return array{rows:array,facet:?string,source:string,options:array,all:bool}
	 */
	private function narrow_next( array $brand, array $chosen ) {
		$rows = $this->narrow_filter( $this->narrow_rows( $brand ), $chosen );
		$step = array(
			'rows'    => $rows,
			'facet'   => null,
			'source'  => '',
			'options' => array(),
			'all'     => false,
		);

		foreach ( $chosen as $selection ) {
			if ( 'all' === $selection['kind'] ) {
				$step['all'] = true;

				return $step;
			}
		}

		if ( count( $rows ) <= self::NARROW_STOP ) {
			return $step;
		}

		$done = array_column( $chosen, 'facet' );

		foreach ( self::NARROW_FACETS as $facet ) {
			if ( in_array( $facet, $done, true ) ) {
				continue;
			}

			$source  = $this->narrow_source( $rows, $facet );
			$options = $this->narrow_options( $rows, $facet, $source );

			if ( null !== $options ) {
				$step['facet']   = $facet;
				$step['source']  = $source;
				$step['options'] = $options;

				return $step;
			}
		}

		return $step;
	}

	/**
	 * Which row field a facet reads for this set.
	 *
	 * @param array  $rows  Rows.
	 * @param string $facet Facet.
	 * @return string
	 */
	private function narrow_source( array $rows, $facet ) {
		if ( 'type' === $facet ) {
			return null !== $this->narrow_options( $rows, 'type', 'cat' ) ? 'cat' : 'family';
		}

		if ( 'make' === $facet ) {
			foreach ( $rows as $row ) {
				if ( '' !== $row['make'] ) {
					return 'make';
				}
			}

			return 'series';
		}

		return $facet;
	}

	/**
	 * Buttons for a facet, or null when it does not split the set.
	 *
	 * @param array  $rows   Rows.
	 * @param string $facet  Facet.
	 * @param string $source Row field.
	 * @return array|null
	 */
	private function narrow_options( array $rows, $facet, $source ) {
		$counts = array();
		$blank  = 0;

		foreach ( $rows as $row ) {
			$value = isset( $row[ $source ] ) ? (string) $row[ $source ] : '';

			if ( '' === $value ) {
				$blank++;
				continue;
			}

			$counts[ $value ] = isset( $counts[ $value ] ) ? $counts[ $value ] + 1 : 1;
		}

		$total = count( $rows );

		if ( $total < 2 || ( count( $counts ) + ( $blank > 0 ? 1 : 0 ) ) < 2 ) {
			return null;
		}

		arsort( $counts );

		$largest = max( $blank, empty( $counts ) ? 0 : (int) reset( $counts ) );

		if ( $largest / $total >= self::NARROW_DOMINANT ) {
			return null;
		}

		$needs_others = $blank > 0 || count( $counts ) > self::MAX_OPTIONS;
		$shown        = array_slice( array_map( 'strval', array_keys( $counts ) ), 0, $needs_others ? self::MAX_OPTIONS - 1 : self::MAX_OPTIONS );
		$options      = array();

		foreach ( $shown as $value ) {
			$label     = 'family' === $source ? ucwords( $value ) : $value;
			$options[] = array(
				'kind'   => 'value',
				'facet'  => $facet,
				'source' => $source,
				'value'  => $value,
				'label'  => $label,
				'match'  => array( $label, $value ),
			);
		}

		if ( $needs_others ) {
			$options[] = array(
				'kind'   => 'others',
				'facet'  => $facet,
				'source' => $source,
				'value'  => $shown,
				'label'  => '',
				'match'  => array( 'others', 'other', 'lain lain', 'yang lain' ),
			);
		}

		$options[] = array(
			'kind'   => 'all',
			'facet'  => $facet,
			'source' => $source,
			'value'  => '',
			'label'  => '',
			'match'  => array( 'all', 'show all', 'everything', 'semua', 'tunjuk semua', 'any' ),
		);

		return $options;
	}

	/**
	 * Apply selections to rows.
	 *
	 * @param array $rows   Rows.
	 * @param array $chosen Selections.
	 * @return array
	 */
	private function narrow_filter( array $rows, array $chosen ) {
		foreach ( $chosen as $selection ) {
			if ( 'all' === $selection['kind'] ) {
				continue;
			}

			$source = $selection['source'];

			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $selection, $source ) {
						$value = isset( $row[ $source ] ) ? (string) $row[ $source ] : '';

						if ( 'others' === $selection['kind'] ) {
							return ! in_array( $value, (array) $selection['value'], true );
						}

						return $value === (string) $selection['value'];
					}
				)
			);
		}

		return $rows;
	}

	/**
	 * Match a message to the current step, then let a typed answer jump ahead
	 * ("handguard kit ktm" answers type and bike at once).
	 *
	 * @param string $message Message.
	 * @param array  $brand   Brand.
	 * @param array  $chosen  Selections so far.
	 * @return array|null Selections after this message, null when not an option.
	 */
	private function narrow_match( $message, array $brand, array $chosen ) {
		$step = $this->narrow_next( $brand, $chosen );

		if ( null === $step['facet'] ) {
			return null;
		}

		$option = $this->narrow_pick_option( $message, $step['options'], false );

		if ( null === $option ) {
			return null;
		}

		$chosen[] = $option;

		for ( $i = 0; $i < 3; $i++ ) {
			$next = $this->narrow_next( $brand, $chosen );

			if ( null === $next['facet'] ) {
				break;
			}

			$more = $this->narrow_pick_option( $message, $next['options'], true );

			if ( null === $more ) {
				break;
			}

			$chosen[] = $more;
		}

		return $chosen;
	}

	/**
	 * The option a message chooses.
	 *
	 * @param string $message      Message.
	 * @param array  $options      Options from narrow_next(). Others/All carry their
	 *                             EN and BM words in `match`, so they match before
	 *                             narrow_localise() fills the display label.
	 * @param bool   $values_only  Jump-ahead: contained value options only.
	 * @return array|null
	 */
	private function narrow_pick_option( $message, array $options, $values_only ) {
		$needle = $this->normalize( $message );

		if ( '' === $needle ) {
			return null;
		}

		if ( ! $values_only ) {
			foreach ( $options as $option ) {
				foreach ( $this->match_forms( $option ) as $form ) {
					if ( $form === $needle ) {
						return $option;
					}
				}
			}
		}

		foreach ( $options as $option ) {
			if ( 'value' !== $option['kind'] ) {
				continue;
			}

			foreach ( $this->match_forms( $option ) as $form ) {
				if ( '' !== $form && false !== strpos( ' ' . $needle . ' ', ' ' . $form . ' ' ) ) {
					return $option;
				}
			}
		}

		return null;
	}

	/**
	 * Fill the Others / All labels for the flow language.
	 *
	 * @param array  $options Options.
	 * @param string $lang    Flow language.
	 * @return array
	 */
	private function narrow_localise( array $options, $lang ) {
		foreach ( $options as $i => $option ) {
			if ( 'others' === $option['kind'] ) {
				$options[ $i ]['label']   = $this->text( 'narrow_others', $lang );
				$options[ $i ]['match'][] = $options[ $i ]['label'];
			} elseif ( 'all' === $option['kind'] ) {
				$options[ $i ]['label']   = $this->text( 'narrow_all', $lang );
				$options[ $i ]['match'][] = $options[ $i ]['label'];
			}
		}

		return $options;
	}

	/**
	 * The question for a step. First line only is matched on replay.
	 *
	 * @param array  $step  Step.
	 * @param array  $brand Brand.
	 * @param string $lang  Flow language.
	 * @return string
	 */
	private function narrow_question( array $step, array $brand, $lang ) {
		$ms = 'ms' === $lang;

		switch ( $step['facet'] ) {
			case 'type':
				return $ms ? $brand['name'] . ' yang mana satu?' : 'Which ' . $brand['name'] . ' product?';
			case 'make':
				if ( 'series' === $step['source'] ) {
					return $ms ? 'Siri yang mana?' : 'Which series?';
				}

				return $ms ? 'Untuk motor apa?' : 'For which bike?';
			case 'size':
				return $ms ? 'Saiz berapa?' : 'Which size?';
			case 'colour':
				return $ms ? 'Warna apa?' : 'Which colour?';
		}

		return '';
	}

	/**
	 * Is this assistant turn the question for this step?
	 *
	 * @param string $content Assistant turn.
	 * @param array  $step    Step.
	 * @param array  $brand   Brand.
	 * @param string $lang    Flow language.
	 * @return bool
	 */
	private function narrow_is_question( $content, array $step, array $brand, $lang ) {
		$expected = $this->normalize( $this->narrow_question( $step, $brand, $lang ) );
		$lines    = preg_split( '/\R/u', (string) $content );
		$first    = is_array( $lines ) && isset( $lines[0] ) ? $lines[0] : (string) $content;

		return '' !== $expected && $this->normalize( $first ) === $expected;
	}

	/**
	 * Render a narrowing turn: a question with buttons, or up to 4 cards.
	 *
	 * @param array $state State from narrow_state().
	 * @return array
	 */
	private function narrow_render( array $state ) {
		$brand = $state['brand'];
		$lang  = $state['lang'];
		$step  = $this->narrow_next( $brand, $state['chosen'] );

		if ( null !== $step['facet'] ) {
			$options = $this->narrow_localise( $step['options'], $lang );
			$reply   = $this->narrow_question( $step, $brand, $lang ) . "\n"
				. sprintf( $this->text( 'narrow_count', $lang ), count( $step['rows'] ) ) . "\n\n"
				. $this->hint( $lang );

			return $this->payload( $reply, array(), $this->chips_from( $options ) );
		}

		$cards = array();

		foreach ( $step['rows'] as $row ) {
			$product = wc_get_product( (int) $row['id'] );

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$card = $this->card( $product );

			if ( null === $card ) {
				continue;
			}

			$cards[] = $card;

			if ( count( $cards ) >= self::NARROW_STOP ) {
				break;
			}
		}

		if ( empty( $cards ) ) {
			return $this->payload( $this->text( 'none', $lang ), array(), array() );
		}

		$total = count( $step['rows'] );
		$lines = array(
			$total > count( $cards )
				? sprintf( $this->text( 'narrow_some', $lang ), count( $cards ), $total )
				: sprintf( $this->text( 'narrow_results', $lang ), count( $cards ) ),
		);

		foreach ( $state['chosen'] as $selection ) {
			if ( 'make' === $selection['facet'] && 'make' === $selection['source'] && 'all' !== $selection['kind'] ) {
				$lines[] = $this->text( 'narrow_fitment', $lang );
				break;
			}
		}

		return $this->payload( implode( "\n", $lines ), $cards, array() );
	}

	/**
	 * Product family: the first two meaningful words after the brand.
	 *
	 * @param string $name  Product name.
	 * @param string $brand Brand name.
	 * @return string Lowercase, e.g. "handguard kit".
	 */
	public static function narrow_family( $name, $brand ) {
		$text  = mb_strtolower( (string) preg_replace( '/\([^)]*\)/u', ' ', (string) $name ) );
		$text  = ' ' . trim( (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text ) ) . ' ';
		$brand = trim( (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', mb_strtolower( (string) $brand ) ) );

		foreach ( preg_split( '/\s+/u', $brand, -1, PREG_SPLIT_NO_EMPTY ) as $word ) {
			$text = str_replace( ' ' . $word . ' ', ' ', $text );
		}

		$words = array_values(
			array_filter(
				preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY ),
				static function ( $word ) {
					return ! in_array( $word, array( 'fits', 'fit', 'for', 'the', 'with' ), true );
				}
			)
		);

		return implode( ' ', array_slice( $words, 0, 2 ) );
	}

	/**
	 * Bike make named in a product name.
	 *
	 * @param string $name Product name.
	 * @return string Canonical make, or ''.
	 */
	public static function narrow_make( $name ) {
		$makes = array(
			'Harley-Davidson' => array( 'harley davidson', 'harley' ),
			'Royal Enfield'   => array( 'royal enfield' ),
			'CF Moto'         => array( 'cf moto', 'cfmoto' ),
			'Honda'           => array( 'honda' ),
			'Yamaha'          => array( 'yamaha' ),
			'Kawasaki'        => array( 'kawasaki' ),
			'Suzuki'          => array( 'suzuki' ),
			'BMW'             => array( 'bmw' ),
			'KTM'             => array( 'ktm' ),
			'Ducati'          => array( 'ducati' ),
			'Triumph'         => array( 'triumph' ),
			'Husqvarna'       => array( 'husqvarna' ),
			'Aprilia'         => array( 'aprilia' ),
			'Benelli'         => array( 'benelli' ),
			'Modenas'         => array( 'modenas' ),
		);

		$text = ' ' . trim( (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', mb_strtolower( (string) $name ) ) ) . ' ';

		foreach ( $makes as $label => $forms ) {
			foreach ( $forms as $form ) {
				if ( false !== strpos( $text, ' ' . $form . ' ' ) ) {
					return $label;
				}
			}
		}

		return '';
	}

	/**
	 * Series or model code outside brackets, e.g. SR777, E705, MX9.
	 *
	 * @param string $name Product name.
	 * @return string
	 */
	public static function narrow_series( $name ) {
		$text = (string) preg_replace( '/\([^)]*\)/u', ' ', (string) $name );

		if ( preg_match( '/\b([A-Za-z]{1,4}-?[0-9]{2,4}[A-Za-z]{0,2})\b/u', $text, $m ) ) {
			return strtoupper( str_replace( '-', '', $m[1] ) );
		}

		return '';
	}

	/**
	 * Tyre size, e.g. 130/70B18, 120/70R17, 130/80-17.
	 *
	 * @param string $name Product name.
	 * @return string
	 */
	public static function narrow_size( $name ) {
		if ( preg_match( '#\b([0-9]{2,3}/[0-9]{2,3}\s?(?:ZR|R|B|-)?\s?[0-9]{2})#i', (string) $name, $m ) ) {
			return strtoupper( (string) preg_replace( '/\s+/', '', $m[1] ) );
		}

		return '';
	}

	/**
	 * Colour named in a product name, brackets first.
	 *
	 * @param string $name Product name.
	 * @return string
	 */
	public static function narrow_colour( $name ) {
		$colours = array(
			'black' => 'Black', 'white' => 'White', 'red' => 'Red', 'blue' => 'Blue', 'green' => 'Green',
			'orange' => 'Orange', 'yellow' => 'Yellow', 'pink' => 'Pink', 'grey' => 'Grey', 'gray' => 'Grey',
			'silver' => 'Silver', 'gold' => 'Gold', 'purple' => 'Purple', 'brown' => 'Brown',
			'carbon' => 'Carbon', 'titanium' => 'Titanium',
		);

		preg_match_all( '/\(([^)]*)\)/u', (string) $name, $groups );

		foreach ( array( implode( ' ', $groups[1] ), (string) $name ) as $haystack ) {
			$text = ' ' . trim( (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', mb_strtolower( $haystack ) ) ) . ' ';

			foreach ( $colours as $word => $label ) {
				if ( false !== strpos( $text, ' ' . $word . ' ' ) ) {
					return $label;
				}
			}
		}

		return '';
	}

	/**
	 * Retire every cached narrowing row. Hooked to product changes below.
	 *
	 * @return void
	 */
	public static function bump_narrow_version() {
		update_option( self::NARROW_VERSION_OPTION, (int) get_option( self::NARROW_VERSION_OPTION, 0 ) + 1, false );
	}

```

- [ ] **Step 6: Narrowing copy**

In `text()`, replace:

```php
			'none'             => __( 'Nothing in stock matches that combination right now. Try a different budget, or type the name of what you are after.', 'durianbikers-chatbot' ),
		);
```

with:

```php
			'none'             => __( 'Nothing in stock matches that combination right now. Try a different budget, or type the name of what you are after.', 'durianbikers-chatbot' ),
			'narrow_count'     => __( '%d in stock.', 'durianbikers-chatbot' ),
			'narrow_results'   => __( 'Here are the %d available:', 'durianbikers-chatbot' ),
			'narrow_some'      => __( 'Here are %1$d of %2$d. Type more detail to narrow it down.', 'durianbikers-chatbot' ),
			'narrow_fitment'   => __( 'Please confirm fitment with our staff before buying.', 'durianbikers-chatbot' ),
			'narrow_others'    => __( 'Others', 'durianbikers-chatbot' ),
			'narrow_all'       => __( 'All', 'durianbikers-chatbot' ),
		);
```

and replace:

```php
			'none'             => 'Tiada stok yang sepadan dengan pilihan itu buat masa ini. Cuba bajet lain, atau taip nama barang yang anda cari.',
		);
```

with:

```php
			'none'             => 'Tiada stok yang sepadan dengan pilihan itu buat masa ini. Cuba bajet lain, atau taip nama barang yang anda cari.',
			'narrow_count'     => '%d ada stok.',
			'narrow_results'   => 'Ini %d yang ada:',
			'narrow_some'      => 'Ini %1$d daripada %2$d. Taip butiran lagi untuk tapis.',
			'narrow_fitment'   => 'Sila sahkan padanan dengan staf sebelum beli.',
			'narrow_others'    => 'Lain-lain',
			'narrow_all'       => 'Semua',
		);
```

- [ ] **Step 7: Retire the cache on product changes**

Append at the very end of `class-product-finder.php`, after the class's closing brace:

```php

/*
 * Narrowing rows are cached per brand for an hour. Any product or stock
 * change retires them all by bumping the version in the cache key; the old
 * transients simply expire.
 */
if ( function_exists( 'add_action' ) ) {
	add_action( 'woocommerce_update_product', array( 'DB_Chat_Product_Finder', 'bump_narrow_version' ) );
	add_action( 'woocommerce_new_product', array( 'DB_Chat_Product_Finder', 'bump_narrow_version' ) );
	add_action( 'woocommerce_product_set_stock_status', array( 'DB_Chat_Product_Finder', 'bump_narrow_version' ) );
	add_action( 'woocommerce_variation_set_stock_status', array( 'DB_Chat_Product_Finder', 'bump_narrow_version' ) );
}
```

- [ ] **Step 8: Lint, deploy, run**

```bash
cd /Users/superfly2026/sites/durianbikers
/opt/homebrew/opt/php@8.3/bin/php -l durianbikers-chatbot/includes/class-product-finder.php
safe_deploy includes/class-product-finder.php task4
sshc "/opt/plesk/php/8.3/bin/php -d memory_limit=1024M /usr/local/bin/wp --path=$WPROOT eval-file claude-uploads/t28-narrow.php 2>&1 | grep -v 'Purged all caches'"
for t in t2-vocabulary t5-flow t11-brand-browse purchase-test t27-pick; do printf '%-18s ' $t; sshc "$WP eval-file claude-uploads/run-guarded.php $t 2>&1 | grep -E 'Fatal|====' | tail -1"; done
time curl -s -m 60 -o /dev/null -w '%{http_code}\n' "https://durianbikers.com/my/?nc=$(date +%s)"
```

Expected: `==== 34 passed, 0 failed ====`; t2 `9`, t5 `19`, t11 `16`, purchase-test `30`, t27 `28`, each `0 failed`. If an older suite now fails because a **brand-only** message is narrowed instead of listed, that is the approved behaviour change: show the operator the exact assertion before changing it. Any other failure is a bug.

- [ ] **Step 9: Commit**

```bash
git add durianbikers-chatbot/includes/class-product-finder.php
git commit -m "feat: brand-only questions narrow with in-stock tap buttons

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

---

### Task 5: Full regression, deploy check, runbook, hand-off to the operator

**Files:**
- Modify: `durianbikers-chatbot/DEPLOY.md` (new section before `### 2.7 Purge the cache`)
- Test: every harness; `~/claude-uploads/t21-cart-snapshot.php` (exists)

**Interfaces:**
- Consumes: everything above.
- Produces: all four parts live on the private test page, verified; nothing switched on for customers.

- [ ] **Step 1: Save the operator's cart**

```bash
sshc "$WP eval-file claude-uploads/t7-cartcheck.php 2>/dev/null"
sshc "$WP eval-file claude-uploads/t21-cart-snapshot.php save 2>&1 | grep -v 'Purged all caches'"
```

Expected: a line starting `saved: `. If not, stop — run no suite.

- [ ] **Step 2: Run every suite**

```bash
TOTAL=0; BAD=""
for t in rank-test purchase-test t1-cart-writer t2-vocabulary t3-pending t4-answer-matching t5-flow t6-confirm t8-chinese t10-fitment t11-brand-browse t15-client t16-budget t17-miss-flag t18-mode t19-endpoint t20-copy t26-human t27-pick; do
  LINE=$(sshc "$WP eval-file claude-uploads/run-guarded.php $t 2>&1 | grep -E 'Fatal|TEST TRIED|====' | tail -1"); printf '%-20s %s\n' $t "$LINE"
  echo "$LINE" | grep -qE ', 0 failed ====' && TOTAL=$((TOTAL + $(echo "$LINE" | sed -E 's/.*==== ([0-9]+) passed.*/\1/'))) || BAD="$BAD $t"
done
LINE=$(sshc "/opt/plesk/php/8.3/bin/php -d memory_limit=1024M /usr/local/bin/wp --path=$WPROOT eval-file claude-uploads/run-guarded.php t28-narrow 2>&1 | grep -E 'Fatal|====' | tail -1"); printf '%-20s %s\n' t28-narrow "$LINE"
echo "$LINE" | grep -qE ', 0 failed ====' && TOTAL=$((TOTAL + 34)) || BAD="$BAD t28-narrow"
echo "TOTAL $TOTAL passed; not clean: [$BAD]"
```

Expected: `TOTAL 347 passed; not clean: []` (255 existing + 30 + 28 + 34).

- [ ] **Step 3: Restore the operator's cart (always, even after a failure)**

```bash
sshc "$WP eval-file claude-uploads/t21-cart-snapshot.php restore 2>&1 | grep -v 'Purged all caches'"
sshc "$WP eval-file claude-uploads/t7-cartcheck.php 2>/dev/null"
```

Expected: `unchanged during the run, nothing written` or `restored: …` with the same fingerprint as Step 1, and the same cart lines listed.

- [ ] **Step 4: Verify the deployed state**

```bash
for f in durianbikers-chatbot.php includes/class-lookup-engine.php includes/class-handoff.php includes/class-chat-endpoint.php includes/class-pending-order.php includes/class-product-index.php includes/class-product-finder.php assets/chatbot.js assets/chatbot.css; do
  L=$(md5 -q durianbikers-chatbot/$f); R=$(sshc "md5sum $PLUG/$f | cut -d' ' -f1"); G=$(git show HEAD:durianbikers-chatbot/$f | md5)
  [ "$L" = "$R" ] && [ "$L" = "$G" ] && echo "match    $f" || echo "MISMATCH $f"
done
sshc "ls $PLUG/*.new $PLUG/includes/*.new $PLUG/assets/*.new 2>/dev/null || echo 'no stray .new files'"
sshc "$WP option get db_chat_mode"
sshc "$WP option get db_chat_ai_spend 2>&1 | head -1"
for u in /my/ /gb/ /my/cart/ /my/checkout/; do printf '%-15s %s\n' $u "$(curl -s -m 30 -o /dev/null -L -w '%{http_code}' "https://durianbikers.com$u?nc=$(date +%s)")"; done
curl -s -m 30 -o /dev/null -w 'session route %{http_code}\n' https://durianbikers.com/wp-json/durianbikers/v1/session
```

Expected: nine `match`; no stray files; mode `lookup`; `Could not get 'db_chat_ai_spend'`; every URL `200`.

- [ ] **Step 5: Widget harness against the committed files**

```bash
cp durianbikers-chatbot/assets/chatbot.js durianbikers-chatbot/assets/chatbot.css $SCRATCH/widget-harness/
```

Browser pane: `preview_start` `widget-harness`; `javascript_tool` `sessionStorage.clear()`; `navigate` to `http://localhost:8765/index.html?store=my`; wait ~8 s; read `window.__result`. Expected `==== 19 passed, 0 failed ====`. Then `preview_stop`.

- [ ] **Step 6: DEPLOY.md**

Insert directly before `### 2.7 Purge the cache`:

```markdown
### 2.6a Product cards, brand questions and asking for a person

These work in every answering mode and need no setting.

- **This one.** Every product card has a **This one** button. It tells the bot exactly which
  product the customer means: the bot replies with the price, stock and in-stock sizes and says
  "say *buy* to add it". While the bot is asking "which one?", tapping it goes straight to the
  size question. A tap never adds anything to a cart — buying still needs "buy", a size and a yes.
- **Card tap.** Tapping the card opens the product page in the same tab. The conversation is
  kept in that browser tab for 30 minutes and reopens on the next page. It is never kept once the
  customer asks about an order, and a `/my` chat never appears on `/gb`.
- **Brand-only questions.** "berapa harga barkbusters" or "shinko price" ask one question at a
  time with tap buttons — product type, then bike or series, then size, then colour — built only
  from what is in stock, and stop at 4 products or fewer. Choosing a bike always adds "please
  confirm fitment with our staff". Buttons can lag the catalogue by up to an hour; the cards never do.
- **Asking for a person.** "talk to a human", "nak cakap dengan orang", "customer service" and
  similar get "a person can help — tap WhatsApp below", with the button highlighted, opening hours,
  and a "we're closed right now" line outside hours. The WhatsApp number field now also accepts the
  local form `01x-xxx xxxx`.
```

Add to the Rollback table, after the `Hybrid AI answers look wrong` row:

```markdown
| Brand buttons wrong or missing | Save any product in wp-admin (retires the cached buttons), or add `add_filter( 'db_chat_finder_enabled', '__return_false' );` to switch off all tap-button flows |
```

- [ ] **Step 7: Commit**

```bash
git add durianbikers-chatbot/DEPLOY.md
git commit -m "docs: runbook for This-one cards, brand narrowing and human handoff

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>"
```

- [ ] **Step 8: Hand to the operator**

Report in plain terms what changed and give these to try through the secret test link, on a phone and a computer:

1. `talk to a human` and `nak cakap dengan orang` — WhatsApp reply, button highlighted, no Terms text.
2. `i want yamanaka 2pc` → tap **This one** → size question → answer → summary → `no`.
3. `shinko 705` → tap **This one** → price/stock/sizes → `buy 2` → size question → `no`.
4. Tap a card → product page opens → open the chat → the conversation is still there.
5. `where is my order` → reload → the chat starts fresh.
6. `berapa harga barkbusters` → tap through → 4 or fewer cards with the fitment note.
7. `barkbusters` (English buttons) and `Semua` / `All`.

Do not switch the chatbot on site-wide or touch Chatway: go-live is a separate step with its own yes.

---

## Self-Review Notes

- **Spec coverage.** §4.1 intent → Task 1 Steps 3–4; §4.2 reply, open/closed/unconfigured, `suggest_handoff` → Task 1 Steps 5, 6, 8; §4.3 local numbers → Task 1 Step 7; §5.1 card id → Task 2 Step 4; §5.2–5.3 `pick` + validation → Task 2 Steps 6, 8; §5.4 behaviour table + `chosen` → Task 2 Steps 3, 5, 6; §6.1 card/button → Task 3 Step 8; §6.2 persistence → Task 3 Steps 3–7, 9; §7.2 trigger → Task 4 `narrow_trigger()`; §7.3 facets/skip/buttons/stop/All/Others/fitment → Task 4 `narrow_next()`, `narrow_options()`, `narrow_render()`; §7.4 copy → Task 4 Steps 5–6; §7.5 cache → Task 4 `narrow_rows()` + Step 7; §8 safety → Global Constraints + every harness; §9 testing → t26, t27, widget harness, t28, Task 5; §10 rollout → safe deploys, Task 5 Step 8; §11 files → File Structure.
- **Deliberate refinements of the spec.** (1) Narrowing question count moves to line 2 so replay matches a stable first line. (2) `pickLabel` is overridable through the existing `db_chat_script_data` filter rather than a new settings field — no settings UI change was asked for. (3) Brand narrowing lives in the finder file as a delimited section rather than a new class, because it reuses a dozen of the finder's private helpers; splitting would duplicate them.
- **Safety trap found while planning.** `resume_pending()` sent every unrecognised stage to `handle_confirmation()`. A new `chosen` stage would have let a stray "yes" try to buy a product with no size. Task 2 Step 5 adds the explicit branch AND a guard that only `ask_confirm` reaches the confirmation handler; t27 asserts `chosen + "yes"` buys nothing.
- **Names used across tasks:** `answer_human()`, `wants_human_zh()`, `answer_pick()`, `pick_language()`, `resume_chosen()`, `card_for()`, `pick_id()`, `STAGE_CHOSEN`, `suggest_handoff`, `pick`, `store`, `saveState()`, `restoreState()`, `clearSavedState()`, `narrow_state()`, `narrow_trigger()`, `narrow_rows()`, `narrow_next()`, `narrow_render()`, `bump_narrow_version()`, `NARROW_VERSION_OPTION`.
- **Known soft spots.** Chinese buying copy and narrowing buttons stay English (spec out of scope). A brand typed glued to Chinese without a space (`shinko多少钱`) is not recognised as brand-only. Series codes are a regex over names and will mislabel a few products; a facet that does not split cleanly is skipped rather than shown.
