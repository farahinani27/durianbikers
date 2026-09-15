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
		// Match "RM 469", "RM469.00", "469 ringgit", "$469".
		if ( preg_match_all( '/(?:RM|\$)\s?\d[\d,]*(?:\.\d{1,2})?|\d[\d,]*(?:\.\d{1,2})?\s?(?:ringgit)/i', (string) $reply, $m ) ) {
			foreach ( $m[0] as $hit ) {
				$v = self::price_value( $hit );
				if ( null === $v ) { continue; }
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
