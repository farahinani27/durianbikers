<?php
/**
 * Page content retrieval for the chatbot.
 *
 * SECURITY BOUNDARY — read this before changing anything in this file.
 *
 * This class lets the assistant answer non-product questions (shipping,
 * returns, payment, contact, FAQ) from the shop's own help pages. It is
 * structurally incapable of reaching anything else:
 *
 *   - It reads posts of type `page` and NOTHING else. No products, no orders,
 *     no customers, no users, no comments, no meta, no $wpdb.
 *   - It is an ALLOWLIST, never a denylist. A page is invisible to the model
 *     until its slug is explicitly listed in self::default_slugs() or added
 *     through the `db_chat_content_pages` filter. That is deliberate: the
 *     store also has checkout, cart, my-account, wishlist, under-construction,
 *     header-test and several duplicate home pages, none of which may ever
 *     reach the model, and a page added tomorrow must default to invisible.
 *   - post_status must be exactly 'publish' and post_password must be exactly
 *     '' — both checked on the post object itself, not merely via query args.
 *     Draft, pending, private, trashed and password-protected pages are out.
 *
 * The retrieved text is DATA, never instructions. The system prompt says so;
 * this file additionally strips tags, shortcodes and control characters so
 * page content cannot forge structure inside the tool result.
 *
 * @package DurianBikers_Chatbot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds a cached, allowlisted index of help pages and searches it.
 */
class DB_Chat_Content_Index {

	/** Transient holding the cached page index. */
	const TRANSIENT = 'db_chat_content_index';

	/** Cache lifetime for the page index. */
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/** Target size of one content section, in characters. */
	const CHUNK_CHARS = 1200;

	/** Sections returned to the model per search. */
	const MAX_SECTIONS = 4;

	/** Sections from any single page, unless there is nothing else to show. */
	const MAX_SECTIONS_PER_PAGE = 2;

	/** Sections stored per page. Bounds both the transient and the scoring. */
	const MAX_CHUNKS_PER_PAGE = 12;

	/** Hard cap on a single encoded tool_result payload. */
	const MAX_PAYLOAD_CHARS = 12000;

	/** Safety cap on how many slugs the filter may add. */
	const MAX_PAGES = 20;

	/** Maximum page links returned to the browser. */
	const MAX_CARDS = 2;

	/**
	 * Pages surfaced to the model during this request.
	 *
	 * Keyed by normalized page title => post ID, in relevance order. Used to
	 * turn a title the model wrote back into a real, verified link. Nothing is
	 * persisted; this is request-scoped only.
	 *
	 * @var array<string,int>
	 */
	private $seen = array();

	/**
	 * Register cache busting.
	 */
	public function __construct() {
		/*
		 * Cache busting matters more than it looks: policy copy gets corrected
		 * precisely because it was wrong, and a 12 hour TTL would keep serving
		 * the wrong version to customers after the owner fixed it.
		 */

		// Fires for pages only; save_post_{$post_type}.
		add_action( 'save_post_page', array( $this, 'maybe_flush_for_post' ), 10, 2 );

		// Trashing does not fire save_post, and deletion removes the row.
		add_action( 'trashed_post', array( $this, 'maybe_flush_for_post' ) );
		add_action( 'untrashed_post', array( $this, 'maybe_flush_for_post' ) );
		add_action( 'before_delete_post', array( $this, 'maybe_flush_for_post' ), 10, 2 );

		// A scheduled page going live calls wp_publish_post, which does NOT
		// fire save_post. Without this, a scheduled policy update stays invisible.
		add_action( 'transition_post_status', array( $this, 'maybe_flush_for_transition' ), 10, 3 );
	}

	/*
	 * -----------------------------------------------------------------------
	 * The allowlist
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Default allowlisted page slugs.
	 *
	 * @return string[]
	 */
	public static function default_slugs() {
		return array(
			'delivery-information',
			'return-refunds-policy',
			'terms-and-conditions',
			'faq',
			'contact-us',
			'about-us',
		);
	}

	/**
	 * The effective allowlist.
	 *
	 * Every entry is run through sanitize_title(), so the filter cannot inject
	 * a query fragment or a path traversal. Nested paths are supported by
	 * passing them slash-separated, e.g. `policies/faq`.
	 *
	 * @return string[]
	 */
	public function get_allowed_slugs() {
		/**
		 * Filter which published pages the chatbot may read.
		 *
		 * This is an ALLOWLIST. Anything not listed here is invisible to the
		 * model. Never turn it into "all pages except…" — the store has
		 * checkout, cart, my-account and other pages that must never be sent
		 * to a third-party API.
		 *
		 * @param string[] $slugs Page slugs (or slash-separated paths).
		 */
		$slugs = (array) apply_filters( 'db_chat_content_pages', self::default_slugs() );

		$clean = array();

		foreach ( $slugs as $slug ) {
			if ( ! is_scalar( $slug ) ) {
				continue;
			}

			$slug = $this->sanitize_path( (string) $slug );

			if ( '' === $slug || in_array( $slug, $clean, true ) ) {
				continue;
			}

			$clean[] = $slug;

			if ( count( $clean ) >= self::MAX_PAGES ) {
				break;
			}
		}

		return $clean;
	}

	/**
	 * Sanitize one allowlist entry to a slug or slash-separated path.
	 *
	 * @param string $path Raw value.
	 * @return string
	 */
	private function sanitize_path( $path ) {
		$parts = explode( '/', trim( (string) $path, "/ \t\n\r\0\x0B" ) );
		$clean = array();

		foreach ( $parts as $part ) {
			$part = sanitize_title( $part );

			if ( '' !== $part ) {
				$clean[] = $part;
			}
		}

		// A path deeper than this is not a help page; refuse it rather than guess.
		if ( count( $clean ) > 3 ) {
			return '';
		}

		return implode( '/', $clean );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Index building
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Cached index of allowlisted pages.
	 *
	 * @return array<int,array{slug:string,title:string,url:string,chunks:string[]}>
	 */
	public function get_index() {
		$slugs = $this->get_allowed_slugs();

		if ( empty( $slugs ) ) {
			return array();
		}

		$signature = md5( implode( '|', $slugs ) );
		$cached    = get_transient( self::TRANSIENT );

		/*
		 * The allowlist is filterable, so a cached index built from a different
		 * allowlist must be discarded rather than served.
		 */
		if ( is_array( $cached ) && isset( $cached['signature'], $cached['pages'] )
			&& $signature === $cached['signature'] && is_array( $cached['pages'] ) ) {
			return $cached['pages'];
		}

		$pages = array();

		foreach ( $slugs as $slug ) {
			$page = $this->build_page( $slug );

			if ( null !== $page ) {
				$pages[] = $page;
			}
		}

		set_transient(
			self::TRANSIENT,
			array(
				'signature' => $signature,
				'pages'     => $pages,
			),
			self::CACHE_TTL
		);

		return $pages;
	}

	/**
	 * Build one indexed page, or null when it must not be indexed.
	 *
	 * @param string $slug Allowlisted slug or path.
	 * @return array{slug:string,title:string,url:string,chunks:string[]}|null
	 */
	private function build_page( $slug ) {
		$post = $this->locate_page( $slug );

		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		/*
		 * Explicit gate. Query args alone are not trusted for this: everything
		 * that must be true is asserted here, on the object.
		 */
		if ( 'page' !== $post->post_type ) {
			return null;
		}

		if ( 'publish' !== $post->post_status ) {
			return null;
		}

		if ( '' !== (string) $post->post_password ) {
			return null;
		}

		$text = $this->extract_text( $post );

		if ( '' === $text ) {
			return null;
		}

		$chunks = $this->chunk_text( $text );

		if ( empty( $chunks ) ) {
			return null;
		}

		$title = wp_strip_all_tags( (string) get_the_title( $post ) );

		// Some titles carry encoded entities; the model should read "&", not "&amp;".
		$title = trim( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

		return array(
			'id'     => (int) $post->ID,
			'slug'   => (string) $post->post_name,
			'title'  => $title,
			'url'    => esc_url_raw( (string) get_permalink( $post ) ),
			'chunks' => $chunks,
		);
	}

	/**
	 * Find the page for an allowlisted slug or path.
	 *
	 * get_page_by_path() handles nested paths. The get_posts() fallback catches
	 * a child page referenced by its bare slug. Both are pinned to post_type
	 * page; the caller re-checks status and password regardless.
	 *
	 * @param string $slug Slug or path.
	 * @return WP_Post|null
	 */
	private function locate_page( $slug ) {
		$post = get_page_by_path( $slug, OBJECT, 'page' );

		if ( $post instanceof WP_Post ) {
			return $post;
		}

		if ( false !== strpos( $slug, '/' ) ) {
			return null;
		}

		$posts = get_posts(
			array(
				'name'                   => $slug,
				'post_type'              => 'page',
				'post_status'            => 'publish',
				'numberposts'            => 1,
				'suppress_filters'       => true,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
			)
		);

		if ( is_array( $posts ) && ! empty( $posts ) && $posts[0] instanceof WP_Post ) {
			return $posts[0];
		}

		return null;
	}

	/**
	 * Page body as plain text.
	 *
	 * Blocks are removed before shortcodes so block delimiters do not survive
	 * as stray comment markup. `the_content` is deliberately NOT applied: it
	 * would run every third-party filter on the site, which is both slow and
	 * an uncontrolled surface for whatever those filters inject.
	 *
	 * @param WP_Post $post Page.
	 * @return string
	 */
	private function extract_text( WP_Post $post ) {
		$raw = (string) $post->post_content;

		/**
		 * Filter the raw content of an indexed page before it is stripped.
		 *
		 * Escape hatch for pages whose text does not live in post_content —
		 * an Elementor-built page, for instance, keeps its copy in postmeta and
		 * will otherwise index as empty. Return plain text or HTML; it is
		 * stripped and normalised either way, and it is still treated as DATA.
		 *
		 * @param string  $raw  Raw post_content.
		 * @param WP_Post $post Page.
		 */
		$raw = (string) apply_filters( 'db_chat_content_page_raw', $raw, $post );

		if ( '' === trim( $raw ) ) {
			return '';
		}

		if ( function_exists( 'excerpt_remove_blocks' ) ) {
			$raw = (string) excerpt_remove_blocks( $raw );
		}

		$raw = strip_shortcodes( $raw );

		return $this->plain_text( $raw );
	}

	/**
	 * HTML/entity/whitespace normalisation.
	 *
	 * Paragraph breaks are kept (collapsed to a single blank line) because they
	 * are what the chunker breaks on and what makes a policy readable. Control
	 * characters are removed so page content cannot forge structure in the
	 * payload the model sees.
	 *
	 * @param string $html Raw HTML or text.
	 * @return string
	 */
	private function plain_text( $html ) {
		$text = (string) $html;

		// Keep block-level boundaries as newlines before the tags disappear.
		$text = preg_replace( '#<(?:br|/p|/div|/li|/h[1-6]|/tr)\s*/?>#i', "\n", $text );
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\xc2\xa0", ' ', $text );

		$stripped = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );

		if ( is_string( $stripped ) ) {
			$text = $stripped;
		}

		$text = preg_replace( '/\r\n?/', "\n", $text );
		$text = preg_replace( '/[^\S\n]+/u', ' ', (string) $text );
		$text = preg_replace( '/ *\n */u', "\n", (string) $text );
		$text = preg_replace( '/\n{3,}/u', "\n\n", (string) $text );

		return trim( (string) $text );
	}

	/**
	 * Split page text into readable sections of roughly CHUNK_CHARS.
	 *
	 * @param string $text Normalised plain text.
	 * @return string[]
	 */
	private function chunk_text( $text ) {
		$chunks = array();
		$length = mb_strlen( $text );
		$offset = 0;

		while ( $offset < $length && count( $chunks ) < self::MAX_CHUNKS_PER_PAGE ) {
			$remaining = $length - $offset;

			if ( $remaining <= self::CHUNK_CHARS ) {
				$piece = mb_substr( $text, $offset, $remaining );
			} else {
				$piece = mb_substr( $text, $offset, self::CHUNK_CHARS );
				$break = $this->break_position( $piece );

				if ( $break > 0 ) {
					$piece = mb_substr( $piece, 0, $break );
				}
			}

			$advance = mb_strlen( $piece );

			// Guarantees progress; without it a pathological string could loop.
			if ( $advance < 1 ) {
				break;
			}

			$offset += $advance;
			$piece   = trim( $piece );

			if ( '' !== $piece ) {
				$chunks[] = $piece;
			}
		}

		return $chunks;
	}

	/**
	 * Where to cut a section so it ends on a sentence, line or word.
	 *
	 * @param string $piece Candidate section.
	 * @return int Character offset, or 0 to cut at the hard limit.
	 */
	private function break_position( $piece ) {
		$min = (int) floor( self::CHUNK_CHARS * 0.6 );

		foreach ( array( "\n", '. ', ' ' ) as $needle ) {
			$pos = mb_strrpos( $piece, $needle );

			if ( false !== $pos && $pos >= $min ) {
				return $pos + mb_strlen( $needle );
			}
		}

		return 0;
	}

	/**
	 * Drop the cached index.
	 *
	 * @return void
	 */
	public function flush_cache() {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Flush only when the saved/trashed/deleted post is a page.
	 *
	 * Not restricted to allowlisted slugs on purpose: a slug can be edited into
	 * or out of the allowlist by the same save that fires this hook.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post object when the hook supplies one.
	 * @return void
	 */
	public function maybe_flush_for_post( $post_id, $post = null ) {
		$type = $post instanceof WP_Post ? $post->post_type : get_post_type( (int) $post_id );

		if ( 'page' === $type ) {
			$this->flush_cache();
		}
	}

	/**
	 * Flush when a page changes status in either direction.
	 *
	 * @param string       $new_status New status.
	 * @param string       $old_status Old status.
	 * @param WP_Post|null $post       Post.
	 * @return void
	 */
	public function maybe_flush_for_transition( $new_status, $old_status, $post = null ) {
		if ( $new_status === $old_status || ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
			return;
		}

		if ( 'publish' === $new_status || 'publish' === $old_status ) {
			$this->flush_cache();
		}
	}

	/*
	 * -----------------------------------------------------------------------
	 * Static context
	 * -----------------------------------------------------------------------
	 */

	/**
	 * One-line list of the help page titles, for the system prompt.
	 *
	 * Titles only — never content. It tells the model which topics are worth a
	 * search_pages call, and nothing about what the pages say.
	 *
	 * @return string
	 */
	public function get_context() {
		$titles = array();

		foreach ( $this->get_index() as $page ) {
			$title = isset( $page['title'] ) ? trim( (string) $page['title'] ) : '';

			if ( '' !== $title ) {
				$titles[] = $title;
			}
		}

		if ( empty( $titles ) ) {
			return '';
		}

		return implode( ', ', $titles );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Search (the model-facing tool)
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Execute a help page search on behalf of the model.
	 *
	 * @param array $args {
	 *     Tool input, loosely typed from JSON.
	 *
	 *     @type string $query Free-text search.
	 * }
	 * @return array{results:array,count:int,truncated:bool,notice:string}
	 */
	public function search_pages( array $args ) {
		$query = isset( $args['query'] ) && is_scalar( $args['query'] ) ? sanitize_text_field( (string) $args['query'] ) : '';
		$query = trim( mb_substr( $query, 0, 200 ) );

		$pages = $this->get_index();

		if ( empty( $pages ) ) {
			return array(
				'results'   => array(),
				'count'     => 0,
				'truncated' => false,
				'notice'    => 'No help pages are available to search. Tell the customer you cannot look that up and offer to pass the question to a human.',
			);
		}

		$tokens = $this->tokenize( $query );
		$scored = $this->score( $pages, $tokens );

		if ( empty( $scored ) ) {
			return $this->fallback( $pages );
		}

		$results = array();

		foreach ( $scored as $hit ) {
			/*
			 * No URL is sent to the model, deliberately. It cannot emit a
			 * plausible-looking wrong link if it has never seen a link: it
			 * cites the page TITLE, and the endpoint resolves that title back
			 * to the real permalink from the WP_Post. Same rule as products.
			 */
			$results[] = array(
				'page'       => $hit['title'],
				'excerpt'    => $hit['text'],
				'is_extract' => true,
			);

			$this->remember( $hit['title'], $hit['id'] );
		}

		return $this->enforce_payload_cap(
			$results,
			'These are extracts, not whole pages. Answer only from this text, name the `page` title in your reply so the shop can attach a link, and say plainly if the extract does not cover the question.'
		);
	}

	/**
	 * Rank sections against the query tokens.
	 *
	 * @param array              $pages  Indexed pages.
	 * @param array<string,int>  $tokens Token => weight.
	 * @return array<int,array{title:string,url:string,text:string}>
	 */
	private function score( array $pages, array $tokens ) {
		if ( empty( $tokens ) ) {
			return array();
		}

		$hits = array();

		foreach ( $pages as $page_index => $page ) {
			$title_text  = mb_strtolower( (string) $page['title'] . ' ' . str_replace( array( '-', '/' ), ' ', (string) $page['slug'] ) );
			$title_score = 0;

			foreach ( $tokens as $token => $weight ) {
				if ( false !== mb_strpos( $title_text, (string) $token ) ) {
					// A title match is a strong signal that the whole page is relevant.
					$title_score += (int) $weight;
				}
			}

			foreach ( (array) $page['chunks'] as $chunk_index => $chunk ) {
				$haystack = mb_strtolower( (string) $chunk );
				$score    = $title_score;

				/*
				 * The customer's own words are worth more than the synonyms we
				 * expanded them into, otherwise a section that merely repeats a
				 * generic word ("DHL", "delivery") outranks the one section that
				 * actually names what they asked about ("Sabah").
				 */
				foreach ( $tokens as $token => $weight ) {
					$score += min( 3, mb_substr_count( $haystack, (string) $token ) ) * (int) $weight;
				}

				if ( $score < 1 ) {
					continue;
				}

				$hits[] = array(
					'score' => $score,
					'page'  => (int) $page_index,
					'chunk' => (int) $chunk_index,
					'id'    => isset( $page['id'] ) ? (int) $page['id'] : 0,
					'title' => (string) $page['title'],
					'text'  => (string) $chunk,
				);
			}
		}

		if ( empty( $hits ) ) {
			return array();
		}

		usort(
			$hits,
			static function ( $a, $b ) {
				if ( $a['score'] !== $b['score'] ) {
					return $b['score'] <=> $a['score'];
				}

				if ( $a['page'] !== $b['page'] ) {
					return $a['page'] <=> $b['page'];
				}

				return $a['chunk'] <=> $b['chunk'];
			}
		);

		/*
		 * First pass caps how much of one page can fill the budget, so a long
		 * terms page cannot crowd out the delivery page. Second pass spends any
		 * leftover slots on whatever scored next.
		 */
		$selected = array();
		$per_page = array();

		foreach ( $hits as $index => $hit ) {
			if ( count( $selected ) >= self::MAX_SECTIONS ) {
				break;
			}

			$used = isset( $per_page[ $hit['page'] ] ) ? $per_page[ $hit['page'] ] : 0;

			if ( $used >= self::MAX_SECTIONS_PER_PAGE ) {
				continue;
			}

			$per_page[ $hit['page'] ] = $used + 1;
			$selected[ $index ]       = $hit;
		}

		if ( count( $selected ) < self::MAX_SECTIONS ) {
			foreach ( $hits as $index => $hit ) {
				if ( count( $selected ) >= self::MAX_SECTIONS ) {
					break;
				}

				if ( ! isset( $selected[ $index ] ) ) {
					$selected[ $index ] = $hit;
				}
			}
		}

		ksort( $selected );

		return array_values( $selected );
	}

	/**
	 * Nothing matched: show the opening of each help page instead.
	 *
	 * Better than an empty result — the customer's wording often differs from
	 * the page's ("post" vs "delivery") — but the notice is emphatic so the
	 * model does not treat an opening paragraph as an answer it does not
	 * contain. A confidently wrong policy answer causes refund disputes.
	 *
	 * @param array $pages Indexed pages.
	 * @return array{results:array,count:int,truncated:bool,notice:string}
	 */
	private function fallback( array $pages ) {
		$results = array();

		foreach ( $pages as $page ) {
			if ( count( $results ) >= self::MAX_SECTIONS ) {
				break;
			}

			if ( empty( $page['chunks'][0] ) ) {
				continue;
			}

			$results[] = array(
				'page'       => (string) $page['title'],
				'excerpt'    => (string) $page['chunks'][0],
				'is_extract' => true,
			);

			$this->remember( (string) $page['title'], isset( $page['id'] ) ? (int) $page['id'] : 0 );
		}

		return $this->enforce_payload_cap(
			$results,
			'NO SECTION MATCHED THE QUERY. These are simply the opening extracts of the help pages and probably do not answer the question. Do not stretch them into an answer. If the wording of the question does not appear here, tell the customer plainly that our pages do not cover it and offer to pass it to a human.'
		);
	}

	/** Score weight for a word the customer actually wrote. */
	const WEIGHT_LITERAL = 3;

	/** Score weight for a word we expanded their word into. */
	const WEIGHT_SYNONYM = 1;

	/**
	 * Query tokens and their weights, with a small synonym expansion.
	 *
	 * The pages say "delivery" where a customer says "shipping", and Malay and
	 * English are both in use, so a literal token match alone misses far too
	 * often. Expanded terms are deliberately worth less than the customer's own
	 * words so they broaden the net without hijacking the ranking.
	 *
	 * @param string $query Raw query.
	 * @return array<string,int> Token => weight.
	 */
	private function tokenize( $query ) {
		$parts = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( (string) $query ), -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $parts ) ) {
			return array();
		}

		$stopwords = array(
			'the', 'and', 'for', 'you', 'your', 'are', 'can', 'how', 'what', 'when', 'where', 'with',
			'does', 'did', 'do', 'is', 'it', 'my', 'me', 'we', 'us', 'about', 'from', 'have', 'has',
			'any', 'all', 'this', 'that', 'there', 'please', 'much', 'many', 'long', 'take',
			'yang', 'untuk', 'ada', 'saya', 'boleh', 'macam', 'mana', 'apa', 'berapa', 'dengan',
			'kalau', 'nak', 'tak', 'ke', 'dan', 'lama',
		);

		$tokens = array();

		foreach ( $parts as $part ) {
			if ( mb_strlen( $part ) < 3 || in_array( $part, $stopwords, true ) ) {
				continue;
			}

			$this->push_token( $tokens, $part, self::WEIGHT_LITERAL );

			foreach ( $this->synonyms( $part ) as $synonym ) {
				$this->push_token( $tokens, $synonym, self::WEIGHT_SYNONYM );
			}
		}

		// Bound the work: 20 tokens against 12 chunks per page is plenty.
		return array_slice( $tokens, 0, 20, true );
	}

	/**
	 * Add a token, keeping the highest weight it was seen with.
	 *
	 * @param array<string,int> $tokens Token map, by reference.
	 * @param string            $token  Candidate.
	 * @param int               $weight Score weight.
	 * @return void
	 */
	private function push_token( array &$tokens, $token, $weight ) {
		$token = trim( (string) $token );

		if ( '' === $token ) {
			return;
		}

		if ( ! isset( $tokens[ $token ] ) || $tokens[ $token ] < (int) $weight ) {
			$tokens[ $token ] = (int) $weight;
		}
	}

	/**
	 * Extra search terms for a query word.
	 *
	 * Deliberately stems by prefix, so "shipping", "shipment" and "ships" all
	 * reach the "ship" group.
	 *
	 * @param string $token Query word.
	 * @return string[]
	 */
	private function synonyms( $token ) {
		$groups = array(
			'ship'     => array( 'ship', 'deliver', 'postage', 'courier', 'poslaju', 'dhl', 'sabah', 'sarawak', 'hantar', 'penghantar' ),
			'delivery' => array( 'deliver', 'ship', 'courier', 'postage', 'arrive', 'dispatch', 'hantar' ),
			'refund'   => array( 'refund', 'return', 'exchange', 'money back', 'pulang', 'bayar balik' ),
			'return'   => array( 'return', 'refund', 'exchange', 'warrant', 'tukar', 'pulang' ),
			'warranty' => array( 'warrant', 'guarantee', 'defect', 'faulty', 'jamin' ),
			'payment'  => array( 'pay', 'payment', 'fpx', 'card', 'bank', 'transfer', 'invoice', 'bayar' ),
			'contact'  => array( 'contact', 'email', 'phone', 'whatsapp', 'address', 'reach', 'hubungi' ),
			'hours'    => array( 'hour', 'open', 'close', 'monday', 'weekend', 'waktu' ),
			'track'    => array( 'track', 'consign', 'deliver', 'ship' ),
			'cancel'   => array( 'cancel', 'return', 'refund', 'batal' ),
			'tax'      => array( 'tax', 'sst', 'duty', 'customs', 'cukai' ),
		);

		/**
		 * Filter the search synonym groups used for help page lookup.
		 *
		 * Keys are matched as a prefix of the customer's word; values are extra
		 * search terms.
		 *
		 * @param array<string,string[]> $groups Synonym groups.
		 */
		$groups = (array) apply_filters( 'db_chat_content_synonyms', $groups );

		$out = array();

		foreach ( $groups as $prefix => $terms ) {
			$prefix = (string) $prefix;

			if ( '' === $prefix || ! is_array( $terms ) ) {
				continue;
			}

			// "shipping" starts with "ship"; "ship" also matches "shipping".
			if ( 0 === mb_strpos( $token, $prefix ) || 0 === mb_strpos( $prefix, $token ) ) {
				foreach ( $terms as $term ) {
					if ( is_scalar( $term ) ) {
						$out[] = mb_strtolower( trim( (string) $term ) );
					}
				}
			}
		}

		return $out;
	}

	/**
	 * Keep the payload under the hard cap.
	 *
	 * Trims the longest excerpt first, then drops sections, so one long section
	 * cannot cost the model the other pages it needs.
	 *
	 * @param array  $results Formatted sections.
	 * @param string $notice  Guidance for the model.
	 * @return array{results:array,count:int,truncated:bool,notice:string}
	 */
	private function enforce_payload_cap( array $results, $notice ) {
		$truncated = false;

		while ( ! empty( $results ) && $this->payload_size( $results ) > self::MAX_PAYLOAD_CHARS ) {
			$longest = 0;

			foreach ( $results as $index => $record ) {
				if ( mb_strlen( (string) $record['excerpt'] ) > mb_strlen( (string) $results[ $longest ]['excerpt'] ) ) {
					$longest = $index;
				}
			}

			$excerpt = (string) $results[ $longest ]['excerpt'];
			$keep    = (int) floor( mb_strlen( $excerpt ) * 0.7 );

			if ( $keep < 200 ) {
				// Nothing left worth trimming; drop the section instead.
				array_pop( $results );
				$results = array_values( $results );
			} else {
				$results[ $longest ]['excerpt'] = rtrim( mb_substr( $excerpt, 0, $keep ) ) . '…';
			}

			$truncated = true;
		}

		if ( $truncated ) {
			$notice = trim( $notice . ' Some text was shortened, so read it as partial.' );
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
	 * Resolving model output back to real pages
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Record a page the model has seen this request.
	 *
	 * @param string $title   Page title.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	private function remember( $title, $post_id ) {
		$key = $this->normalize( $title );

		if ( '' !== $key && $post_id > 0 && ! isset( $this->seen[ $key ] ) ) {
			$this->seen[ $key ] = (int) $post_id;
		}
	}

	/**
	 * Find help page titles the assistant cited, as real links.
	 *
	 * The model is never shown a URL and never asked to write one. It names the
	 * page; this rebuilds the link from the WP_Post, re-checking publish status
	 * and password, and refuses anything that has since dropped out of the
	 * index. A model-generated URL is never trusted, because none exists.
	 *
	 * @param string $text Assistant reply.
	 * @return array<int,array{title:string,url:string}>
	 */
	public function match_in_text( $text ) {
		if ( empty( $this->seen ) ) {
			return array();
		}

		$haystack = $this->normalize( (string) $text );

		if ( '' === $haystack ) {
			return array();
		}

		// Padded so a title can only match on whole-word boundaries.
		$haystack = ' ' . $haystack . ' ';

		$cards = array();
		$used  = array();

		// $seen is in relevance order, so the best-matching page wins ties.
		foreach ( $this->seen as $key => $post_id ) {
			if ( count( $cards ) >= self::MAX_CARDS ) {
				break;
			}

			if ( isset( $used[ $post_id ] ) || false === strpos( $haystack, ' ' . $key . ' ' ) ) {
				continue;
			}

			$card = $this->format_card( $post_id );

			if ( null === $card ) {
				continue;
			}

			$used[ $post_id ] = true;
			$cards[]          = $card;
		}

		return $cards;
	}

	/**
	 * Build the link payload sent to the browser.
	 *
	 * Contract with the frontend: exactly title and url. Both come from the
	 * live post, never from the model.
	 *
	 * @param int $post_id Post ID.
	 * @return array{title:string,url:string}|null
	 */
	private function format_card( $post_id ) {
		$post = get_post( (int) $post_id );

		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		// Re-assert the whole gate: the post may have changed since indexing.
		if ( 'page' !== $post->post_type || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
			return null;
		}

		// And it must still be an allowlisted page, not merely a published one.
		if ( ! in_array( (int) $post->ID, $this->get_indexed_ids(), true ) ) {
			return null;
		}

		$title = wp_strip_all_tags( (string) get_the_title( $post ) );
		$title = trim( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$url   = esc_url_raw( (string) get_permalink( $post ) );

		if ( '' === $title || '' === $url ) {
			return null;
		}

		return array(
			'title' => $title,
			'url'   => $url,
		);
	}

	/**
	 * IDs currently in the allowlisted index.
	 *
	 * @return int[]
	 */
	private function get_indexed_ids() {
		$ids = array();

		foreach ( $this->get_index() as $page ) {
			if ( ! empty( $page['id'] ) ) {
				$ids[] = (int) $page['id'];
			}
		}

		return $ids;
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
