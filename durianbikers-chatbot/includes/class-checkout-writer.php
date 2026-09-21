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

	/**
	 * Everything the WIDGET needs to render a checkout confirmation card.
	 *
	 * Reuses `DB_Chat_Cart_Writer::contents()` for line items/total/cart_url,
	 * then adds a shipping figure from WooCommerce's own totals calculation
	 * and a single human-readable address line. This is a read for the
	 * widget's UI, not for Claude's context — the endpoint keeps it out of
	 * the AI-visible tool results (see the PII boundary note at the top of
	 * this file).
	 *
	 * @return array{items:array,shipping:string,total:string,address_line:string,cart_url:string}
	 */
	public function summary() {
		$cart = ( new DB_Chat_Cart_Writer() )->contents(); // items/count/total/cart_url

		$shipping = '';

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->calculate_shipping();
			WC()->cart->calculate_totals();
			$shipping = wp_strip_all_tags( (string) WC()->cart->get_cart_shipping_total() );
		}

		return array(
			'items'        => isset( $cart['items'] ) ? $cart['items'] : array(),
			'shipping'     => $shipping,
			'total'        => isset( $cart['total'] ) ? (string) $cart['total'] : '',
			'address_line' => $this->address_line(),
			'cart_url'     => isset( $cart['cart_url'] ) ? (string) $cart['cart_url'] : '',
		);
	}

	/**
	 * Build one human-readable address line from `WC()->customer`'s shipping
	 * fields, for the widget to display. Never returns a value elsewhere —
	 * see the PII boundary note at the top of this file.
	 *
	 * @return string
	 */
	private function address_line() {
		$c = function_exists( 'WC' ) ? WC()->customer : null;

		if ( ! $c ) {
			return '';
		}

		$parts = array_filter(
			array(
				trim( $c->get_shipping_first_name() . ' ' . $c->get_shipping_last_name() ),
				$c->get_shipping_address_1(),
				$c->get_shipping_city(),
				$c->get_shipping_postcode(),
				$c->get_shipping_country(),
			)
		);

		return wp_strip_all_tags( implode( ', ', $parts ) );
	}

	/**
	 * Whether at least one enabled shipping method serves the given country.
	 *
	 * @param string $country Two-letter-ish country code, any case/spacing.
	 * @return array{served:bool,zone:string}
	 */
	public function shipping_country_served( $country ) {
		$country = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $country ) );

		if ( '' === $country || ! class_exists( 'WC_Shipping_Zones' ) ) {
			return array(
				'served' => false,
				'zone'   => '',
			);
		}

		$package = array(
			'destination' => array(
				'country'  => $country,
				'state'    => '',
				'postcode' => '',
			),
		);

		$zone    = WC_Shipping_Zones::get_zone_matching_package( $package );
		$methods = $zone ? $zone->get_shipping_methods( true ) : array(); // enabled only

		return array(
			'served' => ! empty( $methods ),
			'zone'   => $zone ? $zone->get_zone_name() : '',
		);
	}
}
