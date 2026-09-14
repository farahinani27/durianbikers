# Chatbot Hybrid AI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Answer every customer question — the free keyword engine answers what it can, and only its genuine misses go to Claude Haiku 4.5, under an RM100/month budget.

**Architecture:** The lookup engine raises a request-scoped miss flag at exactly three decision points. In a new `hybrid` mode, the chat endpoint checks that flag after the free engine has answered and, if a key exists and a new `DB_Chat_Ai_Budget` allows, re-answers the same message through the existing Claude client. The client gains token-usage accounting and prompt-caching markers; every failure falls back to the free answer the customer would have got anyway.

**Tech Stack:** PHP 8.3, WordPress 7.1, WooCommerce 10.9.4, plain JS (untouched), Anthropic Messages API over raw HTTP (`wp_remote_post`). Tests are PHP harnesses run with `wp eval-file` over SSH against the live install.

**Spec:** `docs/superpowers/specs/2026-09-14-chatbot-hybrid-ai-design.md`

## Global Constraints

- Prefix `db_chat_` / `DB_Chat_`. Text domain `durianbikers-chatbot`.
- PHP 8.0 floor. Lint every changed file with `/opt/homebrew/opt/php@8.3/bin/php -l` (8.3.33 = the server). Bare `php` on this Mac is 8.5.9 and proves nothing.
- **No test file is ever placed in the plugin directory.** Harnesses live in `$SCRATCH` and are uploaded to `~/claude-uploads/` on the VPS.
- **No automated test may reach the real Anthropic API.** Every harness installs the no-network guard below, except Task 1's, which installs its own interceptor that returns canned responses.
- **Every harness switches off unmatched-question logging for its own process** with `add_filter( 'db_chat_lookup_log_unmatched', '__return_false' );`. The live worklist must not collect test noise.
- **No test writes the real `db_chat_ai_spend` option.** Tests use their own option names and delete them.
- **The live `db_chat_mode` option stays `lookup` through Tasks 1–7.** Hybrid is switched on only in Task 8, and only after the operator confirms at that moment.
- **Never handle the API key.** The operator creates it, sets a console spend limit, and adds it themselves.
- Monthly cap default **RM100**. `USD_MYR` default **5.00** (deliberately above market). Haiku 4.5 **$1 input / $5 output per million tokens**, re-verified in Task 2 before use.
- Cache write multiplier **1.25**; cache read multiplier **0.10**. Haiku 4.5 minimum cacheable prefix **4,096 tokens**.
- AI is called **only** on the three misses (spec §4.2). **Never** for fitment, "we don't stock that", orders, or anything in the buying flow. The AI gets no cart tool.
- Over budget is a **silent** fallback. Unused daily budget **rolls forward** within the month.
- Months and days are in the **site timezone** (GMT+8), via `wp_date()` / `wp_timezone()`.
- Deploy one file at a time: `$SSH "cat > <remote path>" < <local path>`, then confirm `md5` matches both ends and lint remotely.
- WP-CLI on the VPS needs `-d memory_limit=512M`.

Shell shorthand used throughout:

```bash
SSH="ssh -i $HOME/.ssh/durianbikers_vps -p 8288 -o BatchMode=yes durianbi@103.233.1.74"
WPROOT=/var/www/vhosts/durianbikers.com/staging.durianbikers.com
WP="/opt/plesk/php/8.3/bin/php -d memory_limit=512M /usr/local/bin/wp --path=$WPROOT"
PLUG=$WPROOT/wp-content/plugins/durianbikers-chatbot
SCRATCH=/private/tmp/claude-501/-Users-superfly2026-sites-durianbikers/33a62eb6-8bc3-4efe-bd0a-a5fb95e120e4/scratchpad
```

The no-network guard, pasted at the top of every harness except Task 1's:

```php
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false !== strpos( (string) $url, 'api.anthropic.com' ) ) {
			throw new RuntimeException( 'TEST TRIED TO CALL THE REAL ANTHROPIC API' );
		}
		return $pre;
	},
	1,
	3
);
add_filter( 'db_chat_lookup_log_unmatched', '__return_false' );
```

---

## File Structure

| File | Responsibility | Tasks |
|---|---|---|
| `includes/class-claude-client.php` (modify) | Token-usage accounting, caching markers, never-judge rule | 1 |
| `includes/class-ai-budget.php` (create) | Estimates spend and decides whether an AI call is allowed | 2 |
| `durianbikers-chatbot.php` (modify) | Requires the budget class; `MODE_HYBRID`; engine selection; hybrid footnote | 2, 4, 6 |
| `includes/class-lookup-engine.php` (modify) | Miss flag; hybrid greeting and identity copy | 3, 6 |
| `includes/class-settings.php` (modify) | Hybrid option, status, monthly cap field, spend summary, key help text | 4 |
| `includes/class-chat-endpoint.php` (modify) | Injectable budget; the AI fallback inside the lookup branch | 5 |
| `DEPLOY.md` (modify) | How to switch hybrid on, safely | 7 |

---

### Task 1: Claude client — usage accounting, caching markers, never-judge rule

**Files:**
- Modify: `durianbikers-chatbot/includes/class-claude-client.php`
- Test: `$SCRATCH/t15-client.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `DB_Chat_Claude_Client::get_last_usage(): array` with integer keys `input_tokens`, `output_tokens`, `cache_creation_input_tokens`, `cache_read_input_tokens`, summed across every round of the most recent `chat()` call and kept when a later round fails.

- [ ] **Step 1: Write the failing test**

Create `$SCRATCH/t15-client.php`:

```php
<?php
// Task 1. Every request to api.anthropic.com is intercepted here and answered
// from a queue of canned responses, so no real API call can happen.
add_filter( 'db_chat_lookup_log_unmatched', '__return_false' );
$pass = 0; $fail = 0;
function ck( $l, $ok, &$p, &$f ) { printf( "%s %s\n", $ok ? 'PASS' : 'FAIL', $l ); $ok ? $p++ : $f++; }

class T15_Client extends DB_Chat_Claude_Client {
	public function has_api_key() { return true; }
}
class T15_NoKey extends DB_Chat_Claude_Client {
	public function has_api_key() { return false; }
}

$queue  = array();
$bodies = array();
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$queue, &$bodies ) {
		if ( false === strpos( (string) $url, 'api.anthropic.com' ) ) {
			return $pre;
		}
		$bodies[] = json_decode( (string) $args['body'], true );
		$next     = array_shift( $queue );
		if ( null === $next ) {
			return new WP_Error( 'test_no_canned', 'no canned response left' );
		}
		if ( $next instanceof WP_Error ) {
			return $next;
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $next ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

$text = function ( $t, $in, $out, $cw = 0, $cr = 0 ) {
	return array(
		'content'     => array( array( 'type' => 'text', 'text' => $t ) ),
		'stop_reason' => 'end_turn',
		'usage'       => array( 'input_tokens' => $in, 'output_tokens' => $out, 'cache_creation_input_tokens' => $cw, 'cache_read_input_tokens' => $cr ),
	);
};
$tool = function ( $in, $out ) {
	return array(
		'content'     => array( array( 'type' => 'tool_use', 'id' => 'tu_1', 'name' => 'search_products', 'input' => array( 'query' => 'shinko' ) ) ),
		'stop_reason' => 'tool_use',
		'usage'       => array( 'input_tokens' => $in, 'output_tokens' => $out, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0 ),
	);
};
$exec = function ( $name, $input ) { return array( 'results' => array(), 'count' => 0, 'truncated' => false ); };
$msgs = array( array( 'role' => 'user', 'content' => 'hello' ) );
$c    = new T15_Client();

// 1. One round.
$queue = array( $text( 'Hi there', 1200, 80, 300, 900 ) );
$r     = $c->chat( $msgs, '', $exec, '' );
$u     = $c->get_last_usage();
ck( 'single round returns the text', 'Hi there' === $r, $pass, $fail );
ck( 'single round usage recorded', 1200 === $u['input_tokens'] && 80 === $u['output_tokens'] && 300 === $u['cache_creation_input_tokens'] && 900 === $u['cache_read_input_tokens'], $pass, $fail );

// 2. A tool loop is summed.
$queue = array( $tool( 1000, 40 ), $text( 'Found it', 1500, 60 ) );
$r     = $c->chat( $msgs, '', $exec, '' );
$u     = $c->get_last_usage();
ck( 'tool loop returns the final text', 'Found it' === $r, $pass, $fail );
ck( 'usage summed across rounds (in 2500, out 100)', 2500 === $u['input_tokens'] && 100 === $u['output_tokens'], $pass, $fail );

// 3. A failure after a billed round keeps that round.
$queue = array( $tool( 1000, 40 ), new WP_Error( 'boom', 'transport down' ) );
$r     = $c->chat( $msgs, '', $exec, '' );
$u     = $c->get_last_usage();
ck( 'second-round failure returns WP_Error', is_wp_error( $r ), $pass, $fail );
ck( 'billed first round still counted (in 1000, out 40)', 1000 === $u['input_tokens'] && 40 === $u['output_tokens'], $pass, $fail );

// 4. Usage resets for each call.
$queue = array( $text( 'Again', 10, 5 ) );
$c->chat( $msgs, '', $exec, '' );
$u = $c->get_last_usage();
ck( 'usage resets for each chat() call', 10 === $u['input_tokens'] && 5 === $u['output_tokens'], $pass, $fail );

// 5. Caching markers on the wire.
$queue  = array( $text( 'ok', 1, 1 ) );
$bodies = array();
$c->chat( $msgs, 'Brands: Shinko', $exec, 'FAQ' );
$b    = $bodies[0];
$last = end( $b['tools'] );
ck( 'system is sent as a text block', is_array( $b['system'] ) && 'text' === ( $b['system'][0]['type'] ?? '' ), $pass, $fail );
ck( 'system block is marked ephemeral', 'ephemeral' === ( $b['system'][0]['cache_control']['type'] ?? '' ), $pass, $fail );
ck( 'last tool is marked ephemeral', 'ephemeral' === ( $last['cache_control']['type'] ?? '' ), $pass, $fail );
ck( 'only the last tool is marked', 1 === count( array_filter( $b['tools'], function ( $t ) { return isset( $t['cache_control'] ); } ) ), $pass, $fail );

// 6. Never-judge rule.
ck( 'system prompt forbids saying which product is better', false !== stripos( (string) ( $b['system'][0]['text'] ?? '' ), 'Never tell a customer which product is better' ), $pass, $fail );

// 7. No key: no request, zero usage.
$bodies = array();
$n      = new T15_NoKey();
$r      = $n->chat( $msgs, '', $exec, '' );
ck( 'no key returns WP_Error without a request', is_wp_error( $r ) && 0 === count( $bodies ), $pass, $fail );
ck( 'no key reports zero usage', 0 === array_sum( $n->get_last_usage() ), $pass, $fail );

echo "\n==== $pass passed, $fail failed ====\n";
```

- [ ] **Step 2: Run it to verify it fails**

```bash
$SSH "cat > ~/claude-uploads/t15-client.php" < $SCRATCH/t15-client.php
$SSH "$WP eval-file ~/claude-uploads/t15-client.php"
```

Expected: `single round returns the text` passes, then a fatal: `Call to undefined method T15_Client::get_last_usage()`.

- [ ] **Step 3: Write the implementation**

In `class-claude-client.php`, immediately after `const MAX_TOOL_ROUNDS = 4;`, add:

```php
	/**
	 * Token usage of the most recent chat() call, summed across every round.
	 *
	 * Kept when a later round fails: those earlier rounds were billed, and the
	 * hybrid budget must never undercount what was actually spent.
	 *
	 * @var array<string,int>
	 */
	private $last_usage = array();
```

As the **first line** inside `chat()`, before the `has_api_key()` check:

```php
		$this->last_usage = self::empty_usage();
```

Replace `$tools  = $this->get_tools();` in `chat()` with:

```php
		$tools = $this->get_tools();

		/*
		 * Cache breakpoints. Render order is tools → system → messages, so a
		 * marker on the last tool caches the tool definitions and a marker on
		 * the system block caches tools plus prompt. Haiku 4.5 only caches a
		 * prefix of 4,096+ tokens; below that these are silently ignored, which
		 * costs nothing.
		 */
		if ( ! empty( $tools ) ) {
			$last_tool                            = count( $tools ) - 1;
			$tools[ $last_tool ]['cache_control'] = array( 'type' => 'ephemeral' );
		}
```

In the request body inside the loop, replace `'system'     => $system,` with:

```php
					'system'     => $this->cacheable_system( $system ),
```

Directly after the existing `if ( is_wp_error( $response ) ) { return $response; }` inside the loop, add:

```php
			$this->add_usage( $response );
```

Add these methods after `chat()`:

```php
	/**
	 * Usage of the most recent chat() call.
	 *
	 * @return array<string,int>
	 */
	public function get_last_usage() {
		return array_merge( self::empty_usage(), $this->last_usage );
	}

	/**
	 * Zeroed usage record.
	 *
	 * @return array<string,int>
	 */
	private static function empty_usage() {
		return array(
			'input_tokens'                => 0,
			'output_tokens'               => 0,
			'cache_creation_input_tokens' => 0,
			'cache_read_input_tokens'     => 0,
		);
	}

	/**
	 * Add one response's usage to the running total.
	 *
	 * @param array $response Decoded API response.
	 * @return void
	 */
	private function add_usage( array $response ) {
		if ( empty( $response['usage'] ) || ! is_array( $response['usage'] ) ) {
			return;
		}

		foreach ( array_keys( self::empty_usage() ) as $key ) {
			if ( isset( $response['usage'][ $key ] ) && is_numeric( $response['usage'][ $key ] ) ) {
				$current                  = isset( $this->last_usage[ $key ] ) ? (int) $this->last_usage[ $key ] : 0;
				$this->last_usage[ $key ] = $current + max( 0, (int) $response['usage'][ $key ] );
			}
		}
	}

	/**
	 * The system prompt as a cacheable text block.
	 *
	 * build_system_prompt() and its db_chat_system_prompt filter keep returning
	 * a plain string; the wrapping happens only here, at the wire.
	 *
	 * @param string $system Prompt text.
	 * @return array[]
	 */
	private function cacheable_system( $system ) {
		return array(
			array(
				'type'          => 'text',
				'text'          => (string) $system,
				'cache_control' => array( 'type' => 'ephemeral' ),
			),
		);
	}
```

In `build_system_prompt()`, find this line of the prompt:

```
- If a search returns nothing relevant, say we do not appear to stock it and offer to pass the question to a human. Never invent a product, a price, a stock level, a URL or a specification.
```

and add, as the very next line inside the same double-quoted string:

```
- Never tell a customer which product is better, best, or right for them. You may set out the facts from the search results side by side — name, price, stock, brand — but the choice is theirs. For anything that depends on their bike, their riding or their size, point them to a person.
```

- [ ] **Step 4: Lint, deploy, run**

```bash
/opt/homebrew/opt/php@8.3/bin/php -l durianbikers-chatbot/includes/class-claude-client.php
$SSH "cat > $PLUG/includes/class-claude-client.php" < durianbikers-chatbot/includes/class-claude-client.php
md5 -q durianbikers-chatbot/includes/class-claude-client.php
$SSH "md5sum $PLUG/includes/class-claude-client.php | cut -d' ' -f1; /opt/plesk/php/8.3/bin/php -l $PLUG/includes/class-claude-client.php"
$SSH "$WP eval-file ~/claude-uploads/t15-client.php"
```

Expected: checksums identical, `14 passed, 0 failed`.

- [ ] **Step 5: Commit**

```bash
git add durianbikers-chatbot/includes/class-claude-client.php
git commit -m "feat: client reports token usage, marks cache breakpoints, never judges products"
```

---

### Task 2: `DB_Chat_Ai_Budget` — the RM100 guard

**Files:**
- Create: `durianbikers-chatbot/includes/class-ai-budget.php`
- Modify: `durianbikers-chatbot/durianbikers-chatbot.php` (require the file)
- Test: `$SCRATCH/t16-budget.php`

**Interfaces:**
- Consumes: the usage array shape from Task 1.
- Produces:
  - `new DB_Chat_Ai_Budget( string $option = 'db_chat_ai_spend', ?callable $clock = null )`
  - `can_spend(): bool`, `record( array $usage ): void`, `cost_myr( array $usage ): float`
  - `month_to_date_myr(): float`, `calls_this_month(): int`, `cap_myr(): float`, `last_usage(): array`
  - Constants `OPTION`, `CAP_OPTION` (`db_chat_ai_monthly_cap_myr`), `DEFAULT_CAP_MYR` (`100.0`)

- [ ] **Step 1: Re-verify the prices**

Fetch Anthropic's current pricing (https://www.anthropic.com/pricing or https://docs.anthropic.com/en/docs/about-claude/pricing) and confirm Claude Haiku 4.5 is **$1 input / $5 output per million tokens**, cache writes **1.25×** and cache reads **0.10×** of input. If any figure differs, use the published figure in both Step 2's expected values and Step 4's constants, and say so in the commit message.

- [ ] **Step 2: Write the failing test**

Create `$SCRATCH/t16-budget.php`:

```php
<?php
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) { if ( false !== strpos( (string) $url, 'api.anthropic.com' ) ) { throw new RuntimeException( 'TEST TRIED TO CALL THE REAL ANTHROPIC API' ); } return $pre; }, 1, 3 );
add_filter( 'db_chat_lookup_log_unmatched', '__return_false' );
$pass = 0; $fail = 0;
function ck( $l, $ok, &$p, &$f ) { printf( "%s %s\n", $ok ? 'PASS' : 'FAIL', $l ); $ok ? $p++ : $f++; }
function near( $a, $b ) { return abs( (float) $a - (float) $b ) < 0.000001; }

$opt = 'db_chat_ai_spend_test';
delete_option( $opt );
add_filter( 'db_chat_ai_monthly_cap_myr', function () { return 100.0; } );

$tz   = wp_timezone();
$at   = function ( $s ) use ( $tz ) { return ( new DateTimeImmutable( $s, $tz ) )->getTimestamp(); };
$now  = $at( '2026-09-14 10:00' );
$b    = new DB_Chat_Ai_Budget( $opt, function () use ( &$now ) { return $now; } );
$m1in = array( 'input_tokens' => 1000000, 'output_tokens' => 0, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0 );

// Cost formula.
ck( '1M input = RM5.00', near( 5.00, $b->cost_myr( $m1in ) ), $pass, $fail );
ck( '1M output = RM25.00', near( 25.00, $b->cost_myr( array( 'output_tokens' => 1000000 ) ) ), $pass, $fail );
ck( '1M cache write = RM6.25', near( 6.25, $b->cost_myr( array( 'cache_creation_input_tokens' => 1000000 ) ) ), $pass, $fail );
ck( '1M cache read = RM0.50', near( 0.50, $b->cost_myr( array( 'cache_read_input_tokens' => 1000000 ) ) ), $pass, $fail );
ck( 'mixed call prices correctly', near( 0.010325, $b->cost_myr( array( 'input_tokens' => 1200, 'output_tokens' => 80, 'cache_creation_input_tokens' => 300, 'cache_read_input_tokens' => 900 ) ) ), $pass, $fail );

// Fresh month.
ck( 'fresh month can spend', $b->can_spend(), $pass, $fail );
ck( 'fresh month spend is 0', near( 0, $b->month_to_date_myr() ), $pass, $fail );

// Daily allowance on the 14th: 100 / 17 days left = 5.88.
$b->record( $m1in );
ck( 'RM5 today is under a RM5.88 allowance', $b->can_spend(), $pass, $fail );
$b->record( $m1in );
ck( 'RM10 today is over the allowance', ! $b->can_spend(), $pass, $fail );
ck( 'two calls counted', 2 === $b->calls_this_month(), $pass, $fail );
ck( 'last usage stored', 1000000 === (int) ( $b->last_usage()['input_tokens'] ?? 0 ), $pass, $fail );
wp_cache_delete( 'alloptions', 'options' );
ck( 'spend option exists and is not autoloaded', false !== get_option( $opt, false ) && ! array_key_exists( $opt, wp_load_alloptions() ), $pass, $fail );

// Next day: (100 - 10) / 16 = 5.625 allowance, nothing spent yet today.
$now = $at( '2026-09-15 10:00' );
ck( 'a new day can spend again', $b->can_spend(), $pass, $fail );
ck( 'month spend carries over the day change', near( 10, $b->month_to_date_myr() ), $pass, $fail );

// Quiet days roll forward.
delete_option( $opt );
$now = $at( '2026-09-01 10:00' );
$b->record( array( 'input_tokens' => 1600000 ) ); // RM8 on the 1st; allowance 100/30 = 3.33
ck( 'RM8 on the 1st exceeds that day\'s allowance', ! $b->can_spend(), $pass, $fail );
delete_option( $opt );
$now = $at( '2026-09-20 10:00' );
$b->can_spend(); // reading never writes; only record() persists
$b->record( array( 'input_tokens' => 1600000 ) ); // RM8 on the 20th; allowance 100/11 = 9.09
ck( 'RM8 on the 20th after quiet days is allowed', $b->can_spend(), $pass, $fail );

// Month rollover.
$now = $at( '2026-10-01 00:05' );
ck( 'new month resets spend', near( 0, $b->month_to_date_myr() ), $pass, $fail );
ck( 'new month can spend', $b->can_spend(), $pass, $fail );

// Cap.
$b->record( array( 'input_tokens' => 20000000 ) ); // RM100
ck( 'cap reached blocks spending', ! $b->can_spend(), $pass, $fail );

// Zero usage is not a call.
delete_option( $opt );
$b->record( array( 'input_tokens' => 0, 'output_tokens' => 0 ) );
ck( 'zero-token record is ignored', 0 === $b->calls_this_month(), $pass, $fail );

// Cap of zero.
add_filter( 'db_chat_ai_monthly_cap_myr', function () { return 0.0; }, 20 );
ck( 'cap of RM0 never spends', ! $b->can_spend(), $pass, $fail );
ck( 'reading the budget never creates the option', false === get_option( $opt, false ), $pass, $fail );

delete_option( $opt );
echo "\n==== $pass passed, $fail failed ====\n";
```

- [ ] **Step 3: Run it to verify it fails**

```bash
$SSH "cat > ~/claude-uploads/t16-budget.php" < $SCRATCH/t16-budget.php
$SSH "$WP eval-file ~/claude-uploads/t16-budget.php"
```

Expected: fatal, `Class "DB_Chat_Ai_Budget" not found`.

- [ ] **Step 4: Write the implementation**

Create `durianbikers-chatbot/includes/class-ai-budget.php`:

```php
<?php
/**
 * Monthly spend guard for hybrid mode.
 *
 * An ESTIMATE, priced deliberately high. It exists so a busy week cannot run
 * up an unexpected bill before anyone notices, and so running out degrades to
 * the free answer instead of an error. It is not a billing system: the spend
 * limit set in the Anthropic console is the only hard stop.
 *
 * @package DurianBikers_Chatbot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tracks estimated AI spend and decides whether another call is allowed.
 */
class DB_Chat_Ai_Budget {

	/** Option holding this month's spend. Autoload off. */
	const OPTION = 'db_chat_ai_spend';

	/** Option holding the monthly cap in MYR. */
	const CAP_OPTION = 'db_chat_ai_monthly_cap_myr';

	/** Default monthly cap in MYR. */
	const DEFAULT_CAP_MYR = 100.0;

	/** USD per million input tokens, Claude Haiku 4.5. */
	const PRICE_IN_USD_PER_MTOK = 1.00;

	/** USD per million output tokens, Claude Haiku 4.5. */
	const PRICE_OUT_USD_PER_MTOK = 5.00;

	/** Cache writes cost this multiple of the input price. */
	const CACHE_WRITE_MULTIPLIER = 1.25;

	/** Cache reads cost this multiple of the input price. */
	const CACHE_READ_MULTIPLIER = 0.10;

	/**
	 * Ringgit per US dollar, deliberately ABOVE the market rate.
	 *
	 * Overestimating cost trips the cap early. Underestimating it would let
	 * real spend run past what the owner agreed to before this noticed.
	 */
	const USD_MYR = 5.00;

	/**
	 * Option name. Overridable so tests never touch the live record.
	 *
	 * @var string
	 */
	private $option;

	/**
	 * Returns the current Unix time. Injectable so tests can move through days.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param string        $option Option name.
	 * @param callable|null $clock  Returns a Unix timestamp.
	 */
	public function __construct( $option = self::OPTION, $clock = null ) {
		$this->option = (string) $option;
		$this->clock  = is_callable( $clock ) ? $clock : 'time';
	}

	/**
	 * The monthly cap in MYR.
	 *
	 * @return float
	 */
	public function cap_myr() {
		$cap = (float) get_option( self::CAP_OPTION, self::DEFAULT_CAP_MYR );

		/**
		 * Filter the monthly AI cap, in MYR.
		 *
		 * @param float $cap Cap.
		 */
		$cap = (float) apply_filters( 'db_chat_ai_monthly_cap_myr', $cap );

		return $cap > 0 ? $cap : 0.0;
	}

	/**
	 * Estimated cost of one call, in MYR.
	 *
	 * @param array $usage Usage as returned by DB_Chat_Claude_Client::get_last_usage().
	 * @return float
	 */
	public function cost_myr( array $usage ) {
		$in  = self::tokens( $usage, 'input_tokens' );
		$out = self::tokens( $usage, 'output_tokens' );
		$cw  = self::tokens( $usage, 'cache_creation_input_tokens' );
		$cr  = self::tokens( $usage, 'cache_read_input_tokens' );

		$p_in  = (float) apply_filters( 'db_chat_ai_price_in', self::PRICE_IN_USD_PER_MTOK );
		$p_out = (float) apply_filters( 'db_chat_ai_price_out', self::PRICE_OUT_USD_PER_MTOK );
		$rate  = (float) apply_filters( 'db_chat_ai_usd_myr', self::USD_MYR );

		$usd = ( $in * $p_in
			+ $out * $p_out
			+ $cw * $p_in * self::CACHE_WRITE_MULTIPLIER
			+ $cr * $p_in * self::CACHE_READ_MULTIPLIER ) / 1000000;

		return $usd * $rate;
	}

	/**
	 * May another AI call be made right now?
	 *
	 * The daily allowance is recomputed from what is actually left, so a quiet
	 * day's unused budget rolls forward, while one busy day still cannot drain
	 * the month.
	 *
	 * @return bool
	 */
	public function can_spend() {
		$cap   = $this->cap_myr();
		$state = $this->state();

		if ( $cap <= 0 || $state['month_myr'] >= $cap ) {
			return false;
		}

		$allowance = ( $cap - $state['day_start_myr'] ) / $this->days_left();
		$today     = $state['month_myr'] - $state['day_start_myr'];

		return $today < $allowance;
	}

	/**
	 * Charge one call.
	 *
	 * Called after EVERY call that consumed tokens, including one that failed
	 * after earlier rounds succeeded — those rounds were billed.
	 *
	 * @param array $usage Usage.
	 * @return void
	 */
	public function record( array $usage ) {
		$tokens = self::tokens( $usage, 'input_tokens' )
			+ self::tokens( $usage, 'output_tokens' )
			+ self::tokens( $usage, 'cache_creation_input_tokens' )
			+ self::tokens( $usage, 'cache_read_input_tokens' );

		if ( $tokens <= 0 ) {
			return;
		}

		$state               = $this->state();
		$state['month_myr'] += $this->cost_myr( $usage );
		$state['calls']      = (int) $state['calls'] + 1;
		$state['last_usage'] = array(
			'input_tokens'                => self::tokens( $usage, 'input_tokens' ),
			'output_tokens'               => self::tokens( $usage, 'output_tokens' ),
			'cache_creation_input_tokens' => self::tokens( $usage, 'cache_creation_input_tokens' ),
			'cache_read_input_tokens'     => self::tokens( $usage, 'cache_read_input_tokens' ),
		);

		update_option( $this->option, $state, false );
	}

	/**
	 * Estimated spend so far this month, MYR.
	 *
	 * @return float
	 */
	public function month_to_date_myr() {
		return (float) $this->state()['month_myr'];
	}

	/**
	 * AI calls charged this month.
	 *
	 * @return int
	 */
	public function calls_this_month() {
		return (int) $this->state()['calls'];
	}

	/**
	 * Token counts of the most recently charged call. No message content.
	 *
	 * @return array<string,int>
	 */
	public function last_usage() {
		$state = $this->state();

		return is_array( $state['last_usage'] ) ? $state['last_usage'] : array();
	}

	/**
	 * Current record, rolled over to the current month and day — IN MEMORY.
	 *
	 * Never writes. Only record() persists, so merely reading the budget (the
	 * settings screen does) can never create or change the spend option. The
	 * result is the same: on a day with no spend yet, recomputing "start of
	 * today" from the stored month total gives an identical value every time.
	 *
	 * @return array<string,mixed>
	 */
	private function state() {
		$now   = (int) call_user_func( $this->clock );
		$month = wp_date( 'Y-m', $now );
		$day   = wp_date( 'Y-m-d', $now );

		$stored = get_option( $this->option, array() );
		$state  = array_merge(
			array(
				'month'         => '',
				'month_myr'     => 0.0,
				'day'           => '',
				'day_start_myr' => 0.0,
				'calls'         => 0,
				'last_usage'    => array(),
			),
			is_array( $stored ) ? $stored : array()
		);

		if ( $month !== $state['month'] ) {
			$state = array(
				'month'         => $month,
				'month_myr'     => 0.0,
				'day'           => $day,
				'day_start_myr' => 0.0,
				'calls'         => 0,
				'last_usage'    => array(),
			);
		} elseif ( $day !== $state['day'] ) {
			$state['day']           = $day;
			$state['day_start_myr'] = (float) $state['month_myr'];
		}

		return $state;
	}

	/**
	 * Days left in the month, today included. Never zero.
	 *
	 * @return int
	 */
	private function days_left() {
		$now = (int) call_user_func( $this->clock );

		return max( 1, (int) wp_date( 't', $now ) - (int) wp_date( 'j', $now ) + 1 );
	}

	/**
	 * One non-negative token count.
	 *
	 * @param array  $usage Usage.
	 * @param string $key   Field.
	 * @return int
	 */
	private static function tokens( array $usage, $key ) {
		return isset( $usage[ $key ] ) && is_numeric( $usage[ $key ] ) ? max( 0, (int) $usage[ $key ] ) : 0;
	}
}
```

In `durianbikers-chatbot.php`, next to the other `require_once DB_CHAT_PATH . 'includes/…'` lines, add:

```php
require_once DB_CHAT_PATH . 'includes/class-ai-budget.php';
```

- [ ] **Step 5: Lint, deploy, run**

```bash
for f in includes/class-ai-budget.php durianbikers-chatbot.php; do /opt/homebrew/opt/php@8.3/bin/php -l durianbikers-chatbot/$f; done
$SSH "cat > $PLUG/includes/class-ai-budget.php" < durianbikers-chatbot/includes/class-ai-budget.php
$SSH "cat > $PLUG/durianbikers-chatbot.php" < durianbikers-chatbot/durianbikers-chatbot.php
md5 -q durianbikers-chatbot/includes/class-ai-budget.php durianbikers-chatbot/durianbikers-chatbot.php
$SSH "md5sum $PLUG/includes/class-ai-budget.php $PLUG/durianbikers-chatbot.php | cut -d' ' -f1"
$SSH "$WP eval-file ~/claude-uploads/t16-budget.php"
curl -s -m 25 -o /dev/null -w "%{http_code}\n" -L https://durianbikers.com/my/checkout/
```

Expected: checksums identical, `22 passed, 0 failed`, checkout `200`.

- [ ] **Step 6: Commit**

```bash
git add durianbikers-chatbot/includes/class-ai-budget.php durianbikers-chatbot/durianbikers-chatbot.php
git commit -m "feat: add AI budget guard with daily allowance and monthly cap"
```

---

### Task 3: The miss flag in the lookup engine

**Files:**
- Modify: `durianbikers-chatbot/includes/class-lookup-engine.php`
- Test: `$SCRATCH/t17-miss-flag.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `DB_Chat_Lookup_Engine::needs_ai_fallback(): bool`, true only when the most recent `answer()` genuinely failed to understand the message.

- [ ] **Step 1: Write the failing test**

Create `$SCRATCH/t17-miss-flag.php`. The routing probes come first and use the existing public `detect_intent()`, so a wrong input fails visibly rather than passing for the wrong reason.

```php
<?php
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) { if ( false !== strpos( (string) $url, 'api.anthropic.com' ) ) { throw new RuntimeException( 'TEST TRIED TO CALL THE REAL ANTHROPIC API' ); } return $pre; }, 1, 3 );
add_filter( 'db_chat_lookup_log_unmatched', '__return_false' );
$pass = 0; $fail = 0;
function ck( $l, $ok, &$p, &$f ) { printf( "%s %s\n", $ok ? 'PASS' : 'FAIL', $l ); $ok ? $p++ : $f++; }

$e = new DB_Chat_Lookup_Engine( new DB_Chat_Product_Index(), new DB_Chat_Content_Index() );

// Routing probes (existing API).
ck( 'probe: comparison input routes to compare', 'compare' === $e->detect_intent( 'compare zxqvblorf and quimbleplonk' ), $pass, $fail );
ck( 'probe: priced no-result input is understood', 'fallback' !== $e->detect_intent( 'berapa harga zxqvblorf' ), $pass, $fail );

$flag = function ( $msg, $pending = null ) use ( $e ) {
	$e->answer( $msg, array(), $pending );
	return $e->needs_ai_fallback();
};

// The three misses.
ck( 'unclassifiable message sets the flag', $flag( 'meow' ), $pass, $fail );
ck( 'no keyword and no product sets the flag', $flag( 'zxqv blorf quimble' ), $pass, $fail );
ck( 'comparison resolving neither side sets the flag', $flag( 'compare zxqvblorf and quimbleplonk' ), $pass, $fail );

// Never a miss.
ck( 'fitment does NOT set the flag', ! $flag( 'will this fit my yamaha r15' ), $pass, $fail );
ck( 'understood search with no result does NOT', ! $flag( 'berapa harga zxqvblorf' ), $pass, $fail );
ck( 'order question does NOT', ! $flag( 'where is my order' ), $pass, $fail );
ck( 'greeting does NOT', ! $flag( 'hello' ), $pass, $fail );
ck( 'real product search does NOT', ! $flag( 'shinko 705' ), $pass, $fail );

$r1 = $e->answer( 'i want yamanaka for 2pcs' );
ck( 'buying opener does NOT', ! $e->needs_ai_fallback(), $pass, $fail );
$e->answer( 'MT Helmet THUNDER 4 SV (YAMANAKA 2025 A5 GLOSS)', array(), $r1['pending'] );
ck( 'answering "which one" does NOT', ! $e->needs_ai_fallback(), $pass, $fail );

$sizes   = ( new DB_Chat_Cart_Writer() )->in_stock_variations( 58024 );
$confirm = array(
	'stage'        => DB_Chat_Pending_Order::STAGE_CONFIRM,
	'product_id'   => 58024,
	'variation_id' => (int) $sizes[0]['variation_id'],
	'quantity'     => 1,
	'offered'      => array(),
	'created'      => time(),
);
$e->answer( 'no', array(), $confirm );
ck( 'declining a confirmation does NOT', ! $e->needs_ai_fallback(), $pass, $fail );

// Reset between answers.
$flag( 'meow' );
$e->answer( 'shinko 705' );
ck( 'flag resets on the next answer', ! $e->needs_ai_fallback(), $pass, $fail );

echo "\n==== $pass passed, $fail failed ====\n";
```

The confirmation above is **declined**, so no cart line is ever written.

- [ ] **Step 2: Run it to verify it fails**

```bash
$SSH "cat > ~/claude-uploads/t17-miss-flag.php" < $SCRATCH/t17-miss-flag.php
$SSH "$WP eval-file ~/claude-uploads/t17-miss-flag.php"
```

Expected: both probes PASS, then fatal `Call to undefined method DB_Chat_Lookup_Engine::needs_ai_fallback()`. **If a probe fails, stop**: that input does not route where the test assumes, and the rest of the test would be meaningless.

- [ ] **Step 3: Write the implementation**

Immediately after the line `private $pending_out = null;`, add:

```php
	/**
	 * Did THIS answer() genuinely fail to understand the message?
	 *
	 * Set at exactly three points — the 'fallback' intent, a blind product miss
	 * (no keyword AND no product), and a comparison where neither side
	 * resolved. Deliberately NOT inside log_unmatched(): that function skips
	 * order questions, emails, long digit runs, and everything when logging is
	 * off, so using it would make whether a customer gets an AI answer depend
	 * on a logging setting.
	 *
	 * @var bool
	 */
	private $ai_fallback = false;
```

In `answer()`, replace:

```php
		$this->order_turn  = false;
		$this->pending_out = null;
```

with:

```php
		$this->order_turn  = false;
		$this->pending_out = null;
		$this->ai_fallback = false;
```

Immediately after the `used_order_lookup()` method, add:

```php
	/**
	 * Should the hybrid endpoint ask Claude instead?
	 *
	 * @return bool
	 */
	public function needs_ai_fallback() {
		return (bool) $this->ai_fallback;
	}
```

In `answer()`, replace:

```php
			case 'fallback':
				$this->log_unmatched( $message, $signals, $language );
```

with:

```php
			case 'fallback':
				$this->ai_fallback = true;
				$this->log_unmatched( $message, $signals, $language );
```

In `answer_products()`, replace:

```php
			if ( $blind ) {
				// No keyword recognised AND no product found: we genuinely did
				// not understand it, which is exactly the worklist entry the
				// owner wants.
				$this->log_unmatched( $raw, $signals, $language );
			}
```

with:

```php
			if ( $blind ) {
				// No keyword recognised AND no product found: we genuinely did
				// not understand it, which is exactly the worklist entry the
				// owner wants — and exactly the question hybrid mode hands on.
				$this->ai_fallback = true;
				$this->log_unmatched( $raw, $signals, $language );
			}
```

In `answer_compare()`, replace:

```php
			$this->log_unmatched( $raw, $signals, $language );

			$lines[] = $this->text( $language, 'compare_none', $parsed['sides'][0], $parsed['sides'][1] );
```

with:

```php
			$this->ai_fallback = true;
			$this->log_unmatched( $raw, $signals, $language );

			$lines[] = $this->text( $language, 'compare_none', $parsed['sides'][0], $parsed['sides'][1] );
```

- [ ] **Step 4: Lint, deploy, run**

```bash
/opt/homebrew/opt/php@8.3/bin/php -l durianbikers-chatbot/includes/class-lookup-engine.php
$SSH "cat > $PLUG/includes/class-lookup-engine.php" < durianbikers-chatbot/includes/class-lookup-engine.php
md5 -q durianbikers-chatbot/includes/class-lookup-engine.php
$SSH "md5sum $PLUG/includes/class-lookup-engine.php | cut -d' ' -f1"
$SSH "$WP eval-file ~/claude-uploads/t17-miss-flag.php"
```

Expected: checksums identical, `14 passed, 0 failed`.

- [ ] **Step 5: Commit**

```bash
git add durianbikers-chatbot/includes/class-lookup-engine.php
git commit -m "feat: flag genuine misses in the lookup engine for hybrid fallback"
```

---

### Task 4: Hybrid mode and the settings screen

**Files:**
- Modify: `durianbikers-chatbot/durianbikers-chatbot.php`
- Modify: `durianbikers-chatbot/includes/class-settings.php`
- Test: `$SCRATCH/t18-mode.php`

**Interfaces:**
- Consumes: `DB_Chat_Ai_Budget` (Task 2).
- Produces: `DB_Chat_Plugin::MODE_HYBRID` (`'hybrid'`), accepted by `get_mode()` and `DB_Chat_Settings::sanitize_mode()`. In hybrid, `get_engine()` returns `ENGINE_LOOKUP` when the lookup engine exists, otherwise `ENGINE_NONE`.

- [ ] **Step 1: Write the failing test**

Create `$SCRATCH/t18-mode.php`:

```php
<?php
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) { if ( false !== strpos( (string) $url, 'api.anthropic.com' ) ) { throw new RuntimeException( 'TEST TRIED TO CALL THE REAL ANTHROPIC API' ); } return $pre; }, 1, 3 );
add_filter( 'db_chat_lookup_log_unmatched', '__return_false' );
$pass = 0; $fail = 0;
function ck( $l, $ok, &$p, &$f ) { printf( "%s %s\n", $ok ? 'PASS' : 'FAIL', $l ); $ok ? $p++ : $f++; }

// Settings render first, before anything else reads settings in this process.
add_filter( 'pre_option_db_chat_mode', function () { return 'hybrid'; } );
$render = new ReflectionMethod( 'DB_Chat_Settings', 'render_mode_section' );
$render->setAccessible( true );
ob_start();
$render->invoke( null );
$html = (string) ob_get_clean();
ck( 'mode picker offers Hybrid', false !== strpos( $html, 'value="hybrid"' ), $pass, $fail );
ck( 'status line describes hybrid', false !== strpos( $html, 'Claude for questions it cannot understand' ), $pass, $fail );
ck( 'AI spend summary shown in hybrid', false !== strpos( $html, 'AI spend this month' ), $pass, $fail );
ck( 'monthly cap field shown', false !== strpos( $html, 'db_chat_ai_monthly_cap_myr' ), $pass, $fail );

ck( 'sanitize_mode keeps hybrid', 'hybrid' === DB_Chat_Settings::sanitize_mode( 'hybrid' ), $pass, $fail );
ck( 'sanitize_mode still rejects junk', 'auto' === DB_Chat_Settings::sanitize_mode( 'nonsense' ), $pass, $fail );
ck( 'sanitize_cap clamps negatives to 0', '0' === DB_Chat_Settings::sanitize_cap( '-5' ), $pass, $fail );
ck( 'sanitize_cap keeps a normal value', '100' === DB_Chat_Settings::sanitize_cap( '100' ), $pass, $fail );

add_filter( 'db_chat_mode', function () { return 'hybrid'; } );
ck( 'MODE_HYBRID constant exists', defined( 'DB_Chat_Plugin::MODE_HYBRID' ) && 'hybrid' === DB_Chat_Plugin::MODE_HYBRID, $pass, $fail );
ck( 'get_mode accepts hybrid', 'hybrid' === db_chat()->get_mode(), $pass, $fail );
ck( 'hybrid runs on the lookup engine', DB_Chat_Plugin::ENGINE_LOOKUP === db_chat()->get_engine(), $pass, $fail );

$prop = new ReflectionProperty( 'DB_Chat_Plugin', 'lookup_engine' );
$prop->setAccessible( true );
$saved = $prop->getValue( db_chat() );
$prop->setValue( db_chat(), null );
ck( 'hybrid with no lookup engine switches off', DB_Chat_Plugin::ENGINE_NONE === db_chat()->get_engine(), $pass, $fail );
$prop->setValue( db_chat(), $saved );

ck( 'key help no longer claims free lookup makes no external calls in hybrid', false === strpos( file_get_contents( WP_PLUGIN_DIR . '/durianbikers-chatbot/includes/class-settings.php' ), 'Only used by the Claude modes — free lookup mode needs no key and makes no external calls.' ), $pass, $fail );

echo "\n==== $pass passed, $fail failed ====\n";
```

- [ ] **Step 2: Run it to verify it fails**

```bash
$SSH "cat > ~/claude-uploads/t18-mode.php" < $SCRATCH/t18-mode.php
$SSH "$WP eval-file ~/claude-uploads/t18-mode.php"
```

Expected: the picker/status/summary/cap assertions FAIL, then a fatal on `sanitize_cap()`.

If, after Step 4, **only** the status-line and summary assertions still fail, `DB_Chat_Settings::get()` is not reading `db_chat_mode` through `get_option()` in this process. Read `DB_Chat_Settings::get()` and override the mode the way it actually reads it; do not weaken the assertion.

- [ ] **Step 3: Implement in `durianbikers-chatbot.php`**

Replace:

```php
	/** Mode: always Claude; offline (503) when no key is configured. */
	const MODE_AI = 'ai';

	/** Every accepted mode value. */
	const MODES = array( self::MODE_AUTO, self::MODE_LOOKUP, self::MODE_AI );
```

with:

```php
	/** Mode: always Claude; offline (503) when no key is configured. */
	const MODE_AI = 'ai';

	/**
	 * Mode: the free lookup engine answers first; Claude answers only what it
	 * genuinely cannot understand, within the monthly AI budget.
	 */
	const MODE_HYBRID = 'hybrid';

	/** Every accepted mode value. */
	const MODES = array( self::MODE_AUTO, self::MODE_LOOKUP, self::MODE_AI, self::MODE_HYBRID );
```

In `get_engine()`, replace:

```php
		if ( self::MODE_LOOKUP === $mode ) {
			return null !== $this->lookup_engine ? self::ENGINE_LOOKUP : self::ENGINE_NONE;
		}
```

with:

```php
		if ( self::MODE_LOOKUP === $mode ) {
			return null !== $this->lookup_engine ? self::ENGINE_LOOKUP : self::ENGINE_NONE;
		}

		/*
		 * Hybrid runs ON the lookup engine; the AI step lives inside the lookup
		 * branch of the endpoint. With no lookup engine there is nothing to fall
		 * back from, so hybrid switches off rather than silently becoming
		 * Claude-only and spending money the owner did not choose to spend.
		 */
		if ( self::MODE_HYBRID === $mode ) {
			return null !== $this->lookup_engine ? self::ENGINE_LOOKUP : self::ENGINE_NONE;
		}
```

In `get_mode()`, replace `* @param string $mode One of auto, lookup, ai.` with `* @param string $mode One of auto, lookup, ai, hybrid.`

- [ ] **Step 4: Implement in `class-settings.php`**

In `sanitize_mode()`, replace `array( 'auto', 'lookup', 'ai' )` with `array( 'auto', 'lookup', 'ai', 'hybrid' )`.

Directly after `public static function sanitize_mode( $value ): string { … }`, add:

```php
	/**
	 * Monthly AI cap in MYR: a number from 0 to 10,000.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public static function sanitize_cap( $value ): string {
		$number = is_numeric( $value ) ? (float) $value : 100.0;
		$number = max( 0.0, min( 10000.0, $number ) );

		return (string) round( $number, 2 );
	}
```

Directly after `self::register( 'db_chat_mode', 'auto', array( __CLASS__, 'sanitize_mode' ) );`, add:

```php
		self::register( 'db_chat_ai_monthly_cap_myr', '100', array( __CLASS__, 'sanitize_cap' ) );
```

In `render_mode_section()`, add this entry to the `row_select` choices, after the `'ai'` entry:

```php
						'hybrid' => __( 'Hybrid — free lookup first, Claude only for questions it cannot understand (needs an API key; capped monthly)', 'durianbikers-chatbot' ),
```

In the "Right now" cell, replace:

```php
							<?php if ( 'lookup' === $engine ) : ?>
```

with:

```php
							<?php if ( 'hybrid' === $mode && 'lookup' === $engine ) : ?>
								<strong><?php esc_html_e( 'Answering with free lookup, and Claude for questions it cannot understand.', 'durianbikers-chatbot' ); ?></strong>
								<?php if ( $key ) : ?>
									<?php esc_html_e( 'Only genuine misses are sent to Claude, within the monthly budget below. Fitment, stock, orders and buying never are.', 'durianbikers-chatbot' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'No API key is set, so no AI answers are being given yet: every question gets the free answer.', 'durianbikers-chatbot' ); ?>
								<?php endif; ?>
							<?php elseif ( 'lookup' === $engine ) : ?>
```

Find the description text `The chatbot is switched off below, so nothing is shown to customers regardless of the mode.` and, immediately after the `</tr>` that closes that row, add:

```php
				<?php if ( 'hybrid' === $mode ) : ?>
					<?php
					self::row_text(
						'db_chat_ai_monthly_cap_myr',
						__( 'Monthly AI budget (RM)', 'durianbikers-chatbot' ),
						__( 'An estimate the plugin enforces by falling back to the free answer. Set the same limit in the Anthropic console: that is the only hard stop.', 'durianbikers-chatbot' ),
						'100',
						'small-text'
					);
					?>
					<?php if ( class_exists( 'DB_Chat_Ai_Budget' ) ) : ?>
						<?php $db_chat_budget = new DB_Chat_Ai_Budget(); ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'AI spend this month', 'durianbikers-chatbot' ); ?></th>
							<td>
								<p>
									<?php
									printf(
										/* translators: 1: estimated spend; 2: monthly cap; 3: number of AI answers. */
										esc_html__( 'RM%1$s of RM%2$s estimated, across %3$d AI answers.', 'durianbikers-chatbot' ),
										esc_html( number_format_i18n( $db_chat_budget->month_to_date_myr(), 2 ) ),
										esc_html( number_format_i18n( $db_chat_budget->cap_myr(), 2 ) ),
										(int) $db_chat_budget->calls_this_month()
									);
									?>
								</p>
								<p class="description"><?php esc_html_e( 'Estimated from token counts and priced deliberately high.', 'durianbikers-chatbot' ); ?></p>
							</td>
						</tr>
					<?php endif; ?>
				<?php endif; ?>
```

Replace the API key help text:

```php
							__( 'Only used by the Claude modes — free lookup mode needs no key and makes no external calls. Stored server-side only: never sent to the browser, never printed and never written to a log. Leave blank to keep the current key. Preferred alternative: define DB_CHAT_ANTHROPIC_API_KEY in wp-config.php to keep the key out of the database entirely.', 'durianbikers-chatbot' ),
```

with:

```php
							__( 'Used by the Claude only and Hybrid modes. In Hybrid mode, questions the free engine cannot answer are sent to Anthropic\'s API. Stored server-side only: never sent to the browser, never printed and never written to a log. Leave blank to keep the current key. Preferred alternative: define DB_CHAT_ANTHROPIC_API_KEY in wp-config.php to keep the key out of the database entirely.', 'durianbikers-chatbot' ),
```

- [ ] **Step 5: Lint, deploy, run**

```bash
for f in durianbikers-chatbot.php includes/class-settings.php; do /opt/homebrew/opt/php@8.3/bin/php -l durianbikers-chatbot/$f; done
$SSH "cat > $PLUG/durianbikers-chatbot.php" < durianbikers-chatbot/durianbikers-chatbot.php
$SSH "cat > $PLUG/includes/class-settings.php" < durianbikers-chatbot/includes/class-settings.php
md5 -q durianbikers-chatbot/durianbikers-chatbot.php durianbikers-chatbot/includes/class-settings.php
$SSH "md5sum $PLUG/durianbikers-chatbot.php $PLUG/includes/class-settings.php | cut -d' ' -f1"
$SSH "$WP eval-file ~/claude-uploads/t18-mode.php"
$SSH "$WP option get db_chat_mode"
curl -s -m 25 -o /dev/null -w "%{http_code}\n" -L https://durianbikers.com/my/checkout/
```

Expected: checksums identical, `13 passed, 0 failed`, live mode still `lookup`, checkout `200`.

- [ ] **Step 6: Commit**

```bash
git add durianbikers-chatbot/durianbikers-chatbot.php durianbikers-chatbot/includes/class-settings.php
git commit -m "feat: add hybrid answering mode with budget settings"
```

---

### Task 5: The AI fallback in the endpoint

**Files:**
- Modify: `durianbikers-chatbot/includes/class-chat-endpoint.php`
- Test: `$SCRATCH/t19-endpoint.php`

**Interfaces:**
- Consumes: `get_last_usage()` (Task 1); `DB_Chat_Ai_Budget::can_spend()` / `record()` (Task 2); `needs_ai_fallback()` (Task 3); `DB_Chat_Plugin::MODE_HYBRID` (Task 4).
- Produces: `DB_Chat_Chat_Endpoint::__construct( …, $lookup_engine = null, $ai_budget = null )`. The plugin keeps constructing it with five arguments; the budget defaults internally.

- [ ] **Step 1: Write the failing test**

Create `$SCRATCH/t19-endpoint.php`:

```php
<?php
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) { if ( false !== strpos( (string) $url, 'api.anthropic.com' ) ) { throw new RuntimeException( 'TEST TRIED TO CALL THE REAL ANTHROPIC API' ); } return $pre; }, 1, 3 );
add_filter( 'db_chat_lookup_log_unmatched', '__return_false' );
add_filter( 'db_chat_is_enabled', '__return_true', 1000 ); // past the test-link gate at 99
add_filter( 'db_chat_rate_limit', '__return_zero' );
add_filter( 'db_chat_rate_limit_ip', '__return_zero' );
$pass = 0; $fail = 0;
function ck( $l, $ok, &$p, &$f ) { printf( "%s %s\n", $ok ? 'PASS' : 'FAIL', $l ); $ok ? $p++ : $f++; }

$mode = 'hybrid';
add_filter( 'db_chat_mode', function () use ( &$mode ) { return $mode; } );

class T19_Client extends DB_Chat_Claude_Client {
	public $key   = true;
	public $calls = 0;
	public $reply = 'AI says hello';
	public $usage = array( 'input_tokens' => 1500, 'output_tokens' => 120, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0 );
	public function has_api_key() { return $this->key; }
	public function chat( array $messages, $static_context, callable $tool_executor, $page_context = '' ) { $this->calls++; return $this->reply; }
	public function get_last_usage() { return $this->calls ? $this->usage : array( 'input_tokens' => 0, 'output_tokens' => 0, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0 ); }
}
class T19_Budget extends DB_Chat_Ai_Budget {
	public $allow    = true;
	public $recorded = array();
	public function __construct() { parent::__construct( 'db_chat_ai_spend_t19' ); }
	public function can_spend() { return $this->allow; }
	public function record( array $usage ) { $this->recorded[] = $usage; }
}

$index    = new DB_Chat_Product_Index();
$content  = new DB_Chat_Content_Index();
$engine   = new DB_Chat_Lookup_Engine( $index, $content );
$client   = new T19_Client();
$budget   = new T19_Budget();
$endpoint = new DB_Chat_Chat_Endpoint( $index, $client, $content, new DB_Chat_Order_Lookup(), $engine, $budget );

$ask = function ( $message, $pending = null ) use ( $endpoint ) {
	$req = new WP_REST_Request( 'POST', '/durianbikers/v1/chat' );
	$req->set_param( 'message', $message );
	$req->set_param( 'history', array() );
	$req->set_param( 'pending', $pending );
	$res = $endpoint->handle_request( $req );
	return is_wp_error( $res ) ? array( 'error' => $res->get_error_code(), 'reply' => '' ) : $res->get_data();
};
$reset = function () use ( $client, $budget ) {
	$client->calls    = 0;
	$client->key      = true;
	$client->reply    = 'AI says hello';
	$budget->allow    = true;
	$budget->recorded = array();
};

foreach ( array( 'meow', 'zxqv blorf quimble', 'compare zxqvblorf and quimbleplonk' ) as $miss ) {
	$reset();
	$r = $ask( $miss );
	ck( "miss \"$miss\" is answered by AI", 'AI says hello' === ( $r['reply'] ?? '' ) && 1 === $client->calls, $pass, $fail );
	ck( "miss \"$miss\" clears any pending order", array_key_exists( 'pending', $r ) && null === $r['pending'], $pass, $fail );
	ck( "miss \"$miss\" is charged once", 1 === count( $budget->recorded ) && 1500 === (int) $budget->recorded[0]['input_tokens'], $pass, $fail );
}

$never = array(
	'will this fit my yamaha r15' => 'fitment',
	'berapa harga zxqvblorf'      => 'understood, nothing found',
	'where is my order'           => 'order',
	'i want yamanaka for 2pcs'    => 'buying opener',
);
foreach ( $never as $msg => $label ) {
	$reset();
	$ask( $msg );
	ck( "$label never reaches AI", 0 === $client->calls, $pass, $fail );
}

$reset();
$sizes   = ( new DB_Chat_Cart_Writer() )->in_stock_variations( 58024 );
$confirm = array( 'stage' => DB_Chat_Pending_Order::STAGE_CONFIRM, 'product_id' => 58024, 'variation_id' => (int) $sizes[0]['variation_id'], 'quantity' => 1, 'offered' => array(), 'created' => time() );
$ask( 'no', $confirm );
ck( 'declining a confirmation never reaches AI', 0 === $client->calls, $pass, $fail );

$reset(); $client->key = false;
$r = $ask( 'meow' );
ck( 'no key: free answer, no call, no charge', 0 === $client->calls && 0 === count( $budget->recorded ) && '' !== ( $r['reply'] ?? '' ) && 'AI says hello' !== $r['reply'], $pass, $fail );

$reset(); $budget->allow = false;
$r = $ask( 'meow' );
ck( 'over budget: free answer, no call', 0 === $client->calls && 'AI says hello' !== ( $r['reply'] ?? '' ), $pass, $fail );

$reset(); $client->reply = new WP_Error( 'boom', 'down' );
$r = $ask( 'meow' );
ck( 'AI error: free answer returned', '' !== ( $r['reply'] ?? '' ) && ! isset( $r['error'] ), $pass, $fail );
ck( 'AI error: billed rounds still charged', 1 === count( $budget->recorded ), $pass, $fail );

$reset(); $client->reply = '   ';
$r = $ask( 'meow' );
ck( 'empty AI reply: free answer returned', '' !== trim( (string) ( $r['reply'] ?? '' ) ), $pass, $fail );

$reset(); $mode = 'lookup';
$ask( 'meow' );
ck( 'lookup-only mode never calls AI', 0 === $client->calls, $pass, $fail );

$reset(); $mode = 'ai';
$ask( 'shinko 705' );
ck( 'Claude-only mode still answers through Claude', 1 === $client->calls, $pass, $fail );

delete_option( 'db_chat_ai_spend_t19' );
echo "\n==== $pass passed, $fail failed ====\n";
```

- [ ] **Step 2: Run it to verify it fails**

```bash
$SSH "cat > ~/claude-uploads/t19-endpoint.php" < $SCRATCH/t19-endpoint.php
$SSH "$WP eval-file ~/claude-uploads/t19-endpoint.php"
```

Expected: every "is answered by AI" assertion FAILS (the free answer comes back and the fake client is never called), and the "never reaches AI" assertions pass.

- [ ] **Step 3: Write the implementation**

After the `$lookup_engine` property, add:

```php
	/**
	 * Monthly AI spend guard for hybrid mode, or null when unavailable.
	 *
	 * @var DB_Chat_Ai_Budget|null
	 */
	private $ai_budget;
```

Replace the constructor signature and body's last assignment:

```php
	public function __construct( DB_Chat_Product_Index $product_index, DB_Chat_Claude_Client $client, $content_index = null, $order_lookup = null, $lookup_engine = null ) {
```

with:

```php
	public function __construct( DB_Chat_Product_Index $product_index, DB_Chat_Claude_Client $client, $content_index = null, $order_lookup = null, $lookup_engine = null, $ai_budget = null ) {
```

and, after `$this->lookup_engine = $lookup_engine instanceof DB_Chat_Lookup_Engine ? $lookup_engine : null;`, add:

```php
		if ( $ai_budget instanceof DB_Chat_Ai_Budget ) {
			$this->ai_budget = $ai_budget;
		} else {
			$this->ai_budget = class_exists( 'DB_Chat_Ai_Budget' ) ? new DB_Chat_Ai_Budget() : null;
		}
```

Add `@param DB_Chat_Ai_Budget|null $ai_budget Monthly AI spend guard.` to the constructor docblock.

In `handle_with_lookup()`, replace:

```php
		$this->maybe_log( $message, $reply );

		/*
		 * `pending` is emitted even when null — that null is what CLEARS a
```

with:

```php
		/*
		 * HYBRID. The free engine has answered; if it genuinely did not
		 * understand the question, Claude gets one chance at the same message.
		 * Any reason not to — wrong mode, no key, no budget, an error, an empty
		 * reply — returns null and the free answer below goes out untouched.
		 */
		$ai = $this->maybe_answer_with_ai( $message, $history );

		if ( null !== $ai ) {
			return $ai;
		}

		$this->maybe_log( $message, $reply );

		/*
		 * `pending` is emitted even when null — that null is what CLEARS a
```

Add this method directly after `handle_with_lookup()`:

```php
	/**
	 * Hybrid mode: re-answer a genuine miss with Claude.
	 *
	 * @param string $message Sanitized customer message.
	 * @param array  $history Sanitized history.
	 * @return WP_REST_Response|null Null means "send the free answer".
	 */
	private function maybe_answer_with_ai( $message, array $history ) {
		if ( DB_Chat_Plugin::MODE_HYBRID !== db_chat()->get_mode() ) {
			return null;
		}

		if ( ! is_callable( array( $this->lookup_engine, 'needs_ai_fallback' ) ) || ! $this->lookup_engine->needs_ai_fallback() ) {
			return null;
		}

		if ( null === $this->ai_budget || ! $this->client->has_api_key() || ! $this->ai_budget->can_spend() ) {
			return null;
		}

		$messages     = $this->build_messages( $history, $message );
		$context      = $this->product_index->get_context();
		$page_context = null !== $this->content_index ? $this->content_index->get_context() : '';

		try {
			$reply = $this->client->chat( $messages, $context, $this->get_tool_executor(), $page_context );
		} catch ( Throwable $e ) {
			DB_Chat_Plugin::log_error( 'Hybrid AI fallback threw ' . get_class( $e ) . '.' );
			$reply = new WP_Error( 'db_chat_ai_exception', '' );
		}

		/*
		 * Charged whether or not it worked: earlier tool rounds of a call that
		 * later failed were still billed, and undercounting is the one mistake a
		 * budget must not make.
		 */
		$this->ai_budget->record( $this->client->get_last_usage() );

		if ( is_wp_error( $reply ) ) {
			// The code only. Never the customer's message, never upstream detail.
			DB_Chat_Plugin::log_error( 'Hybrid AI fallback unavailable: ' . $reply->get_error_code() . '.' );

			return null;
		}

		$reply = (string) $reply;

		if ( '' === trim( $reply ) ) {
			return null;
		}

		$products = $this->product_index->match_in_text( $reply );
		$pages    = null !== $this->content_index ? $this->content_index->match_in_text( $reply ) : array();

		$this->maybe_log( $message, $reply );

		$response = rest_ensure_response(
			array(
				'reply'    => $reply,
				'products' => array_values( $products ),
				'pages'    => array_values( $pages ),
				// An AI answer ends any purchase in progress, as an off-topic message does.
				'pending'  => null,
			)
		);

		return $this->apply_no_store( $response );
	}
```

- [ ] **Step 4: Lint, deploy, run**

```bash
/opt/homebrew/opt/php@8.3/bin/php -l durianbikers-chatbot/includes/class-chat-endpoint.php
$SSH "cat > $PLUG/includes/class-chat-endpoint.php" < durianbikers-chatbot/includes/class-chat-endpoint.php
md5 -q durianbikers-chatbot/includes/class-chat-endpoint.php
$SSH "md5sum $PLUG/includes/class-chat-endpoint.php | cut -d' ' -f1"
$SSH "$WP eval-file ~/claude-uploads/t19-endpoint.php"
curl -s -m 25 -o /dev/null -w "%{http_code}\n" -L https://durianbikers.com/my/checkout/
```

Expected: checksums identical, `21 passed, 0 failed`, checkout `200`.

- [ ] **Step 5: Commit**

```bash
git add durianbikers-chatbot/includes/class-chat-endpoint.php
git commit -m "feat: hybrid mode hands genuine misses to Claude within budget"
```

---

### Task 6: Honest copy in hybrid mode

**Files:**
- Modify: `durianbikers-chatbot/includes/class-lookup-engine.php`
- Modify: `durianbikers-chatbot/durianbikers-chatbot.php`
- Test: `$SCRATCH/t20-copy.php`

**Interfaces:**
- Consumes: `DB_Chat_Plugin::MODE_HYBRID` (Task 4).
- Produces: `DB_Chat_Lookup_Engine::hybrid_active(): bool`, `DB_Chat_Lookup_Engine::templates_hybrid( string $lang ): array`.

This task also covers one line the spec missed. The engine's `identity` reply — "I don't understand language either" — is just as false in hybrid as the greeting, so it is replaced too.

- [ ] **Step 1: Write the failing test**

Create `$SCRATCH/t20-copy.php`:

```php
<?php
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) { if ( false !== strpos( (string) $url, 'api.anthropic.com' ) ) { throw new RuntimeException( 'TEST TRIED TO CALL THE REAL ANTHROPIC API' ); } return $pre; }, 1, 3 );
add_filter( 'db_chat_lookup_log_unmatched', '__return_false' );
$pass = 0; $fail = 0;
function ck( $l, $ok, &$p, &$f ) { printf( "%s %s\n", $ok ? 'PASS' : 'FAIL', $l ); $ok ? $p++ : $f++; }

$mode = 'hybrid';
add_filter( 'db_chat_mode', function () use ( &$mode ) { return $mode; } );

$footnote = function () {
	$m = new ReflectionMethod( 'DB_Chat_Plugin', 'get_script_data' );
	$m->setAccessible( true );
	$data = $m->invoke( db_chat() );
	return (string) ( $data['footnote'] ?? '' );
};

// Hybrid.
$en = DB_Chat_Lookup_Engine::templates( 'en' );
$ms = DB_Chat_Lookup_Engine::templates( 'ms' );
$zh = DB_Chat_Lookup_Engine::templates( 'zh' );
ck( 'hybrid EN greeting mentions an AI assistant', false !== strpos( $en['greeting'], 'an AI assistant answers it' ), $pass, $fail );
ck( 'hybrid EN greeting drops "not an AI"', false === strpos( $en['greeting'], 'not an AI' ), $pass, $fail );
ck( 'hybrid EN identity drops "don\'t understand language"', false === strpos( $en['identity'], "don't understand language" ), $pass, $fail );
ck( 'hybrid MS greeting mentions pembantu AI', false !== strpos( $ms['greeting'], 'pembantu AI akan menjawabnya' ), $pass, $fail );
ck( 'hybrid MS identity drops "tidak faham bahasa"', false === strpos( $ms['identity'], 'tidak faham bahasa' ), $pass, $fail );
ck( 'hybrid Chinese falls back to the English greeting', $en['greeting'] === $zh['greeting'], $pass, $fail );
ck( 'hybrid footnote says some answers are AI', false !== strpos( $footnote(), 'some answers are written by AI' ), $pass, $fail );

// Lookup-only keeps its accurate copy.
$mode = 'lookup';
$en   = DB_Chat_Lookup_Engine::templates( 'en' );
ck( 'lookup greeting unchanged', false !== strpos( $en['greeting'], 'not an AI that understands language' ), $pass, $fail );
ck( 'lookup identity unchanged', false !== strpos( $en['identity'], "don't understand language" ), $pass, $fail );
ck( 'lookup footnote unchanged', false !== strpos( $footnote(), 'it matches keywords, not meaning' ), $pass, $fail );

echo "\n==== $pass passed, $fail failed ====\n";
```

- [ ] **Step 2: Run it to verify it fails**

```bash
$SSH "cat > ~/claude-uploads/t20-copy.php" < $SCRATCH/t20-copy.php
$SSH "$WP eval-file ~/claude-uploads/t20-copy.php"
```

Expected: all seven hybrid assertions FAIL; the three lookup assertions pass.

- [ ] **Step 3: Implement in `class-lookup-engine.php`**

In `templates()`, directly before the docblock line `* Filter the lookup mode reply templates.` (that is, before its opening `/**`), add:

```php
		/*
		 * HYBRID MODE. The greeting and identity replies say this assistant is
		 * not an AI — true in lookup mode, false once genuine misses go to
		 * Claude. Overlaid here, before the owner's filter, so a configured
		 * override still wins. Chinese keeps the English wording, as it
		 * already does for every string it has not translated.
		 */
		if ( self::hybrid_active() ) {
			$set = array_merge( $set, self::templates_hybrid( $lang ) );
		}

```

Add these methods directly after `templates()`:

```php
	/**
	 * Is the plugin running in hybrid mode?
	 *
	 * @return bool
	 */
	public static function hybrid_active() {
		return function_exists( 'db_chat' )
			&& defined( 'DB_Chat_Plugin::MODE_HYBRID' )
			&& is_callable( array( db_chat(), 'get_mode' ) )
			&& DB_Chat_Plugin::MODE_HYBRID === db_chat()->get_mode();
	}

	/**
	 * Replacement copy for hybrid mode.
	 *
	 * @param string $lang 'en', 'ms' or 'zh'.
	 * @return array<string,string>
	 */
	public static function templates_hybrid( $lang ) {
		if ( 'ms' === $lang ) {
			return array(
				'greeting' => 'Hai! Saya pembantu kedai Durian Bikers. Saya automatik, bukan manusia. Saya boleh semak produk, harga, stok, dan halaman penghantaran, pemulangan, jaminan dan bayaran kami. Jika saya tidak dapat padankan soalan anda, pembantu AI akan menjawabnya — ia boleh tersilap, jadi sila semak perkara yang penting. Apa yang anda cari?',
				'identity' => 'Saya bukan manusia — saya pembantu automatik di laman web kedai. Saya semak produk dan halaman bantuan kami sendiri, dan jika saya tidak dapat padankan soalan, pembantu AI akan menjawabnya. Ia boleh tersilap, jadi sila semak perkara yang penting.',
			);
		}

		return array(
			'greeting' => __( "Hi! I'm the Durian Bikers shop assistant. I'm automated, not a person. I can look up products, prices, stock, and our shipping, returns, warranty and payment pages. If I can't match your question myself, an AI assistant answers it — it can make mistakes, so please check anything important. What are you after?", 'durianbikers-chatbot' ),
			'identity' => __( "I'm not a person — I'm an automated assistant on the shop's website. I look up our products and help pages myself, and when I can't match a question, an AI assistant answers it. It can make mistakes, so please check anything important.", 'durianbikers-chatbot' ),
		);
	}
```

- [ ] **Step 4: Implement in `durianbikers-chatbot.php`**

In `get_script_data()`, replace:

```php
		if ( self::ENGINE_LOOKUP === $this->get_engine() ) {
			$data['footnote'] = __( 'Automated lookup — it matches keywords, not meaning, so it can miss things. Please confirm fitment before you buy.', 'durianbikers-chatbot' );
		}
```

with:

```php
		/*
		 * Checked by MODE, not engine: hybrid also runs on the lookup engine,
		 * and would otherwise inherit a footnote promising there is no AI.
		 */
		if ( self::MODE_HYBRID === $this->get_mode() ) {
			$data['footnote'] = __( 'Automated assistant — some answers are written by AI and can be wrong. Please confirm fitment before you buy.', 'durianbikers-chatbot' );
		} elseif ( self::ENGINE_LOOKUP === $this->get_engine() ) {
			$data['footnote'] = __( 'Automated lookup — it matches keywords, not meaning, so it can miss things. Please confirm fitment before you buy.', 'durianbikers-chatbot' );
		}
```

- [ ] **Step 5: Lint, deploy, run**

```bash
for f in includes/class-lookup-engine.php durianbikers-chatbot.php; do /opt/homebrew/opt/php@8.3/bin/php -l durianbikers-chatbot/$f; done
$SSH "cat > $PLUG/includes/class-lookup-engine.php" < durianbikers-chatbot/includes/class-lookup-engine.php
$SSH "cat > $PLUG/durianbikers-chatbot.php" < durianbikers-chatbot/durianbikers-chatbot.php
md5 -q durianbikers-chatbot/includes/class-lookup-engine.php durianbikers-chatbot/durianbikers-chatbot.php
$SSH "md5sum $PLUG/includes/class-lookup-engine.php $PLUG/durianbikers-chatbot.php | cut -d' ' -f1"
$SSH "$WP eval-file ~/claude-uploads/t20-copy.php"
```

Expected: checksums identical, `10 passed, 0 failed`.

- [ ] **Step 6: Commit**

```bash
git add durianbikers-chatbot/includes/class-lookup-engine.php durianbikers-chatbot/durianbikers-chatbot.php
git commit -m "feat: honest greeting, identity and footnote copy in hybrid mode"
```

---

### Task 7: Full regression, deploy check, runbook

**Files:**
- Modify: `durianbikers-chatbot/DEPLOY.md`
- Test: every harness, plus `$SCRATCH/t21-cart-snapshot.php`

**Interfaces:**
- Consumes: everything above.
- Produces: a verified deploy with hybrid **available but not switched on**.

- [ ] **Step 1: Protect the operator's cart**

Three existing suites write cart lines into the db_admin persistent cart, which also holds the operator's own items. Snapshot it before and restore it after — never clean up by emptying or by product id.

Create `$SCRATCH/t21-cart-snapshot.php`:

```php
<?php
// wp eval-file t21-cart-snapshot.php save|restore
$action = isset( $args[0] ) ? (string) $args[0] : '';
$user   = get_user_by( 'login', 'db_admin' );
$key    = '_woocommerce_persistent_cart_' . get_current_blog_id();
$file   = getenv( 'HOME' ) . '/claude-uploads/admin-cart-snapshot.json';

if ( 'save' === $action ) {
	file_put_contents( $file, wp_json_encode( get_user_meta( $user->ID, $key, true ) ) );
	echo "saved\n";
} elseif ( 'restore' === $action ) {
	$data = json_decode( (string) file_get_contents( $file ), true );
	update_user_meta( $user->ID, $key, $data );
	echo "restored\n";
} else {
	echo "usage: save|restore\n";
}
```

```bash
$SSH "cat > ~/claude-uploads/t21-cart-snapshot.php" < $SCRATCH/t21-cart-snapshot.php
$SSH "$WP eval-file ~/claude-uploads/t21-cart-snapshot.php save"
```

- [ ] **Step 2: Run every suite**

```bash
for t in rank-test purchase-test t1-cart-writer t2-vocabulary t3-pending t4-answer-matching t5-flow t6-confirm t8-chinese t10-fitment t11-brand-browse t15-client t16-budget t17-miss-flag t18-mode t19-endpoint t20-copy; do
  printf "%-22s " "$t"; $SSH "$WP eval-file ~/claude-uploads/$t.php 2>&1 | grep -E 'Fatal|===='"
done
```

Expected: zero failures and no fatals — the 161 existing assertions plus 94 new ones — 255 in all.

- [ ] **Step 3: Restore the operator's cart**

```bash
$SSH "$WP eval-file ~/claude-uploads/t21-cart-snapshot.php restore"
$SSH "$WP eval-file ~/claude-uploads/t7-cartcheck.php"
```

Expected: the cart lines match what was there before Step 1.

- [ ] **Step 4: Verify the deployed state**

```bash
for f in durianbikers-chatbot.php includes/class-claude-client.php includes/class-ai-budget.php includes/class-lookup-engine.php includes/class-settings.php includes/class-chat-endpoint.php; do
  L=$(md5 -q durianbikers-chatbot/$f); R=$($SSH "md5sum $PLUG/$f | cut -d' ' -f1")
  [ "$L" = "$R" ] && echo "match   $f" || echo "MISMATCH $f"
done
$SSH "$WP option get db_chat_mode"
$SSH "$WP option get db_chat_ai_spend 2>&1 | head -1"
for u in https://durianbikers.com/my/ https://durianbikers.com/my/cart/ https://durianbikers.com/my/checkout/; do printf "%-44s " "$u"; curl -s -m 25 -o /dev/null -w "%{http_code}\n" -L "$u"; done
```

Expected: every file `match`; mode `lookup`; no `db_chat_ai_spend` option yet; all three URLs `200`.

- [ ] **Step 5: Update DEPLOY.md**

Add a section after "Choose an answering mode (free or paid)":

```markdown
### Hybrid mode — free first, AI for the rest

Hybrid answers everything the free engine can, at no cost, and sends only the questions it genuinely
cannot understand to Claude. Fitment, stock, orders and the whole buying flow never go to AI.

**Before switching it on, in this order:**

1. At console.anthropic.com, create an API key **and set a monthly spend limit first**. That limit
   is the only hard stop.
2. Add the key yourself — WooCommerce → Chatbot → Claude API key, or `DB_CHAT_ANTHROPIC_API_KEY` in
   `wp-config.php`.
3. Add a line to your privacy policy: *Questions our chat assistant cannot answer automatically may
   be sent to Anthropic, an AI provider, to write a reply. Please do not share personal or payment
   details in the chat.*
4. WooCommerce → Chatbot → Answering mode → **Hybrid**. Set **Monthly AI budget (RM)** — default 100.

**What the budget does.** The plugin estimates each AI answer's cost from its token counts, priced
deliberately high. It spreads what is left of the month across the days left, so a quiet day's
budget rolls forward and a busy day cannot use up the month. When the budget is reached, customers
quietly get the free answer instead — no error, no message about limits. The settings screen shows
this month's estimated spend.

**Turning it off.** Switch Answering mode back to Free lookup only. Nothing else needs undoing.
```

- [ ] **Step 6: Commit**

```bash
git add durianbikers-chatbot/DEPLOY.md
git commit -m "docs: how to switch on hybrid mode safely"
```

---

### Task 8: Live proof — **blocked until the operator has an API key**

**Files:** none changed. Live verification only.

**Prerequisites, all by the operator:** a console spend limit set, the key added, and the privacy-policy line published. Do not start this task without all three.

- [ ] **Step 1: Confirm the key is detected**

```bash
$SSH "$WP eval '\$c = new DB_Chat_Claude_Client(); var_export( \$c->has_api_key() ); echo PHP_EOL;'"
```

Expected: `true`. If `false`, stop and tell the operator.

- [ ] **Step 2: Ask the operator, then switch on hybrid**

This changes live behaviour for everyone who can reach the chat (the secret test link and staff). **Get the operator's explicit yes in chat first.**

```bash
$SSH "$WP option update db_chat_mode hybrid"
```

- [ ] **Step 3: Exercise it through the secret test link**

In a private window, open the test link and send, in order:

| Message | Should happen |
|---|---|
| `which is better for touring, the stinger 2 or the thunder 4?` | A real AI answer that sets out facts but **does not pick a winner**, and offers a person |
| `can you recommend something for a long ride in the rain` | A real AI answer grounded in search results, not invented products |
| `will this fit my yamaha r15?` | The free fitment decline — **not** an AI answer |
| `i want yamanaka for 2pcs` | The free buying flow — **not** an AI answer |

- [ ] **Step 4: Confirm spend was recorded, and whether caching works**

Send the two AI questions again within five minutes, then:

```bash
$SSH "$WP eval '\$b = new DB_Chat_Ai_Budget(); printf( \"RM%.4f across %d calls\n\", \$b->month_to_date_myr(), \$b->calls_this_month() ); print_r( \$b->last_usage() );'"
```

Expected: spend above zero and a call count matching the AI answers given. **`cache_read_input_tokens` above 0** on the repeat means caching works. If it is `0`, the prefix is under Haiku's 4,096-token minimum. Report that to the operator; do **not** pad the prompt without measuring (spec §7).

- [ ] **Step 5: Confirm the model ID**

A real AI reply in Step 3 proves the configured model works. If every AI question instead got the free answer, check the error log for `Anthropic API returned 404 (not_found_error)` and the value of `wp option get db_chat_model`, then report before changing anything.

- [ ] **Step 6: Compare with the console, and hand back**

The next day, compare the recorded spend with the Anthropic console's usage. Report both numbers. The operator then decides whether hybrid stays on.

---

## Self-Review Notes

- **Spec coverage.** §4.1 mode → Task 4; §4.2 three-point flag → Task 3; §4.3 never-to-AI → Tasks 3 and 5 (asserted both at the engine and at the endpoint); §4.4 flow, failure fallback and `pending: null` → Task 5; §5.1 usage → Task 1; §5.2 formula, daily allowance, rollover, cap, overshoot, autoload off → Task 2; §5.3 settings → Task 4; §6 copy, privacy line and never-judge rule → Tasks 1, 4, 6, 7; §7 caching → Task 1, verified live in Task 8; §8 failures → Task 5; §9 unchanged behaviour → regression in Task 7; §10 testing → every task; §12 model ID → Task 8.
- **Added beyond the spec, with reason.** The engine's `identity` reply is replaced in hybrid (Task 6): it says "I don't understand language either", which is as false in hybrid as the greeting. `record()` also stores the last call's token counts, with no message content, so Task 8 can observe caching from the command line.
- **Budget reads never write.** Rollover happens in memory and only `record()` persists it, so rendering the settings screen cannot create `db_chat_ai_spend` — Task 7 Step 4 relies on that, and Task 2 asserts it.
- **Names consistent across tasks:** `get_last_usage()`, `DB_Chat_Ai_Budget::{can_spend, record, cost_myr, month_to_date_myr, calls_this_month, cap_myr, last_usage}`, `needs_ai_fallback()`, `MODE_HYBRID`, `maybe_answer_with_ai()`, `hybrid_active()`, `templates_hybrid()`, `sanitize_cap()`, option names `db_chat_ai_spend` and `db_chat_ai_monthly_cap_myr`.
- **Known soft spot.** Task 4's settings-render assertions assume `DB_Chat_Settings::get()` reads through `get_option()`. Step 2 of that task says what to do if it does not.
