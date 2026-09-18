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

t_done();
