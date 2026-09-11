<?php
/**
 * Product retrieval for the chatbot.
 *
 * SECURITY BOUNDARY — read this before changing anything in this file.
 *
 * This class is the ONLY thing in the plugin that reads store data on behalf of
 * the chatbot, and it is structurally incapable of reaching private data:
 *
 *   - It queries products and product taxonomies. Nothing else.
 *   - Every query is pinned to status=publish and catalog visibility=visible.
 *   - There is no call to wc_get_orders(), WP_User_Query, get_users(),
 *     get_userdata(), or $wpdb anywhere in this file. Do not add one.
 *   - stock_quantity is never read and never emitted. In / out of stock only.
 *   - No SKUs, cost, supplier or internal notes are emitted.
 *
 * The catalogue is ~5,283 published products, so there is no full-catalogue
 * dump. The model is given a small static taxonomy context (category and brand
 * names) and must call the search_products tool to see any actual product.
 *
 * @package DurianBikers_Chatbot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the static taxonomy context and executes product searches.
 */
class DB_Chat_Product_Index {

	/** Transient holding the cached taxonomy context. */
	const TRANSIENT = 'db_chat_product_index';

	/** Cache lifetime for the taxonomy context. */
	const CACHE_TTL = HOUR_IN_SECONDS;

	/** Results returned per search. */
	const SEARCH_LIMIT = 12;

	/** Hard safety cap on a single encoded tool_result payload. */
	const MAX_PAYLOAD_CHARS = 40000;

	/** Description truncation length. */
	const DESCRIPTION_CHARS = 200;

	/** Maximum product cards returned to the browser. */
	const MAX_CARDS = 4;

	/**
	 * Products surfaced to the model during this request.
	 *
	 * Keyed by normalized product name => product ID. Used to turn the names
	 * the model wrote back into real product data. Request-scoped only.
	 *
	 * @var array<string,int>
	 */
	private $seen = array();

	/**
	 * Resolved brand taxonomy slug, or empty string if none. Lazily detected.
	 *
	 * @var string|null
	 */
	private $brand_taxonomy = null;

	/**
	 * Register cache busting.
	 */
	public function __construct() {
		add_action( 'save_post_product', array( $this, 'flush_cache' ) );
		add_action( 'woocommerce_product_set_stock', array( $this, 'flush_cache' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'flush_cache' ) );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'flush_cache' ) );

		// Taxonomy context changes when categories or brands change.
		add_action( 'created_term', array( $this, 'maybe_flush_for_term' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'maybe_flush_for_term' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'maybe_flush_for_term' ), 10, 3 );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Static context
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Compact plain-text context injected into the system prompt.
	 *
	 * Deliberately small: category names and brand names only, so the model can
	 * pick sensible filter values for the search tool. No products.
	 *
	 * @return string
	 */
	public function get_context() {
		$taxonomies = $this->get_taxonomy_terms();

		$lines = array();

		if ( ! empty( $taxonomies['categories'] ) ) {
			$lines[] = 'PRODUCT CATEGORIES (' . count( $taxonomies['categories'] ) . '): ' . implode( ', ', $taxonomies['categories'] );
		}

		if ( ! empty( $taxonomies['brands'] ) ) {
			$lines[] = 'BRANDS (' . count( $taxonomies['brands'] ) . '): ' . implode( ', ', $taxonomies['brands'] );
		}

		if ( empty( $lines ) ) {
			return '';
		}

		return implode( "\n\n", $lines );
	}

	/**
	 * Cached category and brand term names.
	 *
	 * @return array{categories:string[],brands:string[]}
	 */
	public function get_taxonomy_terms() {
		$cached = get_transient( self::TRANSIENT );

		if ( is_array( $cached ) && isset( $cached['categories'], $cached['brands'] ) ) {
			return $cached;
		}

		$data = array(
			'categories' => $this->get_term_names( 'product_cat' ),
			'brands'     => $this->get_term_names( $this->get_brand_taxonomy() ),
		);

		set_transient( self::TRANSIENT, $data, self::CACHE_TTL );

		return $data;
	}

	/**
	 * Term names for a taxonomy, empty array when the taxonomy is absent.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return string[]
	 */
	private function get_term_names( $taxonomy ) {
		if ( '' === (string) $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'fields'     => 'names',
				'number'     => 200,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		// "Uncategorized" is noise for the model.
		$terms = array_filter(
			array_map( 'wp_strip_all_tags', array_map( 'strval', $terms ) ),
			static function ( $name ) {
				$name = trim( $name );
				return '' !== $name && 'uncategorized' !== strtolower( $name );
			}
		);

		return array_values( $terms );
	}

	/**
	 * Detect the brand taxonomy.
	 *
	 * product_brand is native WooCommerce (merged into core in 9.4) and is what
	 * this store uses; the rest are fallbacks for other installs.
	 *
	 * @return string Taxonomy slug, or empty string when none exists.
	 */
	public function get_brand_taxonomy() {
		if ( null !== $this->brand_taxonomy ) {
			return $this->brand_taxonomy;
		}

		$candidates = array( 'product_brand', 'pwb-brand', 'yith_product_brand', 'berocket_brand', 'pa_brand' );

		/**
		 * Filter the brand taxonomy candidates, highest priority first.
		 *
		 * @param string[] $candidates Taxonomy slugs.
		 */
		$candidates = (array) apply_filters( 'db_chat_brand_taxonomy', $candidates );

		$this->brand_taxonomy = '';

		foreach ( $candidates as $candidate ) {
			$candidate = (string) $candidate;

			if ( '' !== $candidate && taxonomy_exists( $candidate ) ) {
				$this->brand_taxonomy = $candidate;
				break;
			}
		}

		return $this->brand_taxonomy;
	}

	/**
	 * Drop the cached taxonomy context.
	 *
	 * @return void
	 */
	public function flush_cache() {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Flush only when a product category or brand term changed.
	 *
	 * @param int    $term_id  Term ID (unused).
	 * @param int    $tt_id    Term taxonomy ID (unused).
	 * @param string $taxonomy Taxonomy slug.
	 * @return void
	 */
	public function maybe_flush_for_term( $term_id, $tt_id, $taxonomy = '' ) {
		unset( $term_id, $tt_id );

		$watched = array( 'product_cat', $this->get_brand_taxonomy() );

		if ( in_array( (string) $taxonomy, array_filter( $watched ), true ) ) {
			$this->flush_cache();
		}
	}

	/*
	 * -----------------------------------------------------------------------
	 * Search (the model-facing tool)
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Execute a product search on behalf of the model.
	 *
	 * Runs natively inside WordPress via wc_get_products(). It never makes an
	 * HTTP request and never uses the WooCommerce REST API or an app password.
	 *
	 * @param array $args {
	 *     Tool input, already loosely typed from JSON.
	 *
	 *     @type string $query         Free-text search. Required in practice.
	 *     @type string $category      Optional category name or slug.
	 *     @type string $brand         Optional brand name or slug.
	 *     @type bool   $in_stock_only Optional; restrict to in-stock items.
	 * }
	 * @return array{results:array,count:int,truncated:bool,notice:string}
	 */
	public function search_products( array $args ) {
		$query         = isset( $args['query'] ) && is_scalar( $args['query'] ) ? sanitize_text_field( (string) $args['query'] ) : '';
		$category      = isset( $args['category'] ) && is_scalar( $args['category'] ) ? sanitize_text_field( (string) $args['category'] ) : '';
		$brand         = isset( $args['brand'] ) && is_scalar( $args['brand'] ) ? sanitize_text_field( (string) $args['brand'] ) : '';
		$in_stock_only = ! empty( $args['in_stock_only'] );

		$query = trim( mb_substr( $query, 0, 200 ) );

		$products = $this->run_query( $query, $category, $brand, $in_stock_only );

		/*
		 * WordPress core search is an AND across all words. Motorcycle part
		 * queries are wordy ("shinko 705 tyre 17 inch"), so retry once with the
		 * most distinctive single token before reporting nothing.
		 */
		if ( empty( $products ) && '' !== $query ) {
			$fallback = $this->longest_token( $query );

			if ( '' !== $fallback && $fallback !== $query ) {
				$products = $this->run_query( $fallback, $category, $brand, $in_stock_only );
			}
		}

		// Last resort: drop the filters, keep the query.
		if ( empty( $products ) && ( '' !== $category || '' !== $brand ) ) {
			$products = $this->run_query( $query, '', '', $in_stock_only );
		}

		/*
		 * Re-rank before anything is trimmed. WordPress 'relevance' ordering
		 * treats the query as a bag of words, so a wordy customer message like
		 * "Korea Shinko Tires Tyres Tayar E804 E805 Maxxis" put the product
		 * with almost exactly that title LAST, under three tyres that merely
		 * shared the word "Shinko". The customer sees three wrong tyres first.
		 *
		 * This runs before enforce_payload_cap() deliberately: the cap used to
		 * be able to drop the right product entirely.
		 */
		if ( '' !== $query ) {
			$products = $this->rank_by_title_overlap( $products, $query );
		}

		$include_unavailable = (bool) apply_filters( 'db_chat_include_unavailable', true );

		$results = array();

		foreach ( $products as $product ) {
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			// Defence in depth: re-check visibility on the object itself.
			if ( 'publish' !== $product->get_status() || ! $this->is_publicly_visible( $product ) ) {
				continue;
			}

			$record = $this->format_for_model( $product );

			if ( ! $include_unavailable && isset( $record['availability'] ) && 'unavailable' === $record['availability'] ) {
				continue;
			}

			$results[] = $record;

			// Remember it so we can build a real card if the model cites it.
			$this->remember( $product );
		}

		return $this->enforce_payload_cap( $results );
	}

	/**
	 * Run one wc_get_products() query.
	 *
	 * @param string $query         Search string.
	 * @param string $category      Category name or slug.
	 * @param string $brand         Brand name or slug.
	 * @param bool   $in_stock_only Restrict to in-stock.
	 * @return WC_Product[]
	 */
	private function run_query( $query, $category, $brand, $in_stock_only ) {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$args = array(
			'status'     => 'publish',
			'visibility' => 'visible',
			'limit'      => self::SEARCH_LIMIT,
			'return'     => 'objects',
			'orderby'    => '' !== $query ? 'relevance' : 'title',
			'order'      => 'ASC',
		);

		if ( '' !== $query ) {
			$args['s'] = $query;
		}

		if ( $in_stock_only ) {
			$args['stock_status'] = 'instock';
		}

		$category_slug = $this->resolve_term_slug( 'product_cat', $category );

		if ( '' !== $category_slug ) {
			$args['category'] = array( $category_slug );
		}

		$brand_taxonomy = $this->get_brand_taxonomy();
		$brand_slug     = '' !== $brand_taxonomy ? $this->resolve_term_slug( $brand_taxonomy, $brand ) : '';

		$filter = null;

		if ( '' !== $brand_slug ) {
			/*
			 * wc_get_products() has no first-class brand argument, so inject a
			 * tax_query through the documented data-store filter and remove it
			 * again immediately. try/finally guarantees removal even if the
			 * query throws, so we can never leak the filter into other queries.
			 */
			$filter = static function ( $wp_query_args ) use ( $brand_taxonomy, $brand_slug ) {
				if ( ! isset( $wp_query_args['tax_query'] ) || ! is_array( $wp_query_args['tax_query'] ) ) {
					$wp_query_args['tax_query'] = array();
				}

				$wp_query_args['tax_query'][] = array(
					'taxonomy' => $brand_taxonomy,
					'field'    => 'slug',
					'terms'    => array( $brand_slug ),
					'operator' => 'IN',
				);

				return $wp_query_args;
			};

			add_filter( 'woocommerce_product_data_store_cpt_get_products_query', $filter, 99 );
		}

		try {
			$products = wc_get_products( $args );
		} catch ( Throwable $e ) {
			DB_Chat_Plugin::log_error( 'Product search failed: ' . $e->getMessage() );
			$products = array();
		} finally {
			if ( null !== $filter ) {
				remove_filter( 'woocommerce_product_data_store_cpt_get_products_query', $filter, 99 );
			}
		}

		return is_array( $products ) ? $products : array();
	}

	/**
	 * Turn a model-supplied term name or slug into a real term slug.
	 *
	 * Returns an empty string when the term does not exist, which means the
	 * filter is simply dropped rather than returning zero results.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param string $value    Name or slug supplied by the model.
	 * @return string
	 */
	private function resolve_term_slug( $taxonomy, $value ) {
		$value = trim( (string) $value );

		if ( '' === $value || '' === (string) $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return '';
		}

		foreach ( array( 'slug', 'name' ) as $field ) {
			$term = get_term_by( $field, $value, $taxonomy );

			if ( $term instanceof WP_Term ) {
				return $term->slug;
			}
		}

		// Loose match: "shinko" should find the "Shinko" brand.
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'search'     => $value,
				'number'     => 1,
			)
		);

		if ( ! is_wp_error( $terms ) && ! empty( $terms ) && $terms[0] instanceof WP_Term ) {
			return $terms[0]->slug;
		}

		return '';
	}

	/**
	 * Order results by how much of the customer's wording the title accounts for.
	 *
	 * Two ratios, because either alone ranks badly:
	 *
	 *   - how much of the QUERY the title covers (weighted 0.7) — the main
	 *     signal, but on its own a sprawling title full of unrelated models
	 *     scores as well as a precise one;
	 *   - how much of the TITLE the query accounts for (weighted 0.3) — this
	 *     favours a title that IS largely the customer's words over a long one
	 *     that merely contains them.
	 *
	 * Ties keep WordPress's original order, so this only ever promotes a
	 * clearly better match; it never shuffles equally-good results around.
	 *
	 * Deliberately not fuzzy: typo correction already happens upstream in the
	 * lookup engine, and a second layer of approximate matching here would make
	 * it much harder to explain why a given tyre came first.
	 *
	 * @param WC_Product[] $products Products from the query, in WP's order.
	 * @param string       $query    The customer's search wording.
	 * @return WC_Product[]
	 */
	private function rank_by_title_overlap( array $products, $query ) {
		$query_tokens = $this->tokenise( $query );

		if ( empty( $query_tokens ) || count( $products ) < 2 ) {
			return $products;
		}

		$scored = array();

		foreach ( array_values( $products ) as $position => $product ) {
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$scored[] = array(
				'product'  => $product,
				'position' => $position,
				'score'    => $this->overlap_score( $query_tokens, $this->tokenise( $product->get_name() ) ),
			);
		}

		usort(
			$scored,
			static function ( $a, $b ) {
				$by_score = $b['score'] <=> $a['score'];

				return 0 !== $by_score ? $by_score : ( $a['position'] <=> $b['position'] );
			}
		);

		return array_column( $scored, 'product' );
	}

	/**
	 * Lowercase, de-duplicated word tokens.
	 *
	 * Single characters are dropped as noise; digits are kept, because tyre
	 * sizes and model numbers ("705", "E804", "17") are exactly what makes one
	 * product different from another here.
	 *
	 * @param string $text Any text.
	 * @return string[]
	 */
	private function tokenise( $text ) {
		$tokens = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $tokens ) ) {
			return array();
		}

		return array_values(
			array_unique(
				array_filter(
					$tokens,
					static function ( $token ) {
						return mb_strlen( $token ) > 1;
					}
				)
			)
		);
	}

	/**
	 * Overlap of two token sets, 0.0 to 1.0.
	 *
	 * @param string[] $query_tokens Customer's words.
	 * @param string[] $title_tokens Product title's words.
	 * @return float
	 */
	private function overlap_score( array $query_tokens, array $title_tokens ) {
		if ( empty( $query_tokens ) || empty( $title_tokens ) ) {
			return 0.0;
		}

		$matched = count( array_intersect( $query_tokens, $title_tokens ) );

		if ( 0 === $matched ) {
			return 0.0;
		}

		return ( $matched / count( $query_tokens ) ) * 0.7
			+ ( $matched / count( $title_tokens ) ) * 0.3;
	}

	/**
	 * Longest word in a query, used for the fallback search.
	 *
	 * @param string $query Search string.
	 * @return string
	 */
	private function longest_token( $query ) {
		$tokens = preg_split( '/[^\p{L}\p{N}]+/u', (string) $query, -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $tokens ) || empty( $tokens ) ) {
			return '';
		}

		usort(
			$tokens,
			static function ( $a, $b ) {
				return mb_strlen( $b ) <=> mb_strlen( $a );
			}
		);

		return mb_strlen( $tokens[0] ) >= 3 ? $tokens[0] : '';
	}

	/*
	 * -----------------------------------------------------------------------
	 * Formatting
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Whitelisted product fields for the model.
	 *
	 * NOTE: stock_quantity is deliberately absent and must stay absent. So are
	 * SKU, cost, supplier and any meta.
	 *
	 * @param WC_Product $product Product.
	 * @return array<string,mixed>
	 */
	private function format_for_model( WC_Product $product ) {
		$record = array(
			/*
			 * The post id. Not sensitive — it is in every product URL and in
			 * the public REST API — and the buying flow needs it: a customer
			 * answering "which one?" has to be resolved back to a product, and
			 * url_to_postid() is unreliable on this site because the theme
			 * rewrites every URL under /my and /gb.
			 *
			 * The rest of the note below still stands: stock_quantity, SKU,
			 * cost, supplier and meta stay out.
			 */
			'id'         => (int) $product->get_id(),
			'name'       => wp_strip_all_tags( $product->get_name() ),
			'permalink'  => esc_url_raw( (string) $product->get_permalink() ),
			'categories' => $this->get_term_list( $product, 'product_cat' ),
			'brands'     => $this->get_term_list( $product, $this->get_brand_taxonomy() ),
		);

		$description = $this->get_description( $product );

		if ( '' !== $description ) {
			$record['description'] = $description;
		}

		if ( $this->is_unavailable( $product ) ) {
			/*
			 * ~27% of the catalogue are Shopify import artifacts: variable
			 * products with zero variations, no price and outofstock. We give
			 * the model no price and no stock_status for these at all, so it
			 * has nothing to quote, and flag them explicitly.
			 */
			$record['availability'] = 'unavailable';
			$record['note']         = 'Listed in our catalogue but currently unavailable to order. No price can be quoted. Invite the customer to contact us about it. Do NOT say we do not carry it.';

			return $record;
		}

		$record['stock_status'] = $product->is_in_stock() ? 'instock' : 'outofstock';
		$record['availability'] = $product->is_in_stock() ? 'in_stock' : 'out_of_stock';

		$price = $this->get_price_text( $product );

		if ( '' !== $price ) {
			$record['price'] = $price;
		}

		return $record;
	}

	/**
	 * Is this one of the broken imported products?
	 *
	 * A variable product with no variations cannot be bought or priced.
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	private function is_unavailable( WC_Product $product ) {
		if ( ! $product->is_type( 'variable' ) ) {
			return false;
		}

		$children = $product->get_children();

		return empty( $children );
	}

	/**
	 * Human-readable price string, e.g. "RM 320.00" or a min–max range.
	 *
	 * Parent regular_price is empty on every variable product in this store by
	 * design — the real prices live on the variations — so we go through
	 * get_price_html() first and fall back to the variation price range.
	 *
	 * @param WC_Product $product Product.
	 * @return string Empty string when there is genuinely no price.
	 */
	public function get_price_text( WC_Product $product ) {
		if ( $this->is_unavailable( $product ) ) {
			return '';
		}

		$price = $this->plain_text( (string) $product->get_price_html() );

		if ( '' !== $price ) {
			return $price;
		}

		if ( $product->is_type( 'variable' ) && method_exists( $product, 'get_variation_prices' ) ) {
			$prices = $product->get_variation_prices( true );

			if ( ! empty( $prices['price'] ) && is_array( $prices['price'] ) ) {
				$values = array_filter( array_map( 'floatval', $prices['price'] ) );

				if ( ! empty( $values ) ) {
					$min = min( $values );
					$max = max( $values );

					$text = $min === $max
						? wc_price( $min )
						: wc_price( $min ) . ' – ' . wc_price( $max );

					return $this->plain_text( $text );
				}
			}
		}

		$simple = $product->get_price();

		if ( '' !== $simple && null !== $simple && is_numeric( $simple ) ) {
			return $this->plain_text( wc_price( (float) $simple ) );
		}

		return '';
	}

	/**
	 * Product description, stripped and truncated.
	 *
	 * short_description is 0% populated store-wide, so it is not used at all.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private function get_description( WC_Product $product ) {
		$raw = (string) $product->get_description();

		if ( '' === trim( $raw ) ) {
			return '';
		}

		$text = $this->plain_text( strip_shortcodes( $raw ) );

		if ( '' === $text ) {
			return '';
		}

		if ( mb_strlen( $text ) > self::DESCRIPTION_CHARS ) {
			$text = rtrim( mb_substr( $text, 0, self::DESCRIPTION_CHARS ) ) . '…';
		}

		return $text;
	}

	/**
	 * Term names attached to a product.
	 *
	 * Returns an empty array when the taxonomy is missing or the product has no
	 * terms — e.g. a product with no brand assigned. Never emits a notice.
	 *
	 * @param WC_Product $product  Product.
	 * @param string     $taxonomy Taxonomy slug.
	 * @return string[]
	 */
	private function get_term_list( WC_Product $product, $taxonomy ) {
		if ( '' === (string) $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$terms = wp_get_post_terms( $product->get_id(), $taxonomy, array( 'fields' => 'names' ) );

		if ( is_wp_error( $terms ) || ! is_array( $terms ) || empty( $terms ) ) {
			return array();
		}

		return array_values( array_map( 'wp_strip_all_tags', array_map( 'strval', $terms ) ) );
	}

	/**
	 * HTML/entity/whitespace normalisation to a single plain line.
	 *
	 * Also removes pipes and newlines so nothing in store content can forge
	 * structure inside the payload the model sees.
	 *
	 * @param string $html Raw HTML or text.
	 * @return string
	 */
	private function plain_text( $html ) {
		/*
		 * Drop screen-reader-only elements CONTENT AND ALL, before the tags go.
		 *
		 * WooCommerce appends a hidden accessibility span to a variable
		 * product's price HTML:
		 *
		 *   <span…>RM524.00</span> – <span…>RM845.00</span>
		 *   <span class="screen-reader-text">Price range: RM524.00 through RM845.00</span>
		 *
		 * wp_strip_all_tags() removes the tags but KEEPS every character
		 * between them, so the reply read
		 * "RM524.00 – RM845.00Price range: RM524.00 through RM845.00" —
		 * the same figures twice, run together without even a space. It hit
		 * every variable product, which is most of the catalogue.
		 *
		 * This text exists to be spoken, never read. A chat bubble is not a
		 * screen reader, so it should never appear at all.
		 */
		$html = preg_replace(
			'#<(span|div|p)[^>]*class=(["\'])[^"\']*(?:screen-reader-text|sr-only|visually-hidden)[^"\']*\2[^>]*>.*?</\1>#is',
			' ',
			(string) $html
		);

		$text = wp_strip_all_tags( (string) $html, true );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( array( "\xc2\xa0", '|' ), array( ' ', '/' ), $text );
		$text = preg_replace( '/\s+/u', ' ', $text );

		return trim( (string) $text );
	}

	/**
	 * Catalog visibility check that tolerates unusual product types.
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	private function is_publicly_visible( WC_Product $product ) {
		$post = get_post( $product->get_id() );

		if ( $post instanceof WP_Post && '' !== (string) $post->post_password ) {
			return false;
		}

		$visibility = $product->get_catalog_visibility();

		return in_array( $visibility, array( 'visible', 'catalog', 'search' ), true );
	}

	/**
	 * Keep the payload under the safety cap.
	 *
	 * Drops descriptions first, then trims the result count.
	 *
	 * @param array $results Formatted records.
	 * @return array{results:array,count:int,truncated:bool,notice:string}
	 */
	private function enforce_payload_cap( array $results ) {
		$truncated = false;
		$notice    = '';

		if ( $this->payload_size( $results ) > self::MAX_PAYLOAD_CHARS ) {
			foreach ( $results as $index => $record ) {
				unset( $results[ $index ]['description'] );
			}

			$results   = array_values( $results );
			$truncated = true;
			$notice    = 'Descriptions were omitted to keep this response small.';
		}

		while ( count( $results ) > 1 && $this->payload_size( $results ) > self::MAX_PAYLOAD_CHARS ) {
			array_pop( $results );
			$truncated = true;
			$notice    = 'Some results were omitted to keep this response small. Ask the customer to narrow their request.';
		}

		return array(
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

	/*
	 * -----------------------------------------------------------------------
	 * Resolving model output back to real products
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Record a product the model has seen this request.
	 *
	 * @param WC_Product $product Product.
	 * @return void
	 */
	private function remember( WC_Product $product ) {
		$key = $this->normalize( $product->get_name() );

		if ( '' !== $key && ! isset( $this->seen[ $key ] ) ) {
			$this->seen[ $key ] = $product->get_id();
		}
	}

	/**
	 * Map product names the model wrote back to real product data.
	 *
	 * The model's own URLs, prices and stock claims are never used — every card
	 * is rebuilt from the WC_Product object.
	 *
	 * @param string[] $names Product names.
	 * @return array<int,array{name:string,url:string,price:string,image:string,in_stock:bool}>
	 */
	public function resolve_products( array $names ) {
		$cards = array();
		$used  = array();

		foreach ( $names as $name ) {
			if ( count( $cards ) >= self::MAX_CARDS ) {
				break;
			}

			$key = $this->normalize( (string) $name );

			if ( '' === $key ) {
				continue;
			}

			$product_id = isset( $this->seen[ $key ] ) ? $this->seen[ $key ] : $this->lookup_by_title( (string) $name );

			if ( ! $product_id || isset( $used[ $product_id ] ) ) {
				continue;
			}

			$card = $this->format_card( $product_id );

			if ( null === $card ) {
				continue;
			}

			$used[ $product_id ] = true;
			$cards[]             = $card;
		}

		return $cards;
	}

	/**
	 * Find catalogue product names mentioned in the assistant's reply.
	 *
	 * More reliable than trusting the model to emit a parseable list, and it
	 * can only ever match products the model was actually shown this request.
	 *
	 * @param string $text Assistant reply.
	 * @return array<int,array{name:string,url:string,price:string,image:string,in_stock:bool}>
	 */
	public function match_in_text( $text ) {
		$haystack = $this->normalize( (string) $text );

		if ( '' === $haystack || empty( $this->seen ) ) {
			return array();
		}

		// Pad so a name can only match on whole-word boundaries. normalize()
		// has already turned every separator into a single space.
		$haystack = ' ' . $haystack . ' ';

		$keys = array_keys( $this->seen );

		// Longest first so "Shinko 705 Trail Master" wins over "Shinko 705".
		usort(
			$keys,
			static function ( $a, $b ) {
				return strlen( $b ) <=> strlen( $a );
			}
		);

		$matched = array();

		foreach ( $keys as $key ) {
			if ( count( $matched ) >= self::MAX_CARDS ) {
				break;
			}

			// Very short names would false-positive constantly.
			if ( strlen( $key ) < 6 ) {
				continue;
			}

			if ( false !== strpos( $haystack, ' ' . $key . ' ' ) ) {
				$matched[] = $this->seen[ $key ];
			}
		}

		$cards = array();

		foreach ( $matched as $product_id ) {
			$card = $this->format_card( $product_id );

			if ( null !== $card ) {
				$cards[] = $card;
			}
		}

		return $cards;
	}

	/**
	 * Build the card payload sent to the browser.
	 *
	 * Contract with the frontend: exactly name, url, price, image, in_stock.
	 * price is a formatted MYR string or ''. in_stock is a real boolean.
	 * image may be '' — the widget renders its own placeholder.
	 *
	 * @param int $product_id Product ID.
	 * @return array{name:string,url:string,price:string,image:string,in_stock:bool}|null
	 */
	private function format_card( $product_id ) {
		$product = wc_get_product( (int) $product_id );

		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		// Never surface anything that is not publicly published and visible.
		if ( 'publish' !== $product->get_status() || ! $this->is_publicly_visible( $product ) ) {
			return null;
		}

		$image_id  = (int) $product->get_image_id();
		$image_url = '';

		if ( $image_id > 0 ) {
			// Registered size name, never hardcoded pixel dimensions.
			$src = wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' );

			if ( is_string( $src ) ) {
				$image_url = $src;
			}
		}

		return array(
			'name'     => wp_strip_all_tags( $product->get_name() ),
			'url'      => esc_url_raw( (string) $product->get_permalink() ),
			'price'    => $this->get_price_text( $product ),
			'image'    => '' !== $image_url ? esc_url_raw( $image_url ) : '',
			'in_stock' => ! $this->is_unavailable( $product ) && (bool) $product->is_in_stock(),
		);
	}

	/**
	 * Exact-title lookup for a product the model named but we did not cache.
	 *
	 * @param string $name Product name.
	 * @return int Product ID, or 0.
	 */
	private function lookup_by_title( $name ) {
		$name = trim( wp_strip_all_tags( $name ) );

		if ( '' === $name || ! function_exists( 'wc_get_products' ) ) {
			return 0;
		}

		$products = wc_get_products(
			array(
				'status'     => 'publish',
				'visibility' => 'visible',
				'limit'      => 5,
				'return'     => 'objects',
				's'          => $name,
			)
		);

		if ( ! is_array( $products ) ) {
			return 0;
		}

		$target = $this->normalize( $name );

		foreach ( $products as $product ) {
			if ( $product instanceof WC_Product && $this->normalize( $product->get_name() ) === $target ) {
				return $product->get_id();
			}
		}

		return 0;
	}

	/**
	 * Normalize a string for loose comparison.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function normalize( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9]+/', ' ', $value );
		$value = preg_replace( '/\s+/', ' ', (string) $value );

		return trim( (string) $value );
	}
}
