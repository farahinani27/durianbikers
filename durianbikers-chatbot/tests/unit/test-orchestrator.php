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

// 5. TIERED: base model ungrounded → escalate → escalation model grounded → use it.
$facts = array( array( 'name' => 'HJC C10', 'price' => 'RM469.00' ) );
$c5 = new FakeClient( true, array( 'That one is RM999.', 'The C10 is RM469, in stock.' ), $facts, 'claude-sonnet-5' );
$o5 = new DB_Chat_Orchestrator( $c5, new FakeLookup( 'x' ), new FakeBudget( true ), new FakeIndex(), new FakeIndex() );
$r5 = $o5->answer( 'price?', array(), $noop, '', '' );
t_eq( $r5['grounding'], 'ok_escalated', 'base blocked, escalation grounded → ok_escalated' );
t_ok( strpos( $r5['reply'], 'RM469' ) !== false, 'escalated reply passes through' );
t_ok( $r5['escalate'] === false, 'escalated success does not escalate to human' );

// 6. TIERED: base ungrounded AND escalation also ungrounded → safe line.
$c6 = new FakeClient( true, array( 'RM999.', 'RM888.' ), $facts, 'claude-sonnet-5' );
$o6 = new DB_Chat_Orchestrator( $c6, new FakeLookup( 'x' ), new FakeBudget( true ), new FakeIndex(), new FakeIndex() );
$r6 = $o6->answer( 'price?', array(), $noop, '', '' );
t_eq( $r6['grounding'], 'blocked', 'both models ungrounded → blocked' );
t_ok( strpos( $r6['reply'], 'RM888' ) === false && strpos( $r6['reply'], 'RM999' ) === false, 'safe line hides both bad prices' );

// 7. Tiering OFF (escalation ''): base ungrounded → safe line immediately, no retry.
$c7 = new FakeClient( true, array( 'RM999.', 'RM469.' ), $facts, '' );
$o7 = new DB_Chat_Orchestrator( $c7, new FakeLookup( 'x' ), new FakeBudget( true ), new FakeIndex(), new FakeIndex() );
$r7 = $o7->answer( 'price?', array(), $noop, '', '' );
t_eq( $r7['grounding'], 'blocked', 'no escalation configured → blocked, no retry' );
t_done();
