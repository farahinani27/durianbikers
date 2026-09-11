<?php
/**
 * A half-finished order, carried between messages.
 *
 * The customer says "i want yamanaka for 2pcs", the bot asks which one, then
 * which size. Those three facts arrive across three messages, and something
 * has to hold them in between. This is that something.
 *
 * It lives in the REQUEST, not on the server: the reply hands it to the
 * browser and the browser hands it back, exactly as conversation history
 * already works. That means every field here is a CLAIM, not a fact. This
 * class sanitises shape and range only — whether the product exists, whether
 * the variation belongs to it, whether either is in stock, and what it costs
 * are all re-read from WooCommerce by DB_Chat_Cart_Writer before anything is
 * added to a cart. Nothing here may be trusted as a permission.
 *
 * @package DurianBikers_Chatbot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Value object for an in-progress purchase.
 */
class DB_Chat_Pending_Order {

	/** Bot asked which product; customer has not answered yet. */
	const STAGE_PRODUCT = 'ask_product';

	/** Bot asked which size; customer has not answered yet. */
	const STAGE_VARIATION = 'ask_variation';

	/** Bot showed the summary and is waiting for a yes. */
	const STAGE_CONFIRM = 'ask_confirm';

	/**
	 * Seconds a half-finished order stays alive.
	 *
	 * Long enough to walk away and come back mid-conversation, short enough
	 * that yesterday's abandoned browse cannot be confirmed into a purchase by
	 * a stray "yes" today.
	 */
	const TTL = 900;

	/** Hard ceiling, mirroring DB_Chat_Cart_Writer::MAX_QUANTITY. */
	const MAX_QUANTITY = 20;

	/** Most options we will ever offer, and therefore ever accept back. */
	const MAX_OFFERED = 12;

	/**
	 * Which question is outstanding.
	 *
	 * @var string
	 */
	public $stage = '';

	/**
	 * Claimed parent product.
	 *
	 * @var int
	 */
	public $product_id = 0;

	/**
	 * Claimed variation, 0 when not chosen yet or not applicable.
	 *
	 * @var int
	 */
	public $variation_id = 0;

	/**
	 * How many, already clamped to a sane range.
	 *
	 * @var int
	 */
	public $quantity = 1;

	/**
	 * What the bot actually offered — product ids, or rows of variation data.
	 *
	 * The customer's next message is matched against THIS, which is what makes
	 * an answer an answer rather than a fresh search.
	 *
	 * @var array<int,mixed>
	 */
	public $offered = array();

	/**
	 * Unix time the order was created.
	 *
	 * @var int
	 */
	public $created = 0;

	/**
	 * Parse whatever the browser sent.
	 *
	 * Returns null rather than a half-populated object whenever the payload is
	 * not recognisably one of ours — the caller then treats the message as a
	 * fresh question, which is the safe direction.
	 *
	 * @param mixed $raw Untrusted input.
	 * @return DB_Chat_Pending_Order|null
	 */
	public static function from_request( $raw ) {
		if ( ! is_array( $raw ) || empty( $raw['stage'] ) || ! is_scalar( $raw['stage'] ) ) {
			return null;
		}

		$stage = (string) $raw['stage'];

		if ( ! in_array( $stage, self::stages(), true ) ) {
			return null;
		}

		$order               = new self();
		$order->stage        = $stage;
		$order->product_id   = isset( $raw['product_id'] ) ? absint( $raw['product_id'] ) : 0;
		$order->variation_id = isset( $raw['variation_id'] ) ? absint( $raw['variation_id'] ) : 0;
		$order->quantity     = self::clamp_quantity( isset( $raw['quantity'] ) ? $raw['quantity'] : 1 );
		$order->created      = isset( $raw['created'] ) ? absint( $raw['created'] ) : 0;

		if ( isset( $raw['offered'] ) && is_array( $raw['offered'] ) ) {
			$order->offered = array_slice( array_values( $raw['offered'] ), 0, self::MAX_OFFERED );
		}

		return $order;
	}

	/**
	 * Fresh pending order, stamped now.
	 *
	 * @param string $stage One of the STAGE_ constants.
	 * @param array  $state product_id / variation_id / quantity / offered.
	 * @return DB_Chat_Pending_Order
	 */
	public static function create( $stage, array $state ) {
		$order               = new self();
		$order->stage        = (string) $stage;
		$order->product_id   = isset( $state['product_id'] ) ? absint( $state['product_id'] ) : 0;
		$order->variation_id = isset( $state['variation_id'] ) ? absint( $state['variation_id'] ) : 0;
		$order->quantity     = self::clamp_quantity( isset( $state['quantity'] ) ? $state['quantity'] : 1 );
		$order->created      = time();

		if ( isset( $state['offered'] ) && is_array( $state['offered'] ) ) {
			$order->offered = array_slice( array_values( $state['offered'] ), 0, self::MAX_OFFERED );
		}

		return $order;
	}

	/**
	 * Too old to still be the same conversation?
	 *
	 * A missing timestamp counts as expired. A payload without one did not come
	 * from us, and treating it as fresh would hand an unbounded lifetime to
	 * anything that simply omits the field.
	 *
	 * @return bool
	 */
	public function is_expired() {
		return $this->created < 1 || ( time() - $this->created ) > self::TTL;
	}

	/**
	 * Shape handed back to the browser.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		return array(
			'stage'        => $this->stage,
			'product_id'   => $this->product_id,
			'variation_id' => $this->variation_id,
			'quantity'     => $this->quantity,
			'offered'      => $this->offered,
			'created'      => $this->created,
		);
	}

	/**
	 * Every legal stage.
	 *
	 * @return string[]
	 */
	public static function stages() {
		return array( self::STAGE_PRODUCT, self::STAGE_VARIATION, self::STAGE_CONFIRM );
	}

	/**
	 * Quantity into a sane range.
	 *
	 * Deliberately intval(), NOT absint(). absint( -5 ) is 5 — it would take a
	 * nonsense quantity and silently turn it into an order for five. A value
	 * below one is not a small order, it is a broken payload, and the safe
	 * reading of a broken payload is one item.
	 *
	 * @param mixed $value Anything.
	 * @return int
	 */
	private static function clamp_quantity( $value ) {
		$quantity = is_scalar( $value ) ? (int) $value : 1;

		if ( $quantity < 1 ) {
			return 1;
		}

		return min( $quantity, self::MAX_QUANTITY );
	}
}
