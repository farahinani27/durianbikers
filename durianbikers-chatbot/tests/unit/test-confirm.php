<?php
define( 'DB_CHAT_TEST', 1 );
require __DIR__ . '/../lib/assert.php';
require __DIR__ . '/../../includes/class-confirm.php';

// Affirmatives.
foreach ( array( 'yes', 'Yes please', 'ok add it', 'sure', 'go ahead', 'ya', 'boleh', 'nak', 'teruskan', '好', '要', '可以' ) as $y ) {
    t_ok( DB_Chat_Confirm::is_affirmative( $y ) === true && DB_Chat_Confirm::is_refusal( $y ) === false, "affirmative: $y" );
}
// Refusals — including the traps (contain a 'yes' token).
foreach ( array( 'no', 'nope', "don't", 'cancel', 'tak', 'tak nak', 'jangan', 'batal', 'tak mau', '不要', '不用', '不了' ) as $n ) {
    t_ok( DB_Chat_Confirm::is_refusal( $n ) === true && DB_Chat_Confirm::is_affirmative( $n ) === false, "refusal: $n" );
}
// Ambiguous → neither affirmative.
foreach ( array( 'how much is it', 'what colours', 'maybe later', 'hmm' ) as $a ) {
    t_ok( DB_Chat_Confirm::is_affirmative( $a ) === false, "ambiguous not-affirmative: $a" );
}
t_done();
