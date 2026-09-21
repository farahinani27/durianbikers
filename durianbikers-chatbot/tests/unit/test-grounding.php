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

// Budget CEILING echoed from the customer must NOT be flagged, even though no
// product costs exactly that — but real product prices in the same reply are
// still grounded normally.
$thresh = DB_Chat_Grounding::verify('Here are helmets under RM500: the C10 is RM469.', $facts);
t_ok($thresh['ok'] === true, 'ceiling "under RM500" exempt; RM469 grounded');

$thresh2 = DB_Chat_Grounding::verify('Beberapa helmet bawah RM500 — C10 pada RM469.', $facts);
t_ok($thresh2['ok'] === true, 'Malay ceiling "bawah RM500" exempt');

// A HALLUCINATED product price must still be caught even if a ceiling appears
// elsewhere: RM344 is not a fact and is not introduced by a ceiling word.
$halluc = DB_Chat_Grounding::verify('Options under RM1000: the tyre is RM344.', $facts);
t_ok($halluc['ok'] === false && in_array('RM344', $halluc['bad_prices'], true), 'hallucinated RM344 still caught despite a ceiling elsewhere');
t_done();
