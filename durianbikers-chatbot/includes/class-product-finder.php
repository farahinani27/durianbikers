<?php
/**
 * Guided product finder — a deterministic, chip-driven alternative to typing.
 *
 * WHY THIS EXISTS
 * ---------------
 * Free lookup mode matches keywords. It has no language understanding, so it
 * cannot answer "what do you recommend?" — there is nothing in the message to
 * match on. A short sequence of taps covers much of the same ground without
 * pretending to understand anything, and for a customer who does not know the
 * vocabulary of the catalogue it is better UX than a text box.
 *
 *     trigger  -> What are you looking for?   [Helmets] [Tyres] ... [Show me everything]
 *              -> Which brand?                [Shinko] [MT] ...    [Any brand]
 *              -> What's your budget?         [Under RM200] ...    [Any budget]
 *              -> up to 4 product cards
 *
 * THREE PROPERTIES THAT ARE STRUCTURAL, NOT PROMISED
 * --------------------------------------------------
 * 1. NO EMPTY BRANCHES. Options are not read from a list and then checked; they
 *    are *derived from the buyable products that are actually there*. The brand
 *    chips at step 2 are the brands found on in-stock, priced products inside
 *    the chosen category. A branch with nothing behind it cannot be rendered,
 *    because there is no product to name it. This matters more than it sounds:
 *    an empty result after three taps is the worst outcome in the feature.
 * 2. NO SERVER-SIDE STATE. The step is re-derived from the conversation history
 *    the endpoint already receives, by replaying the flow. There is no session
 *    store and no client-supplied step number to forge. See resolve_state().
 * 3. NO DEAD ENDS. Every step carries an escape option, and a customer who
 *    types something that is not an option for the current step is not
 *    answered by the finder at all — maybe_handle() returns null and the normal
 *    engine takes the message. The flow abandons itself; it never traps anyone.
 *
 * THE BROKEN IMPORTS
 * ------------------
 * ~1,450 of the 5,283 published products are Shopify import artifacts: variable
 * products with zero variations, no price, out of stock. They must never be
 * what makes a branch look populated. Two independent filters keep them out —
 * the query is pinned to stock_status=instock (they are all outofstock) and
 * is_buyable() additionally rejects a variable product with no children and
 * anything without a real price. Either alone would probably do; both are
 * cheap, and "probably" is not good enough for a branch-emptiness guarantee.
 *
 * SCOPE
 * -----
 * Published, publicly visible products only. No stock_quantity is read or
 * emitted. No order, no user, no personal data of any kind is reachable from
 * this file, and no outbound HTTP call is made from it.
 *
 * @package durianbikers-chatbot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Chip-driven guided product finder.
 */
final class DB_Chat_Product_Finder {

	/**
	 * Hard cap on chips in one reply. Agreed with frontend-dev.
	 *
	 * Six, of which the last is always the escape option — so five real
	 * choices. The store has 21 brands; a six-chip list always truncates, and
	 * the customer whose brand is not shown needs somewhere to go.
	 */
	const MAX_CHIPS = 6;

	/** Real (non-escape) options offered per step. */
	const MAX_OPTIONS = 5;

	/**
	 * Longest chip label, in characters.
	 *
	 * 32, and the number is measured rather than chosen. frontend-dev measured
	 * the real rendered chip at its actual font, padding and max-width; at
	 * 375px (the mobile sheet, the binding width) one line holds:
	 *
	 *     mixed / Title Case   33 characters
	 *     ALL CAPS             27
	 *     narrow lower-case    52
	 *
	 * DO NOT DROP THIS TO 27 to stop all-caps labels wrapping. That trades five
	 * characters of real meaning on every label in the catalogue against a
	 * minority casing, and what it buys is purely cosmetic: an all-caps chip
	 * that wraps still fits the viewport, still clears the 44px touch target,
	 * still sends the identical value and is still tappable. A two-line chip is
	 * not a dead end. The bug class that actually mattered — a tap resolving to
	 * NULL while typing the same name worked — is fixed by chip_label() and
	 * prefix matching, not by the cap.
	 *
	 * DO NOT RAISE IT EITHER. At 40 every long label wrapped, spending roughly
	 * 200px of a ~700px sheet on four characters of overshoot.
	 *
	 * A character count cannot express a width limit — "WATERPROOF TOURING
	 * JACKETS MENS…" wraps at 32 while "Handguard Mounting Hardware Kits…"
	 * fits at 33. Making every chip single-line needs a width budget measured
	 * in the browser, which cannot be computed server-side, so it would be a
	 * frontend change. It has been considered and declined on both sides.
	 */
	const MAX_CHIP_CHARS = 32;

	/** Product cards in the results reply. Matches the frontend contract. */
	const MAX_CARDS = 4;

	/** Buyable products sampled per branch when deriving options. */
	const PROBE_LIMIT = 40;

	/** Extra sample taken from the expensive end, so high budget bands exist. */
	const PROBE_TAIL = 10;

	/** Top-level categories considered before filtering to the buyable ones. */
	const CATEGORY_CANDIDATES = 8;

	/** Cached option tree. */
	const TRANSIENT = 'db_chat_finder_options';

	/** Option cache lifetime. Stock moves; an hour-stale chip is acceptable. */
	const CACHE_TTL = HOUR_IN_SECONDS;

	/* Steps. STEP_RESULTS is a terminal state, never a question. */
	const STEP_CATEGORY = 1;
	const STEP_BRAND    = 2;
	const STEP_BUDGET   = 3;
	const STEP_RESULTS  = 4;

	/**
	 * Product index, used only for its public price formatter and brand
	 * taxonomy detection. All finder queries are run here, deliberately —
	 * see probe() for why the index's own search cannot be reused.
	 *
	 * @var DB_Chat_Product_Index
	 */
	private $index;

	/**
	 * Branch option cache for this request, mirrored into the transient.
	 *
	 * @var array<string,mixed>
	 */
	private $branches = null;

	/**
	 * Whether $branches gained anything worth persisting.
	 *
	 * @var bool
	 */
	private $branches_dirty = false;

	/**
	 * Memoized singleton.
	 *
	 * @var DB_Chat_Product_Finder|null
	 */
	private static $instance = null;

	/**
	 * @param DB_Chat_Product_Index $index Shared product index.
	 */
	public function __construct( DB_Chat_Product_Index $index ) {
		$this->index = $index;
	}

	/**
	 * Shared instance.
	 *
	 * The endpoint should pass its own index so there is one object doing the
	 * product work per request; the fallback exists so the finder is usable
	 * from a filter or a test without wiring.
	 *
	 * @param DB_Chat_Product_Index|null $index Optional index to adopt.
	 * @return DB_Chat_Product_Finder
	 */
	public static function instance( $index = null ) {
		if ( null === self::$instance ) {
			if ( ! $index instanceof DB_Chat_Product_Index ) {
				$index = new DB_Chat_Product_Index();
			}

			self::$instance = new self( $index );
		}

		return self::$instance;
	}

	/*
	 * =======================================================================
	 * ENTRY POINT
	 * =======================================================================
	 */

	/**
	 * Answer this turn, or decline it.
	 *
	 * Declining is the common case and must stay cheap: a message that is not a
	 * trigger and not a legal answer to a question this class actually asked
	 * costs one normalize() and a substring scan before returning null.
	 *
	 * @param string $message Sanitized customer message.
	 * @param array  $history Sanitized conversation history (untrusted).
	 * @return array{reply:string,products:array,pages:array,chips:array}|null Null when the finder does not apply.
	 */
	public function maybe_handle( $message, array $history = array() ) {
		$message = (string) $message;

		if ( '' === trim( $message ) ) {
			return null;
		}

		if ( ! (bool) apply_filters( 'db_chat_finder_enabled', true ) ) {
			return null;
		}

		try {
			$state = $this->resolve_state( $message, $history );

			if ( null === $state ) {
				return null;
			}

			return $this->render( $state['step'], $state['selections'], $state['lang'] );
		} catch ( Throwable $e ) {
			// A broken finder must degrade to the normal engine, never to a
			// 500. The customer still gets an answer; we just get a log line.
			if ( class_exists( 'DB_Chat_Plugin' ) && is_callable( array( 'DB_Chat_Plugin', 'log_error' ) ) ) {
				DB_Chat_Plugin::log_error( 'Product finder failed: ' . $e->getMessage() );
			}

			return null;
		}
	}

	/*
	 * =======================================================================
	 * STATE — DERIVED FROM HISTORY, NEVER STORED, NEVER TRUSTED
	 * =======================================================================
	 */

	/**
	 * Work out which step this message belongs to, by replaying the flow.
	 *
	 * THIS IS THE SECURITY-RELEVANT METHOD. History is client-supplied: the
	 * browser can send any transcript it likes, including one that never
	 * happened. So the step is never *read* from history — it is *reconstructed*
	 * from it, and the reconstruction is rejected unless the whole transcript is
	 * something this class could actually have produced.
	 *
	 * The replay:
	 *
	 *   1. Find the most recent user turn that is a finder trigger. Everything
	 *      before it is irrelevant — an older, abandoned flow cannot resume.
	 *   2. The turns after it must alternate assistant, user, assistant, user…
	 *      starting with an assistant turn. Any other shape is impossible for
	 *      this flow and is rejected outright.
	 *   3. Each assistant turn must be *exactly the question this class would
	 *      have asked* at that point, given the selections accumulated so far.
	 *      A fabricated "Which brand?" injected before the category was chosen
	 *      fails here, because at that point the machine asks for a category.
	 *   4. Each user turn must be a legal option for that question, where the
	 *      legal options are recomputed live from the catalogue — not read back
	 *      from the transcript. A brand that has no in-stock products behind it
	 *      is not a legal answer even if the transcript claims it was offered.
	 *
	 * Only if every turn survives all four checks is the resulting step used.
	 * Otherwise the finder declines and the normal engine answers, which is also
	 * exactly what happens when a customer simply changes the subject.
	 *
	 * A note on what this does and does not buy. A determined client can still
	 * replay a *valid* transcript it constructed itself — the checks make the
	 * transcript consistent, they do not prove it happened. That is fine and is
	 * the reason the finder was scoped the way it was: reaching step 3 by
	 * forgery gets you a list of published, in-stock products, which is the same
	 * thing the shop pages hand out to anonymous visitors. There is no state to
	 * corrupt, nothing is written, and no personal data is in reach. The check
	 * exists so the *step* cannot be forged into meaning something it does not,
	 * not because the transcript is a credential.
	 *
	 * @param string $message Current message.
	 * @param array  $history Untrusted history.
	 * @return array{step:int,selections:array,lang:string}|null
	 */
	private function resolve_state( $message, array $history ) {
		$turns = $this->clean_history( $history );
		$start = $this->find_trigger( $turns );

		if ( null === $start ) {
			// No flow in progress. Is this message starting one?
			if ( ! $this->is_trigger( $message ) ) {
				return null;
			}

			return array(
				'step'       => $this->first_step( array(), $this->detect_language( $message ) ),
				'selections' => array(),
				'lang'       => $this->detect_language( $message ),
			);
		}

		/*
		 * Language is decided once, from the trigger, and then held for the
		 * whole flow. Deciding it per turn would flip an English flow into
		 * Malay the moment someone tapped a chip containing a word that also
		 * appears in the Malay marker list ("ada", "harga", "saiz").
		 */
		$lang       = $this->detect_language( $turns[ $start ]['content'] );
		$slice      = array_slice( $turns, $start + 1 );
		$selections = array();
		$step       = $this->first_step( $selections, $lang );

		$expect_assistant = true;

		foreach ( $slice as $turn ) {
			if ( $expect_assistant ) {
				if ( 'assistant' !== $turn['role'] ) {
					return null; // Impossible shape.
				}

				if ( self::STEP_RESULTS === $step ) {
					// The flow already finished. A further turn is a new
					// conversation, not a continuation of this one.
					return null;
				}

				if ( ! $this->is_question_for( $turn['content'], $step, $lang ) ) {
					// The transcript claims we asked something we would not
					// have asked at this point. Forged, stale or edited.
					return null;
				}

				$expect_assistant = false;
				continue;
			}

			if ( 'user' !== $turn['role'] ) {
				return null;
			}

			$choice = $this->match_option( $turn['content'], $step, $selections, $lang );

			if ( null === $choice ) {
				/*
				 * The customer said something that was not an option. They left
				 * the flow at that point, so there is no flow to continue now.
				 */
				return null;
			}

			$selections[ $this->slot( $step ) ] = $choice;
			$step                               = $this->next_step( $step, $selections, $lang );
			$expect_assistant                   = true;
		}

		if ( $expect_assistant ) {
			/*
			 * The transcript ends on a completed pair, so no question is
			 * outstanding. The current message is unprompted: if it is a fresh
			 * trigger the caller already handled it above, otherwise it is a
			 * normal question and not ours.
			 */
			return null;
		}

		if ( self::STEP_RESULTS === $step ) {
			return null;
		}

		$choice = $this->match_option( $message, $step, $selections, $lang );

		if ( null === $choice ) {
			return null; // Abandoned mid-flow. The engine answers normally.
		}

		$selections[ $this->slot( $step ) ] = $choice;

		return array(
			'step'       => $this->next_step( $step, $selections, $lang ),
			'selections' => $selections,
			'lang'       => $lang,
		);
	}

	/**
	 * Type and filter the history down to usable turns.
	 *
	 * The endpoint sanitizes history before we see it; this re-does the shape
	 * checks anyway because the finder must be safe to call from anywhere, and
	 * because "the caller validated it" is how untrusted data gets in.
	 *
	 * @param array $history Raw history.
	 * @return array<int,array{role:string,content:string}>
	 */
	private function clean_history( array $history ) {
		$turns = array();

		foreach ( $history as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['role'], $entry['content'] ) ) {
				continue;
			}

			if ( ! is_scalar( $entry['role'] ) || ! is_scalar( $entry['content'] ) ) {
				continue;
			}

			$role    = strtolower( trim( (string) $entry['role'] ) );
			$content = trim( wp_strip_all_tags( (string) $entry['content'] ) );

			if ( '' === $content || ! in_array( $role, array( 'user', 'assistant' ), true ) ) {
				continue;
			}

			$turns[] = array(
				'role'    => $role,
				'content' => $content,
			);
		}

		return $turns;
	}

	/**
	 * Index of the most recent user turn that starts a flow.
	 *
	 * Most recent, not first: a customer who ran the finder, wandered off and
	 * ran it again is in the second flow, and the first must not bleed into it.
	 *
	 * @param array $turns Cleaned turns.
	 * @return int|null
	 */
	private function find_trigger( array $turns ) {
		for ( $i = count( $turns ) - 1; $i >= 0; $i-- ) {
			if ( 'user' === $turns[ $i ]['role'] && $this->is_trigger( $turns[ $i ]['content'] ) ) {
				return $i;
			}
		}

		return null;
	}

	/**
	 * The step the flow opens on.
	 *
	 * @param array  $selections Selections so far.
	 * @param string $lang       Flow language.
	 * @return int
	 */
	private function first_step( array $selections, $lang ) {
		return $this->usable_step( self::STEP_CATEGORY, $selections, $lang );
	}

	/**
	 * The step after this one.
	 *
	 * @param int    $step       Current step.
	 * @param array  $selections Selections including the one just made.
	 * @param string $lang       Flow language.
	 * @return int
	 */
	private function next_step( $step, array $selections, $lang ) {
		return $this->usable_step( $step + 1, $selections, $lang );
	}

	/**
	 * Skip forward past any step that is not worth asking.
	 *
	 * A step with fewer than two real options is a question with one answer.
	 * Asking it costs the customer a tap and tells them nothing, and the brief
	 * is 2-4 steps because longer flows get abandoned. So a category with a
	 * single brand behind it skips the brand question entirely.
	 *
	 * This is deterministic given the catalogue, which is what lets the replay
	 * in resolve_state() reach the same conclusion the live turn did. If stock
	 * changes underneath a flow the replay stops matching, the finder declines,
	 * and the engine answers — degraded, but never wrong.
	 *
	 * @param int    $step       Candidate step.
	 * @param array  $selections Selections so far.
	 * @param string $lang       Flow language.
	 * @return int
	 */
	private function usable_step( $step, array $selections, $lang ) {
		while ( $step < self::STEP_RESULTS ) {
			$options = $this->options_for( $step, $selections, $lang );

			// Escape option excluded: it is never a reason to ask.
			$real = 0;

			foreach ( $options as $option ) {
				if ( 'any' !== $option['kind'] ) {
					$real++;
				}
			}

			if ( $real >= 2 ) {
				return $step;
			}

			/*
			 * Not worth asking. Record the implied "no filter" so the slot is
			 * filled and the replay agrees, then move on.
			 */
			$selections[ $this->slot( $step ) ] = array(
				'kind'  => 'any',
				'value' => '',
				'label' => '',
			);

			$step++;
		}

		return self::STEP_RESULTS;
	}

	/**
	 * Selection slot name for a step.
	 *
	 * @param int $step Step.
	 * @return string
	 */
	private function slot( $step ) {
		switch ( (int) $step ) {
			case self::STEP_CATEGORY:
				return 'category';

			case self::STEP_BRAND:
				return 'brand';

			case self::STEP_BUDGET:
				return 'budget';
		}

		return 'unknown';
	}

	/*
	 * =======================================================================
	 * OPTIONS — DERIVED FROM BUYABLE PRODUCTS, NOT FROM A LIST
	 * =======================================================================
	 */

	/**
	 * The options offered at a step, given what has been chosen already.
	 *
	 * Every option here is backed by at least one buyable product, because
	 * every option is *read off* buyable products. The escape option is
	 * appended last and always exists.
	 *
	 * @param int    $step       Step.
	 * @param array  $selections Selections so far.
	 * @param string $lang       Flow language.
	 * @return array<int,array{kind:string,value:string,label:string,match:string[]}>
	 */
	private function options_for( $step, array $selections, $lang ) {
		switch ( (int) $step ) {
			case self::STEP_CATEGORY:
				$options = $this->category_options();
				break;

			case self::STEP_BRAND:
				$options = $this->brand_options( $selections );
				break;

			case self::STEP_BUDGET:
				$options = $this->budget_options( $selections, $lang );
				break;

			default:
				return array();
		}

		$escape = $this->escape_option( $step, $lang );

		/*
		 * Drop any real option that would collide with the escape label before
		 * appending it. Without this, a brand literally named "Any brand"
		 * would be deduped against the escape option downstream and the escape
		 * option — the only thing preventing a dead end — would be the one
		 * that got dropped. The frontend identifies the escape chip purely by
		 * its position, so this also keeps that contract true unconditionally
		 * rather than almost always.
		 */
		$escape_key = $this->normalize( $escape['label'] );

		$options = array_values(
			array_filter(
				$options,
				function ( $option ) use ( $escape_key ) {
					return $this->normalize( $option['label'] ) !== $escape_key;
				}
			)
		);

		$options   = array_slice( $options, 0, self::MAX_OPTIONS );
		$options[] = $escape;

		return array_slice( $options, 0, self::MAX_CHIPS );
	}

	/**
	 * Top-level categories that have something buyable in them.
	 *
	 * Top-level only, and ordered by product count: the chips have to be the
	 * few broad things a customer recognises, not 60 leaf categories.
	 *
	 * @return array<int,array>
	 */
	private function category_options() {
		$cached = $this->branch_get( 'categories' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$options = array();

		if ( function_exists( 'taxonomy_exists' ) && taxonomy_exists( 'product_cat' ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => true,
					'parent'     => 0,
					'number'     => self::CATEGORY_CANDIDATES,
					'orderby'    => 'count',
					'order'      => 'DESC',
				)
			);

			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					if ( ! $term instanceof WP_Term ) {
						continue;
					}

					$name = trim( wp_strip_all_tags( $term->name ) );

					if ( '' === $name || 'uncategorized' === strtolower( $name ) ) {
						continue;
					}

					// The count on the term includes the broken imports, so it
					// proves nothing. Ask for a buyable product instead.
					if ( empty( $this->probe( $term->slug, '' ) ) ) {
						continue;
					}

					$options[] = $this->term_option( 'category', $term->slug, $name );

					if ( count( $options ) >= self::MAX_OPTIONS ) {
						break;
					}
				}
			}
		}

		$this->branch_set( 'categories', $options );

		return $options;
	}

	/**
	 * Brands present on buyable products inside the chosen category.
	 *
	 * @param array $selections Selections so far.
	 * @return array<int,array>
	 */
	private function brand_options( array $selections ) {
		$category = $this->selected_value( $selections, 'category' );
		$taxonomy = $this->index->get_brand_taxonomy();

		if ( '' === $taxonomy ) {
			return array();
		}

		$key    = 'brands:' . $category;
		$cached = $this->branch_get( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$counts = array();
		$names  = array();

		foreach ( $this->probe( $category, '' ) as $product ) {
			$terms = get_the_terms( $product->get_id(), $taxonomy );

			if ( ! is_array( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				if ( ! $term instanceof WP_Term ) {
					continue;
				}

				$name = trim( wp_strip_all_tags( $term->name ) );

				if ( '' === $name ) {
					continue;
				}

				$counts[ $term->slug ] = isset( $counts[ $term->slug ] ) ? $counts[ $term->slug ] + 1 : 1;
				$names[ $term->slug ]  = $name;
			}
		}

		arsort( $counts );

		$options = array();

		foreach ( $counts as $slug => $count ) {
			unset( $count );

			$options[] = $this->term_option( 'brand', (string) $slug, $names[ $slug ] );

			if ( count( $options ) >= self::MAX_OPTIONS ) {
				break;
			}
		}

		$this->branch_set( $key, $options );

		return $options;
	}

	/**
	 * Budget bands that actually contain something in this branch.
	 *
	 * Computed from the same sample the brand step used, so a band is offered
	 * only when a real priced product falls inside it.
	 *
	 * @param array  $selections Selections so far.
	 * @param string $lang       Flow language.
	 * @return array<int,array>
	 */
	private function budget_options( array $selections, $lang ) {
		$category = $this->selected_value( $selections, 'category' );
		$brand    = $this->selected_value( $selections, 'brand' );

		$options = array();

		foreach ( $this->bands() as $band ) {
			$hit = false;

			foreach ( $this->probe( $category, $brand ) as $product ) {
				if ( $this->in_band( $this->price_of( $product ), $band ) ) {
					$hit = true;
					break;
				}
			}

			if ( ! $hit ) {
				continue;
			}

			$options[] = array(
				'kind'  => 'band',
				'value' => $band['id'],
				'label' => $this->band_label( $band, $lang ),
				'match' => $this->band_synonyms( $band, $lang ),
			);
		}

		return $options;
	}

	/**
	 * Budget bands, in MYR.
	 *
	 * @return array<int,array{id:string,min:float|null,max:float|null}>
	 */
	private function bands() {
		$bands = array(
			array(
				'id'  => 'under200',
				'min' => null,
				'max' => 200.0,
			),
			array(
				'id'  => 'mid',
				'min' => 200.0,
				'max' => 500.0,
			),
			array(
				'id'  => 'over500',
				'min' => 500.0,
				'max' => null,
			),
		);

		/**
		 * Filter the budget bands offered by the guided finder.
		 *
		 * Each band is { id, min, max } with null meaning open-ended, in MYR.
		 *
		 * @param array $bands Budget bands.
		 */
		$bands = (array) apply_filters( 'db_chat_finder_budget_bands', $bands );

		$clean = array();

		foreach ( $bands as $band ) {
			if ( ! is_array( $band ) || empty( $band['id'] ) ) {
				continue;
			}

			$clean[] = array(
				'id'  => (string) $band['id'],
				'min' => isset( $band['min'] ) && null !== $band['min'] ? (float) $band['min'] : null,
				'max' => isset( $band['max'] ) && null !== $band['max'] ? (float) $band['max'] : null,
			);
		}

		return $clean;
	}

	/**
	 * Is a price inside a band? Bands are min-inclusive, max-exclusive so they
	 * tile without overlapping — RM200 belongs to "RM200-500", not to both.
	 *
	 * @param float $price Price, 0.0 when unknown.
	 * @param array $band  Band.
	 * @return bool
	 */
	private function in_band( $price, array $band ) {
		if ( $price <= 0.0 ) {
			return false;
		}

		if ( null !== $band['min'] && $price < $band['min'] ) {
			return false;
		}

		if ( null !== $band['max'] && $price >= $band['max'] ) {
			return false;
		}

		return true;
	}

	/**
	 * The escape option for a step. Always present, always last.
	 *
	 * @param int    $step Step.
	 * @param string $lang Flow language.
	 * @return array
	 */
	private function escape_option( $step, $lang ) {
		$labels = array(
			self::STEP_CATEGORY => array(
				'en' => 'Show me everything',
				'ms' => 'Tunjukkan semua',
			),
			self::STEP_BRAND    => array(
				'en' => 'Any brand',
				'ms' => 'Mana-mana jenama',
			),
			self::STEP_BUDGET   => array(
				'en' => 'Any budget',
				'ms' => 'Apa-apa bajet',
			),
		);

		$label = isset( $labels[ $step ][ $lang ] ) ? $labels[ $step ][ $lang ] : 'Any';

		return array(
			'kind'  => 'any',
			'value' => '',
			'label' => $label,
			'match' => array( $label, 'any', 'anything', 'all', 'everything', 'show me everything', 'no preference', 'any brand', 'any budget', 'semua', 'apa-apa', 'apa apa', 'mana-mana', 'mana mana', 'tunjukkan semua', 'tak kisah' ),
		);
	}

	/**
	 * Build a taxonomy-backed option.
	 *
	 * The label is the catalogue's own term name in both languages. Only the
	 * finder's own prose and its generic options are translated: translating 60
	 * category names and 21 brand names would mean maintaining a second
	 * catalogue that silently drifts from the real one, and brand names do not
	 * translate anyway.
	 *
	 * @param string $kind Option kind.
	 * @param string $slug Term slug.
	 * @param string $name Term name.
	 * @return array
	 */
	private function term_option( $kind, $slug, $name ) {
		return array(
			'kind'  => $kind,
			'value' => $slug,
			'label' => $name,
			'match' => array( $name, $slug, str_replace( '-', ' ', $slug ) ),
		);
	}

	/**
	 * Value of a recorded selection, '' when the slot means "no filter".
	 *
	 * @param array  $selections Selections.
	 * @param string $slot       Slot name.
	 * @return string
	 */
	private function selected_value( array $selections, $slot ) {
		if ( ! isset( $selections[ $slot ] ) || ! is_array( $selections[ $slot ] ) ) {
			return '';
		}

		if ( 'any' === $selections[ $slot ]['kind'] ) {
			return '';
		}

		return (string) $selections[ $slot ]['value'];
	}

	/*
	 * =======================================================================
	 * MATCHING — A TAP AND A TYPED ANSWER MUST BE INDISTINGUISHABLE
	 * =======================================================================
	 */

	/**
	 * Match a message against the options legal at this step.
	 *
	 * A chip only prefills the input; the customer can type the same word
	 * instead, misspell it, singularise it or answer in the other language. So
	 * this deliberately does NOT compare against the exact chip string. It
	 * folds both sides through the same normalizer the lookup engine uses, then
	 * accepts an exact match, a match on the term slug, or a whole-phrase
	 * containment for multi-word labels.
	 *
	 * Containment is whole-phrase and bounded by spaces on both sides, so
	 * "helmets" matches the Helmets option but "helmet camera mount" does not
	 * quietly answer the category question with an accessory query.
	 *
	 * @param string $message    Message.
	 * @param int    $step       Step.
	 * @param array  $selections Selections so far.
	 * @param string $lang       Flow language.
	 * @return array|null The chosen option, or null when nothing matched.
	 */
	private function match_option( $message, $step, array $selections, $lang ) {
		$needle = $this->normalize( $message );

		if ( '' === $needle ) {
			return null;
		}

		/*
		 * Detect the ellipsis on the RAW message, before normalize() folds it
		 * away. It is the only evidence that what arrived is a shortened label
		 * rather than something the customer typed in full, and that distinction
		 * is what makes prefix matching below safe to switch on: an unsolicited
		 * "hel" must never select "Helmets", but a tapped "Helmet Visors &
		 * Replacement…" must select the brand it was cut from.
		 *
		 * ------------------------------------------------------------------
		 * THIS IS HALF OF A TWO-FILE CONTRACT. The other half is in
		 * assets/chatbot.js, chipLabels(), which carries the matching
		 * do-not-touch block.
		 *
		 * The trailing ellipsis is a PROTOCOL MARKER, not decoration. The
		 * widget must send the tapped label back byte-for-byte, trimming
		 * whitespace and nothing else. Anything that "tidies" it on the way
		 * out — normalising U+2026 to "..." or the reverse, stripping trailing
		 * punctuation, a Unicode normalisation pass added for some unrelated
		 * reason — removes the marker, this gate never opens, and every
		 * truncated chip silently becomes a tap that resolves to nothing while
		 * typing the full name still works. That exact split was a live bug in
		 * Aug 2026; it is fixed, and it is cheap to reintroduce from a file
		 * that has no obvious connection to this one.
		 *
		 * Both spellings are accepted here precisely so a client that emits
		 * "..." instead of U+2026 still works. Do not narrow this to one.
		 * ------------------------------------------------------------------
		 */
		$truncated = 1 === preg_match( '/(\x{2026}|\.\.\.)\s*$/u', (string) $message );

		$options = $this->options_for( $step, $selections, $lang );

		// Exact first, across all options, so a short label can never be
		// beaten by a longer one that merely contains it.
		foreach ( $options as $option ) {
			foreach ( $this->match_forms( $option ) as $form ) {
				if ( $form === $needle ) {
					return $this->as_selection( $option );
				}
			}
		}

		foreach ( $options as $option ) {
			foreach ( $this->match_forms( $option ) as $form ) {
				if ( '' === $form ) {
					continue;
				}

				if ( false !== strpos( ' ' . $needle . ' ', ' ' . $form . ' ' ) ) {
					return $this->as_selection( $option );
				}
			}
		}

		/*
		 * Last: an announced truncation matches as a prefix. Gated on the
		 * ellipsis so it cannot fire for ordinary typing. This is what keeps
		 * "tapping equals typing" true for exactly the labels that were
		 * shortened — the subset where it would otherwise break, and the subset
		 * nobody would think to test by hand.
		 *
		 * Matching the remainder rather than regenerating the shortened form
		 * also means we stay correct if the cap changes between the turn that
		 * drew the chip and the turn that receives it.
		 */
		if ( $truncated ) {
			foreach ( $options as $option ) {
				foreach ( $this->match_forms( $option ) as $form ) {
					if ( '' === $form || '' === $needle ) {
						continue;
					}

					if ( 0 === strpos( $form, $needle ) ) {
						return $this->as_selection( $option );
					}
				}
			}
		}

		return null;
	}

	/**
	 * Normalized forms that count as choosing an option.
	 *
	 * @param array $option Option.
	 * @return string[]
	 */
	private function match_forms( array $option ) {
		$forms = array();

		$candidates = isset( $option['match'] ) && is_array( $option['match'] ) ? $option['match'] : array();
		$candidates[] = isset( $option['label'] ) ? $option['label'] : '';

		/*
		 * The DISPLAYED form of the label, because that is what a tap actually
		 * sends. Kept as an exact fast path so a chip we rendered ourselves
		 * resolves in the exact-match pass, before any prefix reasoning.
		 *
		 * BE ACCURATE ABOUT WHAT THIS IS WORTH. It is NOT independent
		 * redundancy alongside the ellipsis-gated prefix match in
		 * match_option(); that was claimed here once and mutation testing
		 * disproved it. Measured, with each mechanism removed in turn:
		 *
		 *   client cut == ours          both mechanisms resolve it
		 *   client cut at another size  ONLY prefix matching resolves it
		 *
		 * So prefix matching subsumes this line entirely, and prefix matching
		 * is the one that survives the realistic future change — a frontend
		 * that starts truncating at its own cap. This line is a fast path and
		 * a precedence guarantee, nothing more. If you are deciding what must
		 * not be broken, it is the prefix match in match_option().
		 */
		$candidates[] = $this->chip_label( isset( $option['label'] ) ? $option['label'] : '' );

		foreach ( $candidates as $candidate ) {
			$form = $this->normalize( (string) $candidate );

			if ( '' === $form ) {
				continue;
			}

			$forms[ $form ] = true;

			// Fold a trailing plural so "helmet" answers "Helmets".
			if ( strlen( $form ) > 3 && 's' === substr( $form, -1 ) ) {
				$forms[ substr( $form, 0, -1 ) ] = true;
			} else {
				$forms[ $form . 's' ] = true;
			}
		}

		return array_keys( $forms );
	}

	/**
	 * Reduce an option to what gets recorded in the state.
	 *
	 * @param array $option Option.
	 * @return array{kind:string,value:string,label:string}
	 */
	private function as_selection( array $option ) {
		return array(
			'kind'  => (string) $option['kind'],
			'value' => (string) $option['value'],
			'label' => (string) $option['label'],
		);
	}

	/*
	 * =======================================================================
	 * TRIGGERS
	 * =======================================================================
	 */

	/**
	 * Does this message ask for guidance rather than for a specific thing?
	 *
	 * The trigger phrase alone is not enough. "Do you recommend the Shinko 705
	 * for wet roads?" contains "recommend", but the customer named a product —
	 * the lookup engine can search for that and will do a better job than three
	 * rounds of chips. So the finder engages only when the message carries a
	 * trigger AND no other content: trigger words and stopwords removed, what
	 * is left must be empty.
	 *
	 * That single rule is what stops the finder hijacking real questions, and
	 * it is why the stopword list is borrowed from the lookup engine rather
	 * than written again here — two lists would drift and the drift would show
	 * up as the finder eating traffic it should have passed on.
	 *
	 * @param string $message Message.
	 * @return bool
	 */
	private function is_trigger( $message ) {
		$normalized = $this->normalize( $message );

		if ( '' === $normalized ) {
			return false;
		}

		$haystack = ' ' . $normalized . ' ';
		$matched  = '';

		foreach ( $this->triggers() as $trigger ) {
			$trigger = $this->normalize( $trigger );

			if ( '' === $trigger ) {
				continue;
			}

			if ( false !== strpos( $haystack, ' ' . $trigger . ' ' ) && strlen( $trigger ) > strlen( $matched ) ) {
				$matched = $trigger;
			}
		}

		if ( '' === $matched ) {
			return false;
		}

		$residual = ' ' . trim( str_replace( ' ' . $matched . ' ', ' ', $haystack ) ) . ' ';

		/*
		 * Strip, in order: any other trigger word still present, the lookup
		 * engine's stopwords, and the fillers below. Repeat until nothing more
		 * comes off, because removing a word can bring two others adjacent
		 * ("recommend me some good gear" -> "me some good gear" -> "").
		 */
		$strip = array_merge( $this->triggers(), $this->stopwords(), $this->fillers() );

		do {
			$before = $residual;

			foreach ( $strip as $word ) {
				$word = $this->normalize( $word );

				if ( '' === $word ) {
					continue;
				}

				$residual = str_replace( ' ' . $word . ' ', ' ', $residual );
			}

			$residual = ' ' . trim( (string) preg_replace( '/\s+/u', ' ', $residual ) ) . ' ';
		} while ( $residual !== $before );

		// Anything left is a specific the engine should search for instead.
		return '' === trim( $residual );
	}

	/**
	 * Words that carry no search value in a request for guidance.
	 *
	 * Kept apart from the engine's stopwords deliberately. These are only ever
	 * used to decide whether a message is a *bare* request for help, never to
	 * build a query, so "something" and "barang" belong here and would be
	 * actively wrong in the engine's list, where they can be the whole subject
	 * of a question.
	 *
	 * @return string[]
	 */
	private function fillers() {
		$fillers = array(
			'something', 'anything', 'some', 'stuff', 'gear', 'item', 'items',
			'product', 'products', 'thing', 'things', 'good', 'best', 'nice',
			'buy', 'shop', 'shopping', 'looking', 'look', 'idea', 'ideas',
			'options', 'option', 'choose', 'choice', 'me', 'us', 'anyone',
			'sesuatu', 'barang', 'barangan', 'benda', 'bagus', 'best', 'beli',
			'pilihan', 'idea', 'apa-apa', 'apa', 'nak',
		);

		/**
		 * Filter the filler words ignored when deciding if a message is a bare
		 * request for guidance.
		 *
		 * @param string[] $fillers Lowercase words.
		 */
		return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'db_chat_finder_fillers', $fillers ) ) ) );
	}

	/**
	 * Phrases that open the guided flow, English and Bahasa Malaysia.
	 *
	 * @return string[]
	 */
	private function triggers() {
		$triggers = array_merge(
			array(
				'recommend', 'recommendation', 'recommendations', 'suggest', 'suggestion',
				'suggestions', 'help me find', 'help me find something', 'help me choose',
				'help me pick', 'find me something', 'show me something', 'what should i buy',
				'what do you recommend', 'product finder', 'browse', 'not sure what',
				'i dont know what',
			),
			self::malay_triggers()
		);

		/**
		 * Filter the phrases that open the guided product finder.
		 *
		 * A phrase here is matched whole and only when the rest of the message
		 * is empty after stopwords, so adding a common word is safer than it
		 * looks — but it will still shadow that word for the lookup engine when
		 * typed on its own.
		 *
		 * @param string[] $triggers Lowercase trigger phrases.
		 */
		return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'db_chat_finder_triggers', $triggers ) ) ) );
	}

	/**
	 * The Bahasa Malaysia trigger phrases.
	 *
	 * Held separately because they do double duty: they open the flow AND they
	 * are the strongest possible evidence the customer is writing in Malay.
	 * The lookup engine's marker list does not contain them — it is tuned for
	 * the questions that engine actually receives, and "cadangkan" is not one
	 * of them, because before this class existed there was nothing to ask for.
	 * Detecting language from the engine's markers alone therefore answered
	 * "cadangkan" in English, which is the first thing a Malay-speaking
	 * customer would see and the last thing they should.
	 *
	 * @return string[]
	 */
	private static function malay_triggers() {
		return array(
			'cadangkan', 'cadangan', 'tolong cari', 'tolong carikan', 'carikan',
			'bantu saya cari', 'bantu cari', 'nak cari sesuatu', 'apa yang best',
			'apa yang bagus', 'tak pasti nak beli apa', 'pilihkan', 'sila cadangkan',
		);
	}

	/*
	 * =======================================================================
	 * RENDERING
	 * =======================================================================
	 */

	/**
	 * Build the reply for a step.
	 *
	 * @param int    $step       Step to render.
	 * @param array  $selections Selections so far.
	 * @param string $lang       Flow language.
	 * @return array{reply:string,products:array,pages:array,chips:array}
	 */
	private function render( $step, array $selections, $lang ) {
		if ( self::STEP_RESULTS === $step ) {
			return $this->render_results( $selections, $lang );
		}

		$options = $this->options_for( $step, $selections, $lang );

		if ( empty( $options ) ) {
			return $this->render_results( $selections, $lang );
		}

		$reply = $this->question( $step, $lang ) . "\n\n" . $this->hint( $lang );

		return $this->payload( $reply, array(), $this->chips_from( $options ) );
	}

	/**
	 * Build the results reply.
	 *
	 * @param array  $selections Selections.
	 * @param string $lang       Flow language.
	 * @return array
	 */
	private function render_results( array $selections, $lang ) {
		$category = $this->selected_value( $selections, 'category' );
		$brand    = $this->selected_value( $selections, 'brand' );
		$band     = $this->selected_band( $selections );

		$cards = array();

		foreach ( $this->probe( $category, $brand ) as $product ) {
			if ( null !== $band && ! $this->in_band( $this->price_of( $product ), $band ) ) {
				continue;
			}

			$card = $this->card( $product );

			if ( null === $card ) {
				continue;
			}

			$cards[] = $card;

			if ( count( $cards ) >= self::MAX_CARDS ) {
				break;
			}
		}

		if ( empty( $cards ) ) {
			/*
			 * Should be unreachable: every option offered was read off a
			 * buyable product. Reachable anyway if the last unit sold between
			 * the question and the answer, which on a live store happens.
			 */
			return $this->payload( $this->text( 'none', $lang ), array(), array() );
		}

		return $this->payload( $this->results_intro( $selections, $lang ), $cards, array() );
	}

	/**
	 * The band a selection refers to.
	 *
	 * @param array $selections Selections.
	 * @return array|null
	 */
	private function selected_band( array $selections ) {
		$id = $this->selected_value( $selections, 'budget' );

		if ( '' === $id ) {
			return null;
		}

		foreach ( $this->bands() as $band ) {
			if ( $band['id'] === $id ) {
				return $band;
			}
		}

		return null;
	}

	/**
	 * Sentence introducing the results, naming what was actually filtered on.
	 *
	 * @param array  $selections Selections.
	 * @param string $lang       Flow language.
	 * @return string
	 */
	private function results_intro( array $selections, $lang ) {
		$parts = array();

		foreach ( array( 'brand', 'category', 'budget' ) as $slot ) {
			if ( isset( $selections[ $slot ] ) && 'any' !== $selections[ $slot ]['kind'] && '' !== $selections[ $slot ]['label'] ) {
				$parts[] = $selections[ $slot ]['label'];
			}
		}

		if ( empty( $parts ) ) {
			return $this->text( 'results_plain', $lang );
		}

		return sprintf( $this->text( 'results_filtered', $lang ), implode( ', ', $parts ) );
	}

	/**
	 * Assemble the REST payload.
	 *
	 * The one place a finder reply leaves this class, so the one place that has
	 * to guarantee the contract: plain-text reply, arrays for products, pages
	 * and chips even when empty, and chips capped and stripped.
	 *
	 * @param string $reply    Reply text.
	 * @param array  $products Product cards.
	 * @param array  $chips    Chip labels.
	 * @return array{reply:string,products:array,pages:array,chips:array}
	 */
	private function payload( $reply, array $products, array $chips ) {
		return array(
			'reply'    => $this->plain_text( $reply ),
			'products' => array_values( $products ),
			'pages'    => array(),
			'chips'    => $this->sanitize_chips( $chips ),
		);
	}

	/**
	 * Chip labels from options.
	 *
	 * @param array $options Options.
	 * @return string[]
	 */
	private function chips_from( array $options ) {
		$chips = array();

		foreach ( $options as $option ) {
			$chips[] = (string) $option['label'];
		}

		return $chips;
	}

	/**
	 * Enforce the chips contract: flat strings, stripped, deduped, capped.
	 *
	 * @param array $chips Raw labels.
	 * @return string[]
	 */
	private function sanitize_chips( array $chips ) {
		$clean = array();

		foreach ( $chips as $chip ) {
			if ( ! is_scalar( $chip ) ) {
				continue;
			}

			/*
			 * Truncate BEFORE building the dedupe key, not after. Keying on the
			 * full label and then shortening it lets two labels that differ
			 * only past the cap both survive and then render as visibly
			 * identical chips — two taps that look the same and do different
			 * things, which is worse than dropping one.
			 */
			$label = $this->chip_label( (string) $chip );

			if ( '' === $label || isset( $clean[ $label ] ) ) {
				continue;
			}

			$clean[ $label ] = true;

			if ( count( $clean ) >= self::MAX_CHIPS ) {
				break;
			}
		}

		return array_keys( $clean );
	}

	/**
	 * The displayed form of a label.
	 *
	 * THE REASON THIS IS A NAMED METHOD RATHER THAN AN INLINE mb_substr():
	 * in this contract the chip label IS the value. Tapping a chip sends the
	 * label back verbatim as an ordinary message, which is what makes the flow
	 * stateless. So the moment a label is shortened for display, the shortened
	 * string is what the matcher will receive — and if the matcher only knows
	 * the full name, the chip becomes untappable. That was a live bug: a brand
	 * called "Helmet Visors & Replacement Parts Accessories" rendered as a chip
	 * that returned null when tapped, i.e. a dead end, in the one class whose
	 * whole job is not having dead ends. Display and matching now go through
	 * this one method, so they cannot drift apart again.
	 *
	 * Cut on a word boundary. A hard cut mid-word ("...Fitting Ki") at least
	 * announces itself as broken and the customer taps it anyway; a hard cut
	 * that happens to land on a boundary reads as a complete, legitimate name
	 * and the customer has no way to know it is not. The ellipsis makes the
	 * truncation visible in both cases, and normalize() drops it again on the
	 * way back in because it is neither a letter nor a number.
	 *
	 * @param string $label Raw label.
	 * @return string
	 */
	private function chip_label( $label ) {
		$label = trim( $this->plain_text( (string) $label ) );

		if ( '' === $label || mb_strlen( $label ) <= self::MAX_CHIP_CHARS ) {
			return $label;
		}

		// One character of room for the ellipsis.
		$cut  = mb_substr( $label, 0, self::MAX_CHIP_CHARS - 1 );
		$stop = mb_strrpos( $cut, ' ' );

		// Only honour the word boundary if it leaves something readable;
		// a first word longer than the cap still has to be cut somewhere.
		if ( false !== $stop && $stop >= (int) floor( self::MAX_CHIP_CHARS / 2 ) ) {
			$cut = mb_substr( $cut, 0, $stop );
		}

		return rtrim( $cut ) . "\xE2\x80\xA6";
	}

	/*
	 * =======================================================================
	 * PRODUCT QUERIES
	 * =======================================================================
	 */

	/**
	 * Buyable products in a branch.
	 *
	 * DB_Chat_Product_Index::search_products() is deliberately NOT reused here.
	 * It has a "last resort" fallback that drops the category and brand filters
	 * when a filtered search returns nothing, which is right for a keyword
	 * search — something is better than nothing — and catastrophic for this
	 * class, where an empty branch that answers with arbitrary products is
	 * precisely the failure the finder exists to avoid. So the finder runs its
	 * own query, with no fallbacks of any kind.
	 *
	 * Two samples are taken, cheapest-first and dearest-first, because a single
	 * price-ordered page of 40 out of thousands would make the top budget band
	 * look empty when it is not.
	 *
	 * @param string $category Category slug, '' for any.
	 * @param string $brand    Brand slug, '' for any.
	 * @return WC_Product[]
	 */
	private function probe( $category, $brand ) {
		$key    = 'probe:' . $category . '|' . $brand;
		$cached = $this->branch_get( $key );

		if ( is_array( $cached ) ) {
			return $this->hydrate( $cached );
		}

		$products = array();

		foreach ( array( 'ASC' => self::PROBE_LIMIT, 'DESC' => self::PROBE_TAIL ) as $order => $limit ) {
			foreach ( $this->query( $category, $brand, $order, $limit ) as $product ) {
				$products[ $product->get_id() ] = $product;
			}
		}

		$ids = array();

		foreach ( $products as $id => $product ) {
			$ids[] = (int) $id;
			unset( $product );
		}

		// Only IDs are cached. Caching WC_Product objects in a transient
		// serialises a stale snapshot of price and stock into the database.
		$this->branch_set( $key, $ids );

		return array_values( $products );
	}

	/**
	 * Turn cached IDs back into products, re-checking buyability.
	 *
	 * The cache says "these were buyable an hour ago". Stock and price are
	 * re-read from the live object here, so a sold-out product cannot be
	 * offered on the strength of a cache entry.
	 *
	 * @param array $ids Product IDs.
	 * @return WC_Product[]
	 */
	private function hydrate( array $ids ) {
		$products = array();

		foreach ( $ids as $id ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $id ) : null;

			if ( $product instanceof WC_Product && $this->is_buyable( $product ) ) {
				$products[] = $product;
			}
		}

		return $products;
	}

	/**
	 * One wc_get_products() call, filtered down to what a customer can buy.
	 *
	 * @param string $category Category slug.
	 * @param string $brand    Brand slug.
	 * @param string $order    ASC or DESC by price.
	 * @param int    $limit    Row limit.
	 * @return WC_Product[]
	 */
	private function query( $category, $brand, $order, $limit ) {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$args = array(
			'status'       => 'publish',
			'visibility'   => 'visible',
			'stock_status' => 'instock',
			'limit'        => (int) $limit,
			'return'       => 'objects',
			'orderby'      => 'price',
			'order'        => 'DESC' === $order ? 'DESC' : 'ASC',
		);

		if ( '' !== $category ) {
			$args['category'] = array( $category );
		}

		$filter = null;

		$taxonomy = $this->index->get_brand_taxonomy();

		if ( '' !== $brand && '' !== $taxonomy ) {
			/*
			 * wc_get_products() has no brand argument. Inject a tax_query
			 * through the documented data-store filter and remove it in a
			 * finally block, so it can never leak into another query on this
			 * request even if the query throws. Same approach as the index.
			 */
			$filter = static function ( $wp_query_args ) use ( $taxonomy, $brand ) {
				if ( ! isset( $wp_query_args['tax_query'] ) || ! is_array( $wp_query_args['tax_query'] ) ) {
					$wp_query_args['tax_query'] = array();
				}

				$wp_query_args['tax_query'][] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'slug',
					'terms'    => array( $brand ),
					'operator' => 'IN',
				);

				return $wp_query_args;
			};

			add_filter( 'woocommerce_product_data_store_cpt_get_products_query', $filter, 99 );
		}

		try {
			$products = wc_get_products( $args );
		} catch ( Throwable $e ) {
			if ( class_exists( 'DB_Chat_Plugin' ) && is_callable( array( 'DB_Chat_Plugin', 'log_error' ) ) ) {
				DB_Chat_Plugin::log_error( 'Finder query failed: ' . $e->getMessage() );
			}

			$products = array();
		} finally {
			if ( null !== $filter ) {
				remove_filter( 'woocommerce_product_data_store_cpt_get_products_query', $filter, 99 );
			}
		}

		$buyable = array();

		if ( is_array( $products ) ) {
			foreach ( $products as $product ) {
				if ( $product instanceof WC_Product && $this->is_buyable( $product ) ) {
					$buyable[] = $product;
				}
			}
		}

		return $buyable;
	}

	/**
	 * Can a customer actually put this in a basket today?
	 *
	 * Published, visible, in stock, and priced. The price test is what excludes
	 * the zero-variation import artifacts even if one of them ever came back
	 * in stock: with no variations there is no price, and a chip leading to a
	 * product nobody can buy is the dead end this whole class is built to
	 * avoid. No stock_quantity is read — in stock or not is the whole question.
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	private function is_buyable( WC_Product $product ) {
		if ( 'publish' !== $product->get_status() ) {
			return false;
		}

		if ( is_callable( array( $product, 'get_catalog_visibility' ) ) ) {
			$visibility = (string) $product->get_catalog_visibility();

			if ( ! in_array( $visibility, array( 'visible', 'catalog', 'search' ), true ) ) {
				return false;
			}
		}

		if ( ! $product->is_in_stock() ) {
			return false;
		}

		// A variable product with no variations: the Shopify import artifact.
		if ( $product->is_type( 'variable' ) && empty( $product->get_children() ) ) {
			return false;
		}

		return $this->price_of( $product ) > 0.0;
	}

	/**
	 * Numeric price used for banding.
	 *
	 * @param WC_Product $product Product.
	 * @return float 0.0 when there is no usable price.
	 */
	private function price_of( WC_Product $product ) {
		$price = $product->get_price();

		if ( '' === $price || null === $price || ! is_numeric( $price ) ) {
			return 0.0;
		}

		return (float) $price;
	}

	/**
	 * Frontend product card.
	 *
	 * Same five keys the rest of the plugin emits — name, url, price, image,
	 * in_stock — rebuilt from the WC_Product every time. The price string comes
	 * from the index's formatter so MYR formatting lives in one place.
	 *
	 * @param WC_Product $product Product.
	 * @return array|null
	 */
	private function card( WC_Product $product ) {
		if ( ! $this->is_buyable( $product ) ) {
			return null;
		}

		$image_url = '';
		$image_id  = (int) $product->get_image_id();

		if ( $image_id > 0 && function_exists( 'wp_get_attachment_image_url' ) ) {
			// Registered size name, never hardcoded pixel dimensions.
			$src = wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' );

			if ( is_string( $src ) ) {
				$image_url = $src;
			}
		}

		return array(
			'name'     => wp_strip_all_tags( $product->get_name() ),
			'url'      => esc_url_raw( (string) $product->get_permalink() ),
			'price'    => $this->index->get_price_text( $product ),
			'image'    => '' !== $image_url ? esc_url_raw( $image_url ) : '',
			'in_stock' => true,
		);
	}

	/*
	 * =======================================================================
	 * BRANCH CACHE
	 * =======================================================================
	 */

	/**
	 * Read a cached branch value.
	 *
	 * @param string $key Branch key.
	 * @return mixed Null when absent.
	 */
	private function branch_get( $key ) {
		if ( null === $this->branches ) {
			$cached = get_transient( self::TRANSIENT );

			$this->branches = is_array( $cached ) ? $cached : array();
		}

		return isset( $this->branches[ $key ] ) ? $this->branches[ $key ] : null;
	}

	/**
	 * Cache a branch value and persist the tree.
	 *
	 * @param string $key   Branch key.
	 * @param mixed  $value Value.
	 * @return void
	 */
	private function branch_set( $key, $value ) {
		if ( null === $this->branches ) {
			$this->branch_get( $key );
		}

		$this->branches[ $key ] = $value;

		// Bound the tree: a filter could otherwise let it grow per category
		// per brand until the option row is large enough to hurt.
		if ( count( $this->branches ) > 200 ) {
			$this->branches = array_slice( $this->branches, -100, null, true );
		}

		set_transient( self::TRANSIENT, $this->branches, self::CACHE_TTL );
	}

	/**
	 * Drop the cached option tree.
	 *
	 * @return void
	 */
	public static function flush_cache() {
		delete_transient( self::TRANSIENT );
	}

	/*
	 * =======================================================================
	 * TEXT
	 * =======================================================================
	 */

	/**
	 * The question asked at a step.
	 *
	 * These strings are load-bearing: resolve_state() identifies which step a
	 * transcript reached by matching an assistant turn against them. Changing
	 * one invalidates flows in progress, which degrade to the lookup engine
	 * rather than breaking — but do not translate them dynamically per request.
	 *
	 * @param int    $step Step.
	 * @param string $lang Flow language.
	 * @return string
	 */
	private function question( $step, $lang ) {
		$questions = array(
			self::STEP_CATEGORY => array(
				'en' => 'What are you looking for?',
				'ms' => 'Apa yang anda cari?',
			),
			self::STEP_BRAND    => array(
				'en' => 'Which brand?',
				'ms' => 'Jenama mana?',
			),
			self::STEP_BUDGET   => array(
				'en' => "What's your budget?",
				'ms' => 'Berapa bajet anda?',
			),
		);

		return isset( $questions[ $step ][ $lang ] ) ? $questions[ $step ][ $lang ] : '';
	}

	/**
	 * Is this assistant turn the question for this step?
	 *
	 * Compared on the first line only, normalized. The hint line underneath is
	 * free to be reworded or translated without invalidating flows that are
	 * already in progress.
	 *
	 * @param string $content Assistant turn.
	 * @param int    $step    Step.
	 * @param string $lang    Flow language.
	 * @return bool
	 */
	private function is_question_for( $content, $step, $lang ) {
		$expected = $this->normalize( $this->question( $step, $lang ) );

		if ( '' === $expected ) {
			return false;
		}

		$lines = preg_split( '/\R/u', (string) $content );
		$first = is_array( $lines ) && isset( $lines[0] ) ? $lines[0] : (string) $content;

		return $this->normalize( $first ) === $expected;
	}

	/**
	 * The line under each question telling the customer they are not trapped.
	 *
	 * @param string $lang Flow language.
	 * @return string
	 */
	private function hint( $lang ) {
		return $this->text( 'hint', $lang );
	}

	/**
	 * Finder copy.
	 *
	 * English goes through __() so a translation file can replace it. Malay is
	 * literal, because the store runs an en_US locale with no .mo file and
	 * __() would hand back the English source — the point of answering in Malay
	 * is that it works on this store, today.
	 *
	 * @param string $key  Copy key.
	 * @param string $lang Flow language.
	 * @return string
	 */
	private function text( $key, $lang ) {
		$en = array(
			'hint'             => __( 'Tap an option, or just type your question if you would rather ask something else.', 'durianbikers-chatbot' ),
			'results_plain'    => __( 'Here are a few things we have in stock:', 'durianbikers-chatbot' ),
			'results_filtered' => __( 'Here is what we have in stock for %s:', 'durianbikers-chatbot' ),
			'none'             => __( 'Nothing in stock matches that combination right now. Try a different budget, or type the name of what you are after.', 'durianbikers-chatbot' ),
		);

		$ms = array(
			'hint'             => 'Tekan satu pilihan, atau taip soalan anda jika anda mahu tanya perkara lain.',
			'results_plain'    => 'Ini beberapa barang yang ada dalam stok:',
			'results_filtered' => 'Ini yang kami ada dalam stok untuk %s:',
			'none'             => 'Tiada stok yang sepadan dengan pilihan itu buat masa ini. Cuba bajet lain, atau taip nama barang yang anda cari.',
		);

		$set = ( 'ms' === $lang ) ? array_merge( $en, $ms ) : $en;

		/**
		 * Filter the guided finder copy.
		 *
		 * @param array  $set  Copy strings.
		 * @param string $lang Flow language, 'en' or 'ms'.
		 */
		$set = (array) apply_filters( 'db_chat_finder_text', $set, $lang );

		return isset( $set[ $key ] ) ? (string) $set[ $key ] : '';
	}

	/**
	 * Human label for a budget band.
	 *
	 * @param array  $band Band.
	 * @param string $lang Flow language.
	 * @return string
	 */
	private function band_label( array $band, $lang ) {
		if ( null === $band['min'] ) {
			return 'ms' === $lang
				? 'Bawah ' . $this->money( $band['max'] )
				: 'Under ' . $this->money( $band['max'] );
		}

		if ( null === $band['max'] ) {
			return 'ms' === $lang
				? $this->money( $band['min'] ) . ' ke atas'
				: $this->money( $band['min'] ) . '+';
		}

		return $this->money( $band['min'] ) . self::dash() . $this->money( $band['max'] );
	}

	/**
	 * Extra typed forms that select a budget band.
	 *
	 * A customer typing "under 200" or "bawah rm200" must land on the same band
	 * as the chip, so the band carries its own spelling variants.
	 *
	 * @param array  $band Band.
	 * @param string $lang Flow language.
	 * @return string[]
	 */
	private function band_synonyms( array $band, $lang ) {
		$forms = array( $this->band_label( $band, 'en' ), $this->band_label( $band, 'ms' ) );

		unset( $lang );

		if ( null === $band['min'] ) {
			$max     = $this->plain_number( $band['max'] );
			$forms[] = 'under ' . $max;
			$forms[] = 'under rm' . $max;
			$forms[] = 'below ' . $max;
			$forms[] = 'bawah ' . $max;
			$forms[] = 'bawah rm' . $max;
			$forms[] = 'kurang ' . $max;

			return $forms;
		}

		$min = $this->plain_number( $band['min'] );

		if ( null === $band['max'] ) {
			$forms[] = 'over ' . $min;
			$forms[] = 'above ' . $min;
			$forms[] = 'rm' . $min . ' plus';
			$forms[] = 'atas ' . $min;
			$forms[] = $min . ' ke atas';

			return $forms;
		}

		$max     = $this->plain_number( $band['max'] );
		$forms[] = $min . ' ' . $max;
		$forms[] = 'rm' . $min . ' rm' . $max;
		$forms[] = $min . ' to ' . $max;
		$forms[] = $min . ' hingga ' . $max;

		return $forms;
	}

	/**
	 * En dash, written as bytes so the file stays ASCII-safe in transit.
	 *
	 * @return string
	 */
	private static function dash() {
		return "\xE2\x80\x93";
	}

	/**
	 * Format an amount as a chip-sized MYR string, e.g. "RM200".
	 *
	 * Deliberately not wc_price(): this is a band boundary, not a product
	 * price, and it must stay short enough to read on a chip.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	private function money( $amount ) {
		return 'RM' . $this->plain_number( $amount );
	}

	/**
	 * A band boundary without decimals or separators.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	private function plain_number( $amount ) {
		return (string) (int) round( (float) $amount );
	}

	/*
	 * =======================================================================
	 * NORMALIZATION
	 * =======================================================================
	 */

	/**
	 * Fold a string to its comparison form.
	 *
	 * Lowercased, punctuation dropped, whitespace collapsed, and each token run
	 * through the lookup engine's spelling variants when that class is present,
	 * so SMS-style Malay and British/American spellings fold the same way for
	 * the finder as they do for the engine. Sharing the table is the point:
	 * two normalizers would drift, and the drift would show up as chips that
	 * only work when tapped.
	 *
	 * @param string $value Raw string.
	 * @return string
	 */
	private function normalize( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );

		// Currency and separators become spaces so "rm200-500" tokenises.
		$value = (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $value );
		$value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );

		if ( '' === $value ) {
			return '';
		}

		$variants = $this->variants();

		if ( empty( $variants ) ) {
			return $value;
		}

		$tokens = explode( ' ', $value );

		foreach ( $tokens as $i => $token ) {
			if ( isset( $variants[ $token ] ) ) {
				$tokens[ $i ] = $variants[ $token ];
			}
		}

		return implode( ' ', $tokens );
	}

	/**
	 * Spelling variants borrowed from the lookup engine.
	 *
	 * @return array<string,string>
	 */
	private function variants() {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$cache = array();

		if ( class_exists( 'DB_Chat_Lookup_Engine' ) && is_callable( array( 'DB_Chat_Lookup_Engine', 'variants' ) ) ) {
			$variants = DB_Chat_Lookup_Engine::variants();

			if ( is_array( $variants ) ) {
				$cache = $variants;
			}
		}

		return $cache;
	}

	/**
	 * Stopwords borrowed from the lookup engine.
	 *
	 * @return string[]
	 */
	private function stopwords() {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$cache = array();

		if ( class_exists( 'DB_Chat_Lookup_Engine' ) && is_callable( array( 'DB_Chat_Lookup_Engine', 'stopwords' ) ) ) {
			$stopwords = DB_Chat_Lookup_Engine::stopwords();

			if ( is_array( $stopwords ) ) {
				$cache = array_map( 'strval', $stopwords );
			}
		}

		return $cache;
	}

	/**
	 * English or Malay?
	 *
	 * The marker list is the lookup engine's, read through its public static
	 * accessor rather than copied, so the db_chat_lookup_malay_markers filter
	 * keeps steering both. Only the detection rule is restated here, because
	 * the engine's own detect_language() is private.
	 *
	 * @param string $message Raw message.
	 * @return string 'en' or 'ms'.
	 */
	private function detect_language( $message ) {
		$normalized = $this->normalize( $message );
		$haystack   = ' ' . $normalized . ' ';

		// A Malay trigger is decisive on its own — see malay_triggers().
		foreach ( self::malay_triggers() as $trigger ) {
			$trigger = $this->normalize( $trigger );

			if ( '' !== $trigger && false !== strpos( $haystack, ' ' . $trigger . ' ' ) ) {
				return 'ms';
			}
		}

		/*
		 * Otherwise defer to the lookup engine's own detector, which became
		 * public static in Aug 2026 specifically so this class could stop
		 * restating the rule. One detector, so the two can no longer disagree
		 * about the same sentence.
		 *
		 * It is given the NORMALISED form deliberately: it does a defensive
		 * lowercase/de-punctuate pass of its own, but it does not fold spelling
		 * variants, so raw "brape hrga" reads as English there while our
		 * normalised "berapa harga" reads as Malay. Normalising first is what
		 * makes the two agree exactly.
		 */
		if ( class_exists( 'DB_Chat_Lookup_Engine' ) && is_callable( array( 'DB_Chat_Lookup_Engine', 'detect_language' ) ) ) {
			try {
				return 'ms' === DB_Chat_Lookup_Engine::detect_language( $normalized ) ? 'ms' : 'en';
			} catch ( Throwable $e ) {
				return 'en';
			}
		}

		return 'en';
	}

	/**
	 * Reduce text to safe plain text for a chat bubble.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function plain_text( $text ) {
		$text = wp_strip_all_tags( (string) $text, false );

		$stripped = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );

		if ( is_string( $stripped ) ) {
			$text = $stripped;
		}

		$text = (string) preg_replace( '/\r\n?/', "\n", $text );
		$text = (string) preg_replace( '/[^\S\n]+/u', ' ', $text );
		$text = (string) preg_replace( '/ *\n */u', "\n", $text );

		return trim( $text );
	}
}
