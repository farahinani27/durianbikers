<?php
/**
 * Plugin Name:       Durian Bikers Chatbot
 * Plugin URI:        https://durianbikers.com
 * Description:       Storefront chat assistant powered by the Anthropic Messages API, plus owner order notifications. The chat path can reach published products, an allowlist of published help pages, and — for a signed-in customer only — that customer's own orders. Never another person's data.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Durian Bikers
 * Text Domain:       durianbikers-chatbot
 * Domain Path:       /languages
 *
 * @package DurianBikers_Chatbot
 */

defined( 'ABSPATH' ) || exit;

/*
 * ---------------------------------------------------------------------------
 * Constants
 * ---------------------------------------------------------------------------
 */

define( 'DB_CHAT_VERSION', '1.0.0' );
define( 'DB_CHAT_FILE', __FILE__ );
define( 'DB_CHAT_PATH', plugin_dir_path( __FILE__ ) );
define( 'DB_CHAT_URL', plugin_dir_url( __FILE__ ) );
define( 'DB_CHAT_TEXTDOMAIN', 'durianbikers-chatbot' );

/** Default (base) model. Overridable from the settings screen. */
define( 'DB_CHAT_DEFAULT_MODEL', 'claude-haiku-4-5-20251001' );

/**
 * Escalation model for the tiered brain: when the base model's answer fails
 * the grounding check (usually because it answered from memory instead of
 * searching), that one turn is retried on this more capable model. Set the
 * db_chat_model_escalation option to '' to disable tiering (base model only).
 */
define( 'DB_CHAT_DEFAULT_ESCALATION_MODEL', 'claude-sonnet-5' );

/** REST route pieces, kept as constants so every class agrees on one string. */
define( 'DB_CHAT_REST_NAMESPACE', 'durianbikers/v1' );
define( 'DB_CHAT_REST_ROUTE', '/chat' );

/**
 * Session route. Hands out a fresh wp_rest nonce at runtime.
 *
 * The site runs LiteSpeed full-page caching with a 7 day TTL while nonces live
 * ~12-24h, so a nonce printed into the page HTML is dead for the overwhelming
 * majority of pageviews — and re-loading serves the same cached HTML, so it
 * never recovers. The nonce is therefore fetched, never baked in.
 */
define( 'DB_CHAT_REST_ROUTE_SESSION', '/session' );

/*
 * Option names. SPEC.md spells `db_chat_logging` verbatim; the rest follow the
 * same `db_chat_` prefix and are agreed with the settings/notifier agent.
 * Nothing outside this file should hardcode these strings.
 */
define( 'DB_CHAT_OPT_API_KEY', 'db_chat_api_key' );
define( 'DB_CHAT_OPT_MODEL', 'db_chat_model' );
define( 'DB_CHAT_OPT_ESCALATION_MODEL', 'db_chat_model_escalation' );
define( 'DB_CHAT_OPT_ENABLED', 'db_chat_enabled' );
define( 'DB_CHAT_OPT_GREETING', 'db_chat_greeting' );
define( 'DB_CHAT_OPT_SUGGESTIONS', 'db_chat_suggestions' );
define( 'DB_CHAT_OPT_LOGGING', 'db_chat_logging' );
// Lets customers attach a photo the assistant can read (routes that turn to
// the escalation/vision model). Default on; flip to '0' to hide the attach
// button and ignore any image sent.
define( 'DB_CHAT_OPT_VISION', 'db_chat_vision' );
// Requires a name + email before a guest can chat (logged-in customers skip it
// and their account details are used). Default on; flip to '0' to let anyone
// chat without leaving details. Only meaningful when the handoff/inbox is on,
// since that is what stores the conversation the name is attached to.
define( 'DB_CHAT_OPT_CONTACT_GATE', 'db_chat_contact_gate' );

/**
 * Which brain answers: 'auto', 'lookup' or 'ai'.
 *
 * Added Aug 2026 with the free lookup mode. Default 'auto' so the plugin works
 * out of the box with no API key: Claude when a key is present, deterministic
 * keyword lookup when it is not.
 */
define( 'DB_CHAT_OPT_MODE', 'db_chat_mode' );

/*
 * ---------------------------------------------------------------------------
 * Requires
 * ---------------------------------------------------------------------------
 *
 * Core files (owned by this agent) are required unconditionally. The settings
 * and notifier files are owned by another agent and may not be on disk yet, so
 * each is guarded. A missing or broken notifier must never fatal the
 * storefront, and above all must never fatal checkout.
 */

require_once DB_CHAT_PATH . 'includes/class-product-index.php';
require_once DB_CHAT_PATH . 'includes/class-content-index.php';
require_once DB_CHAT_PATH . 'includes/class-order-lookup.php';
require_once DB_CHAT_PATH . 'includes/class-pending-order.php';
require_once DB_CHAT_PATH . 'includes/class-ai-budget.php';
require_once DB_CHAT_PATH . 'includes/class-cart-writer.php';
require_once DB_CHAT_PATH . 'includes/class-cart-proposal.php';
require_once DB_CHAT_PATH . 'includes/class-confirm.php';
require_once DB_CHAT_PATH . 'includes/class-checkout-writer.php';
require_once DB_CHAT_PATH . 'includes/class-claude-client.php';
require_once DB_CHAT_PATH . 'includes/class-pii.php';
require_once DB_CHAT_PATH . 'includes/class-messages.php';
require_once DB_CHAT_PATH . 'includes/class-grounding.php';
require_once DB_CHAT_PATH . 'includes/class-orchestrator.php';
require_once DB_CHAT_PATH . 'includes/class-chat-endpoint.php';
require_once DB_CHAT_PATH . 'includes/class-conversation-store.php';

/**
 * Optional modules, in dependency order agreed with the notifier agent.
 * (class-order-notifier.php also lazily requires its own notifiers, so this
 * order is tidiness rather than a hard requirement.)
 *
 * class-lookup-engine.php and class-handoff.php are in this list rather than
 * the core requires above on purpose. Deployment is a manual file upload, so a partial upload is a real
 * possibility; a guarded require means a missing handoff file hides the
 * WhatsApp button, while an unguarded one would fatal the whole storefront.
 * Every caller checks class_exists() for the same reason. A missing lookup
 * engine is handled the same way: get_engine() reports 'none', which switches
 * the chatbot off rather than silently falling back to the paid API the owner
 * may have deliberately chosen not to use.
 *
 * @var string[] $db_chat_optional_files
 */
$db_chat_optional_files = array(
	'includes/class-lookup-engine.php',
	'includes/class-product-finder.php',
	'includes/class-handoff.php',
	'includes/notifiers/interface-notifier.php',
	'includes/notifiers/class-telegram-notifier.php',
	'includes/notifiers/class-slack-notifier.php',
	'includes/notifiers/class-email-notifier.php',
	'includes/notifiers/class-whatsapp-notifier.php',
	'includes/class-order-notifier.php',
	'includes/class-settings.php',
	'includes/class-handoff-alert.php',
	'includes/class-handoff-endpoint.php',
	'includes/class-inbox-admin.php',
);

foreach ( $db_chat_optional_files as $db_chat_optional_file ) {
	if ( file_exists( DB_CHAT_PATH . $db_chat_optional_file ) ) {
		require_once DB_CHAT_PATH . $db_chat_optional_file;
	}
}

unset( $db_chat_optional_files, $db_chat_optional_file );

/**
 * Plugin bootstrap.
 *
 * Owns: settings access, object wiring, frontend asset enqueue.
 *
 * It reads no order and no user itself. The one path to personal data is
 * DB_Chat_Order_Lookup, which resolves the customer from the session at call
 * time — nothing here holds, passes or caches an identity.
 */
final class DB_Chat_Plugin {

	/** Mode: Claude when a key exists, free lookup when it does not. */
	const MODE_AUTO = 'auto';

	/** Mode: always the free lookup engine, key or no key. */
	const MODE_LOOKUP = 'lookup';

	/** Mode: always Claude; offline (503) when no key is configured. */
	const MODE_AI = 'ai';

	/**
	 * Mode: the free lookup engine answers first; Claude answers only what it
	 * genuinely cannot understand, within the monthly AI budget.
	 */
	const MODE_HYBRID = 'hybrid';

	/** Every accepted mode value. */
	const MODES = array( self::MODE_AUTO, self::MODE_LOOKUP, self::MODE_AI, self::MODE_HYBRID );

	/** Resolved brain: the Anthropic API. */
	const ENGINE_AI = 'ai';

	/** Resolved brain: the free, offline keyword lookup. */
	const ENGINE_LOOKUP = 'lookup';

	/** Resolved brain: none usable, chatbot is off. */
	const ENGINE_NONE = 'none';

	/**
	 * Singleton instance.
	 *
	 * @var DB_Chat_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Product catalogue retrieval service.
	 *
	 * @var DB_Chat_Product_Index
	 */
	private $product_index;

	/**
	 * Allowlisted help page retrieval service.
	 *
	 * @var DB_Chat_Content_Index
	 */
	private $content_index;

	/**
	 * Own-order retrieval service for signed-in customers.
	 *
	 * @var DB_Chat_Order_Lookup
	 */
	private $order_lookup;

	/**
	 * Anthropic client.
	 *
	 * @var DB_Chat_Claude_Client
	 */
	private $client;

	/**
	 * FREE keyword lookup engine, or null when its file is not on disk.
	 *
	 * The alternative brain: no API key, no network calls, deterministic
	 * keyword matching over the same product and page retrieval the Claude
	 * path uses. See includes/class-lookup-engine.php.
	 *
	 * @var DB_Chat_Lookup_Engine|null
	 */
	private $lookup_engine = null;

	/**
	 * REST endpoint.
	 *
	 * @var DB_Chat_Chat_Endpoint
	 */
	private $endpoint;

	/**
	 * Boot the plugin once.
	 *
	 * @return DB_Chat_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire everything up.
	 */
	private function __construct() {
		$this->product_index = new DB_Chat_Product_Index();
		$this->content_index = new DB_Chat_Content_Index();
		$this->order_lookup  = new DB_Chat_Order_Lookup();
		$this->client        = new DB_Chat_Claude_Client();

		if ( class_exists( 'DB_Chat_Lookup_Engine' ) ) {
			$this->lookup_engine = new DB_Chat_Lookup_Engine( $this->product_index, $this->content_index );
		}

		$this->endpoint = new DB_Chat_Chat_Endpoint( $this->product_index, $this->client, $this->content_index, $this->order_lookup, $this->lookup_engine );

		/*
		 * Human handoff storage + services. Guarded the same way as the
		 * lookup engine above: a manual file upload can be partial, and a
		 * missing optional file must degrade the feature, never fatal the
		 * storefront. class-conversation-store.php itself is a CORE
		 * (unconditional) require because class-chat-endpoint.php already
		 * calls DB_Chat_Conversation_Store:: unguarded above whenever the
		 * handoff toggle is on.
		 */
		if ( class_exists( 'DB_Chat_Conversation_Store' ) ) {
			DB_Chat_Conversation_Store::maybe_upgrade();
		}

		if ( class_exists( 'DB_Chat_Handoff_Endpoint' ) ) {
			new DB_Chat_Handoff_Endpoint();
		}

		if ( class_exists( 'DB_Chat_Inbox_Admin' ) ) {
			new DB_Chat_Inbox_Admin();
		}

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DB_CHAT_FILE ), array( $this, 'add_settings_action_link' ) );

		$this->register_cache_purge_hooks();

		$this->register_handoff_retention_cron();

		$this->boot_optional_modules();

		/**
		 * Fires once the chatbot core is loaded and its objects exist.
		 *
		 * @param DB_Chat_Plugin $plugin Plugin instance.
		 */
		do_action( 'db_chat_loaded', $this );
	}

	/**
	 * Register the daily conversation-retention purge.
	 *
	 * The purge handler itself is always registered (harmless no-op if the
	 * store class is missing), but the recurring event is only scheduled
	 * when the handoff feature is actually on — no point cluttering wp-cron
	 * with a job for a feature nobody enabled. Scheduling happens on
	 * 'init' (not here in the constructor) because wp_next_scheduled() /
	 * wp_schedule_event() need the cron system available.
	 *
	 * @return void
	 */
	private function register_handoff_retention_cron() {
		add_action(
			'db_chat_handoff_purge',
			function () {
				if ( class_exists( 'DB_Chat_Conversation_Store' ) ) {
					DB_Chat_Conversation_Store::purge( (int) get_option( 'db_chat_handoff_retention_days', 90 ) );
				}
			}
		);

		add_action(
			'init',
			function () {
				if ( '1' === get_option( 'db_chat_handoff_enabled', '0' ) && ! wp_next_scheduled( 'db_chat_handoff_purge' ) ) {
					wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'db_chat_handoff_purge' );
				}
			}
		);
	}

	/**
	 * Boot the settings and order-notifier modules owned by the other agent.
	 *
	 * Both expose a static, idempotent init(). Each call is wrapped so a fatal
	 * inside an optional module cannot take down the storefront or checkout.
	 *
	 * @return void
	 */
	private function boot_optional_modules() {
		foreach ( array( 'DB_Chat_Settings', 'DB_Chat_Order_Notifier' ) as $class_name ) {
			if ( ! class_exists( $class_name ) ) {
				continue;
			}

			try {
				if ( is_callable( array( $class_name, 'init' ) ) ) {
					call_user_func( array( $class_name, 'init' ) );
				}
			} catch ( Throwable $e ) {
				self::log_error( sprintf( '%s failed to initialise: %s', $class_name, $e->getMessage() ) );
			}
		}
	}

	/*
	 * -----------------------------------------------------------------------
	 * Page cache invalidation
	 * -----------------------------------------------------------------------
	 *
	 * The site runs LiteSpeed full-page caching with a 7 day TTL. Every option
	 * below is localized into the page HTML, so editing one changes nothing for
	 * real visitors until the cache turns over — for up to a week.
	 *
	 * The failure mode is silent and misleading: logged-in admins BYPASS the
	 * page cache, so the owner saves a new greeting, reloads, sees it, and
	 * reasonably concludes it shipped. Meanwhile every customer keeps seeing
	 * the old wording, and it looks like the plugin is broken rather than the
	 * cache being stale.
	 */

	/**
	 * Options whose values end up inside cached page HTML.
	 *
	 * Server-side-only settings (model, logging, channel credentials) are
	 * deliberately absent — changing those must not purge the cache.
	 *
	 * @return string[]
	 */
	private function get_cached_copy_options() {
		return array(
			// Control whether the widget renders at all.
			DB_CHAT_OPT_ENABLED,
			DB_CHAT_OPT_API_KEY,

			/*
			 * The mode decides whether the widget renders when there is no API
			 * key at all (lookup mode needs none), so switching it has to reach
			 * cached pages or the storefront keeps the old answer for a week.
			 */
			DB_CHAT_OPT_MODE,

			// Localized copy.
			DB_CHAT_OPT_GREETING,
			DB_CHAT_OPT_SUGGESTIONS,
			'db_chat_title',
			'db_chat_subtitle',
			'db_chat_placeholder',
			'db_chat_launcher_label',
			'db_chat_offline_text',
			'db_chat_error_text',
			'db_chat_rate_limit_text',
			'db_chat_footnote',
			'db_chat_avatar',

			/*
			 * Human handoff. The number, the label and both hours settings all
			 * reach the cached HTML (the hours setting via the generated
			 * summary line). The computed isOpen flag does NOT — it is served
			 * from the uncached /session route, which is exactly why editing
			 * hours here needs a purge and the open/closed state does not.
			 */
			'db_chat_handoff_number',
			'db_chat_handoff_label',
			'db_chat_handoff_hours',
			'db_chat_handoff_hours_text',
		);
	}

	/**
	 * Purge the page cache when localized copy changes.
	 *
	 * @return void
	 */
	private function register_cache_purge_hooks() {
		foreach ( $this->get_cached_copy_options() as $option ) {
			// update_option_{$option} only fires when the value actually
			// changed, so a no-op save never triggers a purge.
			add_action( "update_option_{$option}", array( $this, 'purge_page_cache' ) );
			add_action( "add_option_{$option}", array( $this, 'purge_page_cache' ) );
		}
	}

	/**
	 * Ask the page cache to drop its stored HTML.
	 *
	 * Fires at most once per request even when several options are saved
	 * together, so a single settings save is one purge, not thirteen.
	 *
	 * Only the page cache is touched. The object cache is deliberately left
	 * alone — flushing it here would cold-start unrelated queries across the
	 * whole store for no benefit.
	 *
	 * @return void
	 */
	public function purge_page_cache() {
		static $purged = false;

		if ( $purged ) {
			return;
		}

		/**
		 * Filter whether saving chatbot copy purges the page cache.
		 *
		 * Set false on a site where a full purge at an awkward moment is
		 * costlier than a stale greeting.
		 *
		 * @param bool $purge Whether to purge.
		 */
		if ( ! apply_filters( 'db_chat_purge_cache_on_save', true ) ) {
			return;
		}

		$purged = true;

		/*
		 * LiteSpeed's documented purge hook. Harmless no-op when LiteSpeed is
		 * inactive, since nothing is listening.
		 */
		do_action( 'litespeed_purge_all' );

		/**
		 * Fires when the chatbot wants the page cache dropped.
		 *
		 * Hook this to purge a different caching layer.
		 */
		do_action( 'db_chat_purge_page_cache' );
	}

	/**
	 * Accessor for the product retrieval service.
	 *
	 * @return DB_Chat_Product_Index
	 */
	public function product_index() {
		return $this->product_index;
	}

	/**
	 * Accessor for the help page retrieval service.
	 *
	 * @return DB_Chat_Content_Index
	 */
	public function content_index() {
		return $this->content_index;
	}

	/**
	 * Accessor for the own-order retrieval service.
	 *
	 * @return DB_Chat_Order_Lookup
	 */
	public function order_lookup() {
		return $this->order_lookup;
	}

	/**
	 * Accessor for the Anthropic client.
	 *
	 * @return DB_Chat_Claude_Client
	 */
	public function client() {
		return $this->client;
	}

	/**
	 * Accessor for the free lookup engine.
	 *
	 * @return DB_Chat_Lookup_Engine|null Null when the file is not deployed.
	 */
	public function lookup_engine() {
		return $this->lookup_engine;
	}

	/**
	 * Accessor for the REST endpoint.
	 *
	 * Minimal and non-sensitive: the endpoint object holds no credentials (the
	 * API key lives only inside DB_Chat_Claude_Client, which never exposes
	 * it), and every method on it that touches personal data resolves
	 * identity from the request/session itself, never from a caller-supplied
	 * value. Exists so server smoke tests (tests/server/) can exercise public
	 * endpoint methods such as raise_human_handoff() against the real, fully
	 * wired instance instead of constructing a second one by hand.
	 *
	 * @return DB_Chat_Chat_Endpoint
	 */
	public function endpoint() {
		return $this->endpoint;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Settings access
	 * -----------------------------------------------------------------------
	 *
	 * Everything funnels through here. When DB_Chat_Settings is present we use
	 * its helpers; otherwise we fall back to plain get_option() so the chat
	 * core still works standalone.
	 */

	/**
	 * Read a single scalar setting.
	 *
	 * @param string $option  Option name.
	 * @param string $default Default value.
	 * @return string
	 */
	public static function setting( $option, $default = '' ) {
		if ( is_callable( array( 'DB_Chat_Settings', 'get' ) ) ) {
			return (string) DB_Chat_Settings::get( $option, $default );
		}

		$value = get_option( $option, $default );

		return is_scalar( $value ) ? (string) $value : $default;
	}

	/**
	 * Which brain the owner asked for: 'auto', 'lookup' or 'ai'.
	 *
	 *  - auto   Claude when an API key is present, otherwise the free lookup
	 *           engine. The default, so the plugin works with no key.
	 *  - lookup Always the free lookup engine, even when a key exists. Lets the
	 *           owner test it, or run at zero cost on purpose.
	 *  - ai     Always Claude. With no key this is the original offline
	 *           behaviour, unchanged: no widget, 503 on the route.
	 *
	 * Anything unrecognised falls back to 'auto'.
	 *
	 * @return string
	 */
	public function get_mode() {
		$mode = strtolower( trim( self::setting( DB_CHAT_OPT_MODE, self::MODE_AUTO ) ) );

		if ( ! in_array( $mode, self::MODES, true ) ) {
			$mode = self::MODE_AUTO;
		}

		/**
		 * Filter the chatbot mode.
		 *
		 * @param string $mode One of auto, lookup, ai, hybrid.
		 */
		$mode = (string) apply_filters( 'db_chat_mode', $mode );

		return in_array( $mode, self::MODES, true ) ? $mode : self::MODE_AUTO;
	}

	/**
	 * Which brain will actually answer: 'ai', 'lookup' or 'none'.
	 *
	 * This is the single source of truth for the branch in the REST handler AND
	 * for whether the widget is enqueued at all — they must never disagree, or
	 * customers get a chat box that answers 503.
	 *
	 * 'none' means the chosen brain is not usable (mode ai with no key, or mode
	 * lookup with the engine file missing) and the chatbot is off. Note what it
	 * deliberately does NOT do: an explicit 'lookup' choice never silently
	 * falls back to the paid API, because the owner chose lookup precisely to
	 * avoid paying for one.
	 *
	 * @return string
	 */
	public function get_engine() {
		$mode = $this->get_mode();

		if ( self::MODE_AI === $mode ) {
			return $this->client->has_api_key() ? self::ENGINE_AI : self::ENGINE_NONE;
		}

		if ( self::MODE_LOOKUP === $mode ) {
			return null !== $this->lookup_engine ? self::ENGINE_LOOKUP : self::ENGINE_NONE;
		}

		/*
		 * Hybrid runs ON the lookup engine; the AI step lives inside the lookup
		 * branch of the endpoint. With no lookup engine there is nothing to fall
		 * back from, so hybrid switches off rather than silently becoming
		 * Claude-only and spending money the owner did not choose to spend.
		 */
		if ( self::MODE_HYBRID === $mode ) {
			return null !== $this->lookup_engine ? self::ENGINE_LOOKUP : self::ENGINE_NONE;
		}

		// auto.
		if ( $this->client->has_api_key() ) {
			return self::ENGINE_AI;
		}

		return null !== $this->lookup_engine ? self::ENGINE_LOOKUP : self::ENGINE_NONE;
	}

	/**
	 * Is the chatbot switched on AND usable?
	 *
	 * Deliberately not delegated to DB_Chat_Settings::is_enabled(): that helper
	 * reads the api key OPTION, whereas has_api_key() also honours the
	 * DB_CHAT_ANTHROPIC_API_KEY wp-config constant. Same intent, wider net.
	 *
	 * CHANGED Aug 2026 with lookup mode. "Usable" no longer means "has an API
	 * key" — it means get_engine() found a brain that can answer. In lookup
	 * mode there is no key to have, and gating on one here would have hidden
	 * the widget and returned 503 for the entire free mode. Every other 503 and
	 * offline path is unchanged.
	 *
	 * Defaults to OFF. This runs on a live store; the widget only appears once
	 * someone deliberately turns it on.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		$enabled = '1' === self::setting( DB_CHAT_OPT_ENABLED, '' ) && self::ENGINE_NONE !== $this->get_engine();

		/**
		 * Filter whether the chatbot is active.
		 *
		 * @param bool $enabled Whether the chatbot is on.
		 */
		return (bool) apply_filters( 'db_chat_is_enabled', $enabled );
	}

	/**
	 * Is conversation logging explicitly switched on?
	 *
	 * @return bool
	 */
	public static function is_logging_enabled() {
		if ( is_callable( array( 'DB_Chat_Settings', 'is_logging_enabled' ) ) ) {
			return (bool) DB_Chat_Settings::is_logging_enabled();
		}

		return '1' === self::setting( DB_CHAT_OPT_LOGGING, '' );
	}

	/**
	 * Whether customers may attach a photo for the assistant to read. Default
	 * ON — the toggle exists so cost can be killed instantly.
	 *
	 * @return bool
	 */
	public static function vision_enabled() {
		return '1' === self::setting( DB_CHAT_OPT_VISION, '1' );
	}

	/**
	 * Whether a name + email is required before a guest can chat. Default ON.
	 * Only takes effect when the handoff/inbox is enabled, since that is what
	 * persists the conversation the details attach to. The toggle exists so it
	 * can be switched off instantly if the gate ever costs engagement.
	 *
	 * @return bool
	 */
	public static function contact_gate_enabled() {
		return '1' === self::setting( DB_CHAT_OPT_CONTACT_GATE, '1' )
			&& '1' === get_option( 'db_chat_handoff_enabled', '0' );
	}

	/**
	 * Greeting shown when the panel opens.
	 *
	 * @return string Plain text.
	 */
	public static function get_greeting() {
		$greeting = self::setting( DB_CHAT_OPT_GREETING, '' );

		if ( '' === trim( $greeting ) ) {
			$greeting = __( 'Hi! Ask me anything about our parts, tyres and gear.', 'durianbikers-chatbot' );
		}

		/**
		 * Filter the greeting message.
		 *
		 * @param string $greeting Greeting text.
		 */
		return wp_strip_all_tags( (string) apply_filters( 'db_chat_greeting', $greeting ) );
	}

	/**
	 * Suggested prompt chips.
	 *
	 * Always a flat, reindexed array of plain strings, capped at 4.
	 *
	 * @return string[]
	 */
	public static function get_suggestions() {
		if ( is_callable( array( 'DB_Chat_Settings', 'get_suggestions' ) ) ) {
			$suggestions = (array) DB_Chat_Settings::get_suggestions();
		} else {
			$suggestions = get_option( DB_CHAT_OPT_SUGGESTIONS, array() );
			$suggestions = is_array( $suggestions ) ? $suggestions : array();

			/** This filter is documented in class-settings.php. */
			$suggestions = (array) apply_filters( 'db_chat_suggestions', $suggestions );
		}

		if ( empty( $suggestions ) ) {
			$suggestions = array(
				__( 'What tyres do you have?', 'durianbikers-chatbot' ),
				__( 'Show me helmets', 'durianbikers-chatbot' ),
				__( 'Do you have crash protection?', 'durianbikers-chatbot' ),
			);
		}

		$suggestions = array_map( 'wp_strip_all_tags', array_map( 'strval', $suggestions ) );
		$suggestions = array_values( array_filter( array_map( 'trim', $suggestions ), 'strlen' ) );

		return array_slice( $suggestions, 0, 4 );
	}

	/**
	 * Privacy policy URL for the AI disclaimer.
	 *
	 * get_privacy_policy_url() returns an empty string when no privacy page is
	 * set or the page is not published, so this can legitimately be ''. The
	 * function_exists() guard keeps this safe on very old cores.
	 *
	 * @return string URL or empty string. Never null.
	 */
	public static function get_privacy_policy_url() {
		$url = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';

		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		return esc_url_raw( $url );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Frontend assets
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Enqueue the widget CSS/JS on the frontend only.
	 *
	 * The asset files are owned by the frontend agent; if they are not on disk
	 * we simply do not enqueue rather than emitting 404s. The plugin prints no
	 * markup at all — chatbot.js builds its own DOM.
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets() {
		if ( is_admin() || ! $this->is_enabled() ) {
			return;
		}

		$css_rel = 'assets/chatbot.css';
		$js_rel  = 'assets/chatbot.js';
		$css_abs = DB_CHAT_PATH . $css_rel;
		$js_abs  = DB_CHAT_PATH . $js_rel;

		if ( ! file_exists( $js_abs ) ) {
			return;
		}

		if ( file_exists( $css_abs ) ) {
			wp_enqueue_style( 'db-chat', DB_CHAT_URL . $css_rel, array(), (string) filemtime( $css_abs ) );
		}

		wp_enqueue_script( 'db-chat', DB_CHAT_URL . $js_rel, array(), (string) filemtime( $js_abs ), true );

		wp_localize_script( 'db-chat', 'dbChat', $this->get_script_data() );
	}

	/**
	 * Data handed to the browser.
	 *
	 * Contains no secrets. The API key never leaves the server.
	 *
	 * @return array<string,mixed>
	 */
	private function get_script_data() {
		/*
		 * NOTE: there is deliberately no `nonce` key here. This array is
		 * printed into the page HTML, which LiteSpeed caches for up to 7 days;
		 * a baked-in nonce would be expired for most visitors and refreshing
		 * would re-serve the same dead one. The widget fetches a live nonce
		 * from sessionUrl on first open instead. Do not add it back.
		 */
		$data = array(
			'ajaxUrl'       => esc_url_raw( rest_url( DB_CHAT_REST_NAMESPACE . DB_CHAT_REST_ROUTE ) ),
			'sessionUrl'    => esc_url_raw( rest_url( DB_CHAT_REST_NAMESPACE . DB_CHAT_REST_ROUTE_SESSION ) ),
			'tokenHeader'   => DB_Chat_Chat_Endpoint::CLIENT_TOKEN_HEADER,
			'greeting'      => self::get_greeting(),
			'suggestions'   => self::get_suggestions(),
			'maxLength'     => DB_Chat_Chat_Endpoint::MAX_MESSAGE_LENGTH,
			'maxHistory'    => DB_Chat_Chat_Endpoint::MAX_HISTORY,
			// Photo attach: the widget shows the attach button only when this is
			// '1'. The endpoint independently ignores images when it is off.
			'visionEnabled' => self::vision_enabled() ? '1' : '0',
			// Pre-chat name+email gate. Only the static policy flag is baked in
			// here (safe to cache — no PII). Whether a given visitor has already
			// satisfied it (logged-in account, or details given this session) is
			// decided per-request from the no-store /session route and the
			// widget's own saved state, never from this cached HTML.
			'contactGate'   => self::contact_gate_enabled() ? '1' : '0',
			// Which storefront this page belongs to ('my', 'gb', or '' on the
			// splash). Keys the saved conversation, so MYR and USD chats never
			// mix. From the live theme's store routing when present.
			'store'         => function_exists( 'db_effective_store_prefix' ) ? (string) db_effective_store_prefix() : '',

			// Copy overrides. Empty string means "use the JS default".
			'title'         => '',
			'subtitle'      => '',
			'placeholder'   => '',
			'launcherLabel' => '',
			'offlineText'   => '',
			'errorText'     => '',
			'rateLimitText' => '',
			'footnote'      => '',
			'avatar'        => '',
		);

		/*
		 * Privacy link for the persistent AI disclaimer. The key is omitted
		 * entirely when no privacy policy page is published — the widget hides
		 * the link when the key is absent.
		 */
		$privacy_url = self::get_privacy_policy_url();

		if ( '' !== $privacy_url ) {
			$data['privacyUrl'] = $privacy_url;
		}

		/*
		 * Human handoff (the WhatsApp button).
		 *
		 * Two rules here, both load-bearing:
		 *
		 * 1. The key is omitted ENTIRELY when no number is configured. The
		 *    widget hides the whole feature on absence, so a half-built object
		 *    would render a button that goes nowhere. get_script_data() on the
		 *    handoff class returns an empty array rather than partial data.
		 * 2. There is no `isOpen` in here, and there must never be. This array
		 *    is printed into page HTML that LiteSpeed caches for up to 7 days,
		 *    so a baked-in open/closed flag would be wrong for most visitors
		 *    within hours — the same trap that nearly cost us the nonce. The
		 *    widget reads isOpen from the /session route, which is no-store.
		 */
		if ( class_exists( 'DB_Chat_Handoff' ) ) {
			$handoff = DB_Chat_Handoff::get_script_data();

			if ( ! empty( $handoff ) ) {
				$data['handoff'] = $handoff;
			}
		}

		/*
		 * HONESTY, not decoration. The widget's built-in footnote reads "AI
		 * assistant — it can make mistakes". In lookup mode there is no AI at
		 * all: it is keyword matching, and telling customers otherwise is the
		 * one thing this mode must never do. Set as a DEFAULT, before the
		 * owner's copy is merged in below, so an explicitly configured footnote
		 * still wins.
		 */
		/*
		 * Checked by MODE, not engine: hybrid also runs on the lookup engine,
		 * and would otherwise inherit a footnote promising there is no AI.
		 */
		if ( self::MODE_HYBRID === $this->get_mode() ) {
			$data['footnote'] = __( 'Automated assistant — some answers are written by AI and can be wrong. Please confirm fitment before you buy.', 'durianbikers-chatbot' );
		} elseif ( self::ENGINE_LOOKUP === $this->get_engine() ) {
			$data['footnote'] = __( 'Automated lookup — it matches keywords, not meaning, so it can miss things. Please confirm fitment before you buy.', 'durianbikers-chatbot' );
		}

		if ( is_callable( array( 'DB_Chat_Settings', 'get_localize_copy' ) ) ) {
			$copy = DB_Chat_Settings::get_localize_copy();

			if ( is_array( $copy ) ) {
				// get_localize_copy() strips empty values, so this only ever
				// overrides keys the owner actually filled in.
				$data = array_merge( $data, $copy );
			}
		}

		/**
		 * Filter the data localized to the chatbot script.
		 *
		 * Never add a credential here — this array is printed into the page.
		 *
		 * @param array $data Localized data.
		 */
		return (array) apply_filters( 'db_chat_script_data', $data );
	}

	/**
	 * Add a Settings link on the plugins screen.
	 *
	 * The settings page itself is registered by DB_Chat_Settings; we only link
	 * to it, and only when it exists.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function add_settings_action_link( $links ) {
		if ( ! is_array( $links ) || ! class_exists( 'DB_Chat_Settings' ) || ! defined( 'DB_Chat_Settings::PAGE_SLUG' ) ) {
			return $links;
		}

		$url = add_query_arg( 'page', constant( 'DB_Chat_Settings::PAGE_SLUG' ), admin_url( 'admin.php' ) );

		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( $url ),
				esc_html__( 'Settings', 'durianbikers-chatbot' )
			)
		);

		return $links;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Misc
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( DB_CHAT_TEXTDOMAIN, false, dirname( plugin_basename( DB_CHAT_FILE ) ) . '/languages' );
	}

	/**
	 * Write an error to the PHP log with any credential redacted.
	 *
	 * Never pass request bodies, response bodies or option values into this.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function log_error( $message ) {
		$message = (string) $message;

		// Belt and braces: strip anything shaped like an Anthropic key.
		$message = (string) preg_replace( '/sk-ant-[A-Za-z0-9_\-]+/', '[redacted]', $message );

		error_log( '[durianbikers-chatbot] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

/**
 * Convenience accessor.
 *
 * @return DB_Chat_Plugin
 */
function db_chat() {
	return DB_Chat_Plugin::instance();
}

add_action( 'plugins_loaded', 'db_chat', 20 );

/*
 * ---------------------------------------------------------------------------
 * Activation / deactivation
 * ---------------------------------------------------------------------------
 *
 * Activation only seeds defaults with add_option(), a no-op when the option
 * already exists. Nothing here deletes or overwrites anything.
 */

register_activation_hook(
	__FILE__,
	function () {
		add_option( DB_CHAT_OPT_ENABLED, '0' );
		add_option( DB_CHAT_OPT_MODE, DB_Chat_Plugin::MODE_AUTO );
		add_option( DB_CHAT_OPT_MODEL, DB_CHAT_DEFAULT_MODEL );
		add_option( DB_CHAT_OPT_GREETING, '' );
		add_option( DB_CHAT_OPT_LOGGING, '0' );
		add_option( DB_CHAT_OPT_VISION, '1' );
		add_option( DB_CHAT_OPT_CONTACT_GATE, '1' );

		// Human handoff. Off by default, same reasoning as DB_CHAT_OPT_ENABLED
		// above: this runs on a live store, so the feature stays off until
		// someone deliberately turns it on.
		add_option( 'db_chat_handoff_enabled', '0' );
		add_option( 'db_chat_handoff_notify', '0' );
		add_option( 'db_chat_handoff_retention_days', '90' );
		add_option( 'db_chat_handoff_poll_seconds', '5' );
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		// Only the cached retrieval indexes. Settings are left untouched.
		delete_transient( 'db_chat_product_index' );
		delete_transient( 'db_chat_content_index' );

		wp_clear_scheduled_hook( 'db_chat_handoff_purge' );
	}
);
