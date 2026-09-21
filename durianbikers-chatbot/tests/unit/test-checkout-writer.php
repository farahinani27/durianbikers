<?php
define( 'DB_CHAT_TEST', 1 );
require __DIR__ . '/stubs.php';                       // WC()/wc_* stubs used by the other unit tests
require __DIR__ . '/../../includes/class-pii.php';
require __DIR__ . '/../../includes/class-checkout-writer.php';
require __DIR__ . '/../lib/assert.php';

// sanitize is pure and strips control/tags, trims, caps length.
$s = DB_Chat_Checkout_Writer::sanitize_details([
    'first_name' => "  Farah\n", 'email' => 'a@b.com', 'phone' => '+60 12-345 6789',
    'address_1' => '<b>12 Jalan</b>', 'country' => 'my', 'junk' => 'x',
]);
t_ok($s['first_name'] === 'Farah', 'trims name');
t_ok($s['email'] === 'a@b.com', 'keeps email');
t_ok(strpos($s['address_1'], '<') === false, 'strips tags from address');
t_ok($s['country'] === 'MY', 'uppercases country');
t_ok(!array_key_exists('junk', $s), 'drops unknown keys');

// set_details returns booleans only — NEVER a value.
$w = new DB_Chat_Checkout_Writer();
$res = $w->set_details(['first_name' => 'Farah', 'email' => 'a@b.com']);
t_ok($res['ok'] === true, 'set ok');
t_ok($res['fields']['first_name'] === true, 'first_name flagged set');
t_ok($res['fields']['phone'] === false, 'absent field flagged not-set');
$flat = json_encode($res);
t_ok(strpos($flat, 'Farah') === false && strpos($flat, 'a@b.com') === false,
     'set_details result contains NO PII values');
t_done();
