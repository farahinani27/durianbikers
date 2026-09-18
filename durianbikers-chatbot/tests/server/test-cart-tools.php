<?php
// Run: wp eval-file durianbikers-chatbot/tests/server/test-cart-tools.php --path=<docroot>
// Controller runs this wrapped in the cart snapshot/restore helper (see
// [[chatbot-add-to-cart-sep2026]] — ~/claude-uploads/t21-cart-snapshot.php
// save|restore) to protect the admin's own persistent cart. This test also
// restores the cart itself at the end, on top of that.
//
// Exercises the code-enforced two-step gate (Task 4) end to end: the
// executor is pulled straight off the wired DB_Chat_Chat_Endpoint via
// reflection (get_tool_executor() is private — that is deliberate, nothing
// outside the endpoint should be able to build one), so this is the real
// dispatch path, not a re-implementation of it.
require __DIR__ . '/../lib/assert.php';

if ( ! class_exists( 'DB_Chat_Cart_Writer' ) || ! class_exists( 'DB_Chat_Claude_Client' ) || ! function_exists( 'db_chat' ) ) {
	t_ok( false, 'chatbot plugin not loaded' );
	t_done();
}

$cw = new DB_Chat_Cart_Writer();
$ep = db_chat()->endpoint();

$get_executor_method = new ReflectionMethod( $ep, 'get_tool_executor' );
$get_executor_method->setAccessible( true );

/**
 * Build a tool executor exactly as handle_request() would, for a fixed test
 * conversation and a given "customer said this" message. A fresh one per
 * call because $current_message is baked into the closure at build time,
 * same as it is for a real request.
 */
function db_chat_test_executor( $method, $ep, $conversation_public_id, $current_message = '' ) {
	return $method->invoke( $ep, 0, $current_message, $conversation_public_id );
}

$conversation_public_id = 'test-conv-cart-' . wp_generate_password( 12, false );

// Clean slate (in case a previous failed run left something staged).
DB_Chat_Cart_Proposal::clear( $conversation_public_id );

// --- find an in-stock AND an out-of-stock variation on 59463 (HJC Helmet C10) ---
$product           = wc_get_product( 59463 );
$in_stock_var_id    = 0;
$out_of_stock_var_id = 0;

if ( $product instanceof WC_Product_Variable ) {
	foreach ( $product->get_children() as $child_id ) {
		$variation = wc_get_product( $child_id );

		if ( ! $variation instanceof WC_Product_Variation ) {
			continue;
		}

		if ( 0 === $in_stock_var_id && $variation->is_in_stock() && $variation->has_enough_stock( 2 ) ) {
			$in_stock_var_id = (int) $variation->get_id();
		}

		if ( 0 === $out_of_stock_var_id && ! $variation->is_in_stock() ) {
			$out_of_stock_var_id = (int) $variation->get_id();
		}
	}
}

t_ok( $in_stock_var_id > 0, 'found an in-stock variation with room for qty 2 on 59463' );

// =============================================================================
// 1. propose(add, 59463, <in-stock var>, 1) → cart UNCHANGED, returns proposal.
// =============================================================================
$before_count = (int) $cw->contents()['count'];

$executor = db_chat_test_executor( $get_executor_method, $ep, $conversation_public_id );
$propose1 = $executor(
	DB_Chat_Claude_Client::TOOL_CART_PROPOSE,
	array(
		'action'       => 'add',
		'product_id'   => 59463,
		'variation_id' => $in_stock_var_id,
		'quantity'     => 1,
	)
);

t_ok( ! empty( $propose1['proposal'] ), 'propose(add) returns proposal:true' );
t_ok( isset( $propose1['proposal_id'] ) && '' !== $propose1['proposal_id'], 'propose(add) returns a proposal_id' );
t_eq( (int) $cw->contents()['count'], $before_count, 'cart COUNT UNCHANGED after propose(add) — propose never mutates' );

// =============================================================================
// 2. commit with "no thanks" → committed:false, reason not_confirmed, cart
//    UNCHANGED, proposal cleared.
// =============================================================================
$commit_no = db_chat_test_executor( $get_executor_method, $ep, $conversation_public_id, 'no thanks' )(
	DB_Chat_Claude_Client::TOOL_CART_COMMIT,
	array()
);

t_ok( isset( $commit_no['committed'] ) && false === $commit_no['committed'], 'commit("no thanks") returns committed:false' );
t_eq( $commit_no['reason'], 'not_confirmed', 'commit("no thanks") reason is not_confirmed' );
t_eq( (int) $cw->contents()['count'], $before_count, 'cart COUNT UNCHANGED after commit("no thanks")' );
t_ok( null === DB_Chat_Cart_Proposal::get( $conversation_public_id ), 'proposal cleared after a refused commit' );

// =============================================================================
// 3. re-propose, commit with "yes please" → committed:true, cart count +1.
// =============================================================================
$propose2 = db_chat_test_executor( $get_executor_method, $ep, $conversation_public_id )(
	DB_Chat_Claude_Client::TOOL_CART_PROPOSE,
	array(
		'action'       => 'add',
		'product_id'   => 59463,
		'variation_id' => $in_stock_var_id,
		'quantity'     => 1,
	)
);

t_ok( ! empty( $propose2['proposal'] ), 're-propose(add) returns proposal:true' );

$commit_yes = db_chat_test_executor( $get_executor_method, $ep, $conversation_public_id, 'yes please' )(
	DB_Chat_Claude_Client::TOOL_CART_COMMIT,
	array()
);

t_ok( isset( $commit_yes['committed'] ) && true === $commit_yes['committed'], 'commit("yes please") returns committed:true' );
t_ok( isset( $commit_yes['cart'] ) && is_array( $commit_yes['cart'] ), 'commit("yes please") returns a cart object' );
t_eq( (int) $cw->contents()['count'], $before_count + 1, 'cart count is +1 after commit("yes please")' );
t_ok( null === DB_Chat_Cart_Proposal::get( $conversation_public_id ), 'proposal cleared after a successful commit' );

// Locate the line we just committed, needed for step 5 below.
$committed_key = '';
foreach ( $cw->contents()['items'] as $line ) {
	if ( (int) $line['product_id'] === 59463 && (int) $line['variation_id'] === $in_stock_var_id ) {
		$committed_key = (string) $line['key'];
	}
}
t_ok( '' !== $committed_key, 'located the committed line in the cart' );

// =============================================================================
// 4. propose(add, 59463, <out-of-stock var>, 1) → error, no proposal.
// =============================================================================
if ( $out_of_stock_var_id > 0 ) {
	$propose_oos = db_chat_test_executor( $get_executor_method, $ep, $conversation_public_id )(
		DB_Chat_Claude_Client::TOOL_CART_PROPOSE,
		array(
			'action'       => 'add',
			'product_id'   => 59463,
			'variation_id' => $out_of_stock_var_id,
			'quantity'     => 1,
		)
	);

	t_ok( empty( $propose_oos['proposal'] ), 'propose(add, out-of-stock variation) does NOT return a proposal' );
	t_ok( empty( $propose_oos['ok'] ), 'propose(add, out-of-stock variation) is an error result' );
	t_ok( null === DB_Chat_Cart_Proposal::get( $conversation_public_id ), 'nothing staged after the out-of-stock propose' );
} else {
	t_ok( true, 'no out-of-stock variation found on 59463 to test with — skipped, not a failure' );
}

// =============================================================================
// 5. propose(update, qty 0) on the item added in step 3 → on commit(yes) the
//    item is removed.
// =============================================================================
$propose_update0 = db_chat_test_executor( $get_executor_method, $ep, $conversation_public_id )(
	DB_Chat_Claude_Client::TOOL_CART_PROPOSE,
	array(
		'action'       => 'update',
		'product_id'   => 59463,
		'variation_id' => $in_stock_var_id,
		'quantity'     => 0,
	)
);

t_ok( ! empty( $propose_update0['proposal'] ), 'propose(update, qty 0) returns proposal:true' );
t_eq( (int) $cw->contents()['count'], $before_count + 1, 'cart COUNT UNCHANGED after propose(update, qty 0)' );

$commit_update0 = db_chat_test_executor( $get_executor_method, $ep, $conversation_public_id, 'yes' )(
	DB_Chat_Claude_Client::TOOL_CART_COMMIT,
	array()
);

t_ok( isset( $commit_update0['committed'] ) && true === $commit_update0['committed'], 'commit(update qty 0, "yes") returns committed:true' );

$still_there = false;
foreach ( $cw->contents()['items'] as $line ) {
	if ( $line['key'] === $committed_key ) {
		$still_there = true;
	}
}
t_ok( ! $still_there, 'the item is REMOVED from the cart after committing an update to qty 0' );
t_eq( (int) $cw->contents()['count'], $before_count, 'cart count is back to baseline after the qty-0 update commit' );

// =============================================================================
// 6. propose(add, ..., -1) → refused (qty≤0), no proposal.
// =============================================================================
$propose_negative = db_chat_test_executor( $get_executor_method, $ep, $conversation_public_id )(
	DB_Chat_Claude_Client::TOOL_CART_PROPOSE,
	array(
		'action'       => 'add',
		'product_id'   => 59463,
		'variation_id' => $in_stock_var_id,
		'quantity'     => -1,
	)
);

t_ok( empty( $propose_negative['proposal'] ), 'propose(add, qty -1) does NOT return a proposal' );
t_ok( empty( $propose_negative['ok'] ), 'propose(add, qty -1) is an error result' );
t_ok( null === DB_Chat_Cart_Proposal::get( $conversation_public_id ), 'nothing staged after the negative-quantity propose' );

// --- commit with nothing staged → nothing_staged, never mutates -------------
$commit_nothing_staged = db_chat_test_executor( $get_executor_method, $ep, $conversation_public_id, 'yes' )(
	DB_Chat_Claude_Client::TOOL_CART_COMMIT,
	array()
);

t_ok( isset( $commit_nothing_staged['committed'] ) && false === $commit_nothing_staged['committed'], 'commit() with nothing staged returns committed:false' );
t_eq( $commit_nothing_staged['reason'], 'nothing_staged', 'commit() with nothing staged reason is nothing_staged' );
t_eq( (int) $cw->contents()['count'], $before_count, 'cart COUNT UNCHANGED at the end of the run' );

// --- empty-id guard: propose/commit against an empty conversation id --------
$propose_empty_id = db_chat_test_executor( $get_executor_method, $ep, '' )(
	DB_Chat_Claude_Client::TOOL_CART_PROPOSE,
	array(
		'action'       => 'add',
		'product_id'   => 59463,
		'variation_id' => $in_stock_var_id,
		'quantity'     => 1,
	)
);
t_ok( empty( $propose_empty_id['proposal'] ), 'propose() with an empty conversation_public_id refuses to stage' );

$commit_empty_id = db_chat_test_executor( $get_executor_method, $ep, '', 'yes' )(
	DB_Chat_Claude_Client::TOOL_CART_COMMIT,
	array()
);
t_ok( isset( $commit_empty_id['committed'] ) && false === $commit_empty_id['committed'], 'commit() with an empty conversation_public_id refuses' );

// --- view_cart is read-only and matches contents() --------------------------
$view = db_chat_test_executor( $get_executor_method, $ep, $conversation_public_id )(
	DB_Chat_Claude_Client::TOOL_CART_VIEW,
	array()
);
t_ok( isset( $view['items'], $view['count'], $view['total'], $view['cart_url'] ), 'view_cart() has the whitelisted contents() shape' );
t_eq( (int) $view['count'], $before_count, 'view_cart() count matches baseline — nothing left staged/mutated by this run' );

// --- clean up any stray staged proposal --------------------------------------
DB_Chat_Cart_Proposal::clear( $conversation_public_id );

t_done();
