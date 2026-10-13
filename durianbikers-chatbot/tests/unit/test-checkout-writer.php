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

/*
 * captured_details() / review() — the ONLY readers that return the captured
 * VALUES. They exist so the customer can proof-read what they typed before
 * they pay. Everything below is about two things: that the values come back
 * complete and readable, and that they are shaped for the WIDGET alone.
 * (That they never reach Claude is asserted against the real tool results in
 * tests/unit/test-checkout-tools.php and tests/server/test-checkout-review.php.)
 */
$w->set_details([
    'first_name' => 'Farah', 'last_name' => 'Aziz', 'email' => 'farah@example.test',
    'phone' => '0123456789', 'address_1' => '12 Jalan Test', 'address_2' => 'Unit 4',
    'city' => 'Johor Bahru', 'state' => 'Johor', 'postcode' => '80000', 'country' => 'Malaysia',
]);

$captured = $w->captured_details();
t_ok(array_keys($captured) === DB_Chat_Checkout_Writer::FIELDS, 'captured_details() returns exactly FIELDS, in order');
t_ok($captured['country'] === 'MY', 'the typed country NAME was folded to the ISO code WooCommerce needs');
t_ok($captured['state'] === 'JHR', 'the typed state NAME was folded to the WooCommerce state code');
t_ok($captured['first_name'] === 'Farah' && $captured['email'] === 'farah@example.test', 'captured_details() reads the values back');

$rows = $w->review();
$labels = array_column($rows, 'label');
$values = array_column($rows, 'value');

t_ok($labels === ['Name', 'Address', 'City', 'Postcode', 'State', 'Country', 'Phone', 'Email'],
     'review() rows are in the order a customer reads an address');
t_ok($values[0] === 'Farah Aziz', 'review() joins first + last into one Name row');
t_ok($values[1] === '12 Jalan Test, Unit 4', 'review() joins both address lines into one Address row');
t_ok(in_array('Johor', $values, true), 'review() shows the state NAME, not the stored code — nobody proof-reads "JHR"');
t_ok(in_array('Malaysia', $values, true), 'review() shows the country NAME, not "MY"');

// Nothing blank is shown: an empty field is dropped, not rendered as a label
// with nothing after it.
$w2 = new DB_Chat_Checkout_Writer();
foreach (DB_Chat_Checkout_Writer::FIELDS as $f) {
    foreach (['set_billing_' . $f, 'set_shipping_' . $f] as $setter) {
        WC()->customer->{$setter}('');
    }
}
$w2->set_details(['first_name' => 'Solo', 'email' => 'solo@example.test']);
$sparse = $w2->review();
$sparse_labels = array_column($sparse, 'label');
t_ok($sparse_labels === ['Name', 'Email'], 'review() drops every field the customer did not fill in');
foreach ($sparse as $row) {
    t_ok(trim($row['value']) !== '', 'review() never emits an empty value');
}

// Nothing captured at all -> no rows, so the endpoint attaches no review key
// and the widget renders nothing (rather than an empty card).
foreach (DB_Chat_Checkout_Writer::FIELDS as $f) {
    foreach (['set_billing_' . $f, 'set_shipping_' . $f] as $setter) {
        WC()->customer->{$setter}('');
    }
}
t_ok($w2->review() === [], 'review() is empty when nothing has been captured');

t_done();
