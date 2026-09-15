<?php
// Test doubles + minimal WP_Error/is_wp_error for pure orchestrator tests.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { public $code; public function __construct( $c = '', $m = '' ) { $this->code = $c; } }
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }

class FakeClient {
	private $key; private $replies; private $tool_results;
	public function __construct( $has_key, array $replies = array(), array $tool_results = array() ) {
		$this->key = (bool) $has_key; $this->replies = $replies; $this->tool_results = $tool_results;
	}
	public function has_api_key() { return $this->key; }
	public function chat( $messages, $context, $executor, $pages = '' ) {
		return empty( $this->replies ) ? '' : array_shift( $this->replies );
	}
	public function last_tool_results() { return $this->tool_results; }
	public function get_last_usage() { return array(); }
}
class FakeLookup {
	private $reply; public function __construct( $reply ) { $this->reply = $reply; }
	public function answer( $m, $h = array() ) { return array( 'reply' => $this->reply, 'products' => array(), 'pages' => array() ); }
	public function last_wants_human() { return false; }
}
class FakeBudget {
	private $ok; public function __construct( $ok ) { $this->ok = (bool) $ok; }
	public function can_spend() { return $this->ok; }
	public function record( $usage ) {}
	public function get_last_usage() { return array(); }
}
class FakeIndex { public function match_in_text( $reply ) { return array(); } }
