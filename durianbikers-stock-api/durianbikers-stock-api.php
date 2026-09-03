<?php
/**
 * Plugin Name: Durian Bikers Stock API
 * Description: Read-only, token-authenticated stock lookup for external chat platforms (respond.io). Exposes ONE GET route. Never writes. Never touches orders, customers or payments.
 * Version:     1.0.0
 * Author:      Durian Bikers
 * Requires PHP: 8.0
 *
 * @package durianbikers-stock-api
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read-only stock lookup endpoint.
 *
 * GET /wp-json/durianbikers/v1/stock?q=<search>&limit=<1-5>
 * Header: X-DB-Stock-Token: <token>
 *
 * The token comes from the DB_STOCK_API_TOKEN constant in wp-config.php ONLY.
 * It is never stored in the options table and never appears in this file, so a
 * database dump or a copy of the repo does not leak it. If the constant is not
 * defined the route fails closed with 503 — the endpoint is off by default.
 */
class DB_Stock_API {

	const NAMESPACE_V1 = 'durianbikers/v1';
	const ROUTE        = '/stock';

	/** Header carrying the shared secret. */
	const AUTH_HEADER = 'X-DB-Stock-Token';

	/** Max products returned in one response. Keeps payloads small for chat. */
	const MAX_LIMIT = 5;

	/** Max variations listed per product (sizes/colours). */
	const MAX_VARIATIONS = 12;

	/** Requests allowed per minute, all callers combined. */
	const RATE_LIMIT = 60;

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'check_token' ),
				'args'                => array(
					'q'     => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $value ) {
							$len = strlen( (string) $value );
							return $len > 0 && $len <= 100;
						},
					),
					'limit' => array(
						'required'          => false,
						'type'              => 'integer',
						'default'           => 3,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Timing-safe shared-secret check. Fails closed when unconfigured.
	 */
	public static function check_token( WP_REST_Request $request ) {
		if ( ! defined( 'DB_STOCK_API_TOKEN' ) || '' === (string) DB_STOCK_API_TOKEN ) {
			return new WP_Error(
				'db_stock_not_configured',
				'Stock API is not configured.',
				array( 'status' => 503 )
			);
		}

		$sent = (string) $request->get_header( self::AUTH_HEADER );

		if ( '' === $sent || ! hash_equals( (string) DB_STOCK_API_TOKEN, $sent ) ) {
			return new WP_Error(
				'db_stock_forbidden',
				'Invalid or missing token.',
				array( 'status' => 401 )
			);
		}

		if ( ! self::under_rate_limit() ) {
			return new WP_Error(
				'db_stock_rate_limited',
				'Too many requests.',
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * Coarse per-minute cap. A transient, not a real limiter — enough to stop a
	 * misconfigured integration from hammering the catalogue query.
	 */
	private static function under_rate_limit(): bool {
		$key   = 'db_stock_rl_' . gmdate( 'YmdHi' );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT ) {
			return false;
		}

		set_transient( $key, $count + 1, 120 );

		return true;
	}

	public static function handle( WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return new WP_Error(
				'db_stock_no_woocommerce',
				'WooCommerce is not active.',
				array( 'status' => 503 )
			);
		}

		$query = (string) $request->get_param( 'q' );
		$limit = (int) $request->get_param( 'limit' );
		$limit = max( 1, min( self::MAX_LIMIT, $limit ) );

		$products = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => $limit,
				's'       => $query,
			)
		);

		$results = array();

		foreach ( $products as $product ) {
			$results[] = self::describe( $product );
		}

		return rest_ensure_response(
			array(
				'query'   => $query,
				'count'   => count( $results ),
				'results' => $results,
				// Pre-rendered sentence so a chat platform can send it directly
				// without any JSON path handling on their side.
				'summary' => self::summarise( $query, $results ),
			)
		);
	}

	/**
	 * Whitelisted product facts. Deliberately excludes stock_quantity, cost,
	 * supplier and anything not already public on the storefront.
	 */
	private static function describe( $product ): array {
		$row = array(
			'name'         => $product->get_name(),
			'url'          => $product->get_permalink(),
			'price'        => self::plain_price( $product ),
			'availability' => 'unknown',
			'variations'   => array(),
		);

		// Broken Shopify import artifacts: variable type, zero variations. These
		// are listed but genuinely cannot be bought — never quote a price and
		// never claim we do not carry them.
		if ( $product->is_type( 'variable' ) && empty( $product->get_children() ) ) {
			$row['availability'] = 'unavailable';
			$row['price']        = '';

			return $row;
		}

		$row['availability'] = 'instock' === $product->get_stock_status() ? 'in_stock' : 'out_of_stock';

		if ( $product->is_type( 'variable' ) ) {
			$row['variations']   = self::describe_variations( $product );
			$row['availability'] = self::rollup_availability( $row['variations'] );
		}

		return $row;
	}

	/**
	 * Per-size / per-colour availability. This is what customers actually ask
	 * for ("ada size M?") and a parent stock_status cannot answer it.
	 */
	private static function describe_variations( $product ): array {
		$out = array();

		foreach ( $product->get_children() as $child_id ) {
			if ( count( $out ) >= self::MAX_VARIATIONS ) {
				break;
			}

			$variation = wc_get_product( $child_id );

			if ( ! $variation ) {
				continue;
			}

			$label = trim( (string) $variation->get_attribute_summary() );

			$out[] = array(
				'label'        => '' !== $label ? $label : $variation->get_name(),
				'availability' => 'instock' === $variation->get_stock_status() ? 'in_stock' : 'out_of_stock',
			);
		}

		return $out;
	}

	private static function rollup_availability( array $variations ): string {
		if ( array() === $variations ) {
			return 'unavailable';
		}

		foreach ( $variations as $variation ) {
			if ( 'in_stock' === $variation['availability'] ) {
				return 'in_stock';
			}
		}

		return 'out_of_stock';
	}

	/**
	 * Variable products have no parent price by design — the price lives on the
	 * variations — so use the rendered range rather than get_price().
	 */
	private static function plain_price( $product ): string {
		$html = (string) $product->get_price_html();

		if ( '' === $html ) {
			return '';
		}

		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );

		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Plain-text answer. States facts only — no fitment claims, no
	 * recommendations, no invented part numbers.
	 */
	private static function summarise( string $query, array $results ): string {
		if ( array() === $results ) {
			return sprintf(
				'No product matching "%s" was found in the catalogue. Please pass this to a human to check.',
				$query
			);
		}

		$lines = array();

		foreach ( $results as $row ) {
			if ( 'unavailable' === $row['availability'] ) {
				$lines[] = sprintf( '%s — listed but currently unavailable.', $row['name'] );
				continue;
			}

			$line = $row['name'];

			if ( '' !== $row['price'] ) {
				$line .= ' (' . $row['price'] . ')';
			}

			if ( array() !== $row['variations'] ) {
				$in = array();

				foreach ( $row['variations'] as $variation ) {
					if ( 'in_stock' === $variation['availability'] ) {
						$in[] = $variation['label'];
					}
				}

				$line .= array() !== $in
					? ' — in stock: ' . implode( ', ', $in )
					: ' — all options out of stock.';
			} else {
				$line .= 'in_stock' === $row['availability']
					? ' — in stock.'
					: ' — out of stock.';
			}

			$lines[] = $line;
		}

		return implode( "\n", $lines );
	}
}

DB_Stock_API::init();
