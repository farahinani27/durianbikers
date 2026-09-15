<?php
defined( 'ABSPATH' ) || defined( 'DB_CHAT_TEST' ) || exit;

final class DB_Chat_PII {
	/** Redact card-like sequences: 13–19 digits, optionally grouped by spaces/dashes. */
	public static function scrub( $text ) {
		$text = (string) $text;
		// Grouped (spaces/dashes) 13–19 digit runs: initial digit, then 12–18 (optional separator + digit).
		$text = preg_replace( '/\b\d(?:[ -]?\d){12,18}\b/', '[redacted]', $text );
		return $text;
	}
}
