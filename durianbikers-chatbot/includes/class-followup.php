<?php
/**
 * Customer email follow-ups for the human-handoff inbox.
 *
 * Two behaviours, both driven by one scheduled scan (WP-cron, every 5 min):
 *
 *   A. "We missed you" — a chat left WAITING for a human for longer than the
 *      idle threshold (default 15 min) with no agent reply gets ONE reassurance
 *      email so the customer isn't left hanging.
 *   B. Reply forwarding — when an agent replies from the inbox but the customer
 *      has left the chat (not seen polling for a couple of minutes), the reply
 *      is emailed to them so it doesn't sit unseen in a closed tab.
 *
 * Off by default (db_chat_followup = '0'): the recurring event is only ever
 * scheduled while the master toggle AND the handoff feature are on, so nothing
 * emails a customer until the owner deliberately turns it on. run() can still be
 * called with $force = true for a manual test while the toggle is off.
 *
 * Nothing here touches WooCommerce, the cart or checkout.
 *
 * @package durianbikers-chatbot
 */

defined( 'ABSPATH' ) || exit;

final class DB_Chat_Followup {

	const CRON_HOOK     = 'db_chat_followup_scan';
	const CRON_SCHEDULE = 'db_chat_5min';

	/**
	 * Wire the cron hook + schedule. Idempotent; safe to call once on boot.
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_run' ) );
		add_action( 'init', array( __CLASS__, 'sync_schedule' ) );
	}

	/** Register the 5-minute interval WP-cron doesn't ship by default. */
	public static function add_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::CRON_SCHEDULE ] ) ) {
			$schedules[ self::CRON_SCHEDULE ] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 5 minutes (Durian Bikers chatbot)', 'durianbikers-chatbot' ),
			);
		}
		return $schedules;
	}

	/**
	 * Keep the scheduled event in step with the toggle: schedule it when the
	 * feature is on and unschedule it when off, so turning the toggle off truly
	 * stops all customer emails (not just skips them).
	 */
	public static function sync_schedule() {
		$scheduled = wp_next_scheduled( self::CRON_HOOK );
		if ( self::enabled() ) {
			if ( ! $scheduled ) {
				wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK );
			}
		} elseif ( $scheduled ) {
			wp_unschedule_event( $scheduled, self::CRON_HOOK );
		}
	}

	/**
	 * Master switch. Requires BOTH the follow-up toggle and the handoff/inbox
	 * feature (which owns the conversations these emails are about).
	 */
	public static function enabled() {
		return '1' === get_option( 'db_chat_followup', '0' )
			&& '1' === get_option( 'db_chat_handoff_enabled', '0' );
	}

	/** The cron entry point — always honours the toggle. */
	public static function cron_run() {
		if ( ! self::enabled() ) {
			return;
		}
		self::run( false );
	}

	private static function idle_seconds() {
		return max( 60, (int) get_option( 'db_chat_followup_idle_minutes', 15 ) * MINUTE_IN_SECONDS );
	}

	private static function gone_seconds() {
		return max( 30, (int) get_option( 'db_chat_followup_gone_seconds', 120 ) );
	}

	/**
	 * Scan live handoff conversations and send any due follow-up emails.
	 *
	 * @param bool $force Bypass the master toggle (for a manual test run). The
	 *                    cron path always passes false.
	 * @return array Summary { candidates, missed_sent, reply_sent } or a skip reason.
	 */
	public static function run( $force = false ) {
		if ( ! $force && ! self::enabled() ) {
			return array( 'skipped' => 'disabled' );
		}
		if ( ! class_exists( 'DB_Chat_Conversation_Store' ) ) {
			return array( 'skipped' => 'no_store' );
		}

		$now      = time();
		$idle_cut = $now - self::idle_seconds();
		$gone_cut = $now - self::gone_seconds();

		$missed_sent = 0;
		$reply_sent  = 0;

		$rows = DB_Chat_Conversation_Store::followup_candidates( 100 );

		foreach ( $rows as $c ) {
			$cid   = (int) $c['id'];
			$email = trim( (string) $c['contact_email'] );
			if ( '' === $email || ! is_email( $email ) ) {
				continue;
			}
			$name = trim( (string) $c['contact_name'] );

			// --- B: forward agent replies the customer hasn't seen, once gone. ---
			$after = (int) $c['emailed_msg_id'];
			$agent = DB_Chat_Conversation_Store::messages_since( $cid, $after, array( 'agent' ) );

			if ( ! empty( $agent ) ) {
				$last_seen = ! empty( $c['last_seen_at'] ) ? strtotime( $c['last_seen_at'] . ' UTC' ) : 0;
				$gone      = ! $last_seen || $last_seen < $gone_cut;

				$all_max      = $after;
				$seen_max     = $after;
				$unseen_texts = array();

				foreach ( $agent as $m ) {
					$mid     = (int) $m['id'];
					$all_max = max( $all_max, $mid );
					$m_ts    = strtotime( (string) $m['created_at'] . ' UTC' );
					if ( $last_seen && $m_ts && $m_ts <= $last_seen ) {
						// Customer polled after this reply → they already have it.
						$seen_max = max( $seen_max, $mid );
					} else {
						$body = trim( (string) $m['body'] );
						if ( '' !== $body ) {
							$unseen_texts[] = $body;
						}
					}
				}

				if ( ! empty( $unseen_texts ) && $gone ) {
					if ( self::send_reply_email( $name, $email, $unseen_texts ) ) {
						$reply_sent++;
					}
					// Advance past everything so a bounce isn't retried forever.
					DB_Chat_Conversation_Store::set_emailed_msg_id( $cid, $all_max );
				} elseif ( ! empty( $unseen_texts ) ) {
					// Customer is still here: let them receive it live. Only mark
					// the replies they've already seen; recheck the rest next pass.
					if ( $seen_max > $after ) {
						DB_Chat_Conversation_Store::set_emailed_msg_id( $cid, $seen_max );
					}
				} else {
					// All already seen live → just advance the mark, no email.
					DB_Chat_Conversation_Store::set_emailed_msg_id( $cid, $all_max );
				}
			}

			// --- A: "we missed you" for a chat left waiting with no human reply. ---
			if ( DB_Chat_Conversation_Store::STATUS_WAITING === $c['status'] && empty( $c['notified_missed_at'] ) ) {
				$last_cust = ! empty( $c['last_customer_at'] ) ? strtotime( $c['last_customer_at'] . ' UTC' ) : 0;
				if ( $last_cust && $last_cust <= $idle_cut ) {
					if ( self::send_missed_email( $name, $email ) ) {
						$missed_sent++;
					}
					// Mark regardless of the send result so a hard-bouncing address
					// isn't retried on every scan.
					DB_Chat_Conversation_Store::mark_missed_notified( $cid );
				}
			}
		}

		return array(
			'candidates'  => count( $rows ),
			'missed_sent' => $missed_sent,
			'reply_sent'  => $reply_sent,
		);
	}

	/* ---------------------------------------------------------------------- */

	/**
	 * Email one agent reply to the customer NOW, on demand — used by the inbox's
	 * "Also email this reply" control. Independent of the master toggle and the
	 * cron (a deliberate operator action), it only needs a valid contact email.
	 *
	 * @param array  $conversation Conversation row (needs contact_email / contact_name).
	 * @param string $text         The agent's reply text.
	 * @return bool True if the email was accepted for delivery.
	 */
	public static function email_agent_reply( array $conversation, $text ) {
		$email = isset( $conversation['contact_email'] ) ? trim( (string) $conversation['contact_email'] ) : '';
		$text  = trim( (string) $text );
		if ( '' === $email || ! is_email( $email ) || '' === $text ) {
			return false;
		}
		$name = isset( $conversation['contact_name'] ) ? (string) $conversation['contact_name'] : '';
		return self::send_reply_email( $name, $email, array( $text ) );
	}

	private static function send_missed_email( $name, $email ) {
		$subject = __( 'We got your message — Durian Bikers', 'durianbikers-chatbot' );
		$body    = implode(
			"\n",
			array(
				sprintf( __( 'Hi %s,', 'durianbikers-chatbot' ), self::greeting_name( $name ) ),
				'',
				__( "Thanks for messaging Durian Bikers. Sorry we couldn't reply live just now — we've got your question and a team member will get back to you soon.", 'durianbikers-chatbot' ),
				'',
				sprintf(
					/* translators: %s is an optional " or reach us on WhatsApp: <url>" clause. */
					__( 'You can reply directly to this email%s.', 'durianbikers-chatbot' ),
					self::whatsapp_clause()
				),
				'',
				__( '— Durian Bikers', 'durianbikers-chatbot' ),
			)
		);
		return self::send( $email, $subject, $body );
	}

	private static function send_reply_email( $name, $email, array $texts ) {
		$subject = __( 'Reply from Durian Bikers', 'durianbikers-chatbot' );
		$body    = implode(
			"\n",
			array(
				sprintf( __( 'Hi %s,', 'durianbikers-chatbot' ), self::greeting_name( $name ) ),
				'',
				__( 'Here is a reply to your recent chat with us:', 'durianbikers-chatbot' ),
				'',
				'"' . implode( "\"\n\n\"", $texts ) . '"',
				'',
				__( 'Just reply to this email to continue.', 'durianbikers-chatbot' ),
				'',
				__( '— Durian Bikers', 'durianbikers-chatbot' ),
			)
		);
		return self::send( $email, $subject, $body );
	}

	private static function greeting_name( $name ) {
		$name = trim( (string) $name );
		return '' !== $name ? $name : __( 'there', 'durianbikers-chatbot' );
	}

	/** " or reach us on WhatsApp: <url>" when a handoff WhatsApp URL is set, else "". */
	private static function whatsapp_clause() {
		$url = '';
		if ( class_exists( 'DB_Chat_Handoff' ) && is_callable( array( 'DB_Chat_Handoff', 'get_script_data' ) ) ) {
			try {
				$data = DB_Chat_Handoff::get_script_data();
				if ( ! empty( $data['url'] ) ) {
					$url = (string) $data['url'];
				}
			} catch ( Throwable $e ) {
				$url = '';
			}
		}
		return $url ? sprintf( __( ', or reach us on WhatsApp: %s', 'durianbikers-chatbot' ), $url ) : '';
	}

	/** The Reply-To for follow-up emails; falls back to the site send-from address. */
	private static function reply_to() {
		$addr = trim( (string) get_option( 'db_chat_followup_reply_to', '' ) );
		if ( '' === $addr || ! is_email( $addr ) ) {
			$addr = 'info@durianbikers.com';
		}
		return $addr;
	}

	private static function send( $to, $subject, $body ) {
		$headers = array(
			'Content-Type: text/plain; charset=UTF-8',
			'Reply-To: ' . self::reply_to(),
		);
		return (bool) wp_mail( $to, $subject, $body, $headers );
	}
}
