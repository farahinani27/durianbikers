<?php
/**
 * Notifier contract + shared base class.
 *
 * Every order-notification channel implements DB_Chat_Notifier. The abstract
 * base exists only to share the non-blocking HTTP call and the secret-redacting
 * logger — a channel is free to implement the interface directly instead.
 *
 * @package durianbikers-chatbot
 */

defined( 'ABSPATH' ) || exit;

interface DB_Chat_Notifier {

	/**
	 * Stable machine id, e.g. 'telegram'. Used in option names and form fields.
	 */
	public function get_id(): string;

	/**
	 * Human label for the settings screen.
	 */
	public function get_label(): string;

	/**
	 * True only when the channel is toggled on AND every credential it needs is present.
	 */
	public function is_enabled(): bool;

	/**
	 * Dispatch a plain-text message.
	 *
	 * NOTE: channels that post over HTTP do so with 'blocking' => false because this
	 * runs inside a live checkout. A true return therefore means "handed to the HTTP
	 * layer without error", not "delivered". Delivery is verified out of band.
	 */
	public function send( string $message ): bool;
}

/**
 * Shared plumbing for notifiers.
 */
abstract class DB_Chat_Abstract_Notifier implements DB_Chat_Notifier {

	/**
	 * Strings that must never reach a log line. Populated by subclasses.
	 *
	 * @var string[]
	 */
	protected array $secrets = array();

	/**
	 * Fire-and-forget POST.
	 *
	 * Hard-locked to blocking=false / timeout=2: this runs on the checkout request
	 * and must not add measurable latency for a paying customer.
	 *
	 * @param string $url  Endpoint.
	 * @param array  $args wp_remote_post args (body/headers). Transport args are overridden.
	 */
	protected function post_non_blocking( string $url, array $args = array() ): bool {
		$args = array_merge(
			array(
				'headers' => array(),
				'body'    => '',
			),
			$args
		);

		// Not overridable by callers. Checkout safety.
		$args['blocking']    = false;
		$args['timeout']     = 2;
		$args['redirection'] = 0;
		$args['httpversion'] = '1.1';
		$args['sslverify']   = true;
		$args['user-agent']  = 'DurianBikersChatbot/1.0';

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->log( 'transport error: ' . $response->get_error_message() );
			return false;
		}

		return true;
	}

	/**
	 * Error log with every known secret stripped out.
	 *
	 * Some transports echo the request URL back in the error string, and the
	 * Telegram endpoint carries the bot token in its path — always redact.
	 */
	protected function log( string $message ): void {
		$safe = $this->redact( $message );
		error_log( sprintf( '[db_chat_notify:%s] %s', $this->get_id(), $safe ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Replace any registered secret with a placeholder.
	 */
	protected function redact( string $message ): string {
		foreach ( $this->secrets as $secret ) {
			if ( is_string( $secret ) && strlen( $secret ) > 3 ) {
				$message = str_replace( $secret, '[redacted]', $message );
			}
		}
		return $message;
	}

	/**
	 * Trim a message to a channel's hard limit without splitting mid-line where avoidable.
	 */
	protected function truncate( string $message, int $limit ): string {
		if ( strlen( $message ) <= $limit ) {
			return $message;
		}
		return substr( $message, 0, max( 0, $limit - 3 ) ) . '...';
	}

	/**
	 * Read a plugin option as a trimmed string.
	 */
	protected function opt( string $option ): string {
		$value = get_option( $option, '' );
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Is the channel's own on/off toggle set?
	 */
	protected function toggled_on(): bool {
		return '1' === $this->opt( 'db_chat_' . $this->get_id() . '_enabled' );
	}
}
