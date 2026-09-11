# Chatbot add-to-cart — design

- **Date:** 2026-09-11
- **Status:** Draft for operator review
- **Component:** `durianbikers-chatbot`, free lookup mode (`DB_Chat_Lookup_Engine`)
- **Related:** live store takes real payments since 2026-08-14; chat is currently staff-gated
  on the private `chatbot-test` page (id 59679)

## 1. Goal

Let a customer buy from the chat: say what they want, be asked for anything missing, confirm,
and have the item land in the same cart the website shows.

The bot must never guess a variation. A wrong helmet size or tyre size is a return at best and a
safety problem at worst, which is the same reason this mode already refuses fitment advice.

## 2. What is broken today

Observed on the live test page, 2026-09-11:

1. Customer: *"i want yamanaka for 2pcs"* → bot lists the two Yamanaka helmets. Correct so far.
2. Customer answers with the full product title → **the bot searches again and re-lists the same
   product.** It has no concept of its own previous question, so every message is a fresh search.

Second defect found in the same screenshot: **`want` is not a purchase term.** It is a stopword,
so *"i want yamanaka for 2pcs"* sets no purchase flag and produces no purchase note, even though
`2pcs` parses as a quantity. "I want X" is one of the most common ways a customer asks to buy.
`nak` (Malay "want") has the same problem.

## 3. Decisions taken (operator, 2026-09-11)

| Decision | Choice |
|---|---|
| Add straight away, or confirm first? | **Summarise and wait for an explicit yes** |
| Languages | **English + Bahasa Malaysia + Chinese** |
| Out-of-stock variations | **Only offer sizes that are in stock** |

## 4. Conversation model

A purchase needs three facts: product, variation, quantity. The bot collects what is missing, one
question at a time, then confirms.

```
IDLE
  └─ purchase intent + product match
       ├─ several products matched  → ASK_PRODUCT
       ├─ one product, needs size   → ASK_VARIATION   (in-stock options only)
       └─ everything known          → ASK_CONFIRM
ASK_PRODUCT   ─ customer names one ─→ ASK_VARIATION | ASK_CONFIRM
ASK_VARIATION ─ customer names size ─→ ASK_CONFIRM
ASK_CONFIRM   ─ yes ─→ ADD TO CART → IDLE
              └─ no  ─→ IDLE (dropped, politely)
```

Rules:

- **Never assume a variation.** If exactly one variation is in stock, the bot states that in the
  confirmation ("only available in L") rather than silently selecting it — the customer still
  confirms it.
- **Quantity defaults to 1** when never given, and is always *visible* in the confirmation so it
  can be corrected. A default shown is not an assumption hidden.
- **Answering the bot's question is matched against what the bot just offered**, not re-searched.
  This is the fix for defect 2 above: in `ASK_PRODUCT`, the customer's message is matched against
  the products just listed; in `ASK_VARIATION`, against the sizes just listed.
- **Any off-topic message abandons the pending order** and is answered normally. A customer who
  changes their mind must never be trapped in a loop.
- **A pending order older than 15 minutes is dropped** and the flow restarts.

## 5. Where the pending order lives

**In the request, not on the server.** The reply carries a small `pending` object; the widget sends
it back with the next message, exactly as it already does with conversation history.

Chosen over storing it in the WooCommerce session because it adds no storage, has no session-expiry
failure mode, and keeps the REST route stateless. Tampering is not a concern **because nothing from
the browser is trusted at add time** — see §6.

The WooCommerce cart session is still used for the write itself, since that is the cart the customer
sees on the site. The widget runs on the storefront, same origin, and already sends credentials, so
the session cookie identifies the right cart.

`pending` shape:

```
{ stage, product_id, variation_id, quantity, offered: [ids or size labels], created }
```

## 6. Validation at the moment of adding

Every field is re-read from WooCommerce and re-checked, ignoring whatever the browser sent:

- product exists, is `publish`, is publicly visible and `is_purchasable()`
- variation genuinely belongs to that product (not merely a valid variation id somewhere)
- variation is in stock
- quantity is a positive integer within available stock, and capped at a sane maximum
- price is read from the product, never from the request

Any failure produces a plain explanation and no cart write.

## 7. Cart mechanics

- `wc_load_cart()` when the cart is not already initialised in the REST context.
- `WC()->cart->add_to_cart( $product_id, $qty, $variation_id )`.
- On success the reply includes the cart URL and the new cart total.
- The widget triggers WooCommerce's `wc_fragment_refresh` so the header cart icon updates — the
  theme's `durianbikers_cart_icon_fragment` already supplies that fragment.
- The chat response must never be cached; the route already sets no-store and calls
  `litespeed_control_set_nocache`.

## 8. Languages

Purchase, size, and yes/no vocabulary in all three languages:

- **English** — buy, want, order, get me, I'll take; yes/yeah/ok/confirm.
- **Bahasa Malaysia** — beli, nak, mahu, tempah, pesan; ya/ok/betul/boleh/setuju.
  `want`/`nak` must move from the stopword list into purchase terms (defect 2), taking care that
  `nak` alone in a non-buying sentence does not trigger a purchase.
- **Chinese** — 买 / 要 / 訂 / 下单; 确认 / 好 / 是 / 对. **Chinese needs a different matching path:**
  no spaces between words, so the existing token splitter cannot see them. Chinese terms are matched
  as substrings, with the language detected by Han character range.

Build order: English and Bahasa Malaysia end to end first, then Chinese.

## 9. Testing

Assertion harnesses replayed against the live catalogue, in the pattern already used for ranking and
purchase intent (`wp eval-file`, run over SSH).

Must pass:

- the full happy path, ending with a real cart line
- an out-of-stock size is offered to nobody and refused if asked for
- a wrong or hallucinated variation id in `pending` is rejected
- quantity above available stock is refused
- "nak beli 705" still searches for model 705 and orders nothing
- an off-topic message mid-flow abandons the pending order
- `where is my order` still routes to order lookup, not to buying

Cart writes are exercised against a real product and the cart emptied afterwards.

## 10. Out of scope

No checkout in chat. No coupons. No editing or removing existing cart lines. No multi-product orders
in one message ("2 tyres and a helmet"). Each is a separate decision.

## 11. Risks

- **It writes to a real cart on a store taking real payments.** Mitigated by the confirmation gate,
  full server-side revalidation, and the staff-only gate during testing.
- **Cart in REST context** is the main unknown: `wc_load_cart()` behaviour under this route needs
  proving early, before the conversation work is built on top of it.
- **Guest carts** depend on the WooCommerce session cookie surviving; to be verified logged out
  before this is opened beyond staff.
