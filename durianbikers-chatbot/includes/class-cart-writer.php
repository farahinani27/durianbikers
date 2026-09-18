<?php
/**
 * The only place the chatbot writes to a cart.
 *
 * Everything the browser sent is re-read from WooCommerce here. A pending
 * order arrives from the customer's own browser, so its product id, variation
 * id and quantity are CLAIMS, not facts. Nothing in this class may assume
 * otherwise — not because a customer is expected to tamper, but because the
 * alternative is a store that can be made to sell the wrong thing by editing
 * a JSON payload.
 *
 * The store takes real payments, so the rules here are deliberately strict and
 * deliberately boring: if anything does not check out, nothing is added and
 * the customer is told plainly.
 *
 * @package DurianBikers_Chatbot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validates and performs cart writes for the chatbot.
 */
class DB_Chat_Cart_Writer {

	/**
	 * Never add more than this in one go, whatever was asked for.
	 *
	 * A ceiling that is not a stock level: it caps the damage of a misparse
	 * ("2 pcs" read as "200") independently of what the warehouse holds.
	 */
	const MAX_QUANTITY = 20;

	/**
	 * In-stock variations of a variable product.
	 *
	 * Out-of-stock options are never offered — the customer picks from what
	 * they can actually have, rather than choosing a size and then being told
	 * no.
	 *
	 * @param int $product_id Parent product.
	 * @return array<int,array<string,mixed>>
	 */
	public function in_stock_variations( $product_id ) {
		$product = wc_get_product( (int) $product_id );

		if ( ! $product instanceof WC_Product_Variable ) {
			return array();
		}

		$out = array();

		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );

			if ( ! $variation instanceof WC_Product_Variation || ! $variation->is_in_stock() ) {
				continue;
			}

			$out[] = array(
				'variation_id' => (int) $variation->get_id(),
				'label'        => $this->variation_label( $variation ),
				'price'        => $this->price_text( $variation ),
			);
		}

		return $out;
	}

	/**
	 * Human wording for the confirmation summary.
	 *
	 * @param int $product_id   Parent product.
	 * @param int $variation_id Chosen variation, 0 for a simple product.
	 * @return array<string,string>
	 */
	public function describe( $product_id, $variation_id ) {
		$product = wc_get_product( (int) $product_id );

		if ( ! $product instanceof WC_Product ) {
			return array(
				'name'  => '',
				'label' => '',
				'price' => '',
				'url'   => '',
			);
		}

		$label = '';
		$price = $this->price_text( $product );

		if ( (int) $variation_id > 0 ) {
			$variation = wc_get_product( (int) $variation_id );

			if ( $variation instanceof WC_Product_Variation ) {
				$label = $this->variation_label( $variation );
				$price = $this->price_text( $variation );
			}
		}

		return array(
			'name'  => wp_strip_all_tags( (string) $product->get_name() ),
			'label' => $label,
			'price' => $price,
			'url'   => (string) $product->get_permalink(),
		);
	}

	/**
	 * Validate and add. Returns a result array; never throws.
	 *
	 * @param int $product_id   Parent product.
	 * @param int $variation_id Variation, 0 for simple products.
	 * @param int $quantity     How many.
	 * @return array<string,mixed>
	 */
	public function add( $product_id, $variation_id, $quantity ) {
		$product_id   = (int) $product_id;
		$variation_id = (int) $variation_id;
		$quantity     = (int) $quantity;

		$check = $this->validate( $product_id, $variation_id, $quantity );

		if ( is_wp_error( $check ) ) {
			return $this->failure( $check->get_error_code(), $check->get_error_message() );
		}

		if ( ! $this->load_cart() ) {
			return $this->failure( 'db_chat_no_cart', 'Your cart is not available right now.' );
		}

		try {
			$added = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id );
		} catch ( Throwable $e ) {
			DB_Chat_Plugin::log_error( 'Cart add failed: ' . $e->getMessage() );

			return $this->failure( 'db_chat_cart_failed', 'I could not add that to your cart.' );
		}

		if ( ! $added ) {
			return $this->failure( 'db_chat_cart_refused', 'That item could not be added to your cart.' );
		}

		$this->persist_session();

		return array(
			'ok'         => true,
			'code'       => '',
			'message'    => '',
			'cart_url'   => (string) wc_get_cart_url(),
			'cart_total' => $this->plain( (string) WC()->cart->get_cart_total() ),
			'item_count' => (int) WC()->cart->get_cart_contents_count(),
		);
	}

	/**
	 * Read the cart. Whitelisted fields only — no raw meta, no SKU.
	 *
	 * @return array<string,mixed>
	 */
	public function contents() {
		if ( ! $this->load_cart() ) {
			return array(
				'items'    => array(),
				'count'    => 0,
				'total'    => '',
				'cart_url' => (string) ( function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '' ),
			);
		}

		$items = array();

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$line_product = isset( $item['data'] ) && $item['data'] instanceof WC_Product ? $item['data'] : null;

			if ( ! $line_product instanceof WC_Product ) {
				continue;
			}

			$product_id   = (int) ( $item['product_id'] ?? 0 );
			$variation_id = (int) ( $item['variation_id'] ?? 0 );
			$quantity     = (int) ( $item['quantity'] ?? 0 );

			$parent = wc_get_product( $product_id );
			$name   = $parent instanceof WC_Product
				? wp_strip_all_tags( (string) $parent->get_name() )
				: wp_strip_all_tags( (string) $line_product->get_name() );

			$label = ( $variation_id > 0 && $line_product instanceof WC_Product_Variation )
				? $this->variation_label( $line_product )
				: '';

			$items[] = array(
				'key'          => (string) $key,
				'name'         => $name,
				'label'        => $label,
				'qty'          => $quantity,
				'line_price'   => $this->plain( (string) WC()->cart->get_product_subtotal( $line_product, $quantity ) ),
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
			);
		}

		return array(
			'items'    => $items,
			'count'    => (int) WC()->cart->get_cart_contents_count(),
			'total'    => $this->plain( (string) WC()->cart->get_cart_total() ),
			'cart_url' => (string) wc_get_cart_url(),
		);
	}

	/**
	 * Change the quantity of an existing cart line. Never touches price.
	 *
	 * Re-validates the line's product/variation against the NEW quantity
	 * before applying it — an increase that would go out of stock or over
	 * the per-add ceiling is refused exactly as it would be on `add()`. A
	 * quantity of zero removes the line instead of failing.
	 *
	 * @param string $cart_item_key Key from `contents()`/`WC()->cart->get_cart()`.
	 * @param int    $quantity      New quantity; 0 removes.
	 * @return array<string,mixed>
	 */
	public function update( $cart_item_key, $quantity ) {
		$cart_item_key = (string) $cart_item_key;
		$quantity      = (int) $quantity;

		if ( '' === $cart_item_key ) {
			return $this->failure( 'db_chat_no_cart_item', 'I could not find that item in your cart.' );
		}

		if ( ! $this->load_cart() ) {
			return $this->failure( 'db_chat_no_cart', 'Your cart is not available right now.' );
		}

		$cart = WC()->cart->get_cart();

		if ( ! isset( $cart[ $cart_item_key ] ) ) {
			return $this->failure( 'db_chat_no_cart_item', 'I could not find that item in your cart.' );
		}

		if ( $quantity < 0 ) {
			return $this->failure( 'db_chat_bad_quantity', 'That is not a quantity I can set.' );
		}

		if ( 0 === $quantity ) {
			return $this->remove( $cart_item_key );
		}

		$item         = $cart[ $cart_item_key ];
		$product_id   = (int) ( $item['product_id'] ?? 0 );
		$variation_id = (int) ( $item['variation_id'] ?? 0 );

		$check = $this->validate( $product_id, $variation_id, $quantity );

		if ( is_wp_error( $check ) ) {
			return $this->failure( $check->get_error_code(), $check->get_error_message() );
		}

		try {
			$updated = WC()->cart->set_quantity( $cart_item_key, $quantity, true );
		} catch ( Throwable $e ) {
			DB_Chat_Plugin::log_error( 'Cart update failed: ' . $e->getMessage() );

			return $this->failure( 'db_chat_cart_failed', 'I could not update your cart.' );
		}

		if ( ! $updated ) {
			return $this->failure( 'db_chat_cart_refused', 'That change could not be made to your cart.' );
		}

		$this->persist_session();

		return array(
			'ok'         => true,
			'code'       => '',
			'message'    => '',
			'cart_url'   => (string) wc_get_cart_url(),
			'cart_total' => $this->plain( (string) WC()->cart->get_cart_total() ),
			'item_count' => (int) WC()->cart->get_cart_contents_count(),
		);
	}

	/**
	 * Remove a cart line entirely.
	 *
	 * @param string $cart_item_key Key from `contents()`/`WC()->cart->get_cart()`.
	 * @return array<string,mixed>
	 */
	public function remove( $cart_item_key ) {
		$cart_item_key = (string) $cart_item_key;

		if ( '' === $cart_item_key ) {
			return $this->failure( 'db_chat_no_cart_item', 'I could not find that item in your cart.' );
		}

		if ( ! $this->load_cart() ) {
			return $this->failure( 'db_chat_no_cart', 'Your cart is not available right now.' );
		}

		$cart = WC()->cart->get_cart();

		if ( ! isset( $cart[ $cart_item_key ] ) ) {
			return $this->failure( 'db_chat_no_cart_item', 'I could not find that item in your cart.' );
		}

		try {
			$removed = WC()->cart->remove_cart_item( $cart_item_key );
		} catch ( Throwable $e ) {
			DB_Chat_Plugin::log_error( 'Cart remove failed: ' . $e->getMessage() );

			return $this->failure( 'db_chat_cart_failed', 'I could not remove that from your cart.' );
		}

		if ( ! $removed ) {
			return $this->failure( 'db_chat_cart_refused', 'That item could not be removed from your cart.' );
		}

		$this->persist_session();

		return array(
			'ok'         => true,
			'code'       => '',
			'message'    => '',
			'cart_url'   => (string) wc_get_cart_url(),
			'cart_total' => $this->plain( (string) WC()->cart->get_cart_total() ),
			'item_count' => (int) WC()->cart->get_cart_contents_count(),
		);
	}

	/**
	 * Every rule, applied to freshly read WooCommerce data.
	 *
	 * @param int $product_id   Parent product.
	 * @param int $variation_id Variation, 0 for simple.
	 * @param int $quantity     How many.
	 * @return true|WP_Error
	 */
	private function validate( $product_id, $variation_id, $quantity ) {
		if ( $quantity < 1 || $quantity > self::MAX_QUANTITY ) {
			return new WP_Error( 'db_chat_bad_quantity', 'That is not a quantity I can add.' );
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product || 'publish' !== $product->get_status() ) {
			return new WP_Error( 'db_chat_no_product', 'I cannot find that product any more.' );
		}

		if ( ! $product->is_purchasable() ) {
			return new WP_Error( 'db_chat_not_purchasable', 'That product cannot be bought online.' );
		}

		if ( $product instanceof WC_Product_Variable ) {
			return $this->validate_variation( $product, $product_id, $variation_id, $quantity );
		}

		if ( ! $product->is_in_stock() ) {
			return new WP_Error( 'db_chat_out_of_stock', 'That is out of stock.' );
		}

		if ( ! $product->has_enough_stock( $quantity ) ) {
			return new WP_Error( 'db_chat_not_enough_stock', 'We do not have that many in stock.' );
		}

		return true;
	}

	/**
	 * Variation rules for a variable product.
	 *
	 * @param WC_Product_Variable $product      Parent.
	 * @param int                 $product_id   Parent id.
	 * @param int                 $variation_id Claimed variation.
	 * @param int                 $quantity     How many.
	 * @return true|WP_Error
	 */
	private function validate_variation( WC_Product_Variable $product, $product_id, $variation_id, $quantity ) {
		unset( $product );

		if ( $variation_id < 1 ) {
			return new WP_Error( 'db_chat_no_variation', 'I still need to know which option you want.' );
		}

		$variation = wc_get_product( $variation_id );

		if ( ! $variation instanceof WC_Product_Variation ) {
			return new WP_Error( 'db_chat_no_variation', 'That option does not exist.' );
		}

		/*
		 * The variation must belong to THIS product, not merely be a valid
		 * variation somewhere in the catalogue. Without this check, a swapped
		 * id in the payload buys a different product at this product's price.
		 */
		if ( (int) $variation->get_parent_id() !== $product_id ) {
			return new WP_Error( 'db_chat_wrong_variation', 'That option does not belong to that product.' );
		}

		if ( ! $variation->is_in_stock() ) {
			return new WP_Error( 'db_chat_out_of_stock', 'That option is out of stock.' );
		}

		if ( ! $variation->has_enough_stock( $quantity ) ) {
			return new WP_Error( 'db_chat_not_enough_stock', 'We do not have that many in stock.' );
		}

		return true;
	}

	/**
	 * Variation label with the attribute name trimmed off.
	 *
	 * WooCommerce renders "Size: L". In a chat bubble the attribute name is
	 * noise once several are listed, so keep just the value.
	 *
	 * @param WC_Product_Variation $variation Variation.
	 * @return string
	 */
	private function variation_label( WC_Product_Variation $variation ) {
		$label = trim( (string) $variation->get_attribute_summary() );

		if ( '' === $label ) {
			return wp_strip_all_tags( (string) $variation->get_name() );
		}

		return (string) preg_replace( '/^[^:]{1,20}:\s*/u', '', $label );
	}

	/**
	 * Price as one plain line.
	 *
	 * Mirrors the screen-reader-text stripping in DB_Chat_Product_Index: a
	 * variable product's price HTML carries a hidden accessibility span that
	 * repeats the range, and tag-stripping alone would leave it doubled.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return string
	 */
	private function price_text( WC_Product $product ) {
		$html = (string) $product->get_price_html();

		$html = preg_replace(
			'#<(span|div|p)[^>]*class=(["\'])[^"\']*(?:screen-reader-text|sr-only|visually-hidden)[^"\']*\2[^>]*>.*?</\1>#is',
			' ',
			$html
		);

		return $this->plain( $html );
	}

	/**
	 * HTML to one plain line, entities and all.
	 *
	 * Decoding is not optional here. WooCommerce encodes the currency symbol,
	 * so get_cart_total() returns "&#82;&#77;1,198.00" — strip the tags alone
	 * and the customer reads those entities verbatim in the chat bubble.
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	private function plain( $html ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $html, true ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\xc2\xa0", ' ', $text );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Make sure a cart exists in this request.
	 *
	 * A REST request does not boot the cart the way a page load does, so the
	 * cart has to be loaded explicitly before it can be written to.
	 *
	 * @return bool
	 */
	private function load_cart() {
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}

		if ( null === WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		return null !== WC()->cart;
	}

	/**
	 * Emit the WooCommerce customer session cookie for this request.
	 *
	 * A REST request mutates the cart in-process and WooCommerce writes the
	 * cart to its session row on `shutdown`. WooCommerce also tries to emit
	 * the session cookie on `shutdown` — but by then the REST response has
	 * already been sent and `headers_sent()` is true, so a guest's browser
	 * never receives the cookie and the mutated cart never reaches the
	 * front-end cart page or checkout. Emitting the cookie here, during the
	 * REST callback (before the response is serialised), is what carries a
	 * guest cart forward. Logged-in customers are unaffected either way —
	 * their cart also persists through the user-meta persistent cart — and
	 * `wc_setcookie()` no-ops safely if headers have somehow already gone.
	 *
	 * Call only after a successful mutation, never on a read.
	 *
	 * @return void
	 */
	private function persist_session() {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		$session = WC()->session;

		if ( $session && is_callable( array( $session, 'set_customer_session_cookie' ) ) ) {
			$session->set_customer_session_cookie( true );
		}
	}

	/**
	 * Uniform failure shape, so callers never branch on missing keys.
	 *
	 * @param string $code    Error code.
	 * @param string $message Customer-facing text.
	 * @return array<string,mixed>
	 */
	private function failure( $code, $message ) {
		return array(
			'ok'         => false,
			'code'       => (string) $code,
			'message'    => (string) $message,
			'cart_url'   => '',
			'cart_total' => '',
			'item_count' => 0,
		);
	}
}
