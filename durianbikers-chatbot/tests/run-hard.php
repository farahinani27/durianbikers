<?php
/**
 * Hard-check conversation runner (Phase 1).
 *
 * Deterministic, free regression net for the orchestrator's SAFETY mechanics:
 * grounding never shows an ungrounded price, silent fallback, escalation.
 * Drives DB_Chat_Orchestrator with a scripted stub client (a scenario says what
 * "Claude replied" and what "the tools returned"). Runs under plain `php`.
 *
 * Run: php durianbikers-chatbot/tests/run-hard.php
 */
define( 'DB_CHAT_TEST', 1 );
require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/wp-shims.php';
require __DIR__ . '/unit/stubs.php';
require __DIR__ . '/../includes/class-pii.php';
require __DIR__ . '/../includes/class-messages.php';
require __DIR__ . '/../includes/class-grounding.php';
require __DIR__ . '/../includes/class-orchestrator.php';

$dir  = __DIR__ . '/conversations';
$noop = function ( $n, $i ) { return array(); };

foreach ( glob( $dir . '/*.json' ) as $file ) {
	$s = json_decode( file_get_contents( $file ), true );
	if ( ! is_array( $s ) ) {
		t_ok( false, 'parse ' . basename( $file ) );
		continue;
	}
	$name         = isset( $s['name'] ) ? $s['name'] : basename( $file );
	$ai           = ! array_key_exists( 'ai_available', $s ) || $s['ai_available'];
	$tool_results = isset( $s['stub_tool_results'] ) ? $s['stub_tool_results'] : array();
	$reply        = isset( $s['stub_reply'] ) ? $s['stub_reply'] : '';
	$reply        = ( '__WP_ERROR__' === $reply ) ? new WP_Error( 'ai', 'x' ) : $reply;
	$fallback     = isset( $s['fallback_reply'] ) ? $s['fallback_reply'] : 'keyword fallback';

	$client = new FakeClient( $ai, array( $reply ), $tool_results );
	$o      = new DB_Chat_Orchestrator( $client, new FakeLookup( $fallback ), new FakeBudget( $ai ), new FakeIndex(), new FakeIndex() );
	$r      = $o->answer( isset( $s['message'] ) ? $s['message'] : 'hi', array(), $noop, '', '' );

	$a = isset( $s['assert'] ) ? $s['assert'] : array();
	if ( isset( $a['source'] ) ) {
		t_eq( $r['source'], $a['source'], "$name: source" );
	}
	if ( isset( $a['grounding'] ) ) {
		t_eq( $r['grounding'], $a['grounding'], "$name: grounding" );
	}
	if ( isset( $a['escalate'] ) ) {
		t_eq( (bool) $r['escalate'], (bool) $a['escalate'], "$name: escalate" );
	}
	if ( ! empty( $a['reply_nonempty'] ) ) {
		t_ok( '' !== trim( $r['reply'] ), "$name: reply nonempty" );
	}
	if ( isset( $a['reply_contains'] ) ) {
		foreach ( (array) $a['reply_contains'] as $sub ) {
			t_ok( strpos( $r['reply'], $sub ) !== false, "$name: contains '$sub'" );
		}
	}
	if ( isset( $a['reply_not_contains'] ) ) {
		foreach ( (array) $a['reply_not_contains'] as $sub ) {
			t_ok( strpos( $r['reply'], $sub ) === false, "$name: not contains '$sub'" );
		}
	}
}

/**
 * CART-SAFETY hard-checks (Phase 2).
 *
 * Asserts the REAL commit-gate predicate from
 * includes/class-chat-endpoint.php::tool_commit_cart_change() (~line 1545):
 *
 *     ( DB_Chat_Confirm::is_affirmative( $current_message ) && ! DB_Chat_Confirm::is_refusal( $current_message ) )
 *
 * against the DB_Chat_Confirm refusal/affirmative corpus (representative
 * cases from tests/unit/test-confirm.php). This is not a mirror of the
 * gate — it calls the real DB_Chat_Confirm class — so a regression in
 * DB_Chat_Confirm itself (which would also break the live gate) fails
 * here too.
 *
 * The property under test: predicate FALSE for every refusal / ambiguous /
 * greeting message means the commit path takes the "not_confirmed" branch
 * and never constructs a DB_Chat_Cart_Writer — zero mutation. Predicate
 * TRUE only for genuine affirmatives. Per Controller Ruling P2-5, the
 * "exactly one mutate call" half of the invariant (real cart-writer,
 * snapshot/restore) is covered by the existing server smoke
 * test-cart-tools.php and is not duplicated here.
 */
require __DIR__ . '/../includes/class-confirm.php';

function db_chat_test_commit_gate( $message ) {
	return DB_Chat_Confirm::is_affirmative( $message ) && ! DB_Chat_Confirm::is_refusal( $message );
}

// Refusals — including the traps (contain a 'yes'/confirm token). Gate must
// be false: commit refuses, zero mutation.
foreach ( array( 'no', 'nope', "don't", 'cancel', 'tak', 'tak nak', 'jangan', 'batal', 'tak mau', '不要', '不用', '不了' ) as $n ) {
	t_ok( false === db_chat_test_commit_gate( $n ), "CART-SAFETY gate false for refusal: $n" );
}

// Ambiguous — no recognised word in either list. Gate must be false.
foreach ( array( 'how much is it', 'what colours', 'maybe later', 'hmm', 'maybe' ) as $a ) {
	t_ok( false === db_chat_test_commit_gate( $a ), "CART-SAFETY gate false for ambiguous: $a" );
}

// Greetings/questions that merely contain a confirm-like substring. Gate
// must be false — these are not the customer saying yes to a cart commit.
foreach ( array( '你好', '是吗', '这是什么颜色', '这个好看吗', '这个不错', 'not sure', 'add nothing thanks', 'not now', 'maybe not' ) as $g ) {
	t_ok( false === db_chat_test_commit_gate( $g ), "CART-SAFETY gate false for greeting/trap: $g" );
}

// Genuine affirmatives. Gate must be true — commit proceeds to the writer.
foreach ( array( 'yes', 'ya', 'ok add it', '好的', 'confirm', '加入', 'sure', 'go ahead', 'boleh', 'teruskan' ) as $y ) {
	t_ok( true === db_chat_test_commit_gate( $y ), "CART-SAFETY gate true for affirmative: $y" );
}

/**
 * CHECKOUT-SAFETY hard-checks (Phase 3).
 *
 * Reuses the REAL classes — DB_Chat_Checkout_Writer (Task 1) and
 * DB_Chat_PII (Task 5) — rather than reimplementing their behaviour, same
 * spirit as the CART-SAFETY block above. class-checkout-writer.php's guard
 * is `defined('ABSPATH') || defined('DB_CHAT_TEST') || exit;`, and
 * DB_CHAT_TEST is already defined at the top of this file, so no ABSPATH
 * shim is needed here. WC()/WC()->customer come from tests/unit/stubs.php,
 * already required above for the Phase-1 checks.
 *
 * Checks 1–3 call the real writer/PII classes directly. Check 4 (the
 * hard-wall structural predicate) and the no-store assertion are pure
 * source-position checks against includes/class-chat-endpoint.php, the
 * same technique tests/unit/test-checkout-tools.php's "ordering regression
 * guard" already uses — calling the endpoint's private tool methods for
 * real would require instantiating DB_Chat_Chat_Endpoint (product index +
 * Claude client + several WP/WooCommerce stubs not otherwise needed in
 * this file), which tests/unit/test-checkout-tools.php already exercises
 * in full. Duplicating that scaffolding here for a shape that never
 * changes at runtime (both tool methods return fixed array literals) buys
 * little extra signal; scanning those literals in source is pure, fast and
 * still fails the moment a future edit adds a forbidden key.
 */
require __DIR__ . '/../includes/class-checkout-writer.php';

// 1) sanitize_details: strips tags, trims, drops unknown keys, uppercases
// country. Reuses the Task-1 case from tests/unit/test-checkout-writer.php.
$checkout_sanitised = DB_Chat_Checkout_Writer::sanitize_details(
	array(
		'first_name' => "  Farah\n",
		'address_1'  => '<b>12 Jalan</b>',
		'country'    => 'my',
		'junk'       => 'x',
	)
);
t_ok( 'Farah' === $checkout_sanitised['first_name'], 'CHECKOUT-SAFETY: sanitize_details trims name' );
t_ok( false === strpos( $checkout_sanitised['address_1'], '<' ), 'CHECKOUT-SAFETY: sanitize_details strips tags from address' );
t_ok( 'MY' === $checkout_sanitised['country'], 'CHECKOUT-SAFETY: sanitize_details uppercases country' );
t_ok( ! array_key_exists( 'junk', $checkout_sanitised ), 'CHECKOUT-SAFETY: sanitize_details drops unknown keys' );

// 2) set_details() result contains NO PII value, over a small corpus of
// value-shaped inputs (name/email/phone/address). Needs WC()->customer —
// the FakeWC()/FakeWCCustomer stubs from tests/unit/stubs.php (already
// required at the top of this file) stand in for it.
$checkout_pii_corpus = array(
	array(
		'first_name' => 'Farah',
		'last_name'  => 'Rahman',
		'email'      => 'farah@example.test',
		'phone'      => '+60 12-345 6789',
		'address_1'  => '12 Jalan Test',
	),
	array(
		'first_name' => 'Siti',
		'last_name'  => 'Ahmad',
		'email'      => 'siti.ahmad@example.test',
		'phone'      => '011-2345 6789',
		'address_1'  => '5 Jalan Bunga',
	),
	array(
		'first_name' => 'Wei Ling',
		'last_name'  => 'Tan',
		'email'      => 'tan.wl@example.test',
		'phone'      => '0123456789',
		'address_1'  => '88 Persiaran SS2',
	),
);

foreach ( $checkout_pii_corpus as $checkout_case_i => $checkout_case ) {
	$checkout_writer = new DB_Chat_Checkout_Writer();
	$checkout_result = $checkout_writer->set_details( $checkout_case );
	$checkout_flat    = json_encode( $checkout_result );

	foreach ( $checkout_case as $checkout_field => $checkout_value ) {
		t_ok(
			false === strpos( $checkout_flat, $checkout_value ),
			"CHECKOUT-SAFETY: set_details result #$checkout_case_i carries no PII value for $checkout_field"
		);
	}
}

// 3) DB_Chat_PII::scrub redacts email/phone and leaves price/size/model/year
// untouched. Reuses the Task-5 corpus from tests/unit/test-pii.php.
t_eq(
	DB_Chat_PII::scrub( 'reach me at a@b.com or +60 12-345 6789' ),
	'reach me at [redacted] or [redacted]',
	'CHECKOUT-SAFETY: PII::scrub redacts email and phone'
);
t_eq(
	DB_Chat_PII::scrub( 'the Shinko 705 is RM653.00 in size 120/80-17, 2024 model' ),
	'the Shinko 705 is RM653.00 in size 120/80-17, 2024 model',
	'CHECKOUT-SAFETY: PII::scrub keeps price/size/model/year strings unchanged'
);

// 4) Hard-wall structural predicate: the two checkout tool methods that
// return to Claude — tool_checkout_details_status() (set_checkout_details)
// and tool_checkout_summary() (get_checkout_summary) — must never key
// their result by order_id/payment/paid. Both build a fixed array literal
// (no loop, no dynamic key), so scanning the method body's source text for
// a quoted forbidden key is a sound, pure proxy for "the returned array
// never contains this key": a future edit can only add such a key by
// writing it in source, and this fails the instant that literal appears.
$endpoint_source = file_get_contents( __DIR__ . '/../includes/class-chat-endpoint.php' );

$tool_details_pos = strpos( $endpoint_source, 'private function tool_checkout_details_status(' );
$tool_summary_pos = strpos( $endpoint_source, 'private function tool_checkout_summary(' );
$tool_next_pos    = strpos( $endpoint_source, 'private function tool_propose_cart_change(' );

t_ok(
	false !== $tool_details_pos && false !== $tool_summary_pos && false !== $tool_next_pos && $tool_details_pos < $tool_summary_pos && $tool_summary_pos < $tool_next_pos,
	'CHECKOUT-SAFETY: located tool_checkout_details_status()/tool_checkout_summary() in source'
);

$tool_details_body = substr( $endpoint_source, $tool_details_pos, $tool_summary_pos - $tool_details_pos );
$tool_summary_body  = substr( $endpoint_source, $tool_summary_pos, $tool_next_pos - $tool_summary_pos );

// Documented safe shapes (tests/unit/test-checkout-tools.php asserts these
// are exactly what Claude receives): {ok,fields,missing} and
// {ready,item_count,has_address,shipping,total}.
foreach ( array( 'ok', 'fields', 'missing' ) as $expected_key ) {
	t_ok( false !== strpos( $tool_details_body, "'" . $expected_key . "'" ), "CHECKOUT-SAFETY: tool_checkout_details_status() still returns '$expected_key'" );
}
foreach ( array( 'ready', 'item_count', 'has_address', 'shipping', 'total' ) as $expected_key ) {
	t_ok( false !== strpos( $tool_summary_body, "'" . $expected_key . "'" ), "CHECKOUT-SAFETY: tool_checkout_summary() still returns '$expected_key'" );
}

foreach ( array( 'order_id', 'payment', 'paid' ) as $forbidden_key ) {
	$needle = "'" . $forbidden_key . "'";
	t_ok( false === strpos( $tool_details_body, $needle ), "CHECKOUT-SAFETY: tool_checkout_details_status() never keys result by '$forbidden_key'" );
	t_ok( false === strpos( $tool_summary_body, $needle ), "CHECKOUT-SAFETY: tool_checkout_summary() never keys result by '$forbidden_key'" );
}

// No-store: handle_request() must call prevent_caching() before any
// checkout branch. Also a pure source-position check (driving
// handle_request() itself needs a full WordPress/WooCommerce environment —
// see tests/server/test-endpoint-ai.php) — same technique as the ordering
// regression guard in tests/unit/test-checkout-tools.php.
$handle_request_pos   = strpos( $endpoint_source, 'public function handle_request(' );
$finalize_response_pos = strpos( $endpoint_source, 'private function finalize_response(' );

t_ok( false !== $handle_request_pos && false !== $finalize_response_pos, 'CHECKOUT-SAFETY: located handle_request()/finalize_response() in source' );

$prevent_caching_pos       = strpos( $endpoint_source, '$this->prevent_caching(', $handle_request_pos );
$capture_checkout_input_pos = strpos( $endpoint_source, '$this->capture_checkout_input( $request )', $handle_request_pos );
$checkout_response_pos      = strpos( $endpoint_source, 'if ( $this->checkout_tool_used )', $handle_request_pos );

t_ok( false !== $prevent_caching_pos && $prevent_caching_pos < $finalize_response_pos, 'CHECKOUT-SAFETY: handle_request() calls prevent_caching()' );
t_ok( false !== $capture_checkout_input_pos && $prevent_caching_pos < $capture_checkout_input_pos, 'CHECKOUT-SAFETY: prevent_caching() runs before checkout-details capture' );
t_ok( false !== $checkout_response_pos && $prevent_caching_pos < $checkout_response_pos, 'CHECKOUT-SAFETY: prevent_caching() runs before the checkout-tool-used response branch' );

t_done();
