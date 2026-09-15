<?php
define( 'DB_CHAT_TEST', 1 );
require __DIR__ . '/../lib/assert.php';
require __DIR__ . '/../../includes/class-grounding.php';

$facts = DB_Chat_Grounding::collect_facts([
    ['name' => 'HJC Helmet C10', 'price' => 'RM 469.00'],
    ['results' => [['name' => 'NHK R1S', 'price' => 'RM236.00']]],
]);
t_ok(in_array(469.0, $facts['prices'], true), 'collect price 469');
t_ok(in_array(236.0, $facts['prices'], true), 'collect nested price 236');

$ok = DB_Chat_Grounding::verify('The C10 is RM469, in stock.', $facts);
t_ok($ok['ok'] === true, 'grounded price passes');

$bad = DB_Chat_Grounding::verify('That helmet is RM999.', $facts);
t_ok($bad['ok'] === false && in_array('RM999', $bad['bad_prices'], true), 'ungrounded price fails');

$none = DB_Chat_Grounding::verify('Sure, want full-face or open-face?', $facts);
t_ok($none['ok'] === true, 'reply with no price passes');
t_done();
