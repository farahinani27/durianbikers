<?php
// Run: wp eval-file durianbikers-chatbot/tests/server/test-get-product.php --path=<docroot>
require __DIR__ . '/../lib/assert.php';
$pi = new DB_Chat_Product_Index();
$d = $pi->get_product_detail(['product_id' => 59463]); // HJC Helmet C10 (variable, has sizes)
t_ok(!empty($d['name']), 'has name');
t_ok(is_array($d['variations']) && count($d['variations']) > 0, 'has variations');
$first = $d['variations'][0];
t_ok(isset($first['size'], $first['price'], $first['in_stock']), 'variation whitelisted keys');
t_ok(!isset($d['sku']) && !isset($first['sku']), 'no sku/raw fields leaked');
t_done();
