<?php
define( 'DB_CHAT_TEST', 1 );
require __DIR__ . '/../lib/assert.php';
require __DIR__ . '/../../includes/class-pii.php';
require __DIR__ . '/../../includes/class-messages.php';

// 25-turn history → capped to last 20, then current message appended.
$hist = array();
for ( $i = 0; $i < 25; $i++ ) {
    $hist[] = array( 'role' => ( $i % 2 ? 'assistant' : 'user' ), 'content' => "m$i" );
}
$msgs = DB_Chat_Messages::build( $hist, 'hello there' );
t_ok( count( $msgs ) >= 1, 'produces messages' );
// The very first surviving content must NOT be from an assistant-first turn.
t_eq( $msgs[0]['role'], 'user', 'starts with a user turn' );
// Alternation: no two consecutive same-role turns.
$alt = true; for ( $i = 1; $i < count( $msgs ); $i++ ) { if ( $msgs[$i]['role'] === $msgs[$i-1]['role'] ) { $alt = false; } }
t_ok( $alt, 'roles alternate (consecutive merged)' );
// Current message present at the end.
$last = $msgs[ count( $msgs ) - 1 ];
t_ok( $last['role'] === 'user' && strpos( $last['content'], 'hello there' ) !== false, 'current message appended to a user turn' );
// Cap: only the last 20 history turns feed in — m0..m4 (the oldest 5) must be gone.
// Note: m24 (the newest history turn, role 'user') merges with the appended current
// message ("m24\n\nhello there"), so it is checked as a substring, not a quoted token.
$joined = json_encode( $msgs );
t_ok( strpos( $joined, 'm0' ) === false && strpos( $joined, 'm24' ) !== false, 'history capped to last 20 (oldest dropped)' );

// PII scrub across history AND current message.
$msgs2 = DB_Chat_Messages::build(
    array( array( 'role' => 'user', 'content' => 'saved card 4111 1111 1111 1111' ) ),
    'and this one 4000-0000-0000-0002'
);
$blob = json_encode( $msgs2 );
t_ok( strpos( $blob, '4111' ) === false && strpos( $blob, '4000' ) === false, 'cards scrubbed from history + current' );
t_ok( strpos( $blob, '[redacted]' ) !== false, 'redaction marker present' );
t_done();
