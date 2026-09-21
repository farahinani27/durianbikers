<?php
// Run: php tests/unit/test-checkout-tools.php
//
// Task 3 (Phase 3 checkout): the four checkout tools are registered on
// DB_Chat_Claude_Client, dispatched by DB_Chat_Chat_Endpoint's private
// get_tool_executor(), and — the point of the task — whatever they return
// to Claude carries NO PII value and NO address_line/items. The real
// customer-facing address string and item list only ever reach
// $this->checkout_widget_summary (a private, request-scoped property),
// never last_tool_results(), which is what Claude sees.
//
// Everything here is exercised through the SAME private closure
// handle_request() builds (get_tool_executor(), pulled out via reflection —
// mirrors tests/server/test-cart-tools.php's approach for the cart tools),
// so this is the real dispatch path, not a re-implementation of it.
define( 'DB_CHAT_TEST', 1 );
require __DIR__ . '/stubs.php';

// class-cart-writer.php / class-chat-endpoint.php / class-product-index.php
// only guard on ABSPATH (no DB_CHAT_TEST fallback) — same reasoning as
// test-checkout-writer.php: satisfy the guard, nothing else about a real WP
// install is assumed.
define( 'ABSPATH', __DIR__ . '/' );

if ( ! function_exists( 'add_action' ) )        { function add_action( ...$args ) { return true; } }
if ( ! function_exists( 'add_filter' ) )        { function add_filter( ...$args ) { return true; } }
if ( ! function_exists( 'is_user_logged_in' ) ) { function is_user_logged_in() { return $GLOBALS['db_chat_test_logged_in'] ?? false; } }
if ( ! function_exists( 'wc_get_checkout_url' ) ) { function wc_get_checkout_url() { return 'https://durianbikers.com/checkout/'; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $data ) { return json_encode( $data ); } }

// DB_Chat_Product_Index's CACHE_TTL const references this WP time constant.
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }

require __DIR__ . '/../../includes/class-cart-writer.php';
require __DIR__ . '/../../includes/class-pii.php';
require __DIR__ . '/../../includes/class-checkout-writer.php';
require __DIR__ . '/../../includes/class-claude-client.php';
require __DIR__ . '/../../includes/class-product-index.php';
require __DIR__ . '/../../includes/class-chat-endpoint.php';
require __DIR__ . '/../lib/assert.php';

/*
 * -----------------------------------------------------------------------
 * Build a real endpoint instance, exactly the shape the constructor wants.
 * -----------------------------------------------------------------------
 */
$product_index = new DB_Chat_Product_Index();
$client        = new DB_Chat_Claude_Client();
$endpoint      = new DB_Chat_Chat_Endpoint( $product_index, $client );

$get_executor_method = new ReflectionMethod( $endpoint, 'get_tool_executor' );
$get_executor_method->setAccessible( true );

$executor = $get_executor_method->invoke( $endpoint, 0, '', 'test-conv-checkout' );

/*
 * -----------------------------------------------------------------------
 * Consts + schemas: registered on the client, not just floating strings.
 * -----------------------------------------------------------------------
 */
t_ok( DB_Chat_Claude_Client::TOOL_CHECKOUT_DETAILS === 'set_checkout_details', 'TOOL_CHECKOUT_DETAILS const' );
t_ok( DB_Chat_Claude_Client::TOOL_CHECKOUT_SUMMARY === 'get_checkout_summary', 'TOOL_CHECKOUT_SUMMARY const' );
t_ok( DB_Chat_Claude_Client::TOOL_CHECKOUT_LINK === 'get_checkout_link', 'TOOL_CHECKOUT_LINK const' );
t_ok( DB_Chat_Claude_Client::TOOL_SHIPPING_COUNTRY === 'check_shipping_country', 'TOOL_SHIPPING_COUNTRY const' );

$tools_method = new ReflectionMethod( $client, 'get_tools' );
$tools_method->setAccessible( true );
$tool_names = array_column( $tools_method->invoke( $client ), 'name' );

foreach ( array( 'set_checkout_details', 'get_checkout_summary', 'get_checkout_link', 'check_shipping_country' ) as $expected ) {
	t_ok( in_array( $expected, $tool_names, true ), "tools() registers $expected" );
}

/*
 * -----------------------------------------------------------------------
 * set_checkout_details — { ok, fields:{name:bool}, missing:[name] }.
 * NO PII value: an empty WC()->customer means every REQUIRED field is
 * missing, so the whole assertion is over booleans and field NAMES.
 * -----------------------------------------------------------------------
 */
$details = $executor( DB_Chat_Claude_Client::TOOL_CHECKOUT_DETAILS, array() );

t_ok( is_array( $details ) && array_key_exists( 'ok', $details ) && is_bool( $details['ok'] ), 'details: ok is bool' );
t_ok( isset( $details['fields'] ) && is_array( $details['fields'] ), 'details: fields is an array' );
t_ok( isset( $details['missing'] ) && is_array( $details['missing'] ), 'details: missing is an array' );
t_ok( $details['ok'] === false, 'details: not ok with no fields set on a fresh customer' );

foreach ( DB_Chat_Checkout_Writer::REQUIRED as $f ) {
	t_ok( array_key_exists( $f, $details['fields'] ) && $details['fields'][ $f ] === false, "details: fields[$f] is false (missing)" );
}

foreach ( $details['missing'] as $m ) {
	t_ok( is_string( $m ), 'details: missing entries are field-name strings' );
}

$details_flat = json_encode( $details );
t_ok( strpos( $details_flat, '@' ) === false, 'details result has no email-shaped value' );

// Now set a real name/email/phone on the fake WC customer and confirm the
// tool reports ONLY the field name as true — never "Farah" or the email.
WC()->customer->set_billing_first_name( 'Farah' );
WC()->customer->set_billing_email( 'farah@example.test' );

$details2 = $executor( DB_Chat_Claude_Client::TOOL_CHECKOUT_DETAILS, array() );
$flat2    = json_encode( $details2 );

t_ok( strpos( $flat2, 'Farah' ) === false, 'details result never contains the captured name' );
t_ok( strpos( $flat2, 'farah@example.test' ) === false, 'details result never contains the captured email' );
t_ok( $details2['fields']['email'] === true, 'details: email now flagged set (name only, not value)' );

/*
 * -----------------------------------------------------------------------
 * get_checkout_summary — { ready, item_count, has_address, shipping, total }.
 * NO items, NO address_line to Claude; those land on the widget-only
 * property, which the executor path never returns.
 * -----------------------------------------------------------------------
 */
WC()->customer->set_shipping_first_name( 'Farah' );
WC()->customer->set_shipping_address_1( '12 Jalan Test' );
WC()->customer->set_shipping_city( 'Kuala Lumpur' );
WC()->customer->set_shipping_postcode( '50000' );
WC()->customer->set_shipping_country( 'MY' );

$summary = $executor( DB_Chat_Claude_Client::TOOL_CHECKOUT_SUMMARY, array() );

t_ok( is_array( $summary ), 'summary: returns an array' );
$expected_keys = array( 'ready', 'item_count', 'has_address', 'shipping', 'total' );
foreach ( $expected_keys as $k ) {
	t_ok( array_key_exists( $k, $summary ), "summary: has $k" );
}
t_ok( array_keys( $summary ) === $expected_keys, 'summary: exactly the five contract keys, nothing more' );
t_ok( ! array_key_exists( 'items', $summary ), 'summary result to Claude has NO items key' );
t_ok( ! array_key_exists( 'address_line', $summary ), 'summary result to Claude has NO address_line key' );
t_ok( is_bool( $summary['ready'] ), 'summary: ready is bool' );
t_ok( is_int( $summary['item_count'] ), 'summary: item_count is int' );
t_ok( is_bool( $summary['has_address'] ), 'summary: has_address is bool' );
t_ok( $summary['has_address'] === true, 'summary: has_address true once shipping fields are set' );
t_ok( is_string( $summary['shipping'] ) && is_string( $summary['total'] ), 'summary: shipping/total are strings' );

$summary_flat = json_encode( $summary );
t_ok( strpos( $summary_flat, 'Jalan' ) === false, 'summary result to Claude never contains the street address' );
t_ok( strpos( $summary_flat, 'Kuala Lumpur' ) === false, 'summary result to Claude never contains the city' );

// The widget-facing payload is a SEPARATE, private property — never part of
// what the executor returned above, and it DOES carry the address.
$widget_prop = new ReflectionProperty( $endpoint, 'checkout_widget_summary' );
$widget_prop->setAccessible( true );
$widget      = $widget_prop->getValue( $endpoint );

t_ok( is_array( $widget ), 'checkout_widget_summary populated after get_checkout_summary ran' );
foreach ( array( 'items', 'shipping', 'total', 'address_line', 'cart_url' ) as $k ) {
	t_ok( array_key_exists( $k, $widget ), "widget summary has $k" );
}
t_ok( strpos( (string) $widget['address_line'], 'Jalan Test' ) !== false, 'widget summary DOES carry the real address line' );

/*
 * -----------------------------------------------------------------------
 * get_checkout_link — { url }. Not PII (public checkout URL).
 * -----------------------------------------------------------------------
 */
$link = $executor( DB_Chat_Claude_Client::TOOL_CHECKOUT_LINK, array() );

t_ok( is_array( $link ) && array_keys( $link ) === array( 'url' ), 'link: exactly one key, url' );
t_ok( $link['url'] === 'https://durianbikers.com/checkout/', 'link: url comes from wc_get_checkout_url()' );

$link_prop = new ReflectionProperty( $endpoint, 'checkout_link_url' );
$link_prop->setAccessible( true );
t_ok( $link_prop->getValue( $endpoint ) === 'https://durianbikers.com/checkout/', 'checkout_link_url stashed for handle_request()' );

/*
 * -----------------------------------------------------------------------
 * check_shipping_country — { served, zone }.
 * -----------------------------------------------------------------------
 */
$ship = $executor( DB_Chat_Claude_Client::TOOL_SHIPPING_COUNTRY, array( 'country' => 'GB' ) );

t_ok( is_array( $ship ) && array_keys( $ship ) === array( 'served', 'zone' ), 'shipping: exactly served/zone' );
t_ok( is_bool( $ship['served'] ), 'shipping: served is bool' );
t_ok( is_string( $ship['zone'] ), 'shipping: zone is a string' );

/*
 * -----------------------------------------------------------------------
 * checkout_tool_used: every one of the four branches flags it, which is
 * what makes handle_request() attach the `checkout` response key even on a
 * turn that only checked shipping. Confirmed here per-tool via a fresh
 * executor + fresh endpoint each time (checkout_tool_used only resets at
 * the top of handle_request(), which we do not call here).
 * -----------------------------------------------------------------------
 */
foreach ( array(
	DB_Chat_Claude_Client::TOOL_CHECKOUT_DETAILS => array(),
	DB_Chat_Claude_Client::TOOL_CHECKOUT_SUMMARY => array(),
	DB_Chat_Claude_Client::TOOL_CHECKOUT_LINK    => array(),
	DB_Chat_Claude_Client::TOOL_SHIPPING_COUNTRY => array( 'country' => 'MY' ),
) as $tool_name => $tool_input ) {
	$ep2       = new DB_Chat_Chat_Endpoint( $product_index, $client );
	$exec2     = $get_executor_method->invoke( $ep2, 0, '', 'test-conv-checkout-2' );
	$exec2( $tool_name, $tool_input );

	$flag_prop = new ReflectionProperty( $ep2, 'checkout_tool_used' );
	$flag_prop->setAccessible( true );
	t_ok( $flag_prop->getValue( $ep2 ) === true, "checkout_tool_used set true after $tool_name" );
}

/*
 * -----------------------------------------------------------------------
 * Unknown-tool / allowlist: the client rejects anything not in its
 * allowlist, and the checkout tool names must now be accepted there (not
 * routed to the "Unknown tool" branch). Exercised via run_tool(), same as
 * every other tool name, through a tiny fake executor.
 * -----------------------------------------------------------------------
 */
$run_tool_method = new ReflectionMethod( $client, 'run_tool' );
$run_tool_method->setAccessible( true );

foreach ( array( 'set_checkout_details', 'get_checkout_summary', 'get_checkout_link', 'check_shipping_country' ) as $name ) {
	$block = $run_tool_method->invoke(
		$client,
		array( 'id' => 'toolu_1', 'name' => $name, 'input' => ( 'check_shipping_country' === $name ? array( 'country' => 'MY' ) : array() ) ),
		function ( $n, $i ) { return array( 'ok' => true ); }
	);
	t_ok( empty( $block['is_error'] ), "run_tool: $name is allowlisted (no is_error)" );
}

/*
 * -----------------------------------------------------------------------
 * Task 4: deterministic checkout-details capture from the widget.
 *
 * capture_checkout_input() is the ONE call site in handle_request() that
 * may read checkout_details / checkout_confirm_address. It must (a) write
 * any real form values ONLY to WC()->customer, via
 * DB_Chat_Checkout_Writer::set_details(), and (b) hand handle_request()
 * back nothing but a neutral marker string — never the typed PII — so that
 * marker (not the customer's real form values) is what ends up stored to
 * DB_Chat_Conversation_Store and passed to maybe_log()/Claude for this
 * turn. Exercised via reflection on a fresh endpoint, exactly like the
 * tool-executor tests above.
 * -----------------------------------------------------------------------
 */
$read_details_method = new ReflectionMethod( $endpoint, 'read_checkout_details' );
$read_details_method->setAccessible( true );

$capture_method = new ReflectionMethod( $endpoint, 'capture_checkout_input' );
$capture_method->setAccessible( true );

// -- read_checkout_details(): shape whitelisting, no free text. --
t_ok( null === $read_details_method->invoke( $endpoint, new FakeWPRequest() ), 'read_checkout_details: no param -> null' );
t_ok( null === $read_details_method->invoke( $endpoint, new FakeWPRequest( array( 'checkout_details' => 'not an array' ) ) ), 'read_checkout_details: non-array -> null' );
t_ok( null === $read_details_method->invoke( $endpoint, new FakeWPRequest( array( 'checkout_details' => array() ) ) ), 'read_checkout_details: empty array -> null' );
t_ok( null === $read_details_method->invoke( $endpoint, new FakeWPRequest( array( 'checkout_details' => array( 'evil_key' => 'x' ) ) ) ), 'read_checkout_details: no known field -> null' );

$sanitised = $read_details_method->invoke(
	$endpoint,
	new FakeWPRequest(
		array(
			'checkout_details' => array(
				'first_name' => 'Farah',
				'email'      => 'farah@example.test',
				'evil_key'   => '<script>x</script>',
			),
		)
	)
);
t_ok( is_array( $sanitised ), 'read_checkout_details: known field -> sanitised map' );
t_ok( $sanitised['first_name'] === 'Farah', 'read_checkout_details: keeps known field value' );
t_ok( ! array_key_exists( 'evil_key', $sanitised ), 'read_checkout_details: drops unknown key' );

// -- capture_checkout_input(): writer receives the PII, caller gets only a marker. --
$ep_details   = new DB_Chat_Chat_Endpoint( $product_index, $client );
$req_details  = new FakeWPRequest(
	array(
		'checkout_details' => array(
			'first_name' => 'Siti',
			'last_name'  => 'Rahman',
			'email'      => 'siti@example.test',
			'phone'      => '0123456789',
			'address_1'  => '5 Jalan Bunga',
			'city'       => 'Ipoh',
			'postcode'   => '30000',
			'country'    => 'MY',
		),
	)
);
$marker_details = $capture_method->invoke( $ep_details, $req_details );

t_ok( '[checkout details provided]' === $marker_details, 'capture_checkout_input: returns the neutral marker for a form payload' );
t_ok( WC()->customer->get_billing_first_name() === 'Siti', 'capture_checkout_input: writer wrote first_name to WC()->customer' );
t_ok( WC()->customer->get_billing_email() === 'siti@example.test', 'capture_checkout_input: writer wrote email to WC()->customer' );
t_ok( is_string( $marker_details ) && strpos( $marker_details, 'Siti' ) === false, 'capture_checkout_input: marker never contains the captured name' );
t_ok( is_string( $marker_details ) && strpos( $marker_details, 'siti@example.test' ) === false, 'capture_checkout_input: marker never contains the captured email' );

// -- Logged-in "deliver to saved address" tap: marker, no writer call needed. --
$ep_confirm  = new DB_Chat_Chat_Endpoint( $product_index, $client );
$req_confirm = new FakeWPRequest( array( 'checkout_confirm_address' => true ) );
$marker_confirm = $capture_method->invoke( $ep_confirm, $req_confirm );

t_ok( '[confirmed saved address]' === $marker_confirm, 'capture_checkout_input: saved-address tap -> its own neutral marker' );

// -- Neither signal present this turn: leave $message alone (null, not a marker). --
$ep_none  = new DB_Chat_Chat_Endpoint( $product_index, $client );
$req_none = new FakeWPRequest( array( 'message' => 'do you have MT helmets?' ) );
t_ok( null === $capture_method->invoke( $ep_none, $req_none ), 'capture_checkout_input: no checkout signal -> null (message untouched)' );

// A false / empty checkout_confirm_address must not falsely mark the turn.
$req_false = new FakeWPRequest( array( 'checkout_confirm_address' => false ) );
t_ok( null === $capture_method->invoke( $ep_none, $req_false ), 'capture_checkout_input: checkout_confirm_address=false -> null' );

/*
 * -----------------------------------------------------------------------
 * Ordering regression guard: capture_checkout_input() MUST be called
 * before the handoff conversation's customer add_message() AND before
 * every maybe_log() call in handle_request() — otherwise the raw PII
 * would already be committed to the conversation store / logs before the
 * marker replacement ever runs. A pure source-position check, because
 * driving handle_request() itself needs a full WordPress/WooCommerce
 * environment (see tests/server/test-endpoint-ai.php).
 * -----------------------------------------------------------------------
 */
$source              = file_get_contents( __DIR__ . '/../../includes/class-chat-endpoint.php' );
$handle_request_start = strpos( $source, 'function handle_request(' );
$handle_request_end   = strpos( $source, 'function finalize_response(' );
t_ok( false !== $handle_request_start && false !== $handle_request_end, 'ordering guard: located handle_request()/finalize_response() in source' );

// Patterns match only the real call sites (e.g. "$this->maybe_log("), never
// the prose in the surrounding comments that also mentions these names.
$capture_pos = strpos( $source, '$this->capture_checkout_input( $request )', $handle_request_start );
$add_message_pos = strpos( $source, "add_message( (int) \$conversation['id'], 'customer'", $handle_request_start );
$maybe_log_pos = strpos( $source, '$this->maybe_log( $message, isset(', $handle_request_start );

t_ok( false !== $capture_pos && $capture_pos < $handle_request_end, 'ordering guard: capture_checkout_input() is called inside handle_request()' );
t_ok( false !== $add_message_pos && $capture_pos < $add_message_pos, 'ordering guard: capture runs BEFORE the customer add_message() call' );
t_ok( false !== $maybe_log_pos && $capture_pos < $maybe_log_pos, 'ordering guard: capture runs BEFORE the first maybe_log() call' );

t_done();
