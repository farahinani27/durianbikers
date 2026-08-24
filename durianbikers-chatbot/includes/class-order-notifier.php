<?php
/**
 * Order notifier — builds an owner-facing summary of a new order and fans it
 * out to every enabled channel.
 *
 * SAFETY CONTRACT (this store takes real payments; this code runs inside a
 * customer's checkout request):
 *
 *  1. The entire body is wrapped in try/catch( \Throwable ). Any failure is
 *     error_log'd and swallowed. A notification problem must never surface to
 *     the customer or interrupt checkout.
 *  2. Each channel is dispatched inside its OWN try/catch, so one broken
 *     channel cannot stop the others.
 *  3. All outbound HTTP is 'blocking' => false with 'timeout' => 2 (enforced in
 *     DB_Chat_Abstract_Notifier::post_non_blocking, not per channel).
 *  4. Double-fire is guarded by the `_db_chat_notified` order meta flag, which
 *     is written BEFORE dispatch. At-most-once is deliberate: a missed owner
 *     notification is recoverable from wp-admin, a duplicated one is noise on
 *     every retry.
 *
 * This class reads order data only to build the owner's private message. It is
 * a separate code path from the chatbot, which never touches orders at all.
 *
 * @package durianbikers-chatbot
 */

defined( 'ABSPATH' ) || exit;

class DB_Chat_Order_Notifier {

	/** Order meta flag used as the idempotency key. */
	const META_FLAG = '_db_chat_notified';

	/** Belt and braces: within-request guard in case the meta write fails. */
	private static array $seen = array();

	/** @var DB_Chat_Notifier[]|null */
	private static ?array $notifiers = null;

	/**
	 * Register hooks. Static, idempotent, safe to call once on plugins_loaded.
	 */
	public static function init(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		// Late priority so WooCommerce has finished its own work on the order.
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'maybe_notify' ), 99, 1 );
		add_action( 'woocommerce_new_order', array( __CLASS__, 'maybe_notify' ), 99, 1 );

		// Test sends from the settings screen.
		add_action( 'admin_post_db_chat_test_notification', array( __CLASS__, 'handle_test_notification' ) );
	}

	/**
	 * Order hook entry point. NOTHING may escape this method.
	 *
	 * @param mixed $order_id Order id as passed by WooCommerce.
	 */
	public static function maybe_notify( $order_id ): void {
		try {
			$order_id = absint( $order_id );

			if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) {
				return;
			}

			if ( isset( self::$seen[ $order_id ] ) ) {
				return;
			}

			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return;
			}

			// --- idempotency -------------------------------------------------
			// Assume every job will be retried. If the flag is already there,
			// this order has been announced; do nothing.
			if ( '' !== (string) $order->get_meta( self::META_FLAG, true ) ) {
				self::$seen[ $order_id ] = true;
				return;
			}

			self::$seen[ $order_id ] = true;

			// Claim the order BEFORE sending. If dispatch dies half way through
			// we would rather lose one notification than spam on every retry.
			$order->update_meta_data( self::META_FLAG, gmdate( 'c' ) );
			$order->save_meta_data();
			// -----------------------------------------------------------------

			$notifiers = self::get_enabled_notifiers();
			if ( empty( $notifiers ) ) {
				return;
			}

			$message = self::build_message( $order );
			if ( '' === $message ) {
				return;
			}

			self::dispatch( $message, $notifiers );

		} catch ( \Throwable $e ) {
			// Swallow. Checkout continues regardless.
			error_log( '[db_chat_notify] order notification failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Send a message to a set of notifiers, isolating each one.
	 *
	 * Ordering matters: fire-and-forget HTTP channels go first, wp_mail last,
	 * because wp_mail can block on a slow SMTP transport.
	 *
	 * @param string             $message   Plain text.
	 * @param DB_Chat_Notifier[] $notifiers Defaults to all enabled channels.
	 * @return array<string,bool> Channel id => dispatched.
	 */
	public static function dispatch( string $message, ?array $notifiers = null ): array {
		$results = array();

		if ( null === $notifiers ) {
			$notifiers = self::get_enabled_notifiers();
		}

		foreach ( $notifiers as $notifier ) {
			if ( ! $notifier instanceof DB_Chat_Notifier ) {
				continue;
			}

			try {
				$results[ $notifier->get_id() ] = (bool) $notifier->send( $message );
			} catch ( \Throwable $e ) {
				// One channel down must not take the rest with it.
				$results[ $notifier->get_id() ] = false;
				error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					sprintf( '[db_chat_notify:%s] send failed: %s', $notifier->get_id(), $e->getMessage() )
				);
			}
		}

		return $results;
	}

	/**
	 * All channels, configured or not. Used by the settings screen.
	 *
	 * @return DB_Chat_Notifier[]
	 */
	public static function get_notifiers(): array {
		if ( null !== self::$notifiers ) {
			return self::$notifiers;
		}

		self::require_notifiers();

		$notifiers = array();

		// wp_mail last on purpose — see dispatch().
		foreach ( array(
			'DB_Chat_Telegram_Notifier',
			'DB_Chat_Slack_Notifier',
			'DB_Chat_WhatsApp_Notifier',
			'DB_Chat_Email_Notifier',
		) as $class ) {
			if ( class_exists( $class ) ) {
				$notifiers[] = new $class();
			}
		}

		/**
		 * Filter the notification channels.
		 *
		 * @param DB_Chat_Notifier[] $notifiers
		 */
		$notifiers = apply_filters( 'db_chat_notifiers', $notifiers );

		self::$notifiers = array_values(
			array_filter(
				is_array( $notifiers ) ? $notifiers : array(),
				static function ( $n ) {
					return $n instanceof DB_Chat_Notifier;
				}
			)
		);

		return self::$notifiers;
	}

	/**
	 * Channels that are toggled on AND fully configured.
	 *
	 * @return DB_Chat_Notifier[]
	 */
	public static function get_enabled_notifiers(): array {
		$enabled = array();

		foreach ( self::get_notifiers() as $notifier ) {
			try {
				if ( $notifier->is_enabled() ) {
					$enabled[] = $notifier;
				}
			} catch ( \Throwable $e ) {
				continue;
			}
		}

		return $enabled;
	}

	/**
	 * Fetch a single channel by id.
	 */
	public static function get_notifier( string $id ): ?DB_Chat_Notifier {
		foreach ( self::get_notifiers() as $notifier ) {
			if ( $notifier->get_id() === $id ) {
				return $notifier;
			}
		}
		return null;
	}

	/**
	 * Build the owner-facing message.
	 *
	 * Minimal by design: order number, total, items, customer FIRST NAME only,
	 * payment method title, admin link. No address, no email, no phone, no
	 * payment identifiers of any kind.
	 */
	public static function build_message( WC_Order $order ): string {
		$lines = array();

		$lines[] = sprintf(
			/* translators: %s: order number */
			__( 'New order #%s', 'durianbikers-chatbot' ),
			$order->get_order_number()
		);

		$lines[] = sprintf(
			/* translators: 1: currency code, 2: order total */
			__( 'Total: %1$s %2$s', 'durianbikers-chatbot' ),
			$order->get_currency(),
			number_format( (float) $order->get_total(), 2, '.', ',' )
		);

		$first_name = trim( (string) $order->get_billing_first_name() );
		if ( '' !== $first_name ) {
			$lines[] = sprintf(
				/* translators: %s: customer first name */
				__( 'Customer: %s', 'durianbikers-chatbot' ),
				$first_name
			);
		}

		$payment = trim( (string) $order->get_payment_method_title() );
		if ( '' !== $payment ) {
			$lines[] = sprintf(
				/* translators: %s: payment method title */
				__( 'Payment: %s', 'durianbikers-chatbot' ),
				$payment
			);
		}

		$items = $order->get_items();
		if ( ! empty( $items ) ) {
			$lines[] = '';
			$lines[] = __( 'Items:', 'durianbikers-chatbot' );

			$shown = 0;
			foreach ( $items as $item ) {
				if ( $shown >= 25 ) {
					$lines[] = sprintf(
						/* translators: %d: number of additional line items */
						__( '  ...and %d more line(s)', 'durianbikers-chatbot' ),
						count( $items ) - $shown
					);
					break;
				}

				$lines[] = sprintf(
					'  %s x%d',
					wp_strip_all_tags( (string) $item->get_name() ),
					(int) $item->get_quantity()
				);
				$shown++;
			}
		}

		$lines[] = '';
		$lines[] = self::get_admin_order_url( $order );

		$message = implode( "\n", $lines );

		/**
		 * Filter the owner notification text.
		 *
		 * @param string   $message
		 * @param WC_Order $order
		 */
		$message = (string) apply_filters( 'db_chat_order_notification', $message, $order );

		return trim( $message );
	}

	/**
	 * wp-admin edit URL for the order, HPOS-safe.
	 */
	private static function get_admin_order_url( WC_Order $order ): string {
		if ( method_exists( $order, 'get_edit_order_url' ) ) {
			$url = (string) $order->get_edit_order_url();
			if ( '' !== $url ) {
				return $url;
			}
		}

		return admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );
	}

	/**
	 * admin_post handler for the per-channel "Send test notification" button.
	 *
	 * Nonce + capability gated. Sends fixed placeholder text — never real order
	 * data — so a test can be run safely on a live store.
	 */
	public static function handle_test_notification(): void {
		try {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'You are not allowed to do that.', 'durianbikers-chatbot' ), 403 );
			}

			$channel = isset( $_GET['channel'] ) ? sanitize_key( wp_unslash( $_GET['channel'] ) ) : '';

			check_admin_referer( 'db_chat_test_notification_' . $channel );

			$redirect = add_query_arg(
				array(
					'page'            => 'db-chat-settings',
					'db_chat_channel' => $channel,
				),
				admin_url( 'admin.php' )
			);

			$notifier = self::get_notifier( $channel );

			if ( ! $notifier ) {
				wp_safe_redirect( add_query_arg( 'db_chat_test', 'unknown', $redirect ) );
				exit;
			}

			if ( ! $notifier->is_enabled() ) {
				wp_safe_redirect( add_query_arg( 'db_chat_test', 'disabled', $redirect ) );
				exit;
			}

			$message = sprintf(
				/* translators: 1: site name, 2: date/time */
				__( "Test notification from %1\$s\nDurian Bikers chatbot order alerts are wired up correctly.\nSent %2\$s. No order data in this message.", 'durianbikers-chatbot' ),
				wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				current_time( 'Y-m-d H:i' )
			);

			$ok = false;
			try {
				$ok = (bool) $notifier->send( $message );
			} catch ( \Throwable $e ) {
				error_log( '[db_chat_notify] test send failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			wp_safe_redirect( add_query_arg( 'db_chat_test', $ok ? 'sent' : 'failed', $redirect ) );
			exit;

		} catch ( \Throwable $e ) {
			error_log( '[db_chat_notify] test handler failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			wp_safe_redirect( admin_url( 'admin.php?page=db-chat-settings&db_chat_test=failed' ) );
			exit;
		}
	}

	/**
	 * Load the notifier files if the bootstrap has not already done so.
	 * Only ever requires files inside this plugin's notifiers/ directory.
	 */
	private static function require_notifiers(): void {
		$dir = __DIR__ . '/notifiers/';

		$files = array(
			'DB_Chat_Notifier'             => 'interface-notifier.php',
			'DB_Chat_Telegram_Notifier'    => 'class-telegram-notifier.php',
			'DB_Chat_Slack_Notifier'       => 'class-slack-notifier.php',
			'DB_Chat_Email_Notifier'       => 'class-email-notifier.php',
			'DB_Chat_WhatsApp_Notifier'    => 'class-whatsapp-notifier.php',
		);

		foreach ( $files as $symbol => $file ) {
			if ( interface_exists( $symbol ) || class_exists( $symbol ) ) {
				continue;
			}
			$path = $dir . $file;
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	}
}
