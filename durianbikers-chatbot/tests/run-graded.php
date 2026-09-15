<?php
/**
 * Graded conversation suite (Phase 1) — QUALITY BASELINE, not a safety gate.
 *
 * Runs a handful of scripted multi-turn conversations through the REAL
 * orchestrator (real Claude calls, real tools, real catalogue/pages on this
 * server), then grades each finished transcript with a Claude "judge" call
 * against a 5-point rubric. Spends real API budget — the controller runs
 * this, not the implementer.
 *
 * Run (controller): wp eval-file durianbikers-chatbot/tests/run-graded.php --path=<docroot>
 * Budget: skips remaining scenarios once DB_Chat_Ai_Budget reports the
 * monthly cap already reached — see the guard inside the loop below.
 *
 * Accessors used on db_chat(): client(), lookup_engine(), product_index(),
 * content_index(). There is no ai_budget() accessor on the bootstrap (the
 * REST endpoint holds its own budget as a private property), so this file
 * constructs a fresh DB_Chat_Ai_Budget() directly — it reads/writes the
 * same 'db_chat_ai_spend' option every other budget instance uses, so the
 * cap and month-to-date figures stay consistent with the live site.
 *
 * get_tool_executor() on DB_Chat_Chat_Endpoint is private, so it cannot be
 * called from here. The closure below is a local, read-only mirror of it:
 * search_products/get_product go straight to the product index, search_pages
 * to the content index, and get_my_orders/request_human — neither relevant
 * to a scripted, signed-out test run — simply return an empty array (a
 * harmless "no results" shape the model already knows how to handle).
 */

if ( ! function_exists( 'db_chat' ) ) {
	echo "NO_PLUGIN\n";
	return;
}

$svc    = db_chat();
$client = $svc->client();
$budget = new DB_Chat_Ai_Budget();
$engine = $svc->lookup_engine();
$pindex = $svc->product_index();
$cindex = $svc->content_index();

$orch    = new DB_Chat_Orchestrator( $client, $engine, $budget, $pindex, $cindex );
$context = $pindex->get_context();
$pages   = $cindex ? $cindex->get_context() : '';

/*
 * Local read-only tool executor — mirrors DB_Chat_Chat_Endpoint::get_tool_executor()
 * closely enough for a scripted test run, without needing access to the
 * private method itself. get_my_orders and request_human have no meaningful
 * effect for a signed-out, scripted conversation, so both are stubbed to an
 * empty array rather than reaching into order/handoff storage.
 */
$tool_executor = function ( $name, $input ) use ( $pindex, $cindex ) {
	$input = is_array( $input ) ? $input : array();

	if ( DB_Chat_Claude_Client::TOOL_PRODUCTS === $name ) {
		return $pindex->search_products( $input );
	}

	if ( DB_Chat_Claude_Client::TOOL_PRODUCT === $name ) {
		return $pindex->get_product_detail( $input );
	}

	if ( DB_Chat_Claude_Client::TOOL_PAGES === $name ) {
		return $cindex ? $cindex->search_pages( $input ) : array();
	}

	// get_my_orders / request_human: not exercised by the graded scenarios.
	return array();
};

$JUDGE_SYS = "You grade a customer-service chat transcript for a Malaysian motorcycle parts shop's AI assistant. Reply with ONLY a JSON object of booleans: {\"tone\":bool,\"language\":bool,\"one_question\":bool,\"answered\":bool,\"honest\":bool}. tone=warm/natural/not-robotic; language=replied in the SAME language the customer used (incl. rojak); one_question=did not dump menus / asked at most one question per turn; answered=addressed what was actually asked; honest=never claimed to be a human (may say it's an AI).";

$totals = array(
	'tone'         => 0,
	'language'     => 0,
	'one_question' => 0,
	'answered'     => 0,
	'honest'       => 0,
);

$scenarios = glob( __DIR__ . '/graded/*.json' );
$n         = 0;

foreach ( $scenarios as $file ) {
	$s = json_decode( file_get_contents( $file ), true );

	if ( ! is_array( $s ) || empty( $s['turns'] ) ) {
		continue;
	}

	// Budget guard: stop once month-to-date is already at or past the cap.
	if ( method_exists( $budget, 'month_to_date_myr' ) && method_exists( $budget, 'cap_myr' )
		&& $budget->month_to_date_myr() >= $budget->cap_myr() ) {
		echo "BUDGET CAP reached — stopping.\n";
		break;
	}

	$history    = array();
	$transcript = 'Customer language hint: ' . ( isset( $s['lang'] ) ? $s['lang'] : 'en' ) . "\n";

	foreach ( $s['turns'] as $turn ) {
		$res   = $orch->answer( $turn, $history, $tool_executor, $context, $pages );
		$reply = isset( $res['reply'] ) ? (string) $res['reply'] : '';

		$transcript .= "CUSTOMER: $turn\nASSISTANT: $reply\n";

		$history[] = array(
			'role'    => 'user',
			'content' => $turn,
		);
		$history[] = array(
			'role'    => 'assistant',
			'content' => $reply,
		);
	}

	$verdict_raw = $client->complete( $JUDGE_SYS, $transcript, 200 );
	$v           = json_decode( $verdict_raw, true );
	$n++;

	echo "\n== " . $s['name'] . " ==\n" . $transcript;

	if ( is_array( $v ) ) {
		foreach ( $totals as $k => $_ ) {
			if ( ! empty( $v[ $k ] ) ) {
				$totals[ $k ]++;
			}
		}
		echo 'JUDGE: ' . json_encode( $v ) . "\n";
	} else {
		echo "JUDGE: (unparseable) $verdict_raw\n";
	}
}

echo "\n=== GRADED TOTALS ($n scenarios) ===\n";

foreach ( $totals as $k => $c ) {
	echo "  $k: $c/$n\n";
}

if ( method_exists( $budget, 'month_to_date_myr' ) ) {
	echo '  month-to-date spend: RM' . number_format( (float) $budget->month_to_date_myr(), 2 ) . "\n";
}
