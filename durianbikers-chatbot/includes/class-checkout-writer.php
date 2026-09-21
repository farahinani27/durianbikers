<?php
/**
 * The only place the chatbot writes delivery details into WooCommerce.
 *
 * This is the PII boundary: the chatbot's AI layer collects a customer's
 * name/address/phone/email in conversation, but nothing about that captured
 * text is ever allowed to travel back through a tool result, a log line, or
 * a return value. `set_details()` writes straight into `WC()->customer`
 * (billing and shipping) and reports back only which field NAMES are now
 * populated — never the values. `sanitize_details()` is pure and has no
 * side effects, so it can be unit-tested without a WordPress/WooCommerce
 * environment.
 *
 * @package DurianBikers_Chatbot
 */

defined( 'ABSPATH' ) || defined( 'DB_CHAT_TEST' ) || exit;

/**
 * Sanitises and writes checkout/delivery details for the chatbot.
 */
class DB_Chat_Checkout_Writer {

	/**
	 * Every field this writer knows about, in the shape it stores and returns.
	 *
	 * @var string[]
	 */
	const FIELDS = array( 'first_name', 'last_name', 'email', 'phone', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' );

	/**
	 * WooCommerce-required billing fields for the MY/GB storefronts.
	 *
	 * @var string[]
	 */
	const REQUIRED = array( 'first_name', 'last_name', 'address_1', 'city', 'postcode', 'country', 'phone', 'email' );

	/**
	 * Normalise raw, possibly attacker-controlled input into a fixed field map.
	 *
	 * Pure: no WooCommerce/session access, no output. Unknown keys are
	 * dropped, every value is tag-stripped, trimmed and length-capped, email
	 * is validated (or blanked), and country/state are folded to an
	 * uppercase letters-only code.
	 *
	 * @param array<string,mixed> $raw Raw field map, e.g. from an AI tool call.
	 * @return array<string,string> Map of all self::FIELDS keys to sanitised strings.
	 */
	public static function sanitize_details( array $raw ) {
		$out = array();

		foreach ( self::FIELDS as $f ) {
			$v = isset( $raw[ $f ] ) && is_scalar( $raw[ $f ] ) ? (string) $raw[ $f ] : '';
			$v = trim( wp_strip_all_tags( $v ) );
			$v = mb_substr( $v, 0, 120 );

			if ( 'email' === $f ) {
				$v = is_email( $v ) ? sanitize_email( $v ) : '';
			} elseif ( 'country' === $f || 'state' === $f ) {
				$v = strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', $v ) );
			}

			$out[ $f ] = $v;
		}

		return $out;
	}

	/**
	 * Sanitise and write delivery details into the WooCommerce customer
	 * session (billing and shipping), then save.
	 *
	 * Safety property: the return shape carries only booleans and field
	 * names — never a captured value. Nothing here may be changed to include
	 * a value in the result, in a log line, or in an exception message.
	 *
	 * @param array<string,mixed> $raw Raw field map, e.g. from an AI tool call.
	 * @return array{ok:bool,fields:array<string,bool>}
	 */
	public function set_details( array $raw ) {
		$clean = self::sanitize_details( $raw );

		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return array(
				'ok'     => false,
				'fields' => array_fill_keys( self::FIELDS, false ),
			);
		}

		$c = WC()->customer;

		foreach ( $clean as $f => $v ) {
			if ( '' === $v ) {
				continue;
			}

			$billing  = 'set_billing_' . $f;
			$shipping = 'set_shipping_' . $f;

			if ( is_callable( array( $c, $billing ) ) ) {
				$c->{$billing}( $v );
			}

			// Shipping has no email/phone setters on a stock WC_Customer; guarded by is_callable.
			if ( is_callable( array( $c, $shipping ) ) ) {
				$c->{$shipping}( $v );
			}
		}

		$c->save();

		$fields = array();

		foreach ( self::FIELDS as $f ) {
			$fields[ $f ] = '' !== $clean[ $f ];
		}

		return array(
			'ok'     => true,
			'fields' => $fields,
		);
	}

	/**
	 * Check whether the session already has everything WooCommerce requires
	 * to place an order on the MY/GB storefronts.
	 *
	 * Reads only field names into the result, never the values held.
	 *
	 * @return array{ok:bool,missing:string[]}
	 */
	public function has_required_for_checkout() {
		$missing = array();
		$c       = function_exists( 'WC' ) ? WC()->customer : null;

		foreach ( self::REQUIRED as $f ) {
			$getter = 'get_billing_' . $f;
			$val    = $c && is_callable( array( $c, $getter ) ) ? (string) $c->{$getter}() : '';

			if ( '' === trim( $val ) ) {
				$missing[] = $f;
			}
		}

		return array(
			'ok'      => empty( $missing ),
			'missing' => $missing,
		);
	}
}
