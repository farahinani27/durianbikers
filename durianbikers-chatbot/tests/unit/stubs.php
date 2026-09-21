<?php
// Test doubles + minimal WP_Error/is_wp_error for pure orchestrator tests.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { public $code; public function __construct( $c = '', $m = '' ) { $this->code = $c; } }
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }

class FakeClient {
	private $key; private $replies; private $tool_results; private $escalation; private $tool_results_seq;
	public function __construct( $has_key, array $replies = array(), array $tool_results = array(), $escalation = '', array $tool_results_seq = array() ) {
		$this->key = (bool) $has_key; $this->replies = $replies; $this->tool_results = $tool_results;
		$this->escalation = (string) $escalation; $this->tool_results_seq = $tool_results_seq;
	}
	public function has_api_key() { return $this->key; }
	public function chat( $messages, $context, $executor, $pages = '', $model_override = '' ) {
		// If a per-call tool-results sequence was given, advance it in step with replies.
		if ( ! empty( $this->tool_results_seq ) ) { $this->tool_results = array_shift( $this->tool_results_seq ); }
		return empty( $this->replies ) ? '' : array_shift( $this->replies );
	}
	public function last_tool_results() { return $this->tool_results; }
	public function get_last_usage() { return array(); }
	public function escalation_model() { return $this->escalation; }
}
class FakeLookup {
	private $reply; public function __construct( $reply ) { $this->reply = $reply; }
	public function answer( $m, $h = array() ) { return array( 'reply' => $this->reply, 'products' => array(), 'pages' => array() ); }
	public function last_wants_human() { return false; }
}
class FakeBudget {
	private $ok; public function __construct( $ok ) { $this->ok = (bool) $ok; }
	public function can_spend() { return $this->ok; }
	public function record( $usage ) {}
	public function get_last_usage() { return array(); }
}
class FakeIndex { public function match_in_text( $reply ) { return array(); } }

// --- Minimal stubs for DB_Chat_Checkout_Writer's WooCommerce surface ---
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $t ) { return is_string( $t ) ? trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $t ) ) ) : ''; } }
if ( ! function_exists( 'is_email' ) )          { function is_email( $t ) { return is_string( $t ) && (bool) filter_var( $t, FILTER_VALIDATE_EMAIL ); } }
if ( ! function_exists( 'sanitize_email' ) )    { function sanitize_email( $t ) { return is_string( $t ) ? trim( $t ) : ''; } }

if ( ! class_exists( 'FakeWCCustomer' ) ) {
	class FakeWCCustomer {
		private $data = array();
		public function save() {}
		public function __call( $name, $args ) {
			if ( strpos( $name, 'set_' ) === 0 ) {
				$this->data[ substr( $name, 4 ) ] = $args[0] ?? '';
				return null;
			}
			if ( strpos( $name, 'get_' ) === 0 ) {
				$key = substr( $name, 4 );
				return $this->data[ $key ] ?? '';
			}
			return null;
		}
	}
}

// --- Minimal stubs for DB_Chat_Checkout_Writer::summary()/shipping_country_served() ---
// Deep real-WooCommerce cart totals/shipping-zone calc is exercised by the
// server smoke test (Task 9), not here — these only need to prove the
// method SHAPES and the unknown-country=false path.
if ( ! function_exists( 'wc_get_cart_url' ) ) { function wc_get_cart_url() { return 'https://example.test/cart/'; } }

if ( ! class_exists( 'FakeWCCart' ) ) {
	class FakeWCCart {
		public function calculate_shipping() {}
		public function calculate_totals() {}
		public function get_cart_shipping_total() { return 'RM10.00'; }
		public function get_cart() { return array(); }
		public function get_cart_contents_count() { return 0; }
		public function get_cart_total() { return 'RM0.00'; }
		public function get_product_subtotal( $product, $qty ) { return ''; }
	}
}

if ( ! class_exists( 'FakeWCShippingZone' ) ) {
	class FakeWCShippingZone {
		public function get_shipping_methods( $enabled_only = false ) { return array(); }
		public function get_zone_name() { return ''; }
	}
}

if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
	class WC_Shipping_Zones {
		// Stub: no configured zone ever matches in unit tests, so every
		// country reads as "not served" — enough to prove shipping_country_served()'s
		// unknown-country path without a real zone table.
		public static function get_zone_matching_package( $package ) {
			return new FakeWCShippingZone();
		}
	}
}

// --- Minimal stand-in for WP_REST_Request, just enough for get_param() ---
// (no such class exists outside a real WP install; the unit tests call
// private methods that only ever call $request->get_param(...)).
if ( ! class_exists( 'FakeWPRequest' ) ) {
	class FakeWPRequest {
		private $params;
		public function __construct( array $params = array() ) {
			$this->params = $params;
		}
		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}
	}
}

if ( ! function_exists( 'WC' ) ) {
	class FakeWC {
		public $customer;
		public $cart;
		public function __construct() {
			$this->customer = new FakeWCCustomer();
			$this->cart     = new FakeWCCart();
		}
	}
	function WC() {
		static $wc;
		if ( null === $wc ) { $wc = new FakeWC(); }
		return $wc;
	}
}
