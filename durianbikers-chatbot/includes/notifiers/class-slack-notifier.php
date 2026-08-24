<?php
/**
 * Slack order notifier (incoming webhook).
 *
 * The webhook URL is itself the credential — treat it as a secret.
 *
 * @package durianbikers-chatbot
 */

defined( 'ABSPATH' ) || exit;

class DB_Chat_Slack_Notifier extends DB_Chat_Abstract_Notifier {

	const MAX_LENGTH = 3900;

	public function get_id(): string {
		return 'slack';
	}

	public function get_label(): string {
		return __( 'Slack', 'durianbikers-chatbot' );
	}

	public function is_enabled(): bool {
		return $this->toggled_on() && '' !== $this->get_webhook();
	}

	public function send( string $message ): bool {
		$webhook = $this->get_webhook();

		if ( '' === $webhook ) {
			return false;
		}

		$this->secrets = array( $webhook );

		return $this->post_non_blocking(
			$webhook,
			array(
				'headers' => array(
					'Content-Type' => 'application/json; charset=utf-8',
				),
				'body'    => wp_json_encode(
					array(
						'text' => $this->truncate( $message, self::MAX_LENGTH ),
					)
				),
			)
		);
	}

	private function get_webhook(): string {
		$url = $this->opt( 'db_chat_slack_webhook' );

		// Only ever post to Slack's own webhook host, whatever ends up in the option.
		if ( '' === $url ) {
			return '';
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( 'hooks.slack.com' !== $host ) {
			return '';
		}

		return $url;
	}
}
