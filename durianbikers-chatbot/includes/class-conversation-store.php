<?php
/**
 * Conversation + message persistence for the human-handoff inbox.
 *
 * Two custom tables, owned entirely by this class. Isolated from the store /
 * checkout: nothing here hooks WooCommerce. Only touched when the handoff
 * feature is enabled (callers gate on db_chat_handoff_enabled).
 *
 * @package durianbikers-chatbot
 */

defined( 'ABSPATH' ) || exit;

final class DB_Chat_Conversation_Store {

	const SCHEMA_VERSION = 3;

	const STATUS_BOT      = 'bot';
	const STATUS_WAITING  = 'waiting';
	const STATUS_HUMAN    = 'human';
	const STATUS_RESOLVED = 'resolved';

	/** @return string */
	private static function conversations_table() {
		global $wpdb;
		return $wpdb->prefix . 'dbchat_conversations';
	}

	/** @return string */
	private static function messages_table() {
		global $wpdb;
		return $wpdb->prefix . 'dbchat_messages';
	}

	/**
	 * Create/upgrade tables when the stored schema version is behind. Runs on
	 * every load (cheap: one option read) because deployment is a file upload,
	 * so the activation hook does NOT re-run on an update.
	 */
	public static function maybe_upgrade() {
		$installed = (int) get_option( 'db_chat_db_version', 0 );

		if ( $installed >= self::SCHEMA_VERSION ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$conv    = self::conversations_table();
		$msg     = self::messages_table();

		dbDelta(
			"CREATE TABLE {$conv} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				public_id CHAR(32) NOT NULL,
				status VARCHAR(16) NOT NULL DEFAULT 'bot',
				assigned_user_id BIGINT UNSIGNED NULL,
				contact_type VARCHAR(16) NULL,
				contact_value VARCHAR(191) NULL,
				contact_name VARCHAR(191) NULL,
				contact_email VARCHAR(191) NULL,
				contact_phone VARCHAR(64) NULL,
				lang VARCHAR(8) NULL,
				page_url VARCHAR(255) NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				last_customer_at DATETIME NULL,
				last_agent_at DATETIME NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY status (status),
				KEY updated_at (updated_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$msg} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				conversation_id BIGINT UNSIGNED NOT NULL,
				sender VARCHAR(16) NOT NULL,
				author_user_id BIGINT UNSIGNED NULL,
				body TEXT NOT NULL,
				image_media_type VARCHAR(32) NULL,
				image_data LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY conversation_id (conversation_id, id)
			) {$charset};"
		);

		update_option( 'db_chat_db_version', self::SCHEMA_VERSION );
	}

	/**
	 * Fetch a conversation by its public token, or create a fresh one. An
	 * invalid/absent token yields a NEW conversation (never an error), so the
	 * widget always gets a usable row.
	 *
	 * @param string $public_id 32-char token from the browser ('' to force new).
	 * @param array  $seed      Optional lang/page_url for a new row.
	 * @return array Conversation row.
	 */
	public static function ensure( $public_id, array $seed = array() ) {
		global $wpdb;

		$public_id = is_string( $public_id ) ? $public_id : '';

		if ( preg_match( '/^[a-f0-9]{32}$/', $public_id ) ) {
			$row = $wpdb->get_row(
				$wpdb->prepare( 'SELECT * FROM ' . self::conversations_table() . ' WHERE public_id = %s', $public_id ),
				ARRAY_A
			);

			if ( $row ) {
				return $row;
			}
		}

		$now   = current_time( 'mysql', true );
		$token = bin2hex( random_bytes( 16 ) );

		$wpdb->insert(
			self::conversations_table(),
			array(
				'public_id'  => $token,
				'status'     => self::STATUS_BOT,
				'lang'       => isset( $seed['lang'] ) ? substr( (string) $seed['lang'], 0, 8 ) : null,
				'page_url'   => isset( $seed['page_url'] ) ? substr( (string) $seed['page_url'], 0, 255 ) : null,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return self::get( (int) $wpdb->insert_id );
	}

	/** @return array|null */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::conversations_table() . ' WHERE id = %d', (int) $id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	/** Look up a conversation by its public token WITHOUT creating one. Null if unknown. */
	public static function get_by_public_id( $public_id ) {
		global $wpdb;

		if ( ! preg_match( '/^[a-f0-9]{32}$/', is_string( $public_id ) ? $public_id : '' ) ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::conversations_table() . ' WHERE public_id = %s', $public_id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Append a message and touch the conversation's activity timestamps.
	 *
	 * @return int New message id.
	 */
	public static function add_message( $conversation_id, $sender, $body, $author_user_id = null, $image = null ) {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$data    = array(
			'conversation_id' => (int) $conversation_id,
			'sender'          => (string) $sender,
			'author_user_id'  => $author_user_id ? (int) $author_user_id : null,
			'body'            => (string) $body,
			'created_at'      => $now,
		);
		$formats = array( '%d', '%s', '%d', '%s', '%s' );

		// A photo the customer attached, so the inbox (and the customer's own
		// reloaded chat) can show it. Already validated by
		// DB_Chat_Chat_Endpoint::sanitize_image() before it reaches here.
		if ( is_array( $image ) && isset( $image['media_type'], $image['data'] ) ) {
			$data['image_media_type'] = (string) $image['media_type'];
			$data['image_data']       = (string) $image['data'];
			$formats[]                = '%s';
			$formats[]                = '%s';
		}

		$wpdb->insert( self::messages_table(), $data, $formats );

		$touch = array( 'updated_at' => $now );
		if ( 'customer' === $sender ) {
			$touch['last_customer_at'] = $now;
		} elseif ( 'agent' === $sender ) {
			$touch['last_agent_at'] = $now;
		}

		$wpdb->update(
			self::conversations_table(),
			$touch,
			array( 'id' => (int) $conversation_id ),
			array_fill( 0, count( $touch ), '%s' ),
			array( '%d' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Messages with id greater than $since_id, optionally filtered by sender.
	 *
	 * @param string[] $senders e.g. array('agent','system'); empty = all.
	 * @return array<int,array>
	 */
	public static function messages_since( $conversation_id, $since_id, array $senders = array() ) {
		global $wpdb;

		$sql    = 'SELECT id, sender, body, image_media_type, image_data, created_at FROM ' . self::messages_table()
			. ' WHERE conversation_id = %d AND id > %d';
		$params = array( (int) $conversation_id, (int) $since_id );

		if ( ! empty( $senders ) ) {
			$place  = implode( ',', array_fill( 0, count( $senders ), '%s' ) );
			$sql   .= ' AND sender IN (' . $place . ')';
			$params = array_merge( $params, array_values( $senders ) );
		}

		$sql .= ' ORDER BY id ASC LIMIT 200';

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) ?: array();
	}

	public static function set_status( $conversation_id, $status ) {
		global $wpdb;
		$wpdb->update(
			self::conversations_table(),
			array( 'status' => (string) $status, 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => (int) $conversation_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	public static function assign( $conversation_id, $user_id ) {
		global $wpdb;
		$wpdb->update(
			self::conversations_table(),
			array(
				'assigned_user_id' => (int) $user_id,
				'status'           => self::STATUS_HUMAN,
				'updated_at'       => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $conversation_id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Hand a conversation back to the bot: clear whoever had it assigned and
	 * drop the status back to 'bot'. The inverse of assign() above.
	 */
	public static function assign_to_bot( $conversation_id ) {
		global $wpdb;
		$wpdb->update(
			self::conversations_table(),
			array(
				'assigned_user_id' => null,
				'status'           => self::STATUS_BOT,
				'updated_at'       => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $conversation_id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	public static function set_contact( $conversation_id, $type, $value ) {
		global $wpdb;
		$wpdb->update(
			self::conversations_table(),
			array(
				'contact_type'  => substr( (string) $type, 0, 16 ),
				'contact_value' => substr( (string) $value, 0, 191 ),
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $conversation_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Store the full contact-capture form (name optional, email required by
	 * the endpoint, phone optional) gathered at handoff time. Separate from
	 * the older set_contact() (single email-or-whatsapp field), which stays
	 * in place but unused going forward.
	 */
	public static function set_contact_details( $conversation_id, $name, $email, $phone ) {
		global $wpdb;
		$wpdb->update(
			self::conversations_table(),
			array(
				'contact_name'  => substr( (string) $name, 0, 191 ),
				'contact_email' => substr( (string) $email, 0, 191 ),
				'contact_phone' => substr( (string) $phone, 0, 64 ),
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $conversation_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Newest-updated conversations, optionally filtered by status.
	 *
	 * @return array<int,array>
	 */
	public static function list_conversations( $status = '', $limit = 50, $offset = 0 ) {
		global $wpdb;

		$conv = self::conversations_table();
		$msg  = self::messages_table();

		// Each row carries `preview`: the first 200 chars of the customer's most
		// recent message, so the inbox list can show what they actually asked
		// ("hi, do you have…") instead of only who they are. Correlated subquery
		// — one query for the whole page, no N+1. %s ('customer') is the FIRST
		// placeholder in the SQL text, so it must be the first bound value.
		$sql    = 'SELECT c.*, ( SELECT LEFT( m.body, 200 ) FROM ' . $msg
			. ' m WHERE m.conversation_id = c.id AND m.sender = %s ORDER BY m.id DESC LIMIT 1 ) AS preview FROM '
			. $conv . ' c';
		$params = array( 'customer' );

		if ( '' !== $status ) {
			$sql     .= ' WHERE c.status = %s';
			$params[] = (string) $status;
		}

		$sql     .= ' ORDER BY c.updated_at DESC LIMIT %d OFFSET %d';
		$params[] = (int) $limit;
		$params[] = (int) $offset;

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) ?: array();
	}

	/** @return array<int,array> All messages, oldest first. */
	public static function transcript( $conversation_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, sender, author_user_id, body, image_media_type, image_data, created_at FROM ' . self::messages_table()
				. ' WHERE conversation_id = %d ORDER BY id ASC',
				(int) $conversation_id
			),
			ARRAY_A
		) ?: array();
	}

	/** @return int Number of conversations currently STATUS_WAITING. */
	public static function count_waiting() {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::conversations_table() . ' WHERE status = %s',
				self::STATUS_WAITING
			)
		);
	}

	/**
	 * Hard-delete one conversation and its messages. Unlike purge() (age +
	 * status gated, batch), this removes a single conversation by id
	 * regardless of status — used by the inbox's per-conversation Delete
	 * button, which is a deliberate, explicit operator action.
	 *
	 * @param int $conversation_id
	 * @return bool True if a conversation row was deleted.
	 */
	public static function delete_conversation( $conversation_id ) {
		global $wpdb;

		$conversation_id = (int) $conversation_id;
		if ( $conversation_id <= 0 ) {
			return false;
		}

		$wpdb->delete( self::messages_table(), array( 'conversation_id' => $conversation_id ), array( '%d' ) );
		$deleted = $wpdb->delete( self::conversations_table(), array( 'id' => $conversation_id ), array( '%d' ) );

		return (bool) $deleted;
	}

	/**
	 * Delete resolved or abandoned-bot conversations (and their messages) whose
	 * last activity is older than $days. Returns the number of conversations removed.
	 */
	public static function purge( $days ) {
		global $wpdb;

		$days   = max( 1, (int) $days );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM ' . self::conversations_table()
				. " WHERE updated_at < %s AND status IN ('" . self::STATUS_RESOLVED . "','" . self::STATUS_BOT . "') LIMIT 500",
				$cutoff
			)
		);

		if ( empty( $ids ) ) {
			return 0;
		}

		$place = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::messages_table() . ' WHERE conversation_id IN (' . $place . ')', $ids ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::conversations_table() . ' WHERE id IN (' . $place . ')', $ids ) );

		return count( $ids );
	}
}
