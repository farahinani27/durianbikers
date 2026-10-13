<?php
// Run: wp eval-file durianbikers-chatbot/tests/server/test-checkout-review.php --path=<docroot>
//
// WHAT THIS PROVES, and it is two different things.
//
// 1. THE FEATURE. After the customer fills in the delivery form, the widget is
//    sent their details back — labelled, ordered, with a "Looks right" and a
//    "Fix details" — so a typo'd postcode or email is caught before they pay.
//    Before this, the turn after "Here are my delivery details" went quiet
//    unless the model happened to call a checkout tool.
//
// 2. THE BOUNDARY, which matters more. Those values are a real name, street
//    address, phone and email. They may reach the WIDGET and nothing else:
//    never a tool result, never last_tool_results(), never the message history,
//    never the reply text. Claude may know an address EXISTS and never what it
//    says. The second half of this file asserts that against the MODEL-FACING
//    payloads, not just the response array — see class-checkout-writer.php's
//    boundary note and DB_Chat_Chat_Endpoint::$checkout_widget_summary.
//
// Everything typed below is obviously-fake test data (rule: never test checkout
// with a real customer's details), the cart line is removed at the end, and the
// session's previous delivery details are snapshotted and written back.
//
// Controller: run this wrapped in the cart snapshot/restore helper, as with the
// other cart/checkout harnesses.
require __DIR__ . '/../lib/assert.php';

if ( ! class_exists( 'DB_Chat_Checkout_Writer' ) || ! class_exists( 'DB_Chat_Cart_Writer' ) || ! function_exists( 'db_chat' ) ) {
	t_ok( false, 'chatbot plugin not loaded' );
	t_done();
}

// --- harness isolation (identical to test-checkout-intent.php) -------------
add_filter(
	'pre_http_request',
	function () {
		return new WP_Error( 'db_chat_test_blocked', 'network blocked by the test harness' );
	},
	0
);
add_filter(
	'db_chat_mode',
	function () {
		return 'ai';
	}
);
add_filter( 'db_chat_lookup_log_unmatched', '__return_false' );
add_filter(
	'pre_option_db_chat_handoff_enabled',
	function () {
		return '0';
	}
);
foreach ( array( 'db_chat_rate_limit', 'db_chat_rate_limit_ip' ) as $rl_filter ) {
	add_filter(
		$rl_filter,
		function () {
			return 1000;
		}
	);
}

$writer = new DB_Chat_Checkout_Writer();
$cart   = new DB_Chat_Cart_Writer();

// Snapshot whatever this session already holds, so the run leaves nothing
// behind. captured_details() returns every FIELDS key, so set_details() can
// write it straight back.
$snapshot = $writer->captured_details();

// --- the fake details -------------------------------------------------------
// country and state are typed as NAMES on purpose: that is what a customer
// does, and folding "Malaysia"/"Johor" to MY/JHR is what makes WooCommerce
// calculate shipping at all (the "Shipping: Free!" bug).
$fake = array(
	'first_name' => 'Chatbot',
	'last_name'  => 'Harness',
	'email'      => 'chatbot-harness@example.com',
	'phone'      => '0123456789',
	'address_1'  => '1 Jalan Ujian',
	'address_2'  => 'Unit 2',
	'city'       => 'Johor Bahru',
	'state'      => 'Johor',
	'postcode'   => '80000',
	'country'    => 'Malaysia',
);

/**
 * Every fake value that must never appear outside the widget payload. The
 * country/state names are excluded from the leak scan on purpose: they are
 * rendered back as names in the review card and are not personal data on
 * their own ("Malaysia" is not an identifier).
 *
 * @return string[]
 */
function db_chat_test_pii_values() {
	return array(
		'Chatbot',
		'Harness',
		'chatbot-harness@example.com',
		'0123456789',
		'1 Jalan Ujian',
		'Unit 2',
		'80000',
	);
}

/**
 * Walk any nested structure and report which PII values appear in it.
 *
 * @param mixed $node Any value.
 * @return string[] Values found.
 */
function db_chat_test_find_pii( $node ) {
	$found = array();
	$walk  = function ( $value ) use ( &$walk, &$found ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $child ) {
				$walk( $child );
			}

			return;
		}

		if ( ! is_scalar( $value ) ) {
			return;
		}

		$text = (string) $value;

		foreach ( db_chat_test_pii_values() as $needle ) {
			if ( '' !== $needle && false !== stripos( $text, $needle ) && ! in_array( $needle, $found, true ) ) {
				$found[] = $needle;
			}
		}
	};

	$walk( $node );

	return $found;
}

// ===========================================================================
// Set up: one item in the cart (the attach block refuses to summarise an
// empty one) and nothing captured yet.
// ===========================================================================
if ( function_exists( 'WC' ) ) {
	if ( null === WC()->cart && function_exists( 'wc_load_cart' ) ) {
		wc_load_cart();
	}

	if ( WC()->cart ) {
		WC()->cart->empty_cart();
	}
}

$product      = wc_get_product( 59463 );
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
t_ok( ! empty( $cart->add( 59463, $variation_id, 1 )['ok'] ), 'test item added to the cart' );

// ===========================================================================
// 1. The real capture path: the widget posts `checkout_details` alongside the
//    neutral message text. capture_checkout_input() is private — the one call
//    site allowed to read that param — so reach it the way test-cart-tools.php
//    reaches get_tool_executor().
// ===========================================================================
$capture_request = new WP_REST_Request( 'POST', '/durianbikers/v1/chat' );
$capture_request->set_param( 'message', 'Here are my delivery details' );
$capture_request->set_param( 'checkout_details', $fake );

$capture_method = new ReflectionMethod( db_chat()->endpoint(), 'capture_checkout_input' );
$capture_method->setAccessible( true );
$marker = $capture_method->invoke( db_chat()->endpoint(), $capture_request );

t_eq( $marker, '[checkout details provided]', 'capture returns the neutral marker that replaces the message' );
t_ok( empty( db_chat_test_find_pii( $marker ) ), 'the marker carries NO captured value — this is what the transcript and the log see' );

// ===========================================================================
// 2. The writer's readers. captured_details() is FIELDS-only; review() is the
//    ordered, labelled, customer-readable version of the same values.
// ===========================================================================
$captured = $writer->captured_details();

t_eq( array_keys( $captured ), DB_Chat_Checkout_Writer::FIELDS, 'captured_details() returns exactly FIELDS, in order, and nothing else' );
t_eq( $captured['country'], 'MY', 'country name folded to the ISO code WooCommerce needs' );
t_eq( $captured['state'], 'JHR', 'state name folded to the WooCommerce state code' );

$review = $writer->review();

t_ok( is_array( $review ) && ! empty( $review ), 'review() returns rows' );

$labels = array();
$values = array();

foreach ( $review as $row ) {
	t_ok( is_array( $row ) && isset( $row['label'], $row['value'] ), 'review row has exactly a label and a value' );
	$labels[] = (string) $row['label'];
	$values[] = (string) $row['value'];
}

t_eq( $labels, array( 'Name', 'Address', 'City', 'Postcode', 'State', 'Country', 'Phone', 'Email' ), 'review rows are in the order a customer reads an address' );

// Codes are stored; names are shown. Nobody proof-reads "JHR".
t_ok( in_array( 'Johor', $values, true ), 'state is shown as a NAME, not the stored code' );
t_ok( in_array( 'Malaysia', $values, true ), 'country is shown as a NAME, not the stored code' );

// Every captured FIELDS value must be visible somewhere in the review — the
// point of the card is that nothing is hidden from the proof-read.
$joined = implode( ' | ', $values );

foreach ( DB_Chat_Checkout_Writer::FIELDS as $field ) {
	$value = trim( (string) $captured[ $field ] );

	if ( '' === $value ) {
		continue;
	}

	// country/state are rendered as names; their codes are the stored form.
	if ( 'country' === $field || 'state' === $field ) {
		continue;
	}

	t_ok( false !== strpos( $joined, $value ), 'review shows the captured ' . $field );
}

// ===========================================================================
// 3. The response the WIDGET gets. One real /chat turn, carrying the same
//    `checkout_details` payload the widget posts.
// ===========================================================================
$request = new WP_REST_Request( 'POST', '/durianbikers/v1/chat' );
$request->set_param( 'message', 'Here are my delivery details' );
$request->set_param( 'history', array() );
$request->set_param( 'checkout_details', $fake );

$response = db_chat()->endpoint()->handle_request( $request );
$data     = ( $response instanceof WP_REST_Response ) ? $response->get_data() : (array) $response;

t_ok( isset( $data['checkout'] ) && is_array( $data['checkout'] ), 'checkout block attached — with no model tool call possible (network blocked)' );
t_eq( isset( $data['checkout']['needs'] ) ? $data['checkout']['needs'] : null, '', 'address is complete, so needs is empty (no form re-open)' );
t_ok( isset( $data['checkout']['summary'] ) && is_array( $data['checkout']['summary'] ), 'the itemised summary was built in code, not by the model' );
t_ok( isset( $data['checkout']['review']['fields'] ) && is_array( $data['checkout']['review']['fields'] ), 'checkout.review.fields present' );
t_eq( count( $data['checkout']['review']['fields'] ), count( $review ), 'the response carries the same rows review() built' );

$expected_edit_url = function_exists( 'wc_get_checkout_url' ) ? (string) wc_get_checkout_url() : '';

t_eq( isset( $data['checkout']['review']['edit_url'] ) ? $data['checkout']['review']['edit_url'] : null, $expected_edit_url, '"Fix details" points at the CHECKOUT page' );
t_ok(
	'' !== $expected_edit_url && $expected_edit_url !== (string) $data['checkout']['summary']['cart_url'],
	'"Fix details" is NOT the cart URL — the customer corrects and pays in one place'
);

// ===========================================================================
// 4. THE BOUNDARY. Nothing outside checkout.summary / checkout.review may
//    carry a captured value, and nothing the MODEL sees may carry one.
// ===========================================================================
t_ok( empty( db_chat_test_find_pii( isset( $data['reply'] ) ? $data['reply'] : '' ) ), 'the reply text carries no captured value' );

$stripped = $data;
unset( $stripped['checkout'] );

t_ok( empty( db_chat_test_find_pii( $stripped ) ), 'no other response key carries a captured value' );

// The model-facing record of every tool result this turn.
t_ok( empty( db_chat_test_find_pii( db_chat()->client()->last_tool_results() ) ), 'last_tool_results() — what Claude is shown — carries no captured value' );

// And the tool results themselves, called directly through the real executor,
// so this holds even on a turn where the model DOES call every checkout tool.
$executor_method = new ReflectionMethod( db_chat()->endpoint(), 'get_tool_executor' );
$executor_method->setAccessible( true );
$executor = $executor_method->invoke( db_chat()->endpoint(), 0, 'Here are my delivery details', '' );

foreach ( array(
	DB_Chat_Claude_Client::TOOL_CHECKOUT_DETAILS,
	DB_Chat_Claude_Client::TOOL_CHECKOUT_SUMMARY,
	DB_Chat_Claude_Client::TOOL_CHECKOUT_LINK,
	DB_Chat_Claude_Client::TOOL_CART_VIEW,
) as $tool ) {
	$result = $executor( $tool, array() );
	$leaks  = db_chat_test_find_pii( $result );

	t_ok( empty( $leaks ), $tool . ' returns NO captured value to the model (leaked: ' . implode( ',', $leaks ) . ')' );
}

// get_checkout_summary is the one tool that touches the PII-bearing
// summary(); it must still report only booleans/counts to the model, while
// the widget payload it stashes keeps the address.
$summary_for_model = $executor( DB_Chat_Claude_Client::TOOL_CHECKOUT_SUMMARY, array() );

t_ok( isset( $summary_for_model['has_address'] ) && true === $summary_for_model['has_address'], 'the model is told an address EXISTS — a boolean, which is all it may know' );
t_ok( ! isset( $summary_for_model['address_line'] ) && ! isset( $summary_for_model['items'] ), 'the model gets no address_line and no itemised list' );

// ===========================================================================
// Cleanup: empty the cart, and put the session's delivery details back exactly
// as they were.
//
// set_details() deliberately skips blank values (a half-filled form must never
// wipe a field the customer already gave), so restoring an EMPTY snapshot —
// the normal case for a fresh CLI session — has to blank the fields directly.
// That is the only reason this harness touches WC()->customer itself.
// ===========================================================================
if ( function_exists( 'WC' ) && WC()->cart ) {
	WC()->cart->empty_cart();
}

$customer = ( function_exists( 'WC' ) && WC()->customer ) ? WC()->customer : null;

if ( $customer ) {
	foreach ( DB_Chat_Checkout_Writer::FIELDS as $field ) {
		$value = isset( $snapshot[ $field ] ) ? (string) $snapshot[ $field ] : '';

		foreach ( array( 'set_billing_' . $field, 'set_shipping_' . $field ) as $setter ) {
			if ( is_callable( array( $customer, $setter ) ) ) {
				$customer->{$setter}( $value );
			}
		}
	}

	$customer->save();
}

$restored = $writer->captured_details();

t_ok( empty( db_chat_test_find_pii( $restored ) ), 'the session no longer holds the harness address' );
t_eq( $restored, $snapshot, 'the session holds exactly what it held before the test' );

t_done();
