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
t_done();
