# Chatbot: "This one" cards, brand narrowing, human handoff — Design

**Date:** 2026-09-14
**Status:** Approved in conversation, section by section. Awaiting written review.
**Plugin:** `durianbikers-chatbot` (free lookup mode; hybrid AI built but switched off)
**Driven by:** operator testing on the private chatbot test page, items #3, #6 and #12.

---

## 1. Why

Three things the operator hit while testing, plus one bug found while investigating them:

| # | What the customer did | What happens today | What the operator wants |
|---|---|---|---|
| 3 | `berapa harga barkbusters` | Lists the first 4 of 94 Barkbusters products | Ask which product, one question at a time, until it is specific |
| 12 | `i want yamanaka 2pc`, then picks a product | Customer must type the product name back; tapping a card leaves the page and the chat is lost | A **This one** button that tells the bot exactly which product; tapping the card opens the product page |
| 6 | `will this fit my yamaha r15` | Declines fitment and mentions the WhatsApp button | Get the customer to a person |
| bug | `i want to talk to a human` / `nak cakap dengan orang` / `can i speak to someone` | Replies with a paragraph of the **Terms & Conditions** page | A clear route to a person |

**Go-live is gated on this work.** The operator chose to finish #12 and #3 (and the handoff fix) before the chatbot replaces Chatway site-wide.

## 2. Decisions (all confirmed by the operator)

1. **Human handoff = the existing WhatsApp button** for launch. A staff inbox inside the chat was requested, then deferred: the operator does not want Chatway, and building an inbox is its own project. WhatsApp number already configured: `60196141123`.
2. **Brand narrowing = step-by-step tap buttons** built from what is actually in stock: product type → bike make or series → size → colour. Stop as soon as **4 or fewer** products remain, then show those cards. The customer can always type instead.
3. **"This one" = pick it, no buying.** On every product card. A tap tells the bot the exact product; the bot replies with price, stock and in-stock sizes and says "say *buy* to add it". While the bot is waiting for an answer to "which one?", the tap answers it and the purchase moves straight to the size question.
4. **Card tap = same tab, chat remembered.** The product page opens in the same tab and the chat reopens there where it left off, saved in `sessionStorage` for that tab only. A conversation that touches orders is never saved.

## 3. Scope

**In scope**

- A. Human-request intent and reply, and local-format WhatsApp number input (§4).
- B. Product ID on cards and the server-side "This one" pick (§5).
- C. Widget: This one button, card-as-link, conversation persistence (§6).
- D. Brand narrowing in the guided product finder (§7).

**Out of scope (named so nobody builds them by accident)**

- A staff inbox inside the chat, Chatway integration, respond.io.
- Switching the chatbot on site-wide, deactivating Chatway, cache purge — a separate go-live step with its own explicit yes.
- Chinese button labels (Chinese customers get English buttons, as elsewhere in the finder).
- The payment-question gap (`payment` shows shipping text; `cara pembayaran` not understood) — recorded, not fixed here.
- Carrying a quantity from a brand-only purchase message through narrowing (`nak beli shinko 2` narrows; quantity is asked at the buy step).
- Reading sizes or colours from WooCommerce attributes. The attribute names are inconsistent on this store (`Size` on one product, `Option1` on another); product names are the source.

## 4. Part A — Human handoff

### 4.1 Intent

A new engine intent `human`, resolved **before** `contact`, for requests to reach a person:

- EN: `talk to a human`, `talk to a person`, `talk to someone`, `talk to staff`, `speak to someone`, `speak to a person`, `speak to a human`, `real person please`, `customer service`, `live agent`.
- BM: `nak cakap dengan orang`, `cakap dengan staf`, `bercakap dengan staf`, `nak cakap dengan manusia`, `khidmat pelanggan`.
- ZH: `人工`, `客服`, `真人`.

**Phrases only, never bare words.** `agent` alone is how dealers and salesmen are described on this business, and `human` alone appears in `are you human`. When a message matches both an identity phrase (`are you a bot`, `are you human`, `is this a real person`) and a human-request phrase, **identity wins**. `contact` keeps phone/email/address/hours questions.

### 4.2 Reply

Template `human_handoff` (EN and BM; ZH falls back to EN):

- Handoff configured and open: *"A person can help with that. Tap WhatsApp below and our team will reply. Hours: {hours}."*
- Handoff configured and closed (`DB_Chat_Handoff::is_open()` false): the same, plus *"We're closed right now, so we'll reply when we open."*
- No handoff number configured: *"A person can help with that. Please contact us — details are on our Contact page."* plus the Contact page link if it is allowlisted.

The response carries `suggest_handoff: true`; the widget already highlights the WhatsApp row on that flag. No page passage is quoted. The turn is never a hybrid miss.

### 4.3 WhatsApp number input

`DB_Chat_Handoff::normalize_number()` accepts the Malaysian local form: a number starting `01` with 9–10 further digits becomes `60` + the number without its leading `0` (`0196141123` → `60196141123`). International forms are unchanged. Today the local form normalises to empty and the button silently disappears.

## 5. Part B — Product ID and the server-side pick

### 5.1 Card shape

Every product card object gains `id` (int, the WooCommerce product ID) alongside the existing `name, url, price, image, in_stock`. Built by `DB_Chat_Product_Index` wherever cards are produced.

### 5.2 Request

`POST /durianbikers/v1/chat` accepts an optional `pick`: a positive integer. The widget sends it only from a This one tap, with `message` set to the product name (so the transcript and history read naturally). `message` stays required and validated as today.

### 5.3 Validation

The server re-reads the product for every pick. It must be: a real `WC_Product`, status `publish`, not password-protected, catalogue visibility not `hidden`. Anything else → *"I couldn't find that product — can you tell me which one?"* with no cards and no error status. The pick carries no trust: price, stock and sizes always come live from WooCommerce.

### 5.4 Behaviour

| Pending state sent with the pick | Result |
|---|---|
| `ask_product` and the pick is in `offered` | Same as choosing it by name: `ask_for_variation()` → size question (or confirmation for a simple product / single size). Quantity carried. |
| `ask_product` but the pick is not in `offered` | Treated as an information pick (below); the old pending order is dropped. |
| `ask_variation` / `ask_confirm` | Information pick; pending dropped. A tap is never read as a size or a yes. |
| none | Information pick. |

**Information pick** reply (EN/BM):
*"{name} — {price}. In stock: {sizes}." / "…Out of stock right now."* then *"Say **buy** to add it to your cart."* One card for the product. Returns a new pending stage **`chosen`** carrying `product_id` (no quantity, no variation).

**`chosen` stage.** On the next message:
- A purchase message (`buy`, `beli`, `nak`, `买`, `i want`, with or without a quantity) that names **no other product** → `ask_for_variation( product_id, quantity )`. The purchase proceeds exactly as today, including confirm-before-add.
- Anything else → the pending is dropped and the message is answered normally (same "moving on" rule as the other stages).
- It expires on the existing pending-order timeout.

A pick never writes to a cart. Picks count toward the existing rate limits. A pick turn is never a hybrid miss.

## 6. Part C — Widget

### 6.1 Card

- The card's thumbnail and name become one link (`<a class="db-chat-product-link">`) to the product URL, **same tab**. The separate "View" text link is removed.
- A **This one** button (`<button class="db-chat-product-pick">`) sits beside it, outside the link (no interactive element nested inside another). Label `This one` in every language — the widget has no per-message language, and the label is overridable through the existing copy settings; `aria-label` includes the product name.
- The button is rendered only when the card has a positive integer `id`. Cards without one render as before, minus the button.
- Tapping sends `send(name, { pick: id })`: the customer bubble shows the name, `history` records the name, the request carries `pick`.

### 6.2 Conversation persistence

- **Key:** `db_chat_state_v1_{store}` in `sessionStorage`, where `{store}` is `my`, `gb`, or `default`, localized by the plugin from the theme's store routing when available, otherwise read from the first path segment.
- **Value:** `{ v: 1, savedAt, open, history, pending, transcript }`. `transcript` is the last 10 turns as rendered data: user text, bot reply, products, pages, and chips for the last turn only. Nothing is stored that the widget did not already have in memory.
- **Saved** after every completed turn and on panel open/close.
- **Restored** on page load: re-render the transcript with the same builders (textContent only), restore `history` and `pending`, reopen the panel only if `open` was true.
- **Never saved / wiped:** as soon as the existing order-context rules fire (`noteOrderContext`, `noteOrderTools`), the saved state is deleted and saving stops for this tab.
- **Discarded:** older than 30 minutes since `savedAt`; version mismatch; unparseable; serialised size over 100 KB; a different store key. Storage errors (private mode, quota) are swallowed and the chat works in memory as today.
- Closing the tab ends it, by definition of `sessionStorage`.

## 7. Part D — Brand narrowing

### 7.1 Where it lives

In `DB_Chat_Product_Finder`, as a second entry point. The finder already provides the properties this needs: options derived from buyable in-stock products (no empty branches), state replayed from history (no server-side session), and self-abandonment when the customer types something that is not an option. The endpoint already gives the finder first refusal before the keyword engine.

### 7.2 Trigger

A message that resolves to exactly **one** `product_brand` term and nothing more specific: after removing the brand name, filler words, price words (`price`, `how much`, `harga`, `berapa`, `多少钱`) and purchase words (`buy`, `nak`, `beli`, `i want`, `买`), no product-identifying token remains. Examples that trigger: `barkbusters`, `berapa harga barkbusters`, `shinko price`, `nak beli shinko`. Examples that do **not**: `shinko 705`, `barkbusters storm`, `what MT helmet models do you have` (browse terms keep the existing range listing), `i want yamanaka 2pc` (not a brand). A brand with 4 or fewer buyable products skips narrowing and lists them.

### 7.3 Facets, in order

For the brand's buyable products (published, priced, in stock, not a zero-variation import):

1. **Type** — leaf product category; if the brand sits in one category, the name family (first two words after the brand name, normalised: `handguard kit`, `vps plastic`).
2. **Bike make / series** — bike make from a fixed list found in the name (`Honda, Yamaha, Kawasaki, Suzuki, BMW, KTM, Ducati, Triumph, Husqvarna, Aprilia, Benelli, CF Moto, Harley-Davidson, Royal Enfield, Modenas`); or a series code (`SR777`, `E705`, `RPHA`, pattern of letters followed by digits) when no make is found.
3. **Size** — tyre size `NNN/NN[-RBZ]NN` or helmet size tokens (`XS S M L XL XXL`) in the name.
4. **Colour** — colour words inside parentheses in the name.

A facet is **skipped** when it does not split the current set (fewer than two values that each have products, or one value covering all). Values are ordered by product count. **At most 6 buttons per step, All / Semua always last:** up to 5 values when they all fit; when there are more, the top 4 values, then **Others / Lain-lain** (the remaining values together), then All / Semua. Choosing Others narrows to those products and continues with the next facet that splits them. The flow ends when **≤ 4** products remain, or when no facet can split further, and shows those products as cards with This one. Filtering on bike make always adds *"Please confirm fitment with our staff before buying."* / *"Sila sahkan padanan dengan staf sebelum beli."*

**All / Semua** shows the first 4 products of the current set and *"Type more detail to narrow it down."*

### 7.4 Copy

- Opening question: *"{Brand} has {n} products. Which one?"* / *"{Brand} ada {n} produk. Yang mana satu?"*
- Make: *"For which bike?"* / *"Untuk motor apa?"*; series: *"Which series?"* / *"Siri yang mana?"*
- Size: *"Which size?"* / *"Saiz berapa?"*; colour: *"Which colour?"* / *"Warna apa?"*
- Result: *"Here are the {n} available:"* / *"Ini {n} yang ada:"*

Language follows the finder's existing detection (EN/BM; ZH gets EN).

### 7.5 Speed

Per-brand facet data (product IDs and facet values, **no stock**) is cached in a transient per brand term for 1 hour and cleared when any product is saved. Stock and price on the final cards are always read live. Buttons may be up to an hour behind the catalogue; the cards never are.

## 8. Safety rules carried over

- No test or code path calls the Anthropic API; live mode stays `lookup`.
- Finder, pick and human turns never raise the hybrid miss flag.
- Nothing here writes to a cart except the existing confirmed-purchase path.
- Order data: never in `sessionStorage`, never in a WhatsApp prefill, never logged (existing rules extended to persistence).
- No SKU is generated, changed or inferred. No stock or price is written.
- All widget rendering stays `textContent`; URLs pass `safeUrl()`.

## 9. Testing

**Server (PHP harnesses in the session scratchpad, run with `wp eval-file` on the VPS, with the Anthropic no-network guard and unmatched logging off):**

- A: every human phrase in EN/BM/ZH resolves to `human`; identity and contact questions unchanged; reply open/closed/unconfigured variants; `suggest_handoff` true; no T&C passage; number normalisation cases (`0196141123`, `+60 19-614 1123`, `60196141123`, junk).
- B: cards include `id`; pick of a valid product → information reply + `chosen`; pick in `offered` → size question with quantity carried; pick not offered → information + old pending dropped; draft/private/deleted/password/hidden/zero/negative/non-numeric pick → "couldn't find"; `chosen` + `buy 2` → size question qty 2; `chosen` + unrelated → pending dropped; pick never adds to cart.
- D: trigger examples and non-triggers from §7.2; Barkbusters first step offers types with products; each tap reduces the set; ends at ≤ 4; skipped facets; All / Semua; no empty option for any of the 20 brands with more than 4 products; fitment note when filtered by make; cache cleared on product save.

**Widget (browser, on the private test page, desktop and 375 px):** This one tap on search cards and during "which one?"; card tap navigates in the same tab and the chat reopens with transcript and pending intact; `/my` → `/gb` starts fresh; order question wipes and stops persistence; 30-minute expiry (by editing `savedAt`); closed panel stays closed after navigation; no console errors.

**Regression:** all existing suites (255 assertions) with the admin cart snapshot/restore; deployed files match git; `/my/`, `/gb/`, cart, checkout and the session route return 200.

## 10. Rollout

- Built in the order A → B → C → D, each independently deployable, with the safe deploy method (backup, `.new`, remote lint, atomic move, checksum, health gate with automatic restore).
- The chatbot stays on the private test page throughout; the operator tests each part through the secret link.
- Go-live (site-wide enable, Chatway off, cache purge, logged-out checks) is a separate step with the operator's explicit yes.

## 11. Files

| File | Parts |
|---|---|
| `includes/class-lookup-engine.php` | A (human intent/copy), B (pick handling, `chosen` stage) |
| `includes/class-handoff.php` | A (number normalisation) |
| `includes/class-pending-order.php` | B (`chosen` stage) |
| `includes/class-product-index.php` | B (`id` on cards) |
| `includes/class-chat-endpoint.php` | B (`pick` param) |
| `durianbikers-chatbot.php` | C (store key in script data) |
| `assets/chatbot.js`, `assets/chatbot.css` | C |
| `includes/class-product-finder.php` | D |
| `DEPLOY.md` | docs |
