<?php
// Run: php tests/unit/test-checkout-intent.php
//
// DB_Chat_Chat_Endpoint::wants_checkout() — the deterministic "take me to
// checkout" read. Pure: no WooCommerce, no session, no network, no model, so
// the whole truth table runs offline in milliseconds. The live counterpart
// (tests/server/test-checkout-intent.php) proves that firing it actually
// attaches the checkout step; this file proves WHEN it fires.
//
// Why it exists: the checkout step used to be attached only when the model
// called a checkout tool, so a customer who typed "I want to checkout" and got
// a prose reply saw no form and had to ask where it was — twice, in a real
// conversation. Reading the customer's own words in code takes the model out
// of that decision.
//
// Which way it errs, and why the "stays quiet" list is the longer one: a MISS
// costs a prose reply, which is exactly today's behaviour, so a miss is never
// a regression. A FALSE POSITIVE pops an address form into the middle of a
// product question.
define( 'DB_CHAT_TEST', 1 );
require __DIR__ . '/stubs.php';
define( 'ABSPATH', __DIR__ . '/' );

if ( ! function_exists( 'add_action' ) )         { function add_action( ...$args ) { return true; } }
if ( ! function_exists( 'add_filter' ) )         { function add_filter( ...$args ) { return true; } }
// The intent phrase list is filterable (new care-product vocabulary, new
// Malay phrasing); offline there are no filters, so this returns the default.
if ( ! function_exists( 'apply_filters' ) )      { function apply_filters( $tag, $value ) { return $value; } }
if ( ! function_exists( '__' ) )                 { function __( $text, $domain = null ) { return $text; } }
if ( ! function_exists( 'is_user_logged_in' ) )  { function is_user_logged_in() { return false; } }
if ( ! function_exists( 'wc_get_checkout_url' ) ) { function wc_get_checkout_url() { return 'https://durianbikers.com/checkout/'; } }
if ( ! function_exists( 'wp_json_encode' ) )     { function wp_json_encode( $data ) { return json_encode( $data ); } }
if ( ! defined( 'HOUR_IN_SECONDS' ) )            { define( 'HOUR_IN_SECONDS', 3600 ); }

require __DIR__ . '/../../includes/class-cart-writer.php';
require __DIR__ . '/../../includes/class-pii.php';
// REQUIRED, not optional: wants_checkout() checks refusals through
// DB_Chat_Confirm first, and without this the refusal cases below would pass
// for the wrong reason (the class_exists guard skipping the check).
require __DIR__ . '/../../includes/class-confirm.php';
require __DIR__ . '/../../includes/class-checkout-writer.php';
require __DIR__ . '/../../includes/class-claude-client.php';
require __DIR__ . '/../../includes/class-product-index.php';
require __DIR__ . '/../../includes/class-chat-endpoint.php';
require __DIR__ . '/../lib/assert.php';

t_ok( class_exists( 'DB_Chat_Confirm' ), 'DB_Chat_Confirm loaded — the refusal gate is live in this run' );

// ---------------------------------------------------------------------------
// FIRES. English, Malay and Chinese, including the literal the widget's cart
// "Checkout" button sends ("I want to checkout").
// ---------------------------------------------------------------------------
$fires = array(
	'I want to checkout',
	'i want to check out',
	'checkout',
	'check out',
	'Checkout!',
	'checkout please',
	'i would like to checkout now',
	'take me to checkout',
	'go to checkout',
	'proceed to payment',
	'proceed to checkout',
	'pay now',
	'make payment',
	'ready to pay',
	'place order',
	'place my order',
	'complete my order',
	'nak checkout',
	'nak bayar',
	'mahu bayar',
	'bayar sekarang',
	'teruskan ke pembayaran',
	// Whole-message-only phrases.
	'beli sekarang',
	'buy now',
	'order now',
	'bayar',
	'pay',
	// Chinese, substring-matched (no spaces to tokenise).
	'我要结账',
	'结账',
);

foreach ( $fires as $message ) {
	t_ok( true === DB_Chat_Chat_Endpoint::wants_checkout( $message ), 'fires: ' . $message );
}

// ---------------------------------------------------------------------------
// STAYS QUIET.
// ---------------------------------------------------------------------------
$quiet = array(
	// Our own neutral markers. They contain the word, but nobody typed them,
	// and the turn each stands for has its own handling. If this ever
	// regresses, submitting the delivery form re-triggers the intent.
	'[checkout details provided]',
	'[confirmed saved address]',
	// Refusal-first, same ordering and same Malay particle-negation reason as
	// the cart commit gate: "tak nak" contains the confirm word "nak".
	"I don't want to checkout yet",
	'dont checkout',
	'tak nak bayar',
	'jangan checkout',
	'不要结账',
	// An intent for another day.
	'checkout later',
	'i will checkout tomorrow',
	'bayar nanti',
	// A question about checkout, or a problem at it: a support turn, often a
	// human handoff. A form answers neither.
	'why did checkout fail',
	'my checkout page shows an error',
	'checkout is stuck',
	'my card was declined at checkout',
	'tak boleh checkout',
	// A payment-METHODS question — answered from the published help pages.
	// This is why bare "bayar" only counts as the whole message.
	'boleh bayar guna fpx',
	'what payment methods do you accept',
	'你们接受什么付款方式',
	// Longer sentences that merely mention checkout.
	'can i still use the voucher i got at checkout last week',
	'do you have helmets i can pay for at checkout with fpx or card',
	// Weak phrases inside a product question.
	'can i buy now and pay later',
	'boleh beli sekarang ke helmet ni',
	// Ordinary traffic.
	'',
	'   ',
	'hello',
	'how much is the shinko 705',
	'Here are my delivery details',
	"I'll enter my delivery details later",
	'where is my order',
	'what is your return policy',
);

foreach ( $quiet as $message ) {
	t_ok( false === DB_Chat_Chat_Endpoint::wants_checkout( $message ), 'stays quiet: "' . $message . '"' );
}

// Not a string, and a message long enough to be a question rather than a
// request, must both be refused without notices.
t_ok( false === DB_Chat_Chat_Endpoint::wants_checkout( null ), 'null is not a checkout request' );
t_ok( false === DB_Chat_Chat_Endpoint::wants_checkout( 123 ), 'a number is not a checkout request' );
t_ok(
	false === DB_Chat_Chat_Endpoint::wants_checkout( 'i want to checkout but first tell me whether the shinko 705 is in stock in 17 inch' ),
	'past the word bound, even with a real intent phrase in it'
);

// The bound itself is a named constant, not a magic number buried in a
// condition — it is the thing most likely to need tuning from real traffic.
t_ok( 8 === DB_Chat_Chat_Endpoint::CHECKOUT_INTENT_MAX_WORDS, 'the word bound is a named constant' );

t_done();
