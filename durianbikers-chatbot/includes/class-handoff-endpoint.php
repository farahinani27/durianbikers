<?php
/**
 * Public REST routes for the human handoff: raise a handoff, and poll for new
 * agent/system messages. Same nonce discipline as the chat endpoint. Only
 * active when db_chat_handoff_enabled.
 *
 * @package durianbikers-chatbot
 */

defined( 'ABSPATH' ) || exit;

final class DB_Chat_Handoff_Endpoint {

	const NS = 'durianbikers/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	private function enabled() {
		return '1' === get_option( 'db_chat_handoff_enabled', '0' );
	}

	public function register_routes() {
		register_rest_route( self::NS, '/handoff', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_handoff' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/messages', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_messages' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/handoff/cancel', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_cancel' ),
			'permission_callback' => '__return_true',
		) );
	}

	public function handle_handoff( WP_REST_Request $request ) {
		if ( ! $this->enabled() ) {
			return new WP_Error( 'db_chat_handoff_off', 'Handoff is not enabled.', array( 'status' => 404 ) );
		}

		$public_id = (string) $request->get_param( 'conversation_id' );
		$conv      = DB_Chat_Conversation_Store::get_by_public_id( $public_id );
		if ( ! $conv ) {
			return new WP_Error( 'db_chat_bad_conversation', 'Unknown conversation.', array( 'status' => 404 ) );
		}

		// Already with a human/queue — don't re-alert.
		$already = in_array( $conv['status'], array( DB_Chat_Conversation_Store::STATUS_WAITING, DB_Chat_Conversation_Store::STATUS_HUMAN ), true );

		$type  = (string) $request->get_param( 'contact_type' );
		$value = (string) $request->get_param( 'contact_value' );
		if ( in_array( $type, array( 'email', 'whatsapp' ), true ) && '' !== trim( $value ) ) {
			$clean = 'email' === $type ? sanitize_email( $value ) : preg_replace( '/[^0-9+ ]/', '', $value );
			if ( '' !== $clean ) {
				DB_Chat_Conversation_Store::set_contact( (int) $conv['id'], $type, $clean );
			}
		}

		$name  = sanitize_text_field( (string) $request->get_param( 'contact_name' ) );
		$email = sanitize_email( (string) $request->get_param( 'contact_email' ) );
		$phone = preg_replace( '/[^0-9+ ]/', '', (string) $request->get_param( 'contact_phone' ) );

		// Require a valid email on a FRESH handoff only — an already-waiting or
		// human conversation re-calling handoff must stay a no-op, not an
		// error, exactly as it behaved before this field existed.
		if ( ! $already && '' === $email ) {
			return new WP_Error( 'db_chat_email_required', 'A valid email is required.', array( 'status' => 400 ) );
		}

		if ( ! $already ) {
			DB_Chat_Conversation_Store::set_contact_details( (int) $conv['id'], $name, $email, $phone );
			DB_Chat_Conversation_Store::set_status( (int) $conv['id'], DB_Chat_Conversation_Store::STATUS_WAITING );
			DB_Chat_Conversation_Store::add_message( (int) $conv['id'], 'system', 'Conversation handed to a team member.' );

			if ( class_exists( 'DB_Chat_Handoff_Alert' ) ) {
				DB_Chat_Handoff_Alert::send( (int) $conv['id'] );
			}
		}

		$status = $already ? $conv['status'] : DB_Chat_Conversation_Store::STATUS_WAITING;
		return rest_ensure_response( array( 'status' => $status, 'conversation_id' => $conv['public_id'] ) );
	}

	/**
	 * Customer-initiated return to the bot. The mirror of handle_handoff():
	 * a customer who is waiting for / talking to a person can hand themselves
	 * back to the AI assistant with no staff action. Idempotent and never an
	 * error for a conversation that is not currently with a human.
	 */
	public function handle_cancel( WP_REST_Request $request ) {
		if ( ! $this->enabled() ) {
			return new WP_Error( 'db_chat_handoff_off', 'Handoff is not enabled.', array( 'status' => 404 ) );
		}

		$public_id = (string) $request->get_param( 'conversation_id' );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $public_id ) ) {
			return new WP_Error( 'db_chat_bad_conversation', 'Unknown conversation.', array( 'status' => 400 ) );
		}

		$conv = DB_Chat_Conversation_Store::get_by_public_id( $public_id );
		if ( ! $conv ) {
			return new WP_Error( 'db_chat_bad_conversation', 'Unknown conversation.', array( 'status' => 404 ) );
		}

		// Only a conversation currently with the queue/human flips back to the
		// bot; anything else is left exactly as it is.
		$in_human = in_array( $conv['status'], array( DB_Chat_Conversation_Store::STATUS_WAITING, DB_Chat_Conversation_Store::STATUS_HUMAN ), true );
		if ( $in_human ) {
			DB_Chat_Conversation_Store::assign_to_bot( (int) $conv['id'] );
			DB_Chat_Conversation_Store::add_message( (int) $conv['id'], 'system', 'Customer returned to the AI assistant.' );
		}

		$status = $in_human ? DB_Chat_Conversation_Store::STATUS_BOT : $conv['status'];
		return rest_ensure_response( array( 'status' => $status, 'conversation_id' => $conv['public_id'] ) );
	}

	public function handle_messages( WP_REST_Request $request ) {
		if ( ! $this->enabled() ) {
			return new WP_Error( 'db_chat_handoff_off', 'Handoff is not enabled.', array( 'status' => 404 ) );
		}

		$public_id = (string) $request->get_param( 'conversation_id' );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $public_id ) ) {
			return new WP_Error( 'db_chat_bad_conversation', 'Unknown conversation.', array( 'status' => 400 ) );
		}

		$conv = DB_Chat_Conversation_Store::get_by_public_id( $public_id );
		if ( ! $conv ) {
			return new WP_Error( 'db_chat_bad_conversation', 'Unknown conversation.', array( 'status' => 404 ) );
		}
		$since = (int) $request->get_param( 'since' );

		$messages = DB_Chat_Conversation_Store::messages_since( (int) $conv['id'], $since, array( 'agent', 'system' ) );

		$last = $since;
		foreach ( $messages as $m ) {
			$last = max( $last, (int) $m['id'] );
		}

		return rest_ensure_response( array(
			'status'          => $conv['status'],
			'messages'        => $messages,
			'last_message_id' => $last,
		) );
	}
}
