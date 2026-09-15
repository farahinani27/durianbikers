<?php
/**
 * WP-admin "Chatbot Inbox": list handed-off conversations, read the transcript,
 * take one, reply into the widget, resolve. Staff-only (manage_woocommerce).
 *
 * Consumes DB_Chat_Conversation_Store (includes/class-conversation-store.php).
 * Gated end-to-end (menu + REST routes) behind db_chat_handoff_enabled, which
 * defaults off — inert unless the toggle is on and the feature is wired into
 * the plugin bootstrap.
 *
 * @package durianbikers-chatbot
 */

defined( 'ABSPATH' ) || exit;

final class DB_Chat_Inbox_Admin {

	const CAP = 'manage_woocommerce';
	const NS  = 'durianbikers/v1';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	private function enabled() {
		return '1' === get_option( 'db_chat_handoff_enabled', '0' );
	}

	public function menu() {
		if ( ! $this->enabled() ) {
			return;
		}
		add_menu_page( 'Chatbot Inbox', 'Chatbot Inbox', self::CAP, 'db-chat-inbox', array( $this, 'render_page' ), 'dashicons-format-chat', 58 );
	}

	public function assets( $hook ) {
		if ( 'toplevel_page_db-chat-inbox' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'db-chat-inbox', DB_CHAT_URL . 'assets/inbox.css', array(), (string) filemtime( DB_CHAT_PATH . 'assets/inbox.css' ) );
		wp_enqueue_script( 'db-chat-inbox', DB_CHAT_URL . 'assets/inbox.js', array(), (string) filemtime( DB_CHAT_PATH . 'assets/inbox.js' ), true );

		wp_localize_script(
			'db-chat-inbox',
			'dbChatInbox',
			array(
				'root'  => esc_url_raw( rest_url( self::NS . '/inbox' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	public function render_page() {
		echo '<div class="wrap"><h1>Chatbot Inbox</h1><div id="db-chat-inbox-app">Loading…</div></div>';
	}

	public function routes() {
		$perm = function () {
			return current_user_can( self::CAP );
		};

		register_rest_route(
			self::NS,
			'/inbox/list',
			array(
				'methods'             => 'GET',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'r_list' ),
			)
		);
		register_rest_route(
			self::NS,
			'/inbox/conversation',
			array(
				'methods'             => 'GET',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'r_get' ),
			)
		);
		register_rest_route(
			self::NS,
			'/inbox/take',
			array(
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'r_take' ),
			)
		);
		register_rest_route(
			self::NS,
			'/inbox/reply',
			array(
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'r_reply' ),
			)
		);
		register_rest_route(
			self::NS,
			'/inbox/resolve',
			array(
				'methods'             => 'POST',
				'permission_callback' => $perm,
				'callback'            => array( $this, 'r_resolve' ),
			)
		);
	}

	public function r_list( WP_REST_Request $req ) {
		$status = (string) $req->get_param( 'status' );
		$rows   = DB_Chat_Conversation_Store::list_conversations( in_array( $status, array( 'waiting', 'human', 'bot', 'resolved' ), true ) ? $status : '' );
		return rest_ensure_response( array( 'conversations' => $rows ) );
	}

	public function r_get( WP_REST_Request $req ) {
		$id = (int) $req->get_param( 'id' );
		$c  = DB_Chat_Conversation_Store::get( $id );
		if ( ! $c ) {
			return new WP_Error( 'not_found', 'No such conversation', array( 'status' => 404 ) );
		}
		return rest_ensure_response(
			array(
				'conversation' => $c,
				'messages'     => DB_Chat_Conversation_Store::transcript( $id ),
			)
		);
	}

	public function r_take( WP_REST_Request $req ) {
		$id = (int) $req->get_param( 'id' );
		DB_Chat_Conversation_Store::assign( $id, get_current_user_id() );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function r_reply( WP_REST_Request $req ) {
		$id   = (int) $req->get_param( 'id' );
		$body = trim( (string) $req->get_param( 'body' ) );
		if ( '' === $body ) {
			return new WP_Error( 'empty', 'Empty reply', array( 'status' => 400 ) );
		}
		$c = DB_Chat_Conversation_Store::get( $id );
		if ( ! $c ) {
			return new WP_Error( 'not_found', 'No such conversation', array( 'status' => 404 ) );
		}
		if ( DB_Chat_Conversation_Store::STATUS_HUMAN !== $c['status'] ) {
			DB_Chat_Conversation_Store::assign( $id, get_current_user_id() );
		}
		$mid = DB_Chat_Conversation_Store::add_message( $id, 'agent', $body, get_current_user_id() );
		return rest_ensure_response(
			array(
				'ok' => true,
				'id' => $mid,
			)
		);
	}

	public function r_resolve( WP_REST_Request $req ) {
		$id = (int) $req->get_param( 'id' );
		DB_Chat_Conversation_Store::add_message( $id, 'system', 'Conversation resolved.' );
		DB_Chat_Conversation_Store::set_status( $id, DB_Chat_Conversation_Store::STATUS_RESOLVED );
		return rest_ensure_response( array( 'ok' => true ) );
	}
}
