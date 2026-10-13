<?php
// Run: wp eval-file durianbikers-chatbot/tests/server/test-checkout-intent.php --path=<docroot>
//
// WHAT THIS PROVES. "I want to checkout" has to SHOW the delivery form. It used
// to depend on the MODEL calling a checkout tool, so when Haiku answered in
// prose ("fill in the form that'll pop up") the widget was sent nothing and
// rendered nothing — the operator had to ask "where is the form?" twice in one
// real conversation. The fix reads the customer's words in code
// (DB_Chat_Chat_Endpoint::wants_checkout) and attaches the checkout step
// through the same block, the same empty-cart guard and the same PII boundary
// the tool path uses.
//
// THE NETWORK IS BLOCKED below, which makes the central assertion airtight: no
// HTTP request can leave, so no model call and therefore NO TOOL CALL of any
// kind is possible in this process. Every `checkout` key this test sees was
// attached deterministically, in code. It also means the test costs nothing in
// API spend.
//
// Controller: run this wrapped in the cart snapshot/restore helper (see
// [[chatbot-add-to-cart-sep2026]] — ~/claude-uploads/t21-cart-snapshot.php
// save|restore) to protect the admin's own persistent cart. The test also
// cleans up the line it adds.
require __DIR__ . '/../lib/assert.php';

if ( ! class_exists( 'DB_Chat_Chat_Endpoint' ) || ! function_exists( 'db_chat' ) || ! class_exists( 'DB_Chat_Cart_Writer' ) ) {
	t_ok( false, 'chatbot plugin not loaded' );
	t_done();
}

// ---------------------------------------------------------------------------
// Harness isolation. Nothing here changes behaviour for a real customer: these
// filters live and die with this CLI process.
// ---------------------------------------------------------------------------

// No outbound HTTP at all — no Anthropic call, no spend. The orchestrator
// silently falls back to the free keyword engine, which is exactly what it
// does in production when AI is unavailable.
add_filter(
	'pre_http_request',
	function () {
		return new WP_Error( 'db_chat_test_blocked', 'network blocked by the test harness' );
	},
	0
);

// Force the AI branch regardless of the stored option: the checkout attach
// block lives on that branch (same as tests/server/test-endpoint-ai.php).
add_filter(
	'db_chat_mode',
	function () {
		return 'ai';
	}
);

// Keep the decline worklist clean: these are test questions, not customers'.
add_filter( 'db_chat_lookup_log_unmatched', '__return_false' );

// Handoff off for the duration, so the run does not create conversation rows
// in the staff inbox. The checkout path needs no conversation.
add_filter(
	'pre_option_db_chat_handoff_enabled',
	function () {
		return '0';
	}
);

// Several turns in a row from one "visitor" would trip the real rate limit.
foreach ( array( 'db_chat_rate_limit', 'db_chat_rate_limit_ip' ) as $rl_filter ) {
	add_filter(
		$rl_filter,
		function () {
			return 1000;
		}
	);
}

/**
 * One /chat turn, through the real endpoint.
 *
 * @param string $message Customer's message.
 * @param array  $extra   Extra request params.
 * @return array Response data.
 */
function db_chat_test_turn( $message, array $extra = array() ) {
	$request = new WP_REST_Request( 'POST', '/durianbikers/v1/chat' );
	$request->set_param( 'message', $message );
	$request->set_param( 'history', array() );

	foreach ( $extra as $key => $value ) {
		$request->set_param( $key, $value );
	}

	$response = db_chat()->endpoint()->handle_request( $request );

	if ( is_wp_error( $response ) ) {
		return array( 'wp_error' => $response->get_error_code() );
	}

	return ( $response instanceof WP_REST_Response ) ? $response->get_data() : (array) $response;
}

// ===========================================================================
// 1. wants_checkout() — a few live smoke checks only.
//
// The FULL truth table (30 phrases that must fire, 30 that must not, in
// English, Malay and Chinese) lives offline in
// tests/unit/test-checkout-intent.php, which runs in milliseconds with no
// WordPress. It is deliberately NOT duplicated here: two copies of a word
// list drift, and the offline one is the copy that gets run.
// ===========================================================================
foreach ( array( 'I want to checkout', 'checkout', 'nak bayar', 'proceed to payment' ) as $message ) {
	t_ok( true === DB_Chat_Chat_Endpoint::wants_checkout( $message ), 'wants_checkout fires: ' . $message );
}

foreach ( array(
	'[checkout details provided]',
	'tak nak bayar',
	'why did checkout fail',
	'boleh bayar guna fpx',
	'do you have helmets i can pay for at checkout with fpx or card',
) as $message ) {
	t_ok( false === DB_Chat_Chat_Endpoint::wants_checkout( $message ), 'wants_checkout stays quiet: ' . $message );
}

// ===========================================================================
// 2. Empty cart + "i want to checkout" → needs 'cart', no summary, no link.
//    The empty-cart guard is shared with the tool path and must not be
//    weakened by firing the step deterministically.
// ===========================================================================
$cart = new DB_Chat_Cart_Writer();

// Start from an empty cart for this CLI session.
if ( function_exists( 'WC' ) ) {
	if ( null === WC()->cart && function_exists( 'wc_load_cart' ) ) {
		wc_load_cart();
	}

	if ( WC()->cart ) {
		WC()->cart->empty_cart();
	}
}

t_eq( (int) $cart->contents()['count'], 0, 'cart is empty to start' );

$empty = db_chat_test_turn( 'i want to checkout' );

t_ok( isset( $empty['checkout'] ) && is_array( $empty['checkout'] ), 'empty cart: checkout block attached with NO tool call possible (network blocked)' );
t_eq( isset( $empty['checkout']['needs'] ) ? $empty['checkout']['needs'] : null, 'cart', 'empty cart: needs is "cart"' );
t_ok( array_key_exists( 'summary', (array) $empty['checkout'] ) && null === $empty['checkout']['summary'], 'empty cart: summary is null — never an RM0.00 card' );
t_ok( ! isset( $empty['checkout_link'] ), 'empty cart: no proceed-to-payment link' );

// ===========================================================================
// 3. Non-empty cart + "i want to checkout" as a GUEST → needs 'address',
//    which is what makes the widget open the delivery form.
// ===========================================================================
$product      = wc_get_product( 59463 ); // HJC Helmet C10 — the usual test product.
$variation_id = 0;

if ( $product instanceof WC_Product_Variable ) {
	foreach ( $product->get_children() as $child_id ) {
		$variation = wc_get_product( $child_id );

		if ( $variation instanceof WC_Product_Variation && $variation->is_in_stock() ) {
			$variation_id = (int) $variation->get_id();
			break;
		}
	}
}

t_ok( $variation_id > 0, 'found an in-stock variation on 59463' );

$added = $cart->add( 59463, $variation_id, 1 );
t_ok( ! empty( $added['ok'] ), 'test item added to the cart' );

// A CLI run has no logged-in user, so this is genuinely the guest path.
t_ok( ! is_user_logged_in(), 'running as a guest (mode should be "guest")' );

$guest = db_chat_test_turn( 'i want to checkout' );

t_ok( isset( $guest['checkout'] ) && is_array( $guest['checkout'] ), 'guest + cart: checkout block attached without any model tool call' );
t_eq( isset( $guest['checkout']['mode'] ) ? $guest['checkout']['mode'] : null, 'guest', 'guest + cart: mode is "guest"' );
t_eq( isset( $guest['checkout']['needs'] ) ? $guest['checkout']['needs'] : null, 'address', 'guest + cart: needs is "address" — the widget opens the delivery form' );
t_ok( empty( db_chat()->client()->last_tool_results() ), 'no tool results exist at all this turn (no model call was possible)' );

// ===========================================================================
// 4. A product question that merely mentions checkout attaches NOTHING. A
//    form appearing mid-product-question is the failure this bound protects
//    against.
// ===========================================================================
$product_q = db_chat_test_turn( 'do you have helmets i can pay for at checkout with fpx or card' );

t_ok( ! isset( $product_q['checkout'] ), 'product question mentioning checkout: no checkout block' );
t_ok( ! isset( $product_q['checkout_link'] ), 'product question mentioning checkout: no payment link' );

// ===========================================================================
// 5. A plain product question is unchanged: a reply, a products array, and no
//    checkout machinery.
// ===========================================================================
$plain = db_chat_test_turn( 'do you have shinko 705' );

t_ok( isset( $plain['reply'] ) && '' !== trim( (string) $plain['reply'] ), 'plain product question still answers' );
t_ok( isset( $plain['products'] ) && is_array( $plain['products'] ), 'plain product question still returns a products array' );
t_ok( ! isset( $plain['checkout'] ), 'plain product question: no checkout block' );

// ===========================================================================
// Cleanup — never leave a test line in a cart.
// ===========================================================================
if ( function_exists( 'WC' ) && WC()->cart ) {
	WC()->cart->empty_cart();
}

t_eq( (int) ( new DB_Chat_Cart_Writer() )->contents()['count'], 0, 'cart emptied again after the test' );

t_done();
