<?php
define( 'DB_CHAT_TEST', 1 );
require __DIR__ . '/../lib/assert.php';
require __DIR__ . '/../lib/wp-shims.php';
require __DIR__ . '/../../includes/class-claude-client.php';
$p = DB_Chat_Claude_Client::prompt_directives();
foreach (['one question','never claim to be a person','AI assistant','match','only state','a human','confirm','propose_cart_change','commit_cart_change'] as $needle) {
    t_ok(stripos($p, $needle) !== false, "prompt mentions: $needle");
}
t_ok(strlen($p) > 400, 'directive block is substantial');
t_done();
