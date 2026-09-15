<?php
/**
 * REST endpoint for the chatbot.
 *
 * POST /wp-json/durianbikers/v1/chat
 *
 * Public route (guests must be able to chat) but nonce-gated and rate limited.
 * It touches exactly five things: the product retrieval service, the allowlisted
 * help page retrieval service, the order lookup service, the Anthropic client,
 * and the free keyword lookup engine.
 *
 * TWO BRAINS, ONE CONTRACT. Which one answers is decided by
 * DB_Chat_Plugin::get_engine() (option db_chat_mode: auto / lookup / ai / hybrid)
 * and by nothing else, so the enqueue gate and this handler can never disagree.
 * Both produce the SAME response shape — { reply, products[], pages[] } — so
 * nonce handling, rate limiting, the session route, the WhatsApp handoff and the
 * widget itself are identical either way. Lookup mode needs no API key and
 * makes no network call; it is NOT a model and does not understand language.
 * Hybrid mode runs lookup first and sends only its genuine misses to Claude,
 * within the monthly AI budget — see maybe_answer_with_ai().
 *
 * PERSONAL DATA BOUNDARY. The order lookup is the only path from here to
 * personal data, and it is narrow by construction:
 *
 *   - An anonymous visitor reaches nothing. Identity comes from
 *     get_current_user_id() inside DB_Chat_Order_Lookup, and 0 means "not
 *     signed in" full stop — the route never passes an identifier of any kind
 *     into the lookup, because the tool has no identifier parameter to pass.
 *   - A signed-in customer reaches only their own orders, re-checked per order.
 *   - Nothing about an order is cached or logged. When the order tool ran, this
 *     request's response is sent no-store and the conversation log withholds the
 *     exchange entirely, even with db_chat_logging on.
 *

 * @package DurianBikers_Chatbot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles the chat route.
 */
class DB_Chat_Chat_Endpoint {

	/** Maximum characters in a single customer message. */
	const MAX_MESSAGE_LENGTH = 1000;

	/**
	 * Maximum guided-finder chips forwarded to the widget.
	 *
	 * The contract the finder and the frontend agreed. Re-asserted here so the
	 * endpoint cannot be the thing that breaks it.
	 */
	const MAX_CHIPS = 6;

	/** Maximum history entries accepted from the client. */
	const MAX_HISTORY = 20;

	/**
	 * Tight limit: requests per window per (IP + client token).
	 *
	 * The client token makes accidental collisions between real customers
	 * impossible when they share an upstream address.
	 */
	const RATE_LIMIT = 20;

	/** Tight rate limit window in seconds. */
	const RATE_WINDOW = 600;

	/**
	 * Loose circuit breaker: requests per window per IP alone.
	 *
	 * High enough that a shared proxy address never trips it in normal
	 * traffic, low enough to stop actual flooding. This exists because the
	 * site sits behind LiteSpeed on Plesk and REMOTE_ADDR may be a shared
	 * upstream address rather than the visitor.
	 */
	const RATE_LIMIT_IP = 300;

	/** Loose rate limit window in seconds. */
	const RATE_WINDOW_IP = 600;

	/** Rate limit transient prefix. */
	const RATE_PREFIX = 'db_chat_rl_';

	/** Header carrying the widget's per-session client token. */
	const CLIENT_TOKEN_HEADER = 'X-DB-Chat-Session';

	/**
	 * Product retrieval service.
	 *
	 * @var DB_Chat_Product_Index
	 */
	private $product_index;

	/**
	 * Anthropic client.
	 *
	 * @var DB_Chat_Claude_Client
	 */
	private $client;

	/**
	 * Help page retrieval service, or null when it is unavailable.
	 *
	 * Optional on purpose: if the class is missing the chatbot keeps answering
	 * product questions instead of fataling, exactly as it did before pages
	 * existed.
	 *
	 * @var DB_Chat_Content_Index|null
	 */
	private $content_index;

	/**
	 * Order lookup service, or null when it is unavailable.
	 *
	 * Optional in exactly the same way the page index is: a missing file must
	 * degrade the chatbot to products and policies, never fatal a live store.
	 *
	 * @var DB_Chat_Order_Lookup|null
	 */
	private $order_lookup;

	/**
	 * Free keyword lookup engine, or null when it is unavailable.
	 *
	 * Optional in the same way the page index and the order lookup are: a
	 * missing file must degrade, never fatal. DB_Chat_Plugin::get_engine() only
	 * reports 'lookup' when this object exists, so a missing engine switches
	 * the chatbot off rather than producing a broken widget.
	 *
	 * @var DB_Chat_Lookup_Engine|null
	 */
	private $lookup_engine;

	/**
	 * Monthly AI spend guard for hybrid mode, or null when unavailable.
	 *
	 * @var DB_Chat_Ai_Budget|null
	 */
	private $ai_budget;

	/**
	 * Did the order tool run during THIS request?
	 *
	 * Request-scoped, reset at the top of every handle_request(). Drives two
	 * things: no-store response headers, and complete suppression of the
	 * conversation log for this exchange.
	 *
	 * @var bool
	 */
	private $order_tool_used = false;

	/**
	 * Did the request_human tool run during THIS request?
	 *
	 * Request-scoped exactly like $order_tool_used above: reset at the top of
	 * every handle_request(), set true only inside raise_human_handoff(). ORed
	 * into $escalate so finalize_response() marks the reply for the widget
	 * exactly as the free lookup engine's last_wants_human() path does.
	 *
	 * @var bool
	 */
	private $tool_requested_human = false;

	/**
	 * Constructor.
	 *
	 * @param DB_Chat_Product_Index      $product_index Product retrieval service.
	 * @param DB_Chat_Claude_Client      $client        Anthropic client.
	 * @param DB_Chat_Content_Index|null $content_index Help page retrieval service.
	 * @param DB_Chat_Order_Lookup|null  $order_lookup  Own-order lookup service.
	 * @param DB_Chat_Lookup_Engine|null $lookup_engine Free keyword lookup engine.
	 * @param DB_Chat_Ai_Budget|null     $ai_budget     Monthly AI spend guard.
	 */
	public function __construct( DB_Chat_Product_Index $product_index, DB_Chat_Claude_Client $client, $content_index = null, $order_lookup = null, $lookup_engine = null, $ai_budget = null ) {
		$this->product_index = $product_index;
		$this->client        = $client;
		$this->content_index = $content_index instanceof DB_Chat_Content_Index ? $content_index : null;
		$this->order_lookup  = $order_lookup instanceof DB_Chat_Order_Lookup ? $order_lookup : null;
		$this->lookup_engine = $lookup_engine instanceof DB_Chat_Lookup_Engine ? $lookup_engine : null;

		if ( $ai_budget instanceof DB_Chat_Ai_Budget ) {
			$this->ai_budget = $ai_budget;
		} else {
			$this->ai_budget = class_exists( 'DB_Chat_Ai_Budget' ) ? new DB_Chat_Ai_Budget() : null;
		}

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			DB_CHAT_REST_NAMESPACE,
			DB_CHAT_REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'message' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => array( $this, 'sanitize_message' ),
						'validate_callback' => array( $this, 'validate_message' ),
					),
					'history' => array(
						'required' => false,
						'type'     => 'array',
						'default'  => array(),
					),
					/*
					 * The half-finished purchase, handed back by the widget.
					 * Untrusted by design — DB_Chat_Pending_Order sanitises its
					 * shape and DB_Chat_Cart_Writer re-reads every fact from
					 * WooCommerce before anything reaches a cart.
					 */
					/*
					 * "This one" on a card. Deliberately NO default: has_param()
					 * must stay false when the widget did not send it. Any scalar
					 * is accepted so a junk value answers "couldn't find" instead
					 * of a 400 — the id is a claim either way.
					 */
					'pick'    => array(
						'required'          => false,
						'validate_callback' => static function ( $value ) {
							return null === $value || is_scalar( $value );
						},
					),
					'pending' => array(
						'required'          => false,
						'default'           => null,
						'validate_callback' => static function ( $value ) {
							return null === $value || is_array( $value );
						},
					),
				),
			)
		);

		/*
		 * Session route: hands the widget a live wp_rest nonce.
		 *
		 * Public by necessity — it is what guests use to obtain a nonce for
		 * the public chat route. It is cheap (no API cost, no DB writes) and
		 * deliberately not rate limited, so a shared upstream address can
		 * never lock customers out of obtaining a nonce.
		 */
		register_rest_route(
			DB_CHAT_REST_NAMESPACE,
			DB_CHAT_REST_ROUTE_SESSION,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_session' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Issue a fresh nonce, plus anything else that must not be cached.
	 *
	 * MUST never be cached. The response carries explicit no-store headers plus
	 * a LiteSpeed-specific directive, because LiteSpeed 7.x can be configured
	 * to cache REST responses and the whole point of this route is freshness.
	 *
	 * Shape:
	 *
	 *     { "nonce": "...", "handoff": { "isOpen": true } }
	 *
	 * `nonce` stays exactly where it is — the widget depends on it at the top
	 * level, including on the 409 stale-nonce retry path. Sibling keys are safe
	 * to add; moving or renaming that one is not.
	 *
	 * `handoff` carries ONLY the volatile open/closed state. The url, label,
	 * hours summary and notes are static configuration and ride along in the
	 * localized dbChat object, where caching them costs nothing. isOpen cannot
	 * go there: page HTML lives in the LiteSpeed cache for up to 7 days and the
	 * answer changes twice a day. The key is omitted entirely when no handoff
	 * number is configured, matching the localized data, so the widget has one
	 * rule: no handoff anywhere, render nothing.
	 *
	 * NOT a cross-user token leak, and must not be "hardened" as if it were: for
	 * logged-out visitors WordPress derives the wp_rest nonce from user ID 0 and
	 * an empty session token, so every anonymous visitor legitimately receives
	 * the SAME value within a given tick. Sharing it is by design and carries no
	 * identity. The bug this route exists to fix is purely STALENESS — a cached
	 * nonce outlives its ~12-24h validity and can never refresh itself, which
	 * kills chat for every logged-out customer while staying invisible to
	 * logged-in admins who bypass the cache.
	 *
	 * (Logged-in users are a different case: their nonce is tied to their user
	 * ID and session token, which is a further reason this response must never
	 * be stored by a shared cache.)
	 *
	 * @param WP_REST_Request|null $request Request. Optional so the existing
	 *                                       zero-arg call shape still works;
	 *                                       WordPress always passes it in practice.
	 * @return WP_REST_Response
	 */
	public function handle_session( $request = null ) {
		// First thing, before any output and before the response is built.
		$this->prevent_caching( 'durianbikers chatbot: per-visitor nonce, must never be cached' );

		/*
		 * MUST come before wp_create_nonce(). See restore_cookie_user(): without
		 * it this route hands a logged-in customer a nonce minted for user 0,
		 * and their very next message dies with "Cookie check failed".
		 */
		$this->restore_cookie_user();

		$payload = array( 'nonce' => wp_create_nonce( 'wp_rest' ) );

		/*
		 * This route is what every customer calls before they can chat at all,
		 * so nothing optional here may be allowed to break it. A missing or
		 * broken handoff module costs the WhatsApp button, never the nonce.
		 */
		try {
			if ( class_exists( 'DB_Chat_Handoff' ) ) {
				$handoff = DB_Chat_Handoff::get_session_data();

				if ( ! empty( $handoff ) ) {
					$payload['handoff'] = $handoff;
				}
			}
		} catch ( Throwable $e ) {
			DB_Chat_Plugin::log_error( 'Handoff state could not be computed: ' . $e->getMessage() );
		}

		/*
		 * Off by default in production. When on, hand the widget a public_id
		 * it can round-trip on /chat so both routes agree on one conversation.
		 */
		if ( $this->handoff_enabled() && $request instanceof WP_REST_Request ) {
			$public_id_param = $request->get_param( 'conversation_id' );
			$public_id       = is_string( $public_id_param ) ? $public_id_param : '';

			$conversation = DB_Chat_Conversation_Store::ensure( $public_id );

			$payload['conversation_id'] = $conversation['public_id'];

			if ( ! isset( $payload['handoff'] ) ) {
				$payload['handoff'] = array();
			}

			$payload['handoff']['enabled']     = true;
			$payload['handoff']['pollSeconds'] = max( 2, (int) get_option( 'db_chat_handoff_poll_seconds', 5 ) );
			$payload['handoff']['status']      = $conversation['status'];
		}

		$response = new WP_REST_Response( $payload );

		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Expires', '0' );
		$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );

		return $response;
	}

	/**
	 * Re-establish the cookie-authenticated user for this request.
	 *
	 * WHY THIS EXISTS — the "Cookie check failed" bug, found on live 2026-09-11.
	 *
	 * WordPress core, in rest_cookie_check_errors():
	 *
	 *     if ( null === $nonce ) {
	 *         // No nonce at all, so act as if it's an unauthenticated request.
	 *         wp_set_current_user( 0 );
	 *         return true;
	 *     }
	 *
	 * This route is the one place that CANNOT send a nonce — it is the route
	 * that issues them. So core forces the current user to 0 here even when the
	 * caller's login cookies are present and valid, and wp_create_nonce() then
	 * mints a nonce bound to user 0. The chat POST that follows DOES carry a
	 * nonce, so cookie auth runs normally there and the caller is themselves
	 * again — and verification of a user-0 nonce against a real user fails with
	 * rest_cookie_invalid_nonce, "Cookie check failed".
	 *
	 * It is deterministic, not flaky: the widget's one silent retry calls this
	 * same route, receives another user-0 nonce, and fails identically. It
	 * affects every logged-in user, which is why it stayed invisible until the
	 * widget was first opened by a signed-in person — and it would have made
	 * get_my_orders permanently unreachable for signed-in customers, the only
	 * reason a customer signs in at all.
	 *
	 * Reading the cookie is not trusting user input: wp_validate_auth_cookie()
	 * verifies the signature, the expiry and the session token, exactly as a
	 * normal page load does. A forged or stale cookie returns false and the
	 * caller stays anonymous.
	 *
	 * Consequence to respect: with this in place the nonce is user-specific, so
	 * this response must never be stored by a shared cache. prevent_caching()
	 * above is now load-bearing for correctness, not just freshness.
	 *
	 * @return void
	 */
	private function restore_cookie_user() {
		/*
		 * Already authenticated by some other means — an application password,
		 * say. Core returned early and never reset the user, so there is
		 * nothing to restore and nothing to second-guess.
		 */
		if ( is_user_logged_in() ) {
			return;
		}

		if ( ! function_exists( 'wp_validate_auth_cookie' ) ) {
			return;
		}

		$user_id = wp_validate_auth_cookie( '', 'logged_in' );

		if ( $user_id ) {
			wp_set_current_user( $user_id );
		}
	}

	/**
	 * Tell every caching layer not to store this response.
	 *
	 * MUST be called at the very top of a handler, before any output and before
	 * the response object is built.
	 *
	 * Why the action and not just headers: LSCWP has REST API caching enabled on
	 * this site (responses carry a `308_REST` cache tag) and applies its own
	 * `public,max-age=604800` policy. It does NOT honour `Cache-Control`,
	 * `Pragma` or `Expires` on REST responses, and it consumes an
	 * `X-LiteSpeed-Cache-Control: no-cache` RESPONSE header rather than treating
	 * it as authoritative — that header does not even survive to the client.
	 * `litespeed_control_set_nocache` is LSCWP's documented control API and is
	 * the only thing that actually works here.
	 *
	 * Verified against live on 2026-08-17: without this, /session returned
	 * `x-litespeed-cache: hit` despite every no-store header being present and
	 * correct.
	 *
	 * The headers are still sent — they are correct, and they matter for any
	 * other proxy or CDN in front of the site. This is defence in depth, not a
	 * replacement.
	 *
	 * No-op when LiteSpeed is absent, since nothing is listening to the action.
	 *
	 * @param string $reason Human-readable reason, surfaced in LSCWP debug logs.
	 * @return void
	 */
	private function prevent_caching( $reason ) {
		do_action( 'litespeed_control_set_nocache', (string) $reason );

		// Standard no-cache header set, for every layer that is not LiteSpeed.
		nocache_headers();
	}

	/**
	 * Master toggle for the whole handoff feature. Cheap, cached option read.
	 *
	 * Off ('0') by default in production: DB_Chat_Conversation_Store is not
	 * required by the main plugin yet (Task 8 wires that up), so every call
	 * site that touches it is gated behind this method, and nothing calls it
	 * when the toggle is off.
	 *
	 * @return bool
	 */
	private function handoff_enabled() {
		return '1' === get_option( 'db_chat_handoff_enabled', '0' );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Permission and validation
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Verify the wp_rest nonce.
	 *
	 * The route is open to guests, but the nonce blocks casual cross-origin
	 * abuse of the API budget.
	 *
	 * Two distinct failures, deliberately:
	 *  - No nonce at all  -> 403. A genuine rejection; nothing legitimate calls
	 *    this route without one.
	 *  - Nonce present but not valid -> 409 db_chat_stale_nonce. Almost always
	 *    an expired nonce rather than an attack, so the widget re-fetches from
	 *    the session route and retries once instead of showing an error.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function check_permission( $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( empty( $nonce ) ) {
			$nonce = $request->get_param( '_wpnonce' );
		}

		if ( ! is_string( $nonce ) || '' === trim( $nonce ) ) {
			return new WP_Error(
				'db_chat_missing_nonce',
				__( 'This request could not be verified.', 'durianbikers-chatbot' ),
				array( 'status' => 403 )
			);
		}

		if ( wp_verify_nonce( trim( $nonce ), 'wp_rest' ) ) {
			return true;
		}

		return new WP_Error(
			'db_chat_stale_nonce',
			__( 'Your chat session expired. Reconnecting…', 'durianbikers-chatbot' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Sanitize the customer message.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_message( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		// Strip tags and control characters, keep normal punctuation.
		$value   = wp_strip_all_tags( (string) $value, true );
		$stripped = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value );

		// preg_replace returns null on malformed UTF-8; keep the tag-stripped
		// value rather than silently blanking the customer's message.
		if ( is_string( $stripped ) ) {
			$value = $stripped;
		}

		return trim( $value );
	}

	/**
	 * Validate the customer message.
	 *
	 * @param mixed $value Raw value.
	 * @return true|WP_Error
	 */
	public function validate_message( $value ) {
		$value = $this->sanitize_message( $value );

		if ( '' === $value ) {
			return new WP_Error(
				'db_chat_empty_message',
				__( 'Please type a message first.', 'durianbikers-chatbot' ),
				array( 'status' => 400 )
			);
		}

		if ( mb_strlen( $value ) > self::MAX_MESSAGE_LENGTH ) {
			return new WP_Error(
				'db_chat_message_too_long',
				sprintf(
					/* translators: %d: maximum number of characters. */
					__( 'That message is too long. Please keep it under %d characters.', 'durianbikers-chatbot' ),
					self::MAX_MESSAGE_LENGTH
				),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Handler
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Handle a chat request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_request( $request ) {
		/*
		 * POST is not normally cached, but this reply is per-visitor and can
		 * contain their own order data, so do not rely on that. Belt and braces,
		 * fired before anything else runs.
		 */
		$this->prevent_caching( 'durianbikers chatbot: per-visitor reply, must never be cached' );

		// Request-scoped flags; never carry a previous request's state.
		$this->order_tool_used      = false;
		$this->tool_requested_human = false;

		// Declared here so finalize_response() has a stable value on every
		// code path; the lookup branch below overwrites it once the engine
		// has answered. The direct-AI branch has no lookup-engine signal, so
		// it stays false.
		$escalate = false;

		if ( ! db_chat()->is_enabled() ) {
			return new WP_Error(
				'db_chat_offline',
				$this->offline_message(),
				array( 'status' => 503 )
			);
		}

		if ( ! function_exists( 'wc_get_products' ) ) {
			DB_Chat_Plugin::log_error( 'WooCommerce is not active; chat cannot answer product questions.' );

			return new WP_Error(
				'db_chat_offline',
				$this->offline_message(),
				array( 'status' => 503 )
			);
		}

		$rate_limited = $this->check_rate_limit( $request );

		if ( is_wp_error( $rate_limited ) ) {
			return $rate_limited;
		}

		$message = $this->sanitize_message( $request->get_param( 'message' ) );
		$history = $this->sanitize_history( $request->get_param( 'history' ) );

		/*
		 * HANDOFF GATE. Off by default (db_chat_handoff_enabled = '0'), in
		 * which case $conversation stays null and nothing below this block
		 * ever references DB_Chat_Conversation_Store. That is what makes it
		 * safe to ship while the store isn't wired into the main plugin yet.
		 */
		$conversation = null;

		if ( $this->handoff_enabled() ) {
			$public_id_param = $request->get_param( 'conversation_id' );
			$public_id       = is_string( $public_id_param ) ? $public_id_param : '';
			$page_url_param  = $request->get_param( 'page_url' );

			$seed = array(
				'lang'     => '',
				'page_url' => is_scalar( $page_url_param ) ? (string) $page_url_param : '',
			);

			$conversation = DB_Chat_Conversation_Store::ensure( $public_id, $seed );

			/*
			 * RESOLVE IS FINAL, SERVER-ENFORCED. A resolved conversation must
			 * never receive another message, no matter what conversation_id the
			 * client sent — the widget clears its stored id on resolve, but a
			 * stale tab, a restored localStorage value, or any other client that
			 * skips that step must not be able to reopen a closed thread. Ignore
			 * the resolved id entirely and start a brand-new conversation, whose
			 * fresh public_id goes back to the widget in the response below.
			 */
			if ( DB_Chat_Conversation_Store::STATUS_RESOLVED === $conversation['status'] ) {
				$conversation = DB_Chat_Conversation_Store::ensure( '', $seed );
			}

			DB_Chat_Conversation_Store::add_message( (int) $conversation['id'], 'customer', $message );

			// Handed off → the human owns this conversation; do not run the AI.
			if ( in_array( $conversation['status'], array( DB_Chat_Conversation_Store::STATUS_WAITING, DB_Chat_Conversation_Store::STATUS_HUMAN ), true ) ) {
				return $this->apply_no_store(
					rest_ensure_response(
						array(
							'ack'             => true,
							'status'          => $conversation['status'],
							'conversation_id' => $conversation['public_id'],
							'last_message_id' => 0,
						)
					)
				);
			}
		}

		/*
		 * THE BRANCH. Everything above this line — nonce, rate limit, caching
		 * headers, sanitisation — is shared, and everything below the branch
		 * returns the same JSON shape. Nothing else in the plugin needs to know
		 * which brain answered.
		 */
		if ( DB_Chat_Plugin::ENGINE_LOOKUP === db_chat()->get_engine() ) {
			$response_data = $this->handle_with_lookup(
				$message,
				$history,
				$request->get_param( 'pending' ),
				$request->get_param( 'pick' )
			);

			if ( is_wp_error( $response_data ) ) {
				return $response_data;
			}

			$escalate = $this->handoff_enabled()
				&& is_callable( array( $this->lookup_engine, 'last_wants_human' ) )
				&& $this->lookup_engine->last_wants_human();

			return $this->finalize_response( $response_data, $conversation, $escalate );
		}

		// AI brain: the orchestrator runs the grounded AI turn, or silently falls
		// back to the keyword engine when AI is unavailable / over budget / errors.
		$orchestrator = new DB_Chat_Orchestrator(
			$this->client,
			$this->lookup_engine,
			$this->ai_budget,
			$this->product_index,
			$this->content_index
		);

		$context   = $this->product_index->get_context();
		$pages_ctx = ( null !== $this->content_index ) ? $this->content_index->get_context() : '';

		$result = $orchestrator->answer(
			$message,
			$history,
			$this->get_tool_executor( null !== $conversation ? (int) $conversation['id'] : 0 ),
			$context,
			$pages_ctx
		);

		$this->maybe_log( $message, isset( $result['reply'] ) ? (string) $result['reply'] : '' );

		$escalate = ! empty( $result['escalate'] ) || $this->tool_requested_human;

		$response_data = array(
			'reply'    => isset( $result['reply'] ) ? (string) $result['reply'] : '',
			'products' => isset( $result['products'] ) && is_array( $result['products'] ) ? array_values( $result['products'] ) : array(),
			'pages'    => isset( $result['pages'] ) && is_array( $result['pages'] ) ? array_values( $result['pages'] ) : array(),
		);

		return $this->finalize_response( $response_data, $conversation, $escalate );
	}

	/**
	 * Persist the bot reply (when a conversation is tracked) and build the
	 * final REST response. The single place every `/chat` return path funnels
	 * through, so the persistence block runs exactly once per request.
	 *
	 * When $conversation is null (handoff disabled, the production default)
	 * this is nothing more than `apply_no_store( rest_ensure_response( $response_data ) )`
	 * — byte-identical to what every call site did before this method existed.
	 *
	 * @param array      $response_data Response payload built by the caller.
	 * @param array|null $conversation  Conversation row from DB_Chat_Conversation_Store::ensure(), or null.
	 * @param bool       $escalate      Whether this turn should escalate to a human. True only when handoff is enabled and the free lookup engine resolved an explicit "talk to a human" intent; the direct-AI branch never sets it.
	 * @return WP_REST_Response
	 */
	private function finalize_response( array $response_data, $conversation, $escalate ) {
		if ( null !== $conversation ) {
			$bot_text = isset( $response_data['reply'] ) ? (string) $response_data['reply'] : '';
			$mid      = '' !== $bot_text ? DB_Chat_Conversation_Store::add_message( (int) $conversation['id'], 'bot', $bot_text ) : 0;

			$response_data['conversation_id'] = $conversation['public_id'];
			$response_data['escalate']        = ! empty( $escalate );
			$response_data['last_message_id'] = $mid;
		}

		return $this->apply_no_store( rest_ensure_response( $response_data ) );
	}

	/**
	 * Hand the conversation to a human: reuse the existing handoff + inbox +
	 * alerts. Returns a warm next-step message the model relays to the
	 * customer. When handoff is disabled (the production default until it is
	 * turned on), falls back to the same "talk to a human" copy the free
	 * lookup engine uses and changes no status.
	 *
	 * @param int $conversation_id Active conversation id, or 0 when handoff
	 *                             tracking is off / no conversation exists.
	 * @return array{ handoff:bool, message:string }
	 */
	public function raise_human_handoff( $conversation_id ) {
		$conversation_id = (int) $conversation_id;

		// Only ever set true: this drives $escalate in handle_request(), which
		// must stay set for the rest of the request once the model has asked
		// for a human, no matter how many tool rounds follow.
		$this->tool_requested_human = true;

		if ( ! $this->handoff_enabled() || $conversation_id <= 0 ) {
			// Same wording as DB_Chat_Lookup_Engine's 'human_handoff' copy —
			// see class-lookup-engine.php — so the customer sees one
			// consistent line whichever brain answered.
			return array(
				'handoff' => true,
				'message' => __( 'A person can help with that. Tap the WhatsApp button below and our team will reply.', 'durianbikers-chatbot' ),
			);
		}

		DB_Chat_Conversation_Store::set_status( $conversation_id, DB_Chat_Conversation_Store::STATUS_WAITING );

		if ( class_exists( 'DB_Chat_Handoff_Alert' ) ) {
			DB_Chat_Handoff_Alert::send( $conversation_id );
		}

		$hours = class_exists( 'DB_Chat_Handoff' ) ? DB_Chat_Handoff::get_hours_text() : '';

		$message = '' !== $hours
			? sprintf(
				/* translators: %s: opening hours summary. */
				__( "I've flagged your chat for a team member — they'll reply right here. We're around %s. Anything you'd like me to note for them?", 'durianbikers-chatbot' ),
				$hours
			)
			: __( "I've flagged your chat for a team member — they'll reply right here. Anything you'd like me to note for them?", 'durianbikers-chatbot' );

		return array(
			'handoff' => true,
			'message' => $message,
		);
	}

	/**
	 * Answer from the FREE keyword lookup engine.
	 *
	 * No API key, no outbound HTTP, no cost. Returns exactly the same
	 * { reply, products[], pages[] } payload the Claude path returns, because
	 * the widget must not be able to tell the difference in shape — only in
	 * how good the answer is.
	 *
	 * ORDER QUESTIONS — CHANGED Aug 2026. Lookup mode used to decline these
	 * outright, so $order_tool_used could never be true on this path. It no
	 * longer declines: the engine delegates to the SAME DB_Chat_Order_Lookup
	 * the Claude path uses (identity from the session, guests told to sign in,
	 * same field allowlist). That means this path can now produce order data,
	 * and the suppression below is not optional — without it a lookup-mode
	 * order reply would be written verbatim to the log whenever
	 * db_chat_logging is on. See the flag handling after the answer() call.
	 *
	 * History IS used now, as a subject slot only, re-parsed server-side inside
	 * the engine. It is still untrusted input and is still sanitized here first.
	 *
	 * @param string $message Sanitized customer message.
	 * @param array  $history Sanitized history. Used by the engine as a subject
	 *                        slot; never trusted as a subject in itself.
	 * @return array|WP_Error Raw response data (finalize_response() wraps and
	 *                        persists it), or a WP_Error passed straight through.
	 */
	private function handle_with_lookup( $message, array $history, $pending = null, $pick = null ) {
		if ( null === $this->lookup_engine ) {
			/*
			 * Unreachable in practice: get_engine() only returns 'lookup' when
			 * the engine object exists. Kept because "unreachable" and "cannot
			 * happen" are different things on a store deployed by file upload.
			 */
			return new WP_Error(
				'db_chat_offline',
				$this->offline_message(),
				array( 'status' => 503 )
			);
		}

		/*
		 * THE GUIDED FINDER GETS FIRST REFUSAL.
		 *
		 * It must run BEFORE the keyword engine, not after: a chip tap is a
		 * bare noun ("Helmets", "Shinko", "Any"), and the engine would happily
		 * answer that as a plain product search, so the guided flow could never
		 * advance past its first question.
		 *
		 * null is the common case and means "not mine" — the engine then runs
		 * exactly as before. The finder only claims a turn that is a trigger,
		 * or a legal answer to a question it asked earlier in this same
		 * history. Anything else, including a half-finished flow the customer
		 * abandoned, falls through here untouched.
		 *
		 * A finder turn is never written to the unmatched-question log, and
		 * needs no special case to achieve that: the engine's answer() is what
		 * logs, and on this path it is never called.
		 */
		// A pick names its product exactly; the guided finder has nothing to add.
		if ( null === $pick && class_exists( 'DB_Chat_Product_Finder' ) ) {
			$guided = null;

			try {
				// Passing our own index keeps it to one product object per
				// request rather than the finder building a second one.
				$guided = DB_Chat_Product_Finder::instance( $this->product_index )->maybe_handle( $message, $history );
			} catch ( Throwable $e ) {
				// A broken finder degrades to the normal engine. It must never
				// be the reason a customer gets a 500.
				DB_Chat_Plugin::log_error( 'Product finder dispatch failed: ' . $e->getMessage() );

				$guided = null;
			}

			if ( is_array( $guided ) && isset( $guided['reply'] ) && is_scalar( $guided['reply'] ) && '' !== trim( (string) $guided['reply'] ) ) {
				return $this->finder_response( $message, $guided );
			}
		}

		try {
			$result = null !== $pick
				? $this->lookup_engine->answer_pick( self::pick_id( $pick ), $history, $pending )
				: $this->lookup_engine->answer( $message, $history, $pending );
		} catch ( Throwable $e ) {
			DB_Chat_Plugin::log_error( 'Lookup mode failed: ' . $e->getMessage() );

			return new WP_Error(
				'db_chat_error',
				__( 'Sorry, I could not put an answer together just now. Please try rephrasing, or contact us and a human will help.', 'durianbikers-chatbot' ),
				array( 'status' => 500 )
			);
		}

		/*
		 * Reuse the EXISTING order-data suppression rather than inventing a
		 * second one: this marks the response no-store and makes maybe_log()
		 * withhold BOTH sides of the exchange. Both sides, because the question
		 * routinely carries an order number even when the answer carries
		 * nothing — so the flag is set for guests and empty results too.
		 */
		if ( is_callable( array( $this->lookup_engine, 'used_order_lookup' ) ) && $this->lookup_engine->used_order_lookup() ) {
			$this->order_tool_used = true;
		}

		$reply    = isset( $result['reply'] ) && is_scalar( $result['reply'] ) ? (string) $result['reply'] : '';
		$products = isset( $result['products'] ) && is_array( $result['products'] ) ? $result['products'] : array();
		$pages    = isset( $result['pages'] ) && is_array( $result['pages'] ) ? $result['pages'] : array();

		if ( '' === trim( $reply ) ) {
			// The engine guarantees a non-empty reply; if that guarantee ever
			// breaks, an empty bubble must not reach a customer.
			DB_Chat_Plugin::log_error( 'Lookup mode returned an empty reply.' );

			return new WP_Error(
				'db_chat_error',
				__( 'Sorry, I could not put an answer together just now. Please try rephrasing, or contact us and a human will help.', 'durianbikers-chatbot' ),
				array( 'status' => 500 )
			);
		}

		/*
		 * HYBRID. The free engine has answered; if it genuinely did not
		 * understand the question, Claude gets one chance at the same message.
		 * Any reason not to — wrong mode, no key, no budget, an error, an empty
		 * reply — returns null and the free answer below goes out untouched.
		 */
		$ai = $this->maybe_answer_with_ai( $message, $history );

		if ( null !== $ai ) {
			return $ai;
		}

		$this->maybe_log( $message, $reply );

		/*
		 * `pending` is emitted even when null — that null is what CLEARS a
		 * finished or cancelled purchase in the widget. Dropping the key when
		 * empty would leave the browser holding a stale order.
		 *
		 * Heed the warning on finder_response() below: these keys are
		 * hardcoded, so anything the engine adds and this array omits is lost
		 * silently, with every test still green.
		 */
		$payload = array(
			'reply'    => $reply,
			'products' => array_values( $products ),
			'pages'    => array_values( $pages ),
			'pending'  => isset( $result['pending'] ) && is_array( $result['pending'] ) ? $result['pending'] : null,
		);

		if ( isset( $result['cart'] ) && is_array( $result['cart'] ) ) {
			$payload['cart'] = $result['cart'];
		}

		// Only ever true, only on a request for a person. chatbot.js highlights
		// the WhatsApp row on it (replyOffersHandoff()).
		if ( ! empty( $result['suggest_handoff'] ) ) {
			$payload['suggest_handoff'] = true;
		}

		return $payload;
	}

	/**
	 * A positive integer from the raw pick, or 0 for anything else.
	 *
	 * @param mixed $pick Raw value.
	 * @return int
	 */
	private static function pick_id( $pick ) {
		if ( ! is_scalar( $pick ) || 1 !== preg_match( '/^\s*([0-9]{1,10})\s*$/', (string) $pick, $m ) ) {
			return 0;
		}

		return (int) $m[1];
	}

	/**
	 * Hybrid mode: re-answer a genuine miss with Claude.
	 *
	 * Only ever reached after the free engine has answered, and only acts when
	 * the engine raised its miss flag. The flag is never raised inside a
	 * purchase: resume_pending() handles any turn that answers one, and a turn
	 * that does not has already dropped the pending order in the free answer
	 * too, so returning `pending: null` here changes nothing a customer had.
	 *
	 * @param string $message Sanitized customer message.
	 * @param array  $history Sanitized history.
	 * @return array|null Raw response data (caller/finalize_response() wraps and
	 *                     persists it), or null meaning "send the free answer".
	 */
	private function maybe_answer_with_ai( $message, array $history ) {
		if ( DB_Chat_Plugin::MODE_HYBRID !== db_chat()->get_mode() ) {
			return null;
		}

		if ( ! is_callable( array( $this->lookup_engine, 'needs_ai_fallback' ) ) || ! $this->lookup_engine->needs_ai_fallback() ) {
			return null;
		}

		if ( null === $this->ai_budget || ! $this->client->has_api_key() || ! $this->ai_budget->can_spend() ) {
			return null;
		}

		$messages     = $this->build_messages( $history, $message );
		$context      = $this->product_index->get_context();
		$page_context = null !== $this->content_index ? $this->content_index->get_context() : '';

		try {
			$reply = $this->client->chat( $messages, $context, $this->get_tool_executor(), $page_context );
		} catch ( Throwable $e ) {
			DB_Chat_Plugin::log_error( 'Hybrid AI fallback threw ' . get_class( $e ) . '.' );
			$reply = new WP_Error( 'db_chat_ai_exception', '' );
		}

		/*
		 * Charged whether or not it worked: earlier tool rounds of a call that
		 * later failed were still billed, and undercounting is the one mistake a
		 * budget must not make.
		 */
		$this->ai_budget->record( $this->client->get_last_usage() );

		if ( is_wp_error( $reply ) ) {
			// The code only. Never the customer's message, never upstream detail.
			DB_Chat_Plugin::log_error( 'Hybrid AI fallback unavailable: ' . $reply->get_error_code() . '.' );

			return null;
		}

		$reply = (string) $reply;

		if ( '' === trim( $reply ) ) {
			return null;
		}

		$products = $this->product_index->match_in_text( $reply );
		$pages    = null !== $this->content_index ? $this->content_index->match_in_text( $reply ) : array();

		/*
		 * PROGRAMMATIC CARDS FOR ADVICE ANSWERS.
		 *
		 * Haiku answers a how-to question well but will not reliably call
		 * search_products mid-answer (and must never invent a product), so an
		 * advice reply usually names no product and match_in_text finds nothing.
		 * When the free engine flagged this as an advice miss, attach REAL cards
		 * ourselves: derive the topic from the customer's message and look it up
		 * in the catalogue. Every card is a real published product with a real
		 * price — nothing is invented — and the relevance guard in
		 * cards_for_query() keeps a fitment part off a care question.
		 */
		if ( empty( $products )
			&& is_callable( array( $this->lookup_engine, 'last_was_advice' ) )
			&& $this->lookup_engine->last_was_advice() ) {
			$topic = $this->lookup_engine->advice_search_query( $message );

			if ( ! empty( $topic['queries'] ) && ! empty( $topic['nouns'] ) ) {
				$products = $this->product_index->cards_for_query( $topic['queries'], $topic['nouns'], 2 );
			}
		}

		$this->maybe_log( $message, $reply );

		return array(
			'reply'    => $reply,
			'products' => array_values( $products ),
			'pages'    => array_values( $pages ),
			// An AI answer ends any purchase in progress, as an off-topic message does.
			'pending'  => null,
		);
	}

	/**
	 * Build the response for a turn the guided finder answered.
	 *
	 * THE `chips` KEY IS THE WHOLE POINT OF THIS METHOD. The other lookup
	 * responses hardcode their keys, so a finder payload passed through them
	 * would lose its chips silently — the finder would look built, every test
	 * would pass, and the widget would render a flow with no options to tap.
	 * Chips are emitted ONLY on a finder turn; a plain lookup answer keeps the
	 * original { reply, products, pages } shape exactly.
	 *
	 * Re-validated here rather than trusted: flat strings, capped, and the
	 * contract's own maximum of six.
	 *
	 * The finder touches no order and no customer, so this exchange logs
	 * normally when the owner has logging on.
	 *
	 * @param string $message Sanitized customer message.
	 * @param array  $guided  Finder payload.
	 * @return array Raw response data (caller/finalize_response() wraps and persists it).
	 */
	private function finder_response( $message, array $guided ) {
		$reply    = (string) $guided['reply'];
		$products = isset( $guided['products'] ) && is_array( $guided['products'] ) ? array_values( $guided['products'] ) : array();
		$pages    = isset( $guided['pages'] ) && is_array( $guided['pages'] ) ? array_values( $guided['pages'] ) : array();
		$chips    = array();

		if ( isset( $guided['chips'] ) && is_array( $guided['chips'] ) ) {
			foreach ( $guided['chips'] as $chip ) {
				if ( count( $chips ) >= self::MAX_CHIPS ) {
					break;
				}

				if ( ! is_scalar( $chip ) ) {
					continue;
				}

				$chip = $this->sanitize_message( $chip );

				if ( '' !== $chip && ! in_array( $chip, $chips, true ) ) {
					$chips[] = $chip;
				}
			}
		}

		$this->maybe_log( $message, $reply );

		return array(
			'reply'    => $reply,
			'products' => $products,
			'pages'    => $pages,
			'chips'    => $chips,
		);
	}

	/**
	 * Forbid caching of a reply that may contain the customer's order data.
	 *
	 * The site runs LiteSpeed on Plesk with REST API caching ON. A cached order
	 * reply served to the next visitor would be exactly the leak this whole
	 * feature is built to avoid.
	 *
	 * IMPORTANT — corrected 2026-08-17 after testing against live: these
	 * response headers do NOT stop LiteSpeed. LSCWP ignores Cache-Control /
	 * Pragma / Expires on REST responses and strips
	 * `X-LiteSpeed-Cache-Control` rather than obeying it. The thing that
	 * actually prevents LiteSpeed caching is the
	 * `litespeed_control_set_nocache` action fired by prevent_caching(), which
	 * handle_request() now calls unconditionally at the top for EVERY chat
	 * reply, order tool or not.
	 *
	 * So this method is no longer the load-bearing protection — it is defence in
	 * depth for other proxies and CDNs, which do honour these headers. Do not
	 * remove it, but do not rely on it alone either: if the unconditional
	 * prevent_caching() call in handle_request() is ever removed, this will NOT
	 * cover for it.
	 *
	 * Nothing about an order is stored anywhere else either: no transient, no
	 * object cache, no log.
	 *
	 * @param WP_REST_Response|mixed $response Response.
	 * @return WP_REST_Response|mixed
	 */
	private function apply_no_store( $response ) {
		if ( ! $this->order_tool_used || ! $response instanceof WP_REST_Response ) {
			return $response;
		}

		nocache_headers();

		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Expires', '0' );
		$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );

		return $response;
	}

	/**
	 * The callable the Anthropic client uses to run a tool.
	 *
	 * Dispatch is an explicit allowlist of exactly five tool names
	 * (search_products, get_product, search_pages, get_my_orders,
	 * request_human). Anything else returns an empty result rather than
	 * reaching any store data — the client already rejects unknown names,
	 * this is the second lock.
	 *
	 * NOTE ON IDENTITY: nothing about the customer is captured into this
	 * closure and nothing identifying is passed through $input. The order
	 * lookup resolves the user itself, from the session, at call time. That is
	 * the whole point — there is no identity value here for anything to tamper
	 * with. Do not add one. The same applies to request_human: $conversation_id
	 * identifies a conversation, never a person, and is never taken from the
	 * model's tool input — it is threaded in from handle_request()'s own
	 * $conversation row.
	 *
	 * @param int $conversation_id Active conversation id (handoff tracking),
	 *                             or 0 when handoff is off / no conversation
	 *                             exists yet. Threaded through so a
	 *                             request_human call reaches the right row.
	 * @return callable fn( string $name, array $input ): array
	 */
	private function get_tool_executor( $conversation_id = 0 ) {
		$products        = $this->product_index;
		$pages           = $this->content_index;
		$orders          = $this->order_lookup;
		$conversation_id = (int) $conversation_id;

		// Deliberately NOT static: it flags order/human tool use on $this so
		// the response can be marked no-store, the log suppressed, and the
		// turn escalated to a human.
		return function ( $name, $input ) use ( $products, $pages, $orders, $conversation_id ) {
			$input = is_array( $input ) ? $input : array();

			if ( DB_Chat_Claude_Client::TOOL_HUMAN === $name ) {
				return $this->raise_human_handoff( $conversation_id );
			}

			if ( DB_Chat_Claude_Client::TOOL_ORDERS === $name ) {
				$this->order_tool_used = true;

				if ( null === $orders ) {
					return array(
						'signed_in' => false,
						'results'   => array(),
						'count'     => 0,
						'truncated' => false,
						'notice'    => 'Order lookup is unavailable. Tell the customer you cannot check orders right now and offer to pass it to a human. Do not ask them for personal details.',
					);
				}

				/*
				 * Only the tool input is forwarded, and the lookup reads exactly
				 * one key from it (order_number). The customer identity is NOT
				 * passed in — it is read from the session inside the lookup.
				 */
				return $orders->get_my_orders( $input );
			}

			if ( DB_Chat_Claude_Client::TOOL_PAGES === $name ) {
				if ( null === $pages ) {
					return array(
						'results'   => array(),
						'count'     => 0,
						'truncated' => false,
						'notice'    => 'Help page lookup is unavailable. Do not answer the policy question from memory; offer to pass it to a human.',
					);
				}

				return $pages->search_pages( $input );
			}

			if ( DB_Chat_Claude_Client::TOOL_PRODUCTS === $name ) {
				return $products->search_products( $input );
			}

			if ( DB_Chat_Claude_Client::TOOL_PRODUCT === $name ) {
				return $products->get_product_detail( $input );
			}

			return array(
				'results'   => array(),
				'count'     => 0,
				'truncated' => false,
				'notice'    => 'Unknown tool.',
			);
		};
	}

	/**
	 * Owner-configurable offline copy, with a safe default.
	 *
	 * @return string
	 */
	private function offline_message() {
		$custom = DB_Chat_Plugin::setting( 'db_chat_offline_text', '' );

		if ( '' !== trim( $custom ) ) {
			return wp_strip_all_tags( $custom );
		}

		return __( 'Chat is offline right now. Please try again later or contact us directly.', 'durianbikers-chatbot' );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Input shaping
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Sanitize the conversation history sent by the browser.
	 *
	 * History is client-supplied and therefore untrusted: it is capped, typed,
	 * length-limited and stripped before it goes anywhere near the model.
	 *
	 * @param mixed $history Raw history.
	 * @return array<int,array{role:string,content:string}>
	 */
	private function sanitize_history( $history ) {
		if ( ! is_array( $history ) ) {
			return array();
		}

		// Keep the most recent turns if the client sent too many.
		if ( count( $history ) > self::MAX_HISTORY ) {
			$history = array_slice( $history, -self::MAX_HISTORY );
		}

		$clean = array();

		foreach ( $history as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['role'], $entry['content'] ) ) {
				continue;
			}

			$role = is_scalar( $entry['role'] ) ? strtolower( trim( (string) $entry['role'] ) ) : '';

			if ( ! in_array( $role, array( 'user', 'assistant' ), true ) ) {
				continue;
			}

			$content = $this->sanitize_message( $entry['content'] );

			if ( '' === $content ) {
				continue;
			}

			if ( mb_strlen( $content ) > self::MAX_MESSAGE_LENGTH ) {
				$content = mb_substr( $content, 0, self::MAX_MESSAGE_LENGTH );
			}

			$clean[] = array(
				'role'    => $role,
				'content' => $content,
			);
		}

		return $clean;
	}

	/**
	 * Build the Messages API message array.
	 *
	 * The API requires the first message to be from the user and roles to
	 * alternate, so leading assistant turns are dropped and consecutive
	 * same-role turns are merged.
	 *
	 * @param array  $history Sanitized history.
	 * @param string $message Current customer message.
	 * @return array<int,array{role:string,content:string}>
	 */
	private function build_messages( array $history, $message ) {
		return DB_Chat_Messages::build( $history, (string) $message );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Rate limiting
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Enforce the two-tier rate limit.
	 *
	 * The site is behind LiteSpeed on Plesk, so REMOTE_ADDR may be a shared
	 * upstream address rather than the visitor. Gating solely on it would mean
	 * one visitor's burst locks out every customer on the site. So:
	 *
	 *  - Tight bucket, keyed on IP + the widget's per-session client token.
	 *    Spoofable by a determined attacker, but it makes accidental
	 *    collisions between real customers impossible.
	 *  - Loose circuit breaker, keyed on IP alone, set high enough that a
	 *    shared address never trips it in normal traffic.
	 *
	 * The failure mode of a misdiagnosed proxy is therefore "slightly weaker
	 * abuse protection", not "chatbot dead for everyone".
	 *
	 * Only salted hashes are stored; raw IPs are never persisted.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	private function check_rate_limit( $request ) {
		$ip    = $this->get_client_ip();
		$token = $this->get_client_token( $request );

		/**
		 * Filter the loose per-IP circuit breaker.
		 *
		 * @param int $limit Requests allowed per window.
		 */
		$ip_limit = (int) apply_filters( 'db_chat_rate_limit_ip', self::RATE_LIMIT_IP );

		/**
		 * Filter the loose per-IP window, in seconds.
		 *
		 * @param int $window Window length.
		 */
		$ip_window = (int) apply_filters( 'db_chat_rate_window_ip', self::RATE_WINDOW_IP );

		if ( $ip_limit > 0 && ! $this->consume( 'ip|' . $ip, $ip_limit, $ip_window ) ) {
			return $this->rate_limited_error();
		}

		/**
		 * Filter the tight per-visitor limit.
		 *
		 * @param int $limit Requests allowed per window.
		 */
		$limit = (int) apply_filters( 'db_chat_rate_limit', self::RATE_LIMIT );

		/**
		 * Filter the tight per-visitor window, in seconds.
		 *
		 * @param int $window Window length.
		 */
		$window = (int) apply_filters( 'db_chat_rate_window', self::RATE_WINDOW );

		if ( $limit > 0 && ! $this->consume( 'v|' . $ip . '|' . $token, $limit, $window ) ) {
			return $this->rate_limited_error();
		}

		return true;
	}

	/**
	 * Increment a rate limit bucket.
	 *
	 * @param string $identity Unhashed bucket identity.
	 * @param int    $limit    Requests allowed per window.
	 * @param int    $window   Window length in seconds.
	 * @return bool True when the request is allowed.
	 */
	private function consume( $identity, $limit, $window ) {
		$window = max( 1, (int) $window );
		$key    = self::RATE_PREFIX . substr( wp_hash( 'db_chat_rl|' . $identity ), 0, 32 );
		$now    = time();

		$bucket = get_transient( $key );

		if ( ! is_array( $bucket ) || ! isset( $bucket['count'], $bucket['expires'] ) || $bucket['expires'] <= $now ) {
			$bucket = array(
				'count'   => 0,
				'expires' => $now + $window,
			);
		}

		$bucket['count'] = (int) $bucket['count'] + 1;

		set_transient( $key, $bucket, max( 1, (int) $bucket['expires'] - $now ) );

		return $bucket['count'] <= (int) $limit;
	}

	/**
	 * Customer-safe rate limit response.
	 *
	 * @return WP_Error
	 */
	private function rate_limited_error() {
		$custom = DB_Chat_Plugin::setting( 'db_chat_rate_limit_text', '' );

		$notice = '' !== trim( $custom )
			? wp_strip_all_tags( $custom )
			: __( 'You have sent a lot of messages in a short time. Please wait a few minutes and try again.', 'durianbikers-chatbot' );

		return new WP_Error( 'db_chat_rate_limited', $notice, array( 'status' => 429 ) );
	}

	/**
	 * The widget's per-session client token.
	 *
	 * Generated by the browser and held in sessionStorage. It is an abuse
	 * control input only — never an identity, never persisted, never logged.
	 * Requests without one share a single bucket per IP.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private function get_client_token( $request ) {
		$token = $request->get_header( self::CLIENT_TOKEN_HEADER );

		if ( ! is_string( $token ) ) {
			return 'none';
		}

		$token = trim( $token );

		// Conservative charset, bounded length; anything else is discarded.
		if ( '' === $token || ! preg_match( '/^[A-Za-z0-9_\-]{8,64}$/', $token ) ) {
			return 'none';
		}

		return $token;
	}

	/**
	 * Client IP address.
	 *
	 * REMOTE_ADDR only by default — forwarded headers are trivially spoofable,
	 * so honouring them is opt-in for sites behind a proxy they control.
	 *
	 * @return string
	 */
	private function get_client_ip() {
		$ip = '';

		/**
		 * Filter whether to trust a forwarded-for header for rate limiting.
		 *
		 * Only enable this behind a proxy you control.
		 *
		 * @param bool $trust Whether to trust the header.
		 */
		if ( apply_filters( 'db_chat_trust_proxy_header', false ) && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$parts     = explode( ',', $forwarded );
			$ip        = trim( $parts[0] );
		}

		if ( '' === $ip && ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$ip = 'unknown';
		}

		return $ip;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Logging
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Log the exchange, but only when the owner explicitly enabled logging.
	 *
	 * Off by default. No IP, no user, no identifiers — message text only.
	 *
	 * ORDER DATA IS NEVER LOGGED, even with db_chat_logging on. When the order
	 * tool ran, BOTH sides are withheld: the reply obviously carries order
	 * detail, and the question routinely carries an order number. A one-line
	 * marker is still written so the owner can see the feature was exercised —
	 * that is the audit trail, and it contains nothing personal.
	 *
	 * @param string $message Customer message.
	 * @param string $reply   Assistant reply.
	 * @return void
	 */
	private function maybe_log( $message, $reply ) {
		if ( ! DB_Chat_Plugin::is_logging_enabled() ) {
			return;
		}

		if ( $this->order_tool_used ) {
			DB_Chat_Plugin::log_error( 'chat [order lookup used — question and reply withheld from the log]' );

			return;
		}

		DB_Chat_Plugin::log_error(
			sprintf(
				'chat q=%s | a=%s',
				mb_substr( (string) $message, 0, 300 ),
				mb_substr( (string) $reply, 0, 300 )
			)
		);
	}
}
