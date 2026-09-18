<?php
// Run: wp eval-file durianbikers-chatbot/tests/server/test-cart-proposal.php --path=<docroot>
require __DIR__ . '/../lib/assert.php';

$conversation_id = 'test-conv-' . wp_generate_password( 12, false );
$other_id        = 'test-conv-other-' . wp_generate_password( 12, false );

// Clean slate (in case a previous failed run left something staged).
DB_Chat_Cart_Proposal::clear( $conversation_id );
DB_Chat_Cart_Proposal::clear( $other_id );

t_ok( null === DB_Chat_Cart_Proposal::get( $conversation_id ), 'no proposal staged before stage()' );

// --- stage() then get() returns it with a proposal_id ----------------------
$proposal = array(
	'action'       => 'add',
	'product_id'   => 59463,
	'variation_id' => 12345,
	'quantity'     => 2,
	'name'         => 'HJC Helmet C10',
	'label'        => 'Size M',
	'price'        => 'RM399.00',
);

$proposal_id = DB_Chat_Cart_Proposal::stage( $conversation_id, $proposal );
t_ok( is_string( $proposal_id ) && '' !== $proposal_id, 'stage() returns a non-empty proposal_id' );

$staged = DB_Chat_Cart_Proposal::get( $conversation_id );
t_ok( is_array( $staged ), 'get() returns the staged proposal' );
t_eq( $staged['proposal_id'], $proposal_id, 'staged proposal carries the same proposal_id stage() returned' );
t_eq( $staged['action'], 'add', 'action round-trips' );
t_eq( (int) $staged['product_id'], 59463, 'product_id round-trips as int' );
t_eq( (int) $staged['variation_id'], 12345, 'variation_id round-trips as int' );
t_eq( (int) $staged['quantity'], 2, 'quantity round-trips as int' );
t_eq( $staged['name'], 'HJC Helmet C10', 'name round-trips' );
t_eq( $staged['label'], 'Size M', 'label round-trips' );
t_eq( $staged['price'], 'RM399.00', 'price round-trips unmutated' );

// Only whitelisted keys — nothing extra sneaks into the transient.
$allowed = array( 'proposal_id', 'action', 'product_id', 'variation_id', 'quantity', 'name', 'label', 'price' );
$extra   = array_diff( array_keys( $staged ), $allowed );
t_ok( empty( $extra ), 'staged proposal has only whitelisted keys (extra: ' . implode( ',', $extra ) . ')' );

// --- a second stage() for the same conversation replaces the first ---------
$proposal2 = array(
	'action'       => 'remove',
	'product_id'   => 111,
	'variation_id' => 0,
	'quantity'     => 0,
	'name'         => 'Other Product',
	'label'        => '',
	'price'        => '',
);

$proposal_id2 = DB_Chat_Cart_Proposal::stage( $conversation_id, $proposal2 );
t_ok( $proposal_id2 !== $proposal_id, 'second stage() for the same conversation gets a fresh proposal_id' );

$staged2 = DB_Chat_Cart_Proposal::get( $conversation_id );
t_eq( $staged2['proposal_id'], $proposal_id2, 'get() now returns the replacement proposal' );
t_eq( $staged2['action'], 'remove', 'replacement action round-trips' );
t_eq( (int) $staged2['product_id'], 111, 'replacement product_id round-trips' );

// --- an unrecognised action is sanitized away, not trusted ------------------
DB_Chat_Cart_Proposal::stage( $conversation_id, array( 'action' => 'DROP TABLE', 'product_id' => 1 ) );
$staged3 = DB_Chat_Cart_Proposal::get( $conversation_id );
t_eq( $staged3['action'], '', 'unrecognised action is sanitized to empty, not trusted' );

// --- clear() removes it ------------------------------------------------------
DB_Chat_Cart_Proposal::clear( $conversation_id );
t_ok( null === DB_Chat_Cart_Proposal::get( $conversation_id ), 'get() is null after clear()' );

// --- proposals are namespaced per conversation, not shared ------------------
DB_Chat_Cart_Proposal::stage( $other_id, array( 'action' => 'add', 'product_id' => 2 ) );
DB_Chat_Cart_Proposal::stage( $conversation_id, array( 'action' => 'add', 'product_id' => 3 ) );

$mine  = DB_Chat_Cart_Proposal::get( $conversation_id );
$their = DB_Chat_Cart_Proposal::get( $other_id );
t_eq( (int) $mine['product_id'], 3, 'this conversation only sees its own staged proposal' );
t_eq( (int) $their['product_id'], 2, 'the other conversation only sees its own staged proposal' );

DB_Chat_Cart_Proposal::clear( $conversation_id );
DB_Chat_Cart_Proposal::clear( $other_id );

t_done();
