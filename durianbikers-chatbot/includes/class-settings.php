<?php
/**
 * Admin settings screen for the Durian Bikers chatbot.
 *
 * Registers EVERY option the plugin reads — the core chatbot options as well as
 * the notification channel credentials — each with its own sanitize_callback.
 *
 * SECRET HANDLING: stored secrets are never echoed back into the HTML. A secret
 * field always renders with an empty value and a masked placeholder when a value
 * is set. Submitting an empty secret field keeps the stored value; clearing one
 * requires ticking its explicit "Remove stored value" box.
 *
 * @package durianbikers-chatbot
 */

defined( 'ABSPATH' ) || exit;

class DB_Chat_Settings {

	const OPTION_GROUP = 'db_chat_settings';
	const PAGE_SLUG    = 'db-chat-settings';
	const CAPABILITY   = 'manage_woocommerce';

	/** Mask shown in place of a stored secret. Never a real value. */
	const MASK = '••••••••••••';

	/** admin_post action that empties the unmatched-questions log. */
	const CLEAR_UNMATCHED_ACTION = 'db_chat_clear_unmatched';

	/** Rows shown on screen. The log itself may hold more. */
	const UNMATCHED_SHOWN = 100;

	/**
	 * Fallback storage for the unmatched log.
	 *
	 * Only read when DB_Chat_Lookup_Engine exposes no accessor — see
	 * unmatched_questions() for why that indirection exists.
	 */
	const UNMATCHED_OPTION = 'db_chat_log_unmatched_entries';

	/** The recording on/off switch, owned by the lookup engine. */
	const UNMATCHED_ENABLED_OPTION = 'db_chat_log_unmatched';

	/**
	 * wp-config constant that takes precedence over the db_chat_api_key option.
	 *
	 * The Claude client reads the key constant-first: this constant if defined and
	 * non-empty, otherwise the option. Keeping the key out of the database is the
	 * house rule for credentials, but it means a key typed into the settings field
	 * is silently ignored while the constant exists — so the field says so.
	 *
	 * Checked with defined()/constant() rather than through the client class so the
	 * settings screen stays correct regardless of plugin load order.
	 */
	const API_KEY_CONSTANT = 'DB_CHAT_ANTHROPIC_API_KEY';

	/**
	 * Option name => camelCase key handed to wp_localize_script as dbChat.*
	 * All optional: an empty value means "use the JS default".
	 */
	const COPY_MAP = array(
		'db_chat_title'           => 'title',
		'db_chat_subtitle'        => 'subtitle',
		'db_chat_placeholder'     => 'placeholder',
		'db_chat_launcher_label'  => 'launcherLabel',
		'db_chat_offline_text'    => 'offlineText',
		'db_chat_error_text'      => 'errorText',
		'db_chat_rate_limit_text' => 'rateLimitText',
		'db_chat_footnote'        => 'footnote',
		'db_chat_avatar'          => 'avatar',
	);

	/** Options treated as write-only secrets. */
	const SECRET_OPTIONS = array(
		'db_chat_api_key',
		'db_chat_telegram_token',
		'db_chat_slack_webhook',
		'db_chat_whatsapp_token',
	);

	/**
	 * Plugin directory slugs known to render a competing chat widget.
	 * Read only — nothing is ever deactivated programmatically.
	 */
	const CONFLICT_SLUGS = array(
		'ryan-chat' => 'Ryan Chat for WooCommerce',
	);

	/**
	 * Register hooks. Static, idempotent.
	 */
	public static function init(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_conflict_notice' ) );
		add_action( 'admin_post_' . self::CLEAR_UNMATCHED_ACTION, array( __CLASS__, 'handle_clear_unmatched' ) );

		// NOTE: page-cache purging on option change is deliberately NOT done here.
		// It lives in the plugin bootstrap (register_cache_purge_hooks() /
		// purge_page_cache() in durianbikers-chatbot.php), which debounces a
		// 13-option settings save down to a single purge with a static guard. That
		// guard cannot suppress a purge triggered from this class, so adding one
		// here would double-purge on every save. One mechanism, one place.
		// If a NEW option is added here that gets localized into the page, tell the
		// bootstrap to add it to its list rather than purging from this class.
	}

	/* ---------------------------------------------------------------------
	 * Public accessors (the core chatbot classes read through these).
	 * ------------------------------------------------------------------ */

	/**
	 * Read an option as a trimmed string.
	 *
	 * @param string $option  Option name.
	 * @param string $default Fallback.
	 */
	public static function get( string $option, string $default = '' ): string {
		$value = get_option( $option, $default );
		return is_scalar( $value ) ? trim( (string) $value ) : $default;
	}

	/**
	 * Is the Anthropic key supplied via the wp-config constant?
	 *
	 * Returns a plain boolean and never touches the key value itself.
	 */
	public static function key_is_constant(): bool {
		return defined( self::API_KEY_CONSTANT )
			&& '' !== trim( (string) constant( self::API_KEY_CONSTANT ) );
	}

	/**
	 * Is an API key available from EITHER source?
	 *
	 * Must mirror the client's constant-first lookup — checking the option alone
	 * would report "no key" on a correctly configured site that keeps the key in
	 * wp-config.php.
	 */
	public static function has_api_key(): bool {
		return self::key_is_constant() || '' !== self::get( 'db_chat_api_key' );
	}

	/**
	 * Which brain the owner asked for: auto | lookup | ai | hybrid.
	 *
	 * Mirrors DB_Chat_Plugin::get_mode() for the settings screen; the bootstrap
	 * remains the source of truth at runtime.
	 */
	public static function get_mode(): string {
		$mode = strtolower( self::get( 'db_chat_mode', 'auto' ) );

		return in_array( $mode, array( 'auto', 'lookup', 'ai', 'hybrid' ), true ) ? $mode : 'auto';
	}

	/**
	 * Which brain will actually answer right now: ai | lookup | none.
	 *
	 * Delegated to the bootstrap where possible so this screen can never tell
	 * the owner something different from what the storefront does.
	 */
	public static function active_engine(): string {
		if ( function_exists( 'db_chat' ) && is_callable( array( db_chat(), 'get_engine' ) ) ) {
			return (string) db_chat()->get_engine();
		}

		$mode   = self::get_mode();
		$key    = self::has_api_key();
		$lookup = class_exists( 'DB_Chat_Lookup_Engine' );

		if ( 'ai' === $mode ) {
			return $key ? 'ai' : 'none';
		}

		if ( 'lookup' === $mode ) {
			return $lookup ? 'lookup' : 'none';
		}

		if ( $key ) {
			return 'ai';
		}

		return $lookup ? 'lookup' : 'none';
	}

	/**
	 * Chatbot is live only when it is toggled on AND a usable brain exists.
	 *
	 * CHANGED Aug 2026: "usable" is no longer "has an API key". In lookup mode
	 * there is no key, and the widget must still render. Delegated to the
	 * bootstrap, which owns the rule and also applies the db_chat_is_enabled
	 * filter; the literal fallback is only for the case where this class is
	 * somehow loaded without the plugin bootstrap.
	 */
	public static function is_enabled(): bool {
		if ( function_exists( 'db_chat' ) && is_callable( array( db_chat(), 'is_enabled' ) ) ) {
			return (bool) db_chat()->is_enabled();
		}

		return '1' === self::get( 'db_chat_enabled' ) && 'none' !== self::active_engine();
	}

	public static function is_logging_enabled(): bool {
		return '1' === self::get( 'db_chat_logging' );
	}

	/**
	 * Prompt chips as a flat array of plain strings (max 3).
	 *
	 * @return string[]
	 */
	public static function get_suggestions(): array {
		$stored = get_option( 'db_chat_suggestions', self::default_suggestions() );

		if ( is_string( $stored ) ) {
			$stored = self::lines_to_array( $stored );
		}

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$clean = array();
		foreach ( $stored as $line ) {
			if ( ! is_scalar( $line ) ) {
				continue;
			}
			$line = trim( (string) $line );
			if ( '' !== $line ) {
				$clean[] = $line;
			}
		}

		/**
		 * Filter the suggested prompt chips.
		 *
		 * @param string[] $clean
		 */
		$clean = apply_filters( 'db_chat_suggestions', array_slice( $clean, 0, 3 ) );

		return array_values( array_filter( array_map( 'strval', (array) $clean ), 'strlen' ) );
	}

	/**
	 * Copy overrides for wp_localize_script, camelCase keyed, empties removed.
	 *
	 * Intended use: array_merge( $js_defaults, DB_Chat_Settings::get_localize_copy() ).
	 *
	 * @return array<string,string>
	 */
	public static function get_localize_copy(): array {
		$out = array();

		foreach ( self::COPY_MAP as $option => $key ) {
			$value = self::get( $option );
			if ( '' !== $value ) {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Menu
	 * ------------------------------------------------------------------ */

	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Chatbot', 'durianbikers-chatbot' ),
			__( 'Chatbot', 'durianbikers-chatbot' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Settings registration
	 * ------------------------------------------------------------------ */

	public static function register_settings(): void {

		/* ---------- core chatbot ---------- */

		self::register( 'db_chat_enabled', '0', array( __CLASS__, 'sanitize_bool' ) );
		self::register( 'db_chat_mode', 'auto', array( __CLASS__, 'sanitize_mode' ) );
		self::register( 'db_chat_ai_monthly_cap_myr', '100', array( __CLASS__, 'sanitize_cap' ) );

		self::register(
			'db_chat_api_key',
			'',
			static function ( $value ) {
				return self::sanitize_secret( $value, 'db_chat_api_key' );
			}
		);

		self::register( 'db_chat_model', 'claude-haiku-4-5-20251001', array( __CLASS__, 'sanitize_model' ) );
		self::register( 'db_chat_greeting', self::default_greeting(), array( __CLASS__, 'sanitize_text' ) );

		register_setting(
			self::OPTION_GROUP,
			'db_chat_suggestions',
			array(
				'type'              => 'array',
				'default'           => self::default_suggestions(),
				'sanitize_callback' => array( __CLASS__, 'sanitize_suggestions' ),
				'show_in_rest'      => false,
			)
		);

		self::register( 'db_chat_logging', '0', array( __CLASS__, 'sanitize_bool' ) );

		/*
		 * Defaults to '1' (ON), matching DB_Chat_Lookup_Engine's own default.
		 * Registering it with a '0' default here would have silently switched
		 * recording OFF for every store the moment this screen shipped.
		 */
		self::register( 'db_chat_log_unmatched', '1', array( __CLASS__, 'sanitize_bool' ) );

		/* ---------- appearance & copy (all optional) ---------- */

		foreach ( array_keys( self::COPY_MAP ) as $option ) {
			if ( 'db_chat_avatar' === $option ) {
				self::register( $option, '', array( __CLASS__, 'sanitize_url' ) );
			} else {
				self::register( $option, '', array( __CLASS__, 'sanitize_text' ) );
			}
		}

		/* ---------- human handoff (customer -> WhatsApp) ----------
		 *
		 * Nothing to do with the WhatsApp channel further down. That one sends
		 * ORDER ALERTS to the owner over the Meta Cloud API and needs a token.
		 * This one is a plain wa.me link shown to CUSTOMERS in the chat window
		 * and needs no credentials at all.
		 */

		self::register( 'db_chat_handoff_number', '', array( __CLASS__, 'sanitize_handoff_number' ) );
		self::register( 'db_chat_handoff_label', '', array( __CLASS__, 'sanitize_text' ) );
		self::register( 'db_chat_handoff_hours', self::default_handoff_hours(), array( __CLASS__, 'sanitize_handoff_hours' ) );
		self::register( 'db_chat_handoff_hours_text', '', array( __CLASS__, 'sanitize_text' ) );

		/* ---------- Telegram ---------- */

		self::register( 'db_chat_telegram_enabled', '0', array( __CLASS__, 'sanitize_bool' ) );
		self::register(
			'db_chat_telegram_token',
			'',
			static function ( $value ) {
				return self::sanitize_secret( $value, 'db_chat_telegram_token' );
			}
		);
		self::register( 'db_chat_telegram_chat_id', '', array( __CLASS__, 'sanitize_chat_id' ) );

		/* ---------- Slack ---------- */

		self::register( 'db_chat_slack_enabled', '0', array( __CLASS__, 'sanitize_bool' ) );
		self::register(
			'db_chat_slack_webhook',
			'',
			static function ( $value ) {
				return self::sanitize_slack_webhook( $value );
			}
		);

		/* ---------- Email ---------- */

		self::register( 'db_chat_email_enabled', '0', array( __CLASS__, 'sanitize_bool' ) );
		self::register( 'db_chat_notify_email', '', array( __CLASS__, 'sanitize_email_option' ) );

		/* ---------- WhatsApp ---------- */

		self::register( 'db_chat_whatsapp_enabled', '0', array( __CLASS__, 'sanitize_bool' ) );
		self::register(
			'db_chat_whatsapp_token',
			'',
			static function ( $value ) {
				return self::sanitize_secret( $value, 'db_chat_whatsapp_token' );
			}
		);
		self::register( 'db_chat_whatsapp_phone_id', '', array( __CLASS__, 'sanitize_digits' ) );
		self::register( 'db_chat_whatsapp_to', '', array( __CLASS__, 'sanitize_digits' ) );
	}

	/**
	 * Thin wrapper around register_setting for scalar string options.
	 */
	private static function register( string $option, string $default, $sanitize ): void {
		register_setting(
			self::OPTION_GROUP,
			$option,
			array(
				'type'              => 'string',
				'default'           => $default,
				'sanitize_callback' => $sanitize,
				'show_in_rest'      => false,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Sanitizers
	 * ------------------------------------------------------------------ */

	public static function sanitize_bool( $value ): string {
		return ( '1' === (string) $value || 1 === $value || true === $value ) ? '1' : '0';
	}

	/**
	 * Answering mode. Anything unrecognised becomes 'auto', which is the safe
	 * default: it uses the API key if there is one and the free lookup if not,
	 * so the chatbot keeps working either way.
	 */
	public static function sanitize_mode( $value ): string {
		$value = strtolower( self::sanitize_text( $value ) );

		return in_array( $value, array( 'auto', 'lookup', 'ai', 'hybrid' ), true ) ? $value : 'auto';
	}

	/**
	 * Monthly AI cap in MYR: a number from 0 to 10,000.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public static function sanitize_cap( $value ): string {
		$number = is_numeric( $value ) ? (float) $value : 100.0;
		$number = max( 0.0, min( 10000.0, $number ) );

		return (string) round( $number, 2 );
	}

	public static function sanitize_text( $value ): string {
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	public static function sanitize_url( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( (string) $value );
		return '' === $value ? '' : esc_url_raw( $value );
	}

	/**
	 * Model ids are conservative slugs. Reject anything else rather than
	 * shipping a mangled string to the Anthropic API.
	 */
	public static function sanitize_model( $value ): string {
		$value = self::sanitize_text( $value );

		if ( '' === $value ) {
			return 'claude-haiku-4-5-20251001';
		}

		if ( ! preg_match( '/^[A-Za-z0-9._\-]{1,100}$/', $value ) ) {
			add_settings_error(
				'db_chat_model',
				'db_chat_model_invalid',
				__( 'That model id contains unexpected characters. The previous value was kept.', 'durianbikers-chatbot' ),
				'error'
			);
			return self::get( 'db_chat_model', 'claude-haiku-4-5-20251001' );
		}

		return $value;
	}

	/**
	 * Textarea -> flat array of up to 3 plain strings.
	 */
	public static function sanitize_suggestions( $value ): array {
		if ( is_string( $value ) ) {
			$value = self::lines_to_array( $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();
		foreach ( $value as $line ) {
			if ( ! is_scalar( $line ) ) {
				continue;
			}
			$line = sanitize_text_field( (string) $line );
			if ( '' !== $line ) {
				$clean[] = $line;
			}
			if ( count( $clean ) >= 3 ) {
				break;
			}
		}

		return array_values( $clean );
	}

	/**
	 * Telegram chat ids are numeric and may be negative (groups/channels),
	 * or an @channelusername.
	 */
	public static function sanitize_chat_id( $value ): string {
		$value = self::sanitize_text( $value );

		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '/^-?[0-9]{1,32}$/', $value ) || preg_match( '/^@[A-Za-z0-9_]{4,64}$/', $value ) ) {
			return $value;
		}

		add_settings_error(
			'db_chat_telegram_chat_id',
			'db_chat_telegram_chat_id_invalid',
			__( 'Telegram chat ID must be a number (it can be negative for groups) or an @channelusername.', 'durianbikers-chatbot' ),
			'error'
		);

		return self::get( 'db_chat_telegram_chat_id' );
	}

	public static function sanitize_digits( $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		return preg_replace( '/[^0-9]/', '', $value );
	}

	/**
	 * Customer-facing WhatsApp number for the chat handoff button.
	 *
	 * Digits only, international format. Rejecting a leading zero is the point
	 * of this sanitizer, not pedantry: `0123456789` is the local way of writing
	 * the number, and wa.me would either fail or resolve it to somebody else
	 * entirely. Silently storing it would give us a button that quietly sends
	 * customers nowhere, which nobody notices until they complain twice.
	 */
	public static function sanitize_handoff_number( $value ): string {
		$digits = self::sanitize_digits( $value );

		if ( '' === $digits ) {
			return '';
		}

		if ( '0' === $digits[0] ) {
			add_settings_error(
				'db_chat_handoff_number',
				'db_chat_handoff_number_leading_zero',
				__( 'The WhatsApp number must start with a country code, not a zero. Drop the leading zero and add 60 for Malaysia — 012-345 6789 becomes 60123456789. The previous value was kept.', 'durianbikers-chatbot' ),
				'error'
			);

			return self::get( 'db_chat_handoff_number' );
		}

		$length = strlen( $digits );

		if ( $length < 7 || $length > 15 ) {
			add_settings_error(
				'db_chat_handoff_number',
				'db_chat_handoff_number_length',
				__( 'That does not look like a full international phone number (it should be 7 to 15 digits, country code first). The previous value was kept.', 'durianbikers-chatbot' ),
				'error'
			);

			return self::get( 'db_chat_handoff_number' );
		}

		return $digits;
	}

	/**
	 * Opening hours used to decide whether a human is available right now.
	 *
	 * Validated by the same parser that reads it at runtime, so an unparseable
	 * timetable is refused at save time rather than silently making the widget
	 * report "closed" forever.
	 */
	public static function sanitize_handoff_hours( $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = trim( sanitize_textarea_field( $value ) );

		if ( '' === $value ) {
			return '';
		}

		if ( ! class_exists( 'DB_Chat_Handoff' ) ) {
			// The parser is not loaded; store the text rather than lose it.
			return $value;
		}

		if ( null === DB_Chat_Handoff::parse_hours( $value ) ) {
			add_settings_error(
				'db_chat_handoff_hours',
				'db_chat_handoff_hours_invalid',
				__( 'Those opening hours could not be read. Use one rule per line, for example "mon-fri 10:00-18:00", "sat 10:00-15:00", "sun closed". Times need a 24-hour HH:MM and the closing time must be later than the opening time. The previous value was kept.', 'durianbikers-chatbot' ),
				'error'
			);

			return self::get( 'db_chat_handoff_hours' );
		}

		return $value;
	}

	public static function sanitize_email_option( $value ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $value ) {
			return '';
		}

		$email = sanitize_email( $value );

		if ( ! is_email( $email ) ) {
			add_settings_error(
				'db_chat_notify_email',
				'db_chat_notify_email_invalid',
				__( 'That notification email address is not valid. The previous value was kept.', 'durianbikers-chatbot' ),
				'error'
			);
			return self::get( 'db_chat_notify_email' );
		}

		return $email;
	}

	/**
	 * Slack webhook is a secret AND a URL: keep-if-empty behaviour plus a host check.
	 */
	public static function sanitize_slack_webhook( $value ): string {
		$value = self::sanitize_secret( $value, 'db_chat_slack_webhook' );

		if ( '' === $value ) {
			return '';
		}

		$url  = esc_url_raw( $value );
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( 'hooks.slack.com' !== $host ) {
			add_settings_error(
				'db_chat_slack_webhook',
				'db_chat_slack_webhook_invalid',
				__( 'Slack webhook must be a https://hooks.slack.com/... URL. The previous value was kept.', 'durianbikers-chatbot' ),
				'error'
			);
			return (string) get_option( 'db_chat_slack_webhook', '' );
		}

		return $url;
	}

	/**
	 * Shared secret handling.
	 *
	 * - "<option>_clear" ticked  -> wipe the stored value.
	 * - empty submission        -> keep the stored value (the field never
	 *                              renders the secret, so empty means "unchanged").
	 * - otherwise               -> store the new value.
	 *
	 * Reading $_POST here is safe: the Settings API has already verified the
	 * option-page nonce before any sanitize_callback runs.
	 */
	public static function sanitize_secret( $value, string $option ): string {
		$clear_field = $option . '_clear';

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by options.php.
		if ( ! empty( $_POST[ $clear_field ] ) ) {
			return '';
		}

		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $value || self::MASK === $value ) {
			return (string) get_option( $option, '' );
		}

		// Secrets are opaque tokens: strip control characters, keep everything else.
		$value = preg_replace( '/[\x00-\x1F\x7F]/', '', $value );

		return (string) $value;
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'durianbikers-chatbot' ), 403 );
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Durian Bikers Chatbot', 'durianbikers-chatbot' ); ?></h1>

			<?php
			self::render_test_result_notice();

			// This submenu lives under WooCommerce, not options-general.php, so
			// WordPress does not print the "Settings saved" / validation notices
			// for us. Do it explicitly.
			settings_errors();
			?>

			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<?php self::render_mode_section(); ?>

				<h2><?php esc_html_e( 'Chatbot', 'durianbikers-chatbot' ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
						<?php
						self::row_checkbox(
							'db_chat_enabled',
							__( 'Enable chatbot', 'durianbikers-chatbot' ),
							__( 'Show the chat widget on the storefront. It also needs a working answering mode: with "Free lookup" that is all it needs, with "Claude" it additionally needs an API key — either the field below, or the DB_CHAT_ANTHROPIC_API_KEY constant in wp-config.php.', 'durianbikers-chatbot' )
						);

						self::row_secret(
							'db_chat_api_key',
							__( 'Claude API key', 'durianbikers-chatbot' ),
							__( 'Used by the Claude only and Hybrid modes. In Hybrid mode, questions the free engine cannot answer are sent to Anthropic\'s API. Stored server-side only: never sent to the browser, never printed and never written to a log. Leave blank to keep the current key. Preferred alternative: define DB_CHAT_ANTHROPIC_API_KEY in wp-config.php to keep the key out of the database entirely.', 'durianbikers-chatbot' ),
							self::key_is_constant() ? self::API_KEY_CONSTANT : ''
						);

						self::row_text(
							'db_chat_model',
							__( 'Model', 'durianbikers-chatbot' ),
							__( 'Default: claude-haiku-4-5-20251001. Ignored in free lookup mode.', 'durianbikers-chatbot' ),
							'claude-haiku-4-5-20251001'
						);

						self::row_text(
							'db_chat_greeting',
							__( 'Greeting', 'durianbikers-chatbot' ),
							__( 'First message shown when the panel opens.', 'durianbikers-chatbot' ),
							self::default_greeting(),
							'large-text'
						);

						self::row_suggestions();

						self::row_checkbox(
							'db_chat_logging',
							__( 'Log conversations', 'durianbikers-chatbot' ),
							__( 'Off by default. When on, customer messages are written to the PHP error log. Only enable this temporarily while debugging.', 'durianbikers-chatbot' )
						);

						/*
						 * Deliberately adjacent to the row above: these two are
						 * the easy pair to confuse, and the contrast is clearest
						 * when they are read together. Different defaults on
						 * purpose — one writes whole conversations to the error
						 * log, the other keeps three fields about a question
						 * nobody could answer.
						 */
						self::row_checkbox(
							'db_chat_log_unmatched',
							__( 'Record unanswered questions', 'durianbikers-chatbot' ),
							__( 'On by default, and safe to leave on. NOT the same as "Log conversations" above. It stores three things and nothing else: the question text, the time, and whether it looked like English or Malay. No customer, no IP address, no session, no conversation history and no replies. The list appears at the bottom of this page. Switching this off stops new questions being recorded; it does not clear what is already there.', 'durianbikers-chatbot' )
						);
						?>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Appearance &amp; copy', 'durianbikers-chatbot' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'All optional. Leave a field blank to use the built-in wording.', 'durianbikers-chatbot' ); ?>
					<br>
					<strong><?php esc_html_e( 'Purge the page cache after changing these.', 'durianbikers-chatbot' ); ?></strong>
					<?php esc_html_e( 'The storefront is served from a full-page cache with a long lifetime, and this wording is baked into the cached HTML. Logged-in admins bypass the cache, so the change will look live to you while ordinary visitors keep seeing the old text until the cache is purged.', 'durianbikers-chatbot' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tbody>
						<?php
						self::row_text( 'db_chat_title', __( 'Panel title', 'durianbikers-chatbot' ), '' );
						self::row_text( 'db_chat_subtitle', __( 'Panel subtitle', 'durianbikers-chatbot' ), '' );
						self::row_text( 'db_chat_placeholder', __( 'Input placeholder', 'durianbikers-chatbot' ), '' );
						self::row_text( 'db_chat_launcher_label', __( 'Launcher aria-label', 'durianbikers-chatbot' ), __( 'Screen-reader label for the floating button.', 'durianbikers-chatbot' ) );
						self::row_text( 'db_chat_offline_text', __( 'Offline message', 'durianbikers-chatbot' ), __( 'Shown when no answering mode is usable — for example "Claude only" with no API key. Not used in free lookup mode, which needs no key and stays online. Customer-facing: this text is also returned verbatim as the REST API 503 error message, so keep it plain and free of internal detail.', 'durianbikers-chatbot' ) );
						self::row_text( 'db_chat_error_text', __( 'Error message', 'durianbikers-chatbot' ), '' );
						self::row_text( 'db_chat_rate_limit_text', __( 'Rate limit message', 'durianbikers-chatbot' ), __( 'Shown after too many messages in a short window. Customer-facing: this text is also returned verbatim as the REST API 429 error message, so keep it plain and free of internal detail.', 'durianbikers-chatbot' ) );
						self::row_text( 'db_chat_footnote', __( 'Footnote', 'durianbikers-chatbot' ), __( 'Small print under the input, e.g. an AI disclaimer.', 'durianbikers-chatbot' ), '', 'large-text' );
						self::row_text( 'db_chat_avatar', __( 'Assistant avatar URL', 'durianbikers-chatbot' ), __( 'Optional image shown next to assistant replies.', 'durianbikers-chatbot' ), '', 'large-text' );
						?>
					</tbody>
				</table>

				<?php self::render_handoff_section(); ?>

				<h2><?php esc_html_e( 'Order notifications', 'durianbikers-chatbot' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Sent to you when a new order is placed. These are independent of the chatbot toggle above — you can run notifications with the chat widget switched off. Order alerts are sent once per order and never block or slow down checkout.', 'durianbikers-chatbot' ); ?>
				</p>

				<?php
				self::render_telegram_section();
				self::render_slack_section();
				self::render_email_section();
				self::render_whatsapp_section();
				?>

				<?php submit_button(); ?>
			</form>

			<hr>
			<p class="description">
				<?php esc_html_e( 'Test notifications send fixed placeholder text, never real order data, so they are safe to run on the live store. Save your settings before testing.', 'durianbikers-chatbot' ); ?>
			</p>

			<?php self::render_unmatched_section(); ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Channel sections
	 * ------------------------------------------------------------------ */

	/**
	 * Customer -> human handoff (the WhatsApp button inside the chat window).
	 *
	 * Rendered between the chat settings and the order notification channels on
	 * purpose: it belongs to the customer-facing chat widget, not to the owner
	 * alerts, and the whole section leads with that distinction because the two
	 * WhatsApp numbers on this screen are the obvious thing to confuse.
	 */
	private static function render_handoff_section(): void {
		$number    = self::get( 'db_chat_handoff_number' );
		$has_class = class_exists( 'DB_Chat_Handoff' );
		$active    = $has_class ? DB_Chat_Handoff::is_configured() : false;
		?>
		<h2><?php esc_html_e( 'Talk to a human (WhatsApp button)', 'durianbikers-chatbot' ); ?></h2>

		<div class="notice notice-info inline">
			<p>
				<strong><?php esc_html_e( 'This is not the same as the WhatsApp order alerts below.', 'durianbikers-chatbot' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'This number is shown to CUSTOMERS as a "chat to a human" button inside the chat window, and it is a plain WhatsApp link that needs no token or API setup. The WhatsApp section further down sends ORDER ALERTS to YOU through the Meta Cloud API and does need credentials.', 'durianbikers-chatbot' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'They can be the same phone number. They are still two different settings for two different jobs — filling in one does not fill in the other.', 'durianbikers-chatbot' ); ?>
			</p>
		</div>

		<?php if ( ! $active ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<strong><?php esc_html_e( 'Off.', 'durianbikers-chatbot' ); ?></strong>
					<?php esc_html_e( 'With no valid number here, the chat window shows no handoff button at all — the assistant simply says a human can help. Nothing half-built is ever displayed.', 'durianbikers-chatbot' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<table class="form-table" role="presentation">
			<tbody>
				<?php
				self::row_text(
					'db_chat_handoff_number',
					__( 'WhatsApp number for customers', 'durianbikers-chatbot' ),
					__( 'Digits only, in the format WhatsApp links use: country code first, no plus sign, no spaces, no dashes, and DROP the leading zero. Malaysian example: 012-345 6789 becomes 60123456789. Leave blank to hide the button.', 'durianbikers-chatbot' ),
					'60123456789'
				);

				self::row_text(
					'db_chat_handoff_label',
					__( 'Button label', 'durianbikers-chatbot' ),
					__( 'Optional. Default: "Chat with us on WhatsApp". The assistant refers to this button by name when it hands a customer over, so keep it recognisable.', 'durianbikers-chatbot' ),
					__( 'Chat with us on WhatsApp', 'durianbikers-chatbot' )
				);

				self::row_textarea(
					'db_chat_handoff_hours',
					__( 'Opening hours', 'durianbikers-chatbot' ),
					__( 'One rule per line: a day or day range, then the times in 24-hour HH:MM. For example "mon-fri 10:00-18:00", "sat 10:00-15:00", "sun closed". A split shift is "sat 10:00-13:00, 14:00-17:00". Any day you do not list is treated as closed. Read in the site timezone (Settings > General).', 'durianbikers-chatbot' ),
					self::default_handoff_hours(),
					4
				);

				self::row_text(
					'db_chat_handoff_hours_text',
					__( 'Hours shown to customers', 'durianbikers-chatbot' ),
					__( 'Optional. Leave blank and this is written for you from the opening hours above, so the two can never disagree. Only fill it in if you want different wording.', 'durianbikers-chatbot' ),
					$has_class ? DB_Chat_Handoff::get_hours_text() : '',
					'large-text'
				);
				?>

				<tr>
					<th scope="row"><?php esc_html_e( 'Right now', 'durianbikers-chatbot' ); ?></th>
					<td>
						<?php if ( ! $has_class ) : ?>
							<p class="description">
								<?php esc_html_e( 'The handoff module is not loaded, so no button will be shown. Re-upload includes/class-handoff.php.', 'durianbikers-chatbot' ); ?>
							</p>
						<?php elseif ( '' === $number ) : ?>
							<p class="description"><?php esc_html_e( 'No number set — the button is hidden.', 'durianbikers-chatbot' ); ?></p>
						<?php else : ?>
							<p>
								<?php if ( ! $active ) : ?>
									<strong><?php esc_html_e( 'The stored number is not usable, so the button stays hidden.', 'durianbikers-chatbot' ); ?></strong>
								<?php elseif ( DB_Chat_Handoff::is_open() ) : ?>
									<strong><?php esc_html_e( 'Open.', 'durianbikers-chatbot' ); ?></strong>
									<?php esc_html_e( 'Customers are being told someone usually replies within a few minutes.', 'durianbikers-chatbot' ); ?>
								<?php else : ?>
									<strong><?php esc_html_e( 'Closed.', 'durianbikers-chatbot' ); ?></strong>
									<?php esc_html_e( 'Customers are being told to send a message and you will reply when you open.', 'durianbikers-chatbot' ); ?>
								<?php endif; ?>
							</p>
							<p class="description">
								<?php
								printf(
									/* translators: %s: link the customer is sent to */
									esc_html__( 'Customers are sent to: %s', 'durianbikers-chatbot' ),
									'<code>' . esc_html( $active ? DB_Chat_Handoff::get_url() : '' ) . '</code>'
								);
								?>
								<br>
								<?php esc_html_e( 'Open or closed is worked out live each time the chat window is opened, in the site timezone, and is never cached — so this one does not need a cache purge. The number, label and hours wording are baked into cached pages: purge the page cache after changing those.', 'durianbikers-chatbot' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Answering mode.
	 *
	 * The copy here is deliberately blunt about the trade. Lookup mode is free
	 * and private, and it is NOT a language model: it matches keywords. An
	 * owner who expects it to "understand" will read every declined question as
	 * a bug, so the screen says what it is before they choose it.
	 */
	private static function render_mode_section(): void {
		$mode   = self::get_mode();
		$engine = self::active_engine();
		$key    = self::has_api_key();
		?>
		<h2><?php esc_html_e( 'Answering mode', 'durianbikers-chatbot' ); ?></h2>

		<div class="notice notice-info inline">
			<p>
				<strong><?php esc_html_e( 'Free lookup mode is not AI.', 'durianbikers-chatbot' ); ?></strong>
				<?php esc_html_e( 'It matches keywords in what the customer types against your products and your own help pages, then fills in a fixed reply. It does not understand questions. Wording it does not recognise is politely declined and pointed at the WhatsApp button — it never guesses.', 'durianbikers-chatbot' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'What it costs: nothing. It makes no external API calls at all, so no API key, no bill, and nothing about your customers leaves the server. It understands English and Bahasa Malaysia keywords.', 'durianbikers-chatbot' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'What it cannot do: fitment ("will this fit my CBR?"), recommendations ("which is better for touring?"), or anything phrased unusually. It does handle follow-ups for a few turns, and it can compare two named products side by side without judging between them. Claude mode handles the rest, and costs money per message.', 'durianbikers-chatbot' ); ?>
			</p>
		</div>

		<table class="form-table" role="presentation">
			<tbody>
				<?php
				self::row_select(
					'db_chat_mode',
					__( 'Mode', 'durianbikers-chatbot' ),
					array(
						'auto'   => __( 'Automatic — use Claude if an API key is set, otherwise free lookup (recommended)', 'durianbikers-chatbot' ),
						'lookup' => __( 'Free lookup only — never call the API, even if a key is set', 'durianbikers-chatbot' ),
						'ai'     => __( 'Claude only — needs an API key; chat stays offline without one', 'durianbikers-chatbot' ),
						'hybrid' => __( 'Hybrid — free lookup first, Claude only for questions it cannot understand (needs an API key; capped monthly)', 'durianbikers-chatbot' ),
					),
					__( 'Purge the page cache after changing this: it decides whether the widget appears at all, and that decision is baked into cached storefront HTML.', 'durianbikers-chatbot' )
				);
				?>

				<tr>
					<th scope="row"><?php esc_html_e( 'Right now', 'durianbikers-chatbot' ); ?></th>
					<td>
						<p>
							<?php if ( 'hybrid' === $mode && 'lookup' === $engine ) : ?>
								<strong><?php esc_html_e( 'Answering with free lookup, and Claude for questions it cannot understand.', 'durianbikers-chatbot' ); ?></strong>
								<?php if ( $key ) : ?>
									<?php esc_html_e( 'Only genuine misses are sent to Claude, within the monthly budget below. Fitment, stock, orders and buying never are.', 'durianbikers-chatbot' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'No API key is set, so no AI answers are being given yet: every question gets the free answer.', 'durianbikers-chatbot' ); ?>
								<?php endif; ?>
							<?php elseif ( 'lookup' === $engine ) : ?>
								<strong><?php esc_html_e( 'Answering with free lookup.', 'durianbikers-chatbot' ); ?></strong>
								<?php if ( 'auto' === $mode && ! $key ) : ?>
									<?php esc_html_e( 'No API key is set, so nothing is being charged. Add a key to switch this to Claude automatically.', 'durianbikers-chatbot' ); ?>
								<?php elseif ( 'lookup' === $mode && $key ) : ?>
									<?php esc_html_e( 'An API key is saved but is deliberately not being used, because the mode is set to free lookup only.', 'durianbikers-chatbot' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'Keyword matching only. No external calls are being made.', 'durianbikers-chatbot' ); ?>
								<?php endif; ?>
							<?php elseif ( 'ai' === $engine ) : ?>
								<strong><?php esc_html_e( 'Answering with Claude.', 'durianbikers-chatbot' ); ?></strong>
								<?php esc_html_e( 'Every customer message is a paid API call.', 'durianbikers-chatbot' ); ?>
							<?php elseif ( 'ai' === $mode ) : ?>
								<strong><?php esc_html_e( 'Offline.', 'durianbikers-chatbot' ); ?></strong>
								<?php esc_html_e( 'The mode is set to Claude only and there is no API key, so the widget is hidden and the chat route returns the offline message. Switch to Automatic to answer for free instead.', 'durianbikers-chatbot' ); ?>
							<?php else : ?>
								<strong><?php esc_html_e( 'Offline.', 'durianbikers-chatbot' ); ?></strong>
								<?php esc_html_e( 'Free lookup was selected but includes/class-lookup-engine.php is not on the server, so there is nothing to answer with. Re-upload that file.', 'durianbikers-chatbot' ); ?>
							<?php endif; ?>
						</p>
						<?php if ( '1' !== self::get( 'db_chat_enabled' ) ) : ?>
							<p class="description">
								<?php esc_html_e( 'The chatbot is switched off below, so nothing is shown to customers regardless of the mode.', 'durianbikers-chatbot' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( 'hybrid' === $mode ) : ?>
					<?php
					self::row_text(
						'db_chat_ai_monthly_cap_myr',
						__( 'Monthly AI budget (RM)', 'durianbikers-chatbot' ),
						__( 'An estimate the plugin enforces by falling back to the free answer. Set the same limit in the Anthropic console: that is the only hard stop.', 'durianbikers-chatbot' ),
						'100',
						'small-text'
					);
					?>
					<?php if ( class_exists( 'DB_Chat_Ai_Budget' ) ) : ?>
						<?php $db_chat_budget = new DB_Chat_Ai_Budget(); ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'AI spend this month', 'durianbikers-chatbot' ); ?></th>
							<td>
								<p>
									<?php
									printf(
										/* translators: 1: estimated spend; 2: monthly cap; 3: number of AI answers. */
										esc_html__( 'RM%1$s of RM%2$s estimated, across %3$d AI answers.', 'durianbikers-chatbot' ),
										esc_html( number_format_i18n( $db_chat_budget->month_to_date_myr(), 2 ) ),
										esc_html( number_format_i18n( $db_chat_budget->cap_myr(), 2 ) ),
										(int) $db_chat_budget->calls_this_month()
									);
									?>
								</p>
								<p class="description"><?php esc_html_e( 'Estimated from token counts and priced deliberately high.', 'durianbikers-chatbot' ); ?></p>
							</td>
						</tr>
					<?php endif; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_telegram_section(): void {
		?>
		<h3><?php esc_html_e( 'Telegram', 'durianbikers-chatbot' ); ?></h3>
		<table class="form-table" role="presentation">
			<tbody>
				<?php
				self::row_checkbox( 'db_chat_telegram_enabled', __( 'Enable Telegram', 'durianbikers-chatbot' ), '' );
				self::row_secret(
					'db_chat_telegram_token',
					__( 'Bot token', 'durianbikers-chatbot' ),
					__( 'From @BotFather, looks like 123456789:AA... Leave blank to keep the current token.', 'durianbikers-chatbot' )
				);
				self::row_text(
					'db_chat_telegram_chat_id',
					__( 'Chat ID', 'durianbikers-chatbot' ),
					__( 'Your personal chat ID, or a negative number for a group. Message @userinfobot to get yours.', 'durianbikers-chatbot' )
				);
				self::row_test_button( 'telegram' );
				?>
			</tbody>
		</table>
		<?php
	}

	private static function render_slack_section(): void {
		?>
		<h3><?php esc_html_e( 'Slack', 'durianbikers-chatbot' ); ?></h3>
		<table class="form-table" role="presentation">
			<tbody>
				<?php
				self::row_checkbox( 'db_chat_slack_enabled', __( 'Enable Slack', 'durianbikers-chatbot' ), '' );
				self::row_secret(
					'db_chat_slack_webhook',
					__( 'Incoming webhook URL', 'durianbikers-chatbot' ),
					__( 'Must be a https://hooks.slack.com/... URL. Treat it as a password — anyone with it can post to your channel.', 'durianbikers-chatbot' )
				);
				self::row_test_button( 'slack' );
				?>
			</tbody>
		</table>
		<?php
	}

	private static function render_email_section(): void {
		?>
		<h3><?php esc_html_e( 'Email', 'durianbikers-chatbot' ); ?></h3>
		<table class="form-table" role="presentation">
			<tbody>
				<?php
				self::row_checkbox( 'db_chat_email_enabled', __( 'Enable email', 'durianbikers-chatbot' ), '' );
				self::row_text(
					'db_chat_notify_email',
					__( 'Send to', 'durianbikers-chatbot' ),
					sprintf(
						/* translators: %s: site admin email address */
						__( 'Leave blank to use the site admin address (%s).', 'durianbikers-chatbot' ),
						(string) get_option( 'admin_email', '' )
					)
				);
				self::row_test_button( 'email' );
				?>
			</tbody>
		</table>
		<?php
	}

	private static function render_whatsapp_section(): void {
		$notifier   = class_exists( 'DB_Chat_Order_Notifier' ) ? DB_Chat_Order_Notifier::get_notifier( 'whatsapp' ) : null;
		$configured = ( $notifier instanceof DB_Chat_WhatsApp_Notifier ) ? $notifier->is_configured() : false;
		$missing    = ( $notifier instanceof DB_Chat_WhatsApp_Notifier ) ? $notifier->get_missing_credentials() : array();
		?>
		<h3><?php esc_html_e( 'WhatsApp (Meta Cloud API)', 'durianbikers-chatbot' ); ?></h3>

		<p class="description">
			<strong><?php esc_html_e( 'This sends order alerts to you, the owner.', 'durianbikers-chatbot' ); ?></strong>
			<?php esc_html_e( 'It is not the customer-facing WhatsApp button — that is the "Talk to a human" section above, which needs only a phone number.', 'durianbikers-chatbot' ); ?>
		</p>

		<?php if ( ! $configured ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<strong><?php esc_html_e( 'Inactive.', 'durianbikers-chatbot' ); ?></strong>
					<?php esc_html_e( 'This channel stays completely inert until all three credentials below are filled in — the enable toggle on its own does nothing.', 'durianbikers-chatbot' ); ?>
					<?php if ( ! empty( $missing ) ) : ?>
						<br>
						<?php
						printf(
							/* translators: %s: comma separated list of missing credential names */
							esc_html__( 'Still missing: %s', 'durianbikers-chatbot' ),
							esc_html( implode( ', ', $missing ) )
						);
						?>
					<?php endif; ?>
				</p>
			</div>
		<?php endif; ?>

		<div class="notice notice-info inline">
			<p>
				<strong><?php esc_html_e( 'Meta 24-hour customer service window.', 'durianbikers-chatbot' ); ?></strong>
				<?php esc_html_e( 'Meta only allows free-form text messages within 24 hours of the recipient last messaging your WhatsApp Business number. Order alerts are business-initiated, so they will usually fall outside that window and Meta will reject them (error 131047) unless you send an approved template message instead.', 'durianbikers-chatbot' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'Practical workaround while you finish setup: send any message from the recipient phone to your Business number to reopen the 24-hour window, or register and get approval for a message template and switch this channel to template sends. Until then treat WhatsApp as best-effort and keep Telegram or email enabled as the reliable channel.', 'durianbikers-chatbot' ); ?>
			</p>
		</div>

		<table class="form-table" role="presentation">
			<tbody>
				<?php
				self::row_checkbox( 'db_chat_whatsapp_enabled', __( 'Enable WhatsApp', 'durianbikers-chatbot' ), __( 'Has no effect until all three credentials below are set.', 'durianbikers-chatbot' ) );
				self::row_secret(
					'db_chat_whatsapp_token',
					__( 'Access token', 'durianbikers-chatbot' ),
					__( 'Permanent system-user token from Meta Business. Temporary tokens expire after 24 hours.', 'durianbikers-chatbot' )
				);
				self::row_text(
					'db_chat_whatsapp_phone_id',
					__( 'Phone number ID', 'durianbikers-chatbot' ),
					__( 'Numeric ID from the WhatsApp > API Setup screen. Not the phone number itself.', 'durianbikers-chatbot' )
				);
				self::row_text(
					'db_chat_whatsapp_to',
					__( 'Recipient number', 'durianbikers-chatbot' ),
					__( 'Your own number in international format, digits only, no plus sign. Malaysia example: 60123456789', 'durianbikers-chatbot' )
				);
				self::row_test_button( 'whatsapp' );
				?>
			</tbody>
		</table>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Field renderers
	 * ------------------------------------------------------------------ */

	private static function row_checkbox( string $option, string $label, string $description ): void {
		$value = self::get( $option );
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<input type="hidden" name="<?php echo esc_attr( $option ); ?>" value="0">
				<label>
					<input type="checkbox"
						name="<?php echo esc_attr( $option ); ?>"
						id="<?php echo esc_attr( $option ); ?>"
						value="1"
						<?php checked( '1', $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</label>
				<?php if ( '' !== $description ) : ?>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private static function row_text( string $option, string $label, string $description, string $placeholder = '', string $class = 'regular-text' ): void {
		$value = self::get( $option );
		?>
		<tr>
			<th scope="row">
				<label for="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $label ); ?></label>
			</th>
			<td>
				<input type="text"
					class="<?php echo esc_attr( $class ); ?>"
					name="<?php echo esc_attr( $option ); ?>"
					id="<?php echo esc_attr( $option ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
					placeholder="<?php echo esc_attr( $placeholder ); ?>"
					autocomplete="off">
				<?php if ( '' !== $description ) : ?>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Secret field. The stored value is NEVER rendered — the input is always
	 * empty and only a mask is shown as a placeholder when something is saved.
	 *
	 * When $overridden_by is a constant name, the field is locked: the value in
	 * wp-config wins, so accepting input here would be a lie. A disabled input is
	 * not submitted, and sanitize_secret() treats a missing/null value as
	 * "unchanged", so the stored option survives the save untouched.
	 *
	 * @param string $overridden_by Constant name taking precedence, or '' for none.
	 */
	private static function row_secret( string $option, string $label, string $description, string $overridden_by = '' ): void {
		$is_set   = '' !== self::get( $option );
		$is_locked = '' !== $overridden_by;
		?>
		<tr>
			<th scope="row">
				<label for="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $label ); ?></label>
			</th>
			<td>
				<input type="password"
					class="regular-text"
					name="<?php echo esc_attr( $option ); ?>"
					id="<?php echo esc_attr( $option ); ?>"
					value=""
					placeholder="<?php echo esc_attr( ! $is_locked && $is_set ? self::MASK : '' ); ?>"
					autocomplete="new-password"
					spellcheck="false"
					<?php disabled( true, $is_locked ); ?>>

				<?php if ( $is_locked ) : ?>
					<p class="description">
						<strong><?php esc_html_e( 'Set in wp-config.php.', 'durianbikers-chatbot' ); ?></strong>
						<?php
						printf(
							/* translators: %s: PHP constant name */
							esc_html__( 'The key is read from the %s constant, so this field is ignored and has been disabled. To change the key, edit wp-config.php.', 'durianbikers-chatbot' ),
							'<code>' . esc_html( $overridden_by ) . '</code>'
						);
						?>
					</p>
					<?php if ( $is_set ) : ?>
						<div class="notice notice-warning inline">
							<p>
								<?php esc_html_e( 'There is also a key saved in the database. It is NOT being used — the constant wins. Tick the box below and save to clear the unused copy out of the database.', 'durianbikers-chatbot' ); ?>
							</p>
						</div>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $option . '_clear' ); ?>" value="1">
							<?php esc_html_e( 'Remove the unused value stored in the database', 'durianbikers-chatbot' ); ?>
						</label>
					<?php endif; ?>
				<?php else : ?>
					<p class="description">
						<?php if ( $is_set ) : ?>
							<strong><?php esc_html_e( 'Saved.', 'durianbikers-chatbot' ); ?></strong>
							<?php esc_html_e( 'The stored value is hidden and is not sent to your browser. Leave this blank to keep it.', 'durianbikers-chatbot' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Not set.', 'durianbikers-chatbot' ); ?>
						<?php endif; ?>
						<?php if ( '' !== $description ) : ?>
							<br><?php echo esc_html( $description ); ?>
						<?php endif; ?>
					</p>

					<?php if ( $is_set ) : ?>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $option . '_clear' ); ?>" value="1">
							<?php esc_html_e( 'Remove stored value on save', 'durianbikers-chatbot' ); ?>
						</label>
					<?php endif; ?>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Select field. Values are an allowlist; the sanitizer re-checks them.
	 *
	 * @param array<string,string> $choices Value => label.
	 */
	private static function row_select( string $option, string $label, array $choices, string $description = '' ): void {
		$value = self::get( $option );

		if ( ! isset( $choices[ $value ] ) ) {
			$value = (string) array_key_first( $choices );
		}
		?>
		<tr>
			<th scope="row">
				<label for="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $label ); ?></label>
			</th>
			<td>
				<select name="<?php echo esc_attr( $option ); ?>" id="<?php echo esc_attr( $option ); ?>">
					<?php foreach ( $choices as $choice => $choice_label ) : ?>
						<option value="<?php echo esc_attr( (string) $choice ); ?>" <?php selected( $value, (string) $choice ); ?>>
							<?php echo esc_html( (string) $choice_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php if ( '' !== $description ) : ?>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private static function row_textarea( string $option, string $label, string $description, string $placeholder = '', int $rows = 4 ): void {
		$value = self::get( $option );
		?>
		<tr>
			<th scope="row">
				<label for="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $label ); ?></label>
			</th>
			<td>
				<textarea name="<?php echo esc_attr( $option ); ?>"
					id="<?php echo esc_attr( $option ); ?>"
					rows="<?php echo esc_attr( (string) max( 2, $rows ) ); ?>"
					class="large-text code"
					placeholder="<?php echo esc_attr( $placeholder ); ?>"
					spellcheck="false"><?php echo esc_textarea( $value ); ?></textarea>
				<?php if ( '' !== $description ) : ?>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private static function row_suggestions(): void {
		$lines = implode( "\n", self::get_suggestions() );
		?>
		<tr>
			<th scope="row">
				<label for="db_chat_suggestions"><?php esc_html_e( 'Suggested prompts', 'durianbikers-chatbot' ); ?></label>
			</th>
			<td>
				<textarea name="db_chat_suggestions"
					id="db_chat_suggestions"
					rows="3"
					class="large-text code"><?php echo esc_textarea( $lines ); ?></textarea>
				<p class="description">
					<?php esc_html_e( 'One prompt per line, maximum three. Shown as chips under the greeting. Like the greeting, these are baked into cached storefront HTML — purge the page cache after changing them.', 'durianbikers-chatbot' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * "Send test notification" link. A GET to admin-post.php with its own nonce,
	 * not AJAX, and rendered as a link so it does not nest inside the settings form.
	 */
	private static function row_test_button( string $channel ): void {
		$notifier = class_exists( 'DB_Chat_Order_Notifier' ) ? DB_Chat_Order_Notifier::get_notifier( $channel ) : null;
		$ready    = $notifier instanceof DB_Chat_Notifier && $notifier->is_enabled();

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'db_chat_test_notification',
					'channel' => $channel,
				),
				admin_url( 'admin-post.php' )
			),
			'db_chat_test_notification_' . $channel
		);
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Test', 'durianbikers-chatbot' ); ?></th>
			<td>
				<?php if ( $ready ) : ?>
					<a href="<?php echo esc_url( $url ); ?>" class="button">
						<?php esc_html_e( 'Send test notification', 'durianbikers-chatbot' ); ?>
					</a>
				<?php else : ?>
					<button type="button" class="button" disabled>
						<?php esc_html_e( 'Send test notification', 'durianbikers-chatbot' ); ?>
					</button>
					<p class="description">
						<?php esc_html_e( 'Enable the channel and save its credentials first.', 'durianbikers-chatbot' ); ?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Notices
	 * ------------------------------------------------------------------ */

	private static function render_test_result_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect result.
		$result = isset( $_GET['db_chat_test'] ) ? sanitize_key( wp_unslash( $_GET['db_chat_test'] ) ) : '';

		if ( '' === $result ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect result.
		$channel = isset( $_GET['db_chat_channel'] ) ? sanitize_key( wp_unslash( $_GET['db_chat_channel'] ) ) : '';

		switch ( $result ) {
			case 'sent':
				$class   = 'notice-success';
				$message = sprintf(
					/* translators: %s: channel id */
					__( 'Test notification handed to %s. Delivery is asynchronous, so check the channel itself to confirm it arrived.', 'durianbikers-chatbot' ),
					$channel
				);
				break;

			case 'disabled':
				$class   = 'notice-warning';
				$message = __( 'That channel is not enabled or is missing credentials, so nothing was sent.', 'durianbikers-chatbot' );
				break;

			case 'unknown':
				$class   = 'notice-error';
				$message = __( 'Unknown notification channel.', 'durianbikers-chatbot' );
				break;

			default:
				$class   = 'notice-error';
				$message = __( 'The test notification could not be sent. Check the PHP error log for details.', 'durianbikers-chatbot' );
				break;
		}

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
	}

	/**
	 * Warn if another chat widget plugin is active. Read only — this never
	 * deactivates anything, it just tells the operator so they do not end up
	 * with two launchers on the storefront.
	 */
	public static function maybe_render_conflict_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$active = get_option( 'active_plugins', array() );
		if ( ! is_array( $active ) ) {
			return;
		}

		if ( is_multisite() ) {
			$network = get_site_option( 'active_sitewide_plugins', array() );
			if ( is_array( $network ) ) {
				$active = array_merge( $active, array_keys( $network ) );
			}
		}

		$found = array();
		foreach ( $active as $plugin_file ) {
			$slug = strtok( (string) $plugin_file, '/' );
			if ( isset( self::CONFLICT_SLUGS[ $slug ] ) ) {
				$found[ $slug ] = self::CONFLICT_SLUGS[ $slug ];
			}
		}

		if ( empty( $found ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'Durian Bikers Chatbot:', 'durianbikers-chatbot' ),
			esc_html(
				sprintf(
					/* translators: %s: comma separated plugin names */
					__( 'another chat plugin is active (%s). Two chat widgets may render on the storefront. Deactivate the other plugin from the Plugins screen if that is not intended.', 'durianbikers-chatbot' ),
					implode( ', ', $found )
				)
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Defaults / helpers
	 * ------------------------------------------------------------------ */

	private static function default_greeting(): string {
		return __( 'Hi! Ask me anything about our parts, tyres and gear.', 'durianbikers-chatbot' );
	}

	/**
	 * The store's real opening hours, used when the field is left blank.
	 *
	 * Mirrors DB_Chat_Handoff::DEFAULT_HOURS; the literal fallback keeps the
	 * settings screen working if the handoff module has not been uploaded.
	 */
	private static function default_handoff_hours(): string {
		if ( class_exists( 'DB_Chat_Handoff' ) ) {
			return (string) DB_Chat_Handoff::DEFAULT_HOURS;
		}

		return "mon-fri 10:00-18:00\nsat 10:00-15:00\nsun closed";
	}

	/**
	 * @return string[]
	 */
	private static function default_suggestions(): array {
		return array(
			__( 'Do you have Shinko tyres?', 'durianbikers-chatbot' ),
			__( 'Show me helmets under RM 500', 'durianbikers-chatbot' ),
			__( 'What R&G sliders do you stock?', 'durianbikers-chatbot' ),
		);
	}

	/**
	 * Split a textarea value into a flat array of non-empty trimmed lines.
	 *
	 * @return string[]
	 */
	private static function lines_to_array( string $value ): array {
		$lines = preg_split( '/\r\n|\r|\n/', $value );

		if ( ! is_array( $lines ) ) {
			return array();
		}

		$out = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Unmatched questions
	 *
	 * The free lookup engine records every question it could not answer. This
	 * is the screen that shows them, and it is the most operationally useful
	 * thing on the page: the list is a to-do list, not a report. Almost every
	 * entry is one keyword away from working.
	 *
	 * SECURITY POSTURE. Every string below is raw text a stranger typed into a
	 * box on a public website. It is stored verbatim on purpose — normalising
	 * it would hide the misspelling that is usually the whole point of reading
	 * it — which means this screen is the place it gets rendered, and every
	 * single value goes through esc_html(). There is no "this one is fine"
	 * exception; a customer question is hostile input until it is escaped.
	 * ------------------------------------------------------------------ */

	/**
	 * The unmatched-questions log, newest first.
	 *
	 * Read through the lookup engine's own accessor when it has one, and only
	 * falling back to reading the option directly when it does not. The engine
	 * is owned by another agent and is being written in parallel with this
	 * screen; going through its accessor means the storage format stays its
	 * business, and the fallback means this screen still works if the accessor
	 * is named something else or lands later. Whichever path produced the rows,
	 * they are normalised into one shape here so the renderer has no branches.
	 *
	 * @return array<int,array{question:string,time:int,lang:string}>
	 */
	private static function unmatched_questions(): array {
		$candidates = array( 'get_unmatched_questions', 'get_unmatched', 'get_unmatched_log', 'unmatched_questions' );

		/**
		 * Filter the static accessor names tried on DB_Chat_Lookup_Engine.
		 *
		 * @param string[] $candidates Method names, highest priority first.
		 */
		$candidates = (array) apply_filters( 'db_chat_unmatched_accessors', $candidates );

		if ( class_exists( 'DB_Chat_Lookup_Engine' ) ) {
			foreach ( $candidates as $method ) {
				$callback = array( 'DB_Chat_Lookup_Engine', (string) $method );

				if ( ! is_callable( $callback ) ) {
					continue;
				}

				try {
					$rows = call_user_func( $callback );
				} catch ( Throwable $e ) {
					// A broken accessor must not take out the settings page.
					continue;
				}

				if ( is_array( $rows ) ) {
					return self::normalize_unmatched( $rows );
				}
			}
		}

		/**
		 * Filter the option the unmatched log falls back to.
		 *
		 * @param string $option Option name.
		 */
		$option = (string) apply_filters( 'db_chat_unmatched_option', self::UNMATCHED_OPTION );

		foreach ( array_unique( array( $option, 'db_chat_unmatched_questions' ) ) as $name ) {
			$rows = get_option( $name, array() );

			if ( is_array( $rows ) && ! empty( $rows ) ) {
				// Raw option storage IS append-ordered (oldest first), so it
				// needs reversing to become newest-first. The accessor path
				// above does not, because it documents newest-first already.
				return self::normalize_unmatched( array_reverse( $rows ) );
			}
		}

		return array();
	}

	/**
	 * Force whatever the engine stores into one predictable row shape.
	 *
	 * Deliberately permissive about input and strict about output. The stored
	 * shape is not this class's to define, so a plain string, a row keyed
	 * 'text' instead of 'question', and a MySQL datetime instead of a unix
	 * timestamp all have to land somewhere sensible. Anything with no question
	 * text in it is dropped rather than rendered as a blank row.
	 *
	 * @param array $rows Raw rows.
	 * @return array<int,array{question:string,time:int,lang:string}>
	 */
	private static function normalize_unmatched( array $rows ): array {
		$out = array();

		foreach ( $rows as $row ) {
			if ( is_scalar( $row ) ) {
				$row = array( 'question' => (string) $row );
			}

			if ( ! is_array( $row ) ) {
				continue;
			}

			$question = '';

			foreach ( array( 'question', 'text', 'message', 'query', 'q' ) as $key ) {
				if ( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ) {
					$question = (string) $row[ $key ];
					break;
				}
			}

			$question = trim( $question );

			if ( '' === $question ) {
				continue;
			}

			$time = 0;

			foreach ( array( 'time', 'timestamp', 'logged_at', 'date', 'when', 'created' ) as $key ) {
				if ( ! isset( $row[ $key ] ) ) {
					continue;
				}

				$time = self::unmatched_timestamp( $row[ $key ] );

				if ( $time > 0 ) {
					break;
				}
			}

			$lang = '';

			foreach ( array( 'lang', 'language', 'locale' ) as $key ) {
				if ( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ) {
					$lang = strtolower( trim( (string) $row[ $key ] ) );
					break;
				}
			}

			$out[] = array(
				'question' => $question,
				'time'     => $time,
				'lang'     => $lang,
			);
		}

		/*
		 * Sort newest first, and ONLY sort — do not reverse here.
		 *
		 * This method used to reverse first, on the assumption that its input
		 * was append-ordered. That is true of the raw option but NOT of
		 * DB_Chat_Lookup_Engine::get_unmatched_questions(), which already
		 * returns newest first. The bug that caused was invisible in normal
		 * data: with distinct timestamps the sort simply undid the reverse, so
		 * everything looked right. When two questions landed in the SAME
		 * SECOND the stable sort faithfully preserved the reversed order and
		 * those rows came out backwards — at the top of the list, which is the
		 * part the owner reads as "most recent". Callers whose input really is
		 * append-ordered now reverse it themselves before calling in.
		 *
		 * usort is stable as of PHP 8.0, so ties keep the caller's order rather
		 * than shuffling.
		 */
		usort(
			$out,
			static function ( array $a, array $b ): int {
				return $b['time'] <=> $a['time'];
			}
		);

		return $out;
	}

	/**
	 * Coerce a stored time value to a unix timestamp.
	 *
	 * @param mixed $value Stored value.
	 * @return int 0 when unusable.
	 */
	private static function unmatched_timestamp( $value ): int {
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return 0;
		}

		// A MySQL datetime is stored in site-local time, not UTC.
		$parsed = strtotime( trim( $value ) . ' ' . wp_timezone_string() );

		if ( false === $parsed ) {
			$parsed = strtotime( trim( $value ) );
		}

		return false === $parsed ? 0 : (int) $parsed;
	}

	/**
	 * The unmatched-questions panel.
	 *
	 * A section on this page rather than a menu entry of its own: it is read
	 * while tuning the bot, next to the settings being tuned, and the store
	 * does not need another top-level menu.
	 */
	private static function render_unmatched_section(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a redirect result; the action itself was nonce-checked.
		if ( isset( $_GET['db_chat_cleared'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Unmatched-questions log cleared.', 'durianbikers-chatbot' )
			);
		}

		$rows  = self::unmatched_questions();
		$total = count( $rows );
		$shown = array_slice( $rows, 0, self::UNMATCHED_SHOWN );

		$clear_url = wp_nonce_url(
			add_query_arg( 'action', self::CLEAR_UNMATCHED_ACTION, admin_url( 'admin-post.php' ) ),
			self::CLEAR_UNMATCHED_ACTION
		);
		?>
		<hr>

		<h2><?php esc_html_e( 'Questions the bot could not answer', 'durianbikers-chatbot' ); ?></h2>

		<?php if ( '1' !== self::get( self::UNMATCHED_ENABLED_OPTION, '1' ) ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php esc_html_e( 'Recording is currently switched off, so this list is not being added to. Turn "Record unanswered questions" back on in the Chatbot section above to start collecting again.', 'durianbikers-chatbot' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<p class="description" style="max-width:46em;">
			<?php esc_html_e( 'Questions the free lookup mode could not understand, newest first. Treat this as a to-do list rather than a report: free lookup has no language understanding, it matches keywords, so most entries here are one keyword away from working.', 'durianbikers-chatbot' ); ?>
			<br><br>
			<?php esc_html_e( 'Only genuine misunderstandings are recorded. A question that was understood but found no matching product is NOT listed here — that is a gap in the catalogue rather than in the keywords, and the two need different fixes. Order questions are never recorded, and any message containing an email address or a long run of digits is skipped rather than stored.', 'durianbikers-chatbot' ); ?>
			<br><br>
			<?php
			printf(
				/* translators: %s: name of the WordPress filter, rendered as code. */
				esc_html__( 'To make one of these work, add the word the customer used to the %s filter and point it at the right topic. Nothing here is a bug report — a question in this list simply used vocabulary the catalogue and the keyword tables do not share yet.', 'durianbikers-chatbot' ),
				'<code>db_chat_lookup_keywords</code>'
			);
			?>
			<br><br>
			<strong><?php esc_html_e( 'These are raw customer-typed strings.', 'durianbikers-chatbot' ); ?></strong>
			<?php esc_html_e( 'They are shown exactly as they were typed, including any misspelling — that is usually the useful part. They are escaped on output and are not clickable.', 'durianbikers-chatbot' ); ?>
		</p>

		<?php if ( 0 === $total ) : ?>
			<p><em><?php esc_html_e( 'Nothing logged yet. Either the bot has answered everything it was asked, or free lookup mode has not been used since logging began.', 'durianbikers-chatbot' ); ?></em></p>
			<?php
			return;
		endif;
		?>

		<p>
			<?php
			if ( $total > self::UNMATCHED_SHOWN ) {
				printf(
					/* translators: 1: number shown, 2: total number logged. */
					esc_html__( 'Showing the newest %1$s of %2$s logged questions.', 'durianbikers-chatbot' ),
					esc_html( number_format_i18n( count( $shown ) ) ),
					esc_html( number_format_i18n( $total ) )
				);

				$cap = self::unmatched_cap();

				if ( $cap > 0 ) {
					echo ' ';
					printf(
						/* translators: %s: maximum number of questions kept. */
						esc_html__( 'The log keeps the newest %s and discards older ones automatically.', 'durianbikers-chatbot' ),
						esc_html( number_format_i18n( $cap ) )
					);
				}
			} else {
				printf(
					/* translators: %s: number of logged questions. */
					esc_html( _n( '%s logged question.', '%s logged questions.', $total, 'durianbikers-chatbot' ) ),
					esc_html( number_format_i18n( $total ) )
				);
			}
			?>
		</p>

		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col" style="width:14em;"><?php esc_html_e( 'When', 'durianbikers-chatbot' ); ?></th>
					<th scope="col" style="width:8em;"><?php esc_html_e( 'Language', 'durianbikers-chatbot' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Question', 'durianbikers-chatbot' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $shown as $row ) : ?>
					<tr>
						<td>
							<?php
							echo $row['time'] > 0
								? esc_html( wp_date( 'Y-m-d H:i', $row['time'] ) )
								: esc_html__( 'Unknown', 'durianbikers-chatbot' );
							?>
						</td>
						<td><?php echo esc_html( self::language_label( $row['lang'] ) ); ?></td>
						<td>
							<?php
							/*
							 * esc_html() on customer-typed text, always. The
							 * <code> wrapper is presentational only: it makes a
							 * stray character visible without making it live.
							 */
							$question = $row['question'];

							if ( mb_strlen( $question ) > 300 ) {
								$question = mb_substr( $question, 0, 300 ) . "\u{2026}";
							}

							echo '<code style="background:transparent;padding:0;">' . esc_html( $question ) . '</code>';
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p style="margin-top:1em;">
			<a href="<?php echo esc_url( $clear_url ); ?>"
				class="button"
				onclick="return confirm( <?php echo esc_js( wp_json_encode( __( 'Clear the whole unmatched-questions log? This cannot be undone.', 'durianbikers-chatbot' ) ) ); ?> );">
				<?php esc_html_e( 'Clear log', 'durianbikers-chatbot' ); ?>
			</a>
			<span class="description" style="margin-left:.5em;">
				<?php esc_html_e( 'Clears the list only. It does not change any setting and does not affect the chatbot.', 'durianbikers-chatbot' ); ?>
			</span>
		</p>
		<?php
	}

	/**
	 * The engine's own cap on stored questions, when it publishes one.
	 *
	 * Read from the engine rather than restated, so the number on screen cannot
	 * drift from the number actually enforced.
	 */
	private static function unmatched_cap(): int {
		if ( class_exists( 'DB_Chat_Lookup_Engine' ) && defined( 'DB_Chat_Lookup_Engine::MAX_UNMATCHED' ) ) {
			return (int) constant( 'DB_Chat_Lookup_Engine::MAX_UNMATCHED' );
		}

		return 0;
	}

	/**
	 * Human label for a detected language code.
	 *
	 * @param string $lang Stored code.
	 */
	private static function language_label( string $lang ): string {
		switch ( $lang ) {
			case 'ms':
			case 'ms_my':
			case 'my':
				return __( 'Malay', 'durianbikers-chatbot' );

			case 'en':
			case 'en_us':
			case 'en_gb':
				return __( 'English', 'durianbikers-chatbot' );

			case '':
				return __( 'Unknown', 'durianbikers-chatbot' );
		}

		// An unrecognised code is still customer-adjacent data. Escaped by the
		// caller, but bounded here so a long string cannot wreck the column.
		return mb_substr( $lang, 0, 12 );
	}

	/**
	 * admin_post handler for "Clear log".
	 *
	 * A GET-with-nonce link rather than AJAX, matching the test-notification
	 * button above: it is a rare, deliberate, admin-only action, and keeping it
	 * out of JavaScript means it works on a page whose scripts failed to load.
	 * Capability is checked here and not merely on the screen that drew the
	 * link — the link is a URL and anyone can construct it.
	 */
	public static function handle_clear_unmatched(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'durianbikers-chatbot' ), 403 );
		}

		check_admin_referer( self::CLEAR_UNMATCHED_ACTION );

		$cleared    = false;
		$candidates = array( 'clear_unmatched_questions', 'clear_unmatched', 'clear_unmatched_log', 'flush_unmatched' );

		/**
		 * Filter the static clearer names tried on DB_Chat_Lookup_Engine.
		 *
		 * @param string[] $candidates Method names, highest priority first.
		 */
		$candidates = (array) apply_filters( 'db_chat_unmatched_clearers', $candidates );

		if ( class_exists( 'DB_Chat_Lookup_Engine' ) ) {
			foreach ( $candidates as $method ) {
				$callback = array( 'DB_Chat_Lookup_Engine', (string) $method );

				if ( ! is_callable( $callback ) ) {
					continue;
				}

				try {
					call_user_func( $callback );
					$cleared = true;
					break;
				} catch ( Throwable $e ) {
					if ( class_exists( 'DB_Chat_Plugin' ) && is_callable( array( 'DB_Chat_Plugin', 'log_error' ) ) ) {
						DB_Chat_Plugin::log_error( 'Clearing the unmatched log failed: ' . $e->getMessage() );
					}
				}
			}
		}

		if ( ! $cleared ) {
			$option = (string) apply_filters( 'db_chat_unmatched_option', self::UNMATCHED_OPTION );

			// delete_option(), not update_option( array() ): if the engine
			// stores under a different name this deletes nothing, which is the
			// correct outcome for a name we were never told.
			foreach ( array_unique( array( $option, 'db_chat_unmatched_questions' ) ) as $name ) {
				delete_option( $name );
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => self::PAGE_SLUG,
					'db_chat_cleared' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
