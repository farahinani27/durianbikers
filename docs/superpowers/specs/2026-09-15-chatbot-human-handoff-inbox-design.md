# Chatbot Human Handoff & Inbox — Phase 1 Design

**Date:** 2026-09-15
**Component:** `durianbikers-chatbot` WordPress plugin
**Status:** Approved design, pre-implementation

## Goal

Let the customer chatbot hand a conversation to a human. The AI answers as it
does today; when it cannot help, or the customer asks for a person, or the
customer taps a "Talk to a human" button, the conversation is handed off. A
staff member picks it up from a new **Chatbot Inbox** in WP admin and replies
**inside the same website chat widget**, in near-real-time. Staff are alerted on
the channels already configured (Telegram / email / WhatsApp / Slack).

## Scope

**In scope (Phase 1):**
- Server-side storage of conversations and messages.
- Handoff via a widget button *and* AI escalation.
- Contact capture (email or WhatsApp) at handoff.
- In-widget delivery of agent replies via polling.
- A WP admin Chatbot Inbox (list, transcript, Take, Reply, Resolve).
- Staff alerts on handoff, reusing the existing notifier system.
- Retention purge + a privacy-policy note.

**Out of scope (Phase 2, separate spec later):**
- True two-way continuation on WhatsApp / Telegram (official channel APIs,
  Chatwoot or equivalent, own server).
- Real-time push (websockets); Phase 1 uses polling.
- Agent presence / typing indicators / canned replies / teams / SLAs.

## Non-negotiable constraints

- **Never risk the live store or checkout.** All new code lives on its own REST
  routes, its own tables, and its own admin page. Nothing hooks the cart,
  checkout, or order flow. The notifier is already fire-and-forget
  (`blocking=false`, `timeout=2`).
- **The AI never asks for personal details.** Contact capture is a widget UI
  element the customer fills in voluntarily, not the AI prompting for PII. The
  system prompt's "never ask for personal details" rule is unchanged.
- **LiteSpeed-safe.** REST routes are dynamic (uncached). Nonces continue to be
  issued by `GET /session` (never baked into cached HTML). Polling is protected
  by the conversation `public_id` + nonce.

## Data model

Two new tables, prefix `{$wpdb->prefix}dbchat_`, created on activation /
version-bump via `dbDelta()`.

### `dbchat_conversations`
| column | type | notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AUTO | internal sequential id, never exposed |
| `public_id` | CHAR(32) | random token; what the browser stores and sends |
| `status` | VARCHAR(16) | `bot` \| `waiting` \| `human` \| `resolved` |
| `assigned_user_id` | BIGINT UNSIGNED NULL | WP user handling it |
| `contact_type` | VARCHAR(16) NULL | `email` \| `whatsapp` |
| `contact_value` | VARCHAR(191) NULL | customer-provided contact |
| `lang` | VARCHAR(8) NULL | detected reply language |
| `page_url` | VARCHAR(255) NULL | page the customer was on at handoff (context) |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | any activity |
| `last_customer_at` | DATETIME NULL | |
| `last_agent_at` | DATETIME NULL | |

Indexes: `UNIQUE(public_id)`, `KEY(status)`, `KEY(updated_at)`.

### `dbchat_messages`
| column | type | notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AUTO | |
| `conversation_id` | BIGINT UNSIGNED | FK (logical) |
| `sender` | VARCHAR(16) | `customer` \| `bot` \| `agent` \| `system` |
| `author_user_id` | BIGINT UNSIGNED NULL | set for `agent` |
| `body` | TEXT | message text (plain) |
| `created_at` | DATETIME | |

Index: `KEY(conversation_id, id)` — makes "messages since id X" a single fast
range scan (the polling query).

## Conversation lifecycle (state machine)

```
        first customer message
                 │
                 ▼
             [ bot ] ──────── AI answers; every customer+bot message persisted
                 │
   handoff (button or AI escalate)
                 │  widget shows contact field; system msg; staff alerted
                 ▼
           [ waiting ] ────── AI PAUSED; customer messages stored, not answered
                 │
        staff clicks "Take"
                 │
                 ▼
            [ human ] ─────── agent replies (persisted); AI stays paused
                 │
        staff clicks "Resolve"
                 │
                 ▼
          [ resolved ] ────── a later customer message reopens as [ bot ]
```

### Handoff triggers (what sets `escalate` / moves to `waiting`)
1. **Button** — the customer taps "Talk to a human" (always available). Direct
   `POST /handoff`.
2. **Explicit request** — the customer's message asks for a person, detected by a
   rule-based phrase list in the existing lookup engine (English + Malay:
   "talk to a human/person/agent", "customer service", "cakap dengan orang",
   "nak jumpa staff", etc.). The engine already has a `human` intent
   (`wants_human_zh` + a human keyword group) — reuse/extend it: instead of only
   pointing at the WhatsApp button, it now returns `escalate:true`.
3. **AI decline (soft, optional in Phase 1):** when the hybrid AI answer is a
   genuine "I can't help with that, a person can" case, the reply may carry
   `escalate:true`. Kept conservative; the button and explicit request are the
   primary paths.

The widget, on `escalate:true`, shows the "Talk to a human" confirmation + the
contact field rather than handing off silently — the customer still chooses.

Rules:
- The AI runs **only** when `status = bot`. In `waiting`/`human`, `POST /chat`
  stores the customer's message and returns an ack with **no bot reply**.
- Handoff sets `waiting`, records the contact (if given), writes a `system`
  message ("Handed to a team member"), and fires alerts once (idempotent — a
  second handoff on an already-waiting/human conversation does not re-alert).
- Resolve writes a `system` message and sets `resolved`; the next customer
  message creates activity under `bot` again (AI resumes, may re-escalate).

## Endpoints

### Widget (public REST, nonce-protected as today)
- `POST /wp-json/durianbikers/v1/chat` — **extended.** Body gains
  `conversation_id` (the `public_id`). Behaviour:
  - Resolve/lazily-create the conversation from `public_id`.
  - Persist the customer message.
  - If `status = bot`: run the engine exactly as now, persist the bot reply,
    return `{ reply, products, pages, escalate, conversation_id, last_message_id }`.
    `escalate: true` when the engine/AI decided a human is needed.
  - If `status = waiting|human`: persist only; return
    `{ ack: true, status, last_message_id }`, no bot reply.
- `GET /wp-json/durianbikers/v1/messages?conversation_id=…&since=<id>` — returns
  `{ status, messages: [{id, sender, body, created_at}], last_message_id }` for
  ids greater than `since`, sender in (`agent`,`system`). Used by polling.
- `POST /wp-json/durianbikers/v1/handoff` — body: `conversation_id`,
  optional `contact_type`+`contact_value`, optional `reason`. Sets `waiting`,
  stores contact, alerts staff. Returns `{ status }`.
- `GET /wp-json/durianbikers/v1/session` — **extended** to also issue/return the
  `public_id` (create a conversation row lazily or return an existing one).

### Admin (WP admin, capability `manage_woocommerce`)
Served via REST routes gated by `current_user_can('manage_woocommerce')` + admin
nonce (or `admin-ajax`, whichever matches existing plugin patterns):
- list conversations (filter by status, newest first, paginated)
- get one conversation with full transcript
- `take` (assign to current user, status→`human`)
- `reply` (append an `agent` message)
- `resolve` (status→`resolved`)

## Widget changes (`assets/chatbot.js`)

- **Identity:** obtain `public_id` from `/session`, persist in `localStorage`
  (try/catch; on failure, behave as a fresh bot-only session). Send it on every
  `/chat`, `/messages`, `/handoff`.
- **Handoff UI:** a persistent "Talk to a human" affordance; also react to
  `escalate:true` from `/chat`. On handoff, show an inline contact field
  ("Leave your email or WhatsApp so we can reply" — optional, submit or skip),
  then `POST /handoff`.
- **Polling:** only while the panel is **open** and `status ∈ {waiting, human}`,
  `GET /messages` every ~5s (configurable), render new `agent`/`system` messages
  with a distinct "Team" style, advance the `since` cursor. Stop on resolve,
  panel close, or idle timeout (e.g. 10 min). No polling during normal bot chat.
- **Input stays live** so the customer can talk to the human; bot does not reply
  while handed off.

## Admin inbox

- New menu **Chatbot Inbox** (cap `manage_woocommerce`), usable on a phone
  browser.
- **List:** status badge (🔴 Needs human / 🟡 In progress / Bot / Resolved),
  last-message preview, time, contact; auto-refresh (poll) so new items surface.
- **Detail:** transcript + reply box + **Take** / **Resolve**.
- Deliberately minimal; no bulk actions, search, or filters beyond status in
  Phase 1 (YAGNI).

## Notifications

- On handoff, build a short summary — the customer's latest question, their
  contact (if any), and a deep link to the conversation in the inbox — and call
  `DB_Chat_Order_Notifier::dispatch( $message )`, reusing the enabled
  Telegram / email / WhatsApp / Slack channels.
- One new setting: **"Alert staff when a customer needs a human"** (on/off),
  reusing the already-configured channels. Off by default until the operator
  enables it.

## Settings added
- `db_chat_handoff_enabled` (master on/off for the whole feature).
- `db_chat_handoff_notify` (alert staff on handoff).
- `db_chat_handoff_retention_days` (default 90).
- `db_chat_handoff_poll_seconds` (default 5).

## Privacy, retention, safety

- Conversations are new stored PII. A daily WP-cron job purges `resolved` (and
  abandoned `bot`) conversations and their messages older than
  `retention_days`. Provide a manual "purge now" as well.
- Add a privacy-policy line: chat conversations may be stored and read by staff
  to answer your question; contact details you provide are used only to reply.
- Contact capture is customer-initiated UI, never the AI asking.
- Feature is master-gated (`db_chat_handoff_enabled`); when off, the plugin
  behaves exactly as today (stateless bot), so it can ship dormant and be
  switched on deliberately.

## Testing

- **Automated (plugin's existing harness):** state transitions
  (bot→waiting→human→resolved→bot), message persistence, "messages since id"
  correctness, `/chat` does not run the AI in `waiting`/`human`, handoff fires
  the notifier exactly once (fake notifier), retention purge selects the right
  rows.
- **Manual on the logged-out test link:** chat with the bot → tap Talk to human
  → leave an email → confirm staff sees it in the inbox and gets the alert →
  staff Take + Reply → customer sees the reply live (polling) → Resolve → a new
  message is handled by the bot again.
- **Regression:** with `db_chat_handoff_enabled` off, and on with no handoff,
  the normal AI flow, product cards, and existing tests are unchanged.

## Deployment

- Files deploy to the live plugin at
  `staging.durianbikers.com/wp-content/plugins/durianbikers-chatbot/` via
  ssh-stdin, each backed up (`.bak.<ts>`) and `php -l`-linted on the server, as
  established this project. Tables are created via `dbDelta()` on a version bump.
- Ship with `db_chat_handoff_enabled` **off**; enable after the manual test on
  the logged-out link passes.

## Risks / mitigations
- **Polling load** on a cached site: bounded — only while a handed-off panel is
  open, ~5s, a single indexed range query. Configurable; can raise the interval.
- **Abandoned conversations** accumulating rows: retention purge + index on
  `status`/`updated_at`.
- **Nonce/cache interplay:** reuse the proven `/session` nonce path; never bake a
  nonce into cached HTML.
