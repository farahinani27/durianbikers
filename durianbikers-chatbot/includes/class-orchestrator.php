<?php
defined( 'ABSPATH' ) || defined( 'DB_CHAT_TEST' ) || exit;

/**
 * Runs the AI conversation turn with a code-enforced grounding check, and
 * silently falls back to the keyword engine when AI is unavailable or errors.
 * Reuses DB_Chat_Messages (message building), DB_Chat_Grounding (fact check),
 * and the product/content indexes' match_in_text() (card/link resolution).
 */
final class DB_Chat_Orchestrator {

	private $client; private $lookup; private $budget; private $products; private $pages_index;

	public function __construct( $client, $lookup_engine, $budget, $product_index = null, $content_index = null ) {
		$this->client      = $client;
		$this->lookup      = $lookup_engine;
		$this->budget      = $budget;
		$this->products    = $product_index;
		$this->pages_index = $content_index;
	}

	public function can_use_ai() {
		return $this->client && $this->client->has_api_key() && $this->budget && $this->budget->can_spend();
	}

	/**
	 * @return array{reply:string,products:array,pages:array,source:string,grounding:string,escalate:bool}
	 */
	public function answer( $message, array $history, callable $tool_executor, $context = '', $pages = '', $image = null ) {
		if ( ! $this->can_use_ai() ) {
			return $this->fallback( $message, $history );
		}

		$messages = DB_Chat_Messages::build( $history, (string) $message );

		// A photo turn runs on the stronger vision model from the start (it must
		// read small logos / model text on the item), so it does NOT go through
		// the base->escalation retry below — it is already on the top model.
		$has_image  = is_array( $image ) && isset( $image['media_type'], $image['data'] );
		$vision_mdl = ( $has_image && method_exists( $this->client, 'escalation_model' ) ) ? (string) $this->client->escalation_model() : '';

		$reply = $this->client->chat( $messages, (string) $context, $tool_executor, (string) $pages, $vision_mdl, $has_image ? $image : null );

		if ( $this->budget ) {
			$this->budget->record( $this->client->get_last_usage() );
		}

		if ( is_wp_error( $reply ) ) {
			return $this->fallback( $message, $history );
		}

		$reply = (string) $reply;
		$facts = DB_Chat_Grounding::collect_facts( $this->client->last_tool_results() );
		$check = DB_Chat_Grounding::verify( $reply, $facts );

		if ( empty( $check['ok'] ) ) {
			// TIERED BRAIN. The base model's answer isn't grounded — usually
			// because it answered from memory instead of searching. Retry this
			// ONE turn on the more capable escalation model, with the same
			// tools, so it can search and answer from real data. Only turns
			// that fail on the base model pay for the escalation.
			// A photo turn already ran on the escalation/vision model, so there
			// is nowhere to escalate to — fall straight through to the safe line.
			$escalation = ( ! $has_image && method_exists( $this->client, 'escalation_model' ) ) ? (string) $this->client->escalation_model() : '';

			if ( '' !== $escalation ) {
				$retry = $this->client->chat( $messages, (string) $context, $tool_executor, (string) $pages, $escalation );

				if ( $this->budget ) {
					$this->budget->record( $this->client->get_last_usage() );
				}

				if ( ! is_wp_error( $retry ) ) {
					$retry   = (string) $retry;
					$facts2  = DB_Chat_Grounding::collect_facts( $this->client->last_tool_results() );
					$check2  = DB_Chat_Grounding::verify( $retry, $facts2 );

					if ( ! empty( $check2['ok'] ) ) {
						return $this->success_result( $retry, 'ok_escalated' );
					}
				}
			}

			// Base model (and the escalation model, if it was tried) both
			// produced an ungrounded reply. Never show an ungrounded price.
			return array(
				'reply'     => $this->safe_line(),
				'products'  => array(),
				'pages'     => array(),
				'source'    => 'ai',
				'grounding' => 'blocked',
				'escalate'  => true,
			);
		}

		return $this->success_result( $reply, 'ok' );
	}

	/**
	 * Build the success shape shared by the base and escalated paths: attach
	 * the product/page cards resolved from the (grounded) reply text.
	 *
	 * @param string $reply     The grounded reply.
	 * @param string $grounding 'ok' or 'ok_escalated'.
	 * @return array<string,mixed>
	 */
	private function success_result( $reply, $grounding ) {
		$products = ( $this->products && method_exists( $this->products, 'match_in_text' ) ) ? $this->products->match_in_text( $reply ) : array();
		$pages_o  = ( $this->pages_index && method_exists( $this->pages_index, 'match_in_text' ) ) ? $this->pages_index->match_in_text( $reply ) : array();

		return array(
			'reply'     => (string) $reply,
			'products'  => is_array( $products ) ? array_values( $products ) : array(),
			'pages'     => is_array( $pages_o ) ? array_values( $pages_o ) : array(),
			'source'    => 'ai',
			'grounding' => (string) $grounding,
			'escalate'  => false,
		);
	}

	private function fallback( $message, array $history ) {
		$r = ( $this->lookup && method_exists( $this->lookup, 'answer' ) ) ? $this->lookup->answer( $message, $history ) : array();
		$escalate = $this->lookup && method_exists( $this->lookup, 'last_wants_human' ) && $this->lookup->last_wants_human();
		return array(
			'reply'     => isset( $r['reply'] ) ? (string) $r['reply'] : '',
			'products'  => isset( $r['products'] ) && is_array( $r['products'] ) ? array_values( $r['products'] ) : array(),
			'pages'     => isset( $r['pages'] ) && is_array( $r['pages'] ) ? array_values( $r['pages'] ) : array(),
			'source'    => 'fallback',
			'grounding' => 'na',
			'escalate'  => (bool) $escalate,
		);
	}

	private function safe_line() {
		return __( "Let me double-check that for you — give me a moment. In the meantime, is there anything else I can help with? I can also get a team member if you'd prefer.", 'durianbikers-chatbot' );
	}
}
