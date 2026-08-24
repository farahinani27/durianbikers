<?php
/**
 * Telegram order notifier.
 *
 * Bot API sendMessage. The bot token lives in the URL path, so the endpoint
 * itself is a secret — it is never logged and never rendered.
 *
 * @package durianbikers-chatbot
 */

defined( 'ABSPATH' ) || exit;

class DB_Chat_Telegram_Notifier extends DB_Chat_Abstract_Notifier {

	/** Bot API hard limit is 4096 chars; leave headroom. */
	const MAX_LENGTH = 3900;

	public function get_id(): string {
		return 'telegram';
	}

	public function get_label(): string {
		return __( 'Telegram', 'durianbikers-chatbot' );
	}

	public function is_enabled(): bool {
		return $this->toggled_on()
			&& '' !== $this->get_token()
			&& '' !== $this->get_chat_id();
	}

	public function send( string $message ): bool {
		$token   = $this->get_token();
		$chat_id = $this->get_chat_id();

		if ( '' === $token || '' === $chat_id ) {
			return false;
		}

		// Register the token so it can never leak into an error_log line.
		$this->secrets = array( $token );

		$url = 'https://api.telegram.org/bot' . rawurlencode( $token ) . '/sendMessage';

		return $this->post_non_blocking(
			$url,
			array(
				'headers' => array(
					'Content-Type' => 'application/json; charset=utf-8',
				),
				'body'    => wp_json_encode(
					array(
						'chat_id'                  => $chat_id,
						// Plain text on purpose: order data contains characters that
						// break Markdown/HTML parse modes and Telegram would reject
						// the whole message.
						'text'                     => $this->truncate( $message, self::MAX_LENGTH ),
						'disable_web_page_preview' => true,
					)
				),
			)
		);
	}

	private function get_token(): string {
		return $this->opt( 'db_chat_telegram_token' );
	}

	private function get_chat_id(): string {
		return $this->opt( 'db_chat_telegram_chat_id' );
	}
}
