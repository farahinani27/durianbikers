<?php
define( 'DB_CHAT_TEST', 1 );
require __DIR__ . '/stubs.php';                       // WC()/wc_* stubs used by the other unit tests

// class-cart-writer.php only guards on ABSPATH (no DB_CHAT_TEST fallback,
// unlike the other includes here), because it is normally exercised by the
// server/integration tests under tests/server/. summary() needs the real
// class (not a re-implemented stub) to prove it actually consumes
// DB_Chat_Cart_Writer::contents(), so satisfy that guard for this pure-unit
// run — nothing else about a real WP install is assumed.
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../../includes/class-cart-writer.php';
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

// summary() reuses DB_Chat_Cart_Writer::contents() and adds shipping/address reads.
$sum = $w->summary();
t_ok(array_key_exists('address_line', $sum) && is_string($sum['address_line']), 'summary has address_line string');
t_ok(array_key_exists('total', $sum) && is_string($sum['total']), 'summary total is a string');
t_ok(array_key_exists('shipping', $sum) && is_string($sum['shipping']), 'summary shipping is a string');
t_ok(array_key_exists('items', $sum) && is_array($sum['items']), 'summary items is an array');
t_ok(array_key_exists('cart_url', $sum) && is_string($sum['cart_url']), 'summary cart_url is a string');

// shipping_country_served() — unknown/unconfigured country is never served.
$srv = $w->shipping_country_served('ZZ');
t_ok(isset($srv['served']) && $srv['served'] === false, 'unknown country not served');
t_ok(isset($srv['zone']) && $srv['zone'] === '', 'unknown country has no zone name');

// blank/garbage input short-circuits before any zone lookup.
$blank = $w->shipping_country_served('');
t_ok($blank['served'] === false, 'blank country not served');

t_done();
