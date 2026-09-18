<?php
/**
 * DB_Chat_Confirm — affirmative / refusal detection for the cart-commit gate.
 *
 * Pure PHP, no WordPress, no I/O, no network. Deterministic.
 *
 * Word lists and the refusal-FIRST ordering are ported from
 * includes/class-lookup-engine.php (confirm_terms(), decline_terms(),
 * chinese_terms(), and handle_confirmation()'s "decline before confirm"
 * logic around line 4361). That ordering is the load-bearing safety
 * property: Malay negates with a particle rather than a different word
 * ("tak nak" contains the confirm word "nak"), and Chinese "不要" ("don't
 * want") contains the confirm character "要" ("want"). Checking
 * confirmation first would read a refusal as a yes and put something in a
 * customer's cart, so refusal is always checked first, in every language.
 *
 * @package DurianBikersChatbot
 */

defined( 'ABSPATH' ) || defined( 'DB_CHAT_TEST' ) || exit;

final class DB_Chat_Confirm {

	/**
	 * English / Malay words that reject a confirmation, ported from
	 * class-lookup-engine.php decline_terms(). "tak" alone (not just "tak
	 * nak"/"tak mau") is enough, because it is checked as a whole word
	 * before the confirm words below are — the same particle-negation trap
	 * the lookup engine's comment documents at line ~4384.
	 *
	 * @return string[]
	 */
	private static function decline_terms() {
		return array(
			'no', 'nope', 'nah', 'cancel', 'stop', 'nevermind', 'dont', "don't",
			'tidak', 'tak', 'jangan', 'batal', 'belum', 'usah',
		);
	}

	/**
	 * English / Malay words that accept a confirmation, ported from
	 * class-lookup-engine.php confirm_terms(), plus "ahead" ("go ahead") —
	 * not in the lookup engine's list, but specific enough (no overlap with
	 * any decline term) that it does not weaken refusal-first.
	 *
	 * @return string[]
	 */
	private static function confirm_terms() {
		return array(
			'yes', 'yeah', 'yep', 'yup', 'ok', 'okay', 'confirm', 'confirmed', 'sure', 'add', 'proceed',
			'ya', 'yer', 'betul', 'boleh', 'setuju', 'sah', 'teruskan', 'nak', 'ahead',
		);
	}

	/**
	 * Chinese decline substrings, ported from class-lookup-engine.php
	 * chinese_terms()['decline']. Longest-first so a longer phrase is not
	 * shadowed by a shorter one it contains, though role order (decline
	 * checked in full before confirm) is what actually stops the 不要/要
	 * trap.
	 *
	 * @return string[]
	 */
	private static function chinese_decline_terms() {
		return array( '不要了', '不用了', '先不要', '不要', '不用', '不了', '取消', '算了', '等等', '不' );
	}

	/**
	 * Chinese confirm substrings, ported from class-lookup-engine.php
	 * chinese_terms()['confirm'].
	 *
	 * @return string[]
	 */
	private static function chinese_confirm_terms() {
		return array( '确认', '確認', '好的', '可以', '没错', '沒錯', '对', '對', '是', '好', '要' );
	}

	/**
	 * Does this text contain Han characters? Ported from
	 * class-lookup-engine.php is_chinese().
	 *
	 * @param string $text Any text.
	 * @return bool
	 */
	private static function is_chinese( $text ) {
		return 1 === preg_match( '/\p{Han}/u', (string) $text );
	}

	/**
	 * Lowercase, trim, and split into whole-word tokens (letters only, plus
	 * the apostrophe so "don't" stays one token). Punctuation and digits
	 * become separators, same intent as class-lookup-engine.php normalize().
	 *
	 * @param string $text Raw message.
	 * @return string[]
	 */
	private static function tokenize( $text ) {
		$t      = mb_strtolower( trim( (string) $text ) );
		$tokens = preg_split( '/[^\p{L}\']+/u', $t, -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $tokens ) ? $tokens : array();
	}

	/**
	 * True when the message declines / refuses. Checked FIRST by
	 * is_affirmative() and by any caller that also wants a "yes" reading —
	 * this is the safety gate. Never weaken this ordering.
	 *
	 * @param string $text Customer's reply.
	 * @return bool
	 */
	public static function is_refusal( $text ) {
		$t = trim( (string) $text );

		if ( '' === $t ) {
			return false;
		}

		if ( self::is_chinese( $t ) ) {
			foreach ( self::chinese_decline_terms() as $term ) {
				if ( false !== mb_strpos( $t, $term ) ) {
					return true;
				}
			}

			return false;
		}

		$tokens = self::tokenize( $t );

		foreach ( self::decline_terms() as $term ) {
			if ( in_array( $term, $tokens, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * True when the message clearly agrees AND is not also a refusal.
	 * Refusal is checked first (via is_refusal()) so a trap like "tak nak"
	 * (contains the confirm word "nak") or "不要" (contains the confirm
	 * character "要") is never read as a yes. Anything ambiguous — no
	 * recognised word in either list — returns false.
	 *
	 * @param string $text Customer's reply.
	 * @return bool
	 */
	public static function is_affirmative( $text ) {
		$t = trim( (string) $text );

		if ( '' === $t || self::is_refusal( $t ) ) {
			return false;
		}

		if ( self::is_chinese( $t ) ) {
			foreach ( self::chinese_confirm_terms() as $term ) {
				if ( false !== mb_strpos( $t, $term ) ) {
					return true;
				}
			}

			return false;
		}

		$tokens = self::tokenize( $t );

		foreach ( self::confirm_terms() as $term ) {
			if ( in_array( $term, $tokens, true ) ) {
				return true;
			}
		}

		return false;
	}
}
