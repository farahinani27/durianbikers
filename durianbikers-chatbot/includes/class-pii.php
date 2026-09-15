<?php
defined( 'ABSPATH' ) || defined( 'DB_CHAT_TEST' ) || exit;

final class DB_Chat_PII {
	/** Redact card-like sequences: 13–19 digits, optionally grouped by spaces/dashes. */
	public static function scrub( $text ) {
		$text = (string) $text;
		// Grouped (spaces/dashes) 13–19 digit runs: not preceded by digit, not followed by alphanumeric.
		$text = preg_replace( '/(?<![0-9])\d(?:[ -]?\d){12,18}(?![0-9A-Za-z])/', '[redacted]', $text );
		return $text;
	}
}
