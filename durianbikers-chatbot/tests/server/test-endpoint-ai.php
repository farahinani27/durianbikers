<?php
// Run: wp eval-file tests/server/test-endpoint-ai.php --path=<docroot>
require __DIR__ . '/../lib/assert.php';
// Force ai mode for this check regardless of the stored option.
add_filter( 'db_chat_mode', function () { return 'ai'; } );
$ep = db_chat()->endpoint();
$req = new WP_REST_Request( 'POST', '/durianbikers/v1/chat' );
$req->set_param( 'message', 'do you sell car?' );
$req->set_param( 'history', array() );
$res = $ep->handle_request( $req );
$data = ( $res instanceof WP_REST_Response ) ? $res->get_data() : $res;
t_ok( is_array( $data ) && isset( $data['reply'] ), 'returns a reply' );
t_ok( '' !== trim( (string) $data['reply'] ), 'reply is non-empty' );
t_ok( ! preg_match( '/RM\s?\d/i', (string) $data['reply'] ), 'off-topic reply quotes no RM price' );
t_ok( isset( $data['products'] ) && is_array( $data['products'] ), 'products array present' );
echo "REPLY: " . substr( (string) $data['reply'], 0, 200 ) . "\n";
t_done();
