<?php
/**
 * Builds and dispatches the "a customer needs a human" alert to whichever
 * notification channels the owner has enabled. Reuses the order-notifier fan-out.
 *
 * @package durianbikers-chatbot
 */

defined( 'ABSPATH' ) || exit;

final class DB_Chat_Handoff_Alert {

	public static function send( $conversation_id ) {
		if ( '1' !== get_option( 'db_chat_handoff_notify', '0' ) ) {
			return;
		}
		if ( ! class_exists( 'DB_Chat_Order_Notifier' ) ) {
			return;
		}

		$conv = DB_Chat_Conversation_Store::get( (int) $conversation_id );
		if ( ! $conv ) {
			return;
		}

		$transcript = DB_Chat_Conversation_Store::transcript( (int) $conversation_id );
		$last_q     = '';
		foreach ( array_reverse( $transcript ) as $m ) {
			if ( 'customer' === $m['sender'] ) {
				$last_q = $m['body'];
				break;
			}
		}

		$contact_lines = array();
		if ( ! empty( $conv['contact_name'] ) ) {
			$contact_lines[] = sprintf( 'Name: %s', $conv['contact_name'] );
		}
		if ( ! empty( $conv['contact_email'] ) ) {
			$contact_lines[] = sprintf( 'Email: %s', $conv['contact_email'] );
		}
		if ( ! empty( $conv['contact_phone'] ) ) {
			$contact_lines[] = sprintf( 'Phone: %s', $conv['contact_phone'] );
		}

		$contact = '';
		if ( ! empty( $contact_lines ) ) {
			$contact = "\n" . implode( "\n", $contact_lines );
		} elseif ( ! empty( $conv['contact_value'] ) ) {
			// Fallback for the older single-field contact (pre-contact_email).
			$contact = sprintf( "\nContact (%s): %s", $conv['contact_type'], $conv['contact_value'] );
		}

		$link = admin_url( 'admin.php?page=db-chat-inbox&conversation=' . (int) $conversation_id );

		$message = sprintf(
			"🔔 A customer needs a human on Durian Bikers chat.\n\nTheir question: %s%s\n\nOpen the inbox: %s",
			'' !== $last_q ? $last_q : '(no text)',
			$contact,
			$link
		);

		DB_Chat_Order_Notifier::dispatch( $message );
	}
}
