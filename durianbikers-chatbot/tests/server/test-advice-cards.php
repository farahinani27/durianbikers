<?php
// Run: wp eval-file durianbikers-chatbot/tests/server/test-advice-cards.php --path=<docroot>
//
// WHAT THIS PROVES. A care/how-to answer ("how do I clean my helmet") should
// carry up to THREE real product cards that genuinely upsell — and must not
// pad the list with the thing the customer already owns. The live failure was
// a scratched-helmet question that offered a "Simpson Helmet M52" beside a
// care kit: the old relevance guard only asked whether the product name
// contained a topic noun, and on a helmet question every helmet in the shop
// passes that test.
//
// Read-only: no cart, no customer session, no HTTP. It calls the same two
// methods the endpoint's advice path calls, so what it prints is what a
// customer would have seen.
require __DIR__ . '/../lib/assert.php';

if ( ! function_exists( 'db_chat' ) || ! class_exists( 'DB_Chat_Product_Index' ) ) {
	t_ok( false, 'chatbot plugin not loaded' );
	t_done();
}

$index  = db_chat()->product_index();
$engine = db_chat()->lookup_engine();

t_ok( $index instanceof DB_Chat_Product_Index, 'product index available' );
t_ok( is_callable( array( $engine, 'advice_search_query' ) ), 'lookup engine exposes advice_search_query()' );
t_ok( is_callable( array( $engine, 'is_care_question' ) ), 'lookup engine exposes is_care_question()' );

/**
 * The care/consumable vocabulary the relevance guard uses. Kept in step with
 * DB_Chat_Product_Index::cards_for_query()'s list through the same filter, so
 * this test cannot drift away from the code it is checking.
 *
 * @return string[]
 */
function db_chat_test_care_markers() {
	return (array) apply_filters(
		'db_chat_care_product_markers',
		array(
			'clean', 'wash', 'shampoo', 'degreas', 'lube', 'lubricant', 'oil',
			'grease', 'polish', 'wax', 'shine', 'protectant', 'sealant',
			'spray', 'kit', 'care', 'detailer', 'remover', 'anti fog',
			'anti-fog', 'antifog', 'microfib', 'cloth', 'towel', 'brush',
			'sponge', 'coating', 'treatment', 'fluid', 'additive', 'filter',
		)
	);
}

/**
 * Is this product name a care product rather than the object of the care?
 *
 * @param string $name Product name.
 * @return bool
 */
function db_chat_test_is_care_product( $name ) {
	$name = ' ' . mb_strtolower( (string) $name ) . ' ';

	foreach ( db_chat_test_care_markers() as $marker ) {
		if ( false !== mb_strpos( $name, mb_strtolower( (string) $marker ) ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Run the endpoint's advice-card path for one customer question.
 *
 * @param string $message Customer question.
 * @param int    $limit   Card limit (the endpoint passes 3).
 * @return array<int,array> Cards.
 */
function db_chat_test_advice_cards( $message, $limit = 3 ) {
	$engine = db_chat()->lookup_engine();
	$index  = db_chat()->product_index();
	$topic  = $engine->advice_search_query( $message );

	if ( empty( $topic['queries'] ) || empty( $topic['nouns'] ) ) {
		return array();
	}

	return $index->cards_for_query( $topic['queries'], $topic['nouns'], $limit );
}

// Every care question the operator's conversation went through, plus the Malay
// equivalents the same path has to handle.
$questions = array(
	'how do i clean my helmet',
	'helmet cleaning',
	'how do i fix a scratch on my helmet',
	'how do i clean my chain',
	'macam mana nak jaga chain',
);

foreach ( $questions as $question ) {
	t_ok( true === $engine->is_care_question( $question ), 'recognised as a care question: ' . $question );

	$cards = db_chat_test_advice_cards( $question, 3 );
	$names = array();

	foreach ( $cards as $card ) {
		$names[] = isset( $card['name'] ) ? (string) $card['name'] : '';
	}

	echo "\nQ: $question\n";

	foreach ( $names as $name ) {
		echo '   - ' . $name . ( db_chat_test_is_care_product( $name ) ? '   [care product]' : '   [NOT a care product]' ) . "\n";
	}

	t_ok( count( $cards ) <= 3, 'at most 3 cards: ' . $question . ' (got ' . count( $cards ) . ')' );

	// Every card is a real, published, visible product rebuilt from WooCommerce
	// — the card path never trusts model text.
	foreach ( $cards as $card ) {
		t_ok(
			isset( $card['name'], $card['url'], $card['in_stock'] ) && '' !== trim( (string) $card['name'] ) && '' !== trim( (string) $card['url'] ),
			'card is a real product with a name and a URL: ' . ( isset( $card['name'] ) ? $card['name'] : '?' )
		);
	}

	/*
	 * THE REGRESSION. Either every card is from the care shelf, or there is
	 * exactly ONE card and nothing on the care shelf matched. What must never
	 * happen again is a MIX — a helmet sitting among the polishes, which reads
	 * as "we recommend all of these" and so recommends a new helmet to someone
	 * asking how to clean the one they own.
	 */
	$care_count = 0;

	foreach ( $names as $name ) {
		if ( db_chat_test_is_care_product( $name ) ) {
			++$care_count;
		}
	}

	if ( count( $cards ) > 1 ) {
		t_eq( $care_count, count( $cards ), 'no unrelated product mixed in among the care products: ' . $question );
	}

	if ( 0 === $care_count && ! empty( $cards ) ) {
		t_eq( count( $cards ), 1, 'nothing on the care shelf matched, so at most ONE related card: ' . $question );
	}
}

// A helmet-cleaning question must never card a plain helmet. Checked by name
// shape as well as by the marker test above: "<brand> Helmet <model>" with no
// care word in it is the exact card the operator saw.
$helmet_cards = db_chat_test_advice_cards( 'how do i clean my helmet', 3 );

foreach ( $helmet_cards as $card ) {
	$name = mb_strtolower( (string) $card['name'] );
	$bare_helmet = ( false !== mb_strpos( $name, 'helmet' ) ) && ! db_chat_test_is_care_product( $name );

	t_ok( ! $bare_helmet, 'helmet-cleaning question does not card a bare helmet: ' . $card['name'] );
}

// The limit is honoured and bounded by the card cap.
t_ok( 3 <= DB_Chat_Product_Index::MAX_CARDS, 'three cards is within MAX_CARDS' );

// A non-care question must not reach this path at all — the gate, not the
// guard, is what keeps upsell cards off order/policy/greeting turns.
foreach ( array( 'where is my order', 'what is your return policy', 'hello', 'how much is the shinko 705' ) as $not_care ) {
	t_ok( false === $engine->is_care_question( $not_care ), 'not treated as a care question: ' . $not_care );
}

t_done();
