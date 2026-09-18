<?php
/**
 * Server-side staged cart change for the propose -> confirm -> commit flow.
 *
 * The AI proposes a cart change in chat; the customer replies yes/no; only
 * then does the endpoint write to the cart. If that flow trusted whatever
 * the client echoed back at confirm time, a tampered request could commit a
 * different product, quantity or price than what was actually proposed and
 * shown to the customer. This class closes that gap: stage() is the only
 * place a proposal is written, keyed per conversation in a short-lived WP
 * transient, and commit-time code must read it back with get() rather than
 * accept proposal details from the client.
 *
 * No PII is stored — conversation_id is already an opaque id the endpoint
 * uses elsewhere, and the proposal itself only carries product/cart fields.
 * Nothing here ever computes or mutates a price; whatever price string is
 * handed to stage() is stored and returned as-is.
 *
 * @package DurianBikers_Chatbot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stages, reads and clears one cart-change proposal per conversation.
 */
class DB_Chat_Cart_Proposal {

	/**
	 * How long a staged proposal stays valid.
	 *
	 * Long enough for the customer to read the confirmation and reply,
	 * short enough that a stale proposal from an abandoned chat can never
	 * be resurrected and committed later.
	 */
	const TTL = 900; // 15 minutes.

	/**
	 * Keys a stored proposal may contain, in addition to the generated
	 * proposal_id. Anything else passed to stage() is dropped.
	 *
	 * @var string[]
	 */
	const ALLOWED_KEYS = array( 'action', 'product_id', 'variation_id', 'quantity', 'name', 'label', 'price' );

	/**
	 * Cart actions a proposal may carry. Anything else is sanitized to ''.
	 *
	 * @var string[]
	 */
	const ALLOWED_ACTIONS = array( 'add', 'update', 'remove' );

	/**
	 * Transient name for a conversation's staged proposal.
	 *
	 * Hashed rather than using the conversation id verbatim so the
	 * transient key never leaks the id itself in a DB dump or debug log.
	 *
	 * @param string $conversation_id Conversation public id (or per-session token).
	 * @return string
	 */
	private static function transient_key( $conversation_id ) {
		return 'db_chat_cart_prop_' . sha1( (string) $conversation_id );
	}

	/**
	 * Stage a cart-change proposal for a conversation, replacing any
	 * previous one for the same conversation.
	 *
	 * @param string             $conversation_id Conversation public id (or per-session token).
	 * @param array<string,mixed> $proposal       Raw proposal; only ALLOWED_KEYS are kept.
	 * @return string The generated proposal_id, also stored inside the proposal.
	 */
	public static function stage( $conversation_id, array $proposal ) {
		$proposal_id = bin2hex( random_bytes( 8 ) );

		$clean = array(
			'proposal_id'  => $proposal_id,
			'action'       => self::sanitize_action( isset( $proposal['action'] ) ? $proposal['action'] : '' ),
			'product_id'   => isset( $proposal['product_id'] ) ? absint( $proposal['product_id'] ) : 0,
			'variation_id' => isset( $proposal['variation_id'] ) ? absint( $proposal['variation_id'] ) : 0,
			'quantity'     => isset( $proposal['quantity'] ) ? absint( $proposal['quantity'] ) : 0,
			'name'         => isset( $proposal['name'] ) ? sanitize_text_field( (string) $proposal['name'] ) : '',
			'label'        => isset( $proposal['label'] ) ? sanitize_text_field( (string) $proposal['label'] ) : '',
			// Stored verbatim (as text) — never recomputed, never mutated.
			'price'        => isset( $proposal['price'] ) ? sanitize_text_field( (string) $proposal['price'] ) : '',
		);

		set_transient( self::transient_key( $conversation_id ), $clean, self::TTL );

		return $proposal_id;
	}

	/**
	 * The staged proposal for a conversation, or null if none/expired.
	 *
	 * @param string $conversation_id Conversation public id (or per-session token).
	 * @return array<string,mixed>|null
	 */
	public static function get( $conversation_id ) {
		$value = get_transient( self::transient_key( $conversation_id ) );

		return is_array( $value ) ? $value : null;
	}

	/**
	 * Clear a conversation's staged proposal, if any.
	 *
	 * @param string $conversation_id Conversation public id (or per-session token).
	 * @return void
	 */
	public static function clear( $conversation_id ) {
		delete_transient( self::transient_key( $conversation_id ) );
	}

	/**
	 * Whitelist an action string, dropping anything unrecognised rather
	 * than trusting it through to a later cart write.
	 *
	 * @param mixed $action Raw action value.
	 * @return string One of ALLOWED_ACTIONS, or '' if not recognised.
	 */
	private static function sanitize_action( $action ) {
		$action = strtolower( trim( (string) $action ) );

		return in_array( $action, self::ALLOWED_ACTIONS, true ) ? $action : '';
	}
}
