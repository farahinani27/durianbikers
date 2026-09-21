<?php
defined( 'ABSPATH' ) || defined( 'DB_CHAT_TEST' ) || exit;

final class DB_Chat_Grounding {

	/** Pull every price and product name a tool result surfaced. */
	public static function collect_facts( array $tool_results ) {
		$prices = array();
		$names  = array();
		$walk = function ( $node ) use ( &$walk, &$prices, &$names ) {
			if ( ! is_array( $node ) ) {
				return;
			}
			if ( isset( $node['price'] ) && is_scalar( $node['price'] ) ) {
				$v = self::price_value( (string) $node['price'] );
				if ( null !== $v ) { $prices[] = $v; }
			}
			if ( isset( $node['name'] ) && is_scalar( $node['name'] ) ) {
				$names[] = strtolower( trim( (string) $node['name'] ) );
			}
			foreach ( $node as $child ) {
				if ( is_array( $child ) ) { $walk( $child ); }
			}
		};
		foreach ( $tool_results as $r ) { $walk( $r ); }
		return array( 'prices' => array_values( array_unique( $prices, SORT_NUMERIC ) ), 'names' => $names );
	}

	/** Reject a reply that states a money amount no tool returned. */
	public static function verify( $reply, array $facts ) {
		$prices = isset( $facts['prices'] ) && is_array( $facts['prices'] ) ? $facts['prices'] : array();
		$bad    = array();
		$reply  = (string) $reply;
		// Match "RM 469", "RM469.00", "469 ringgit", "$469".
		if ( preg_match_all( '/(?:RM|\$)\s?\d[\d,]*(?:\.\d{1,2})?|\d[\d,]*(?:\.\d{1,2})?\s?(?:ringgit)/i', $reply, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $match ) {
				$hit = $match[0];
				$pos = (int) $match[1];
				$v   = self::price_value( $hit );
				if ( null === $v ) { continue; }

				// A budget CEILING the customer set ("helmets under RM500",
				// "below RM500", "up to RM500", "bawah RM500") is not a claim
				// that any product costs that amount — it is the limit they
				// gave, which the reply naturally echoes. Do not treat it as an
				// ungrounded price. A genuine product price is never introduced
				// with a ceiling word, so hallucinated prices are still caught.
				$before = substr( $reply, max( 0, $pos - 20 ), min( 20, $pos ) );
				if ( preg_match( '/\b(?:under|below|less than|up to|no more than|maximum|max|bawah|di bawah|kurang\s+dari(?:pada)?)\s*$/i', $before ) ) {
					continue;
				}

				$grounded = false;
				foreach ( $prices as $p ) {
					if ( abs( $p - $v ) < 0.01 ) { $grounded = true; break; }
				}
				if ( ! $grounded ) { $bad[] = trim( $hit ); }
			}
		}
		return array( 'ok' => empty( $bad ), 'bad_prices' => array_values( array_unique( $bad ) ) );
	}

	/** First numeric value in a price-like string, thousands-separator aware. */
	private static function price_value( $text ) {
		if ( ! preg_match( '/([0-9][0-9.,]*)/', (string) $text, $mm ) ) { return null; }
		$n = str_replace( ',', '', $mm[1] );
		$n = rtrim( $n, '.' );
		return is_numeric( $n ) ? (float) $n : null;
	}
}
