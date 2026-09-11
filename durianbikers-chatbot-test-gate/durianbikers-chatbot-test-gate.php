<?php
/**
 * Plugin Name: Durian Bikers Chatbot — Test Page Only (TEMPORARY)
 * Description: Shows the chatbot widget ONLY to logged-in staff, and ONLY on one private test page. No customer can see the widget or reach the chat, even by guessing the URL. Deactivate and delete this plugin when testing is finished.
 * Version:     1.0.0
 * Author:      Durian Bikers
 * Requires PHP: 8.0
 *
 * @package durianbikers-chatbot-test-gate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Slug of the private page the widget is allowed to appear on.
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
 * Gate the chatbot to staff, on the test page only.
 *
 * WHY THIS IS NOT JUST `is_page()`:
 *
 * The chat REST route re-checks db_chat()->is_enabled() before it will answer
 * (class-chat-endpoint.php). A REST request is not a page view — is_page() is
 * false during it — so gating on the page alone would render the widget on the
 * test page and then have every message come back "Chat is offline right now".
 * The widget would look deployed and be useless.
 *
 * So the two request types are gated differently:
 *
 *   - Page views: staff AND the test page. This is what keeps the widget off
 *     every customer-facing page.
 *   - REST calls: staff only. Safe, because a non-staff visitor is never given
 *     a widget, never given a nonce, and fails this check anyway.
 *
 * In both cases the owner's own "Enable chatbot" setting still has to be on —
 * $enabled is passed through, never replaced with true. Unticking it in
 * WooCommerce → Chatbot remains a working off switch.
 *
 * @param bool $enabled Whether the chatbot is on per the plugin's own rules.
 * @return bool
 */
add_filter(
	'db_chat_is_enabled',
	static function ( $enabled ) {
		// Never for logged-out visitors, and never for customers.
		if ( ! is_user_logged_in() || ! current_user_can( DB_CHAT_TEST_CAP ) ) {
			return false;
		}

		if ( ! $enabled ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		return is_page( DB_CHAT_TEST_PAGE );
	},
	99
);

/**
 * Say plainly, on the test page itself, that this gate is active.
 *
 * Testing on a live store goes wrong when someone forgets which of the two
 * states they are in. A visible banner on the page removes the doubt, and it
 * disappears the moment this plugin is deactivated.
 *
 * @return void
 */
add_action(
	'wp_footer',
	static function () {
		if ( ! is_user_logged_in() || ! current_user_can( DB_CHAT_TEST_CAP ) ) {
			return;
		}

		if ( ! is_page( DB_CHAT_TEST_PAGE ) ) {
			return;
		}

		printf(
			'<div style="position:fixed;left:0;right:0;bottom:0;z-index:99998;background:#1d1f1a;color:#f7f6f2;'
			. 'font:500 13px/1.4 system-ui,sans-serif;padding:9px 14px;text-align:center">%s</div>',
			esc_html__(
				'TEST MODE — the chatbot is visible to staff on this page only. No customer can see it. Deactivate "Durian Bikers Chatbot — Test Page Only" when testing is done.',
				'durianbikers-chatbot'
			)
		);
	},
	99
);
