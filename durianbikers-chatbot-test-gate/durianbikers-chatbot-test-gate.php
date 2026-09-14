<?php
/**
 * Plugin Name: Durian Bikers Chatbot — Test Page Only (TEMPORARY)
 * Description: Shows the chatbot ONLY on one test page, and only to staff or to someone who opened the secret test link. Everyone else gets a 404 for that page and "offline" from the chat. Deactivate and delete this plugin when testing is finished.
 * Version:     1.1.0
 * Author:      Durian Bikers
 * Requires PHP: 8.0
 *
 * @package durianbikers-chatbot-test-gate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Slug of the page the widget is allowed to appear on.
 *
 * Override in wp-config.php with:
 *   define( 'DB_CHAT_TEST_PAGE', 'some-other-slug' );
 */
if ( ! defined( 'DB_CHAT_TEST_PAGE' ) ) {
	define( 'DB_CHAT_TEST_PAGE', 'chatbot-test' );
}

/**
 * Capability that counts as "staff".
 *
 * manage_woocommerce rather than manage_options so a shop manager can test
 * too, without anyone needing a full administrator account.
 */
if ( ! defined( 'DB_CHAT_TEST_CAP' ) ) {
	define( 'DB_CHAT_TEST_CAP', 'manage_woocommerce' );
}

/**
 * Link-only access to the test page.
 *
 * WHY THIS EXISTS. Signed-in testing cannot show what a customer gets: admins
 * skip the LiteSpeed page cache and carry a logged-in cart. Opening the secret
 * link lets anyone test as a real guest, without an account, while every
 * other visitor still gets a 404.
 *
 * HOW IT WORKS.
 *
 *   /my/chatbot-test/?key=<secret>
 *     → key checked → cookie set → redirected to /my/chatbot-test/
 *       (the key is removed from the address bar, browser history, and any
 *       Referer header the page might send)
 *
 * The cookie holds an HMAC of the key, not the key. Rotating the key in the
 * `db_chat_test_key` option therefore signs everyone out at once.
 *
 * THE KEY LIVES IN THE DATABASE, NEVER IN THIS FILE. It is generated on the
 * server. With no key set, or a key shorter than 32 characters, link access is
 * switched off entirely and only staff get in — the gate fails closed.
 */
final class DB_Chat_Test_Gate {

	/** Option holding the secret. */
	const KEY_OPTION = 'db_chat_test_key';

	/** Cookie set once a valid link has been opened. */
	const COOKIE = 'db_chat_test_access';

	/** How long one opened link keeps working. */
	const COOKIE_TTL = 7 * DAY_IN_SECONDS;

	/** Anything shorter is guessable, so it is treated as "no key". */
	const MIN_KEY_LENGTH = 32;

	/**
	 * The secret, or '' when link access is off.
	 *
	 * @return string
	 */
	public static function key() {
		static $key = null;

		if ( null === $key ) {
			$stored = get_option( self::KEY_OPTION, '' );
			$key    = ( is_string( $stored ) && strlen( $stored ) >= self::MIN_KEY_LENGTH ) ? $stored : '';
		}

		return $key;
	}

	/**
	 * What the cookie must contain. Never the key itself.
	 *
	 * @return string
	 */
	public static function token() {
		$key = self::key();

		return '' === $key ? '' : hash_hmac( 'sha256', $key, wp_salt( 'auth' ) );
	}

	/**
	 * Signed in with the staff capability?
	 *
	 * @return bool
	 */
	public static function is_staff() {
		return is_user_logged_in() && current_user_can( DB_CHAT_TEST_CAP );
	}

	/**
	 * Carries a valid unlock cookie?
	 *
	 * @return bool
	 */
	public static function has_cookie() {
		$token = self::token();

		if ( '' === $token || ! isset( $_COOKIE[ self::COOKIE ] ) || ! is_string( $_COOKIE[ self::COOKIE ] ) ) {
			return false;
		}

		return hash_equals( $token, wp_unslash( $_COOKIE[ self::COOKIE ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	/**
	 * Did this request arrive with the right ?key=?
	 *
	 * @return bool
	 */
	public static function link_matches() {
		$key = self::key();

		if ( '' === $key || ! isset( $_GET['key'] ) || ! is_string( $_GET['key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		}

		return hash_equals( $key, wp_unslash( $_GET['key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput
	}

	/**
	 * Allowed in?
	 *
	 * @return bool
	 */
	public static function can_access() {
		return self::is_staff() || self::has_cookie();
	}

	/**
	 * Is this the test page?
	 *
	 * @return bool
	 */
	public static function is_test_page() {
		return is_page( DB_CHAT_TEST_PAGE );
	}

	/**
	 * Keep this page out of every shared cache, in every state.
	 *
	 * The same URL answers 404 to one visitor and the unlocked page to another,
	 * depending only on a cookie. A cache that ignores the cookie would either
	 * serve the unlocked page to everyone or serve the 404 to the tester, so
	 * nothing about this page may be stored — the 404 included.
	 *
	 * @return void
	 */
	public static function no_cache() {
		nocache_headers();
		do_action( 'litespeed_control_set_nocache', 'durianbikers chatbot test page: access depends on a cookie' );
		header( 'X-Robots-Tag: noindex, nofollow', true );
	}

	/**
	 * Remember that this browser opened a valid link.
	 *
	 * Path '/' rather than COOKIEPATH so the chat's REST calls under /wp-json/
	 * carry it too — without that, the page opens and every message comes back
	 * "Chat is offline".
	 *
	 * @return void
	 */
	public static function set_cookie() {
		$token = self::token();

		setcookie(
			self::COOKIE,
			$token,
			array(
				'expires'  => time() + self::COOKIE_TTL,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		$_COOKIE[ self::COOKIE ] = $token;
	}

	/**
	 * Answer exactly as if the page did not exist.
	 *
	 * @return void
	 */
	public static function send_404() {
		global $wp_query;

		$wp_query->set_404();
		status_header( 404 );
	}
}

/**
 * Page access: staff or a valid link, otherwise a real 404.
 *
 * Priority 0 so this runs before anything else can print output or redirect.
 */
add_action(
	'template_redirect',
	static function () {
		if ( ! DB_Chat_Test_Gate::is_test_page() ) {
			return;
		}

		DB_Chat_Test_Gate::no_cache();

		if ( DB_Chat_Test_Gate::link_matches() ) {
			DB_Chat_Test_Gate::set_cookie();

			$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

			/*
			 * A ROOT-RELATIVE redirect, never home_url( $uri ).
			 *
			 * On this site home_url( $uri ) sent people to /my/my/chatbot-test/
			 * — a 404. REQUEST_URI already carries the /my store prefix, and
			 * during a store request home_url() evidently carries it too, so
			 * joining them counts it twice. It went unnoticed at first because
			 * the cookie was still set, so a test that opened the page directly
			 * afterwards passed while a real click landed nowhere.
			 *
			 * A path starting with "/" is resolved by the browser against the
			 * address it is already on, which already has the right prefix, and
			 * wp_safe_redirect() accepts it without needing a host.
			 */
			wp_safe_redirect( remove_query_arg( 'key', $uri ), 302 );
			exit;
		}

		if ( DB_Chat_Test_Gate::can_access() ) {
			return;
		}

		DB_Chat_Test_Gate::send_404();
	},
	0
);

/**
 * Gate the chatbot itself.
 *
 * WHY PAGE VIEWS AND REST CALLS ARE GATED DIFFERENTLY:
 *
 * The chat REST route re-checks db_chat()->is_enabled() before it will answer
 * (class-chat-endpoint.php). A REST request is not a page view — is_page() is
 * false during it — so gating on the page alone would render the widget and
 * then answer "Chat is offline right now" to every message.
 *
 *   - Page views: (staff OR valid link) AND the test page.
 *   - REST calls: staff OR valid link.
 *
 * The owner's own "Enable chatbot" setting is passed through, never replaced
 * with true, so unticking it in WooCommerce → Chatbot is still an off switch.
 *
 * @param bool $enabled Whether the chatbot is on per the plugin's own rules.
 * @return bool
 */
add_filter(
	'db_chat_is_enabled',
	static function ( $enabled ) {
		if ( ! $enabled || ! DB_Chat_Test_Gate::can_access() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		return DB_Chat_Test_Gate::is_test_page();
	},
	99
);

/**
 * Never index the test page, even though it is now published.
 *
 * @param array $robots Robots directives.
 * @return array
 */
add_filter(
	'wp_robots',
	static function ( $robots ) {
		if ( DB_Chat_Test_Gate::is_test_page() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}

		return $robots;
	}
);

/**
 * Keep the test page out of the WordPress sitemap.
 *
 * A private page is excluded automatically; a published one is not, and the
 * sitemap is the first place a crawler looks.
 *
 * @param array  $args      Query args.
 * @param string $post_type Post type being mapped.
 * @return array
 */
add_filter(
	'wp_sitemaps_posts_query_args',
	static function ( $args, $post_type ) {
		if ( 'page' !== $post_type ) {
			return $args;
		}

		$page = get_page_by_path( DB_CHAT_TEST_PAGE );

		if ( $page ) {
			$existing              = isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array();
			$args['post__not_in'] = array_merge( $existing, array( (int) $page->ID ) );
		}

		return $args;
	},
	10,
	2
);

/**
 * Take the Chatway bubble off the test page only.
 *
 * Two chat buttons in the same corner makes a test worthless — you cannot tell
 * which widget answered. This dequeues Chatway's one handle (`chatway-script`,
 * from chatway-live-chat/app/Assets.php) on this single page, at priority 100
 * so it runs after Chatway's own enqueue at 10. The vendor plugin is never
 * modified.
 *
 * @return void
 */
add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( ! DB_Chat_Test_Gate::is_test_page() ) {
			return;
		}

		wp_dequeue_script( 'chatway-script' );
		wp_deregister_script( 'chatway-script' );
	},
	100
);

/**
 * Say plainly, on the page itself, that this is a test surface.
 *
 * @return void
 */
add_action(
	'wp_footer',
	static function () {
		if ( ! DB_Chat_Test_Gate::is_test_page() || ! DB_Chat_Test_Gate::can_access() ) {
			return;
		}

		printf(
			'<div style="position:fixed;left:0;right:0;bottom:0;z-index:99998;background:#1d1f1a;color:#f7f6f2;'
			. 'font:500 13px/1.4 system-ui,sans-serif;padding:9px 14px;text-align:center">%s</div>',
			esc_html__(
				'TEST MODE — this page and its chatbot open only with the secret test link, or when signed in as staff. Everyone else gets a 404.',
				'durianbikers-chatbot'
			)
		);
	},
	99
);
