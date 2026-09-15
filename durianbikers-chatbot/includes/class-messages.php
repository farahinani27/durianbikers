<?php
defined( 'ABSPATH' ) || defined( 'DB_CHAT_TEST' ) || exit;

/**
 * Builds the Anthropic messages array from the customer's history + current
 * message. One shared implementation for the endpoint and the orchestrator.
 * Caps context to the last MAX_TURNS turns and scrubs card-like PII from every
 * content string before it can reach the model.
 */
final class DB_Chat_Messages {

	const MAX_TURNS = 20;

	/**
	 * @param array  $history [{role:'user'|'assistant', content:string}, ...]
	 * @param string $message The current customer message.
	 * @return array [{role, content}, ...]
	 */
	public static function build( array $history, $message ) {
		if ( count( $history ) > self::MAX_TURNS ) {
			$history = array_slice( $history, -self::MAX_TURNS );
		}

		$messages = array();

		foreach ( $history as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['role'], $entry['content'] ) ) {
				continue;
			}
			$role    = 'assistant' === $entry['role'] ? 'assistant' : 'user';
			$content = self::scrub( (string) $entry['content'] );

			// Drop assistant turns before the first user turn.
			if ( empty( $messages ) && 'assistant' === $role ) {
				continue;
			}

			$last = count( $messages ) - 1;
			if ( $last >= 0 && $messages[ $last ]['role'] === $role ) {
				$messages[ $last ]['content'] .= "\n\n" . $content;
				continue;
			}

			$messages[] = array( 'role' => $role, 'content' => $content );
		}

		$message = self::scrub( (string) $message );
		$last    = count( $messages ) - 1;
		if ( $last >= 0 && 'user' === $messages[ $last ]['role'] ) {
			$messages[ $last ]['content'] .= "\n\n" . $message;
		} else {
			$messages[] = array( 'role' => 'user', 'content' => $message );
		}

		return $messages;
	}

	private static function scrub( $text ) {
		return class_exists( 'DB_Chat_PII' ) ? DB_Chat_PII::scrub( $text ) : $text;
	}
}
