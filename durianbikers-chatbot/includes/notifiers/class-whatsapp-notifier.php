<?php
/**
 * WhatsApp order notifier — Meta WhatsApp Cloud API.
 *
 * INERT UNTIL FULLY CONFIGURED. is_enabled() returns false unless the toggle is
 * on AND all three credentials (token, phone number id, recipient) are present.
 * Nothing is ever sent while the WhatsApp Business API setup is incomplete.
 *
 * IMPORTANT DELIVERY CAVEAT (surfaced in the settings UI too):
 * Meta only permits free-form text messages inside the 24-hour customer service
 * window, i.e. within 24 hours of the recipient last messaging the business
 * number. Outside that window a plain text send is rejected (error 131047) and
 * an approved template message must be used instead. Order notifications are
 * business-initiated and will usually fall OUTSIDE the window, so treat this
 * channel as best-effort until an approved template is wired up.
 *
 * @package durianbikers-chatbot
 */

defined( 'ABSPATH' ) || exit;

class DB_Chat_WhatsApp_Notifier extends DB_Chat_Abstract_Notifier {

	/** Cloud API text body limit is 4096 chars; leave headroom. */
	const MAX_LENGTH = 3900;

	/** Pinned Graph API version — bump deliberately, not automatically. */
	const API_VERSION = 'v21.0';

	public function get_id(): string {
		return 'whatsapp';
	}

	public function get_label(): string {
		return __( 'WhatsApp (Meta Cloud API)', 'durianbikers-chatbot' );
	}

	/**
	 * All three credentials are mandatory. Partial config = channel stays off.
	 */
	public function is_enabled(): bool {
		return $this->toggled_on() && $this->is_configured();
	}

	/**
	 * Credentials complete? Exposed for the settings screen so it can explain
	 * exactly why the channel is still inert.
	 */
	public function is_configured(): bool {
		return '' !== $this->get_token()
			&& '' !== $this->get_phone_id()
			&& '' !== $this->get_recipient();
	}

	/**
	 * Which credentials are still missing. Used by the settings notice.
	 *
	 * @return string[] Human-readable field labels.
	 */
	public function get_missing_credentials(): array {
		$missing = array();

		if ( '' === $this->get_token() ) {
			$missing[] = __( 'Access token', 'durianbikers-chatbot' );
		}
		if ( '' === $this->get_phone_id() ) {
			$missing[] = __( 'Phone number ID', 'durianbikers-chatbot' );
		}
		if ( '' === $this->get_recipient() ) {
			$missing[] = __( 'Recipient number', 'durianbikers-chatbot' );
		}

		return $missing;
	}

	public function send( string $message ): bool {
		if ( ! $this->is_configured() ) {
			return false;
		}

		$token    = $this->get_token();
		$phone_id = $this->get_phone_id();

		$this->secrets = array( $token );

		$url = sprintf(
			'https://graph.facebook.com/%s/%s/messages',
			self::API_VERSION,
			rawurlencode( $phone_id )
		);

		return $this->post_non_blocking(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json; charset=utf-8',
				),
				'body'    => wp_json_encode(
					array(
						'messaging_product' => 'whatsapp',
						'recipient_type'    => 'individual',
						'to'                => $this->get_recipient(),
						'type'              => 'text',
						'text'              => array(
							'preview_url' => false,
							'body'        => $this->truncate( $message, self::MAX_LENGTH ),
						),
					)
				),
			)
		);
	}

	private function get_token(): string {
		return $this->opt( 'db_chat_whatsapp_token' );
	}

	/**
	 * Phone number ID is numeric — strip anything else defensively.
	 */
	private function get_phone_id(): string {
		return preg_replace( '/[^0-9]/', '', $this->opt( 'db_chat_whatsapp_phone_id' ) );
	}

	/**
	 * Recipient in E.164 without the leading '+', e.g. 60123456789 for Malaysia.
	 */
	private function get_recipient(): string {
		return preg_replace( '/[^0-9]/', '', $this->opt( 'db_chat_whatsapp_to' ) );
	}
}
