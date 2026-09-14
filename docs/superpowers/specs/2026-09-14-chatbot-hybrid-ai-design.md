# Chatbot hybrid AI — design

- **Date:** 2026-09-14
- **Status:** Design and all three judgement calls approved by the operator; spec awaiting review
- **Component:** `durianbikers-chatbot`
- **Related:** `2026-09-11-chatbot-add-to-cart-design.md` (the buying flow this must not disturb);
  live store takes real payments; chat is reachable only through the secret test link or staff login

## 1. Goal

Answer every customer question. The free keyword engine answers what it can at no cost; only the
questions it genuinely cannot understand are passed to Claude.

## 2. Why the free engine cannot do this alone

The free engine matches wording against keyword tables. More keywords catch more phrasings, but it
never understands a question it was not built for — "which of these suits touring?" will always
fail. Answering *all* questions needs a language model. Sending *every* message to one costs money on
every message, including the many the free engine already answers well. Hybrid pays only for the gap.

## 3. Decisions taken (operator, 2026-09-14)

| Decision | Choice |
|---|---|
| How to cover questions the free engine cannot answer | **Hybrid** — free engine first, Claude only on a miss |
| Monthly AI budget | **RM100** (≈500 AI-answered conversations, ≈16 a day, at ≈RM0.20 each) |
| Model | **Claude Haiku 4.5**, as priced when the operator chose hybrid |
| Unresolved comparisons | **Go to AI**, bound by a new rule never to say which product is better |
| Over budget | **Silent fallback** to the free answer — no "limit reached" message to customers |
| Unused daily budget | **Rolls forward** within the month |

**Prerequisite owned by the operator:** create an API key at console.anthropic.com, set a monthly
spend limit there first, then add the key in WooCommerce → Chatbot or as
`DB_CHAT_ANTHROPIC_API_KEY` in `wp-config.php`. The key is never handled on the operator's behalf.

## 4. Routing — when AI answers

### 4.1 A fourth mode

`DB_Chat_Plugin::MODE_HYBRID = 'hybrid'`, added to `MODES` and to the Answering mode setting.

In hybrid mode `get_engine()` returns `ENGINE_LOOKUP` whenever the lookup engine exists, so the
widget enqueue and the endpoint's existing branch behave exactly as in lookup mode. The AI step is an
addition inside the lookup branch, not a third branch.

If the lookup engine is missing, hybrid returns `ENGINE_NONE` and the chatbot switches off — the
same as lookup mode. Hybrid is a fallback *from* the free engine; with no free engine there is
nothing to fall back from, and silently becoming Claude-only would spend money the operator did
not choose to spend.

### 4.2 The miss signal

The lookup engine sets a request-scoped flag at **exactly three** points, and exposes it as
`DB_Chat_Lookup_Engine::needs_ai_fallback(): bool`, mirroring the existing `used_order_lookup()`.
The flag is reset at the top of `answer()`.

| Point | Meaning |
|---|---|
| `answer()` — the `'fallback'` intent case | The message could not be classified at all |
| `answer_products()` — the `$blind` branch | No keyword recognised **and** no product found |
| `answer_compare()` — neither side resolved | A comparison whose split or subjects were not understood |

**Not `log_unmatched()`.** That function is the right place for the worklist and the wrong place for
this signal: it deliberately returns early for order questions, for messages containing an email
address or a run of five or more digits, and for everything when unmatched logging is switched off.
Using it would make whether a customer gets an AI answer depend on a logging setting.

### 4.3 Never sent to AI

The flag is never set on any of these, and each is asserted in tests:

- A fitment question — declined deliberately; the product list is suppressed.
- A product search that understood the question but found nothing ("we don't stock X") — that is a
  catalogue gap, and an AI cannot find products the search did not.
- An order question — handled by the existing order lookup, with its privacy boundary.
- Anything in the buying flow — resuming a pending order, answering which product or size,
  confirming or declining, and the cart write. The AI has no cart tool and must not be given one.

### 4.4 The hybrid request

```
free engine answers
  └─ needs_ai_fallback()?
        no  → return the free answer (as today)
        yes → key present AND budget allows?
                no  → return the free answer (as today)
                yes → Claude answers the same message with the same history
                        success → return the AI reply, record the spend
                        failure → return the free answer, record any spend already incurred
```

- The AI reply uses the existing Claude-path response shape `{ reply, products[], pages[] }`, plus
  `pending: null`. An AI answer therefore ends any purchase in progress, which matches the existing
  rule that an off-topic message abandons a pending order.
- The existing `order_tool_used` handling on the Claude path applies unchanged, so an AI reply that
  touched `get_my_orders` is still sent no-store and withheld from the conversation log.

## 5. Budget guard — RM100 a month

### 5.1 Reading usage

The Claude client currently reads no token usage at all. It gains
`DB_Chat_Claude_Client::get_last_usage(): array` returning `input_tokens`, `output_tokens`,
`cache_creation_input_tokens` and `cache_read_input_tokens`, **summed across every round** of the
most recent `chat()` call. The return contract of `chat()` itself does not change, so the existing
AI mode is unaffected. Usage is kept even when `chat()` returns a `WP_Error` after some rounds
succeeded, because those rounds were billed.

### 5.2 `DB_Chat_Ai_Budget` (new, `includes/class-ai-budget.php`)

| Method | Purpose |
|---|---|
| `can_spend(): bool` | Checked **before** a call |
| `record( array $usage ): void` | Called after **every** call that consumed tokens — including one that failed after earlier rounds succeeded — so spend is never undercounted |
| `month_to_date_myr(): float` | Shown on the settings screen |

Storage: option `db_chat_ai_spend` (autoload off) holding the current month (`YYYY-MM`), spend so
far in MYR, spend at the start of today, today's date (`YYYY-MM-DD`), and a call count. Months and
days are in the **site timezone** (GMT+8), via `wp_date()`. A new month resets everything; a new day
snapshots the month's spend as "start of today".

Cost of one call, in USD:

```
( input × P_in  +  output × P_out  +  cache_write × P_in × 1.25  +  cache_read × P_in × 0.10 ) ÷ 1,000,000
```

- `P_in` / `P_out` are filterable constants. Last known Haiku 4.5 prices: **$1 input, $5 output per
  million tokens**. They must be re-checked against Anthropic's pricing page when implemented.
- Converted at `USD_MYR = 5.00`, filterable. **Deliberately above the market rate**, so the estimate
  runs high and the cap trips early rather than late.

`can_spend()` is true only when both hold:

```
month_spend  <  cap
spend_today  <  ( cap − spend_at_start_of_today ) ÷ days_left_in_month_including_today
```

Recomputing the daily allowance from what is actually left means quiet days roll their budget
forward (judgement call 3), while a single busy day still cannot consume the month.

Cap setting: option `db_chat_ai_monthly_cap_myr`, default `100`.

**Overshoot bound.** The budget is checked before a call and charged after it, so one call can take
spend past the cap by at most that call's own cost — bounded by `MAX_TOOL_ROUNDS + 1` requests of at
most `MAX_TOKENS` output each. Concurrent requests racing on the option can undercount slightly. The
Anthropic console spend limit is the hard stop for both.

### 5.3 Settings screen

Answering mode gains **Hybrid**. The screen shows month-to-date spend, the cap, and the call count.

## 6. Honesty and privacy

In hybrid mode, three pieces of existing copy become false and are replaced: the greeting (in
English and Bahasa Malaysia), the footnote, and the API key help text. Lookup-only mode keeps
its current, accurate copy.

| Where | Current (false in hybrid) | Hybrid wording |
|---|---|---|
| Greeting (EN) | "…not an AI that understands language…" | "Hi! I'm the Durian Bikers shop assistant. I'm automated, not a person. I can look up products, prices, stock, and our shipping, returns, warranty and payment pages. If I can't match your question myself, an AI assistant answers it — it can make mistakes, so please check anything important. What are you after?" |
| Greeting (BM) | "Saya automatik — bukan manusia, dan bukan AI yang faham bahasa…" | "Hai! Saya pembantu kedai Durian Bikers. Saya automatik, bukan manusia. Saya boleh semak produk, harga, stok, dan halaman penghantaran, pemulangan, jaminan dan bayaran kami. Jika saya tidak dapat padankan soalan anda, pembantu AI akan menjawabnya — ia boleh tersilap, jadi sila semak perkara yang penting. Apa yang anda cari?" |
| Footnote | "Automated lookup — it matches keywords, not meaning…" | "Automated assistant — some answers are written by AI and can be wrong. Please confirm fitment before you buy." |
| API key help text | "…free lookup mode needs no key and makes no external calls…" | "Used by the Claude only and Hybrid modes. In Hybrid mode, questions the free engine cannot answer are sent to Anthropic's API. Stored server-side only: never sent to the browser, never printed and never written to a log." |

Chinese replies fall back to the English greeting, as today.

**AI system prompt addition** (judgement call 1):

> Never tell a customer which product is better, best, or right for them. You may set out the facts
> from the search results side by side — name, price, stock, brand — but the choice is theirs. For
> anything that depends on their bike, their riding or their size, point them to a person.

**Operator action — privacy policy.** Add a line with this meaning before hybrid is opened to
customers:

> Questions our chat assistant cannot answer automatically may be sent to Anthropic, an AI provider,
> to write a reply. Please do not share personal or payment details in the chat.

## 7. Prompt caching

- `cache_control: {"type": "ephemeral"}` on the last tool definition and the last system block.
  Render order is tools → system → messages.
- Anything that varies per request must sit **after** the last breakpoint, or every request writes a
  fresh cache entry at 1.25× the input price.
- Haiku 4.5 caches only a prefix of **at least 4,096 tokens**. The current tools-plus-system prefix is
  estimated at about 3,500, so it may silently not cache. Measure `cache_read_input_tokens` on the
  first live calls before deciding anything. Do not pad the prompt to reach the minimum without that
  measurement. The budget formula prices cached tokens correctly whether or not caching happens.

## 8. Failure behaviour

Every failure returns the free answer unchanged. None of them shows the customer an error.

| Condition | Result |
|---|---|
| No API key | Free answer; no call attempted |
| Over budget (monthly or daily) | Free answer; no call attempted |
| HTTP error, timeout, or `WP_Error` from the client | Free answer; logged with `DB_Chat_Plugin::log_error()`, **no message content** |
| Empty AI reply | Free answer |

## 9. What does not change

- The buying flow and cart write stay entirely in the free engine.
- The order privacy boundary.
- Fitment is never answered, by either brain.
- Lookup-only and Claude-only modes behave exactly as today.
- The test gate: hybrid is exercised through the secret test link and staff login first.

## 10. Testing

**No API key needed** for everything except the live proof. A fake Claude client — a subclass of
`DB_Chat_Claude_Client` passed into `DB_Chat_Chat_Endpoint`'s constructor — records whether it was
called and returns canned replies, canned usage, or a `WP_Error`. Handlers are exercised directly
with a `WP_REST_Request`; the `db_chat_rate_limit` filter is raised for the run so the suite cannot
trip the real rate limiter.

Must pass:

- AI is called on each of the three miss kinds, and returns the AI reply.
- AI is **not** called for a fitment question, a "we don't stock X" search, an order question, a
  pending-order answer, a confirmation or a decline.
- No key, over budget, `WP_Error` and an empty reply each return the free answer unchanged.
- Budget maths: daily allowance; rollover across a day and across a month in the site timezone;
  unused budget carried forward; no call once the cap is reached; recorded cost matches the formula
  for given usage, including cache tokens.
- `get_last_usage()` sums usage across multiple tool rounds.
- Hybrid mode switches the greeting, footnote and key help text; lookup-only mode does not.
- The never-judge rule is present in the system prompt.
- The existing 161 assertions stay green.

**With a key (operator-provided):** live AI replies through the test link; `cache_read_input_tokens`
observed; recorded month-to-date spend compared with the Anthropic console.

## 11. Out of scope

AI inside the buying flow. Streaming replies. Choosing the model per question. Per-customer AI quotas
beyond the existing rate limits. A usage dashboard beyond the settings-screen summary. Clearing the
unmatched-question log, which currently holds only test noise.

## 12. Risks

- **Spend.** Mitigated by the plugin cap, the deliberately high exchange rate, the daily allowance, and
  above all the Anthropic console spend limit, which is the only hard stop.
- **Latency.** A fallback adds Claude round trips — up to `MAX_TOOL_ROUNDS + 1` requests with a
  20-second timeout each in the worst case — on top of the free engine.
- **Disclosure must ship with the feature.** Hybrid must not be opened to customers before the copy in
  §6 and the privacy-policy line are live.
- **Model ID.** The plugin uses `claude-haiku-4-5-20251001`; the current API reference lists the alias
  `claude-haiku-4-5`. Confirm the configured ID on the first live call.
- **Prompt injection.** Unchanged from the existing Claude path: no tool accepts a customer
  identifier, and there is no cart tool.
