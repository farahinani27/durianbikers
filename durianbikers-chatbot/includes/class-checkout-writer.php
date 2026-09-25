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
	 * Map a country the customer typed to WooCommerce's 2-letter code.
	 * Accepts an existing code ("MY") or a full name ("Malaysia"/"MALAYSIA");
	 * unknown input is returned unchanged. Input arrives letters-only+uppercase
	 * from sanitize_details(), so names are compared the same way.
	 *
	 * @param string $value Country code or name (letters-only, uppercase).
	 * @return string ISO country code, or the input if it could not be mapped.
	 */
	private static function to_country_code( $value ) {
		$value = strtoupper( trim( (string) $value ) );

		if ( '' === $value || ! function_exists( 'WC' ) || ! WC()->countries ) {
			return $value;
		}

		$countries = WC()->countries->get_countries();

		if ( isset( $countries[ $value ] ) ) {
			return $value; // already a valid code
		}

		foreach ( $countries as $code => $name ) {
			if ( strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', (string) $name ) ) === $value ) {
				return $code;
			}
		}

		return $value; // unknown — leave as given
	}

	/**
	 * Map a state the customer typed to WooCommerce's code for that country
	 * (e.g. Malaysia "Johor" -> "JHR"). Accepts a code or a name; returns the
	 * input unchanged when the country has no state list or nothing matches.
	 *
	 * @param string $country Country code (already resolved).
	 * @param string $value   State code or name (letters-only, uppercase).
	 * @return string State code, or the input if it could not be mapped.
	 */
	private static function to_state_code( $country, $value ) {
		$value = strtoupper( trim( (string) $value ) );

		if ( '' === $value || '' === $country || ! function_exists( 'WC' ) || ! WC()->countries ) {
			return $value;
		}

		$states = WC()->countries->get_states( $country );

		if ( empty( $states ) || isset( $states[ $value ] ) ) {
			return $value; // no state list, or already a code
		}

		foreach ( $states as $code => $name ) {
			if ( strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', (string) $name ) ) === $value ) {
				return $code;
			}
		}

		return $value; // unknown — leave as given
	}

	/**
	 * Return the session-bound WC_Customer, loading the WooCommerce session
	 * first if a REST request has not booted it yet.
	 *
	 * A REST request does not initialise WC()->customer the way a page load
	 * does — it stays null until something calls wc_load_cart(), exactly like
	 * WC()->cart (see DB_Chat_Cart_Writer::load_cart()). Without this, writing
	 * checkout details silently no-ops for guests and the details never reach
	 * the session, so the customer is asked for them again.
	 *
	 * @return WC_Customer|null
	 */
	private function ensure_customer() {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		if ( null === WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		return WC()->customer ? WC()->customer : null;
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

		// Resolve the country/state NAMES to the ISO codes WooCommerce needs.
		// sanitize_details() only upper-cased them, so a customer who typed
		// "Malaysia"/"Johor" left "MALAYSIA"/"JOHOR" — which WC cannot map to a
		// shipping zone, so checkout showed shipping as "Free!" and the order
		// could not be placed. This needs WC's country/state tables, so it lives
		// here (with WC access), not in the pure sanitiser.
		$clean['country'] = self::to_country_code( $clean['country'] );
		$clean['state']   = self::to_state_code( $clean['country'], $clean['state'] );

		$c = $this->ensure_customer();

		if ( ! $c ) {
			return array(
				'ok'     => false,
				'fields' => array_fill_keys( self::FIELDS, false ),
			);
		}

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
		$c       = $this->ensure_customer();

		foreach ( self::REQUIRED as $f ) {
			$getter = 'get_billing_' . $f;
			$val    = $c && is_callable( array( $c, $getter ) ) ? (string) $c->{$getter}() : '';

			if ( '' === trim( $val ) ) {
				$missing[] = $f;
			}
		}

		// State is required only where the country actually HAS states (Malaysia
		// does, and without it WooCommerce won't calculate shipping or accept the
		// order). Countries with no state list must not be blocked on it.
		$country = $c && is_callable( array( $c, 'get_billing_country' ) ) ? (string) $c->get_billing_country() : '';
		if ( '' !== $country && function_exists( 'WC' ) && WC()->countries ) {
			$states = WC()->countries->get_states( $country );
			$state  = is_callable( array( $c, 'get_billing_state' ) ) ? (string) $c->get_billing_state() : '';
			if ( ! empty( $states ) && '' === trim( $state ) ) {
				$missing[] = 'state';
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
		$cart = ( new DB_Chat_Cart_Writer() )->contents(); // items/count/cart_url

		$shipping = '';
		$total    = isset( $cart['total'] ) ? (string) $cart['total'] : '';

		if ( function_exists( 'WC' ) && WC()->cart ) {
			// Calculate BEFORE reading the totals — get_cart_total() otherwise
			// returns a stale (often zero) figure on a fresh REST request.
			WC()->cart->calculate_shipping();
			WC()->cart->calculate_totals();

			$shipping = $this->money( (string) WC()->cart->get_cart_shipping_total() );

			// The GRAND total the customer pays — items + shipping (+ tax).
			// get_cart_total() excludes shipping here; get_total() includes it.
			$grand = $this->money( (string) WC()->cart->get_total() );
			if ( '' !== $grand ) {
				$total = $grand;
			}
		}

		return array(
			'items'        => isset( $cart['items'] ) ? $cart['items'] : array(),
			'shipping'     => $shipping,
			'total'        => $total,
			'address_line' => $this->address_line(),
			'cart_url'     => isset( $cart['cart_url'] ) ? (string) $cart['cart_url'] : '',
		);
	}

	/**
	 * Normalise a WooCommerce price-HTML string to plain text: strip tags,
	 * decode numeric entities (the MYR symbol can arrive as "&#82;&#77;"), and
	 * turn a decoded &nbsp; into a normal space, so the widget (which renders
	 * this as plain text) shows "RM10.00" rather than "&#82;&#77;10.00".
	 *
	 * @param string $html Price HTML from WooCommerce.
	 * @return string Plain "RM…" text.
	 */
	private function money( $html ) {
		$s = html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' );
		return trim( str_replace( "\xC2\xA0", ' ', $s ) );
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
