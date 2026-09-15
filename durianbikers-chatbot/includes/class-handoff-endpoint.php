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

		if ( ! $already ) {
			DB_Chat_Conversation_Store::set_status( (int) $conv['id'], DB_Chat_Conversation_Store::STATUS_WAITING );
			DB_Chat_Conversation_Store::add_message( (int) $conv['id'], 'system', 'Conversation handed to a team member.' );

			if ( class_exists( 'DB_Chat_Handoff_Alert' ) ) {
				DB_Chat_Handoff_Alert::send( (int) $conv['id'] );
			}
		}

		$status = $already ? $conv['status'] : DB_Chat_Conversation_Store::STATUS_WAITING;
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
