<?php
/**
 * Customer -> human WhatsApp handoff.
 *
 * Owns everything about the "talk to a person" button: the number, the copy,
 * the opening hours schedule, and whether the shop is open RIGHT NOW.
 *
 * TWO THINGS THIS FILE IS CAREFUL ABOUT
 *
 * 1. CACHING. The storefront is served from a LiteSpeed full-page cache with a
 *    7 day TTL, so anything printed into page HTML is potentially a week stale.
 *    The static configuration (number, label, hours summary) is fine there — it
 *    only changes when the owner edits a setting, and saving purges the cache.
 *    `is_open()` is NOT fine there: it changes twice a day on its own. It is
 *    therefore deliberately absent from the localized data and is served from
 *    the uncached /session REST route instead. Do not "helpfully" add an
 *    isOpen key to get_script_data() — see the note there.
 *
 * 2. FAILING CLOSED. If the hours setting cannot be parsed we report CLOSED,
 *    never open. Telling a customer a human is standing by at 2am when nobody
 *    is produces an unanswered message and a bad first impression; the reverse
 *    just means they send the message anyway and read a slightly cautious note.
 *
 * PRIVACY: the wa.me URL is built from configuration and nothing else. No order
 * number, no customer name, no email, no prefilled personal data — ever. Any
 * prefill text is added by the widget at click time from what the customer
 * typed themselves.
 *
 * @package DurianBikers_Chatbot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static helper. No state, no instance, nothing cached between requests.
 */
class DB_Chat_Handoff {

	/**
	 * Customer-facing WhatsApp number, digits only, international format.
	 *
	 * NOT the same thing as db_chat_whatsapp_to, which is where ORDER ALERTS go
	 * to the owner. This one is printed on the storefront for customers to
	 * message. They may be the same number; they are not the same setting.
	 */
	const OPT_NUMBER = 'db_chat_handoff_number';

	/** Optional button label override. */
	const OPT_LABEL = 'db_chat_handoff_label';

	/** Machine-readable opening hours. Drives isOpen. */
	const OPT_HOURS = 'db_chat_handoff_hours';

	/** Optional human-readable hours override. Display only. */
	const OPT_HOURS_TEXT = 'db_chat_handoff_hours_text';

	/** Shortest plausible E.164 subscriber number. */
	const MIN_DIGITS = 7;

	/** E.164 maximum. */
	const MAX_DIGITS = 15;

	/** wa.me link base. Deliberately no query string — see the file header. */
	const WA_BASE = 'https://wa.me/';

	/**
	 * Weekday token => PHP weekday index (0 = Sunday, matching date('w')).
	 *
	 * @var array<string,int>
	 */
	const DAY_TOKENS = array(
		'sun'       => 0,
		'sunday'    => 0,
		'mon'       => 1,
		'monday'    => 1,
		'tue'       => 2,
		'tues'      => 2,
		'tuesday'   => 2,
		'wed'       => 3,
		'weds'      => 3,
		'wednesday' => 3,
		'thu'       => 4,
		'thur'      => 4,
		'thurs'     => 4,
		'thursday'  => 4,
		'fri'       => 5,
		'friday'    => 5,
		'sat'       => 6,
		'saturday'  => 6,
	);

	/**
	 * Order the summary text lists days in. Monday first, Malaysian week.
	 *
	 * @var int[]
	 */
	const DISPLAY_ORDER = array( 1, 2, 3, 4, 5, 6, 0 );

	/**
	 * The store's real hours, used when the setting is empty.
	 *
	 * Mon-Fri 10:00-18:00, Sat 10:00-15:00, Sunday closed.
	 */
	const DEFAULT_HOURS = "mon-fri 10:00-18:00\nsat 10:00-15:00\nsun closed";

	/*
	 * -----------------------------------------------------------------------
	 * Configuration
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Read an option without depending on the optional settings class.
	 *
	 * @param string $option  Option name.
	 * @param string $default Fallback.
	 * @return string
	 */
	private static function setting( $option, $default = '' ) {
		if ( class_exists( 'DB_Chat_Plugin' ) && is_callable( array( 'DB_Chat_Plugin', 'setting' ) ) ) {
			return (string) DB_Chat_Plugin::setting( $option, $default );
		}

		$value = get_option( $option, $default );

		return is_scalar( $value ) ? trim( (string) $value ) : $default;
	}

	/**
	 * The customer-facing WhatsApp number, digits only.
	 *
	 * Returns '' — meaning "feature off" — for anything that is not a usable
	 * international number. A number beginning with 0 is a local-format number
	 * (e.g. 012-345 6789) that was never converted, and wa.me would silently
	 * resolve it to the wrong person or to nobody, so it is rejected here as
	 * well as at save time.
	 *
	 * @return string Digits, or '' when not configured / unusable.
	 */
	public static function get_number() {
		$raw = self::setting( self::OPT_NUMBER, '' );

		return self::normalize_number( $raw );
	}

	/**
	 * Reduce a raw value to a usable E.164-style digit string.
	 *
	 * @param mixed $raw Raw value.
	 * @return string Digits, or '' if unusable.
	 */
	public static function normalize_number( $raw ) {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}

		$digits = preg_replace( '/[^0-9]/', '', (string) $raw );
		$digits = is_string( $digits ) ? $digits : '';

		if ( '' === $digits ) {
			return '';
		}

		/*
		 * Malaysian local form: 01x-xxx xxxx. Typed that way into the settings
		 * screen it used to normalise to '' and the WhatsApp button silently
		 * disappeared. 10 or 11 digits starting 01 become 60 + the number
		 * without its leading 0. Any other leading 0 is still refused.
		 */
		if ( 1 === preg_match( '/^01[0-9]{8,9}$/', $digits ) ) {
			$digits = '6' . $digits;
		}

		if ( '0' === $digits[0] ) {
			return '';
		}

		$length = strlen( $digits );

		if ( $length < self::MIN_DIGITS || $length > self::MAX_DIGITS ) {
			return '';
		}

		return $digits;
	}

	/**
	 * Is the handoff configured well enough to show?
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== self::get_number();
	}

	/**
	 * The wa.me link.
	 *
	 * Configuration only. Never carries a customer identifier, an order number,
	 * or any prefilled personal data.
	 *
	 * @return string URL, or '' when not configured.
	 */
	public static function get_url() {
		$number = self::get_number();

		if ( '' === $number ) {
			return '';
		}

		return esc_url_raw( self::WA_BASE . $number );
	}

	/**
	 * Button label. Falls back to the built-in wording.
	 *
	 * @return string Plain text.
	 */
	public static function get_label() {
		$label = self::setting( self::OPT_LABEL, '' );

		if ( '' === trim( $label ) ) {
			$label = __( 'Chat with us on WhatsApp', 'durianbikers-chatbot' );
		}

		/**
		 * Filter the handoff button label.
		 *
		 * @param string $label Button label.
		 */
		$label = (string) apply_filters( 'db_chat_handoff_label', $label );

		return self::clean_line( $label, 80 );
	}

	/**
	 * Human-readable opening hours.
	 *
	 * Generated from the machine-readable schedule so the two can never drift
	 * apart, unless the owner deliberately overrides the wording.
	 *
	 * @return string Plain text. '' when the schedule is unusable.
	 */
	public static function get_hours_text() {
		$override = self::setting( self::OPT_HOURS_TEXT, '' );

		if ( '' !== trim( $override ) ) {
			return self::clean_line( $override, 160 );
		}

		$schedule = self::get_schedule();

		if ( null === $schedule ) {
			return '';
		}

		return self::format_schedule( $schedule );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Opening hours
	 * -----------------------------------------------------------------------
	 */

	/**
	 * The raw hours setting, falling back to the store's real hours.
	 *
	 * @return string
	 */
	private static function get_hours_raw() {
		$raw = self::setting( self::OPT_HOURS, '' );

		if ( '' === trim( $raw ) ) {
			$raw = self::DEFAULT_HOURS;
		}

		return $raw;
	}

	/**
	 * Parsed schedule for the configured hours.
	 *
	 * Memoized per raw string for the lifetime of the request. Parsing is a
	 * handful of preg calls over a sub-200-character option that WordPress has
	 * already loaded, so a transient would cost more than it saved — but the
	 * session route can be hit often, and re-parsing per call is pointless.
	 *
	 * @return array<int,array<int,array{start:int,end:int}>>|null Null when malformed.
	 */
	public static function get_schedule() {
		static $cache = array();

		$raw = self::get_hours_raw();
		$key = md5( $raw );

		if ( ! array_key_exists( $key, $cache ) ) {
			$cache[ $key ] = self::parse_hours( $raw );
		}

		return $cache[ $key ];
	}

	/**
	 * Parse the compact hours format.
	 *
	 * One rule per line:
	 *
	 *     mon-fri 10:00-18:00
	 *     sat 10:00-15:00
	 *     sun closed
	 *
	 * Day lists (`mon,wed,fri`), day ranges (`mon-fri`) and split shifts
	 * (`sat 10:00-13:00, 14:00-17:00`) are all accepted. A day never mentioned
	 * is closed.
	 *
	 * Returns NULL — not an empty schedule — if ANY line is unparseable. A
	 * half-understood timetable is worse than no timetable, because the half we
	 * understood would be presented to customers as fact.
	 *
	 * @param string $raw Raw setting value.
	 * @return array<int,array<int,array{start:int,end:int}>>|null
	 */
	public static function parse_hours( $raw ) {
		if ( ! is_scalar( $raw ) ) {
			return null;
		}

		$raw = (string) $raw;

		// Normalise the dashes people actually type, then fold case.
		$raw = str_replace( array( "\xE2\x80\x93", "\xE2\x80\x94", "\xE2\x88\x92", ' to ' ), '-', $raw );
		$raw = strtolower( $raw );

		$lines = preg_split( '/[\r\n;]+/', $raw );

		if ( ! is_array( $lines ) ) {
			return null;
		}

		$schedule = array_fill( 0, 7, array() );
		$seen     = false;

		foreach ( $lines as $line ) {
			$line = trim( (string) preg_replace( '/\s+/', ' ', $line ) );

			if ( '' === $line ) {
				continue;
			}

			if ( ! preg_match( '/^([a-z][a-z, \-]*?) +(closed|[0-9].*)$/', $line, $matches ) ) {
				return null;
			}

			$days = self::parse_days( $matches[1] );

			if ( null === $days ) {
				return null;
			}

			$seen = true;

			if ( 'closed' === $matches[2] ) {
				foreach ( $days as $day ) {
					$schedule[ $day ] = array();
				}

				continue;
			}

			$ranges = self::parse_ranges( $matches[2] );

			if ( null === $ranges ) {
				return null;
			}

			foreach ( $days as $day ) {
				foreach ( $ranges as $range ) {
					$schedule[ $day ][] = $range;
				}
			}
		}

		if ( ! $seen ) {
			return null;
		}

		return $schedule;
	}

	/**
	 * Expand a day expression into weekday indexes.
	 *
	 * @param string $expression e.g. "mon-fri" or "mon,wed,fri" or "sat".
	 * @return int[]|null Null when any token is not a weekday.
	 */
	private static function parse_days( $expression ) {
		$parts = explode( ',', $expression );
		$days  = array();

		foreach ( $parts as $part ) {
			$part = trim( $part );

			if ( '' === $part ) {
				return null;
			}

			if ( false !== strpos( $part, '-' ) ) {
				$bounds = explode( '-', $part );

				if ( 2 !== count( $bounds ) ) {
					return null;
				}

				$from = self::day_index( trim( $bounds[0] ) );
				$to   = self::day_index( trim( $bounds[1] ) );

				if ( null === $from || null === $to ) {
					return null;
				}

				// Walk forwards, wrapping through Sunday, so sat-sun works.
				$day = $from;

				for ( $guard = 0; $guard < 7; $guard++ ) {
					$days[] = $day;

					if ( $day === $to ) {
						break;
					}

					$day = ( $day + 1 ) % 7;
				}

				continue;
			}

			$index = self::day_index( $part );

			if ( null === $index ) {
				return null;
			}

			$days[] = $index;
		}

		return array_values( array_unique( $days ) );
	}

	/**
	 * Weekday token to index.
	 *
	 * @param string $token Token.
	 * @return int|null
	 */
	private static function day_index( $token ) {
		return isset( self::DAY_TOKENS[ $token ] ) ? self::DAY_TOKENS[ $token ] : null;
	}

	/**
	 * Parse one or more HH:MM-HH:MM ranges into minutes-from-midnight.
	 *
	 * @param string $expression Time expression.
	 * @return array<int,array{start:int,end:int}>|null
	 */
	private static function parse_ranges( $expression ) {
		$parts  = explode( ',', $expression );
		$ranges = array();

		foreach ( $parts as $part ) {
			$part = trim( $part );

			if ( ! preg_match( '/^([0-9]{1,2}):([0-9]{2}) *- *([0-9]{1,2}):([0-9]{2})$/', $part, $m ) ) {
				return null;
			}

			$open_hour   = (int) $m[1];
			$open_min    = (int) $m[2];
			$close_hour  = (int) $m[3];
			$close_min   = (int) $m[4];

			// 24:00 is accepted as "midnight, end of day"; 24:30 is not a time.
			if ( $open_hour > 23 || $close_hour > 24 || $open_min > 59 || $close_min > 59 ) {
				return null;
			}

			if ( 24 === $close_hour && 0 !== $close_min ) {
				return null;
			}

			$start = ( $open_hour * 60 ) + $open_min;
			$end   = ( $close_hour * 60 ) + $close_min;

			// No overnight shifts: a motorcycle parts shop does not have them,
			// and silently accepting one would invert the open/closed test.
			if ( $end <= $start ) {
				return null;
			}

			$ranges[] = array(
				'start' => $start,
				'end'   => $end,
			);
		}

		return empty( $ranges ) ? null : $ranges;
	}

	/**
	 * Is a human available right now?
	 *
	 * Uses the SITE timezone via current_datetime() / wp_timezone(). Never
	 * date_default_timezone_get() and never time() with a hardcoded offset:
	 * PHP's default timezone on this host is unrelated to the shop's, and a
	 * hardcoded +8 silently rots if the site is ever moved.
	 *
	 * @param DateTimeInterface|null $now Optional. Injected for testing.
	 * @return bool
	 */
	public static function is_open( $now = null ) {
		$schedule = self::get_schedule();

		if ( ! is_array( $schedule ) ) {
			return false;
		}

		if ( ! $now instanceof DateTimeInterface ) {
			$now = self::now();
		}

		if ( ! $now instanceof DateTimeInterface ) {
			return false;
		}

		$day = (int) $now->format( 'w' );

		if ( empty( $schedule[ $day ] ) ) {
			return false;
		}

		$minutes = ( (int) $now->format( 'G' ) * 60 ) + (int) $now->format( 'i' );

		foreach ( $schedule[ $day ] as $range ) {
			// Half-open interval: at the closing minute we are shut.
			if ( $minutes >= $range['start'] && $minutes < $range['end'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * "Now", in the site's timezone.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function now() {
		try {
			if ( function_exists( 'current_datetime' ) ) {
				return current_datetime();
			}

			$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );

			return new DateTimeImmutable( 'now', $timezone );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/*
	 * -----------------------------------------------------------------------
	 * Display formatting
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Turn a parsed schedule into "Mon-Fri 10am-6pm · Sat 10am-3pm".
	 *
	 * Consecutive days with identical hours are grouped; closed days are simply
	 * left out, so nobody has to read "Sun: closed".
	 *
	 * @param array $schedule Parsed schedule.
	 * @return string
	 */
	public static function format_schedule( array $schedule ) {
		$groups  = array();
		$current = null;

		foreach ( self::DISPLAY_ORDER as $day ) {
			$ranges    = isset( $schedule[ $day ] ) && is_array( $schedule[ $day ] ) ? $schedule[ $day ] : array();
			$signature = self::range_signature( $ranges );

			if ( '' === $signature ) {
				// Closed: breaks any run in progress.
				if ( null !== $current ) {
					$groups[] = $current;
					$current  = null;
				}

				continue;
			}

			if ( null !== $current && $current['signature'] === $signature ) {
				$current['last'] = $day;

				continue;
			}

			if ( null !== $current ) {
				$groups[] = $current;
			}

			$current = array(
				'signature' => $signature,
				'ranges'    => $ranges,
				'first'     => $day,
				'last'      => $day,
			);
		}

		if ( null !== $current ) {
			$groups[] = $current;
		}

		if ( empty( $groups ) ) {
			return '';
		}

		$parts = array();

		foreach ( $groups as $group ) {
			$days = self::day_name( $group['first'] );

			if ( $group['first'] !== $group['last'] ) {
				$days .= "\xE2\x80\x93" . self::day_name( $group['last'] );
			}

			$times = array();

			foreach ( $group['ranges'] as $range ) {
				$times[] = self::time_name( $range['start'] ) . "\xE2\x80\x93" . self::time_name( $range['end'] );
			}

			$parts[] = $days . ' ' . implode( ', ', $times );
		}

		return implode( " \xC2\xB7 ", $parts );
	}

	/**
	 * Stable signature for a day's ranges, used to group identical days.
	 *
	 * @param array $ranges Ranges.
	 * @return string '' when closed.
	 */
	private static function range_signature( array $ranges ) {
		$parts = array();

		foreach ( $ranges as $range ) {
			if ( isset( $range['start'], $range['end'] ) ) {
				$parts[] = (int) $range['start'] . '-' . (int) $range['end'];
			}
		}

		return implode( ',', $parts );
	}

	/**
	 * Localized short weekday name.
	 *
	 * Built from a fixed reference week in UTC so the site timezone cannot
	 * shift the name by a day.
	 *
	 * @param int $day Weekday index, 0 = Sunday.
	 * @return string
	 */
	private static function day_name( $day ) {
		// 2023-12-31 was a Sunday.
		$timestamp = strtotime( '2023-12-31 12:00:00 UTC' ) + ( ( (int) $day % 7 ) * DAY_IN_SECONDS );

		return self::format_utc( 'D', $timestamp );
	}

	/**
	 * Localized clock time, minutes dropped when they are zero.
	 *
	 * @param int $minutes Minutes from midnight.
	 * @return string
	 */
	private static function time_name( $minutes ) {
		$minutes   = max( 0, (int) $minutes );
		$timestamp = strtotime( '2024-01-01 00:00:00 UTC' ) + ( $minutes * MINUTE_IN_SECONDS );
		$format    = ( 0 === $minutes % 60 ) ? 'ga' : 'g:ia';

		return self::format_utc( $format, $timestamp );
	}

	/**
	 * Format a timestamp in UTC, translated where the core helper exists.
	 *
	 * @param string $format    Date format.
	 * @param int    $timestamp Timestamp.
	 * @return string
	 */
	private static function format_utc( $format, $timestamp ) {
		if ( function_exists( 'wp_date' ) ) {
			$formatted = wp_date( $format, $timestamp, new DateTimeZone( 'UTC' ) );

			if ( is_string( $formatted ) && '' !== $formatted ) {
				return $formatted;
			}
		}

		return gmdate( $format, $timestamp );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Output
	 * -----------------------------------------------------------------------
	 */

	/**
	 * The static half of the handoff contract, for wp_localize_script.
	 *
	 * DELIBERATELY CONTAINS NO isOpen. This array is printed into page HTML that
	 * LiteSpeed keeps for up to 7 days; a baked-in open/closed flag would tell
	 * customers a human is standing by at 3am for the rest of the week. The
	 * widget reads isOpen from the uncached /session route instead.
	 *
	 * Returns an EMPTY ARRAY when no number is configured. The caller must then
	 * omit the key entirely — a half-built handoff object is worse than none,
	 * because the widget would render a button that goes nowhere.
	 *
	 * @return array<string,string>
	 */
	public static function get_script_data() {
		$url = self::get_url();

		if ( '' === $url ) {
			return array();
		}

		$data = array(
			'url'        => $url,
			'label'      => self::get_label(),
			'hours'      => self::get_hours_text(),
			'openNote'   => self::get_open_note(),
			'closedNote' => self::get_closed_note(),
		);

		/**
		 * Filter the handoff data sent to the browser.
		 *
		 * Never add anything derived from a customer, an order or a session
		 * here: this array is printed into shared, cached page HTML.
		 *
		 * @param array $data Handoff data.
		 */
		$data = (array) apply_filters( 'db_chat_handoff_data', $data );

		return self::validate_script_data( $data );
	}

	/**
	 * Re-check the shape after filtering.
	 *
	 * A filter that drops the URL or hands back something odd must not be able
	 * to produce a half-built object, so anything unexpected collapses the whole
	 * feature rather than shipping a broken button.
	 *
	 * @param array $data Filtered data.
	 * @return array<string,string>
	 */
	private static function validate_script_data( array $data ) {
		$out = array();

		foreach ( array( 'url', 'label', 'hours', 'openNote', 'closedNote' ) as $key ) {
			$out[ $key ] = isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? (string) $data[ $key ] : '';
		}

		if ( '' === $out['url'] || '' === $out['label'] ) {
			return array();
		}

		if ( 0 !== strpos( $out['url'], self::WA_BASE ) ) {
			return array();
		}

		// The link is configuration only. A query string here would mean
		// somebody started prefilling it server-side, which is where personal
		// data leaks into a URL that gets cached and shared.
		if ( false !== strpos( $out['url'], '?' ) || false !== strpos( $out['url'], '#' ) ) {
			return array();
		}

		return $out;
	}

	/**
	 * Note shown while a human is available.
	 *
	 * @return string
	 */
	public static function get_open_note() {
		/**
		 * Filter the "we are open" note.
		 *
		 * @param string $note Note text.
		 */
		$note = (string) apply_filters(
			'db_chat_handoff_open_note',
			__( 'We usually reply within a few minutes.', 'durianbikers-chatbot' )
		);

		return self::clean_line( $note, 200 );
	}

	/**
	 * Note shown outside opening hours.
	 *
	 * @return string
	 */
	public static function get_closed_note() {
		/**
		 * Filter the "we are closed" note.
		 *
		 * @param string $note Note text.
		 */
		$note = (string) apply_filters(
			'db_chat_handoff_closed_note',
			__( "We're closed now — send a message and we'll reply when we open.", 'durianbikers-chatbot' )
		);

		return self::clean_line( $note, 200 );
	}

	/**
	 * The volatile half of the contract, for the uncached /session route.
	 *
	 * @return array{isOpen:bool}|array{} Empty when not configured.
	 */
	public static function get_session_data() {
		if ( ! self::is_configured() ) {
			return array();
		}

		return array( 'isOpen' => self::is_open() );
	}

	/**
	 * How the model should refer to the button, for the system prompt.
	 *
	 * Returns the LABEL, never the number and never the URL: the model has no
	 * business writing either, and a model-written phone number that is one
	 * digit out sends a customer to a stranger.
	 *
	 * @return string '' when the handoff is not configured.
	 */
	public static function get_prompt_label() {
		if ( ! self::is_configured() ) {
			return '';
		}

		return self::get_label();
	}

	/**
	 * Collapse to a single safe plain-text line.
	 *
	 * @param string $value Value.
	 * @param int    $max   Maximum length.
	 * @return string
	 */
	private static function clean_line( $value, $max ) {
		$value = wp_strip_all_tags( (string) $value );
		$value = (string) preg_replace( '/\s+/u', ' ', $value );
		$value = trim( $value );

		if ( function_exists( 'mb_substr' ) && mb_strlen( $value ) > $max ) {
			return trim( mb_substr( $value, 0, $max ) );
		}

		return $value;
	}
}
