<?php
define( 'DB_CHAT_TEST', 1 );
require __DIR__ . '/../lib/assert.php';
require __DIR__ . '/../lib/wp-shims.php';
require __DIR__ . '/../../includes/class-claude-client.php';
$p = DB_Chat_Claude_Client::prompt_directives();
foreach (['one question','never claim to be a person','AI assistant','match','only state','a human','confirm','propose_cart_change','commit_cart_change','Tap Yes','report the cart as changed','the system makes the change','never take card','Proceed to payment','get_checkout_summary','check_shipping_country','enter payment only on','SEARCH BEFORE YOU ANSWER','FIRST action',
    // Oct 2026: the care answer recommends THREE named products (so the text
    // and the cards agree), and the reply never promises a form again — the
    // shop attaches the delivery form itself.
    'recommend THREE','NAMING each one exactly','NEVER promise a form','the form is already there'] as $needle) {
    t_ok(stripos($p, $needle) !== false, "prompt mentions: $needle");
}
t_ok(strlen($p) > 400, 'directive block is substantial');
t_done();
