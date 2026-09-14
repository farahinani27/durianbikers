<?php
/**
 * Monthly spend guard for hybrid mode.
 *
 * An ESTIMATE, priced deliberately high. It exists so a busy week cannot run
 * up an unexpected bill before anyone notices, and so running out degrades to
 * the free answer instead of an error. It is not a billing system: the spend
 * limit set in the Anthropic console is the only hard stop.
 *
 * @package DurianBikers_Chatbot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tracks estimated AI spend and decides whether another call is allowed.
 */
class DB_Chat_Ai_Budget {

	/** Option holding this month's spend. Autoload off. */
	const OPTION = 'db_chat_ai_spend';

	/** Option holding the monthly cap in MYR. */
	const CAP_OPTION = 'db_chat_ai_monthly_cap_myr';

	/** Default monthly cap in MYR. */
	const DEFAULT_CAP_MYR = 100.0;

	/** USD per million input tokens, Claude Haiku 4.5. */
	const PRICE_IN_USD_PER_MTOK = 1.00;

	/** USD per million output tokens, Claude Haiku 4.5. */
	const PRICE_OUT_USD_PER_MTOK = 5.00;

	/** Cache writes cost this multiple of the input price. */
	const CACHE_WRITE_MULTIPLIER = 1.25;

	/** Cache reads cost this multiple of the input price. */
	const CACHE_READ_MULTIPLIER = 0.10;

	/**
	 * Ringgit per US dollar, deliberately ABOVE the market rate.
	 *
	 * Overestimating cost trips the cap early. Underestimating it would let
	 * real spend run past what the owner agreed to before this noticed.
	 */
	const USD_MYR = 5.00;

	/**
	 * Option name. Overridable so tests never touch the live record.
	 *
	 * @var string
	 */
	private $option;

	/**
	 * Returns the current Unix time. Injectable so tests can move through days.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param string        $option Option name.
	 * @param callable|null $clock  Returns a Unix timestamp.
	 */
	public function __construct( $option = self::OPTION, $clock = null ) {
		$this->option = (string) $option;
		$this->clock  = is_callable( $clock ) ? $clock : 'time';
	}

	/**
	 * The monthly cap in MYR.
	 *
	 * @return float
	 */
	public function cap_myr() {
		$cap = (float) get_option( self::CAP_OPTION, self::DEFAULT_CAP_MYR );

		/**
		 * Filter the monthly AI cap, in MYR.
		 *
		 * @param float $cap Cap.
		 */
		$cap = (float) apply_filters( 'db_chat_ai_monthly_cap_myr', $cap );

		return $cap > 0 ? $cap : 0.0;
	}

	/**
	 * Estimated cost of one call, in MYR.
	 *
	 * @param array $usage Usage as returned by DB_Chat_Claude_Client::get_last_usage().
	 * @return float
	 */
	public function cost_myr( array $usage ) {
		$in  = self::tokens( $usage, 'input_tokens' );
		$out = self::tokens( $usage, 'output_tokens' );
		$cw  = self::tokens( $usage, 'cache_creation_input_tokens' );
		$cr  = self::tokens( $usage, 'cache_read_input_tokens' );

		$p_in  = (float) apply_filters( 'db_chat_ai_price_in', self::PRICE_IN_USD_PER_MTOK );
		$p_out = (float) apply_filters( 'db_chat_ai_price_out', self::PRICE_OUT_USD_PER_MTOK );
		$rate  = (float) apply_filters( 'db_chat_ai_usd_myr', self::USD_MYR );

		$usd = ( $in * $p_in
			+ $out * $p_out
			+ $cw * $p_in * self::CACHE_WRITE_MULTIPLIER
			+ $cr * $p_in * self::CACHE_READ_MULTIPLIER ) / 1000000;

		return $usd * $rate;
	}

	/**
	 * May another AI call be made right now?
	 *
	 * The daily allowance is recomputed from what is actually left, so a quiet
	 * day's unused budget rolls forward, while one busy day still cannot drain
	 * the month.
	 *
	 * @return bool
	 */
	public function can_spend() {
		$cap   = $this->cap_myr();
		$state = $this->state();

		if ( $cap <= 0 || $state['month_myr'] >= $cap ) {
			return false;
		}

		$allowance = ( $cap - $state['day_start_myr'] ) / $this->days_left();
		$today     = $state['month_myr'] - $state['day_start_myr'];

		return $today < $allowance;
	}

	/**
	 * Charge one call.
	 *
	 * Called after EVERY call that consumed tokens, including one that failed
	 * after earlier rounds succeeded — those rounds were billed.
	 *
	 * @param array $usage Usage.
	 * @return void
	 */
	public function record( array $usage ) {
		$tokens = self::tokens( $usage, 'input_tokens' )
			+ self::tokens( $usage, 'output_tokens' )
			+ self::tokens( $usage, 'cache_creation_input_tokens' )
			+ self::tokens( $usage, 'cache_read_input_tokens' );

		if ( $tokens <= 0 ) {
			return;
		}

		$state               = $this->state();
		$state['month_myr'] += $this->cost_myr( $usage );
		$state['calls']      = (int) $state['calls'] + 1;
		$state['last_usage'] = array(
			'input_tokens'                => self::tokens( $usage, 'input_tokens' ),
			'output_tokens'               => self::tokens( $usage, 'output_tokens' ),
			'cache_creation_input_tokens' => self::tokens( $usage, 'cache_creation_input_tokens' ),
			'cache_read_input_tokens'     => self::tokens( $usage, 'cache_read_input_tokens' ),
		);

		update_option( $this->option, $state, false );
	}

	/**
	 * Estimated spend so far this month, MYR.
	 *
	 * @return float
	 */
	public function month_to_date_myr() {
		return (float) $this->state()['month_myr'];
	}

	/**
	 * AI calls charged this month.
	 *
	 * @return int
	 */
	public function calls_this_month() {
		return (int) $this->state()['calls'];
	}

	/**
	 * Token counts of the most recently charged call. No message content.
	 *
	 * @return array<string,int>
	 */
	public function last_usage() {
		$state = $this->state();

		return is_array( $state['last_usage'] ) ? $state['last_usage'] : array();
	}

	/**
	 * Current record, rolled over to the current month and day — IN MEMORY.
	 *
	 * Never writes. Only record() persists, so merely reading the budget (the
	 * settings screen does) can never create or change the spend option. The
	 * result is the same: on a day with no spend yet, recomputing "start of
	 * today" from the stored month total gives an identical value every time.
	 *
	 * @return array<string,mixed>
	 */
	private function state() {
		$now   = (int) call_user_func( $this->clock );
		$month = wp_date( 'Y-m', $now );
		$day   = wp_date( 'Y-m-d', $now );

		$stored = get_option( $this->option, array() );
		$state  = array_merge(
			array(
				'month'         => '',
				'month_myr'     => 0.0,
				'day'           => '',
				'day_start_myr' => 0.0,
				'calls'         => 0,
				'last_usage'    => array(),
			),
			is_array( $stored ) ? $stored : array()
		);

		if ( $month !== $state['month'] ) {
			$state = array(
				'month'         => $month,
				'month_myr'     => 0.0,
				'day'           => $day,
				'day_start_myr' => 0.0,
				'calls'         => 0,
				'last_usage'    => array(),
			);
		} elseif ( $day !== $state['day'] ) {
			$state['day']           = $day;
			$state['day_start_myr'] = (float) $state['month_myr'];
		}

		return $state;
	}

	/**
	 * Days left in the month, today included. Never zero.
	 *
	 * @return int
	 */
	private function days_left() {
		$now = (int) call_user_func( $this->clock );

		return max( 1, (int) wp_date( 't', $now ) - (int) wp_date( 'j', $now ) + 1 );
	}

	/**
	 * One non-negative token count.
	 *
	 * @param array  $usage Usage.
	 * @param string $key   Field.
	 * @return int
	 */
	private static function tokens( array $usage, $key ) {
		return isset( $usage[ $key ] ) && is_numeric( $usage[ $key ] ) ? max( 0, (int) $usage[ $key ] ) : 0;
	}
}
