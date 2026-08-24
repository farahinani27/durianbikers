<?php
/**
 * Order retrieval for the chatbot — the signed-in customer's OWN orders only.
 *
 * SECURITY BOUNDARY — read this whole header before changing anything here.
 *
 * This file deliberately reverses the plugin's original "no code path can reach
 * an order" design. The store owner asked for order retrieval for signed-in
 * customers. The old blanket guarantee is therefore replaced by a narrower one,
 * which this class exists to enforce:
 *
 *   An anonymous visitor can reach NO personal data whatsoever.
 *   A signed-in customer can reach ONLY their own orders.
 *   No user, signed in or not, can ever reach another person's data.
 *
 * How that is enforced, in order of importance:
 *
 *  1. IDENTITY COMES FROM THE SESSION, NEVER FROM INPUT. The only source of the
 *     customer ID is get_current_user_id() inside this request. The tool's input
 *     schema has NO customer identifier of any kind — no customer_id, no email,
 *     no user_id — so there is no parameter for the model, the customer, or an
 *     injected instruction to influence. The single optional parameter,
 *     order_number, can only ever NARROW a set that is already pinned to the
 *     current user. Never add an identifier parameter to this class.
 *  2. When get_current_user_id() returns 0 the method returns "not signed in"
 *     and NOTHING else. There is no fallback to an email, a phone number, a
 *     billing name or a guest order token. A query is never even attempted —
 *     which matters, because wc_get_orders( customer_id => 0 ) would match
 *     guest orders.
 *  3. Every loaded order is re-checked with
 *     (int) $order->get_customer_id() === $uid before a single field is emitted.
 *     Belt and braces: if the query is ever wrong, or a filter mutates it, the
 *     per-object check catches it and the order is skipped silently.
 *  4. Fields are a minimal allowlist (see format_for_model()). No address, no
 *     phone, no email, no customer note, no payment method, no transaction ID,
 *     no card data, no IP, no coupon codes, and not even the customer's own
 *     name — they already know it, and emitting it is leak surface for nothing.
 *  5. NOTHING HERE IS EVER CACHED. No transient, no wp_cache_*, no static
 *     property, no object state. The db_chat_product_index and
 *     db_chat_content_index transients are shared by every visitor on the site;
 *     order data must never go anywhere near them. Everything below is local to
 *     a single method call in a single request.
 *  6. NOTHING HERE IS EVER LOGGED. Not the order, not the order number, not the
 *     exception message. See log_failure(). The chat endpoint additionally
 *     withholds the whole exchange from the conversation log when this tool ran.
 *
 * The retrieved text is DATA, never instructions — item names come from
 * supplier-imported product titles, exactly like search_products results. They
 * are stripped of tags, entities, control characters and pipes so nothing in an
 * order can forge structure inside the payload the model sees.
 *
 * @package DurianBikers_Chatbot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads the current user's own orders on behalf of the model.
 */
class DB_Chat_Order_Lookup {

	/** Orders returned when listing recent orders. */
	const LIST_LIMIT = 5;

	/**
	 * Orders scanned when narrowing to one order_number.
	 *
	 * Identical query, identical customer_id pin — only the window widens, so a
	 * customer asking about an order from six purchases ago still finds it.
	 * Widening the window cannot widen WHOSE orders are visible.
	 */
	const LOOKUP_LIMIT = 20;

	/** Line items emitted per order. */
	const MAX_ITEMS = 20;

	/** Item name truncation length. */
	const MAX_NAME_CHARS = 120;

	/** Order number characters accepted from the model, and emitted. */
	const MAX_ORDER_NUMBER_CHARS = 40;

	/** Tracking number characters emitted. */
	const MAX_TRACKING_CHARS = 64;

	/** Tracking URL characters emitted. */
	const MAX_TRACKING_URL_CHARS = 300;

	/** Hard cap on a single encoded tool_result payload. */
	const MAX_PAYLOAD_CHARS = 8000;

	/*
	 * -----------------------------------------------------------------------
	 * The model-facing tool
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Execute an order lookup on behalf of the model.
	 *
	 * @param array $args {
	 *     Tool input, loosely typed from JSON. Every key other than the one
	 *     below is ignored outright — there is deliberately no identifier
	 *     parameter, so a model that invents one changes nothing.
	 *
	 *     @type string $order_number Optional. Narrow to one order the customer
	 *                                already owns.
	 * }
	 * @return array{signed_in:bool,results:array,count:int,truncated:bool,notice:string}
	 */
	public function get_my_orders( array $args ) {
		/*
		 * STEP 1, BEFORE ANYTHING ELSE: identity, from the session only.
		 *
		 * $args is never consulted for identity. It cannot be — there is no
		 * identifier key in the schema, and nothing below reads one.
		 */
		$uid = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		if ( $uid < 1 ) {
			/*
			 * Anonymous visitor. Return the fact and nothing else. No query is
			 * run: wc_get_orders( customer_id => 0 ) would match guest orders,
			 * which belong to people we cannot identify from a chat widget.
			 */
			return $this->not_signed_in();
		}

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $this->unavailable();
		}

		// The ONLY input this tool reads, and it can only ever narrow.
		$order_number = $this->sanitize_order_number( isset( $args['order_number'] ) ? $args['order_number'] : '' );

		try {
			$orders = $this->fetch_orders( $uid, '' === $order_number ? self::LIST_LIMIT : self::LOOKUP_LIMIT );
		} catch ( Throwable $e ) {
			$this->log_failure( $e );

			return $this->unavailable();
		}

		$results = array();

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				// Excludes WC_Order_Refund, which extends WC_Abstract_Order.
				continue;
			}

			/*
			 * OWNERSHIP RE-CHECK. The query was already pinned to this customer;
			 * this asserts it again on the loaded object. If wc_get_orders() is
			 * ever wrong — a filter, a data-store quirk, a future refactor — the
			 * order is skipped here and never reaches the model.
			 */
			if ( (int) $order->get_customer_id() !== $uid ) {
				continue;
			}

			if ( '' !== $order_number && ! $this->number_matches( $order, $order_number ) ) {
				continue;
			}

			try {
				$results[] = $this->format_for_model( $order );
			} catch ( Throwable $e ) {
				$this->log_failure( $e );
				continue;
			}

			if ( '' !== $order_number ) {
				// A number identifies exactly one order; stop at the first hit.
				break;
			}

			if ( count( $results ) >= self::LIST_LIMIT ) {
				break;
			}
		}

		if ( empty( $results ) ) {
			return '' !== $order_number ? $this->number_not_found() : $this->no_orders();
		}

		return $this->enforce_payload_cap(
			$results,
			'These are this signed-in customer\'s own orders, read from our records just now. Report only what is here. Do not estimate a delivery date, and do not add a status the order does not have.'
		);
	}

	/**
	 * Run the ownership-pinned order query.
	 *
	 * The customer_id argument is the whole security model of the query, so it
	 * is written here once and nowhere else, from a value that came from
	 * get_current_user_id(). No caller supplies it.
	 *
	 * @param int $uid   Current user ID, already verified as > 0.
	 * @param int $limit Result window.
	 * @return array Orders as returned by WooCommerce.
	 */
	private function fetch_orders( $uid, $limit ) {
		$orders = wc_get_orders(
			array(
				'customer_id' => (int) $uid,
				'limit'       => (int) $limit,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		return is_array( $orders ) ? $orders : array();
	}

	/**
	 * Does this order carry the number the customer asked about?
	 *
	 * Compared loosely (case and punctuation insensitive) because customers
	 * write "#DB-1042" for order 1042. The comparison happens only AFTER the
	 * ownership check, against a set that is already restricted to this
	 * customer, so a guessed foreign number simply never matches anything and
	 * the caller returns "not found".
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $number Sanitized number from the model.
	 * @return bool
	 */
	private function number_matches( WC_Order $order, $number ) {
		$candidate = $this->normalize_number( (string) $order->get_order_number() );
		$wanted    = $this->normalize_number( $number );

		if ( '' === $candidate || '' === $wanted ) {
			return false;
		}

		if ( $candidate === $wanted ) {
			return true;
		}

		// The raw ID, for stores where get_order_number() is filtered to a prefix.
		return $this->normalize_number( (string) $order->get_id() ) === $wanted;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Formatting — the field allowlist
	 * -----------------------------------------------------------------------
	 */

	/**
	 * The ONLY order fields the model is ever shown.
	 *
	 * Allowed: order number, order date, human status label, item names and
	 * quantities, formatted total, and a tracking number/URL when one already
	 * exists in order meta.
	 *
	 * Forbidden, permanently: billing address, shipping address, phone, email,
	 * customer name, customer note, payment method or title, transaction ID,
	 * card data, IP address, coupon codes, refund detail, order key, admin URL,
	 * and anything belonging to any other customer. Do not add a field here
	 * because it "seems harmless" — every field is leak surface, and the
	 * customer already knows their own address.
	 *
	 * @param WC_Order $order Order, already ownership-checked by the caller.
	 * @return array<string,mixed>
	 */
	private function format_for_model( WC_Order $order ) {
		$record = array(
			'order_number' => $this->plain_text( (string) $order->get_order_number(), self::MAX_ORDER_NUMBER_CHARS ),
			'status'       => $this->get_status_label( $order ),
			'items'        => $this->get_items( $order ),
			'total'        => $this->get_total_text( $order ),
		);

		$date = $this->get_date_text( $order );

		if ( '' !== $date ) {
			$record['order_date'] = $date;
		}

		$tracking = $this->get_tracking( $order );

		if ( ! empty( $tracking ) ) {
			$record = array_merge( $record, $tracking );
		}

		return $record;
	}

	/**
	 * Human-readable status label, e.g. "Processing".
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function get_status_label( WC_Order $order ) {
		$status = (string) $order->get_status();

		if ( '' === $status ) {
			return '';
		}

		if ( function_exists( 'wc_get_order_status_name' ) ) {
			$label = (string) wc_get_order_status_name( $status );
		} else {
			$label = $status;
		}

		return $this->plain_text( $label, 60 );
	}

	/**
	 * Order date as a readable string.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function get_date_text( WC_Order $order ) {
		$date = $order->get_date_created();

		if ( ! $date || ! function_exists( 'wc_format_datetime' ) ) {
			return '';
		}

		return $this->plain_text( (string) wc_format_datetime( $date ), 40 );
	}

	/**
	 * Order total, formatted and stripped to plain text.
	 *
	 * wc_price() rather than get_formatted_order_total(): the latter can carry
	 * refund markup, and a strikethrough total the model half-reads is worse
	 * than a plain number.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function get_total_text( WC_Order $order ) {
		if ( ! function_exists( 'wc_price' ) ) {
			return '';
		}

		$total = wc_price(
			(float) $order->get_total(),
			array( 'currency' => (string) $order->get_currency() )
		);

		return $this->plain_text( (string) $total, 40 );
	}

	/**
	 * Line item names and quantities.
	 *
	 * get_items() is asked for 'line_item' only and each item is additionally
	 * required to be a WC_Order_Item_Product, so fee lines, shipping lines, tax
	 * lines and — importantly — coupon lines are structurally excluded rather
	 * than filtered out afterwards.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int,array{name:string,quantity:int}>
	 */
	private function get_items( WC_Order $order ) {
		$items = array();

		foreach ( (array) $order->get_items( 'line_item' ) as $item ) {
			if ( count( $items ) >= self::MAX_ITEMS ) {
				break;
			}

			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$name = $this->plain_text( (string) $item->get_name(), self::MAX_NAME_CHARS );

			if ( '' === $name ) {
				continue;
			}

			$items[] = array(
				'name'     => $name,
				'quantity' => max( 0, (int) $item->get_quantity() ),
			);
		}

		return $items;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Tracking
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Meta keys that may hold a tracking number or tracking URL.
	 *
	 * An ALLOWLIST, never a scan of the order's meta. Different courier plugins
	 * store this differently, so the common keys are covered; anything else has
	 * to be added deliberately through the filter.
	 *
	 * @return array{numbers:string[],urls:string[],items:string[]}
	 */
	private function tracking_meta_keys() {
		$keys = array(
			'numbers' => array(
				'_tracking_number',
				'_wc_shipment_tracking_number',
				'_shipment_tracking_number',
				'_dhl_tracking_number',
				'_aftership_tracking_number',
				'_wcst_order_trackno',
				'tracking_number',
			),
			'urls'    => array(
				'_tracking_url',
				'_custom_tracking_link',
				'tracking_url',
			),
			'items'   => array(
				'_wc_shipment_tracking_items',
			),
		);

		/**
		 * Filter the order meta keys searched for a tracking number or URL.
		 *
		 * Only tracking data may be added here. Keys that look like personal
		 * data are rejected outright regardless of what this filter returns —
		 * see is_safe_meta_key().
		 *
		 * @param array $keys Keys grouped as numbers / urls / items.
		 */
		$keys = (array) apply_filters( 'db_chat_order_tracking_meta_keys', $keys );

		foreach ( array( 'numbers', 'urls', 'items' ) as $group ) {
			$candidates = isset( $keys[ $group ] ) && is_array( $keys[ $group ] ) ? $keys[ $group ] : array();
			$clean      = array();

			foreach ( $candidates as $key ) {
				if ( is_scalar( $key ) && $this->is_safe_meta_key( (string) $key ) ) {
					$clean[] = (string) $key;
				}
			}

			$keys[ $group ] = $clean;
		}

		return array(
			'numbers' => $keys['numbers'],
			'urls'    => $keys['urls'],
			'items'   => $keys['items'],
		);
	}

	/**
	 * Refuse any meta key that could plausibly hold personal data.
	 *
	 * The filter above is a convenience for a courier plugin we have not seen.
	 * It is not a way to widen the field allowlist, so a key that smells of
	 * billing, shipping, contact or payment data is dropped even if someone
	 * adds it deliberately.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	private function is_safe_meta_key( $key ) {
		$key = strtolower( trim( $key ) );

		if ( '' === $key || strlen( $key ) > 64 ) {
			return false;
		}

		$forbidden = array(
			'billing',
			'shipping',
			'customer',
			'email',
			'phone',
			'address',
			'name',
			'transaction',
			'payment',
			'card',
			'token',
			'_ip',
			'ip_address',
			'user_agent',
			'coupon',
			'note',
			'password',
			'key',
		);

		foreach ( $forbidden as $needle ) {
			if ( false !== strpos( $key, $needle ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Tracking number and/or URL, when one already exists on the order.
	 *
	 * Nothing is generated, guessed or built from a provider name — if the shop
	 * has not recorded tracking, the model is told nothing and must not imply
	 * that a parcel has moved.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string,string> Possibly empty.
	 */
	private function get_tracking( WC_Order $order ) {
		$keys = $this->tracking_meta_keys();
		$out  = array();

		foreach ( $keys['items'] as $key ) {
			$value = $order->get_meta( $key, true );

			if ( ! is_array( $value ) ) {
				continue;
			}

			foreach ( $value as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				$number = isset( $entry['tracking_number'] ) && is_scalar( $entry['tracking_number'] )
					? $this->sanitize_tracking_number( (string) $entry['tracking_number'] )
					: '';

				$url = isset( $entry['custom_tracking_link'] ) && is_scalar( $entry['custom_tracking_link'] )
					? $this->sanitize_tracking_url( (string) $entry['custom_tracking_link'] )
					: '';

				if ( '' !== $number && ! isset( $out['tracking_number'] ) ) {
					$out['tracking_number'] = $number;
				}

				if ( '' !== $url && ! isset( $out['tracking_url'] ) ) {
					$out['tracking_url'] = $url;
				}
			}
		}

		if ( ! isset( $out['tracking_number'] ) ) {
			foreach ( $keys['numbers'] as $key ) {
				$value = $order->get_meta( $key, true );

				if ( ! is_scalar( $value ) ) {
					continue;
				}

				$number = $this->sanitize_tracking_number( (string) $value );

				if ( '' !== $number ) {
					$out['tracking_number'] = $number;
					break;
				}
			}
		}

		if ( ! isset( $out['tracking_url'] ) ) {
			foreach ( $keys['urls'] as $key ) {
				$value = $order->get_meta( $key, true );

				if ( ! is_scalar( $value ) ) {
					continue;
				}

				$url = $this->sanitize_tracking_url( (string) $value );

				if ( '' !== $url ) {
					$out['tracking_url'] = $url;
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * A tracking number is alphanumerics, dashes and underscores. Nothing else.
	 *
	 * Deliberately brutal: it guarantees that whatever a courier plugin wrote
	 * into that meta key cannot carry prose, punctuation or an instruction into
	 * the payload the model reads.
	 *
	 * @param string $value Raw meta value.
	 * @return string
	 */
	private function sanitize_tracking_number( $value ) {
		$value = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $value );
		$value = is_string( $value ) ? $value : '';

		if ( strlen( $value ) < 4 ) {
			return '';
		}

		return substr( $value, 0, self::MAX_TRACKING_CHARS );
	}

	/**
	 * A tracking URL must be a plain http(s) URL or it is dropped.
	 *
	 * @param string $value Raw meta value.
	 * @return string
	 */
	private function sanitize_tracking_url( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value || strlen( $value ) > self::MAX_TRACKING_URL_CHARS ) {
			return '';
		}

		if ( preg_match( '/\s/', $value ) ) {
			return '';
		}

		$url = esc_url_raw( $value, array( 'http', 'https' ) );

		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		return 0 === strpos( $url, 'http://' ) || 0 === strpos( $url, 'https://' ) ? $url : '';
	}

	/*
	 * -----------------------------------------------------------------------
	 * Input shaping
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Sanitize the one permitted parameter.
	 *
	 * It is a filter over the customer's OWN orders, never a lookup key, so the
	 * worst a hostile value can do is match nothing.
	 *
	 * @param mixed $value Raw tool input.
	 * @return string
	 */
	private function sanitize_order_number( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = preg_replace( '/[^A-Za-z0-9_\-#\/ ]/', '', (string) $value );
		$value = is_string( $value ) ? trim( $value ) : '';

		return substr( $value, 0, self::MAX_ORDER_NUMBER_CHARS );
	}

	/**
	 * Reduce an order number to comparable characters.
	 *
	 * @param string $value Raw number.
	 * @return string
	 */
	private function normalize_number( $value ) {
		$value = strtolower( (string) $value );
		$value = preg_replace( '/[^a-z0-9]+/', '', $value );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * HTML/entity/whitespace normalisation to a single plain line.
	 *
	 * Same treatment product descriptions get: order text is DATA, and nothing
	 * inside it may forge structure in the payload the model sees.
	 *
	 * @param string $text  Raw value.
	 * @param int    $limit Maximum characters.
	 * @return string
	 */
	private function plain_text( $text, $limit ) {
		$text = wp_strip_all_tags( (string) $text, true );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( array( "\xc2\xa0", '|' ), array( ' ', '/' ), $text );

		$stripped = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );

		if ( is_string( $stripped ) ) {
			$text = $stripped;
		}

		$text = preg_replace( '/\s+/u', ' ', (string) $text );
		$text = trim( (string) $text );

		$limit = max( 1, (int) $limit );

		if ( mb_strlen( $text ) > $limit ) {
			$text = rtrim( mb_substr( $text, 0, $limit ) );
		}

		return $text;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Results
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Keep the payload under the hard cap by dropping the oldest orders.
	 *
	 * @param array  $results Formatted orders, newest first.
	 * @param string $notice  Guidance for the model.
	 * @return array{signed_in:bool,results:array,count:int,truncated:bool,notice:string}
	 */
	private function enforce_payload_cap( array $results, $notice ) {
		$truncated = false;

		while ( count( $results ) > 1 && $this->payload_size( $results ) > self::MAX_PAYLOAD_CHARS ) {
			array_pop( $results );
			$truncated = true;
		}

		if ( $truncated ) {
			$notice = trim( $notice . ' Older orders were left out to keep this response small.' );
		}

		return array(
			'signed_in' => true,
			'results'   => array_values( $results ),
			'count'     => count( $results ),
			'truncated' => $truncated,
			'notice'    => $notice,
		);
	}

	/**
	 * Encoded size of a result set.
	 *
	 * @param array $results Records.
	 * @return int
	 */
	private function payload_size( array $results ) {
		$encoded = wp_json_encode( $results );

		return is_string( $encoded ) ? strlen( $encoded ) : 0;
	}

	/**
	 * The visitor is not signed in.
	 *
	 * The notice is emphatic about NOT asking for identifying details. A chat
	 * widget that asks a stranger for an order number or an email address is
	 * teaching customers to hand personal data to anything that asks, which is
	 * exactly the phishing habit we refuse to normalise — and it would not help
	 * anyway, because this tool cannot use them.
	 *
	 * @return array{signed_in:bool,results:array,count:int,truncated:bool,notice:string}
	 */
	private function not_signed_in() {
		return array(
			'signed_in' => false,
			'results'   => array(),
			'count'     => 0,
			'truncated' => false,
			'notice'    => 'The customer is NOT signed in to an account on this website, so there is no order information available to you at all. Tell them plainly that they need to sign in to their account on our website to see their order details, and offer to help with products or shop policies meanwhile. Do NOT ask them for an order number, an email address, a phone number, a name or any other personal detail — you cannot look an order up that way, and asking for personal details in a chat window is unsafe for the customer.',
		);
	}

	/**
	 * Signed in, but this account has no orders in the window we read.
	 *
	 * @return array{signed_in:bool,results:array,count:int,truncated:bool,notice:string}
	 */
	private function no_orders() {
		return array(
			'signed_in' => true,
			'results'   => array(),
			'count'     => 0,
			'truncated' => false,
			'notice'    => 'This signed-in customer has no orders on their account. Say so plainly. If they believe they ordered, they may have checked out as a guest or used a different account — suggest they check the email address they ordered with, and offer to pass it to a human. Do not ask them for personal details.',
		);
	}

	/**
	 * A specific order number that this customer does not own.
	 *
	 * Identical wording whether the number belongs to nobody or to somebody
	 * else. The model is never told which, because the difference is itself
	 * information about another person's order.
	 *
	 * @return array{signed_in:bool,results:array,count:int,truncated:bool,notice:string}
	 */
	private function number_not_found() {
		return array(
			'signed_in' => true,
			'results'   => array(),
			'count'     => 0,
			'truncated' => false,
			'notice'    => 'No order with that number is on this signed-in customer\'s account. Tell them you cannot find that order number on their account, and suggest they check the number or sign in with the account they ordered with. Do not speculate about whose order it might be, do not confirm or deny that the number exists, and never ask for extra personal details to "verify" them.',
		);
	}

	/**
	 * WooCommerce or the query is unavailable.
	 *
	 * @return array{signed_in:bool,results:array,count:int,truncated:bool,notice:string}
	 */
	private function unavailable() {
		return array(
			'signed_in' => true,
			'results'   => array(),
			'count'     => 0,
			'truncated' => false,
			'notice'    => 'The order lookup is unavailable right now. Tell the customer you could not check their order at the moment and offer to pass it to a human. Do not guess the status of the order.',
		);
	}

	/**
	 * Log a failure WITHOUT logging anything about the order.
	 *
	 * Only the exception class name is recorded. Exception messages from a data
	 * store can carry query fragments, and an order lookup query contains a
	 * customer ID — so the message is dropped rather than redacted.
	 *
	 * @param Throwable $e Exception.
	 * @return void
	 */
	private function log_failure( Throwable $e ) {
		DB_Chat_Plugin::log_error( 'Order lookup failed (' . get_class( $e ) . '). Detail withheld: it may contain order data.' );
	}
}
