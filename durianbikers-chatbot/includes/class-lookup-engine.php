<?php
/**
 * FREE lookup mode — the no-API-key alternative brain.
 *
 * WHAT THIS IS, AND WHAT IT IS NOT.
 *
 * This is deterministic keyword and intent matching. It is NOT a language
 * model, it does NOT understand the customer's question, and nothing in it
 * infers meaning. It recognises words it has been told about, decides which of
 * a fixed list of intents they point at, runs the SAME retrieval the Claude
 * path uses, and fills in a template. Unusual phrasing gets declined, and that
 * is the honest, intended behaviour — not a bug to paper over with guesswork.
 *
 * Every comment, template and settings string in this plugin must keep saying
 * so. A customer who believes they are talking to something that understands
 * them will phrase things it cannot match and will be told "no" by a shop that
 * actually stocks the part.
 *
 * WHY IT EXISTS: the store owner does not want a paid API key. This mode costs
 * nothing to run.
 *
 * NO OUTBOUND HTTP. ASSERT THIS ON EVERY CHANGE. There is no wp_remote_*,
 * no curl, no file_get_contents on a URL, and no third-party SDK anywhere in
 * this file or in anything it calls (DB_Chat_Product_Index runs
 * wc_get_products() natively; DB_Chat_Content_Index reads posts). Zero network
 * calls is the entire point of the mode — if a future change here needs a
 * network call, it belongs in the Claude path, not in this file.
 *
 * BOUNDARIES, identical to the rest of the plugin:
 *
 *   - Products: published + catalogue-visible only, in/out of stock only,
 *     never stock_quantity. The ~1,450 zero-variation Shopify import artifacts
 *     are reported as "listed but currently unavailable", never priced and
 *     never described as "we don't carry it".
 *   - Comparisons: COMPARE, NEVER RANK. "X vs Y" restates the facts each
 *     listing actually carries and stops. It never says which is better, in
 *     any wording, in either language, and it never orders the two in a way
 *     that reads as a recommendation — the sides are printed in the order the
 *     customer named them, and nothing is ever sorted. Asked outright which to
 *     buy, it answers the factual half and then says plainly that it cannot
 *     advise, and points at a human. Same reasoning as the fitment rule: a
 *     keyword matcher has no basis for a judgement, and an implied
 *     recommendation on a brake or a tyre is a safety and returns problem.
 *   - Pages: the same six-slug allowlist, published, not password protected.
 *   - Orders: REVERSED Aug 2026, deliberately, and read this before "fixing"
 *     it back. This file used to decline every order question outright. It no
 *     longer does — but it still contains NO order code. It DELEGATES, whole,
 *     to DB_Chat_Order_Lookup::get_my_orders(), which resolves identity from
 *     get_current_user_id() inside its own class and returns only its own
 *     field allowlist. So there is still exactly ONE order boundary in this
 *     plugin, in one file, and this class cannot widen it:
 *
 *       * There is no get_current_user_id(), no wc_get_orders(), no $wpdb and
 *         no customer identifier anywhere in this file. Grep proves it, and
 *         the offline harness asserts it.
 *       * get_my_orders() is called with an EMPTY array. Not even the optional
 *         order_number is passed: pulling a number out of free text is a
 *         guess, a wrong guess returns "not found" about an order the customer
 *         does own, and the customer's own recent orders answer the question
 *         anyway. Never add an argument to that call.
 *       * A guest is told to sign in and is NEVER asked for an order number,
 *         an email or a phone number — same rule as the Claude path. Asking a
 *         stranger for identifying details in a chat window is the phishing
 *         habit we refuse to normalise, and nothing here could use them.
 *       * Order replies are never cached and never logged. used_order_lookup()
 *         reports an order turn to the endpoint so its existing suppression
 *         withholds BOTH sides of the exchange — the question routinely
 *         carries an order number.
 *   - No other personal data of any kind: no customers, no users, no lists.
 *
 * UNMATCHED QUESTION LOG. Messages this mode could not understand are recorded
 * so the owner can see which wording to add to the keyword tables. Question
 * text, timestamp and language flag ONLY — no IP, no user, no session, no
 * history, no reply — and never an order question. See the accessors at the
 * bottom of this file.
 *
 * @package DurianBikers_Chatbot
 */

defined( 'ABSPATH' ) || exit;

/**
 * Deterministic keyword/intent lookup. No model, no network.
 */
class DB_Chat_Lookup_Engine {

	/** Longest customer message considered. Matches the endpoint's own cap. */
	const MAX_MESSAGE_CHARS = 1000;

	/** Longest policy passage quoted back, before trimming to a sentence. */
	const MAX_PASSAGE_CHARS = 480;

	/** Hardest cap on the composed reply. */
	const MAX_REPLY_CHARS = 1600;

	/** Products named in the reply text. The card array is capped separately. */
	const MAX_LISTED = 4;

	/**
	 * Listing cap for a browse question.
	 *
	 * "What models do you have" is a request to see the range, and four of
	 * thirteen is not an answer to it. Ordinary searches keep the tight cap
	 * above — a long list is right here and wrong there.
	 */
	const MAX_LISTED_BROWSE = 15;

	/**
	 * How much more a policy or contact word counts than a generic opener.
	 *
	 * "ada waranti tak?" is one point of product ("ada") against one point of
	 * warranty ("waranti"), and a straight tie would send a warranty question
	 * to the catalogue and return "no products found". A topic noun — warranty,
	 * penghantaran, alamat, fpx — is a far more specific signal than "do you
	 * have", so topic scores are doubled before the comparison. A genuine tie
	 * AFTER weighting still resolves to product lookup.
	 */
	const TOPIC_WEIGHT = 2;

	/**
	 * Marker inside DB_Chat_Content_Index's "nothing matched" notice.
	 *
	 * COUPLING, deliberate and narrow: search_pages() falls back to the opening
	 * paragraph of every help page when no section matched, which is useful
	 * context for a model and actively misleading as a quoted answer. The model
	 * is warned off it by the notice text; this class has no model to warn, so
	 * it detects that same notice and declines instead of quoting an opening
	 * paragraph as if it were the policy. If the notice wording in
	 * class-content-index.php changes, change this constant with it — the test
	 * harness asserts the two still line up.
	 */
	const NO_MATCH_MARKER = 'NO SECTION MATCHED';

	/**
	 * How many customer turns a remembered subject survives.
	 *
	 * THE SUBJECT SLOT, in one paragraph. "berapa harga" on its own has no
	 * subject, so the engine re-reads the recent history server-side and reuses
	 * the last product or brand that actually resolved. It is a SLOT, not
	 * comprehension: it carries a string forward, nothing more.
	 *
	 * The expiry is the important half. A stale subject leaking into an
	 * unrelated answer — quoting a tyre price at somebody now asking about
	 * returns — is the failure mode that makes this feature worse than not
	 * having it, and it is worse than a decline. So the slot dies three
	 * customer turns after the subject was named, and dies immediately the
	 * moment the customer asks about policy, contact, hours or an order.
	 */
	const SUBJECT_MAX_TURNS = 3;

	/** History entries considered. Matches the endpoint's own cap. */
	const MAX_HISTORY = 10;

	/** Products compared side by side in one reply. Never more than two. */
	const COMPARE_MAX_SIDES = 2;

	/**
	 * Longest side of a comparison kept, in characters.
	 *
	 * ONE value, producing ONE string that is used as BOTH the catalogue query
	 * and the text echoed back at the customer. That is deliberate. The
	 * recurring bug on this plugin has been a string truncated for display and
	 * matched at full length (or the reverse), so the two paths quietly stop
	 * agreeing months later. Truncate once, here, and hand the SAME value to
	 * the search and to the reply — there is then no second path to drift.
	 */
	const COMPARE_MAX_SIDE_CHARS = 60;

	/** Unmatched questions kept before the oldest is dropped. FIFO. */
	const MAX_UNMATCHED = 200;

	/** Longest unmatched question stored. */
	const MAX_UNMATCHED_CHARS = 300;

	/** Option holding the unmatched question log. Autoloaded OFF. */
	const OPTION_UNMATCHED_ENTRIES = 'db_chat_log_unmatched_entries';

	/** Option switching the unmatched question log on. Default ON. */
	const OPTION_UNMATCHED_ENABLED = 'db_chat_log_unmatched';

	/** Orders described in one reply. */
	const ORDER_MAX_LISTED = 3;

	/** Item lines described per order. */
	const ORDER_MAX_ITEMS = 5;

	/**
	 * Shortest token that may be fuzzy-corrected.
	 *
	 * Below four characters everything is one edit from everything else —
	 * "pad"/"pads"/"rad", "gld"/"gold" — so short tokens are left alone.
	 */
	const FUZZY_MIN_CHARS = 4;

	/** Token length at which two edits are allowed instead of one. */
	const FUZZY_LONG_TOKEN = 8;

	/** Minimum similar_text() percentage before a correction is accepted. */
	const FUZZY_MIN_SIMILARITY = 82.0;

	/**
	 * Product retrieval service. Shared with the Claude path.
	 *
	 * @var DB_Chat_Product_Index
	 */
	private $product_index;

	/**
	 * Help page retrieval service, or null when unavailable.
	 *
	 * Optional for the same reason it is optional in the endpoint: a missing
	 * file must degrade the assistant to product answers, never fatal a live
	 * store.
	 *
	 * @var DB_Chat_Content_Index|null
	 */
	private $content_index;

	/**
	 * Own-order retrieval service, or null when it is unavailable.
	 *
	 * The ONLY path from this class to any personal data, and it is a
	 * delegation: this class never resolves who the customer is. See the file
	 * header.
	 *
	 * @var DB_Chat_Order_Lookup|null
	 */
	private $order_lookup;

	/**
	 * True when the last answer() call handled an ORDER question.
	 *
	 * Set for every order turn, not only the ones that returned data, because
	 * the risk is on the QUESTION side too: "where is order 10425" carries an
	 * order number whether or not we found it. The endpoint reads this through
	 * used_order_lookup() and withholds both sides of the exchange from the log.
	 *
	 * @var bool
	 */
	private $order_turn = false;

	/**
	 * Constructor.
	 *
	 * $order_lookup is optional and is normally NOT passed: the bootstrap
	 * builds this object with two arguments, so the service is constructed
	 * lazily on the first order question instead (see order_lookup()). The
	 * parameter exists so a test — or a future bootstrap — can inject one
	 * without this class reaching for a global.
	 *
	 * @param DB_Chat_Product_Index      $product_index Product retrieval service.
	 * @param DB_Chat_Content_Index|null $content_index Help page retrieval service.
	 * @param DB_Chat_Order_Lookup|null  $order_lookup  Own-order retrieval service.
	 */
	public function __construct( DB_Chat_Product_Index $product_index, $content_index = null, $order_lookup = null ) {
		$this->product_index = $product_index;
		$this->content_index = $content_index instanceof DB_Chat_Content_Index ? $content_index : null;
		$this->order_lookup  = $order_lookup instanceof DB_Chat_Order_Lookup ? $order_lookup : null;
	}

	/*
	 * =======================================================================
	 * THE KEYWORD TABLES
	 * =======================================================================
	 *
	 * ONE BLOCK, ON PURPOSE. Everything this mode can recognise is in the two
	 * methods below and nowhere else, so the owner can add their own wording
	 * without reading a line of the matching logic.
	 *
	 * Both English and Bahasa Malaysia. Malay is not an extra: the store's
	 * customers write in it constantly, and the plugin this replaces declines
	 * non-English outright.
	 *
	 * Matching is whole-phrase, on a normalised (lowercased, punctuation
	 * stripped) message, so entries must be lowercase and unpunctuated. A
	 * phrase scores its own word count — "where is my order" outweighs "order"
	 * — which is what stops a single common word hijacking an intent.
	 */

	/**
	 * Every recognised keyword, grouped by signal.
	 *
	 * @return array<string,string[]>
	 */
	public static function keywords() {
		$keywords = array(

			/* -------- greeting / smalltalk -------- */
			'greeting'     => array(
				'hi', 'hello', 'hey', 'helo', 'good morning', 'good afternoon', 'good evening',
				'thanks', 'thank you', 'ok thanks', 'bye', 'good day',
				'hai', 'haai', 'salam', 'assalamualaikum', 'apa khabar', 'apa kabar',
				'selamat pagi', 'selamat petang', 'selamat tengah hari', 'selamat malam',
				'terima kasih', 'tq', 'thanks ya', 'baik', 'ok terima kasih', 'selamat tinggal',
			),

			/* -------- "am I talking to a person?" -------- */
			'identity'     => array(
				'are you a real person', 'are you real', 'are you human', 'are you a human',
				'are you a bot', 'are you a robot', 'is this a bot', 'is this a real person',
				'am i talking to a human', 'am i talking to a person', 'are you ai', 'are you an ai',
				'who are you', 'chatgpt',
				'ini bot ke', 'ini robot ke', 'awak bot ke', 'awak bot', 'bot ke ni', 'bot ke',
				'manusia ke', 'orang ke', 'betul orang ke', 'siapa awak', 'siapa ni', 'ini ai ke',
			),

			/* -------- orders: DECLINED, never looked up -------- */
			'order'        => array(
				'my order', 'order status', 'status of my order', 'where is my order',
				'where is my parcel', 'track my order', 'tracking number', 'tracking',
				'has my order shipped', 'did my order ship', 'my parcel', 'my delivery',
				'what did i buy', 'my purchase', 'my receipt', 'my invoice', 'cancel my order',
				'change my order', 'refund my order', 'order number',
				'order saya', 'pesanan saya', 'status order', 'status pesanan', 'barang saya',
				'parcel saya', 'bungkusan saya', 'bila sampai', 'dah hantar ke', 'dah post ke',
				'no tracking', 'nombor tracking', 'resit saya', 'invois saya', 'batal order',
			),

			/* -------- price -------- */
			'price'        => array(
				'how much', 'how much is', 'how much for', 'what is the price', 'whats the price',
				'price of', 'price for', 'the price', 'price please', 'cost of', 'how much does',
				'harga', 'berapa harga', 'harga untuk', 'berapa', 'brape', 'brapa', 'harga dia',
				'harga berapa', 'berapa ringgit', 'rm berapa', 'kos',
			),

			/* -------- stock / availability -------- */
			'stock'        => array(
				'in stock', 'out of stock', 'still available', 'available', 'availability',
				'do you still have', 'still have', 'ready stock', 'restock', 'back in stock',
				'is it available', 'got stock',
				'ada stok', 'stok', 'ada lagi', 'masih ada', 'ada tak lagi', 'ready ke',
				'stok ada', 'ada ready', 'bila restock', 'habis stok',
			),

			/* -------- price filter -------- */
			'price_filter' => array(
				'under', 'below', 'less than', 'cheaper than', 'cheapest', 'lowest price',
				'budget', 'affordable', 'no more than',
				'bawah', 'kurang dari', 'kurang daripada', 'murah', 'termurah', 'paling murah',
				'yang murah', 'bajet', 'ke bawah', 'tak lebih',
			),

			/* -------- size / colour / attribute -------- */
			'attribute'    => array(
				'size', 'sizes', 'what size', 'which size', 'colour', 'color', 'colours', 'colors',
				'what colour', 'what color',
				'saiz', 'ukuran', 'warna', 'apa warna', 'saiz apa', 'ada saiz', 'ada warna',
			),

			/* -------- fitment: never answered, only caveated -------- */
			'fitment'      => array(
				'will it fit', 'does it fit', 'fit my', 'fits my', 'compatible', 'compatibility',
				'suitable for my', 'for my bike', 'boleh pakai', 'sesuai untuk', 'muat tak',
				'muat ke', 'boleh masuk', 'sesuai tak',
			),

			/* -------- policy: shipping -------- */
			'shipping'     => array(
				'shipping', 'shipping cost', 'shipping charge', 'delivery cost', 'delivery charge',
				'postage cost', 'how much is shipping', 'how much is delivery', 'how much is postage',
				'harga penghantaran', 'kos hantar', 'berapa penghantaran', 'berapa kos hantar',
				'delivery', 'deliver', 'postage', 'courier', 'dhl',
				'poslaju', 'ship to', 'do you ship', 'how long to deliver', 'delivery time',
				'free shipping', 'east malaysia', 'sabah', 'sarawak', 'overseas',
				'penghantaran', 'hantar', 'kos penghantaran', 'berapa lama sampai', 'sampai bila',
				'pos', 'poskan', 'boleh hantar', 'hantar ke', 'berapa hari', 'free postage',
			),

			/* -------- policy: returns / refunds -------- */
			'returns'      => array(
				'return', 'returns', 'return policy', 'refund', 'refunds', 'exchange', 'money back',
				'send it back', 'wrong item', 'faulty item',
				'pemulangan', 'pulangkan', 'boleh pulang', 'tukar barang', 'boleh tukar',
				'bayar balik', 'duit balik', 'salah barang',
			),

			/* -------- policy: warranty -------- */
			'warranty'     => array(
				'warranty', 'guarantee', 'guaranteed', 'defect', 'defective', 'faulty',
				'jaminan', 'waranti', 'garansi', 'rosak', 'cacat',
			),

			/* -------- policy: payment -------- */
			'payment'      => array(
				'payment', 'payment method', 'how do i pay', 'how to pay', 'pay by', 'fpx',
				'credit card', 'debit card', 'bank transfer', 'online banking', 'instalment',
				'installment', 'cash on delivery', 'cod',
				'bayaran', 'cara bayar', 'boleh bayar', 'bayar guna', 'ansuran', 'bayar cash',
				'transfer bank', 'bayar masa terima',
			),

			/* -------- policy: terms -------- */
			'terms'        => array(
				'terms', 'terms and conditions', 'conditions', 'policy', 'policies', 'privacy',
				'terma', 'syarat', 'terma dan syarat', 'polisi', 'privasi',
			),

			/* -------- contact -------- */
			'contact'      => array(
				'contact', 'contact you', 'contact number', 'phone number', 'your number', 'email',
				'email address', 'whatsapp', 'call you', 'talk to a human', 'talk to someone',
				'speak to someone', 'speak to a person', 'customer service', 'customer support',
				'hubungi', 'nombor telefon', 'no telefon', 'nombor', 'emel', 'cakap dengan orang',
				'nak tanya orang', 'khidmat pelanggan',
			),

			/* -------- opening hours -------- */
			'hours'        => array(
				'opening hours', 'open today', 'are you open', 'what time do you open',
				'what time do you close', 'closing time', 'business hours', 'working hours',
				'open on sunday', 'open on saturday', 'open on weekend',
				'waktu operasi', 'waktu buka', 'jam buka', 'bila buka', 'buka pukul',
				'buka tak', 'tutup pukul', 'buka ahad', 'buka sabtu', 'waktu urusniaga',
			),

			/* -------- location -------- */
			'location'     => array(
				'where are you', 'where are you located', 'your address', 'shop address',
				'store address', 'location', 'showroom', 'directions', 'walk in',
				'di mana', 'dimana', 'kat mana', 'lokasi', 'alamat', 'alamat kedai', 'kedai mana',
				'boleh datang', 'ada kedai',
			),

			/* -------- product lookup -------- */
			'product'      => array(
				'do you have', 'do you sell', 'do you stock', 'do you carry', 'looking for',
				'i want', 'i need', 'show me', 'any', 'got any', 'sell', 'stock any', 'recommend',
				'suggest', 'what do you have', 'searching for', 'find me',
				'ada', 'ada tak', 'ada ke', 'adakah', 'jual', 'ada jual', 'nak beli', 'nak cari',
				'cari', 'tunjuk', 'nak', 'saya nak', 'nak tanya pasal', 'ada barang',
			),
		);

		/**
		 * Filter the lookup mode keyword tables.
		 *
		 * Add the wording your customers actually use. Entries must be
		 * lowercase, unpunctuated, and are matched as whole phrases against the
		 * normalised message. A longer phrase scores higher than a single word,
		 * so prefer "bila sampai barang" over "bila".
		 *
		 * This is keyword matching, not comprehension: adding a word here makes
		 * the assistant recognise that word, not the idea behind it.
		 *
		 * @param array<string,string[]> $keywords Signal group => phrases.
		 */
		$keywords = (array) apply_filters( 'db_chat_lookup_keywords', $keywords );

		$clean = array();

		foreach ( $keywords as $group => $phrases ) {
			if ( ! is_string( $group ) || ! is_array( $phrases ) ) {
				continue;
			}

			$list = array();

			foreach ( $phrases as $phrase ) {
				if ( ! is_scalar( $phrase ) ) {
					continue;
				}

				$phrase = trim( preg_replace( '/[^a-z0-9]+/', ' ', strtolower( (string) $phrase ) ) );

				if ( '' !== $phrase ) {
					$list[ $phrase ] = true;
				}
			}

			if ( ! empty( $list ) ) {
				$clean[ $group ] = array_keys( $list );
			}
		}

		return $clean;
	}

	/**
	 * Words dropped before a product query is handed to the catalogue search.
	 *
	 * English and Malay. Deliberately short: dropping too much turns "do you
	 * have the R&G for a CB650R" into a search for nothing.
	 *
	 * @return string[]
	 */
	public static function stopwords() {
		$stopwords = array(
			'the', 'a', 'an', 'and', 'or', 'of', 'for', 'to', 'in', 'on', 'at', 'is', 'it', 'its',
			'do', 'does', 'did', 'you', 'your', 'yours', 'we', 'us', 'our', 'i', 'im', 'me', 'my',
			'have', 'has', 'had', 'can', 'could', 'would', 'will', 'please', 'thanks', 'thank',
			'what', 'whats', 'which', 'how', 'when', 'where', 'who', 'why', 'there', 'here', 'this',
			'that', 'these', 'those', 'some', 'still', 'also', 'just', 'want', 'need', 'get',
			'yang', 'untuk', 'dengan', 'dan', 'atau', 'ini', 'itu', 'saya', 'aku', 'awak', 'kami',
			'kita', 'boleh', 'macam', 'mana', 'apa', 'ada', 'nak', 'tak', 'tidak', 'ke', 'kah',
			'lah', 'la', 'pun', 'je', 'sahaja', 'sini', 'situ', 'kalau', 'jika', 'pada', 'dari',
			'daripada', 'nya', 'tu', 'ni', 'bang', 'bro', 'sis', 'encik', 'puan', 'tuan',
		);

		/**
		 * Filter the stopwords stripped from a product query.
		 *
		 * @param string[] $stopwords Lowercase words.
		 */
		return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'db_chat_lookup_stopwords', $stopwords ) ) ) );
	}

	/**
	 * Spelling and shorthand folded before matching.
	 *
	 * Malaysian customers type SMS-style Malay ("brape", "ade", "x ada") and
	 * both British and American English spellings. Folding these is what stops
	 * the keyword tables needing five entries per idea.
	 *
	 * @return array<string,string>
	 */
	public static function variants() {
		$variants = array(
			// Malay shorthand.
			'ade'    => 'ada',
			'ada2'   => 'ada',
			'brape'  => 'berapa',
			'brapa'  => 'berapa',
			'brp'    => 'berapa',
			'hrga'   => 'harga',
			'sy'     => 'saya',
			'utk'    => 'untuk',
			'dgn'    => 'dengan',
			'blh'    => 'boleh',
			'bole'   => 'boleh',
			'nk'     => 'nak',
			// Single-letter folds are limited to 'x', the Malay SMS negation. Others
			// ('r' for are, 'u' for you) are deliberately absent: they would eat the
			// R and G of "R&G" and single-letter model codes.
			'x'      => 'tak',
			'xde'    => 'tak ada',
			'xda'    => 'tak ada',
			'tade'   => 'tak ada',
			'takde'  => 'tak ada',
			'kt'     => 'kat',
			'mne'    => 'mana',
			'mana2'  => 'mana',
			'bl'     => 'bila',
			'bile'   => 'bila',
			'brg'    => 'barang',
			'hntr'   => 'hantar',
			'pnghantaran' => 'penghantaran',
			'wrna'   => 'warna',
			'siz'    => 'saiz',

			/*
			 * Malay product nouns folded to the English word the CATALOGUE uses.
			 * Product titles in this store are English, so "tayar murah" has to
			 * become "tyre" or it searches for a word that appears nowhere.
			 * Deliberately short and unambiguous — a wrong fold here searches
			 * for the wrong part.
			 */
			'tayar'  => 'tyre',
			'tayer'  => 'tyre',
			'topi'   => 'helmet',
			'keledar' => 'helmet',
			'brek'   => 'brake',
			'minyak' => 'oil',
			'rantai' => 'chain',
			'bateri' => 'battery',
			'lampu'  => 'light',
			'cermin' => 'mirror',
			'ekzos'  => 'exhaust',
			'eksos'  => 'exhaust',
			'penapis' => 'filter',
			'jaket'  => 'jacket',
			'motosikal' => 'motorcycle',

			// English spelling variants.
			'tire'   => 'tyre',
			'tires'  => 'tyres',
			'color'  => 'colour',
			'colors' => 'colours',
			'gray'   => 'grey',
			'thx'    => 'thanks',
			'tq'     => 'thanks',
			'pls'    => 'please',
			'ur'     => 'your',
			'wat'    => 'what',
			'hw'     => 'how',
			'availble' => 'available',
			'avail'  => 'available',
			'delivary' => 'delivery',
			'shiping'  => 'shipping',
			'wa'     => 'whatsapp',
			'wsap'   => 'whatsapp',
		);

		/**
		 * Filter the spelling/shorthand fold applied before matching.
		 *
		 * Token-for-token only: keys and values are single words compared after
		 * lowercasing and punctuation stripping.
		 *
		 * @param array<string,string> $variants Typed form => canonical form.
		 */
		return (array) apply_filters( 'db_chat_lookup_variants', $variants );
	}

	/**
	 * Colour words recognised in a product query, English and Malay.
	 *
	 * Malay colours fold to their English equivalent because product TITLES in
	 * this catalogue are in English — searching for "hitam" finds nothing,
	 * searching for "black" finds the product the customer meant.
	 *
	 * @return array<string,string>
	 */
	public static function colours() {
		$colours = array(
			'black'  => 'black',
			'white'  => 'white',
			'red'    => 'red',
			'blue'   => 'blue',
			'green'  => 'green',
			'yellow' => 'yellow',
			'grey'   => 'grey',
			'silver' => 'silver',
			'gold'   => 'gold',
			'orange' => 'orange',
			'pink'   => 'pink',
			'purple' => 'purple',
			'brown'  => 'brown',
			'matt'   => 'matt',
			'matte'  => 'matte',
			'hitam'  => 'black',
			'putih'  => 'white',
			'merah'  => 'red',
			'biru'   => 'blue',
			'hijau'  => 'green',
			'kuning' => 'yellow',
			'kelabu' => 'grey',
			'perak'  => 'silver',
			'emas'   => 'gold',
			'oren'   => 'orange',
			'ungu'   => 'purple',
			'coklat' => 'brown',
		);

		/**
		 * Filter the recognised colour words.
		 *
		 * @param array<string,string> $colours Typed colour => search term.
		 */
		return (array) apply_filters( 'db_chat_lookup_colours', $colours );
	}

	/**
	 * Tokens that mark a message as Bahasa Malaysia.
	 *
	 * Curated so none of them is also an English word — a false positive here
	 * answers an English customer in Malay.
	 *
	 * @return string[]
	 */
	public static function malay_markers() {
		$markers = array(
			'ada', 'adakah', 'berapa', 'harga', 'saya', 'awak', 'nak', 'tak', 'boleh', 'jual',
			'beli', 'stok', 'saiz', 'warna', 'murah', 'termurah', 'bawah', 'hantar', 'penghantaran',
			'bila', 'mana', 'dimana', 'lokasi', 'alamat', 'hubungi', 'jaminan', 'waranti',
			'pemulangan', 'pulangkan', 'bayaran', 'bayar', 'terima', 'kasih', 'selamat', 'khabar',
			'waktu', 'buka', 'tutup', 'barang', 'kedai', 'pesanan', 'macam', 'sesuai', 'muat',
			'terma', 'syarat', 'cari', 'tunjuk', 'kos', 'ringgit', 'dah', 'belum', 'sini',
			'ini', 'itu', 'anda', 'tak', 'kah', 'ni', 'nombor', 'emel', 'sila', 'guna',
			// Comparison wording. Without these, "beza Shinko dengan Shoei" is
			// answered in English, because none of its other words is a marker.
			// "banding" is deliberately absent: it is also an English word.
			'atau', 'dengan', 'beza', 'bezanya', 'bandingkan', 'perbezaan', 'lebih',
		);

		/**
		 * Filter the tokens that switch replies to Bahasa Malaysia.
		 *
		 * @param string[] $markers Lowercase tokens.
		 */
		return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'db_chat_lookup_malay_markers', $markers ) ) ) );
	}

	/**
	 * Everything the COMPARISON intent recognises, in one table.
	 *
	 * Six groups, and the difference between them is the whole design:
	 *
	 *   markers  A word that says outright "this is a comparison" — compare,
	 *            beza, bandingkan, difference between. Stripped from the
	 *            message BEFORE the sides are split, so a marker can never end
	 *            up inside a product query or be echoed back at the customer.
	 *   strong   Separators that mean nothing else: vs, versus, lawan. These
	 *            always split, marker or no marker.
	 *   weak     "or" / "atau". Listed as a trigger in their own right, but
	 *            they are ordinary conjunctions, so they only split when the
	 *            message carries NO policy, contact, hours, location, order or
	 *            identity signal. "do you ship to Sabah or Sarawak" is a
	 *            shipping question, not a comparison of two products.
	 *   joined   "and" / "dan" / "dengan". Far too common to split on alone —
	 *            they only separate when a marker is also present, which is
	 *            exactly the "compare X and Y" / "banding X dengan Y" shape.
	 *   advice   "which is better", "mana lebih baik". Detected so the reply
	 *            can decline to advise EXPLICITLY, and stripped for the same
	 *            reason markers are.
	 *   ranking  Single judgement words scrubbed out of each side before it is
	 *            searched or echoed. Nothing in this catalogue is named
	 *            "better" or "terbaik", and letting one through would put a
	 *            ranking word into our own reply inside a quoted echo — which
	 *            is indistinguishable, to a customer, from us saying it.
	 *
	 * KEYWORD MATCHING, NOT COMPREHENSION, exactly like keywords(). Adding a
	 * word here makes this mode recognise that word, not the idea behind it.
	 *
	 * @return array<string,string[]>
	 */
	public static function compare_terms() {
		$terms = array(
			'markers' => array(
				'compare', 'comparison', 'compare between', 'compared to', 'compared with',
				'difference between', 'the difference between', 'what is the difference',
				'whats the difference', 'any difference', 'side by side',
				'banding', 'bandingkan', 'perbandingan', 'perbezaan', 'perbezaan antara',
				'beza', 'bezanya', 'apa beza', 'apa bezanya', 'beza antara', 'bandingan',
			),
			'strong'  => array( 'vs', 'versus', 'lawan' ),
			'weak'    => array( 'or', 'atau' ),
			'joined'  => array( 'and', 'dan', 'dengan' ),
			'advice'  => array(
				'which is better', 'which one is better', 'which is the better',
				'which is best', 'which one is best', 'which should i get',
				'which should i buy', 'which should i choose', 'what should i get',
				'what should i buy', 'which do you recommend', 'what do you recommend',
				'which would you recommend', 'which is worth it', 'which one do you prefer',
				'mana lebih baik', 'yang mana lebih baik', 'mana satu lebih baik',
				'mana lebih bagus', 'yang mana lebih bagus', 'mana lagi baik',
				'lebih baik mana', 'mana yang terbaik', 'yang mana terbaik',
				'mana lagi bagus', 'awak cadang yang mana', 'cadangkan yang mana',
			),
			'ranking' => array(
				'better', 'best', 'worse', 'worst', 'superior', 'inferior',
				'recommend', 'recommended', 'prefer', 'preferred', 'favourite', 'favorite',
				'baik', 'bagus', 'terbaik', 'lebih', 'cadang', 'cadangkan', 'disyorkan',
			),
		);

		/**
		 * Filter the comparison intent's trigger tables.
		 *
		 * Same rules as db_chat_lookup_keywords: lowercase, unpunctuated,
		 * matched as whole phrases against the normalised message. Entries in
		 * `ranking` are matched token-for-token, so put single words there.
		 *
		 * Removing a `ranking` word does NOT make this mode start ranking —
		 * nothing here composes a judgement. It only lets that word survive
		 * from the customer's own message into a quoted echo of it.
		 *
		 * @param array<string,string[]> $terms Group => phrases.
		 */
		$terms = (array) apply_filters( 'db_chat_lookup_compare', $terms );

		$clean = array();

		foreach ( array( 'markers', 'strong', 'weak', 'joined', 'advice', 'ranking' ) as $group ) {
			$list = isset( $terms[ $group ] ) && is_array( $terms[ $group ] ) ? $terms[ $group ] : array();
			$kept = array();

			foreach ( $list as $phrase ) {
				if ( ! is_scalar( $phrase ) ) {
					continue;
				}

				$phrase = trim( preg_replace( '/[^a-z0-9]+/', ' ', strtolower( (string) $phrase ) ) );

				if ( '' !== $phrase ) {
					$kept[ $phrase ] = true;
				}
			}

			$kept = array_keys( $kept );

			// Longest first, so "the difference between" is removed before
			// "difference between" leaves a stray "the" behind.
			usort(
				$kept,
				static function ( $a, $b ) {
					return strlen( (string) $b ) <=> strlen( (string) $a );
				}
			);

			$clean[ $group ] = $kept;
		}

		return $clean;
	}

	/*
	 * =======================================================================
	 * REPLY TEMPLATES
	 * =======================================================================
	 *
	 * Every word this mode can say is here. Nothing downstream writes prose.
	 *
	 * The English set goes through __() so a translation file can replace it.
	 * The Malay set is literal, because on a store running an en_US locale
	 * there is no translation file to consult and __() would hand back the
	 * English source — the whole point of answering in Malay is that it works
	 * on this store, today, with no .mo file. Both sets are filterable.
	 *
	 * Templates only ever restate what a query returned. No template invents a
	 * product, a price, a stock level or a policy.
	 *
	 * NOTE ON THE WHATSAPP WORDING: no template names the button. The pointer
	 * to a human is appended at compose time by handoff_hint(), which asks
	 * DB_Chat_Handoff whether a number is actually configured — otherwise the
	 * assistant would tell customers to press a button the widget is not
	 * rendering. The wording deliberately contains the word "WhatsApp": the
	 * widget highlights the handoff button when the reply mentions it.
	 */

	/**
	 * The reply templates for a language.
	 *
	 * @param string $lang 'en' or 'ms'.
	 * @return array<string,string>
	 */
	public static function templates( $lang = 'en' ) {
		$en = array(
			'greeting'           => __( "Hi! I'm the Durian Bikers shop assistant. I'm automated — not a person, and not an AI that understands language. I look things up: products, prices, stock, and what our shipping, returns, warranty and payment pages say. What are you after?", 'durianbikers-chatbot' ),
			'identity'           => __( "I'm not a person — I'm an automated assistant on the shop's website. I don't understand language either: I match what you type against our product catalogue and our help pages, and show you what I find.", 'durianbikers-chatbot' ),
			'order'              => __( "I can't check orders just now — the order lookup isn't responding. Please don't send me any personal details; a person can check it for you instead.", 'durianbikers-chatbot' ),
			'order_signed_out'   => __( "I can only look up orders for a customer who is signed in, because I read them from your account and nothing else. Please sign in on our website and ask me again. I won't ask you for an order number, an email address or a phone number — I can't look an order up that way, and you shouldn't be typing personal details into a chat window.", 'durianbikers-chatbot' ),
			'order_none'         => __( "I can't see any orders on the account you're signed in to. If you're sure you ordered, it may have been a guest checkout or a different account.", 'durianbikers-chatbot' ),
			'order_found'        => __( 'Here is what your account shows:', 'durianbikers-chatbot' ),

			/* translators: 1: order number; 2: order status, e.g. "Processing". */
			'order_line'         => __( '#%1$s — %2$s', 'durianbikers-chatbot' ),
			/* translators: %s: order date. */
			'order_placed'       => __( 'Placed %s', 'durianbikers-chatbot' ),
			/* translators: %s: order total. */
			'order_total'        => __( 'Total %s', 'durianbikers-chatbot' ),
			/* translators: %s: comma-separated item list. */
			'order_items'        => __( 'Items: %s', 'durianbikers-chatbot' ),
			/* translators: 1: item name; 2: quantity. */
			'order_item'         => __( '%1$s x%2$d', 'durianbikers-chatbot' ),
			/* translators: %s: tracking number. */
			'order_tracking'     => __( 'Tracking: %s', 'durianbikers-chatbot' ),
			/* translators: %s: tracking URL. */
			'order_tracking_url' => __( 'Track it: %s', 'durianbikers-chatbot' ),

			'order_no_eta'       => __( 'I only repeat what the order record says — I cannot work out a delivery date from it.', 'durianbikers-chatbot' ),
			'order_readonly'     => __( 'I can only read orders. I cannot cancel, change, refund or chase one.', 'durianbikers-chatbot' ),
			'order_more'         => __( 'Those are your most recent orders; your account page lists them all.', 'durianbikers-chatbot' ),

			/* translators: %s: the product or brand carried over from earlier. */
			'subject_carried'    => __( 'Taking that as still about "%s".', 'durianbikers-chatbot' ),
			/* translators: %s: the product or brand carried over from earlier. */
			'subject_gone'       => __( 'You asked about "%s" earlier, but I can no longer find it in our catalogue — so rather than answer about something else, I would rather stop here. Tell me the brand or product name again.', 'durianbikers-chatbot' ),
			/* translators: %s: the corrected spelling. */
			'fuzzy_corrected'    => __( 'I could not find what you typed, so I am showing results for "%s" instead.', 'durianbikers-chatbot' ),
			'fallback'           => __( "Sorry — I couldn't work out what you're asking. I'm a simple lookup, so I only recognise certain wording. I can check whether we stock something, what it costs, whether it's in stock, and what our shipping, returns, warranty and payment pages say. Try a brand or product name on its own.", 'durianbikers-chatbot' ),
			'ask_product'        => __( 'Tell me a brand or product name and I will look it up — for example "Shinko 705" or "R&G sliders".', 'durianbikers-chatbot' ),

			/* translators: %s: what the customer asked about. */
			'products_found'     => __( 'Here is what I found for "%s":', 'durianbikers-chatbot' ),
			/* translators: %s: what the customer asked about. */
			'products_none'      => __( 'I could not find anything matching "%s" in our catalogue. That does not always mean we do not have it — my matching is literal, so try the brand name or the exact model.', 'durianbikers-chatbot' ),
			'prices_found'       => __( 'Here are the prices I have:', 'durianbikers-chatbot' ),
			'stock_found'        => __( 'Here is what our catalogue says right now:', 'durianbikers-chatbot' ),
			'cheapest_found'     => __( 'These are the lowest prices I can see:', 'durianbikers-chatbot' ),
			/* translators: %s: price ceiling, e.g. "RM 500". */
			'under_found'        => __( 'Here is what I found under %s:', 'durianbikers-chatbot' ),
			/* translators: 1: price ceiling, e.g. "RM 500"; 2: what the customer asked about. */
			'under_none'         => __( 'I could not find anything under %1$s for "%2$s".', 'durianbikers-chatbot' ),

			/* translators: 1: product name; 2: price. */
			'item_price'         => __( '%1$s — %2$s', 'durianbikers-chatbot' ),
			/* translators: 1: product name; 2: price. */
			'item_price_stock'   => __( '%1$s — %2$s, in stock', 'durianbikers-chatbot' ),
			/* translators: 1: product name; 2: price. */
			'item_price_nostock' => __( '%1$s — %2$s, out of stock', 'durianbikers-chatbot' ),
			/* translators: %s: product name. */
			'item_in_stock'      => __( '%s — in stock', 'durianbikers-chatbot' ),
			/* translators: %s: product name. */
			'item_out_of_stock'  => __( '%s — out of stock', 'durianbikers-chatbot' ),
			/* translators: %s: product name. */
			'item_unavailable'   => __( '%s — listed, but currently unavailable to order, so I have no price for it', 'durianbikers-chatbot' ),
			/* translators: %s: product name. */
			'item_plain'         => __( '%s', 'durianbikers-chatbot' ),


			/*
			 * COMPARISON. Two short labelled blocks, never a table: the widget
			 * renders replies as plain text and a 375px phone holds about 36
			 * characters on a line, so anything column-aligned falls apart.
			 * Every label here is short enough to leave room for its value.
			 *
			 * NOT ONE OF THESE RANKS. There is no "better", no "best", no
			 * "we recommend", and no template that puts the two sides in any
			 * relation to each other at all — each block restates one listing
			 * and stops. compare_no_advice says so out loud, on every reply
			 * that shows a product, because two blocks one after the other
			 * read as an ordering whether or not one is intended.
			 */
			'compare_intro'      => __( 'Side by side, from what our catalogue lists:', 'durianbikers-chatbot' ),
			'compare_same'       => __( 'Both of those come back as the same product, so there is only one to show:', 'durianbikers-chatbot' ),
			'compare_extra'      => __( 'You named more than two things — I only compare two, so I have taken the first two.', 'durianbikers-chatbot' ),

			/* translators: %s: price, or "not listed". */
			'compare_price'      => __( 'Price: %s', 'durianbikers-chatbot' ),
			/* translators: %s: stock wording. */
			'compare_stock'      => __( 'Stock: %s', 'durianbikers-chatbot' ),
			/* translators: %s: brand name, or "not listed". */
			'compare_brand'      => __( 'Brand: %s', 'durianbikers-chatbot' ),
			/* translators: %s: category name, or "not listed". */
			'compare_category'   => __( 'Category: %s', 'durianbikers-chatbot' ),

			'compare_unknown'    => __( 'not listed', 'durianbikers-chatbot' ),
			'compare_in_stock'   => __( 'in stock', 'durianbikers-chatbot' ),
			'compare_out_stock'  => __( 'out of stock', 'durianbikers-chatbot' ),
			'compare_unavail'    => __( 'listed, not orderable now', 'durianbikers-chatbot' ),
			'compare_no_price'   => __( 'none I can quote', 'durianbikers-chatbot' ),

			/* translators: %s: what was searched for. */
			'compare_missing'    => __( 'I could not find anything for "%s".', 'durianbikers-chatbot' ),
			/* translators: 1: first thing searched for; 2: second thing searched for. */
			'compare_none'       => __( 'I could not find "%1$s" or "%2$s" in our catalogue. My matching is literal, so try the exact brand or model name.', 'durianbikers-chatbot' ),

			'compare_no_advice'  => __( 'Those are the facts our listings carry. I cannot judge which one suits you — I match words, I have no fitment data for your bike, and the wrong part is a safety problem.', 'durianbikers-chatbot' ),
			'compare_no_specs'   => __( 'I have no specifications, weights or dimensions to compare — our listings do not record them.', 'durianbikers-chatbot' ),

			'note_no_price'      => __( 'Some listings have no price I can read, so they are not in that comparison.', 'durianbikers-chatbot' ),
			'note_size'          => __( "I cannot check sizes — sizes and their stock live on each product's own page, so open the product to see what is available.", 'durianbikers-chatbot' ),
			'note_colour'        => __( 'I cannot confirm colour options; I only matched the colour word against the product name.', 'durianbikers-chatbot' ),
			'note_fitment'       => __( 'I cannot confirm what fits your bike. Our listings carry no fitment data, and the wrong part is a safety problem — please check with a person before ordering.', 'durianbikers-chatbot' ),
			'note_more'          => __( 'There are more matches than I can show here — the shop search will list them all.', 'durianbikers-chatbot' ),
			'buy_which_product'  => __( 'Which one would you like?', 'durianbikers-chatbot' ),
			'buy_which_size'     => __( 'Which size? These are in stock now:', 'durianbikers-chatbot' ),
			/* translators: 1: product name; 2: option label; 3: quantity; 4: price. */
			'buy_confirm'        => __( '%1$s — %2$s, %3$d of them, %4$s each. Add that to your cart?', 'durianbikers-chatbot' ),
			/* translators: 1: product name; 2: quantity; 3: price. */
			'buy_confirm_simple' => __( '%1$s, %2$d of them, %3$s each. Add that to your cart?', 'durianbikers-chatbot' ),
			/* translators: %s: the only available option. */
			'buy_only_option'    => __( 'That one is only available in %s right now.', 'durianbikers-chatbot' ),
			'buy_cancelled'      => __( 'No problem, I have not added anything.', 'durianbikers-chatbot' ),
			/* translators: 1: quantity; 2: product name; 3: cart total. */
			'buy_added'          => __( 'Added %1$d × %2$s to your cart. Your cart is now %3$s.', 'durianbikers-chatbot' ),
			/* translators: %s: the reason, already customer-safe. */
			'buy_failed'         => __( 'I could not add that to your cart: %s', 'durianbikers-chatbot' ),
			'note_purchase'      => __( 'I cannot place an order here — open the product page to choose your options and add it to your cart.', 'durianbikers-chatbot' ),
			/* translators: %d: number of items the customer asked for. */
			'note_purchase_qty'  => __( 'I cannot place an order here. You mentioned %d — open the product page to choose your options and add them to your cart.', 'durianbikers-chatbot' ),

			/* translators: %s: page title. */
			'policy_source'      => __( 'From our %s page.', 'durianbikers-chatbot' ),
			/* translators: %s: comma-separated page titles. */
			'policy_no_match'    => __( 'I could not find that in our help pages, and I would rather not guess. The page to read is %s.', 'durianbikers-chatbot' ),
			'policy_unavailable' => __( 'I cannot read our help pages right now, so I cannot answer that.', 'durianbikers-chatbot' ),
			/* translators: %s: opening hours summary. */
			'contact_hours'      => __( 'Opening hours: %s.', 'durianbikers-chatbot' ),

			'handoff_hint'       => __( 'For anything I cannot answer, use the WhatsApp button below and a person will reply.', 'durianbikers-chatbot' ),
			'handoff_none'       => __( 'For anything I cannot answer, please contact the shop directly.', 'durianbikers-chatbot' ),
		);

		$ms = array(
			'greeting'           => 'Hai! Saya pembantu kedai Durian Bikers. Saya automatik — bukan manusia, dan bukan AI yang faham bahasa. Saya cuma mencari maklumat: produk, harga, stok, dan apa yang tertulis di halaman penghantaran, pemulangan, jaminan dan bayaran kami. Apa yang anda cari?',
			'identity'           => 'Saya bukan manusia — saya pembantu automatik di laman web kedai. Saya juga tidak faham bahasa: saya cuma padankan apa yang anda taip dengan katalog produk dan halaman bantuan kami, kemudian tunjukkan apa yang saya jumpa.',
			'order'              => 'Saya tidak dapat menyemak pesanan sekarang — sistem semakan pesanan tidak memberi respons. Sila jangan hantar apa-apa maklumat peribadi kepada saya; seseorang boleh menyemaknya untuk anda.',
			'order_signed_out'   => 'Saya hanya boleh menyemak pesanan untuk pelanggan yang sudah log masuk, kerana saya membacanya daripada akaun anda sahaja. Sila log masuk di laman web kami dan tanya saya semula. Saya tidak akan meminta nombor pesanan, alamat emel atau nombor telefon anda — saya tidak boleh mencari pesanan dengan cara itu, dan anda tidak sepatutnya menaip maklumat peribadi dalam tetingkap sembang.',
			'order_none'         => 'Saya tidak nampak sebarang pesanan pada akaun yang anda log masuk ini. Jika anda pasti telah membuat pesanan, mungkin ia dibuat sebagai tetamu atau menggunakan akaun lain.',
			'order_found'        => 'Ini yang tercatat pada akaun anda:',

			'order_line'         => '#%1$s — %2$s',
			'order_placed'       => 'Dibuat %s',
			'order_total'        => 'Jumlah %s',
			'order_items'        => 'Barang: %s',
			'order_item'         => '%1$s x%2$d',
			'order_tracking'     => 'Tracking: %s',
			'order_tracking_url' => 'Jejak di sini: %s',

			'order_no_eta'       => 'Saya hanya mengulang apa yang tercatat pada rekod pesanan — saya tidak boleh menentukan tarikh sampai.',
			'order_readonly'     => 'Saya hanya boleh membaca pesanan. Saya tidak boleh membatalkan, menukar, memulangkan atau mengejar penghantaran.',
			'order_more'         => 'Itu pesanan terkini anda; halaman akaun anda menyenaraikan semuanya.',

			'subject_carried'    => 'Saya anggap ini masih tentang "%s".',
			'subject_gone'       => 'Awal tadi anda tanya tentang "%s", tetapi saya tidak jumpa lagi dalam katalog kami — jadi daripada menjawab tentang benda lain, lebih baik saya berhenti di sini. Sila beritahu semula nama jenama atau produk itu.',
			'fuzzy_corrected'    => 'Saya tidak jumpa apa yang anda taip, jadi saya tunjukkan hasil untuk "%s" sebagai gantinya.',
			'fallback'           => 'Maaf — saya tidak dapat memahami soalan itu. Saya cuma carian mudah, jadi saya hanya kenal perkataan tertentu. Saya boleh semak sama ada kami ada sesuatu, harganya, ada stok atau tidak, dan apa yang tertulis di halaman penghantaran, pemulangan, jaminan dan bayaran kami. Cuba taip nama jenama atau nama produk sahaja.',
			'ask_product'        => 'Beritahu saya nama jenama atau nama produk dan saya akan carikan — contohnya "Shinko 705" atau "R&G sliders".',

			'products_found'     => 'Ini yang saya jumpa untuk "%s":',
			'products_none'      => 'Saya tidak jumpa apa-apa yang sepadan dengan "%s" dalam katalog kami. Itu tidak semestinya bermakna kami tiada — padanan saya literal, jadi cuba nama jenama atau model yang tepat.',
			'prices_found'       => 'Ini harga yang saya ada:',
			'stock_found'        => 'Ini yang tercatat dalam katalog kami sekarang:',
			'cheapest_found'     => 'Ini harga paling rendah yang saya nampak:',
			'under_found'        => 'Ini yang saya jumpa bawah %s:',
			'under_none'         => 'Saya tidak jumpa apa-apa bawah %1$s untuk "%2$s".',

			'item_price'         => '%1$s — %2$s',
			'item_price_stock'   => '%1$s — %2$s, ada stok',
			'item_price_nostock' => '%1$s — %2$s, stok habis',
			'item_in_stock'      => '%s — ada stok',
			'item_out_of_stock'  => '%s — stok habis',
			'item_unavailable'   => '%s — tersenarai, tetapi tidak boleh dipesan buat masa ini, jadi saya tiada harga untuknya',
			'item_plain'         => '%s',


			'compare_intro'      => 'Perbandingan sebelah-menyebelah, ikut apa yang tersenarai dalam katalog kami:',
			'compare_same'       => 'Kedua-duanya membawa kepada produk yang sama, jadi hanya satu yang saya papar:',
			'compare_extra'      => 'Anda sebut lebih daripada dua benda — saya hanya banding dua, jadi saya ambil dua yang pertama.',

			'compare_price'      => 'Harga: %s',
			'compare_stock'      => 'Stok: %s',
			'compare_brand'      => 'Jenama: %s',
			'compare_category'   => 'Kategori: %s',

			'compare_unknown'    => 'tiada dalam senarai',
			'compare_in_stock'   => 'ada stok',
			'compare_out_stock'  => 'stok habis',
			'compare_unavail'    => 'tersenarai, tak boleh dipesan',
			'compare_no_price'   => 'tiada yang boleh saya beri',

			'compare_missing'    => 'Saya tidak jumpa apa-apa untuk "%s".',
			'compare_none'       => 'Saya tidak jumpa "%1$s" atau "%2$s" dalam katalog kami. Padanan saya literal, jadi cuba nama jenama atau model yang tepat.',

			'compare_no_advice'  => 'Itu maklumat yang ada pada senarai kami. Saya tidak boleh menilai yang mana sesuai untuk anda — saya cuma padankan perkataan, saya tiada data kesesuaian untuk motosikal anda, dan bahagian yang salah adalah masalah keselamatan.',
			'compare_no_specs'   => 'Saya tiada spesifikasi, berat atau ukuran untuk dibandingkan — senarai kami tidak menyimpannya.',

			'note_no_price'      => 'Ada senarai yang tiada harga yang saya boleh baca, jadi ia tidak termasuk dalam perbandingan itu.',
			'note_size'          => 'Saya tidak boleh menyemak saiz — saiz dan stoknya ada di halaman produk itu sendiri, jadi sila buka produk tersebut.',
			'note_colour'        => 'Saya tidak boleh sahkan pilihan warna; saya cuma padankan perkataan warna dengan nama produk.',
			'note_fitment'       => 'Saya tidak boleh sahkan apa yang muat pada motosikal anda. Senarai kami tiada data kesesuaian, dan bahagian yang salah adalah masalah keselamatan — sila semak dengan seseorang sebelum membeli.',
			'note_more'          => 'Ada lebih banyak padanan daripada yang boleh saya papar di sini — carian kedai akan menyenaraikan semuanya.',
			'buy_which_product'  => 'Yang mana satu anda mahu?',
			'buy_which_size'     => 'Saiz apa? Yang ada stok sekarang:',
			/* translators: 1: product name; 2: option label; 3: quantity; 4: price. */
			'buy_confirm'        => '%1$s — %2$s, %3$d unit, %4$s seunit. Masukkan ke dalam troli?',
			/* translators: 1: product name; 2: quantity; 3: price. */
			'buy_confirm_simple' => '%1$s, %2$d unit, %3$s seunit. Masukkan ke dalam troli?',
			/* translators: %s: the only available option. */
			'buy_only_option'    => 'Yang itu hanya ada dalam saiz %s buat masa ini.',
			'buy_cancelled'      => 'Baik, saya tidak masukkan apa-apa.',
			/* translators: 1: quantity; 2: product name; 3: cart total. */
			'buy_added'          => 'Sudah masukkan %1$d × %2$s ke dalam troli anda. Jumlah troli sekarang %3$s.',
			/* translators: %s: the reason, already customer-safe. */
			'buy_failed'         => 'Saya tidak dapat masukkan ke dalam troli: %s',
			'note_purchase'      => 'Saya tidak boleh membuat pesanan di sini — sila buka halaman produk untuk pilih pilihan anda dan masukkan ke dalam troli.',
			/* translators: %d: number of items the customer asked for. */
			'note_purchase_qty'  => 'Saya tidak boleh membuat pesanan di sini. Anda sebut %d — sila buka halaman produk untuk pilih pilihan anda dan masukkan ke dalam troli.',

			'policy_source'      => 'Daripada halaman %s kami.',
			'policy_no_match'    => 'Saya tidak jumpa perkara itu dalam halaman bantuan kami, dan saya tidak mahu meneka. Halaman yang perlu dibaca ialah %s.',
			'policy_unavailable' => 'Saya tidak dapat membaca halaman bantuan kami sekarang, jadi saya tidak boleh menjawabnya.',
			'contact_hours'      => 'Waktu operasi: %s.',

			'handoff_hint'       => 'Untuk apa-apa yang saya tidak boleh jawab, guna butang WhatsApp di bawah dan seseorang akan membalas.',
			'handoff_none'       => 'Untuk apa-apa yang saya tidak boleh jawab, sila hubungi kedai secara terus.',
		);

		$lang = in_array( $lang, array( 'ms', 'zh' ), true ) ? $lang : 'en';

		/*
		 * Each set is MERGED OVER English, so a language only has to translate
		 * the strings it has. Anything untranslated falls back to English
		 * rather than to a blank bubble.
		 */
		if ( 'ms' === $lang ) {
			$set = array_merge( $en, $ms );
		} elseif ( 'zh' === $lang ) {
			$set = array_merge( $en, self::templates_zh() );
		} else {
			$set = $en;
		}

		/*
		 * HYBRID MODE. The greeting and identity replies say this assistant is
		 * not an AI — true in lookup mode, false once genuine misses go to
		 * Claude. Overlaid here, before the owner's filter, so a configured
		 * override still wins. Chinese keeps the English wording, as it
		 * already does for every string it has not translated.
		 */
		if ( self::hybrid_active() ) {
			$set = array_merge( $set, self::templates_hybrid( $lang ) );
		}

		/**
		 * Filter the lookup mode reply templates.
		 *
		 * Keep the placeholders (%s / %1$s) intact — a template that loses one
		 * silently drops the product name or the page title out of the reply.
		 *
		 * @param array<string,string> $set  Template key => text.
		 * @param string               $lang Detected language, 'en' or 'ms'.
		 */
		$set = (array) apply_filters( 'db_chat_lookup_templates', $set, $lang );

		return $set;
	}

	/**
	 * Is the plugin running in hybrid mode?
	 *
	 * @return bool
	 */
	public static function hybrid_active() {
		return function_exists( 'db_chat' )
			&& defined( 'DB_Chat_Plugin::MODE_HYBRID' )
			&& is_callable( array( db_chat(), 'get_mode' ) )
			&& DB_Chat_Plugin::MODE_HYBRID === db_chat()->get_mode();
	}

	/**
	 * Replacement copy for hybrid mode.
	 *
	 * @param string $lang 'en', 'ms' or 'zh'.
	 * @return array<string,string>
	 */
	public static function templates_hybrid( $lang ) {
		if ( 'ms' === $lang ) {
			return array(
				'greeting' => 'Hai! Saya pembantu kedai Durian Bikers. Saya automatik, bukan manusia. Saya boleh semak produk, harga, stok, dan halaman penghantaran, pemulangan, jaminan dan bayaran kami. Jika saya tidak dapat padankan soalan anda, pembantu AI akan menjawabnya — ia boleh tersilap, jadi sila semak perkara yang penting. Apa yang anda cari?',
				'identity' => 'Saya bukan manusia — saya pembantu automatik di laman web kedai. Saya semak produk dan halaman bantuan kami sendiri, dan jika saya tidak dapat padankan soalan, pembantu AI akan menjawabnya. Ia boleh tersilap, jadi sila semak perkara yang penting.',
			);
		}

		return array(
			'greeting' => __( "Hi! I'm the Durian Bikers shop assistant. I'm automated, not a person. I can look up products, prices, stock, and our shipping, returns, warranty and payment pages. If I can't match your question myself, an AI assistant answers it — it can make mistakes, so please check anything important. What are you after?", 'durianbikers-chatbot' ),
			'identity' => __( "I'm not a person — I'm an automated assistant on the shop's website. I look up our products and help pages myself, and when I can't match a question, an AI assistant answers it. It can make mistakes, so please check anything important.", 'durianbikers-chatbot' ),
		);
	}

	/**
	 * One template, already formatted.
	 *
	 * @param string $lang    Language.
	 * @param string $key     Template key.
	 * @param mixed  ...$args sprintf arguments.
	 * @return string
	 */
	private function text( $lang, $key, ...$args ) {
		$templates = $this->template_cache( $lang );

		if ( ! isset( $templates[ $key ] ) || ! is_scalar( $templates[ $key ] ) ) {
			return '';
		}

		$template = (string) $templates[ $key ];

		if ( empty( $args ) ) {
			return $template;
		}

		/*
		 * A filtered template that dropped a placeholder must not put a PHP
		 * warning in front of a customer; fall back to the raw template.
		 */
		$formatted = @vsprintf( $template, $args ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return is_string( $formatted ) ? $formatted : $template;
	}

	/**
	 * Templates memoized per language, so the filter runs once per request.
	 *
	 * @param string $lang Language.
	 * @return array<string,string>
	 */
	private function template_cache( $lang ) {
		static $cache = array();

		$lang = in_array( $lang, array( 'ms', 'zh' ), true ) ? $lang : 'en';

		if ( ! isset( $cache[ $lang ] ) ) {
			$cache[ $lang ] = self::templates( $lang );
		}

		return $cache[ $lang ];
	}

	/**
	 * The pointer to a human, matched to what is actually configured.
	 *
	 * Says "WhatsApp button" only when DB_Chat_Handoff has a usable number, so
	 * the assistant never sends a customer to a button the widget is hiding.
	 *
	 * @param string $lang Language.
	 * @return string
	 */
	private function handoff_hint( $lang ) {
		$configured = false;

		if ( class_exists( 'DB_Chat_Handoff' ) ) {
			try {
				$configured = (bool) DB_Chat_Handoff::is_configured();
			} catch ( Throwable $e ) {
				$configured = false;
			}
		}

		return $this->text( $lang, $configured ? 'handoff_hint' : 'handoff_none' );
	}

	/*
	 * =======================================================================
	 * ENTRY POINT
	 * =======================================================================
	 */

	/**
	 * Answer one customer message.
	 *
	 * Returns EXACTLY the REST contract the widget already renders — the same
	 * shape the Claude path returns, so nothing downstream needs to know which
	 * brain produced it:
	 *
	 *     array( 'reply' => string, 'products' => array, 'pages' => array )
	 *
	 * Never returns a WP_Error and never returns an empty reply: every path
	 * ends in a template, because a blank bubble reads as a broken shop.
	 *
	 * Makes ZERO network calls.
	 *
	 * HISTORY IS USED, but only as a subject slot, and only ever re-parsed
	 * server-side. See carried_subject(). Nothing the client sends is trusted
	 * as a subject: history is user-controllable input, so it is sanitized here
	 * exactly as the endpoint sanitizes it and then re-read with the same
	 * keyword matching as a live message. There is deliberately no
	 * client-supplied "subject" field to spoof.
	 *
	 * @param string $message Sanitized customer message.
	 * @param array  $history Prior turns, {role, content}. Untrusted.
	 * @return array{reply:string,products:array,pages:array}
	 */
	public function answer( $message, array $history = array(), $pending = null ) {
		// Reset per call: this object is reused across the request.
		$this->order_turn  = false;
		$this->pending_out = null;
		$this->ai_fallback = false;

		/*
		 * A buying conversation in progress takes precedence — but only if the
		 * customer is actually answering. resume_pending() returns null when
		 * they have moved on, and we fall through to a normal reply rather
		 * than trapping them in the sale.
		 */
		$resumed = $this->resume_pending( $message, $pending );

		if ( null !== $resumed ) {
			return $resumed;
		}

		$normalized = $this->normalize( $message );

		if ( '' === $normalized ) {
			// Nothing to learn from a blank message, so it is not logged.
			return $this->compose( $this->text( 'en', 'fallback' ), 'en' );
		}

		$language = self::detect_language( $normalized );
		$signals  = $this->signals( $normalized );
		$intent   = $this->resolve_intent( $normalized, $signals );

		switch ( $intent ) {
			case 'greeting':
				return $this->compose( $this->text( $language, 'greeting' ), $language );

			case 'identity':
				return $this->compose( $this->text( $language, 'identity' ), $language );

			case 'order':
				return $this->answer_orders( $normalized, $signals, $language );

			case 'compare':
				return $this->answer_compare( $normalized, $signals, $language, $history, $message );

			case 'policy':
				return $this->answer_policy( $normalized, $signals, $language );

			case 'contact':
				return $this->answer_contact( $normalized, $language );

			case 'fallback':
				$this->ai_fallback = true;
				$this->log_unmatched( $message, $signals, $language );

				return $this->compose( $this->text( $language, 'fallback' ), $language );

			default:
				return $this->answer_products( $intent, $normalized, $signals, $language, $history, $message );
		}
	}

	/**
	 * Did the last answer() call handle an ORDER question?
	 *
	 * Read by the chat endpoint so its EXISTING order-data suppression covers
	 * this mode too: the reply is marked no-store, and both the question and
	 * the reply are withheld from the conversation log. Reusing that flag was
	 * deliberate — a second suppression mechanism is a second thing to get
	 * wrong.
	 *
	 * True for every order turn, including a guest who was told to sign in and
	 * a customer with no orders, because the QUESTION is the exposure: it
	 * routinely carries an order number even when the answer carries nothing.
	 *
	 * @return bool
	 */
	public function used_order_lookup() {
		return (bool) $this->order_turn;
	}

	/**
	 * Should the hybrid endpoint ask Claude instead?
	 *
	 * @return bool
	 */
	public function needs_ai_fallback() {
		return (bool) $this->ai_fallback;
	}

	/**
	 * The intent a message resolves to. Public so it can be tested offline.
	 *
	 * @param string $message Raw customer message.
	 * @return string One of: product, price, stock, price_filter, attribute,
	 *                compare, policy, contact, greeting, identity, order,
	 *                fallback.
	 */
	public function detect_intent( $message ) {
		$normalized = $this->normalize( $message );

		if ( '' === $normalized ) {
			return 'fallback';
		}

		return $this->resolve_intent( $normalized, $this->signals( $normalized ) );
	}

	/*
	 * =======================================================================
	 * NORMALISATION AND SIGNALS
	 * =======================================================================
	 */

	/**
	 * Lowercase, de-punctuate, collapse, and fold spelling variants.
	 *
	 * Everything downstream matches against this form, so keyword tables can
	 * stay plain lowercase words.
	 *
	 * @param mixed $value Raw message.
	 * @return string
	 */
	private function normalize( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$text = wp_strip_all_tags( (string) $value, true );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = mb_substr( $text, 0, self::MAX_MESSAGE_CHARS );
		$text = mb_strtolower( $text );

		// Everything that is not a letter or a digit becomes a separator. This
		// also splits "rm500" style input into "rm 500" below.
		$text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );
		$text = preg_replace( '/\brm(\d)/u', 'rm $1', (string) $text );
		$text = preg_replace( '/(\d)\s*(rm)\b/u', '$1 $2', (string) $text );

		$tokens = preg_split( '/\s+/u', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $tokens ) ) {
			return '';
		}

		$variants = $this->variant_cache();
		$folded   = array();

		foreach ( $tokens as $token ) {
			// Split a glued "rm500" that survived the pass above.
			if ( preg_match( '/^rm([0-9].*)$/', $token, $matches ) ) {
				$folded[] = 'rm';
				$token    = $matches[1];
			}

			$folded[] = isset( $variants[ $token ] ) && is_scalar( $variants[ $token ] )
				? (string) $variants[ $token ]
				: $token;
		}

		return trim( implode( ' ', $folded ) );
	}

	/**
	 * Variant map, memoized so the filter runs once per request.
	 *
	 * @return array<string,string>
	 */
	private function variant_cache() {
		static $cache = null;

		if ( null === $cache ) {
			$cache = array();

			foreach ( self::variants() as $from => $to ) {
				if ( is_string( $from ) && is_scalar( $to ) ) {
					$cache[ $from ] = (string) $to;
				}
			}
		}

		return $cache;
	}

	/**
	 * Keyword tables, memoized so the filter runs once per request.
	 *
	 * @return array<string,string[]>
	 */
	private function keyword_cache() {
		static $cache = null;

		if ( null === $cache ) {
			$cache = self::keywords();
		}

		return $cache;
	}

	/**
	 * Which signal groups the message matched, and how strongly.
	 *
	 * A phrase scores its own word count, so "where is my order" (4) beats a
	 * bare "order" (1) and a single common word cannot capture the message.
	 * Matching is on whole phrases with space padding, so "ada" never matches
	 * inside "adapter".
	 *
	 * @param string $normalized Normalized message.
	 * @return array{scores:array<string,int>,phrases:string[]}
	 */
	private function signals( $normalized ) {
		$haystack = ' ' . $normalized . ' ';
		$scores   = array();
		$phrases  = array();

		foreach ( $this->keyword_cache() as $group => $list ) {
			$score = 0;

			foreach ( $list as $phrase ) {
				if ( false === strpos( $haystack, ' ' . $phrase . ' ' ) ) {
					continue;
				}

				$score    += 1 + substr_count( $phrase, ' ' );
				$phrases[] = $phrase;
			}

			if ( $score > 0 ) {
				$scores[ $group ] = $score;
			}
		}

		return array(
			'scores'  => $scores,
			'phrases' => array_values( array_unique( $phrases ) ),
		);
	}

	/**
	 * Decide the intent from the signal scores.
	 *
	 * Two intents are decided BEFORE scoring, deliberately:
	 *
	 *  - orders, because the cost of misreading "where is my order" as a
	 *    product search is a customer who thinks the shop ignored them, and
	 *    because this mode must always decline order questions rather than
	 *    look anything up;
	 *  - "are you a real person", because answering that with a product list
	 *    is exactly the failure that makes an automated assistant feel
	 *    dishonest.
	 *
	 * Everything after that is highest score wins, and a TIE GOES TO PRODUCT
	 * LOOKUP — it is far and away the most common question this shop gets.
	 *
	 * @param string $normalized Normalized message.
	 * @param array  $signals    Signal scores and matched phrases.
	 * @return string
	 */
	private function resolve_intent( $normalized, array $signals ) {
		$scores = isset( $signals['scores'] ) && is_array( $signals['scores'] ) ? $signals['scores'] : array();

		$score = static function ( $group ) use ( $scores ) {
			return isset( $scores[ $group ] ) ? (int) $scores[ $group ] : 0;
		};

		if ( $score( 'order' ) > 0 ) {
			return 'order';
		}

		if ( $score( 'identity' ) > 0 ) {
			return 'identity';
		}

		/*
		 * COMPARISON, decided here for the same reason orders and identity are:
		 * "Shinko 705 vs Shoei NXR2" scores as a plain product lookup and would
		 * silently answer about one of the two, which is worse than either
		 * answering about both or declining.
		 *
		 * It is decided by THE PARSE, not by a keyword score: compare_sides()
		 * only reports a comparison when it can actually produce two nameable
		 * sides. That is the SAME function the answer path runs, so the
		 * resolver can never route a message the answer path then cannot
		 * handle — the two cannot drift because there is only one of them.
		 */
		$compare = $this->compare_sides( $normalized, $signals );

		if ( count( $compare['sides'] ) >= self::COMPARE_MAX_SIDES ) {
			return 'compare';
		}

		/*
		 * What is left once every recognised keyword and stopword is removed.
		 * "hi" leaves nothing, so it is a greeting. "hi, do you have Shinko
		 * 705" leaves "shinko 705", so it is a product question that happens
		 * to start politely.
		 */
		$residual = $this->residual( $normalized, isset( $signals['phrases'] ) ? $signals['phrases'] : array() );

		if ( $score( 'greeting' ) > 0 && '' === $residual ) {
			return 'greeting';
		}

		/*
		 * A price ceiling or a "cheapest" is decided by its own presence rather
		 * than by out-scoring the other groups: "show me the cheapest tyres"
		 * carries a strong product opener ("show me") and would otherwise be
		 * read as a plain lookup and lose the sort. Policy and contact still
		 * pre-empt it below by scoring higher, which is right — "cheapest
		 * shipping" is a shipping question.
		 */
		if ( $this->has_price_filter( $normalized ) ) {
			$topics = self::TOPIC_WEIGHT * (
				$score( 'shipping' ) + $score( 'returns' ) + $score( 'warranty' ) + $score( 'payment' )
				+ $score( 'terms' ) + $score( 'contact' ) + $score( 'hours' ) + $score( 'location' )
			);

			if ( 0 === $topics ) {
				return 'price_filter';
			}
		}

		/*
		 * Product first in this list so that array iteration resolves a tie in
		 * its favour: the loop below only replaces the leader on a strictly
		 * higher score.
		 */
		$candidates = array(
			'product'      => $score( 'product' ),
			'price'        => $score( 'price' ),
			'stock'        => $score( 'stock' ),
			'price_filter' => $score( 'price_filter' ),
			'attribute'    => $score( 'attribute' ),
			'policy'       => self::TOPIC_WEIGHT * ( $score( 'shipping' ) + $score( 'returns' ) + $score( 'warranty' ) + $score( 'payment' ) + $score( 'terms' ) ),
			'contact'      => self::TOPIC_WEIGHT * ( $score( 'contact' ) + $score( 'hours' ) + $score( 'location' ) ),
		);

		$intent = 'product';
		$best   = 0;

		foreach ( $candidates as $candidate => $value ) {
			if ( $value > $best ) {
				$best   = $value;
				$intent = $candidate;
			}
		}

		if ( $best > 0 ) {
			return $intent;
		}

		/*
		 * Nothing was recognised at all. If there is still a real word left
		 * over it is most likely a bare product or brand name ("shinko 705"),
		 * which is a completely normal way to ask this shop a question — so it
		 * goes to the catalogue. If that search finds nothing, the product
		 * path emits the fallback wording rather than a "not found", because
		 * with no signal at all we never actually understood the question.
		 */
		return '' !== $residual ? 'product' : 'fallback';
	}

	/**
	 * The message with every matched keyword phrase and stopword removed.
	 *
	 * @param string   $normalized Normalized message.
	 * @param string[] $phrases    Matched keyword phrases.
	 * @return string
	 */
	private function residual( $normalized, $phrases ) {
		$text = ' ' . $normalized . ' ';

		$phrases = is_array( $phrases ) ? $phrases : array();

		// Longest first, so "do you have" is removed before "have".
		usort(
			$phrases,
			static function ( $a, $b ) {
				return strlen( (string) $b ) <=> strlen( (string) $a );
			}
		);

		foreach ( $phrases as $phrase ) {
			$needle = ' ' . (string) $phrase . ' ';

			// Re-padded each time so two adjacent phrases both match.
			while ( false !== strpos( $text, $needle ) ) {
				$text = str_replace( $needle, ' ', $text );
			}
		}

		$tokens = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $tokens ) ) {
			return '';
		}

		$stopwords = $this->stopword_cache();
		$kept      = array();

		foreach ( $tokens as $token ) {
			if ( isset( $stopwords[ $token ] ) || 'rm' === $token ) {
				continue;
			}

			// Single characters are noise on their own.
			if ( mb_strlen( $token ) < 2 ) {
				continue;
			}

			$kept[] = $token;
		}

		return trim( implode( ' ', $kept ) );
	}

	/**
	 * Stopwords as a lookup map, memoized.
	 *
	 * @return array<string,bool>
	 */
	private function stopword_cache() {
		static $cache = null;

		if ( null === $cache ) {
			$cache = array();

			foreach ( self::stopwords() as $word ) {
				$word = trim( (string) $word );

				if ( '' !== $word ) {
					$cache[ $word ] = true;
				}
			}
		}

		return $cache;
	}

	/**
	 * English or Malay?
	 *
	 * One unambiguous Malay marker is enough: the markers are curated so none
	 * of them is also an English word, and a Malaysian customer who mixes the
	 * two ("ada Shinko 705 in stock?") is better served a Malay reply than an
	 * English one.
	 *
	 * PUBLIC STATIC on purpose: the guided product finder needs the same answer
	 * for the same message, and two language detectors that can drift apart is
	 * a bug waiting to happen. Expects the NORMALISED form — lowercased, with
	 * punctuation collapsed to single spaces. A raw string is defended against
	 * below but is not variant-folded, so raw "brape hrga" reads as English
	 * where the engine's own normalised path reads Malay. Normalise first.
	 *
	 * @param string $normalized Normalized message.
	 * @return string 'en' or 'ms'.
	 */
	public static function detect_language( $normalized ) {
		if ( ! is_scalar( $normalized ) ) {
			return 'en';
		}

		$normalized = (string) $normalized;

		/*
		 * Script beats vocabulary. Han characters are unambiguous in a way no
		 * word list is, so this is checked before the Malay markers — a message
		 * mixing Chinese with a Latin product name is still Chinese.
		 */
		if ( self::is_chinese( $normalized ) ) {
			return 'zh';
		}

		// Defensive only: a caller that passed raw text still gets whole-word
		// matching rather than accidental substring hits.
		if ( preg_match( '/[^a-z0-9 ]/', $normalized ) ) {
			$normalized = trim( (string) preg_replace( '/\s+/u', ' ', (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', mb_strtolower( $normalized ) ) ) );
		}

		$haystack = ' ' . $normalized . ' ';

		foreach ( self::malay_markers() as $marker ) {
			$marker = trim( (string) $marker );

			if ( '' !== $marker && false !== strpos( $haystack, ' ' . $marker . ' ' ) ) {
				return 'ms';
			}
		}

		return 'en';
	}

	/*
	 * =======================================================================
	 * PRODUCT ANSWERS
	 * =======================================================================
	 *
	 * All five product-shaped intents land here — plain lookup, price, stock,
	 * size/colour and price filter. They differ only in what is extracted from
	 * the message and which template introduces the list; the retrieval is one
	 * call to the same DB_Chat_Product_Index::search_products() the Claude path
	 * uses, so the publish/visibility/no-stock-quantity rules are enforced in
	 * exactly one place.
	 */

	/**
	 * Answer a product-shaped question.
	 *
	 * @param string $intent     Resolved intent.
	 * @param string $normalized Normalized message.
	 * @param array  $signals    Signal scores and matched phrases.
	 * @param string $language   Reply language.
	 * @param array  $history    Prior turns, untrusted. Subject slot only.
	 * @param string $raw        The customer's own words, for the unmatched log.
	 * @return array{reply:string,products:array,pages:array}
	 */
	private function answer_products( $intent, $normalized, array $signals, $language, array $history = array(), $raw = '' ) {
		$filters            = $this->blank_filters();
		$filters['fitment'] = ! empty( $signals['scores']['fitment'] );

		/*
		 * FITMENT ANSWERS CARRY NO PRODUCT LIST.
		 *
		 * "Will this fit my Yamaha R15?" used to run as an ordinary search and
		 * return whatever mentioned an R15 — a headlight surround, a meter
		 * cover, an air filter — with the fitment caveat underneath. The
		 * caveat was correct and the list undermined it: putting four products
		 * in front of someone who asked what fits implies these might, which
		 * is the one thing this mode cannot know. The customer reads the list,
		 * not the small print.
		 *
		 * So a fitment question gets the decline and a route to a human, and
		 * nothing else. This applies even when the question names a product —
		 * "does the Shinko 705 fit my R15" is still a fitment question, and a
		 * confident-looking listing beside "I cannot confirm this" is exactly
		 * the mixed message that sells someone the wrong brake pad.
		 */
		if ( $filters['fitment'] ) {
			return $this->compose(
				$this->text( $language, 'note_fitment' ) . ' ' . $this->handoff_hint( $language ),
				$language
			);
		}

		$query = $this->build_query( $normalized, $signals, $filters );

		// Nothing recognised at all: the intent was inferred, not detected.
		$blind = empty( $signals['scores'] );

		/*
		 * THE SUBJECT SLOT FIRES HERE, AND ONLY HERE.
		 *
		 * "berapa harga" is a price question that named nothing, so build_query
		 * returns ''. That empty query is the ONLY trigger: if the customer
		 * named anything of their own — a brand, a category, a model, a leftover
		 * word — it wins outright and history is never consulted. That is the
		 * "no extractable subject of its own" half of the rule, enforced by
		 * construction rather than by a heuristic.
		 *
		 * The "intent requires one" half lives in carried_subject().
		 */
		$carried = '';

		if ( '' === $query ) {
			$carried = $this->carried_subject( $intent, $history );

			if ( '' === $carried ) {
				return $this->compose( $this->text( $language, 'ask_product' ), $language );
			}

			$query = $carried;
		}

		$args = array( 'query' => $query );

		if ( '' !== $filters['brand'] ) {
			$args['brand'] = $filters['brand'];
		}

		if ( '' !== $filters['category'] ) {
			$args['category'] = $filters['category'];
		}

		/*
		 * search_products() already retries once on the longest single token
		 * when a multi-word query returns nothing, and drops the taxonomy
		 * filters as a last resort. That retry is inherited on purpose rather
		 * than duplicated here — one search behaviour, one place to fix it.
		 */
		$records = $this->search_products( $args );

		/*
		 * FUZZY CORRECTION, and note where it sits: AFTER an exact search came
		 * back empty, never before. A query that matched something is never
		 * second-guessed, so the normal path stays exactly as fast and as
		 * literal as it was.
		 *
		 * Never applied to a carried subject. A remembered subject that no
		 * longer resolves must be dropped and declined, not "corrected" into a
		 * different product the customer never mentioned.
		 */
		$corrected = '';

		if ( empty( $records ) && '' === $carried ) {
			$corrected = $this->fuzzy_correct( $query );

			if ( '' !== $corrected ) {
				$args['query'] = $corrected;
				$records       = $this->search_products( $args );

				if ( empty( $records ) ) {
					// The guess bought nothing. Say nothing about it either.
					$corrected = '';
				}
			}
		}

		$dropped_unpriced = false;

		if ( null !== $filters['ceiling'] ) {
			$kept = array();

			foreach ( $records as $record ) {
				$value = $this->price_value( isset( $record['price'] ) ? $record['price'] : '' );

				if ( null === $value ) {
					// No readable price (unavailable import artifact, or a
					// format we cannot parse). It cannot be compared, so it is
					// left out and the customer is told some were.
					$dropped_unpriced = true;
					continue;
				}

				if ( $value <= (float) $filters['ceiling'] ) {
					$kept[] = $record;
				}
			}

			$records = $kept;
		}

		if ( $filters['cheapest'] ) {
			$priced = array();

			foreach ( $records as $record ) {
				$value = $this->price_value( isset( $record['price'] ) ? $record['price'] : '' );

				if ( null === $value ) {
					$dropped_unpriced = true;
					continue;
				}

				$priced[] = array(
					'value'  => $value,
					'record' => $record,
				);
			}

			usort(
				$priced,
				static function ( $a, $b ) {
					return $a['value'] <=> $b['value'];
				}
			);

			$records = array_column( $priced, 'record' );
		}

		if ( empty( $records ) ) {
			/*
			 * The remembered subject no longer resolves to a published product
			 * — unpublished, renamed, or the catalogue moved on. DECLINE. The
			 * one thing that must not happen is answering about something else
			 * while the customer believes we are still discussing their tyre.
			 */
			if ( '' !== $carried ) {
				return $this->compose(
					$this->text( $language, 'subject_gone', $carried ) . ' ' . $this->handoff_hint( $language ),
					$language
				);
			}

			if ( $blind ) {
				// No keyword recognised AND no product found: we genuinely did
				// not understand it, which is exactly the worklist entry the
				// owner wants — and exactly the question hybrid mode hands on.
				$this->ai_fallback = true;
				$this->log_unmatched( $raw, $signals, $language );
			}

			return $this->product_miss( $intent, $query, $filters, $blind, $language );
		}

		/*
		 * A browse question earns a longer list, but only when the range is
		 * actually narrowed to a brand or a category. "What models do you
		 * have" with no brand is a request for the whole catalogue, and
		 * answering that with fifteen arbitrary products helps nobody.
		 */
		$narrowed = ( '' !== $filters['brand'] || '' !== $filters['category'] );
		$cap      = ( $filters['browse'] && $narrowed ) ? self::MAX_LISTED_BROWSE : self::MAX_LISTED;
		$listed   = array_slice( $records, 0, $cap );
		$lines  = array();

		/*
		 * SAY THAT WE GUESSED. Both of these change what the customer is
		 * actually being shown, so both are stated before the results rather
		 * than buried in a footnote — a silent correction or a silently
		 * carried subject is indistinguishable from the shop understanding
		 * them, which is the impression this whole mode must never give.
		 */
		if ( '' !== $corrected ) {
			$lines[] = $this->text( $language, 'fuzzy_corrected', $corrected );
		}

		if ( '' !== $carried ) {
			$lines[] = $this->text( $language, 'subject_carried', $carried );
		}

		$lines[] = $this->product_intro( $intent, $query, $filters, $language );

		foreach ( $listed as $record ) {
			$line = $this->product_line( $record, $intent, $language );

			if ( '' !== $line ) {
				$lines[] = "\xE2\x80\xA2 " . $line;
			}
		}

		$notes = array();

		if ( '' !== $filters['size'] || $filters['size_asked'] ) {
			$notes[] = $this->text( $language, 'note_size' );
		}

		if ( '' !== $filters['colour'] ) {
			$notes[] = $this->text( $language, 'note_colour' );
		}

		if ( $dropped_unpriced ) {
			$notes[] = $this->text( $language, 'note_no_price' );
		}

		if ( count( $records ) > count( $listed ) ) {
			$notes[] = $this->text( $language, 'note_more' );
		}

		/*
		 * Say the thing the customer actually asked for. They said "nak beli";
		 * silently returning a product list answers a question they did not
		 * ask and leaves them waiting for a cart that is never coming.
		 */
		$is_purchase = $filters['purchase'] || ( $filters['purchase_soft'] && ! empty( $records ) );

		if ( $is_purchase ) {
			$quantity = max( 1, (int) $filters['quantity'] );
			$offered  = array();

			foreach ( $listed as $record ) {
				$id = isset( $record['id'] ) ? absint( $record['id'] ) : 0;

				if ( $id > 0 ) {
					$offered[] = $id;
				}
			}

			if ( 1 === count( $offered ) ) {
				// Nothing to disambiguate — go straight to the size question.
				return $this->ask_for_variation( $offered[0], $quantity, $language );
			}

			if ( count( $offered ) > 1 ) {
				$this->pending_out = DB_Chat_Pending_Order::create(
					DB_Chat_Pending_Order::STAGE_PRODUCT,
					array(
						'quantity' => $quantity,
						'offered'  => $offered,
						'language' => $language,
					)
				)->to_array();

				$notes[] = $this->text( $language, 'buy_which_product' );
			} else {
				// Found nothing buyable; fall back to the old plain note.
				$notes[] = $quantity > 1
					? $this->text( $language, 'note_purchase_qty', $quantity )
					: $this->text( $language, 'note_purchase' );
			}
		}

		if ( $filters['fitment'] ) {
			// Safety, not politeness: a wrong part on a motorcycle is a crash
			// and a return. This mode has no fitment data at all.
			$notes[] = $this->text( $language, 'note_fitment' );
			$notes[] = $this->handoff_hint( $language );
		}

		$reply = implode( "\n", $lines );

		if ( ! empty( $notes ) ) {
			$reply .= "\n\n" . implode( ' ', array_filter( $notes ) );
		}

		$names = array();

		foreach ( $listed as $record ) {
			if ( isset( $record['name'] ) && is_scalar( $record['name'] ) ) {
				$names[] = (string) $record['name'];
			}
		}

		/*
		 * Cards get the SAME cap as the text list. Twelve products written out
		 * above four picture cards reads as a broken widget rather than as a
		 * deliberate limit.
		 */
		return $this->compose( $reply, $language, $this->resolve_cards( $names, $cap ) );
	}

	/**
	 * Nothing matched the catalogue.
	 *
	 * @param string $intent   Resolved intent.
	 * @param string $query    Extracted product query.
	 * @param array  $filters  Extracted filters.
	 * @param bool   $blind    True when no keyword was recognised at all.
	 * @param string $language Reply language.
	 * @return array{reply:string,products:array,pages:array}
	 */
	private function product_miss( $intent, $query, array $filters, $blind, $language ) {
		unset( $intent );

		if ( null !== $filters['ceiling'] ) {
			return $this->compose(
				$this->text( $language, 'under_none', $this->money( $filters['ceiling'] ), $query ) . ' ' . $this->handoff_hint( $language ),
				$language
			);
		}

		/*
		 * No keyword matched AND no product matched, so we never understood the
		 * message in the first place. Saying "we don't stock X" would be a
		 * claim about the catalogue we have not earned — the fallback wording
		 * says plainly that the question was not understood.
		 */
		if ( $blind ) {
			return $this->compose(
				$this->text( $language, 'fallback' ) . ' ' . $this->handoff_hint( $language ),
				$language
			);
		}

		return $this->compose(
			$this->text( $language, 'products_none', $query ) . ' ' . $this->handoff_hint( $language ),
			$language
		);
	}

	/**
	 * The line that introduces a result list.
	 *
	 * @param string $intent   Resolved intent.
	 * @param string $query    Extracted product query.
	 * @param array  $filters  Extracted filters.
	 * @param string $language Reply language.
	 * @return string
	 */
	private function product_intro( $intent, $query, array $filters, $language ) {
		if ( null !== $filters['ceiling'] ) {
			return $this->text( $language, 'under_found', $this->money( $filters['ceiling'] ) );
		}

		if ( $filters['cheapest'] ) {
			return $this->text( $language, 'cheapest_found' );
		}

		if ( 'price' === $intent ) {
			return $this->text( $language, 'prices_found' );
		}

		if ( 'stock' === $intent ) {
			return $this->text( $language, 'stock_found' );
		}

		return $this->text( $language, 'products_found', $query );
	}

	/**
	 * One result line.
	 *
	 * Restates the retrieved record and nothing else. Note what is absent: no
	 * stock quantity (the index never emits one), no price for an unavailable
	 * import artifact, and no claim that we do not carry it.
	 *
	 * @param array  $record   One search_products() record.
	 * @param string $intent   Resolved intent.
	 * @param string $language Reply language.
	 * @return string
	 */
	private function product_line( array $record, $intent, $language ) {
		$name = isset( $record['name'] ) && is_scalar( $record['name'] ) ? trim( (string) $record['name'] ) : '';

		if ( '' === $name ) {
			return '';
		}

		if ( isset( $record['availability'] ) && 'unavailable' === $record['availability'] ) {
			return $this->text( $language, 'item_unavailable', $name );
		}

		$price    = isset( $record['price'] ) && is_scalar( $record['price'] ) ? trim( (string) $record['price'] ) : '';
		$in_stock = isset( $record['stock_status'] ) && 'instock' === $record['stock_status'];

		if ( 'stock' === $intent ) {
			return $this->text( $language, $in_stock ? 'item_in_stock' : 'item_out_of_stock', $name );
		}

		if ( '' === $price ) {
			return $this->text( $language, 'item_plain', $name );
		}

		if ( 'price' === $intent || 'price_filter' === $intent ) {
			return $this->text( $language, $in_stock ? 'item_price_stock' : 'item_price_nostock', $name, $price );
		}

		return $this->text( $language, 'item_price', $name, $price );
	}


	/*
	 * =======================================================================
	 * COMPARISON — COMPARE, NEVER RANK
	 * =======================================================================
	 *
	 * "Shinko 705 vs Shoei NXR2", "beza X dengan Y", "mana lebih baik X atau
	 * Y". Both sides go through the SAME catalogue search everything else
	 * here uses, both come back as product cards, and the reply restates the
	 * handful of facts each listing actually carries.
	 *
	 * THE RULE THIS SECTION EXISTS TO ENFORCE: it must never say which is
	 * better, and never imply it.
	 *
	 *   - No template ranks, and no code composes a sentence at all — the
	 *     reply is assembled from fixed templates, exactly like every other
	 *     answer in this file.
	 *   - Nothing is SORTED. The sides are printed in the order the customer
	 *     named them. Sorting by price would be a ranking with no word for it,
	 *     and would be read as one.
	 *   - Nothing is CALCULATED across the two. There is no "RM 100 cheaper",
	 *     no "in stock, unlike the other one". Each block restates one listing
	 *     and knows nothing about the other.
	 *   - Judgement words from the customer's own message are scrubbed out of
	 *     each side before it is searched or quoted back, so a ranking word
	 *     cannot reach our reply inside an echo. To a customer, a word in our
	 *     bubble is our word.
	 *   - Asked outright ("which is better", "mana lebih baik"), it answers the
	 *     factual half and then declines to advise, and points at a human.
	 *
	 * Why so hard a line: this mode matches keywords. It has no specifications,
	 * no dimensions, no weights, no sale prices and NO FITMENT DATA — see the
	 * catalogue notes at the top of the file. A recommendation from it would be
	 * a guess dressed as advice, and on a brake or a tyre that is a safety and
	 * returns problem. It is the same reasoning as note_fitment, applied to a
	 * question that invites an opinion instead of a fact.
	 *
	 * WHAT IT CAN ACTUALLY COMPARE, and nothing else: name, price (or the
	 * min-max range the index formats for a variable product), in or out of
	 * stock, brand, category. A field the listing does not carry is printed as
	 * "not listed" rather than dropped — an absent line reads as "the same" when
	 * it means "unknown". The ~1,450 zero-variation import artifacts appear as
	 * listed-but-not-orderable with NO price, never as "we don't carry it".
	 *
	 * FORMAT, and why it is not a table: the widget renders replies as plain
	 * text with textContent, and roughly 36 characters fit on a line at 375px.
	 * An ASCII table is unreadable there. So this emits two short labelled
	 * blocks, one field per line, every label short.
	 */

	/**
	 * Answer a side-by-side comparison.
	 *
	 * @param string $normalized Normalized message.
	 * @param array  $signals    Signal scores and matched phrases.
	 * @param string $language   Reply language.
	 * @param array  $history    Prior turns, untrusted. Only used if this turns
	 *                           out not to be a comparison after all.
	 * @param string $raw        The customer's own words, for the unmatched log.
	 * @return array{reply:string,products:array,pages:array}
	 */
	private function answer_compare( $normalized, array $signals, $language, array $history = array(), $raw = '' ) {
		$parsed = $this->compare_sides( $normalized, $signals );

		/*
		 * The resolver routed here using this same function, so this cannot
		 * normally be false. It is checked anyway because a filter on
		 * db_chat_lookup_compare could change the tables between the two calls
		 * within one request, and the failure mode of trusting it would be a
		 * comparison of one thing against an undefined index.
		 */
		if ( count( $parsed['sides'] ) < self::COMPARE_MAX_SIDES ) {
			return $this->answer_products( 'product', $normalized, $signals, $language, $history, $raw );
		}

		/*
		 * ONE SEARCH PER SIDE, each with only its own words. No taxonomy filter
		 * is passed: detect_taxonomy() reads the WHOLE message, so a brand
		 * named on the left would be silently applied to the right and the
		 * customer would be shown two products from one brand.
		 *
		 * The top hit is taken, and no fuzzy correction is attempted. Inside a
		 * comparison a correction is unusually dangerous: "shinkoo vs shinko"
		 * would correct one side into the other and print the same product
		 * twice as though it were two. A named miss is the honest answer.
		 */
		$records = array();

		foreach ( $parsed['sides'] as $side ) {
			$found = $this->search_products( array( 'query' => $side ) );

			$records[] = ( ! empty( $found ) && isset( $found[0] ) && is_array( $found[0] ) ) ? $found[0] : null;
		}

		$lines = array();

		if ( $parsed['extra'] ) {
			$lines[] = $this->text( $language, 'compare_extra' );
		}

		/* NEITHER SIDE RESOLVED. Decline, name both searches, offer a human. */
		if ( null === $records[0] && null === $records[1] ) {
			/*
			 * LOGGED, unlike an ordinary product miss.
			 *
			 * The standing rule in log_unmatched() is that a product miss is a
			 * CATALOGUE gap, not a keyword gap, so it is not worklist material.
			 * A comparison that resolves NEITHER side is different in kind: the
			 * most likely cause is that the split itself was wrong — a
			 * separator or marker this table does not know — and that IS a
			 * keyword gap the owner can close by adding a word. Every privacy
			 * guard still applies, because this calls the same logger.
			 */
			$this->ai_fallback = true;
			$this->log_unmatched( $raw, $signals, $language );

			$lines[] = $this->text( $language, 'compare_none', $parsed['sides'][0], $parsed['sides'][1] );
			$lines[] = $this->handoff_hint( $language );

			return $this->compose( implode( "\n", array_filter( $lines ) ), $language );
		}

		$same = $this->compare_same_product( $records[0], $records[1] );

		$lines[] = $this->text( $language, $same ? 'compare_same' : 'compare_intro' );

		$names = array();

		foreach ( $records as $index => $record ) {
			if ( $same && $index > 0 ) {
				// Both sides are one product. Say so once; do not print it
				// twice as though the customer had found two things.
				break;
			}

			$lines[] = '';

			if ( null === $record ) {
				// NAME WHAT WAS SEARCHED FOR. Silently showing one product
				// against nothing reads as a comparison the customer never got.
				$lines[] = $this->text( $language, 'compare_missing', $parsed['sides'][ $index ] );

				continue;
			}

			$block = $this->compare_block( $record, $language );

			if ( '' !== $block ) {
				$lines[] = $block;
			}

			if ( isset( $record['name'] ) && is_scalar( $record['name'] ) ) {
				$names[] = (string) $record['name'];
			}
		}

		/*
		 * THE DECLINE TO ADVISE IS UNCONDITIONAL on any reply that shows a
		 * product. Not only when asked "which is better" — two blocks one after
		 * the other read as an ordering on their own, and a guard that only
		 * fires on recognised phrasing is a guard that a customer can walk
		 * around by rephrasing. Being asked outright only adds the pointer to a
		 * human, because that question deserves an actual answer path.
		 */
		$notes = array( $this->text( $language, 'compare_no_advice' ) );

		if ( ! $same && null !== $records[0] && null !== $records[1] ) {
			// Two listings shown: state the missing axes. An absent row reads
			// as "identical" when it means "we hold no such data".
			$notes[] = $this->text( $language, 'compare_no_specs' );
		}

		if ( $parsed['asked'] || in_array( null, $records, true ) ) {
			$notes[] = $this->handoff_hint( $language );
		}

		if ( preg_match( '/\b(?:size|sizes|saiz|ukuran)\b/u', $normalized ) ) {
			// Size options live on variations, which this mode never reads.
			$notes[] = $this->text( $language, 'note_size' );
		}

		if ( ! empty( $signals['scores']['fitment'] ) ) {
			// Safety, not politeness — identical to the product path.
			$notes[] = $this->text( $language, 'note_fitment' );
			$notes[] = $this->handoff_hint( $language );
		}

		$notes = array_values( array_unique( array_filter( $notes ) ) );

		$reply = implode( "\n", $lines );

		if ( ! empty( $notes ) ) {
			$reply .= "\n\n" . implode( ' ', $notes );
		}

		return $this->compose( $reply, $language, $this->resolve_cards( $names ) );
	}

	/**
	 * Split a message into the two things being compared.
	 *
	 * THE SINGLE SOURCE OF TRUTH for "is this a comparison, and of what". Both
	 * resolve_intent() and answer_compare() call THIS function rather than each
	 * deciding for itself — the recurring bug on this plugin has been two paths
	 * that agreed on the day they were written and quietly diverged later, and
	 * a detector that disagrees with the parser would route a message to an
	 * answer path that cannot handle it.
	 *
	 * Returns two sides or none. Never one: a message with only one nameable
	 * side is an ordinary product question and is left to the product path,
	 * which does it better.
	 *
	 * @param string $normalized Normalized message.
	 * @param array  $signals    Signal scores and matched phrases.
	 * @return array{sides:string[],extra:bool,asked:bool}
	 */
	private function compare_sides( $normalized, array $signals ) {
		$none  = array(
			'sides' => array(),
			'extra' => false,
			'asked' => false,
		);
		$terms = $this->compare_cache();
		$text  = ' ' . (string) $normalized . ' ';

		/*
		 * "which is better" / "mana lebih baik" is noted AND removed. Noted so
		 * the reply can decline to advise explicitly; removed so it cannot end
		 * up inside a side and be quoted back with a ranking word in it.
		 */
		$asked = false;

		foreach ( $terms['advice'] as $phrase ) {
			$needle = ' ' . $phrase . ' ';

			while ( false !== strpos( $text, $needle ) ) {
				$text  = str_replace( $needle, ' ', $text );
				$asked = true;
			}
		}

		$marked = $asked;

		foreach ( $terms['markers'] as $phrase ) {
			$needle = ' ' . $phrase . ' ';

			while ( false !== strpos( $text, $needle ) ) {
				$text   = str_replace( $needle, ' ', $text );
				$marked = true;
			}
		}

		/*
		 * WHICH SEPARATORS ARE LIVE, and this is the whole safety of the loose
		 * triggers. "vs" always splits. "or"/"atau" split only when the message
		 * shows no policy, contact, hours, location, order or identity signal
		 * — "do you ship to Sabah or Sarawak" is a shipping question. "and" /
		 * "dan" / "dengan" split only alongside an explicit marker, which is
		 * exactly the "compare X and Y" shape; on their own they are far too
		 * common to be evidence of anything.
		 */
		$separators = $terms['strong'];

		if ( $marked ) {
			$separators = array_merge( $separators, $terms['weak'], $terms['joined'] );
		} elseif ( ! $this->compare_topic_signal( $signals ) ) {
			$separators = array_merge( $separators, $terms['weak'] );
		}

		$separators = array_values( array_unique( array_filter( $separators ) ) );

		if ( empty( $separators ) ) {
			return $none;
		}

		$pattern = '/\s(?:' . implode(
			'|',
			array_map(
				static function ( $separator ) {
					return preg_quote( (string) $separator, '/' );
				},
				$separators
			)
		) . ')\s/u';

		$parts = preg_split( $pattern, $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $parts ) || count( $parts ) < self::COMPARE_MAX_SIDES ) {
			return $none;
		}

		$sides = array();

		foreach ( array_slice( $parts, 0, self::COMPARE_MAX_SIDES ) as $part ) {
			$side = $this->compare_side_query( $part, $signals );

			if ( '' === $side ) {
				/*
				 * A side with nothing nameable left in it was never a side.
				 * "do you ship to Sabah or Sarawak" empties both (they are
				 * shipping keywords); "red or black" does not, and is declined
				 * later by the catalogue rather than answered wrongly here.
				 */
				return $none;
			}

			$sides[] = $side;
		}

		return array(
			'sides' => $sides,
			'extra' => count( $parts ) > self::COMPARE_MAX_SIDES,
			'asked' => $asked,
		);
	}

	/**
	 * Reduce one side of a comparison to a catalogue query.
	 *
	 * The returned string is used for BOTH the search and the echo back to the
	 * customer. There is deliberately no second, prettier version of it.
	 *
	 * @param string $part    One raw side.
	 * @param array  $signals Signal scores and matched phrases.
	 * @return string
	 */
	private function compare_side_query( $part, array $signals ) {
		$query = $this->residual( trim( (string) $part ), isset( $signals['phrases'] ) ? $signals['phrases'] : array() );

		if ( '' === $query ) {
			return '';
		}

		/*
		 * SCRUB JUDGEMENT WORDS. Nothing in this catalogue is called "better"
		 * or "terbaik", so removing them costs no real query — and it is what
		 * stops "which is the best helmet vs Shoei" coming back as: I could not
		 * find anything for "best helmet". That sentence contains our own
		 * ranking word in our own bubble, which is exactly what this intent may
		 * never do, even when the customer supplied the word.
		 */
		$terms = $this->compare_cache();
		$kept  = array();

		foreach ( preg_split( '/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
			if ( ! in_array( $token, $terms['ranking'], true ) ) {
				$kept[] = $token;
			}
		}

		$query = trim( implode( ' ', $kept ) );

		if ( mb_strlen( $query ) > self::COMPARE_MAX_SIDE_CHARS ) {
			$query = mb_substr( $query, 0, self::COMPARE_MAX_SIDE_CHARS );
			$cut   = mb_strrpos( $query, ' ' );

			// Cut on a word boundary so the echo does not read as a glitch.
			if ( false !== $cut && $cut > 0 ) {
				$query = mb_substr( $query, 0, $cut );
			}
		}

		return trim( $query );
	}

	/**
	 * Does the message carry a signal that outranks a bare "or"?
	 *
	 * @param array $signals Signal scores and matched phrases.
	 * @return bool
	 */
	private function compare_topic_signal( array $signals ) {
		$scores = isset( $signals['scores'] ) && is_array( $signals['scores'] ) ? $signals['scores'] : array();

		$groups = array(
			'shipping', 'returns', 'warranty', 'payment', 'terms',
			'contact', 'hours', 'location', 'order', 'identity',
		);

		foreach ( $groups as $group ) {
			if ( ! empty( $scores[ $group ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Comparison tables, memoized so the filter runs once per request.
	 *
	 * @return array<string,string[]>
	 */
	private function compare_cache() {
		static $cache = null;

		if ( null === $cache ) {
			$cache = self::compare_terms();

			foreach ( array( 'markers', 'strong', 'weak', 'joined', 'advice', 'ranking' ) as $group ) {
				if ( ! isset( $cache[ $group ] ) || ! is_array( $cache[ $group ] ) ) {
					$cache[ $group ] = array();
				}
			}
		}

		return $cache;
	}

	/**
	 * Did both sides resolve to the same product?
	 *
	 * Checked on the permalink first, because two listings can share a name.
	 *
	 * @param array|null $left  First record.
	 * @param array|null $right Second record.
	 * @return bool
	 */
	private function compare_same_product( $left, $right ) {
		if ( ! is_array( $left ) || ! is_array( $right ) ) {
			return false;
		}

		foreach ( array( 'permalink', 'name' ) as $key ) {
			$a = isset( $left[ $key ] ) && is_scalar( $left[ $key ] ) ? trim( mb_strtolower( (string) $left[ $key ] ) ) : '';
			$b = isset( $right[ $key ] ) && is_scalar( $right[ $key ] ) ? trim( mb_strtolower( (string) $right[ $key ] ) ) : '';

			if ( '' !== $a && $a === $b ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * One product's block: name, then one short labelled line per fact.
	 *
	 * Restates the retrieved record and nothing else. Nothing here looks at the
	 * other side, so no line can express a relation between the two.
	 *
	 * @param array  $record   One search_products() record.
	 * @param string $language Reply language.
	 * @return string
	 */
	private function compare_block( array $record, $language ) {
		$name = isset( $record['name'] ) && is_scalar( $record['name'] ) ? trim( (string) $record['name'] ) : '';

		if ( '' === $name ) {
			return '';
		}

		$rows = array( $name );

		if ( isset( $record['availability'] ) && 'unavailable' === $record['availability'] ) {
			/*
			 * A zero-variation import artifact. It has no price and no stock
			 * status at all, and NEITHER may be invented: no price is quoted,
			 * and it is described as listed-but-not-orderable rather than as
			 * something we do not carry.
			 */
			$rows[] = $this->text( $language, 'compare_price', $this->text( $language, 'compare_no_price' ) );
			$rows[] = $this->text( $language, 'compare_stock', $this->text( $language, 'compare_unavail' ) );
		} else {
			$price = isset( $record['price'] ) && is_scalar( $record['price'] ) ? trim( (string) $record['price'] ) : '';

			$rows[] = $this->text( $language, 'compare_price', '' !== $price ? $price : $this->text( $language, 'compare_unknown' ) );

			$status = isset( $record['stock_status'] ) && is_scalar( $record['stock_status'] ) ? (string) $record['stock_status'] : '';

			if ( 'instock' === $status ) {
				$stock = $this->text( $language, 'compare_in_stock' );
			} elseif ( 'outofstock' === $status ) {
				$stock = $this->text( $language, 'compare_out_stock' );
			} else {
				// Unknown is said out loud. Defaulting to "out of stock" would
				// be a claim about the catalogue we have not read.
				$stock = $this->text( $language, 'compare_unknown' );
			}

			$rows[] = $this->text( $language, 'compare_stock', $stock );
		}

		$rows[] = $this->text( $language, 'compare_brand', $this->compare_term( $record, 'brands', $language ) );
		$rows[] = $this->text( $language, 'compare_category', $this->compare_term( $record, 'categories', $language ) );

		return implode( "\n", array_filter( $rows ) );
	}

	/**
	 * The first brand or category name on a record, or "not listed".
	 *
	 * FIRST ONLY, deliberately: a product in five categories would wrap over
	 * several lines on a phone and bury the fields that matter. The card
	 * alongside the reply links to the product itself for the full picture.
	 *
	 * @param array  $record   One search_products() record.
	 * @param string $key      'brands' or 'categories'.
	 * @param string $language Reply language.
	 * @return string
	 */
	private function compare_term( array $record, $key, $language ) {
		$list = isset( $record[ $key ] ) && is_array( $record[ $key ] ) ? $record[ $key ] : array();

		foreach ( $list as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$value = trim( (string) $value );

			if ( '' !== $value ) {
				return $value;
			}
		}

		// Said, not omitted: a missing row reads as "the same as the other one".
		return $this->text( $language, 'compare_unknown' );
	}


	/*
	 * =======================================================================
	 * QUERY EXTRACTION
	 * =======================================================================
	 */

	/**
	 * Patterns that express a price ceiling, English and Malay.
	 *
	 * Shared by the intent resolver and the query builder so "under RM 500"
	 * cannot be a price filter in one and invisible to the other.
	 *
	 * @return string[]
	 */
	private static function ceiling_patterns() {
		return array(
			'/\b(?:under|below|less than|cheaper than|no more than|bawah|kurang dari|kurang daripada|tak lebih)\s+(?:rm\s+)?([0-9]+)\b/u',
			'/\brm\s+([0-9]+)\s+(?:ke bawah|kebawah|and below|or less|or below)\b/u',
		);
	}

	/**
	 * Words that mean "sort by cheapest", English and Malay.
	 *
	 * @return string[]
	 */
	private static function cheapest_terms() {
		return array( 'cheapest', 'lowest price', 'termurah', 'paling murah', 'yang murah', 'murah', 'budget', 'bajet' );
	}

	/**
	 * Does the message carry a price ceiling or a "cheapest" request?
	 *
	 * @param string $normalized Normalized message.
	 * @return bool
	 */
	private function has_price_filter( $normalized ) {
		$haystack = ' ' . $normalized . ' ';

		foreach ( self::ceiling_patterns() as $pattern ) {
			if ( preg_match( $pattern, $haystack ) ) {
				return true;
			}
		}

		foreach ( self::cheapest_terms() as $term ) {
			if ( false !== strpos( $haystack, ' ' . $term . ' ' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Turn the message into a catalogue query, pulling out the filters.
	 *
	 * @param string $normalized Normalized message.
	 * @param array  $signals    Signal scores and matched phrases.
	 * @param array  $filters    Filters, by reference.
	 * @return string
	 */
	private function build_query( $normalized, array $signals, array &$filters ) {
		$text    = ' ' . $normalized . ' ';
		$phrases = isset( $signals['phrases'] ) && is_array( $signals['phrases'] ) ? $signals['phrases'] : array();

		/*
		 * PRICE CEILING. Whole ringgit only: normalisation has already turned
		 * "RM 89.90" into "rm 89 90", so a decimal ceiling is not recoverable
		 * and is not worth guessing at. Customers write round numbers here
		 * ("under RM500") and a ceiling of 89 rather than 89.90 errs towards
		 * showing less, which is the safe direction.
		 */
		foreach ( self::ceiling_patterns() as $pattern ) {
			if ( preg_match( $pattern, $text, $matches ) ) {
				$value = (float) $matches[1];

				if ( $value > 0 ) {
					$filters['ceiling'] = $value;
					$text               = str_replace( $matches[0], ' ', $text );
				}

				break;
			}
		}

		// A stray "rm 500" left after a ceiling was read is the same number.
		if ( null !== $filters['ceiling'] ) {
			$text = (string) preg_replace( '/\brm\s+[0-9]+\b/u', ' ', $text );
		}

		/*
		 * QUANTITY. Read before the counting word is removed, and ONLY when a
		 * number is glued to one ("2 pcs", "4 biji", "x2").
		 *
		 * The number must never be taken on its own. Normalisation turns a tyre
		 * size into bare numbers — "shinko 705 120/70R17" becomes
		 * "shinko 705 120 70r17" — so a rule like "the first number is the
		 * quantity" would order 120 tyres. A counting word is the only thing
		 * that distinguishes a quantity from a model or a size.
		 */
		foreach ( self::quantity_units() as $unit ) {
			if ( preg_match( '/\b([0-9]{1,3})\s*' . preg_quote( $unit, '/' ) . '\b/u', $text, $matches ) ) {
				$filters['quantity'] = (int) $matches[1];
				$text                = str_replace( $matches[0], ' ', $text );
				break;
			}
		}

		if ( 0 === $filters['quantity'] && preg_match( '/\b(?:x\s*([0-9]{1,3})|([0-9]{1,3})\s*x)\b/u', $text, $matches ) ) {
			$quantity = (int) ( '' !== $matches[1] ? $matches[1] : $matches[2] );

			if ( $quantity > 0 ) {
				$filters['quantity'] = $quantity;
				$text                = str_replace( $matches[0], ' ', $text );
			}
		}

		/*
		 * PURCHASE INTENT. Removed from the query as well as flagged — these
		 * words are never in a product title, and searching for them costs
		 * results. The reply says plainly that the chat cannot take an order.
		 */
		foreach ( self::purchase_terms() as $term ) {
			if ( false === strpos( $text, ' ' . $term . ' ' ) ) {
				continue;
			}

			$filters['purchase'] = true;

			/*
			 * "buy 2 handguards" — a bare number straight after the verb, with
			 * no counting word to confirm it.
			 *
			 * TWO DIGITS MAXIMUM, and only here. Customers name tyres by model
			 * number ("nak beli 705", "beli 804"), and reading that as a
			 * quantity would both order 705 of something and strip the only
			 * term that finds the product. Three digits are a model; one or two
			 * after a buy verb are a count.
			 */
			if ( 0 === $filters['quantity'] && preg_match( '/\b' . preg_quote( $term, '/' ) . '\s+([0-9]{1,2})\b/u', $text, $matches ) ) {
				$filters['quantity'] = (int) $matches[1];
				$text                = str_replace( $matches[0], ' ' . $term . ' ', $text );
			}

			$text = str_replace( ' ' . $term . ' ', ' ', $text );
		}

		/*
		 * SOFT PURCHASE. Flagged, NOT stripped — these are already stopwords,
		 * and whether they mean "buy" is not knowable yet. The decision waits
		 * until the search has run and we know whether a product was found.
		 */
		if ( ! $filters['purchase'] ) {
			foreach ( self::purchase_soft_terms() as $term ) {
				if ( false !== strpos( $text, ' ' . $term . ' ' ) ) {
					$filters['purchase_soft'] = true;
					break;
				}
			}
		}

		/*
		 * CHINESE. Substring matching, because there are no spaces to split on.
		 * Terms are stripped once matched for the same reason the English buy
		 * words are: they are never part of a product title, and searching for
		 * them costs results.
		 */
		if ( self::is_chinese( $text ) ) {
			$zh_buy = self::chinese_match( $text, 'purchase' );

			if ( '' !== $zh_buy ) {
				$filters['purchase'] = true;
				$text                = str_replace( $zh_buy, ' ', $text );
			}

			if ( 0 === $filters['quantity'] ) {
				foreach ( self::chinese_terms()['unit'] as $unit ) {
					if ( preg_match( '/([0-9]{1,3})\s*' . preg_quote( $unit, '/' ) . '/u', $text, $zh_matches ) ) {
						$filters['quantity'] = (int) $zh_matches[1];
						$text                = str_replace( $zh_matches[0], ' ', $text );
						break;
					}
				}
			}
		}

		/*
		 * BROWSE WORDS. "What models do you have", "ada model apa", "what
		 * series" — these ask to SEE A RANGE, and none of them is ever part of
		 * a product title. Left in the query they are searched for literally,
		 * which is how "mt helmet ada model apa" turned into a hunt for the
		 * word "model" and returned an interior liner and a pinlock insert.
		 */
		foreach ( self::browse_terms() as $term ) {
			if ( false !== strpos( $text, ' ' . $term . ' ' ) ) {
				$filters['browse'] = true;
				$text              = str_replace( ' ' . $term . ' ', ' ', $text );
			}
		}

		/* CHEAPEST. A sort, not a filter — it never hides anything. */
		foreach ( self::cheapest_terms() as $term ) {
			if ( false !== strpos( $text, ' ' . $term . ' ' ) ) {
				$filters['cheapest'] = true;
				$text                = str_replace( ' ' . $term . ' ', ' ', $text );
			}
		}

		/*
		 * SIZE. Removed from the query rather than searched for: sizes live on
		 * variations, not in product titles, so "size m" as a search term
		 * matches noise. The reply says plainly that sizes cannot be checked.
		 */
		if ( preg_match( '/\b(?:size|saiz|ukuran)\s+([a-z0-9]{1,4})\b/u', $text, $matches ) ) {
			$filters['size'] = $matches[1];
			$text            = str_replace( $matches[0], ' ', $text );
		} elseif ( ! empty( $signals['scores']['attribute'] ) ) {
			foreach ( array( 'size', 'sizes', 'what size', 'which size', 'saiz', 'ukuran', 'ada saiz' ) as $term ) {
				if ( false !== strpos( $text, ' ' . $term . ' ' ) ) {
					$filters['size_asked'] = true;
					break;
				}
			}
		}

		/*
		 * COLOUR. Kept in the query, because this catalogue does put colours in
		 * product titles ("Matt Black"). Malay colour words are swapped for
		 * their English equivalent for the same reason — the titles are English.
		 */
		foreach ( $this->colour_cache() as $typed => $search ) {
			if ( false !== strpos( $text, ' ' . $typed . ' ' ) ) {
				$filters['colour'] = $search;
				$text              = str_replace( ' ' . $typed . ' ', ' ' . $search . ' ', $text );
				break;
			}
		}

		/* BRAND / CATEGORY, from the cached taxonomy names. */
		$this->detect_taxonomy( $normalized, $filters );

		$query = $this->residual( trim( (string) $text ), $phrases );

		/*
		 * A brand or category recognised by name is a perfectly good query on
		 * its own — "ada Shinko?" leaves nothing behind once "ada" is stripped.
		 */
		if ( '' === $query ) {
			if ( '' !== $filters['brand'] ) {
				return $filters['brand'];
			}

			if ( '' !== $filters['category'] ) {
				return $filters['category'];
			}
		}

		return $query;
	}

	/**
	 * Colour map, memoized.
	 *
	 * @return array<string,string>
	 */
	private function colour_cache() {
		static $cache = null;

		if ( null === $cache ) {
			$cache = array();

			foreach ( self::colours() as $typed => $search ) {
				if ( is_string( $typed ) && is_scalar( $search ) ) {
					$cache[ $typed ] = (string) $search;
				}
			}
		}

		return $cache;
	}

	/**
	 * Spot a brand or category the customer named.
	 *
	 * Reads the taxonomy names DB_Chat_Product_Index already caches for the
	 * system prompt (transient, 1 hour) — no new query. Longest name wins, so
	 * "Shoei" does not beat "Shoei Helmets".
	 *
	 * @param string $normalized Normalized message.
	 * @param array  $filters    Filters, by reference.
	 * @return void
	 */
	private function detect_taxonomy( $normalized, array &$filters ) {
		try {
			$terms = $this->product_index->get_taxonomy_terms();
		} catch ( Throwable $e ) {
			DB_Chat_Plugin::log_error( 'Lookup mode could not read the taxonomy index: ' . $e->getMessage() );

			return;
		}

		if ( ! is_array( $terms ) ) {
			return;
		}

		$haystack = ' ' . $normalized . ' ';

		foreach ( array( 'brands' => 'brand', 'categories' => 'category' ) as $key => $filter ) {
			if ( empty( $terms[ $key ] ) || ! is_array( $terms[ $key ] ) ) {
				continue;
			}

			$best = '';

			foreach ( $terms[ $key ] as $name ) {
				if ( ! is_scalar( $name ) ) {
					continue;
				}

				$name    = (string) $name;
				$compact = trim( preg_replace( '/[^a-z0-9]+/', ' ', strtolower( $name ) ) );

				/*
				 * Two characters, not three. The old floor silently excluded
				 * exactly one brand — MT — which is a brand this shop actually
				 * stocks, so "mt helmet ada model apa" found no brand at all
				 * and fell back to keyword soup. MT is the ONLY term in the
				 * whole taxonomy shorter than three characters, so this widens
				 * the net by precisely one real brand. Matching stays
				 * whole-token, so "mt" inside another word still cannot hit.
				 */
				if ( mb_strlen( $compact ) < 2 ) {
					continue;
				}

				/*
				 * Customers write "helmet", the category is "Helmets". Try the
				 * singular as well so a plural category name is still found;
				 * without this, "mt helmet" matched the brand but never the
				 * category, and the reply mixed helmets with visors.
				 */
				$forms = array( $compact );

				if ( 's' === substr( $compact, -1 ) ) {
					$forms[] = substr( $compact, 0, -1 );
				}

				foreach ( $forms as $form ) {
					if ( '' !== $form && false !== strpos( $haystack, ' ' . $form . ' ' ) && strlen( $compact ) > strlen( $best ) ) {
						$best = $name;
						break;
					}
				}
			}

			if ( '' !== $best ) {
				$filters[ $filter ] = $best;
			}
		}
	}

	/*
	 * =======================================================================
	 * POLICY AND CONTACT ANSWERS
	 * =======================================================================
	 *
	 * Both read the SAME allowlisted six pages the Claude path reads, through
	 * DB_Chat_Content_Index::search_pages(). No page outside that allowlist is
	 * reachable from here, and the publish / password checks live in that class.
	 */

	/**
	 * Answer a shipping, returns, warranty, payment or terms question.
	 *
	 * The best-matching passage is quoted back and trimmed to something
	 * readable, and the page itself is returned in `pages` so the widget can
	 * render a tappable link to the full policy. The reply names the page title
	 * because that is what DB_Chat_Content_Index::match_in_text() resolves back
	 * to a real, re-verified permalink — the same mechanism the Claude path
	 * uses, so no URL is ever composed here.
	 *
	 * @param string $normalized Normalized message.
	 * @param array  $signals    Signal scores and matched phrases.
	 * @param string $language   Reply language.
	 * @return array{reply:string,products:array,pages:array}
	 */
	private function answer_policy( $normalized, array $signals, $language ) {
		unset( $signals );

		return $this->answer_from_pages( $normalized, $language, array() );
	}

	/**
	 * Answer a contact, hours or location question.
	 *
	 * The query is seeded with contact wording so the Contact Us page outranks
	 * the other allowlisted pages, then the configured opening hours and the
	 * WhatsApp pointer are appended. Opening hours come from DB_Chat_Handoff,
	 * which is the one place that knows them — and only the SUMMARY is used,
	 * never a live "we are open now" claim, so the reply cannot promise that
	 * somebody is standing by.
	 *
	 * @param string $normalized Normalized message.
	 * @param string $language   Reply language.
	 * @return array{reply:string,products:array,pages:array}
	 */
	private function answer_contact( $normalized, $language ) {
		$extra = array();

		$hours = $this->hours_text();

		if ( '' !== $hours ) {
			$extra[] = $this->text( $language, 'contact_hours', $hours );
		}

		$extra[] = $this->handoff_hint( $language );

		return $this->answer_from_pages(
			'contact us address phone email opening hours location hubungi alamat waktu operasi lokasi ' . $normalized,
			$language,
			$extra
		);
	}

	/**
	 * Shared page-answer path.
	 *
	 * @param string   $query    Query handed to the page index.
	 * @param string   $language Reply language.
	 * @param string[] $extra    Lines appended after the passage.
	 * @return array{reply:string,products:array,pages:array}
	 */
	private function answer_from_pages( $query, $language, array $extra ) {
		if ( null === $this->content_index ) {
			return $this->compose(
				$this->text( $language, 'policy_unavailable' ) . ' ' . $this->handoff_hint( $language ),
				$language
			);
		}

		try {
			$found = $this->content_index->search_pages( array( 'query' => $query ) );
		} catch ( Throwable $e ) {
			DB_Chat_Plugin::log_error( 'Lookup mode page search failed: ' . $e->getMessage() );
			$found = array();
		}

		$results = isset( $found['results'] ) && is_array( $found['results'] ) ? $found['results'] : array();
		$notice  = isset( $found['notice'] ) && is_scalar( $found['notice'] ) ? (string) $found['notice'] : '';

		if ( empty( $results ) ) {
			return $this->compose(
				$this->text( $language, 'policy_unavailable' ) . ' ' . $this->handoff_hint( $language ),
				$language
			);
		}

		/*
		 * The page index falls back to the OPENING paragraph of every help page
		 * when nothing actually matched. For a model that is context; quoted
		 * verbatim by this mode it would be a confidently wrong policy answer,
		 * and wrong policy answers turn into refund disputes. So: name the page
		 * and decline. See NO_MATCH_MARKER.
		 */
		if ( false !== strpos( $notice, self::NO_MATCH_MARKER ) ) {
			$titles = array();

			foreach ( $results as $result ) {
				if ( isset( $result['page'] ) && is_scalar( $result['page'] ) ) {
					$titles[ (string) $result['page'] ] = true;
				}

				if ( count( $titles ) >= 2 ) {
					break;
				}
			}

			$reply = $this->text( $language, 'policy_no_match', implode( ', ', array_keys( $titles ) ) )
				. ' ' . $this->handoff_hint( $language );

			return $this->compose( $reply, $language, array(), $this->resolve_pages( $reply ) );
		}

		$best    = $results[0];
		$passage = $this->trim_passage( isset( $best['excerpt'] ) ? (string) $best['excerpt'] : '' );
		$title   = isset( $best['page'] ) && is_scalar( $best['page'] ) ? trim( (string) $best['page'] ) : '';

		if ( '' === $passage ) {
			return $this->compose(
				$this->text( $language, 'policy_unavailable' ) . ' ' . $this->handoff_hint( $language ),
				$language
			);
		}

		$lines = array( $passage );

		if ( '' !== $title ) {
			$lines[] = $this->text( $language, 'policy_source', $title );
		}

		foreach ( $extra as $line ) {
			$line = trim( (string) $line );

			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		$reply = implode( "\n\n", $lines );

		return $this->compose( $reply, $language, array(), $this->resolve_pages( $reply ) );
	}

	/**
	 * The configured opening hours summary, or ''.
	 *
	 * @return string
	 */
	private function hours_text() {
		if ( ! class_exists( 'DB_Chat_Handoff' ) ) {
			return '';
		}

		try {
			if ( ! DB_Chat_Handoff::is_configured() ) {
				return '';
			}

			return trim( (string) DB_Chat_Handoff::get_hours_text() );
		} catch ( Throwable $e ) {
			return '';
		}
	}

	/**
	 * Trim a retrieved passage to something a chat bubble can carry.
	 *
	 * Cuts on a sentence end where possible, then a word boundary, so the
	 * customer never gets half a word — and marks it with an ellipsis so it
	 * reads as an extract rather than the whole policy.
	 *
	 * @param string $text Passage.
	 * @return string
	 */
	private function trim_passage( $text ) {
		$text = trim( (string) preg_replace( '/\n{2,}/', "\n", (string) $text ) );

		if ( '' === $text || mb_strlen( $text ) <= self::MAX_PASSAGE_CHARS ) {
			return $text;
		}

		$piece = mb_substr( $text, 0, self::MAX_PASSAGE_CHARS );
		$floor = (int) floor( self::MAX_PASSAGE_CHARS * 0.5 );

		foreach ( array( '. ', "\n", ' ' ) as $needle ) {
			$position = mb_strrpos( $piece, $needle );

			if ( false !== $position && $position >= $floor ) {
				$piece = mb_substr( $piece, 0, $position + ( '. ' === $needle ? 1 : 0 ) );
				break;
			}
		}

		return rtrim( $piece ) . "\xE2\x80\xA6";
	}

	/*
	 * =======================================================================
	 * RETRIEVAL WRAPPERS
	 * =======================================================================
	 *
	 * Everything that touches the store goes through these three methods, and
	 * every one of them is wrapped: a retrieval failure must degrade to a
	 * polite decline, never a 500 on a live storefront.
	 *
	 * None of them makes a network call.
	 */

	/**
	 * Run one catalogue search.
	 *
	 * @param array $args search_products() arguments.
	 * @return array<int,array<string,mixed>>
	 */
	private function search_products( array $args ) {
		try {
			$found = $this->product_index->search_products( $args );
		} catch ( Throwable $e ) {
			DB_Chat_Plugin::log_error( 'Lookup mode product search failed: ' . $e->getMessage() );

			return array();
		}

		if ( ! is_array( $found ) || empty( $found['results'] ) || ! is_array( $found['results'] ) ) {
			return array();
		}

		$records = array();

		foreach ( $found['results'] as $record ) {
			if ( is_array( $record ) ) {
				$records[] = $record;
			}
		}

		return $records;
	}

	/**
	 * Build product cards from names we just retrieved.
	 *
	 * resolve_products() rebuilds every card from the live WC_Product and
	 * re-checks publish status and visibility, so the card data can never drift
	 * from the catalogue even if this class mangled a name.
	 *
	 * @param string[] $names Product names.
	 * @return array<int,array<string,mixed>>
	 */
	private function resolve_cards( array $names, $limit = 0 ) {
		if ( empty( $names ) ) {
			return array();
		}

		try {
			$cards = $this->product_index->resolve_products( $names, $limit );
		} catch ( Throwable $e ) {
			DB_Chat_Plugin::log_error( 'Lookup mode could not build product cards: ' . $e->getMessage() );

			return array();
		}

		return is_array( $cards ) ? array_values( $cards ) : array();
	}

	/**
	 * Resolve page titles named in a reply back to verified links.
	 *
	 * @param string $reply Composed reply.
	 * @return array<int,array<string,mixed>>
	 */
	private function resolve_pages( $reply ) {
		if ( null === $this->content_index ) {
			return array();
		}

		try {
			$pages = $this->content_index->match_in_text( (string) $reply );
		} catch ( Throwable $e ) {
			DB_Chat_Plugin::log_error( 'Lookup mode could not build page links: ' . $e->getMessage() );

			return array();
		}

		return is_array( $pages ) ? array_values( $pages ) : array();
	}

	/*
	 * =======================================================================
	 * THE SUBJECT SLOT
	 * =======================================================================
	 *
	 * "do you have Shinko 705"  -> the subject is Shinko 705
	 * "berapa harga"            -> price intent, named nothing -> reuse it
	 * "ada stok tak"            -> stock intent, named nothing -> reuse it
	 *
	 * This is a SLOT, not comprehension. It carries one string forward. It does
	 * not know what a pronoun is, and it never will.
	 *
	 * THERE IS NO SERVER-SIDE SESSION. The slot is recomputed from the history
	 * the widget sends, on every request, by running the SAME keyword matching
	 * over the earlier customer turns. That has one very useful consequence:
	 * expiry and clearing are not stored state that can go stale — they are
	 * recomputed, so they cannot disagree with what the customer actually said.
	 *
	 * HISTORY IS UNTRUSTED INPUT. It is client-controlled, so it is capped,
	 * role-filtered, tag-stripped and length-limited here exactly as the
	 * endpoint does it, and only ever read as TEXT to be re-parsed. There is
	 * deliberately no client-supplied "subject" field: if there were, a crafted
	 * request could make the engine assert whatever it liked about the
	 * catalogue.
	 *
	 * ASSISTANT TURNS ARE IGNORED. Only the customer's own words become a
	 * subject. Our own replies list up to four products, so "the subject" in
	 * them is ambiguous, and feeding our output back in as input is how a
	 * lookup starts confidently talking to itself.
	 */

	/** Intents that cannot be answered without a subject. */
	const SUBJECT_INTENTS = array( 'price', 'stock', 'attribute' );

	/**
	 * Intents that KILL a remembered subject on sight.
	 *
	 * The customer has moved to a different kind of question, and a tyre model
	 * leaking into a returns answer is the failure this whole feature has to
	 * avoid. price_filter is not in SUBJECT_INTENTS either: "anything cheaper?"
	 * with no subject is a whole-catalogue browse, and silently narrowing it to
	 * a remembered brand hides the shop from the customer.
	 */
	const SUBJECT_CLEARING_INTENTS = array( 'policy', 'contact', 'order', 'identity' );

	/**
	 * The product or brand to carry forward, or '' to decline.
	 *
	 * @param string $intent  Resolved intent of the CURRENT message.
	 * @param array  $history Prior turns, untrusted.
	 * @return string
	 */
	private function carried_subject( $intent, array $history ) {
		// Half one of the rule: the intent must actually need a subject.
		if ( ! in_array( $intent, self::SUBJECT_INTENTS, true ) ) {
			return '';
		}

		$turns = $this->history_user_turns( $history );
		$age   = 0;

		foreach ( $turns as $turn ) {
			$age++;

			// EXPIRY. Older than three customer turns and we are guessing about
			// a conversation that has moved on. Declining is the cheaper error.
			if ( $age > self::SUBJECT_MAX_TURNS ) {
				return '';
			}

			$signals = $this->signals( $turn );
			$prior   = $this->resolve_intent( $turn, $signals );

			/*
			 * TOPIC CHANGE. Walking newest-first means any clearing turn is
			 * seen BEFORE the older turn that named the product, so a customer
			 * who asked about a tyre, then about shipping, then types "berapa
			 * harga" gets a decline rather than the tyre's price. That decline
			 * is the correct answer: we genuinely do not know what they mean.
			 */
			if ( in_array( $prior, self::SUBJECT_CLEARING_INTENTS, true ) ) {
				return '';
			}

			$filters = $this->blank_filters();
			$subject = $this->build_query( $turn, $signals, $filters );

			if ( '' !== $subject ) {
				return $subject;
			}
		}

		return '';
	}

	/**
	 * The customer's own earlier turns, normalized, NEWEST FIRST.
	 *
	 * Sanitized here rather than trusted: this class must be safe to call with
	 * a raw request body, not only with what the endpoint happens to hand it.
	 *
	 * @param array $history Prior turns, untrusted.
	 * @return string[]
	 */
	private function history_user_turns( array $history ) {
		if ( count( $history ) > self::MAX_HISTORY ) {
			$history = array_slice( $history, -self::MAX_HISTORY );
		}

		$turns = array();

		foreach ( $history as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['role'], $entry['content'] ) ) {
				continue;
			}

			$role = is_scalar( $entry['role'] ) ? strtolower( trim( (string) $entry['role'] ) ) : '';

			// Customer turns only. See the section note.
			if ( 'user' !== $role ) {
				continue;
			}

			$normalized = $this->normalize( $this->sanitize_turn( $entry['content'] ) );

			if ( '' !== $normalized ) {
				$turns[] = $normalized;
			}
		}

		return array_reverse( $turns );
	}

	/**
	 * Sanitize one history entry, exactly as the endpoint sanitizes a message.
	 *
	 * Kept identical on purpose. If the endpoint's rule changes, change this
	 * with it — the harness asserts the two still agree.
	 *
	 * @param mixed $value Raw content.
	 * @return string
	 */
	private function sanitize_turn( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value    = wp_strip_all_tags( (string) $value, true );
		$stripped = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value );

		if ( is_string( $stripped ) ) {
			$value = $stripped;
		}

		$value = trim( $value );

		if ( mb_strlen( $value ) > self::MAX_MESSAGE_CHARS ) {
			$value = mb_substr( $value, 0, self::MAX_MESSAGE_CHARS );
		}

		return $value;
	}

	/**
	 * A fresh, empty filter set.
	 *
	 * @return array<string,mixed>
	 */
	private function blank_filters() {
		return array(
			'ceiling'    => null,
			'cheapest'   => false,
			'size'       => '',
			'size_asked' => false,
			'colour'     => '',
			'brand'      => '',
			'category'   => '',
			'fitment'    => false,
			'browse'     => false,
			'purchase'   => false,
			'purchase_soft' => false,
			'quantity'   => 0,
		);
	}

	/**
	 * Words that mean "I want to buy this", not "show me this".
	 *
	 * Stripped from the search query: none of them appears in a product title,
	 * and left in they actively hurt. "nak tempah 4 biji tayar" returned ZERO
	 * products before this existed, because "tempah" and "biji" were searched
	 * for as if they were part of a product name.
	 *
	 * "order" is safe to list here even though it is also the order-status
	 * word. Order-status intent is decided before this runs, so by the time a
	 * message reaches the product query builder, "order" can only be the verb.
	 *
	 * @return string[]
	 */
	public static function purchase_terms() {
		$terms = array(
			'buy', 'buying', 'purchase', 'purchasing', 'order', 'ordering', 'checkout',
			'beli', 'membeli', 'belikan', 'tempah', 'menempah', 'tempahan', 'pesan',
		);

		/**
		 * Filter the words that mark a message as a purchase request.
		 *
		 * @param string[] $terms Lowercase words.
		 */
		return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'db_chat_purchase_terms', $terms ) ) ) );
	}

	/**
	 * Buying state handed back to the browser with this reply.
	 *
	 * Null means "no purchase in progress", and that null is sent just as
	 * deliberately as a populated state — it is what clears a cancelled or
	 * completed order.
	 *
	 * @var array<string,mixed>|null
	 */
	private $pending_out = null;

	/**
	 * Did THIS answer() genuinely fail to understand the message?
	 *
	 * Set at exactly three points — the 'fallback' intent, a blind product miss
	 * (no keyword AND no product), and a comparison where neither side
	 * resolved. Deliberately NOT inside log_unmatched(): that function skips
	 * order questions, emails, long digit runs, and everything when logging is
	 * off, so using it would make whether a customer gets an AI answer depend
	 * on a logging setting.
	 *
	 * @var bool
	 */
	private $ai_fallback = false;

	/**
	 * Continue a buying conversation, if one is genuinely in progress.
	 *
	 * Returns null when there is nothing to resume, OR when the customer has
	 * clearly moved on. That second case matters: someone who asks about
	 * delivery halfway through choosing a helmet must get an answer about
	 * delivery, not be asked about sizes again. A chat that will not let you
	 * change the subject is worse than one that cannot sell you anything.
	 *
	 * @param string $message Customer's message.
	 * @param mixed  $pending Raw pending order from the browser.
	 * @return array<string,mixed>|null
	 */
	private function resume_pending( $message, $pending ) {
		$order = DB_Chat_Pending_Order::from_request( $pending );

		if ( null === $order || $order->is_expired() ) {
			return null;
		}

		/*
		 * The purchase keeps the language it started in. Re-detecting per
		 * message would flip to English the moment a Chinese customer answers
		 * "which one?" by pasting an English product title — which is how
		 * people answer that question.
		 */
		$language = '' !== $order->language
			? $order->language
			: self::detect_language( $this->normalize( $message ) );

		if ( DB_Chat_Pending_Order::STAGE_PRODUCT === $order->stage ) {
			$chosen = $this->match_offered_product( $message, (array) $order->offered );

			if ( $chosen < 1 ) {
				return null;
			}

			return $this->ask_for_variation( $chosen, $order->quantity, $language );
		}

		if ( DB_Chat_Pending_Order::STAGE_VARIATION === $order->stage ) {
			$variation = $this->match_offered_variation( $message, (array) $order->offered );

			if ( $variation < 1 ) {
				return null;
			}

			return $this->ask_for_confirmation( $order->product_id, $variation, $order->quantity, $language );
		}

		return $this->handle_confirmation( $message, $order, $language );
	}

	/**
	 * Ask which size, offering only what is in stock.
	 *
	 * @param int    $product_id Product.
	 * @param int    $quantity   Quantity carried from earlier in the conversation.
	 * @param string $language   Reply language.
	 * @return array<string,mixed>
	 */
	private function ask_for_variation( $product_id, $quantity, $language ) {
		$writer  = new DB_Chat_Cart_Writer();
		$sizes   = $writer->in_stock_variations( $product_id );
		$details = $writer->describe( $product_id, 0 );

		if ( empty( $sizes ) ) {
			/*
			 * A simple product, or a variable one with nothing in stock.
			 * Either way go to the confirmation, where the cart writer refuses
			 * anything that cannot actually be bought — one place decides what
			 * is purchasable, and it is not this one.
			 */
			return $this->ask_for_confirmation( $product_id, 0, $quantity, $language );
		}

		if ( 1 === count( $sizes ) ) {
			/*
			 * One size left. NOT silently chosen: it is named in the
			 * confirmation, which the customer still has to accept.
			 */
			return $this->ask_for_confirmation( $product_id, (int) $sizes[0]['variation_id'], $quantity, $language );
		}

		$lines = array( $details['name'], '', $this->text( $language, 'buy_which_size' ) );

		foreach ( $sizes as $row ) {
			$lines[] = "\xE2\x80\xA2 " . $row['label'];
		}

		$this->pending_out = DB_Chat_Pending_Order::create(
			DB_Chat_Pending_Order::STAGE_VARIATION,
			array(
				'product_id' => $product_id,
				'quantity'   => $quantity,
				'offered'    => $sizes,
				'language'   => $language,
			)
		)->to_array();

		return $this->compose( implode( "\n", $lines ), $language, $this->resolve_cards( array( $details['name'] ) ) );
	}

	/**
	 * Show the summary and wait for a yes.
	 *
	 * The quantity is always stated, including the default of 1, so a number
	 * the customer never gave is visible rather than assumed in silence.
	 *
	 * @param int    $product_id   Product.
	 * @param int    $variation_id Variation, 0 for simple products.
	 * @param int    $quantity     Quantity.
	 * @param string $language     Reply language.
	 * @return array<string,mixed>
	 */
	private function ask_for_confirmation( $product_id, $variation_id, $quantity, $language ) {
		$writer  = new DB_Chat_Cart_Writer();
		$details = $writer->describe( $product_id, (int) $variation_id );
		$qty     = max( 1, (int) $quantity );

		$reply = '' !== $details['label']
			? $this->text( $language, 'buy_confirm', $details['name'], $details['label'], $qty, $details['price'] )
			: $this->text( $language, 'buy_confirm_simple', $details['name'], $qty, $details['price'] );

		$this->pending_out = DB_Chat_Pending_Order::create(
			DB_Chat_Pending_Order::STAGE_CONFIRM,
			array(
				'product_id'   => $product_id,
				'variation_id' => (int) $variation_id,
				'quantity'     => $qty,
				'language'     => $language,
			)
		)->to_array();

		return $this->compose( $reply, $language, $this->resolve_cards( array( $details['name'] ) ) );
	}

	/**
	 * The customer answered the confirmation.
	 *
	 * Filled in by Task 6. Until then an outstanding confirmation simply falls
	 * through to a normal reply rather than pretending to buy anything.
	 *
	 * @param string                $message  Customer's reply.
	 * @param DB_Chat_Pending_Order $order    The pending order.
	 * @param string                $language Reply language.
	 * @return array<string,mixed>|null
	 */
	private function handle_confirmation( $message, DB_Chat_Pending_Order $order, $language ) {
		$normalized = ' ' . $this->normalize( $message ) . ' ';

		/*
		 * Chinese first, and decline before confirm within it. 不要 contains
		 * 要 — the same trap as Malay's "tak nak", in a script where the
		 * space-delimited matching below cannot see word boundaries at all.
		 */
		if ( self::is_chinese( $message ) ) {
			if ( '' !== self::chinese_match( $message, 'decline' ) ) {
				$this->pending_out = null;

				return $this->compose( $this->text( $language, 'buy_cancelled' ), $language );
			}

			if ( '' !== self::chinese_match( $message, 'confirm' ) ) {
				return $this->complete_purchase( $order, $language );
			}

			return null;
		}

		/*
		 * DECLINE IS TESTED FIRST, and the order is not stylistic. Malay
		 * negates with a particle rather than a different word: "tak nak"
		 * contains "nak", which is a confirmation word. Checking confirmation
		 * first would read a refusal as a yes and put a helmet in a real cart.
		 */
		foreach ( self::decline_terms() as $term ) {
			if ( false !== strpos( $normalized, ' ' . $term . ' ' ) ) {
				$this->pending_out = null;

				return $this->compose( $this->text( $language, 'buy_cancelled' ), $language );
			}
		}

		$confirmed = false;

		foreach ( self::confirm_terms() as $term ) {
			if ( false !== strpos( $normalized, ' ' . $term . ' ' ) ) {
				$confirmed = true;
				break;
			}
		}

		/*
		 * Neither yes nor no. Answer whatever they actually asked instead of
		 * pressing the sale — and the pending order is dropped rather than
		 * held, because a stale confirmation waiting in the background is how
		 * a later stray "yes" buys something nobody meant to buy.
		 */
		if ( ! $confirmed ) {
			return null;
		}

		return $this->complete_purchase( $order, $language );
	}

	/**
	 * Do the buying, once a confirmation has been established.
	 *
	 * Shared by every language so there is exactly ONE place that writes to a
	 * cart from a confirmation — a second copy for Chinese would be a second
	 * place for the rules to drift.
	 *
	 * @param DB_Chat_Pending_Order $order    The pending order.
	 * @param string                $language Reply language.
	 * @return array<string,mixed>
	 */
	private function complete_purchase( DB_Chat_Pending_Order $order, $language ) {
		$writer  = new DB_Chat_Cart_Writer();
		$details = $writer->describe( $order->product_id, $order->variation_id );
		$result  = $writer->add( $order->product_id, $order->variation_id, $order->quantity );

		// Either way the purchase is over: succeeded, or failed and not retried silently.
		$this->pending_out = null;

		if ( empty( $result['ok'] ) ) {
			$reply = $this->text( $language, 'buy_failed', $result['message'] );

			return $this->with_cart(
				$this->compose( $reply, $language ),
				array( 'added' => false, 'url' => '', 'total' => '', 'count' => 0 )
			);
		}

		$reply = $this->text( $language, 'buy_added', $order->quantity, $details['name'], $result['cart_total'] );

		return $this->with_cart(
			$this->compose( $reply, $language, $this->resolve_cards( array( $details['name'] ) ) ),
			array(
				'added' => true,
				'url'   => $result['cart_url'],
				'total' => $result['cart_total'],
				'count' => $result['item_count'],
			)
		);
	}

	/**
	 * Attach cart state to a composed reply.
	 *
	 * Kept separate so compose() stays the one shape every reply has, and only
	 * the two cart outcomes carry this extra key.
	 *
	 * @param array $reply Composed reply.
	 * @param array $cart  Cart outcome.
	 * @return array<string,mixed>
	 */
	private function with_cart( array $reply, array $cart ) {
		$reply['cart'] = $cart;

		return $reply;
	}

	/**
	 * Which of the products we just offered is the customer naming?
	 *
	 * Scored against the OFFERED products only, never the whole catalogue.
	 * This is the difference between a conversation and four disconnected
	 * searches: when the bot has just asked "which one?", the reply is an
	 * ANSWER, and answering with a product title used to simply re-run the
	 * search and list the same products again.
	 *
	 * @param string $message Customer's reply.
	 * @param int[]  $offered Product ids previously offered, in the order shown.
	 * @return int Product id, or 0 when nothing matched clearly.
	 */
	public function match_offered_product( $message, array $offered ) {
		$offered = array_values( array_filter( array_map( 'absint', $offered ) ) );

		if ( empty( $offered ) ) {
			return 0;
		}

		$normalized = $this->normalize( $message );
		$ordinal    = $this->match_ordinal( $normalized, $offered );

		if ( $ordinal > 0 ) {
			return $ordinal;
		}

		$message_tokens = $this->answer_tokens( $normalized );

		if ( empty( $message_tokens ) ) {
			return 0;
		}

		$best       = 0;
		$best_score = 0.0;
		$runner_up  = 0.0;

		foreach ( $offered as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$title_tokens = $this->answer_tokens( $this->normalize( $product->get_name() ) );

			if ( empty( $title_tokens ) ) {
				continue;
			}

			$matched = count( array_intersect( $message_tokens, $title_tokens ) );
			$score   = $matched / count( $message_tokens );

			if ( $score > $best_score ) {
				$runner_up  = $best_score;
				$best_score = $score;
				$best       = $product_id;
			} elseif ( $score > $runner_up ) {
				$runner_up = $score;
			}
		}

		/*
		 * Two ways to refuse, and both matter on a store that will put the
		 * answer in a cart.
		 *
		 * A weak best match means they probably changed the subject — the
		 * caller then answers their actual question instead of pressing on.
		 *
		 * A best that barely beats the runner-up means the reply did not
		 * DISTINGUISH between the options: "mt helmet" is true of both
		 * Yamanakas. Picking the higher score there is guessing, and guessing
		 * is how a customer ends up with the wrong RM699 helmet.
		 */
		if ( $best_score < 0.34 || ( $best_score - $runner_up ) < 0.10 ) {
			return 0;
		}

		return (int) $best;
	}

	/**
	 * "the first one" / "yang kedua" — position, not description.
	 *
	 * @param string $normalized Normalised message.
	 * @param int[]  $offered    Offered ids, in the order shown.
	 * @return int
	 */
	private function match_ordinal( $normalized, array $offered ) {
		$ordinals = array(
			1 => array( 'first', '1st', 'pertama', 'satu' ),
			2 => array( 'second', '2nd', 'kedua' ),
			3 => array( 'third', '3rd', 'ketiga' ),
		);

		$padded = ' ' . $normalized . ' ';

		foreach ( $ordinals as $nth => $words ) {
			foreach ( $words as $word ) {
				if ( false !== strpos( $padded, ' ' . $word . ' ' ) && isset( $offered[ $nth - 1 ] ) ) {
					return (int) $offered[ $nth - 1 ];
				}
			}
		}

		return 0;
	}

	/**
	 * Which offered variation is the customer naming?
	 *
	 * Every word of a label must appear as a WHOLE token, which is what stops
	 * "L" matching "XL" — a substring check here would sell someone the wrong
	 * size of helmet.
	 *
	 * @param string $message Customer's reply.
	 * @param array  $offered Rows of variation_id + label, from
	 *                        DB_Chat_Cart_Writer::in_stock_variations().
	 * @return int Variation id, or 0.
	 */
	public function match_offered_variation( $message, array $offered ) {
		$tokens = $this->answer_tokens( $this->normalize( $message ) );

		if ( empty( $tokens ) ) {
			return 0;
		}

		foreach ( $offered as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['variation_id'], $row['label'] ) ) {
				continue;
			}

			$label_tokens = $this->answer_tokens( $this->normalize( (string) $row['label'] ) );

			if ( empty( $label_tokens ) ) {
				continue;
			}

			if ( count( array_intersect( $label_tokens, $tokens ) ) === count( $label_tokens ) ) {
				return (int) $row['variation_id'];
			}
		}

		return 0;
	}

	/**
	 * Tokens for answer matching.
	 *
	 * Unlike the product index's tokeniser, single characters are KEPT: "L",
	 * "S" and "M" are sizes, and dropping them would make it impossible to
	 * answer a size question with one letter — which is exactly how people
	 * answer a size question.
	 *
	 * @param string $normalized Already normalised text.
	 * @return string[]
	 */
	private function answer_tokens( $normalized ) {
		$tokens = preg_split( '/[^\p{L}\p{N}]+/u', (string) $normalized, -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $tokens ) ? array_values( array_unique( $tokens ) ) : array();
	}

	/**
	 * Words that mean buying ONLY when a product was actually found.
	 *
	 * "want" and "nak" are stopwords for good reason — "I want to know your
	 * delivery time", "nak tanya pasal penghantaran". Promoting them to plain
	 * purchase terms would stamp a buying note on half of all conversations.
	 *
	 * They count only when the same message also matched a product, which is
	 * exactly what separates "i want yamanaka" from "i want to know". This is
	 * why the flag is resolved late, after the search has run, rather than in
	 * the query builder where every other signal is decided.
	 *
	 * @return string[]
	 */
	public static function purchase_soft_terms() {
		$terms = array( 'want', 'wants', 'need', 'needs', 'get', 'take', 'nak', 'mahu', 'hendak', 'ambil', 'amik' );

		/**
		 * Filter the weak purchase words.
		 *
		 * @param string[] $terms Lowercase words.
		 */
		return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'db_chat_purchase_soft_terms', $terms ) ) ) );
	}

	/**
	 * Chinese reply copy.
	 *
	 * Only the strings a buying customer meets. Everything else falls back to
	 * English by the merge in templates(), which is honest: the engine matches
	 * an English catalogue, and pretending otherwise would promise a Chinese
	 * experience it cannot deliver.
	 *
	 * @return array<string,string>
	 */
	public static function templates_zh() {
		return array(
			'buy_which_product'  => '您要哪一款？',
			'buy_which_size'     => '要哪个尺码？现在有货的：',
			/* translators: 1: product name; 2: option label; 3: quantity; 4: price. */
			'buy_confirm'        => '%1$s — %2$s，%3$d 件，每件 %4$s。加入购物车吗？',
			/* translators: 1: product name; 2: quantity; 3: price. */
			'buy_confirm_simple' => '%1$s，%2$d 件，每件 %3$s。加入购物车吗？',
			/* translators: %s: the only available option. */
			'buy_only_option'    => '这款目前只有 %s。',
			'buy_cancelled'      => '好的，我没有加入任何东西。',
			/* translators: 1: quantity; 2: product name; 3: cart total. */
			'buy_added'          => '已将 %1$d × %2$s 加入购物车。购物车金额现在是 %3$s。',
			/* translators: %s: the reason, already customer-safe. */
			'buy_failed'         => '无法加入购物车：%s',
		);
	}

	/**
	 * Chinese vocabulary, matched as SUBSTRINGS.
	 *
	 * Chinese is written without spaces, so the whole-token matching every
	 * other language here relies on cannot find a word boundary. These are
	 * matched with mb_strpos against the message instead.
	 *
	 * Each list is ordered LONGEST FIRST, and that ordering is load-bearing:
	 * 要买 contains 买, and 不要 contains 要. Matching a short term first would
	 * read "不要" ("don't want") as the confirmation "要" and buy something the
	 * customer just refused.
	 *
	 * @return array<string,string[]>
	 */
	public static function chinese_terms() {
		$terms = array(
			'purchase' => array( '我要买', '想买', '要买', '下单', '訂購', '订购', '購買', '购买', '买', '購', '购' ),
			'confirm'  => array( '确认', '確認', '好的', '可以', '没错', '沒錯', '对', '對', '是', '好', '要' ),
			'decline'  => array( '不要了', '不用了', '先不要', '不要', '不用', '取消', '算了', '等等', '不' ),
			'unit'     => array( '个', '個', '件', '双', '雙', '套' ),
		);

		/**
		 * Filter the Chinese vocabulary.
		 *
		 * @param array<string,string[]> $terms Terms by role.
		 */
		$terms = (array) apply_filters( 'db_chat_chinese_terms', $terms );

		foreach ( $terms as $role => $list ) {
			$list = array_values( array_unique( array_map( 'strval', (array) $list ) ) );

			// Re-sort after filtering: a third party adding a short term must
			// not be able to break the longest-first guarantee above.
			usort(
				$list,
				static function ( $a, $b ) {
					return mb_strlen( $b ) <=> mb_strlen( $a );
				}
			);

			$terms[ $role ] = $list;
		}

		return $terms;
	}

	/**
	 * Does this text contain Han characters?
	 *
	 * @param string $text Any text.
	 * @return bool
	 */
	public static function is_chinese( $text ) {
		return 1 === preg_match( '/\p{Han}/u', (string) $text );
	}

	/**
	 * First Chinese term of a role found in the text.
	 *
	 * @param string $text Message.
	 * @param string $role purchase / confirm / decline / unit.
	 * @return string Matched term, or ''.
	 */
	public static function chinese_match( $text, $role ) {
		$terms = self::chinese_terms();

		if ( empty( $terms[ $role ] ) ) {
			return '';
		}

		foreach ( $terms[ $role ] as $term ) {
			if ( '' !== $term && false !== mb_strpos( (string) $text, $term ) ) {
				return $term;
			}
		}

		return '';
	}

	/**
	 * Words that accept a confirmation summary.
	 *
	 * @return string[]
	 */
	public static function confirm_terms() {
		$terms = array(
			'yes', 'yeah', 'yep', 'yup', 'ok', 'okay', 'confirm', 'confirmed', 'sure', 'add',
			'ya', 'yer', 'betul', 'boleh', 'setuju', 'sah', 'teruskan', 'nak',
		);

		/**
		 * Filter the confirmation words.
		 *
		 * @param string[] $terms Lowercase words.
		 */
		return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'db_chat_confirm_terms', $terms ) ) ) );
	}

	/**
	 * Words that reject a confirmation summary.
	 *
	 * Tested BEFORE the confirm list by every caller. Malay negates with a
	 * particle rather than a different word — "tak nak" contains "nak" — so
	 * checking confirmation first would read a refusal as a yes and put
	 * something in a customer's cart.
	 *
	 * @return string[]
	 */
	public static function decline_terms() {
		$terms = array(
			'no', 'nope', 'cancel', 'stop', 'nevermind', 'dont', 'wait',
			'tidak', 'tak', 'jangan', 'batal', 'belum',
		);

		/**
		 * Filter the decline words.
		 *
		 * @param string[] $terms Lowercase words.
		 */
		return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'db_chat_decline_terms', $terms ) ) ) );
	}

	/**
	 * Words that ask to see a range rather than name a product.
	 *
	 * Stripped from the query for the same reason the buying words are: a
	 * customer asking "what models do you have" is not looking for a product
	 * with "model" in its title.
	 *
	 * @return string[]
	 */
	public static function browse_terms() {
		$terms = array(
			'model', 'models', 'series', 'range', 'lineup', 'line up', 'variant', 'variants',
			'selection', 'available', 'jenis', 'model apa', 'ada apa', 'apa saja', 'apa sahaja',
		);

		/**
		 * Filter the browse words stripped from a product query.
		 *
		 * @param string[] $terms Lowercase words.
		 */
		return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'db_chat_browse_terms', $terms ) ) ) );
	}

	/**
	 * Counting words that can follow a quantity.
	 *
	 * Only ever matched WITH a leading number. "set" and "pasang" appear in
	 * real product titles ("Wind Deflector Set"), so a bare one must survive
	 * into the query untouched — it is only noise in "2 set".
	 *
	 * @return string[]
	 */
	public static function quantity_units() {
		$units = array(
			'pcs', 'pc', 'pieces', 'piece', 'unit', 'units',
			'biji', 'keping', 'set', 'sets', 'pasang', 'batang', 'helai',
		);

		/**
		 * Filter the unit words recognised after a quantity.
		 *
		 * @param string[] $units Lowercase words.
		 */
		return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'db_chat_quantity_units', $units ) ) ) );
	}

	/*
	 * =======================================================================
	 * FUZZY MATCHING — TYPOS ONLY
	 * =======================================================================
	 *
	 * "shinkoo" -> Shinko. "barkbuster" -> BARKBUSTERS.
	 *
	 * PHP built-ins only: levenshtein(), similar_text(), metaphone(). No
	 * dependency, and nothing to install on a store deployed by file upload.
	 *
	 * WHAT IT IS MATCHED AGAINST, and why it is not the catalogue: the
	 * candidate set is the product_brand and product_cat term names the index
	 * already caches (one transient, no new query) plus a short curated list of
	 * common product-title words. Fuzzy-matching every one of 5,283 product
	 * titles would mean thousands of levenshtein() calls inside a customer's
	 * request, on a live store taking real payments. The curated list is the
	 * deliberate, filterable substitute — see fuzzy_terms().
	 *
	 * WHEN IT RUNS: only after an exact search returned nothing. A query that
	 * found something is never second-guessed, so the common path is exactly as
	 * fast and as literal as before.
	 *
	 * WHEN IT REFUSES — and it refuses often, on purpose. A wrong correction is
	 * worse than a decline: answering confidently about a DIFFERENT brand is
	 * how a customer buys the wrong part. So a correction needs ALL of:
	 *
	 *   1. the typed token is at least 4 characters and is not all digits;
	 *   2. edit distance within 1 (or 2 for tokens of 8+ characters);
	 *   3. exactly ONE candidate at that distance — a tie is ambiguous and is
	 *      declined outright, never resolved by picking the first;
	 *   4. similar_text() at 82% or better, which rejects short words that are
	 *      one cheap edit apart but nothing like each other;
	 *   5. the same metaphone code OR the same first letter, so a correction
	 *      has to at least sound like, or start like, what was typed.
	 *
	 * And when it does correct, the reply SAYS SO. The customer is told which
	 * spelling was used, so they can see it guessed and correct us.
	 */

	/**
	 * Correct a failed query, or return '' to leave it alone.
	 *
	 * @param string $query The query that found nothing.
	 * @return string Corrected query, or '' when no confident correction exists.
	 */
	private function fuzzy_correct( $query ) {
		$tokens = preg_split( '/\s+/u', trim( (string) $query ), -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $tokens ) || empty( $tokens ) ) {
			return '';
		}

		$candidates = $this->fuzzy_candidates();

		if ( empty( $candidates ) ) {
			return '';
		}

		$out     = array();
		$changed = false;

		foreach ( $tokens as $token ) {
			$fixed = $this->fuzzy_token( (string) $token, $candidates );

			if ( '' !== $fixed ) {
				$out[]   = $fixed;
				$changed = true;
				continue;
			}

			$out[] = $token;
		}

		return $changed ? trim( implode( ' ', $out ) ) : '';
	}

	/**
	 * The one confident correction for a token, or '' for none.
	 *
	 * @param string                $token      Typed token.
	 * @param array<string,string>  $candidates Lowercased token => display form.
	 * @return string
	 */
	private function fuzzy_token( $token, array $candidates ) {
		$length = mb_strlen( $token );

		if ( $length < self::FUZZY_MIN_CHARS || ctype_digit( $token ) ) {
			return '';
		}

		// Already a known word. Nothing to correct, and correcting it would be
		// actively wrong.
		if ( isset( $candidates[ $token ] ) ) {
			return '';
		}

		$max     = $length >= self::FUZZY_LONG_TOKEN ? 2 : 1;
		$best    = null;
		$winners = array();

		foreach ( $candidates as $candidate => $display ) {
			// Cheap reject before the expensive call.
			if ( abs( mb_strlen( $candidate ) - $length ) > $max ) {
				continue;
			}

			$distance = levenshtein( $token, $candidate );

			if ( $distance < 1 || $distance > $max ) {
				continue;
			}

			if ( null === $best || $distance < $best ) {
				$best    = $distance;
				$winners = array( $candidate );
				continue;
			}

			if ( $distance === $best ) {
				$winners[] = $candidate;
			}
		}

		/*
		 * AMBIGUITY IS A DECLINE. Two brands equally close to what was typed
		 * means we do not know which one they meant, and picking one would be
		 * a coin flip presented to the customer as an answer.
		 */
		if ( null === $best || 1 !== count( $winners ) ) {
			return '';
		}

		$candidate = $winners[0];
		$percent   = 0.0;

		similar_text( $token, $candidate, $percent );

		if ( (float) $percent < self::FUZZY_MIN_SIMILARITY ) {
			return '';
		}

		if ( metaphone( $token ) !== metaphone( $candidate )
			&& mb_substr( $token, 0, 1 ) !== mb_substr( $candidate, 0, 1 ) ) {
			return '';
		}

		return is_string( $candidates[ $candidate ] ) && '' !== $candidates[ $candidate ]
			? $candidates[ $candidate ]
			: $candidate;
	}

	/**
	 * The fuzzy candidate set, memoized for the request.
	 *
	 * @return array<string,string> Lowercased token => display form.
	 */
	private function fuzzy_candidates() {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$cache = array();
		$names = array();

		try {
			$terms = $this->product_index->get_taxonomy_terms();
		} catch ( Throwable $e ) {
			DB_Chat_Plugin::log_error( 'Lookup mode could not read the taxonomy index for fuzzy matching: ' . $e->getMessage() );

			$terms = array();
		}

		if ( is_array( $terms ) ) {
			foreach ( array( 'brands', 'categories' ) as $key ) {
				if ( empty( $terms[ $key ] ) || ! is_array( $terms[ $key ] ) ) {
					continue;
				}

				foreach ( $terms[ $key ] as $name ) {
					if ( is_scalar( $name ) ) {
						$names[] = (string) $name;
					}
				}
			}
		}

		foreach ( self::fuzzy_terms() as $term ) {
			$names[] = $term;
		}

		foreach ( $names as $name ) {
			$tokens = preg_split( '/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY );

			if ( ! is_array( $tokens ) ) {
				continue;
			}

			foreach ( $tokens as $token ) {
				$key = strtolower( $token );

				if ( mb_strlen( $key ) < self::FUZZY_MIN_CHARS || ctype_digit( $key ) ) {
					continue;
				}

				if ( ! isset( $cache[ $key ] ) ) {
					// First spelling wins, so a brand name beats a generic word.
					$cache[ $key ] = $token;
				}
			}
		}

		return $cache;
	}

	/**
	 * Common product-title words a typo may be corrected to.
	 *
	 * THIS LIST EXISTS BECAUSE THE CATALOGUE IS TOO BIG TO SCAN. 5,283 titles
	 * through levenshtein() on every failed search is not something to put in
	 * a customer's request on a live store. Add the words your customers
	 * mistype; adding one here does not teach the assistant the idea behind it,
	 * only the spelling.
	 *
	 * @return string[]
	 */
	public static function fuzzy_terms() {
		$terms = array(
			'helmet', 'helmets', 'visor', 'goggles', 'gloves', 'jacket', 'boots',
			'tyre', 'tyres', 'tire', 'tires', 'tube', 'tubes',
			'brake', 'brakes', 'pads', 'disc', 'rotor', 'lever', 'levers', 'clutch',
			'slider', 'sliders', 'crash', 'protector', 'protectors',
			'handguard', 'handguards', 'guard', 'guards',
			'bracket', 'brackets', 'rack', 'racks', 'pannier', 'panniers', 'luggage',
			'exhaust', 'muffler', 'chain', 'sprocket', 'sprockets',
			'filter', 'filters', 'battery', 'mirror', 'mirrors',
			'screen', 'windscreen', 'radiator', 'grips', 'footpeg', 'footpegs',
			'tayar', 'sarung', 'minyak', 'bateri', 'rantai', 'cermin',
		);

		/**
		 * Filter the words a mistyped token may be corrected to.
		 *
		 * The product_brand and product_cat term names are added automatically;
		 * this list is for words that appear in product TITLES. Keep entries
		 * lowercase and single words.
		 *
		 * @param string[] $terms Candidate words.
		 */
		$terms = (array) apply_filters( 'db_chat_lookup_fuzzy_terms', $terms );

		$clean = array();

		foreach ( $terms as $term ) {
			if ( ! is_scalar( $term ) ) {
				continue;
			}

			$term = trim( strtolower( (string) $term ) );

			if ( '' !== $term ) {
				$clean[ $term ] = true;
			}
		}

		return array_keys( $clean );
	}

	/*
	 * =======================================================================
	 * ORDERS — DELEGATED, NEVER IMPLEMENTED
	 * =======================================================================
	 *
	 * Read the file header before touching anything below.
	 *
	 * Identity is not a language problem, so there was never a technical reason
	 * for this mode to decline order questions: whether the customer is signed
	 * in is a fact about the session, not about how well we parsed their
	 * sentence. What this mode cannot do is UNDERSTAND the question, and that
	 * is handled by keeping the request maximally dumb — we ask for the
	 * customer's own recent orders and restate them.
	 *
	 * The security model is DB_Chat_Order_Lookup's, entirely and unmodified:
	 * identity from get_current_user_id() inside that class, ownership
	 * re-checked on every order object, and a strict field allowlist. This
	 * class adds nothing to it and cannot widen it — it has no user id, no
	 * query, and no way to name a customer.
	 */

	/**
	 * Fields this class will read off an order record. An ALLOWLIST.
	 *
	 * Deliberately narrower in spirit than the source: no address, no phone, no
	 * email, no customer name, no payment method, no transaction id.
	 *
	 * WHICH HALF ACTUALLY PROTECTS YOU — measured 2026-08-18, because the
	 * comment here used to imply both did. Two things are going on:
	 *
	 *   1. NOTHING EVER ITERATES AN ORDER RECORD. This is the load-bearing one.
	 *      Mutating the renderer to splice the record's values into the reply
	 *      is caught immediately by 7 assertions, against a fixture that offers
	 *      address, phone, email, name, payment method and transaction id.
	 *   2. order_field() refuses a key not on this list. Today this catches
	 *      NOTHING: all six call sites pass string literals that are already on
	 *      the list, so disabling the in_array() check changes no output and no
	 *      test. There is no current input that distinguishes it.
	 *
	 * Keep both, but keep them honestly: (2) is future-proofing against a
	 * caller that one day asks for 'billing_address', not a second live
	 * defence. If you are relying on something here, rely on (1).
	 */
	const ORDER_FIELDS = array( 'order_number', 'order_date', 'status', 'total', 'tracking_number', 'tracking_url' );

	/**
	 * The order service, constructed lazily.
	 *
	 * The bootstrap builds this engine with two arguments, so there is normally
	 * nothing injected. Building it here on first use — rather than on every
	 * request — keeps the object off the path of the 99% of messages that are
	 * about tyres.
	 *
	 * @return DB_Chat_Order_Lookup|null
	 */
	private function order_lookup() {
		if ( null !== $this->order_lookup ) {
			return $this->order_lookup;
		}

		if ( ! class_exists( 'DB_Chat_Order_Lookup' ) ) {
			return null;
		}

		try {
			$this->order_lookup = new DB_Chat_Order_Lookup();
		} catch ( Throwable $e ) {
			DB_Chat_Plugin::log_error( 'Lookup mode could not build the order lookup (' . get_class( $e ) . ').' );

			return null;
		}

		return $this->order_lookup;
	}

	/**
	 * Answer an order question.
	 *
	 * @param string $normalized Normalized message.
	 * @param array  $signals    Signal scores and matched phrases.
	 * @param string $language   Reply language.
	 * @return array{reply:string,products:array,pages:array}
	 */
	private function answer_orders( $normalized, array $signals, $language ) {
		unset( $signals );

		/*
		 * Marked BEFORE anything is read, and marked for every order turn
		 * including a guest's. The endpoint reads this and withholds both sides
		 * of the exchange from the log: "where is order 10425" carries an order
		 * number whether or not we could answer it.
		 */
		$this->order_turn = true;

		$lookup = $this->order_lookup();

		if ( null === $lookup ) {
			return $this->compose(
				$this->text( $language, 'order' ) . ' ' . $this->handoff_hint( $language ),
				$language
			);
		}

		try {
			/*
			 * THE EMPTY ARRAY IS LOAD-BEARING. get_my_orders() accepts an
			 * optional order_number, and this mode does not pass one — not
			 * because it is unsafe (it only ever narrows a set already pinned
			 * to the signed-in customer), but because pulling a number out of
			 * free text is a guess, and a wrong guess returns "not found" about
			 * an order the customer really does own. Their recent orders answer
			 * the question anyway. NEVER add an argument here.
			 */
			$result = $lookup->get_my_orders( array() );
		} catch ( Throwable $e ) {
			// Class name only. An order-path exception can carry query
			// fragments, so the message is dropped rather than redacted.
			DB_Chat_Plugin::log_error( 'Lookup mode order lookup failed (' . get_class( $e ) . '). Detail withheld: it may contain order data.' );

			return $this->compose(
				$this->text( $language, 'order' ) . ' ' . $this->handoff_hint( $language ),
				$language
			);
		}

		if ( ! is_array( $result ) ) {
			return $this->compose(
				$this->text( $language, 'order' ) . ' ' . $this->handoff_hint( $language ),
				$language
			);
		}

		/*
		 * GUEST. Tell them to sign in, and STOP. No order number is requested,
		 * no email is requested, no phone number is requested — the same rule
		 * the Claude path follows. Asking a stranger for identifying details in
		 * a chat window teaches customers to hand personal data to anything
		 * that asks, and nothing here could use them anyway.
		 */
		if ( empty( $result['signed_in'] ) ) {
			return $this->compose( $this->text( $language, 'order_signed_out' ), $language );
		}

		$records = isset( $result['results'] ) && is_array( $result['results'] ) ? $result['results'] : array();

		if ( empty( $records ) ) {
			return $this->compose(
				$this->text( $language, 'order_none' ) . ' ' . $this->handoff_hint( $language ),
				$language
			);
		}

		$lines = array();
		$shown = 0;

		foreach ( $records as $record ) {
			if ( $shown >= self::ORDER_MAX_LISTED ) {
				break;
			}

			if ( ! is_array( $record ) ) {
				continue;
			}

			$number = $this->order_field( $record, 'order_number' );

			if ( '' === $number ) {
				continue;
			}

			$status = $this->order_field( $record, 'status' );
			$block  = array( '' !== $status ? $this->text( $language, 'order_line', $number, $status ) : '#' . $number );

			$meta  = array();
			$date  = $this->order_field( $record, 'order_date' );
			$total = $this->order_field( $record, 'total' );

			if ( '' !== $date ) {
				$meta[] = $this->text( $language, 'order_placed', $date );
			}

			if ( '' !== $total ) {
				$meta[] = $this->text( $language, 'order_total', $total );
			}

			if ( ! empty( $meta ) ) {
				$block[] = implode( " \xC2\xB7 ", $meta );
			}

			$items = $this->order_items_text( $record, $language );

			if ( '' !== $items ) {
				$block[] = $this->text( $language, 'order_items', $items );
			}

			$tracking = $this->order_field( $record, 'tracking_number' );

			if ( '' !== $tracking ) {
				$block[] = $this->text( $language, 'order_tracking', $tracking );
			}

			$tracking_url = $this->order_field( $record, 'tracking_url' );

			if ( '' !== $tracking_url ) {
				$block[] = $this->text( $language, 'order_tracking_url', $tracking_url );
			}

			$lines[] = implode( "\n", $block );
			$shown++;
		}

		if ( empty( $lines ) ) {
			// Records came back but none was renderable. Say nothing rather
			// than invent a status.
			return $this->compose(
				$this->text( $language, 'order_none' ) . ' ' . $this->handoff_hint( $language ),
				$language
			);
		}

		array_unshift( $lines, $this->text( $language, 'order_found' ) );

		// No delivery estimate, ever. The record says what it says.
		$notes = array( $this->text( $language, 'order_no_eta' ) );

		if ( count( $records ) > $shown ) {
			$notes[] = $this->text( $language, 'order_more' );
		}

		if ( $this->wants_order_action( $normalized ) ) {
			// Cancel, change, refund, chase: this reads, it does not act.
			$notes[] = $this->text( $language, 'order_readonly' );
			$notes[] = $this->handoff_hint( $language );
		}

		return $this->compose(
			implode( "\n\n", $lines ) . "\n\n" . implode( ' ', array_filter( $notes ) ),
			$language
		);
	}

	/**
	 * Read ONE allowlisted field off an order record.
	 *
	 * @param array  $record Order record from DB_Chat_Order_Lookup.
	 * @param string $key    Field name.
	 * @return string
	 */
	private function order_field( array $record, $key ) {
		if ( ! in_array( $key, self::ORDER_FIELDS, true ) ) {
			return '';
		}

		if ( ! isset( $record[ $key ] ) || ! is_scalar( $record[ $key ] ) ) {
			return '';
		}

		return mb_substr( trim( wp_strip_all_tags( (string) $record[ $key ], true ) ), 0, 300 );
	}

	/**
	 * The item list for one order.
	 *
	 * Reads only 'name' and 'quantity' — the two keys the source emits, named
	 * explicitly rather than iterated, for the same reason as order_field().
	 *
	 * @param array  $record   Order record.
	 * @param string $language Reply language.
	 * @return string
	 */
	private function order_items_text( array $record, $language ) {
		if ( empty( $record['items'] ) || ! is_array( $record['items'] ) ) {
			return '';
		}

		$parts = array();

		foreach ( $record['items'] as $item ) {
			if ( count( $parts ) >= self::ORDER_MAX_ITEMS ) {
				break;
			}

			if ( ! is_array( $item ) || ! isset( $item['name'] ) || ! is_scalar( $item['name'] ) ) {
				continue;
			}

			$name = trim( wp_strip_all_tags( (string) $item['name'], true ) );

			if ( '' === $name ) {
				continue;
			}

			$name     = mb_substr( $name, 0, 120 );
			$quantity = isset( $item['quantity'] ) && is_numeric( $item['quantity'] ) ? max( 0, (int) $item['quantity'] ) : 0;

			$parts[] = $quantity > 1 ? $this->text( $language, 'order_item', $name, $quantity ) : $name;
		}

		return implode( ', ', $parts );
	}

	/**
	 * Did the customer ask us to DO something to an order?
	 *
	 * @param string $normalized Normalized message.
	 * @return bool
	 */
	private function wants_order_action( $normalized ) {
		$haystack = ' ' . $normalized . ' ';

		foreach ( self::order_action_terms() as $term ) {
			if ( false !== strpos( $haystack, ' ' . $term . ' ' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Words that mean "change this order", not "tell me about it".
	 *
	 * @return string[]
	 */
	public static function order_action_terms() {
		$terms = array(
			'cancel', 'cancel my order', 'refund', 'refund my order', 'chase',
			'complain', 'complaint', 'wrong item', 'send it back',
			'batal', 'batal order', 'tukar order', 'pulangkan', 'aduan',
		);

		/**
		 * Filter the wording that marks an order ACTION request.
		 *
		 * These only add a "I can read orders, I cannot change them" note and a
		 * pointer to a human. They never change what is looked up.
		 *
		 * @param string[] $terms Action phrases, lowercase and unpunctuated.
		 */
		$terms = (array) apply_filters( 'db_chat_lookup_order_actions', $terms );

		$clean = array();

		foreach ( $terms as $term ) {
			if ( ! is_scalar( $term ) ) {
				continue;
			}

			$term = trim( preg_replace( '/[^a-z0-9]+/', ' ', strtolower( (string) $term ) ) );

			if ( '' !== $term ) {
				$clean[ $term ] = true;
			}
		}

		return array_keys( $clean );
	}

	/*
	 * =======================================================================
	 * THE UNMATCHED QUESTION LOG
	 * =======================================================================
	 *
	 * The point of this mode is that it declines what it does not recognise.
	 * The problem with that is the owner never finds out WHAT it declined, so
	 * the decline rate stays an unknown instead of a worklist. This turns every
	 * decline into one line the owner can act on: add that wording to
	 * keywords() and the question is answered next time.
	 *
	 * WHAT IS STORED: the question text, a timestamp, and 'en'/'ms'. That is
	 * all. No IP, no user id, no session token, no history, no reply, no
	 * products. This is why it can be ON by default where conversation logging
	 * is off by default — there is nothing here that identifies anybody.
	 *
	 * WHAT IS NEVER STORED: anything from a turn where the ORDER intent fired.
	 * Order questions routinely carry an order number, and order data is never
	 * logged anywhere in this plugin. Order turns cannot reach a fallback in
	 * the first place, so this is belt and braces — along with skipping any
	 * message that contains an email address or a run of five or more digits.
	 * That heuristic will occasionally drop a legitimate row. Dropping a
	 * worklist entry costs the owner nothing; storing a phone number costs a
	 * customer something.
	 *
	 * WHAT IS DELIBERATELY NOT LOGGED: a "we could not find that product"
	 * miss. That is a CATALOGUE gap, not a keyword gap, and the row shape has
	 * no field to tell the two apart — a screen full of rows the owner cannot
	 * fix by adding a keyword makes the whole list feel like noise. Only true
	 * fallbacks are recorded: messages the engine did not understand at all.
	 *
	 * Storage is ONE option, capped and FIFO. Not a table (nothing here is
	 * worth a schema, and the schema is not this agent's to write) and not a
	 * transient (it must survive a cache flush, or the worklist evaporates
	 * exactly when the owner goes looking for it).
	 */

	/**
	 * Record a question this mode could not understand.
	 *
	 * @param mixed  $message  The customer's own words.
	 * @param array  $signals  Signal scores and matched phrases.
	 * @param string $language Detected language.
	 * @return void
	 */
	private function log_unmatched( $message, array $signals, $language ) {
		/*
		 * NEVER an order question.
		 *
		 * MEASURED, not assumed (2026-08-18). This reads like two independent
		 * checks and is not. With order routing mutated so this line is
		 * reachable at all, dropping the $signals half loses 4 log-privacy
		 * assertions; dropping $this->order_turn loses NONE. It rescues nothing
		 * on any input the suite can produce.
		 *
		 * So: **the $signals check is the one that must not be broken.**
		 *
		 * $this->order_turn is retained as future-proofing, NOT as redundancy.
		 * It only becomes load-bearing if answer_orders() ever stops returning
		 * immediately and falls through to a product search ("no order found,
		 * let me look for products instead") — at which point the flag is true
		 * and it is the only thing between an order number and the log. Do not
		 * call it a second check again until there is an input that proves it.
		 */
		if ( $this->order_turn || ! empty( $signals['scores']['order'] ) ) {
			return;
		}

		if ( ! self::unmatched_logging_enabled() ) {
			return;
		}

		$question = $this->plain_question( $message );

		if ( '' === $question ) {
			return;
		}

		// Err towards not logging. See the section note.
		if ( preg_match( '/[^\s@]+@[^\s@]+\.[a-z]{2,}/i', $question ) || preg_match( '/[0-9]{5,}/', $question ) ) {
			return;
		}

		$entries   = self::read_unmatched();
		$entries[] = array(
			'question' => $question,
			'time'     => time(),
			'lang'     => 'ms' === $language ? 'ms' : 'en',
		);

		// FIFO: the oldest go first, so the list always shows what is happening
		// now rather than what happened when the mode was switched on.
		if ( count( $entries ) > self::MAX_UNMATCHED ) {
			$entries = array_slice( $entries, -self::MAX_UNMATCHED );
		}

		self::write_unmatched( $entries );
	}

	/**
	 * Reduce a raw message to storable plain text.
	 *
	 * @param mixed $message Raw message.
	 * @return string
	 */
	private function plain_question( $message ) {
		if ( ! is_scalar( $message ) ) {
			return '';
		}

		$text     = wp_strip_all_tags( (string) $message, true );
		$stripped = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );

		if ( is_string( $stripped ) ) {
			$text = $stripped;
		}

		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		return mb_substr( $text, 0, self::MAX_UNMATCHED_CHARS );
	}

	/**
	 * Is the unmatched log switched on?
	 *
	 * Default ON, unlike conversation logging. It stores no personal data and
	 * it is the entire feedback loop for this mode — off by default would mean
	 * the owner has to discover a setting before the feature does anything.
	 *
	 * @return bool
	 */
	public static function unmatched_logging_enabled() {
		$enabled = true;

		if ( function_exists( 'get_option' ) ) {
			$value   = get_option( self::OPTION_UNMATCHED_ENABLED, '1' );
			$value   = is_scalar( $value ) ? (string) $value : '1';
			$enabled = ! in_array( strtolower( trim( $value ) ), array( '0', '', 'no', 'false', 'off' ), true );
		}

		/**
		 * Filter whether unmatched questions are recorded.
		 *
		 * @param bool $enabled Whether logging is on.
		 */
		return (bool) apply_filters( 'db_chat_lookup_log_unmatched', $enabled );
	}

	/**
	 * The stored log, oldest first, every row validated.
	 *
	 * @return array<int,array{question:string,time:int,lang:string}>
	 */
	private static function read_unmatched() {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}

		$stored = get_option( self::OPTION_UNMATCHED_ENTRIES, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$clean = array();

		foreach ( $stored as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['question'] ) || ! is_scalar( $entry['question'] ) ) {
				continue;
			}

			$question = trim( wp_strip_all_tags( (string) $entry['question'], true ) );

			if ( '' === $question ) {
				continue;
			}

			$clean[] = array(
				'question' => mb_substr( $question, 0, self::MAX_UNMATCHED_CHARS ),
				'time'     => isset( $entry['time'] ) && is_numeric( $entry['time'] ) ? (int) $entry['time'] : 0,
				'lang'     => isset( $entry['lang'] ) && 'ms' === $entry['lang'] ? 'ms' : 'en',
			);
		}

		return $clean;
	}

	/**
	 * Persist the log.
	 *
	 * Autoload OFF: this can reach 200 rows and does not belong on every page
	 * load of a store taking real payments.
	 *
	 * @param array $entries Entries, oldest first.
	 * @return void
	 */
	private static function write_unmatched( array $entries ) {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}

		update_option( self::OPTION_UNMATCHED_ENTRIES, array_values( $entries ), false );
	}

	/*
	 * -----------------------------------------------------------------------
	 * PUBLIC ACCESSORS for the admin screen.
	 *
	 * These are the agreed integration point for the unmatched-questions
	 * viewer, which lives in class-settings.php and is another agent's file.
	 * All static, so no engine instance is needed.
	 *
	 * NOTE FOR THE SCREEN: nothing here checks a capability or a nonce. These
	 * are data methods, not screens. The caller gates them — clear_* in
	 * particular must sit behind current_user_can() and check_admin_referer().
	 * And 'question' is raw customer-typed text: escape it on output.
	 * -----------------------------------------------------------------------
	 */

	/**
	 * The unmatched questions, NEWEST FIRST.
	 *
	 * 'time' is a UNIX timestamp, so render it with wp_date() to get the site
	 * timezone. 'lang' is 'en' or 'ms'.
	 *
	 * @param int $limit Maximum rows. 0 or less means all of them.
	 * @return array<int,array{question:string,time:int,lang:string}>
	 */
	public static function get_unmatched_questions( $limit = 0 ) {
		$entries = array_reverse( self::read_unmatched() );
		$limit   = (int) $limit;

		if ( $limit > 0 ) {
			$entries = array_slice( $entries, 0, $limit );
		}

		return array_values( $entries );
	}

	/**
	 * How many unmatched questions are stored.
	 *
	 * @return int
	 */
	public static function get_unmatched_count() {
		return count( self::read_unmatched() );
	}

	/**
	 * Delete the whole log.
	 *
	 * @return bool True when the option was removed.
	 */
	public static function clear_unmatched_questions() {
		if ( ! function_exists( 'delete_option' ) ) {
			return false;
		}

		return (bool) delete_option( self::OPTION_UNMATCHED_ENTRIES );
	}

	/*
	 * =======================================================================
	 * SMALL HELPERS
	 * =======================================================================
	 */

	/**
	 * Read a number out of a formatted price string.
	 *
	 * The index hands back display text ("RM 320.00", or "RM 100.00 – RM
	 * 200.00" for a variable product), so the FIRST number is taken: for a
	 * range that is the low end, which is the right thing to compare against
	 * "under RM 500".
	 *
	 * Returns null when there is nothing readable — the caller must then leave
	 * the product out of a price comparison rather than assume a value.
	 *
	 * @param mixed $text Price text.
	 * @return float|null
	 */
	private function price_value( $text ) {
		if ( ! is_scalar( $text ) ) {
			return null;
		}

		$text = (string) $text;

		if ( ! preg_match( '/([0-9][0-9.,]*)/', $text, $matches ) ) {
			return null;
		}

		$number = $matches[1];

		if ( false !== strpos( $number, ',' ) && false !== strpos( $number, '.' ) ) {
			// "1,234.56" — comma is the thousands separator.
			$number = str_replace( ',', '', $number );
		} elseif ( preg_match( '/,[0-9]{1,2}$/', $number ) ) {
			// "1.234,56" — comma is the decimal separator.
			$number = str_replace( '.', '', $number );
			$number = str_replace( ',', '.', $number );
		} else {
			$number = str_replace( ',', '', $number );
		}

		$number = rtrim( $number, '.' );

		return is_numeric( $number ) ? (float) $number : null;
	}

	/**
	 * Format a ceiling for display, e.g. 500.0 => "RM 500".
	 *
	 * @param float|null $value Amount.
	 * @return string
	 */
	private function money( $value ) {
		if ( null === $value ) {
			return '';
		}

		$formatted = number_format( (float) $value, 2, '.', ',' );

		// Drop a pointless ".00" without touching ".50".
		if ( '.00' === substr( $formatted, -3 ) ) {
			$formatted = substr( $formatted, 0, -3 );
		}

		return 'RM ' . $formatted;
	}

	/**
	 * Build the REST payload.
	 *
	 * The ONLY place a reply leaves this class, so it is the one place that has
	 * to guarantee the contract: a non-empty plain-text reply, and arrays for
	 * products and pages even when there are none.
	 *
	 * @param string $reply    Composed reply.
	 * @param string $language Reply language.
	 * @param array  $products Product cards.
	 * @param array  $pages    Page links.
	 * @return array{reply:string,products:array,pages:array}
	 */
	private function compose( $reply, $language, array $products = array(), array $pages = array() ) {
		$reply = $this->finalize( $reply );

		if ( '' === $reply ) {
			// Belt and braces: a blank bubble reads as a broken shop.
			$reply = $this->finalize( $this->text( $language, 'fallback' ) );
		}

		return array(
			'reply'    => $reply,
			'products' => array_values( $products ),
			'pages'    => array_values( $pages ),
			/*
			 * Every reply carries the buying state, including the null that
			 * clears it. compose() is the single place every reply passes
			 * through, so a new reply path cannot forget to include it and
			 * strand a customer mid-purchase.
			 */
			'pending'  => $this->pending_out,
		);
	}

	/**
	 * Reduce the reply to safe plain text.
	 *
	 * Tags stripped, control characters removed, newlines kept (the widget
	 * renders the bubble with white-space: pre-wrap and sets it with
	 * textContent, so a line break is a line break and markup is inert).
	 *
	 * @param string $text Reply.
	 * @return string
	 */
	private function finalize( $text ) {
		$text = wp_strip_all_tags( (string) $text, false );

		$stripped = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );

		if ( is_string( $stripped ) ) {
			$text = $stripped;
		}

		$text = (string) preg_replace( '/\r\n?/', "\n", $text );
		$text = (string) preg_replace( '/[^\S\n]+/u', ' ', $text );
		$text = (string) preg_replace( '/ *\n */u', "\n", $text );
		$text = (string) preg_replace( '/\n{3,}/u', "\n\n", $text );
		$text = trim( $text );

		if ( mb_strlen( $text ) > self::MAX_REPLY_CHARS ) {
			$text = rtrim( mb_substr( $text, 0, self::MAX_REPLY_CHARS ) ) . "\xE2\x80\xA6";
		}

		return $text;
	}
}
