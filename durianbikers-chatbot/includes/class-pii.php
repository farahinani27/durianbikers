<?php
defined( 'ABSPATH' ) || defined( 'DB_CHAT_TEST' ) || exit;

final class DB_Chat_PII {
	/**
	 * Redact PII from free text before it can reach Claude:
	 * - card-like sequences: 13–19 digits, optionally grouped by spaces/dashes.
	 * - email addresses.
	 * - phone-like runs: 7+ digits total, allowing spaces/dashes/+/parens
	 *   between the digits.
	 * Ordinary shop text (prices, sizes, model codes, years) is left as-is.
	 */
	public static function scrub( $text ) {
		$text = (string) $text;

		// Card numbers: grouped (spaces/dashes) 13–19 digit runs; not preceded
		// by a digit, not followed by alphanumeric.
		$text = preg_replace( '/(?<![0-9])\d(?:[ -]?\d){12,18}(?![0-9A-Za-z])/', '[redacted]', $text );

		// Emails: conservative token@token.token pattern.
		$text = preg_replace( '/\S+@\S+\.\S+/', '[redacted]', $text );

		// Phone-like runs: candidate is a digit run (optionally +-prefixed)
		// that may contain spaces/dashes/parens, never glued to a letter or
		// another digit on either side (so prices, sizes and model codes are
		// never touched). Only redact if it actually contains 7+ digits.
		$text = preg_replace_callback(
			'/(?<![0-9A-Za-z])\+?\d(?:[\d \-()]*\d)?(?![0-9A-Za-z])/',
			static function ( $matches ) {
				$digits = preg_replace( '/\D/', '', $matches[0] );
				return strlen( $digits ) >= 7 ? '[redacted]' : $matches[0];
			},
			$text
		);

		return $text;
	}
}
