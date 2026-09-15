# Durian Bikers Chatbot — AI-Orchestrator Redesign (Design Spec)

**Status:** Draft for operator review
**Date:** 2026-09-15
**Scope:** How the `durianbikers-chatbot` plugin DECIDES and WRITES replies. Not a rewrite of the
widget or the Chatbot Inbox. Stays staff/test-page-only until the operator says go-live.

---

## 1. Problem this solves

The current bot is **keyword-first**: `class-lookup-engine.php` answers first, and Claude only sees
"genuine misses". Consequences, observed repeatedly:

- Every bug is a phrase the keyword engine mishandled, so every fix is another keyword/rule —
  whack-a-mole with no end.
- Two brains ⇒ two personalities (robotic templates vs natural Claude), depending on which caught
  the message.
- The keyword engine cannot comprehend language, context ("the red one", "yang tadi"), mixed
  Malay/English, typos, or new languages — those are hand-maintained keyword tables.
- Claude has only 2 tools (`search_products`, `get_my_orders`); everything transactional runs on a
  separate rule-based path (finder → pending-order → cart-writer) Claude can't drive.
- Grounding is prompt-only: nothing in code stops an invented product or price (happened once).
- No checkout-to-payment flow, and no conversation regression suite.

**Fix (operator-chosen, agreed):** Claude runs the conversation and calls real WooCommerce-backed
tools; the keyword engine is demoted to a read-only fallback for when AI is unavailable; safety is
enforced in **code**, not the prompt; and a 60+ scripted-conversation suite runs before deploys.

## 2. Decisions locked (from brainstorming)

1. **Model:** Haiku 4.5 (`claude-haiku-4-5-20251001`) now. Architecture leaves a per-request model
   hook so a **Haiku + Sonnet tier** (escalate hard cases to Sonnet) can be added later without a
   rewrite. Tiering is **not built now**.
2. **Delivery:** One spec (this doc) covering the whole design; **built and deployed in 3 phases**
   (§12). Operator's explicit go-ahead required before Phase 2 (cart) and again before Phase 3
   (checkout).
3. **Checkout PII capture:** Hybrid by login state — logged-in customers **tap-confirm** their saved
   WooCommerce details; guests fill **inline form fields**. Personal details go **only** to the
   WooCommerce session and are **never sent to Claude** (§7).
4. **Budget:** Keep the existing hard cap (`db_chat_ai_monthly_cap_myr`, default RM100/month) with a
   **silent fallback** to the keyword engine when over budget, no key, timeout, or error.

## 3. Architecture

```
Customer message
      │
      ▼
class-chat-endpoint.php  (nonce, rate-limit, sanitise, conversation store — unchanged shell)
      │
      ▼
DB_Chat_Orchestrator            ← NEW thin coordinator (replaces the lookup-first branch)
  ├─ budget/availability gate → if AI unavailable: DB_Chat_Lookup_Engine (READ-ONLY fallback)
  ├─ build PII-stripped history + system prompt + tool schemas (prompt-cached)
  ├─ DB_Chat_Claude_Client tool loop  (reuse; raise MAX_TOOL_ROUNDS to 6)
  │     └─ tools execute against real WooCommerce data (see §4)
  ├─ DB_Chat_Grounding::verify(reply, tool_facts)   ← code-enforced (see §5.1)
  └─ assemble REST response {reply, products[], buttons[], cart?, checkout?, escalate?}
      │
      ▼
Widget renders (unchanged): cards, "This one", tap buttons, back-to-AI, WhatsApp, handoff form
```

**Default mode on the test page:** `db_chat_mode = ai` (Claude orchestrates). `hybrid`/`lookup`/`none`
stay valid for fallback and emergencies. When `ai` is selected but the AI is unavailable at request
time, the orchestrator degrades to the keyword engine automatically (silent fallback).

**Fallback engine scope (safety):** when the keyword engine answers (AI unavailable), it runs
**read-only** — product/policy/order Q&A and handoff only. It does **not** drive cart or checkout
without the orchestrator. Its product-search, fitment guard, and in-stock filtering are refactored
into shared backends the tools also use, so there is one implementation of each.

**Context:** the orchestrator passes the full recent conversation (raise history cap from 10 to ~20
turns, PII-stripped) so Claude resolves "the red one" / "yang tadi" from context rather than a narrow
keyword slot.

## 4. Tool list

All tools run **inside WordPress** (native `wc_get_products()` / `WC()->cart` / `WC()->customer`),
never the REST API, never the app password. Every tool returns **whitelisted fields only**. Phase
column says when each is built.

| Tool | Purpose | Key inputs | Returns (whitelisted) | Phase |
|---|---|---|---|---|
| `search_products` | find products | `query`, `category?`, `brand?`, `in_stock_only?`, `max_price?` | name, url, price(text), stock_status, image, categories, brands; plus `options` facets (brands/sizes/colours) for tap-buttons | 1 |
| `get_product` | one product's detail | `product_id` | variations: size/colour, per-variation stock & price; fitment/description (tag-stripped, capped) | 1 |
| `get_policies` | shipping/returns/payment/warranty text | `topic` | the allowlisted help-page section (reuse `class-content-index.php`) | 1 |
| `lookup_order` | the signed-in customer's OWN orders | (no identity input) | reuse `DB_Chat_Order_Lookup` allowlist exactly; identity from `get_current_user_id()` only | 1 |
| `request_human` | raise a handoff | `reason` | triggers existing handoff + inbox + alerts; returns hours/next-step text | 1 |
| `view_cart` | show current cart | — | line items: name, size, qty, line price; cart subtotal (from `WC()->cart`) | 2 |
| `propose_cart_change` | stage an add/update/remove | `action`, `product_id`, `variation_id?`, `quantity` | a **proposal** (item, size, qty, price) — does NOT commit | 2 |
| `commit_cart_change` | apply a staged change after a clear yes | `proposal_id` | new cart state; refuses if not confirmed / out-of-stock / negative qty (via `class-cart-writer.php`) | 2 |
| `set_checkout_details` | write name/phone/email/address to WC session — **widget-driven, NOT a Claude tool call** (the inline form / tap-confirm POSTs straight to this REST action) | field values from the widget, never from Claude | Claude is only notified "fields captured" (booleans); values are never in Claude's context | 3 |
| `get_checkout_summary` | items + shipping + total | — | items/sizes/qty, shipping method+cost, total (MYR) — all **as WooCommerce calculates them** | 3 |
| `get_checkout_link` | the pre-filled checkout URL | — | the normal WooCommerce checkout URL for this session | 3 |
| `check_shipping_country` | /gb guard | `country` | whether a shipping zone/method serves that country | 3 |

Cart tools are **two-step**: `propose_cart_change` (Claude may call freely) never mutates; only
`commit_cart_change` mutates, and only after the customer's clear confirmation (§5.2). This keeps the
"propose then confirm" rule in code, not prompt.

## 5. Safety — enforced in code

### 5.1 Grounding (no invented facts)
`DB_Chat_Grounding` runs on Claude's final reply, every turn:

1. Build a **fact set** from all tool results in this conversation: product names, price strings,
   stock statuses, cart totals, shipping costs.
2. Extract every **money amount** in the reply (currency/number regex). Each must match a fact
   (normalised). An amount with no matching fact = violation. (This is the proven failure mode:
   Haiku invented a price.)
3. Any product **named with a price/stock claim** must correspond to a tool-returned product.
4. **On violation:** one silent regeneration with a corrective instruction ("restate using only
   tool facts; if unknown, say you'll check"). If it still violates, replace the reply with a safe
   line ("Let me double-check that for you — one moment.") and either re-run a search or hand off;
   **never show an ungrounded price**. Log every violation for review.

Product **cards/buttons** already come only from tool results (`resolve_products`) — kept. This makes
the visible cards structurally grounded; the check above closes the reply-text gap.

### 5.2 Cart changes — propose then confirm
- `commit_cart_change` refuses unless the last customer turn is a clear affirmative for the staged
  proposal. Keep the existing refusal-before-confirmation guards ("tak nak", "不要", "no", etc.),
  the **negative-quantity guard**, and **in-stock-only sizes** — all already in `class-cart-writer.php`
  and reused unchanged.
- Everything that mutates the cart goes through `class-cart-writer.php`. No tool writes `WC()->cart`
  directly except through it.

### 5.3 No order, no payment (hard wall)
No tool can: create an order, submit checkout, choose/submit a payment method, mark paid, apply
coupons, change prices, or touch gateway settings. The checkout tools stop at **filling the session +
handing over the checkout URL**. If a customer pastes card/bank details, the reply must not store or
repeat them (a scrub step drops card-pattern text before it reaches logs or Claude), and the bot tells
them to enter payment only on the checkout page.

### 5.4 Fitment
No "fits your Y15" claim unless `get_product` data supports it. Otherwise say it can't confirm and
offer a human. Reuse the existing fitment guard.

### 5.5 PII boundary (unchanged principle)
Identity is never a tool input. `lookup_order` reads only the current user's own orders via
`get_current_user_id()`. Anonymous visitors reach no personal data. (Exactly today's rule; §1 of
`SPEC.md` still holds.)

## 6. Checkout flow up to payment (Phase 3)

1. Cart has items. Customer signals they want to check out (any language).
2. Claude asks for details **one or two at a time**, naturally. Capture is hybrid:
   - **Logged-in:** show saved billing/shipping as a tap-confirm ("Deliver to <saved address>? ✓/✗").
     No PII typed.
   - **Guest:** show inline form fields (name, phone, email, address) reusing the handoff-form
     pattern. Values POST to `set_checkout_details` → `WC()->customer` billing/shipping. **Not sent
     to Claude.**
3. `get_checkout_summary` returns items/sizes/qty + shipping (WooCommerce-calculated) + total MYR +
   delivery address. Claude shows a short summary and asks "all correct?"
4. On a clear yes, the widget shows a **"Proceed to payment"** button (from `get_checkout_link`) that
   opens the normal WooCommerce checkout page with the session pre-filled. Customer picks a payment
   method and pays. **Bot stops here.**
5. **/gb international:** before promising delivery, `check_shipping_country` verifies a zone/method
   serves the country. If not, say so and hand off to a human.

**Guest prefill note:** WooCommerce prefills the checkout form from `WC()->customer` for the current
session, so a guest who set details via the chat (same session) sees them on the checkout page. Phase 3
verifies this on the test page before relying on it; if session prefill proves unreliable for guests,
the fallback is tap-confirm-only for logged-in and a "we'll fill what we can" note for guests.

## 7. Personal-data handling

- Chat PII (name/phone/email/address) is written **only** to `WC()->customer` (the checkout session).
- **Never** sent to Claude: capture is via form/tap; tools return booleans, not values; the summary's
  address line is rendered by the widget/tool, not passed back into Claude's context.
- History sent to Claude is **PII-stripped** (a scrub pass removes address/phone/email/card patterns
  before the messages array is built).
- Not written in full to chat logs or the Chatbot Inbox. (The existing handoff contact capture — email
  required, name/phone optional — stays as-is for *handoff*; that is a separate, consented step and
  already stored for staff to reply. Checkout address is not added to logs.)
- Card/bank details pasted by a customer are scrubbed before logging and never repeated.

## 8. Handoff rules

Hand off (existing handoff + Chatbot Inbox + Telegram/email/WhatsApp alerts) when: the bot can't
answer confidently; the customer asks for a person; the customer is frustrated; it's a
complaint/refund/warranty/damaged item; an unverifiable fitment question; a checkout/payment problem;
or it has failed twice on the same request. `request_human` is a tool Claude calls; a couple of these
(twice-failed, checkout/payment error) also fire from code regardless of the model. The reply tells
the customer what happens next in human terms (hours, WhatsApp), reusing existing copy.

## 9. Tone guide

Warm, short, natural — a friendly parts-shop staffer. Match the customer's language and register,
including rojak/Manglish. One question at a time. No robotic phrases, no "I'm sorry, I didn't
understand", no long bullet lists, no repeating a menu. If sincerely asked whether it's a bot, say
honestly it's an AI assistant; never claim to be a person. Facts only for comparisons.

**5 English examples**
1. Customer: "do you sell car?" → "We don't do cars — we're a motorcycle parts and gear shop 🙂 Helmets, tyres, exhausts, chain lube, that sort of thing. Looking for something for your bike?"
2. Customer: "need a helmet under 300" → "Got a few under RM300 — the NHK R1S is RM236 and the S1GP is RM284, both in stock. Want full-face or open-face?"
3. Customer: "is the C10 available in L?" → "Let me check… yep, the HJC C10 in L is in stock, RM469. Want me to pop it in your cart?"
4. Customer: "this is taking forever, I just want to talk to someone" → "Totally fair — I'll get a human on it. Our team's on WhatsApp Mon–Sat; I've flagged your chat so someone picks it up. Anything you want me to note for them?"
5. Customer: "does it fit my Y15?" (no fitment data) → "I can't confirm Y15 fitment from what I've got here, and I don't want to guess on fitment. Let me pass you to someone who can check properly — that OK?"

**5 Malay examples**
1. Customer: "bro ada stock tak size L?" → "Jap ya bro… ada, size L in stock. Yang mana satu — helmet apa tadi tu?"
2. Customer: "nak helmet murah bawah 300" → "Ada beberapa bawah RM300 — NHK R1S RM236, S1GP RM284, dua-dua ada stok. Nak full-face ke open-face?"
3. Customer: "yang merah tadi berapa?" → "Yang merah tu RM284 bro, ada stok. Nak saya masukkan cart?"
4. Customer: "tak faham la, nak cakap dengan orang" → "Boleh, saya sambungkan ke team kami 🙂 Diorang on WhatsApp Isnin–Sabtu. Dah saya tanda chat ni. Ada apa nak saya catat untuk diorang?"
5. Customer: "muat tak untuk Y15?" (no fitment data) → "Fitment Y15 tu saya tak boleh confirm dari data yang saya ada, tak nak main teka. Saya pass ke orang yang boleh check betul-betul ye?"

The tone guide + these examples go into the system prompt; graded tests (§10) check them.

## 10. Conversation test suite (built in Phase 1, grows every phase)

Two layers, both run before a deploy; the report prints pass/fail counts.

**A. Hard checks — deterministic, no API cost.** Stub the Claude client with canned tool-call
sequences; run real tool backends against the read-only catalogue. Assert:
- no price/product/total in a reply unless a tool returned it (grounding),
- cart unchanged without a clear yes; negative-qty refused; out-of-stock size refused,
- no order created, no payment method chosen, no card data stored/echoed,
- the right tool fired for a given intent; handoff fired on its triggers,
- fallback engine stays read-only when AI is "unavailable".

**B. Graded checks — real Haiku, small cost.** Run 60+ scripted multi-turn conversations through the
real orchestrator; grade outputs against a rubric (natural tone, same language, one-question-at-a-time,
answered-what-was-asked, honest-about-being-a-bot). Grading by a Claude judge pass + spot manual
review. Cost ≈ 60 convos × ~RM0.11 + grading ≈ **~RM10 per full graded run**.

**Cadence:** hard checks (free) run before **every** deploy — non-negotiable. The full suite
including graded checks runs before every deploy too, as requested; if the ~RM10/run adds up during
rapid iteration, the operator can choose to gate graded runs to behaviour-changing deploys while hard
checks stay on every deploy. Either way the report prints pass/fail counts and the operator sees them.

**Scenario coverage (aim 60+),** including every bug found so far:
human-request-returning-T&C; "under RMx"; jet engines; advice/maintenance questions;
invented-price; "tak nak"/"不要"; bare yes/no; vague follow-ups ("the red one", "yang tadi");
language switching mid-chat; rojak/Manglish; typos; off-topic; rude/frustrated; "are you a bot?";
full browse→size→cart→checkout-details→summary→Proceed-to-payment in EN/MS/ZH; customer changing mind
mid-checkout; customer pasting card details; /gb international shipping; each handoff trigger.

**Process:** run the full suite before every deploy and show the operator pass/fail. When the operator
reports a new problem: **add it as a test first**, fix the underlying cause (not the one phrase), then
rerun the whole suite. Suite lives as scenario files + a runner executed server-side via `wp eval-file`
(same mechanism as today's smoke tests; there is no local WP/phpunit).

## 11. Existing features — what each phase touches (keep working)

| Feature | Status in this redesign |
|---|---|
| Widget look, icon, avatar, human avatar on agent replies | **Unchanged.** |
| Product cards + "This one" button; tap buttons (brand/size/colour); same-tab card links | **Kept**, now sourced from tool results (`search_products`/`get_product` return `options`). Deterministic `class-product-finder.php` state machine is retired as orchestrator; its search/fitment/in-stock logic is reused inside tools. |
| Chat remembered across pages (sessionStorage), back-to-AI button, WhatsApp button, contact capture at handoff | **Unchanged** (widget). |
| Chatbot Inbox: live updates, human/bot toggle, delete, waiting badge, sounds, resolve=fresh, agent replies in widget | **Unchanged.** |
| Staff alerts (Telegram/email/WhatsApp), private test link + staff-only gate, settings screen, AI budget guard, add-to-cart safety checks | **Unchanged / reused.** Budget guard now governs the always-on AI path. |
| `class-lookup-engine.php` | **Kept as read-only fallback**, no longer the primary brain. |
| `class-chat-endpoint.php` | Shell (nonce/rate-limit/store/finalize) unchanged; the lookup-first branch is replaced by the orchestrator call. |
| Checkout page, payment gateway, prices, stock, orders | **Never modified.** Phase 3 only fills the session and links to checkout. |

Before changing any file, the implementation plan lists which of the above it touches; anything that
could affect a kept feature is flagged to the operator first.

## 12. Phases

**Phase 1 — Brain swap (no checkout risk).** Orchestrator; read-only tools (`search_products`,
`get_product`, `get_policies`, `lookup_order`, `request_human`); grounding check; tone/language;
narrowing via tool `options`; fallback-engine demotion; the test suite (hard + graded). Deploy to the
test page. *This alone ends the whack-a-mole.*

**Phase 2 — Cart actions.** `view_cart`, `propose_cart_change`, `commit_cart_change` (confirm-gated,
via `cart-writer`; add update/remove to `cart-writer`). Grows the suite with cart scenarios. Operator
go-ahead required first.

**Phase 3 — Checkout to payment.** `set_checkout_details` (hybrid capture), `get_checkout_summary`,
`get_checkout_link`, `check_shipping_country`; the summary → "Proceed to payment" hand-off. Grows the
suite with full-flow + /gb scenarios. Operator go-ahead required first; **touches the WC session**, so
extra pre-flight (§13).

## 13. Deploy & safety (every phase)

- Staff/test-page only until operator says go-live. Chatway untouched.
- Back up each file on the server before replacing (`.bak-<stamp>`); `php -l` / `node --check`;
  md5 verify; purge LiteSpeed.
- Pre-flight health check around deploys: `/my/`, `/gb/`, `/my/shop/`, checkout reachable/200.
- Protect the admin cart with the snapshot/restore test helper during any cart test.
- SSH gate lift/restore per the operator's approved posture; no git push or customer-facing change
  without asking; API key never printed or committed.

## 14. Cost summary

Haiku 4.5, prompt-cached system+tools, ~10 API calls/conversation:
~US$0.023 ≈ **RM0.11/conversation** (plugin's own `USD_MYR=5.0`). RM100/month cap covers ~30
conversations/day before silent fallback. Sonnet 5 ≈ RM0.32/conversation (for the future tier).

## 15. Open items to confirm at plan time
- Exact grounding regex/normalisation for MYR + how strictly to gate product-name claims (design in
  Phase 1, tune against the suite).
- Guest checkout session-prefill reliability (verify on the test page in Phase 3; fallback noted §6).
- Raising `MAX_TOOL_ROUNDS` (4→6) and `MAX_TOKENS` (1024→~1536 for summaries) — confirm against
  latency/cost during Phase 1.
