<?php
/**
 * Regression test: a no-argument tool_use echoed back into the messages array
 * must serialise as `{}` (object), not `[]` — otherwise the Anthropic API
 * rejects the whole request with 400 tool_use.input: Input should be an object,
 * dropping the customer to the keyword fallback.
 */

define( 'DB_CHAT_TEST', 1 );
require __DIR__ . '/../lib/assert.php';
require __DIR__ . '/../lib/wp-shims.php';
require __DIR__ . '/../../includes/class-claude-client.php';

$client = new DB_Chat_Claude_Client();
$method = new ReflectionMethod( 'DB_Chat_Claude_Client', 'normalize_tool_use_input' );
$method->setAccessible( true );

$content = array(
	array( 'type' => 'text', 'text' => 'Let me check that.' ),
	array( 'type' => 'tool_use', 'id' => 't1', 'name' => 'get_checkout_summary', 'input' => array() ),          // no-arg → must become {}
	array( 'type' => 'tool_use', 'id' => 't2', 'name' => 'search_products', 'input' => array( 'query' => 'x' ) ), // has args → unchanged
);

$out  = $method->invoke( $client, $content );
$json = json_encode( $out ); // wp_json_encode is a thin json_encode wrapper; same output for this shape.

t_ok( is_object( $out[1]['input'] ), 'empty tool_use input coerced to object' );
t_ok( strpos( $json, '"input":{}' ) !== false, 'empty tool_use input serialises as {}' );
t_ok( strpos( $json, '"input":[]' ) === false, 'no tool_use input serialises as []' );
t_ok( strpos( $json, '"query":"x"' ) !== false, 'non-empty input preserved as object' );
t_ok( $out[0]['type'] === 'text' && $out[0]['text'] === 'Let me check that.', 'text block untouched' );
t_ok( is_array( $out[2]['input'] ) === false || ! empty( $out[2]['input'] ), 'non-empty input not blanked' );

t_done();
