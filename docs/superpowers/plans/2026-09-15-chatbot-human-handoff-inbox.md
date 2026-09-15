# Chatbot Human Handoff & Inbox (Phase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the customer chatbot hand a conversation to a human who replies inside the same website chat widget, managed from a new WP admin Chatbot Inbox, with staff alerted on the existing notification channels.

**Architecture:** A persistence layer (two custom tables) stores conversations and messages. The existing `/chat` endpoint gains status-awareness (AI answers only while `bot`). New public REST routes handle handoff and polling; new admin REST routes + an admin page drive the inbox. Alerts reuse `DB_Chat_Order_Notifier::dispatch()`. Everything is gated by a master toggle, off by default, and never touches cart/checkout.

**Tech Stack:** PHP 8.3, WordPress/WooCommerce, `$wpdb` + `dbDelta()`, plugin REST namespace `durianbikers/v1`, vanilla JS widget (`assets/chatbot.js`), WP-cron.

**Spec:** `docs/superpowers/specs/2026-09-15-chatbot-human-handoff-inbox-design.md`

## Global Constraints

- **Master toggle:** option `db_chat_handoff_enabled` (`'0'`/`'1'`), **default `'0'`**. When off, the plugin behaves exactly as today (stateless bot); no tables read/written on the hot path beyond a single cached option check.
- **Never touch checkout:** no new hooks on cart/checkout/order/`woocommerce_*`. All new code is on its own REST routes, tables, admin page, and cron.
- **No local WP / no phpunit.** Verify each task with: (a) `php -l <file>` locally AND on the server; (b) `wp eval` smoke scripts run on the live install via `ssh -i ~/.ssh/durianbikers_vps -p 8288 durianbi@103.233.1.74 '/opt/plesk/php/8.3/bin/php -d memory_limit=768M /usr/local/bin/wp eval "…" --path=$SITE'` where `SITE=/var/www/vhosts/durianbikers.com/staging.durianbikers.com`; (c) manual checks on the logged-out test link `https://durianbikers.com/my/chatbot-test/?key=<db_chat_test_key>`. Smoke scripts that write rows must delete their own test rows.
- **Deploy per task, behind the OFF toggle:** upload changed files via ssh-stdin, `cp -p` a `.bak.<ts>` first, `php -l` on the server. Since there is no local WP, "run the test" for a task means deploy then run its `wp eval` smoke script. Customers never see partial work because the feature is gated off.
- **DB writes use `$wpdb->prepare`** for every value; table names via `$wpdb->prefix . 'dbchat_...'`.
- **REST auth:** public widget routes use the existing nonce pattern (header `X-DBChat-Nonce`, issued by `GET /session`, retried on `db_chat_stale_nonce`/`rest_cookie_invalid_nonce`). Admin routes require `current_user_can('manage_woocommerce')` + `wp_rest` nonce.
- **Names are fixed and shared** (copy verbatim): tables `dbchat_conversations`, `dbchat_messages`; options `db_chat_handoff_enabled`, `db_chat_handoff_notify`, `db_chat_handoff_retention_days` (default `90`), `db_chat_handoff_poll_seconds` (default `5`), `db_chat_db_version`; cron hook `db_chat_handoff_purge`; statuses `bot`/`waiting`/`human`/`resolved`; senders `customer`/`bot`/`agent`/`system`.

## File structure

**Create:**
- `includes/class-conversation-store.php` — persistence: schema + migration, CRUD, polling query, purge. Static class `DB_Chat_Conversation_Store`.
- `includes/class-handoff-endpoint.php` — public REST routes `POST /handoff`, `GET /messages`. Class `DB_Chat_Handoff_Endpoint`.
- `includes/class-handoff-alert.php` — builds + dispatches the staff alert. Class `DB_Chat_Handoff_Alert`.
- `includes/class-inbox-admin.php` — admin menu page + admin REST routes (list/get/take/reply/resolve) + enqueues. Class `DB_Chat_Inbox_Admin`.
- `assets/inbox.js`, `assets/inbox.css` — the admin inbox UI.

**Modify:**
- `includes/class-chat-endpoint.php` — persist messages; skip AI when `waiting`/`human`; return `conversation_id`, `escalate`, `last_message_id`; extend `/session` to issue `public_id`.
- `includes/class-lookup-engine.php` — the `human` intent exposes an escalation signal (`last_wants_human()`).
- `includes/class-settings.php` — register 4 new options + a "Human handoff" settings section.
- `durianbikers-chatbot.php` — require new files (guarded list), instantiate endpoint/admin classes, run `maybe_upgrade()` + register cron, seed new options on activation.

---

## Task 1: Conversation store (schema, migration, CRUD, polling, purge)

**Files:**
- Create: `includes/class-conversation-store.php`
- Test: server `wp eval` smoke script (below)

**Interfaces:**
- Produces:
  - `DB_Chat_Conversation_Store::STATUS_BOT`, `STATUS_WAITING`, `STATUS_HUMAN`, `STATUS_RESOLVED` (string consts)
  - `maybe_upgrade(): void` — creates/updates tables when `db_chat_db_version` < `SCHEMA_VERSION`
  - `ensure(string $public_id, array $seed = []): array` — returns a conversation row (existing by valid public_id, else a new one; row has `id`, `public_id`, `status`, …)
  - `get(int $id): ?array`
  - `add_message(int $conversation_id, string $sender, string $body, ?int $author_user_id = null): int`
  - `messages_since(int $conversation_id, int $since_id, array $senders = array()): array`
  - `set_status(int $conversation_id, string $status): void`
  - `assign(int $conversation_id, int $user_id): void`
  - `set_contact(int $conversation_id, string $type, string $value): void`
  - `list_conversations(string $status = '', int $limit = 50, int $offset = 0): array`
  - `transcript(int $conversation_id): array`
  - `purge(int $days): int`

- [ ] **Step 1: Create the class with constants and table-name helpers**

Create `includes/class-conversation-store.php`:

```php
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

	const SCHEMA_VERSION = 1;

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
}
```

- [ ] **Step 2: `php -l` the new file**

Run: `php -l includes/class-conversation-store.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Add schema creation + version-checked migration**

Add inside the class:

```php
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
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY conversation_id (conversation_id, id)
			) {$charset};"
		);

		update_option( 'db_chat_db_version', self::SCHEMA_VERSION );
	}
```

- [ ] **Step 4: Add `ensure()`, `get()`, `add_message()`**

```php
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

	/**
	 * Append a message and touch the conversation's activity timestamps.
	 *
	 * @return int New message id.
	 */
	public static function add_message( $conversation_id, $sender, $body, $author_user_id = null ) {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$wpdb->insert(
			self::messages_table(),
			array(
				'conversation_id' => (int) $conversation_id,
				'sender'          => (string) $sender,
				'author_user_id'  => $author_user_id ? (int) $author_user_id : null,
				'body'            => (string) $body,
				'created_at'      => $now,
			),
			array( '%d', '%s', '%d', '%s', '%s' )
		);

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
```

- [ ] **Step 5: Add `messages_since()`, `set_status()`, `assign()`, `set_contact()`, `list_conversations()`, `transcript()`, `purge()`**

```php
	/**
	 * Messages with id greater than $since_id, optionally filtered by sender.
	 *
	 * @param string[] $senders e.g. array('agent','system'); empty = all.
	 * @return array<int,array>
	 */
	public static function messages_since( $conversation_id, $since_id, array $senders = array() ) {
		global $wpdb;

		$sql    = 'SELECT id, sender, body, created_at FROM ' . self::messages_table()
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
	 * Newest-updated conversations, optionally filtered by status.
	 *
	 * @return array<int,array>
	 */
	public static function list_conversations( $status = '', $limit = 50, $offset = 0 ) {
		global $wpdb;

		$sql    = 'SELECT * FROM ' . self::conversations_table();
		$params = array();

		if ( '' !== $status ) {
			$sql     .= ' WHERE status = %s';
			$params[] = (string) $status;
		}

		$sql     .= ' ORDER BY updated_at DESC LIMIT %d OFFSET %d';
		$params[] = (int) $limit;
		$params[] = (int) $offset;

		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) ?: array();
	}

	/** @return array<int,array> All messages, oldest first. */
	public static function transcript( $conversation_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, sender, author_user_id, body, created_at FROM ' . self::messages_table()
				. ' WHERE conversation_id = %d ORDER BY id ASC',
				(int) $conversation_id
			),
			ARRAY_A
		) ?: array();
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
```

- [ ] **Step 6: `php -l` locally, then deploy behind the OFF toggle and force the migration**

Run locally: `php -l includes/class-conversation-store.php` → `No syntax errors`.
Deploy the file (ssh-stdin, `.bak`, server `php -l`). Add the `require_once` in the main plugin file's guarded optional list (see Task 8 wires the rest) — for this task, temporarily require it and call `maybe_upgrade()` from a smoke script.

- [ ] **Step 7: Smoke test on the server (creates + verifies + deletes its own rows)**

Run (server `wp eval`):

```php
require_once WP_PLUGIN_DIR . '/durianbikers-chatbot/includes/class-conversation-store.php';
DB_Chat_Conversation_Store::maybe_upgrade();
$c = DB_Chat_Conversation_Store::ensure( '', array( 'lang' => 'en', 'page_url' => 'https://x/test' ) );
$m1 = DB_Chat_Conversation_Store::add_message( $c['id'], 'customer', 'hello' );
$m2 = DB_Chat_Conversation_Store::add_message( $c['id'], 'bot', 'hi there' );
$m3 = DB_Chat_Conversation_Store::add_message( $c['id'], 'agent', 'a human here', 1 );
$since = DB_Chat_Conversation_Store::messages_since( $c['id'], $m1, array( 'agent', 'system' ) );
echo 'ensure_ok=' . ( preg_match('/^[a-f0-9]{32}$/', $c['public_id']) ? 'Y' : 'N' ) . "\n";
echo 'poll_returns_agent_only=' . ( count($since) === 1 && $since[0]['sender'] === 'agent' ? 'Y' : 'N' ) . "\n";
DB_Chat_Conversation_Store::set_status( $c['id'], 'waiting' );
DB_Chat_Conversation_Store::assign( $c['id'], 1 );
$re = DB_Chat_Conversation_Store::get( $c['id'] );
echo 'assigned_human=' . ( $re['status'] === 'human' && (int)$re['assigned_user_id'] === 1 ? 'Y' : 'N' ) . "\n";
global $wpdb;
$wpdb->query( $wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'dbchat_messages WHERE conversation_id=%d', $c['id']) );
$wpdb->query( $wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'dbchat_conversations WHERE id=%d', $c['id']) );
echo "cleaned\n";
```

Expected output: `ensure_ok=Y`, `poll_returns_agent_only=Y`, `assigned_human=Y`, `cleaned`.

- [ ] **Step 8: Commit**

```bash
git add includes/class-conversation-store.php
git commit -m "feat(chatbot): conversation+message store for human handoff"
```

---

## Task 2: Make the chat flow status-aware (persist + pause AI when handed off)

**Files:**
- Modify: `includes/class-chat-endpoint.php`
- Test: server `wp eval` + manual on the test link

**Interfaces:**
- Consumes: `DB_Chat_Conversation_Store::ensure/get/add_message/STATUS_*` (Task 1).
- Produces: `/chat` response now includes `conversation_id` (public), `escalate` (bool), `last_message_id` (int); `/session` returns `conversation_id`. A private helper `handoff_enabled(): bool`.

- [ ] **Step 1: Add a feature-flag helper and read the public_id on `/chat`**

In `class-chat-endpoint.php`, add near the other private helpers:

```php
	/** Master toggle for the whole handoff feature. Cheap, cached option read. */
	private function handoff_enabled() {
		return '1' === get_option( 'db_chat_handoff_enabled', '0' );
	}
```

- [ ] **Step 2: In the `/chat` handler, resolve the conversation and gate the AI**

Locate the `/chat` REST callback (registered at `class-chat-endpoint.php:186`). At the top, after the message is sanitized and BEFORE the engine runs, insert:

```php
		$conversation = null;

		if ( $this->handoff_enabled() ) {
			$public_id    = isset( $params['conversation_id'] ) && is_string( $params['conversation_id'] ) ? $params['conversation_id'] : '';
			$conversation = DB_Chat_Conversation_Store::ensure(
				$public_id,
				array( 'lang' => '', 'page_url' => isset( $params['page_url'] ) ? (string) $params['page_url'] : '' )
			);

			DB_Chat_Conversation_Store::add_message( (int) $conversation['id'], 'customer', $message );

			// Handed off → the human owns this conversation; do not run the AI.
			if ( in_array( $conversation['status'], array( DB_Chat_Conversation_Store::STATUS_WAITING, DB_Chat_Conversation_Store::STATUS_HUMAN ), true ) ) {
				return $this->apply_no_store(
					rest_ensure_response(
						array(
							'ack'             => true,
							'status'          => $conversation['status'],
							'conversation_id' => $conversation['public_id'],
							'last_message_id' => 0,
						)
					)
				);
			}
		}
```

- [ ] **Step 3: Persist the bot reply and add fields to the normal response**

Find every place the `/chat` callback returns the normal `{ reply, products, pages, pending }` response (the lookup answer path AND the `maybe_answer_with_ai` path both ultimately return through the callback — persist at the callback level). Immediately before returning, when `$conversation` is set, persist the bot reply and append fields:

```php
		if ( null !== $conversation ) {
			$bot_text = isset( $response_data['reply'] ) ? (string) $response_data['reply'] : '';
			$mid      = $bot_text !== '' ? DB_Chat_Conversation_Store::add_message( (int) $conversation['id'], 'bot', $bot_text ) : 0;

			$response_data['conversation_id'] = $conversation['public_id'];
			$response_data['escalate']        = ! empty( $escalate );
			$response_data['last_message_id'] = $mid;
		}
```

> Implementation note for the executor: the callback currently builds a `rest_ensure_response( array( 'reply' => …, 'products' => …, 'pages' => …, 'pending' => … ) )` in more than one branch. Refactor those into a single `$response_data` array returned at the end of the callback, so the block above runs once. `$escalate` is produced in Task 5; until then it is always false — declare `$escalate = false;` at the top of the callback.

- [ ] **Step 4: Extend `/session` to issue a public_id**

In the `/session` callback (registered at `class-chat-endpoint.php:242`), when `handoff_enabled()`, create/resolve a conversation from the incoming `conversation_id` query arg and add it to the response:

```php
		$out = array( 'nonce' => $nonce, 'handoff' => array( 'isOpen' => DB_Chat_Handoff::is_open() ) );

		if ( $this->handoff_enabled() ) {
			$public_id            = isset( $request['conversation_id'] ) ? (string) $request['conversation_id'] : '';
			$conversation         = DB_Chat_Conversation_Store::ensure( $public_id );
			$out['conversation_id'] = $conversation['public_id'];
			$out['handoff']['enabled'] = true;
			$out['handoff']['pollSeconds'] = max( 2, (int) get_option( 'db_chat_handoff_poll_seconds', 5 ) );
		}

		return rest_ensure_response( $out );
```

(Keep the exact existing shape for the non-handoff case.)

- [ ] **Step 5: `php -l` and deploy behind the OFF toggle**

Run: `php -l includes/class-chat-endpoint.php` → `No syntax errors`. Deploy (`.bak`, server `php -l`).

- [ ] **Step 6: Smoke test — with the toggle ON, a `waiting` conversation gets no AI reply**

Run (server `wp eval`): enable the toggle in-memory by option, drive the endpoint's logic through the engine is heavy; instead assert the gate directly:

```php
update_option('db_chat_handoff_enabled','1');
require_once WP_PLUGIN_DIR . '/durianbikers-chatbot/includes/class-conversation-store.php';
$c = DB_Chat_Conversation_Store::ensure('');
DB_Chat_Conversation_Store::set_status($c['id'],'waiting');
$fresh = DB_Chat_Conversation_Store::get($c['id']);
echo 'is_waiting=' . ($fresh['status']==='waiting'?'Y':'N') . "\n";
// cleanup
global $wpdb; $wpdb->query($wpdb->prepare('DELETE FROM '.$wpdb->prefix.'dbchat_conversations WHERE id=%d',$c['id']));
update_option('db_chat_handoff_enabled','0');
echo "ok\n";
```

Expected: `is_waiting=Y`, `ok`. (Full request-path behaviour is verified manually in Task 9.)

- [ ] **Step 7: Commit**

```bash
git add includes/class-chat-endpoint.php
git commit -m "feat(chatbot): persist messages and pause AI when handed off"
```

---

## Task 3: Public handoff + polling endpoints

**Files:**
- Create: `includes/class-handoff-endpoint.php`
- Test: server `wp eval` + manual

**Interfaces:**
- Consumes: `DB_Chat_Conversation_Store` (Task 1), `DB_Chat_Handoff_Alert::send()` (Task 4 — guard with `class_exists`/`method_exists` so this task deploys before Task 4).
- Produces: routes `POST durianbikers/v1/handoff`, `GET durianbikers/v1/messages`. Class `DB_Chat_Handoff_Endpoint` with `__construct()` that hooks `rest_api_init`.

- [ ] **Step 1: Create the class and register routes (mirroring chat-endpoint's nonce checks)**

```php
<?php
/**
 * Public REST routes for the human handoff: raise a handoff, and poll for new
 * agent/system messages. Same nonce discipline as the chat endpoint. Only
 * active when db_chat_handoff_enabled.
 *
 * @package durianbikers-chatbot
 */

defined( 'ABSPATH' ) || exit;

final class DB_Chat_Handoff_Endpoint {

	const NS = 'durianbikers/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	private function enabled() {
		return '1' === get_option( 'db_chat_handoff_enabled', '0' );
	}

	public function register_routes() {
		register_rest_route( self::NS, '/handoff', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_handoff' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/messages', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_messages' ),
			'permission_callback' => '__return_true',
		) );
	}
}
```

- [ ] **Step 2: Implement `handle_handoff()`**

```php
	public function handle_handoff( WP_REST_Request $request ) {
		if ( ! $this->enabled() ) {
			return new WP_Error( 'db_chat_handoff_off', 'Handoff is not enabled.', array( 'status' => 404 ) );
		}

		$public_id = (string) $request->get_param( 'conversation_id' );
		$conv      = DB_Chat_Conversation_Store::ensure( $public_id );

		// Already with a human/queue — don't re-alert.
		$already = in_array( $conv['status'], array( DB_Chat_Conversation_Store::STATUS_WAITING, DB_Chat_Conversation_Store::STATUS_HUMAN ), true );

		$type  = (string) $request->get_param( 'contact_type' );
		$value = (string) $request->get_param( 'contact_value' );
		if ( in_array( $type, array( 'email', 'whatsapp' ), true ) && '' !== trim( $value ) ) {
			$clean = 'email' === $type ? sanitize_email( $value ) : preg_replace( '/[^0-9+ ]/', '', $value );
			if ( '' !== $clean ) {
				DB_Chat_Conversation_Store::set_contact( (int) $conv['id'], $type, $clean );
			}
		}

		if ( ! $already ) {
			DB_Chat_Conversation_Store::set_status( (int) $conv['id'], DB_Chat_Conversation_Store::STATUS_WAITING );
			DB_Chat_Conversation_Store::add_message( (int) $conv['id'], 'system', 'Conversation handed to a team member.' );

			if ( class_exists( 'DB_Chat_Handoff_Alert' ) ) {
				DB_Chat_Handoff_Alert::send( (int) $conv['id'] );
			}
		}

		return rest_ensure_response( array( 'status' => DB_Chat_Conversation_Store::STATUS_WAITING, 'conversation_id' => $conv['public_id'] ) );
	}
```

- [ ] **Step 3: Implement `handle_messages()` (polling)**

```php
	public function handle_messages( WP_REST_Request $request ) {
		if ( ! $this->enabled() ) {
			return new WP_Error( 'db_chat_handoff_off', 'Handoff is not enabled.', array( 'status' => 404 ) );
		}

		$public_id = (string) $request->get_param( 'conversation_id' );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $public_id ) ) {
			return new WP_Error( 'db_chat_bad_conversation', 'Unknown conversation.', array( 'status' => 400 ) );
		}

		$conv = DB_Chat_Conversation_Store::ensure( $public_id );
		$since = (int) $request->get_param( 'since' );

		$messages = DB_Chat_Conversation_Store::messages_since( (int) $conv['id'], $since, array( 'agent', 'system' ) );

		$last = $since;
		foreach ( $messages as $m ) {
			$last = max( $last, (int) $m['id'] );
		}

		return rest_ensure_response( array(
			'status'          => $conv['status'],
			'messages'        => $messages,
			'last_message_id' => $last,
		) );
	}
```

- [ ] **Step 4: `php -l` and deploy behind the OFF toggle**

`php -l includes/class-handoff-endpoint.php` → clean. Add its `require_once` + `new DB_Chat_Handoff_Endpoint();` in Task 8 (or temporarily instantiate for the smoke test). Deploy.

- [ ] **Step 5: Smoke test — handoff sets waiting + system message; poll returns it**

Run (server `wp eval`):

```php
update_option('db_chat_handoff_enabled','1');
foreach (['class-conversation-store','class-handoff-endpoint'] as $f) require_once WP_PLUGIN_DIR."/durianbikers-chatbot/includes/$f.php";
$c = DB_Chat_Conversation_Store::ensure('');
$ep = new DB_Chat_Handoff_Endpoint();
$req = new WP_REST_Request('POST','/durianbikers/v1/handoff');
$req->set_param('conversation_id',$c['public_id']);
$req->set_param('contact_type','email'); $req->set_param('contact_value','a@b.com');
$res = $ep->handle_handoff($req);
$data = $res->get_data();
$poll = new WP_REST_Request('GET','/durianbikers/v1/messages');
$poll->set_param('conversation_id',$c['public_id']); $poll->set_param('since',0);
$pd = $ep->handle_messages($poll)->get_data();
echo 'status=' . $data['status'] . "\n";
echo 'system_msg_polled=' . ( count($pd['messages'])>=1 && $pd['messages'][0]['sender']==='system' ? 'Y':'N' ) . "\n";
global $wpdb; $wpdb->query($wpdb->prepare('DELETE FROM '.$wpdb->prefix.'dbchat_messages WHERE conversation_id=%d',$c['id']));
$wpdb->query($wpdb->prepare('DELETE FROM '.$wpdb->prefix.'dbchat_conversations WHERE id=%d',$c['id']));
update_option('db_chat_handoff_enabled','0'); echo "ok\n";
```

Expected: `status=waiting`, `system_msg_polled=Y`, `ok`.

- [ ] **Step 6: Commit**

```bash
git add includes/class-handoff-endpoint.php
git commit -m "feat(chatbot): public handoff + polling REST endpoints"
```

---

## Task 4: Staff alerts on handoff (reuse the notifier system) + settings

**Files:**
- Create: `includes/class-handoff-alert.php`
- Modify: `includes/class-settings.php`
- Test: server `wp eval` with a fake notifier

**Interfaces:**
- Consumes: `DB_Chat_Order_Notifier::dispatch( string )`, `DB_Chat_Conversation_Store` (Task 1).
- Produces: `DB_Chat_Handoff_Alert::send( int $conversation_id ): void`; 4 new registered options with a "Human handoff" settings section.

- [ ] **Step 1: Create the alert builder**

```php
<?php
/**
 * Builds and dispatches the "a customer needs a human" alert to whichever
 * notification channels the owner has enabled. Reuses the order-notifier fan-out.
 *
 * @package durianbikers-chatbot
 */

defined( 'ABSPATH' ) || exit;

final class DB_Chat_Handoff_Alert {

	public static function send( $conversation_id ) {
		if ( '1' !== get_option( 'db_chat_handoff_notify', '0' ) ) {
			return;
		}
		if ( ! class_exists( 'DB_Chat_Order_Notifier' ) ) {
			return;
		}

		$conv = DB_Chat_Conversation_Store::get( (int) $conversation_id );
		if ( ! $conv ) {
			return;
		}

		$transcript = DB_Chat_Conversation_Store::transcript( (int) $conversation_id );
		$last_q     = '';
		foreach ( array_reverse( $transcript ) as $m ) {
			if ( 'customer' === $m['sender'] ) {
				$last_q = $m['body'];
				break;
			}
		}

		$contact = '';
		if ( ! empty( $conv['contact_value'] ) ) {
			$contact = sprintf( "\nContact (%s): %s", $conv['contact_type'], $conv['contact_value'] );
		}

		$link = admin_url( 'admin.php?page=db-chat-inbox&conversation=' . (int) $conversation_id );

		$message = sprintf(
			"🔔 A customer needs a human on Durian Bikers chat.\n\nTheir question: %s%s\n\nOpen the inbox: %s",
			'' !== $last_q ? $last_q : '(no text)',
			$contact,
			$link
		);

		DB_Chat_Order_Notifier::dispatch( $message );
	}
}
```

- [ ] **Step 2: Register the 4 options in settings**

In `class-settings.php` `register_settings()`, alongside the existing `self::register(...)` calls, add:

```php
		self::register( 'db_chat_handoff_enabled', '0', array( __CLASS__, 'sanitize_bool' ) );
		self::register( 'db_chat_handoff_notify', '0', array( __CLASS__, 'sanitize_bool' ) );
		self::register( 'db_chat_handoff_retention_days', '90', array( __CLASS__, 'sanitize_int' ) );
		self::register( 'db_chat_handoff_poll_seconds', '5', array( __CLASS__, 'sanitize_int' ) );
```

If `sanitize_bool`/`sanitize_int` do not already exist in the class, add them:

```php
	public static function sanitize_bool( $v ): string { return '1' === (string) $v || 1 === $v || true === $v ? '1' : '0'; }
	public static function sanitize_int( $v ): string { return (string) max( 0, (int) $v ); }
```

- [ ] **Step 3: Render a "Human handoff" section on the settings screen**

In the settings page renderer, add a fieldset with: master toggle (`db_chat_handoff_enabled`), "Alert staff when a customer needs a human" (`db_chat_handoff_notify`), retention days, poll seconds. Follow the exact markup pattern the screen already uses for a checkbox + number field (copy an existing field block and swap the option name/label). Include help text: "Alerts use the channels configured in Order notifications below."

- [ ] **Step 4: `php -l` both files and deploy behind OFF**

`php -l includes/class-handoff-alert.php includes/class-settings.php` → clean. Deploy.

- [ ] **Step 5: Smoke test the alert with a fake notifier**

Run (server `wp eval`):

```php
update_option('db_chat_handoff_notify','1');
foreach (['class-conversation-store','class-handoff-alert'] as $f) require_once WP_PLUGIN_DIR."/durianbikers-chatbot/includes/$f.php";
require_once WP_PLUGIN_DIR.'/durianbikers-chatbot/includes/class-order-notifier.php';
$GLOBALS['__sent']=null;
add_filter('db_chat_notifiers', function($ns){ $GLOBALS['__fake']=new class { function get_id(){return 'fake';} function get_label(){return 'Fake';} function is_enabled(){return true;} function send($m){ $GLOBALS['__sent']=$m; return true; } }; return array($GLOBALS['__fake']); });
$c=DB_Chat_Conversation_Store::ensure('');
DB_Chat_Conversation_Store::add_message($c['id'],'customer','how do I fit this?');
DB_Chat_Handoff_Alert::send($c['id']);
echo 'alert_built=' . ( is_string($GLOBALS['__sent']) && strpos($GLOBALS['__sent'],'needs a human')!==false ? 'Y':'N' ) . "\n";
global $wpdb; $wpdb->query($wpdb->prepare('DELETE FROM '.$wpdb->prefix.'dbchat_messages WHERE conversation_id=%d',$c['id']));
$wpdb->query($wpdb->prepare('DELETE FROM '.$wpdb->prefix.'dbchat_conversations WHERE id=%d',$c['id']));
update_option('db_chat_handoff_notify','0'); echo "ok\n";
```

> Executor note: confirm the exact filter name the order-notifier uses to collect notifiers (grep `apply_filters` in `class-order-notifier.php`). If it is not `db_chat_notifiers`, use the real one, or skip the fake-injection and instead assert `DB_Chat_Handoff_Alert::send()` runs without error and returns void when `db_chat_handoff_notify` is off vs on.

Expected: `alert_built=Y`, `ok`.

- [ ] **Step 6: Commit**

```bash
git add includes/class-handoff-alert.php includes/class-settings.php
git commit -m "feat(chatbot): staff alert on handoff + handoff settings"
```

---

## Task 5: Engine escalation signal (explicit "I want a human")

**Files:**
- Modify: `includes/class-lookup-engine.php`, `includes/class-chat-endpoint.php`
- Test: server `wp eval`

**Interfaces:**
- Consumes: existing `human` intent resolution in the engine.
- Produces: `DB_Chat_Lookup_Engine::last_wants_human(): bool`; `$escalate` wired in the `/chat` callback.

- [ ] **Step 1: Add an `advice_miss`-style flag for the human intent**

In `class-lookup-engine.php`: add `private $wants_human = false;`, reset it to `false` at the top of `answer()` (next to the other resets), and set `$this->wants_human = true;` in the `case 'human':` branch of the intent switch. Add:

```php
	public function last_wants_human() {
		return (bool) $this->wants_human;
	}
```

- [ ] **Step 2: Wire `$escalate` in the chat callback**

In `class-chat-endpoint.php` `/chat` callback, after the engine has answered and before persisting (Task 2, Step 3), set:

```php
		$escalate = $this->handoff_enabled()
			&& is_callable( array( $this->lookup_engine, 'last_wants_human' ) )
			&& $this->lookup_engine->last_wants_human();
```

- [ ] **Step 3: `php -l`, deploy, smoke test**

Run (server `wp eval`):

```php
$e = db_chat()->lookup_engine();
$e->answer('can I talk to a human please?');
echo 'wants_human=' . ($e->last_wants_human()?'Y':'n') . "\n";
$e->answer('do you have shinko tyres?');
echo 'not_on_product=' . ($e->last_wants_human()?'Y':'n') . "\n";
```

Expected: `wants_human=Y`, `not_on_product=n`.

- [ ] **Step 4: Commit**

```bash
git add includes/class-lookup-engine.php includes/class-chat-endpoint.php
git commit -m "feat(chatbot): engine exposes human-escalation signal"
```

---

## Task 6: Widget — identity, handoff button, contact field, polling, render agent messages

**Files:**
- Modify: `assets/chatbot.js`, `assets/chatbot.css`
- Test: manual on the logged-out test link (Task 9 covers the full pass; this task ships the code)

**Interfaces:**
- Consumes: `/session` (`conversation_id`, `handoff.enabled`, `handoff.pollSeconds`), `/chat` (`conversation_id`, `escalate`, `last_message_id`), `GET /messages`, `POST /handoff`.

- [ ] **Step 1: Persist and send the conversation id**

Add a module-scoped `var conversationId = null;` and `var lastMessageId = 0;`. On `/session` success, set `conversationId = data.conversation_id || null` and persist to `localStorage` under key `dbchat_cid` (wrapped in try/catch). On load, seed `conversationId` from `localStorage` and pass it as a `conversation_id` query param to `/session`. Include `conversation_id: conversationId` in every `/chat` POST body.

- [ ] **Step 2: Handle escalate + a "Talk to a human" control**

- Add a persistent "Talk to a human" button/link in the widget footer (only when `handoffEnabled` from `/session`).
- On a `/chat` response with `escalate === true`, render an inline prompt: "Want me to bring in a team member?" with a confirm action.
- Both the button and the confirm open the **contact step**.

- [ ] **Step 3: Contact step + POST /handoff**

Render an inline field: "Leave your email or WhatsApp number so we can reply (optional)" with Send and Skip. On submit/skip, `POST /handoff` with `conversation_id` and, if provided, `contact_type` (detect `@` → email else whatsapp) + `contact_value`. On success, set local state to `waiting`, show "A team member will reply here shortly.", and **start polling**.

- [ ] **Step 4: Polling loop**

```js
var pollTimer = null;
function startPolling() {
  if (pollTimer || !handoffEnabled || !conversationId) return;
  var ms = Math.max(2000, (pollSeconds || 5) * 1000);
  pollTimer = setInterval(fetchNewMessages, ms);
}
function stopPolling() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }
function fetchNewMessages() {
  if (!isOpen) return; // only while the panel is open
  var url = ajaxBase + '/messages?conversation_id=' + encodeURIComponent(conversationId) + '&since=' + lastMessageId;
  fetch(url, { headers: nonceHeaders(), credentials: 'same-origin' })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (d) {
      if (!d) return;
      (d.messages || []).forEach(function (m) {
        renderTeamMessage(m); // 'agent' → Team bubble, 'system' → subtle note
        lastMessageId = Math.max(lastMessageId, m.id);
      });
      currentStatus = d.status;
      if (d.status === 'resolved') { stopPolling(); }
    })
    .catch(function () {});
}
```

Start polling when status becomes `waiting`/`human`; `stopPolling()` on panel close and on `resolved`; resume on panel open if status is still `waiting`/`human`.

- [ ] **Step 5: Render agent/system messages + CSS**

Add `renderTeamMessage(m)`: an `agent` message renders as a bubble labelled "Team"; a `system` message renders as a centered subtle line. Add matching classes to `chatbot.css`.

- [ ] **Step 6: `php -l` is N/A; sanity-check JS**

Run: `node --check assets/chatbot.js` (if `node` is available) else visually confirm no syntax errors. Deploy `chatbot.js` + `chatbot.css`.

- [ ] **Step 7: Commit**

```bash
git add assets/chatbot.js assets/chatbot.css
git commit -m "feat(chatbot): widget handoff button, contact capture, polling"
```

---

## Task 7: Admin Chatbot Inbox (page + REST + UI)

**Files:**
- Create: `includes/class-inbox-admin.php`, `assets/inbox.js`, `assets/inbox.css`
- Test: manual in wp-admin + server `wp eval` for the REST callbacks

**Interfaces:**
- Consumes: `DB_Chat_Conversation_Store` (Task 1).
- Produces: admin menu `db-chat-inbox`; REST routes under `durianbikers/v1/inbox/*` gated by `manage_woocommerce`.

- [ ] **Step 1: Class skeleton — menu + enqueue + route registration**

```php
<?php
/**
 * WP-admin "Chatbot Inbox": list handed-off conversations, read the transcript,
 * take one, reply into the widget, resolve. Staff-only (manage_woocommerce).
 *
 * @package durianbikers-chatbot
 */

defined( 'ABSPATH' ) || exit;

final class DB_Chat_Inbox_Admin {

	const CAP = 'manage_woocommerce';
	const NS  = 'durianbikers/v1';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	private function enabled() { return '1' === get_option( 'db_chat_handoff_enabled', '0' ); }

	public function menu() {
		if ( ! $this->enabled() ) { return; }
		add_menu_page( 'Chatbot Inbox', 'Chatbot Inbox', self::CAP, 'db-chat-inbox', array( $this, 'render_page' ), 'dashicons-format-chat', 58 );
	}

	public function assets( $hook ) {
		if ( 'toplevel_page_db-chat-inbox' !== $hook ) { return; }
		wp_enqueue_style( 'db-chat-inbox', plugins_url( 'assets/inbox.css', dirname( __FILE__ ) . '/../durianbikers-chatbot.php' ), array(), DB_CHAT_VERSION );
		wp_enqueue_script( 'db-chat-inbox', plugins_url( 'assets/inbox.js', dirname( __FILE__ ) . '/../durianbikers-chatbot.php' ), array(), DB_CHAT_VERSION, true );
		wp_localize_script( 'db-chat-inbox', 'dbChatInbox', array(
			'root'  => esc_url_raw( rest_url( self::NS . '/inbox' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		) );
	}

	public function render_page() {
		echo '<div class="wrap"><h1>Chatbot Inbox</h1><div id="db-chat-inbox-app">Loading…</div></div>';
	}
}
```

> Executor note: verify the `plugins_url(...)` base resolves correctly against the plugin root; if the existing code has a `DB_CHAT_URL` constant, use `DB_CHAT_URL . 'assets/inbox.js'` instead (grep the main file).

- [ ] **Step 2: Register the admin REST routes with a capability check**

```php
	public function routes() {
		$perm = function () { return current_user_can( self::CAP ); };

		register_rest_route( self::NS, '/inbox/list', array(
			'methods' => 'GET', 'permission_callback' => $perm, 'callback' => array( $this, 'r_list' ),
		) );
		register_rest_route( self::NS, '/inbox/conversation', array(
			'methods' => 'GET', 'permission_callback' => $perm, 'callback' => array( $this, 'r_get' ),
		) );
		register_rest_route( self::NS, '/inbox/take', array(
			'methods' => 'POST', 'permission_callback' => $perm, 'callback' => array( $this, 'r_take' ),
		) );
		register_rest_route( self::NS, '/inbox/reply', array(
			'methods' => 'POST', 'permission_callback' => $perm, 'callback' => array( $this, 'r_reply' ),
		) );
		register_rest_route( self::NS, '/inbox/resolve', array(
			'methods' => 'POST', 'permission_callback' => $perm, 'callback' => array( $this, 'r_resolve' ),
		) );
	}
```

- [ ] **Step 3: Implement the five callbacks**

```php
	public function r_list( WP_REST_Request $req ) {
		$status = (string) $req->get_param( 'status' );
		$rows   = DB_Chat_Conversation_Store::list_conversations( in_array( $status, array( 'waiting', 'human', 'bot', 'resolved' ), true ) ? $status : '' );
		return rest_ensure_response( array( 'conversations' => $rows ) );
	}

	public function r_get( WP_REST_Request $req ) {
		$id = (int) $req->get_param( 'id' );
		$c  = DB_Chat_Conversation_Store::get( $id );
		if ( ! $c ) { return new WP_Error( 'not_found', 'No such conversation', array( 'status' => 404 ) ); }
		return rest_ensure_response( array( 'conversation' => $c, 'messages' => DB_Chat_Conversation_Store::transcript( $id ) ) );
	}

	public function r_take( WP_REST_Request $req ) {
		$id = (int) $req->get_param( 'id' );
		DB_Chat_Conversation_Store::assign( $id, get_current_user_id() );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function r_reply( WP_REST_Request $req ) {
		$id   = (int) $req->get_param( 'id' );
		$body = trim( (string) $req->get_param( 'body' ) );
		if ( '' === $body ) { return new WP_Error( 'empty', 'Empty reply', array( 'status' => 400 ) ); }
		$c = DB_Chat_Conversation_Store::get( $id );
		if ( ! $c ) { return new WP_Error( 'not_found', 'No such conversation', array( 'status' => 404 ) ); }
		if ( DB_Chat_Conversation_Store::STATUS_HUMAN !== $c['status'] ) {
			DB_Chat_Conversation_Store::assign( $id, get_current_user_id() );
		}
		$mid = DB_Chat_Conversation_Store::add_message( $id, 'agent', $body, get_current_user_id() );
		return rest_ensure_response( array( 'ok' => true, 'id' => $mid ) );
	}

	public function r_resolve( WP_REST_Request $req ) {
		$id = (int) $req->get_param( 'id' );
		DB_Chat_Conversation_Store::add_message( $id, 'system', 'Conversation resolved.' );
		DB_Chat_Conversation_Store::set_status( $id, DB_Chat_Conversation_Store::STATUS_RESOLVED );
		return rest_ensure_response( array( 'ok' => true ) );
	}
```

- [ ] **Step 4: Build `assets/inbox.js` and `assets/inbox.css`**

`inbox.js` (vanilla, no build step): on load, GET `inbox/list`; render the left list with status badges; clicking a row GETs `inbox/conversation` and renders the transcript on the right + a reply box + Take/Resolve buttons; Reply POSTs `inbox/reply` then refreshes the transcript; auto-refresh the list every ~5s. All fetches send header `X-WP-Nonce: dbChatInbox.nonce` and `credentials:'same-origin'`. `inbox.css`: a two-column layout, status badge colours, message bubbles. Keep it minimal and mobile-friendly (single column under ~700px).

- [ ] **Step 5: `php -l` the PHP file; deploy all three**

`php -l includes/class-inbox-admin.php` → clean. Deploy `class-inbox-admin.php`, `assets/inbox.js`, `assets/inbox.css`.

- [ ] **Step 6: Smoke test the callbacks + verify the page loads**

Run (server `wp eval`, acting as admin user 1):

```php
update_option('db_chat_handoff_enabled','1');
foreach (['class-conversation-store','class-inbox-admin'] as $f) require_once WP_PLUGIN_DIR."/durianbikers-chatbot/includes/$f.php";
wp_set_current_user(1);
$a = new DB_Chat_Inbox_Admin();
$c = DB_Chat_Conversation_Store::ensure(''); DB_Chat_Conversation_Store::set_status($c['id'],'waiting');
$req=new WP_REST_Request('POST','/durianbikers/v1/inbox/reply'); $req->set_param('id',$c['id']); $req->set_param('body','hello from staff');
$a->r_reply($req);
$fresh=DB_Chat_Conversation_Store::get($c['id']);
$tr=DB_Chat_Conversation_Store::transcript($c['id']);
echo 'became_human=' . ($fresh['status']==='human'?'Y':'N') . "\n";
echo 'agent_msg_stored=' . ( $tr[count($tr)-1]['sender']==='agent' ? 'Y':'N' ) . "\n";
global $wpdb; $wpdb->query($wpdb->prepare('DELETE FROM '.$wpdb->prefix.'dbchat_messages WHERE conversation_id=%d',$c['id']));
$wpdb->query($wpdb->prepare('DELETE FROM '.$wpdb->prefix.'dbchat_conversations WHERE id=%d',$c['id']));
update_option('db_chat_handoff_enabled','0'); echo "ok\n";
```

Expected: `became_human=Y`, `agent_msg_stored=Y`, `ok`. Then, in a browser logged in as admin with the toggle temporarily on, confirm **Chatbot Inbox** appears in the menu and the page renders.

- [ ] **Step 7: Commit**

```bash
git add includes/class-inbox-admin.php assets/inbox.js assets/inbox.css
git commit -m "feat(chatbot): admin Chatbot Inbox page + REST"
```

---

## Task 8: Wire everything in the main plugin file + retention cron

**Files:**
- Modify: `durianbikers-chatbot.php`
- Test: server `wp eval`

**Interfaces:**
- Consumes: all classes above.
- Produces: files required; `maybe_upgrade()` on load; endpoint/admin instances; cron `db_chat_handoff_purge`; activation seeds the 4 options.

- [ ] **Step 1: Require the new files (guarded optional list)**

Add to the `$db_chat_optional_file` array (the guarded requires around `class-lookup-engine.php`): `includes/class-conversation-store.php`, `includes/class-handoff-alert.php`, `includes/class-handoff-endpoint.php`, `includes/class-inbox-admin.php`.

- [ ] **Step 2: Run the migration on load + instantiate the new services**

After the requires, where the plugin boots (e.g. on `plugins_loaded` or in the constructor that already `new`s the endpoint), add:

```php
	if ( class_exists( 'DB_Chat_Conversation_Store' ) ) {
		DB_Chat_Conversation_Store::maybe_upgrade();
	}
	if ( class_exists( 'DB_Chat_Handoff_Endpoint' ) ) {
		new DB_Chat_Handoff_Endpoint();
	}
	if ( class_exists( 'DB_Chat_Inbox_Admin' ) ) {
		new DB_Chat_Inbox_Admin();
	}
```

> Executor note: follow the file's existing instantiation style. The chat endpoint is already constructed somewhere near `DB_Chat_Plugin` boot — instantiate the two new services in the same place, unconditionally requiring nothing that the guarded requires might have skipped (hence the `class_exists` guards).

- [ ] **Step 3: Schedule + handle the retention cron**

```php
	add_action( 'db_chat_handoff_purge', function () {
		if ( class_exists( 'DB_Chat_Conversation_Store' ) ) {
			DB_Chat_Conversation_Store::purge( (int) get_option( 'db_chat_handoff_retention_days', 90 ) );
		}
	} );

	add_action( 'init', function () {
		if ( '1' === get_option( 'db_chat_handoff_enabled', '0' ) && ! wp_next_scheduled( 'db_chat_handoff_purge' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'db_chat_handoff_purge' );
		}
	} );
```

- [ ] **Step 4: Seed the 4 options on activation + clear cron on deactivation**

In the `register_activation_hook` closure add `add_option( 'db_chat_handoff_enabled', '0' );` and the other three defaults. In the deactivation closure add `wp_clear_scheduled_hook( 'db_chat_handoff_purge' );`.

- [ ] **Step 5: `php -l`, deploy, verify boot + migration + cron scheduling**

Run (server `wp eval`):

```php
echo 'store_loaded=' . (class_exists('DB_Chat_Conversation_Store')?'Y':'N') . "\n";
echo 'endpoint_loaded=' . (class_exists('DB_Chat_Handoff_Endpoint')?'Y':'N') . "\n";
echo 'admin_loaded=' . (class_exists('DB_Chat_Inbox_Admin')?'Y':'N') . "\n";
echo 'db_version=' . get_option('db_chat_db_version') . "\n";
global $wpdb; $t=$wpdb->prefix.'dbchat_conversations';
echo 'table_exists=' . ($wpdb->get_var("SHOW TABLES LIKE '$t'")===$t?'Y':'N') . "\n";
```

Expected: all `Y`, `db_version=1`, `table_exists=Y`.

- [ ] **Step 6: Commit**

```bash
git add durianbikers-chatbot.php
git commit -m "feat(chatbot): wire handoff services, migration, retention cron"
```

---

## Task 9: End-to-end verification on the test link + enable

**Files:** none (verification + rollout)

- [ ] **Step 1: Turn the feature ON on the live install (still staff/test-gated widget)**

`wp option update db_chat_handoff_enabled 1`, `wp option update db_chat_handoff_notify 1` (server). Purge LiteSpeed cache.

- [ ] **Step 2: Full manual pass on the logged-out test link**

In an incognito window on `https://durianbikers.com/my/chatbot-test/?key=<db_chat_test_key>`:
1. Chat normally → AI answers, product cards still work (regression).
2. Tap **Talk to a human** (or ask "can I talk to a person?") → contact prompt appears.
3. Leave an email → widget shows "a team member will reply shortly"; polling starts (check Network tab: `GET /messages` every ~5s).
4. In wp-admin (as staff), open **Chatbot Inbox** → the conversation shows as **Needs human** with the question + contact; confirm the alert arrived on Telegram/email.
5. **Take** it, type a reply, Send → within ~5s the reply appears in the incognito widget as a **Team** bubble.
6. Send a customer message → it appears in the inbox; the **bot does not auto-reply**.
7. **Resolve** → widget stops polling; a later customer message is answered by the **bot** again.

- [ ] **Step 3: Regression check with the toggle OFF**

`wp option update db_chat_handoff_enabled 0`; on the test link confirm the widget behaves exactly as before (no polling, no handoff button, AI answers, cards) — proving the feature is cleanly gated.

- [ ] **Step 4: Decide rollout**

Leave `db_chat_handoff_enabled` per the operator's choice (recommended: keep ON only once staff know to watch the inbox; otherwise OFF until then). Record the decision.

- [ ] **Step 5: Commit any doc updates**

```bash
git add DEPLOY.md
git commit -m "docs(chatbot): human handoff runbook"
```

---

## Self-review notes

- **Spec coverage:** data model → Task 1; lifecycle/status gating → Tasks 1–2; endpoints (widget) → Tasks 2–3; escalation triggers → Task 5 (+ button/contact in Task 6); widget changes → Task 6; admin inbox → Task 7; notifications → Task 4; settings → Task 4/8; privacy/retention → Task 8; testing → per-task smoke + Task 9; deployment/toggle → Global Constraints + Tasks 8–9.
- **Testing adaptation:** no phpunit exists in this repo; verification is `php -l` + server `wp eval` smoke scripts (self-cleaning) + the manual test-link pass. This is intentional and matches the project.
- **Cross-task names:** store method signatures in Task 1 are the ones every later task calls; option/route/status/sender names are fixed in Global Constraints and reused verbatim.
- **Executor notes** flag the three spots that must be checked against the live code (single-return refactor in `/chat`; the notifier filter name; the plugin-url/constant for asset URLs and the boot/instantiation point).
