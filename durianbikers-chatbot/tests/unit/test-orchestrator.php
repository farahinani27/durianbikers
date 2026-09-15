<?php
define( 'DB_CHAT_TEST', 1 );
require __DIR__ . '/../lib/assert.php';
require __DIR__ . '/../lib/wp-shims.php';
require __DIR__ . '/stubs.php';
require __DIR__ . '/../../includes/class-pii.php';
require __DIR__ . '/../../includes/class-messages.php';
require __DIR__ . '/../../includes/class-grounding.php';
require __DIR__ . '/../../includes/class-orchestrator.php';

$noop = function ( $name, $input ) { return array(); };

// 1. AI unavailable → keyword fallback.
$o = new DB_Chat_Orchestrator( new FakeClient( true ), new FakeLookup( 'fallback reply' ), new FakeBudget( false ), new FakeIndex(), new FakeIndex() );
$r = $o->answer( 'hi', array(), $noop, '', '' );
t_eq( $r['source'], 'fallback', 'unavailable AI → fallback' );
t_eq( $r['reply'], 'fallback reply', 'fallback reply text' );

// 2. AI available, grounded reply → ai/ok.
$c2 = new FakeClient( true, array( 'The C10 is RM469, in stock.' ), array( array( 'name' => 'HJC C10', 'price' => 'RM469.00' ) ) );
$o2 = new DB_Chat_Orchestrator( $c2, new FakeLookup( 'x' ), new FakeBudget( true ), new FakeIndex(), new FakeIndex() );
$r2 = $o2->answer( 'price?', array(), $noop, '', '' );
t_eq( $r2['source'], 'ai', 'available AI → ai' );
t_eq( $r2['grounding'], 'ok', 'grounded reply ok' );
t_ok( strpos( $r2['reply'], 'RM469' ) !== false, 'grounded reply passes through' );

// 3. AI invents a price → blocked, safe line, escalate, bad price hidden.
$c3 = new FakeClient( true, array( 'That one is RM999.' ), array( array( 'name' => 'HJC C10', 'price' => 'RM469.00' ) ) );
$o3 = new DB_Chat_Orchestrator( $c3, new FakeLookup( 'x' ), new FakeBudget( true ), new FakeIndex(), new FakeIndex() );
$r3 = $o3->answer( 'price?', array(), $noop, '', '' );
t_eq( $r3['grounding'], 'blocked', 'ungrounded price blocked' );
t_ok( strpos( $r3['reply'], 'RM999' ) === false, 'blocked reply hides bad price' );
t_ok( $r3['escalate'] === true, 'blocked → escalate' );

// 4. AI errors → fallback.
$c4 = new FakeClient( true, array( new WP_Error( 'x', 'y' ) ), array() );
$o4 = new DB_Chat_Orchestrator( $c4, new FakeLookup( 'fallback2' ), new FakeBudget( true ), new FakeIndex(), new FakeIndex() );
$r4 = $o4->answer( 'hi', array(), $noop, '', '' );
t_eq( $r4['source'], 'fallback', 'AI error → fallback' );
t_eq( $r4['reply'], 'fallback2', 'fallback-on-error reply' );
t_done();
