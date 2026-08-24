<?php
/**
 * Email order notifier.
 *
 * wp_mail() is not an outbound HTTP call we control, so it cannot be made
 * non-blocking the way the other channels are. DB_Chat_Order_Notifier therefore
 * dispatches this channel LAST, after the fire-and-forget HTTP channels, so a
 * slow SMTP plugin cannot delay the others. It is still wrapped in the caller's
 * per-channel try/catch and can never break checkout.
 *
 * @package durianbikers-chatbot
 */

defined( 'ABSPATH' ) || exit;

class DB_Chat_Email_Notifier extends DB_Chat_Abstract_Notifier {

	const MAX_LENGTH = 20000;

	public function get_id(): string {
		return 'email';
	}

	public function get_label(): string {
		return __( 'Email', 'durianbikers-chatbot' );
	}

	public function is_enabled(): bool {
		return $this->toggled_on() && '' !== $this->get_recipient();
	}

	public function send( string $message ): bool {
		$to = $this->get_recipient();

		if ( '' === $to ) {
			return false;
		}

		$message = $this->truncate( $message, self::MAX_LENGTH );

		// First line of the notification doubles as the subject.
		$lines   = preg_split( '/\r\n|\r|\n/', $message );
		$subject = is_array( $lines ) && isset( $lines[0] ) ? trim( $lines[0] ) : '';
		if ( '' === $subject ) {
			$subject = __( 'New order', 'durianbikers-chatbot' );
		}
		$subject = $this->truncate( $subject, 150 );

		$sent = wp_mail(
			$to,
			$subject,
			$message,
			array( 'Content-Type: text/plain; charset=UTF-8' )
		);

		if ( ! $sent ) {
			$this->log( 'wp_mail returned false' );
		}

		return (bool) $sent;
	}

	/**
	 * Configured recipient, falling back to the site admin email.
	 */
	private function get_recipient(): string {
		$to = $this->opt( 'db_chat_notify_email' );

		if ( '' === $to ) {
			$to = (string) get_option( 'admin_email', '' );
		}

		return is_email( $to ) ? $to : '';
	}
}
