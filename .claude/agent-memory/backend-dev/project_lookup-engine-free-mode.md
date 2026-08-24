---
name: lookup-engine-free-mode
description: The four Aug 2026 upgrades to the FREE lookup engine — subject slot, unmatched-question log, fuzzy typo thresholds, and the order reversal — plus the second-brain logging leak that nearly shipped
metadata:
  type: project
---

`durianbikers-chatbot/includes/class-lookup-engine.php` is the no-API-key brain
(deterministic keyword matching, zero network calls). Aug 2026 it gained four
features. What follows is the reasoning that is NOT recoverable from the code.

## The recurring bug shape on this feature (FIVE instances, Aug 2026)

Settled jointly with the finder agent. Every one was **two paths that agreed on
the day they were written and diverged later**, none visible in realistic data,
each found only by EXECUTING the other side rather than reading it:

1. display vs matching (chip truncation)
2. dedupe key vs truncation
3. shared normaliser vs an already-ordered caller
4. **a guard vs the thing it guards** (the `$order_tool_used` leak below —
   highest stakes of the set, because it failed open into an error log rather
   than into a wrong sort order)
5. **code vs its own test** (the reflection trap below — worst of the family,
   because the test is the instrument you would otherwise trust to detect
   divergence)

1–3 are in [[stateless-flow-replay]]. Five in one feature is not bad luck.

**The unifying statement: the bug is never in either path — it is in the
unexamined assumption that they still agree.** Practical consequences: go
looking at guards in files you do not own, and when a peer reports a shape like
this, check your own code for the same shape rather than just fixing the
instance they found. Both of us had instance 5 and only one of us had noticed.

Corollary that cost nothing and would have cost hours: **an agreement that lives
only in a message thread protects nothing once the thread ends.** Every
cross-file contract from this build is now pinned by an assertion on both sides
— option names, accessor names, the chips cap, the ellipsis/truncation contract.

## The bug worth remembering: a second brain bypasses the first brain's guards

Adding an order path to lookup mode surfaced a real leak in a file nobody owned.
`DB_Chat_Chat_Endpoint::maybe_log()` withholds order questions and replies — but
only when `$order_tool_used` is true, and that flag was set **exclusively by the
Claude tool executor**. Lookup mode had no order path when it was written, so
the flag could never be true there. The moment lookup mode could return order
data, every order reply would have been written verbatim to the error log with
`db_chat_logging` on.

**The generalisable rule:** when a system grows a SECOND implementation of a
capability, audit every guard built for the first one. A guard keyed on "did
component A do X" silently fails to cover component B doing X, and it fails
open. This is the mirror image of [[new-tool-check-old-prompt]] — there a stale
rule suppressed a new capability; here a stale *guard* failed to cover one. Both
are invisible: tests pass, lint passes, nothing errors.

Fix: `DB_Chat_Lookup_Engine::used_order_lookup()`, read by the endpoint to set
the SAME `$order_tool_used` flag. Reusing the existing suppression rather than
writing a second one was deliberate — two suppression mechanisms is two things
to keep correct. The flag is set for **every** order turn including guests and
empty results, because the exposure is on the QUESTION side: "where is order
10425" carries an order number whether or not we could answer it.

## Orders: the decline was never technical

The old rule ("no order path exists in this file and none may be added") was
reversed. It had felt like a security boundary and was not one: **identity is a
fact about the session, not something to be parsed out of a sentence**. What
lookup mode genuinely cannot do is understand the question.

The boundary survives because the engine **delegates whole** to
`DB_Chat_Order_Lookup` — no `get_current_user_id()`, no `wc_get_orders()`, no
`$wpdb`, no identifier anywhere in the file. See
[[order-lookup-identity-from-session]] for the enforcement pattern.

Two decisions that look arbitrary and are not:

- `get_my_orders( array() )` — the optional `order_number` is deliberately NOT
  passed, even though it is safe (it only narrows a set already pinned to the
  user). Extracting a number from free text is a guess, and a wrong guess
  reports "not found" about an order the customer really does own. Their recent
  orders answer the question anyway.
- Fields are read by **name** through an allowlist helper; nothing ever iterates
  an order record. So a new field appearing upstream cannot reach a reply by
  accident. The harness proves this with a deliberately leaky stub that offers
  address, phone, email, name, payment method and transaction id.

## Subject slot: derived every request, never stored

"berapa harga" after "do you have Shinko 705" works. Implementation choices:

- **There is no server-side session.** The slot is recomputed from the history
  the widget sends, by re-running the same keyword matching over earlier USER
  turns. Consequence worth keeping: expiry and topic-clearing are *recomputed*,
  so they cannot go stale or disagree with what the customer actually said.
- **The trigger is `build_query()` returning `''`** — nothing else. If the
  customer named anything of their own it wins outright and history is never
  consulted. "No extractable subject of its own" is enforced by construction,
  not by a heuristic.
- Reused only for price / stock / attribute. **`price_filter` is excluded on
  purpose**: "anything cheaper?" with no subject is a whole-catalogue browse,
  and silently narrowing it to a remembered brand hides the shop.
- Expires after 3 customer turns; cleared outright by policy / contact / order /
  identity. Walking history newest-first means a clearing turn is always seen
  before the older turn that named the product, so the clearing is free.
- **Assistant turns are never read as a subject.** Our replies list up to four
  products, so "the subject" in them is ambiguous — and feeding our own output
  back in is how a lookup starts confidently talking to itself.
- A remembered subject that no longer resolves to a published product is
  DROPPED and declined, never fuzzy-corrected into something else.

Forged history is acceptable here for exactly the reason recorded in
[[stateless-flow-replay]]: the payoff is published products, the same thing the
shop pages give anyone. **That reasoning does not extend to the order path**,
which derives nothing at all from history — `answer_orders()` is not even passed
the history argument.

## Fuzzy typo thresholds, and why each one is there

Only runs **after an exact search returned nothing**, so the normal path stays
literal and fast. Accepts only with ALL of: token ≥4 chars, edit distance ≤1
(≤2 for tokens of 8+), **exactly one candidate at that distance**, `similar_text`
≥82%, and matching metaphone OR first letter.

- The 82% floor is what rejects 4-letter words that are one cheap edit apart but
  nothing like each other (`tubb`/`tube`, `disk`/`disc` all score 75%).
- The unique-winner rule is the important one. `rackz` is one edit from both
  `rack` and `racks`; a tie is ambiguous and is declined outright, never
  resolved by taking the first. A wrong correction sends someone the wrong brand
  of brake pad — worse than a decline.
- Real check that it is calibrated: **`shimano`, `brembo`, `pirelli` and
  `michelin` are all declined** rather than corrected into stocked brands.
  `shinkoo`→Shinko and `handgard`→handguard are accepted. When it corrects, the
  reply says so.
- **Candidates are the cached `product_brand`/`product_cat` term names plus a
  curated title-word list — never the 5,283 product titles.** Thousands of
  `levenshtein()` calls inside a customer request on a live store is not
  acceptable. The curated list (`fuzzy_terms()`, filterable) is the deliberate
  substitute, and that trade should be re-stated to anyone who asks why typos in
  a model number are not caught.

## Unmatched question log: the feedback loop

Option `db_chat_log_unmatched` (default **ON**), entries in
`db_chat_log_unmatched_entries` (autoload off, 200 FIFO, one option — not a
table, not a transient; a transient would evaporate on a cache flush exactly
when the owner goes looking).

- Stores `question` / `time` (unix) / `lang` and **nothing else**. That is why
  it can default on where `db_chat_logging` defaults off.
- **Only true fallbacks are logged, not "we couldn't find that product".** A
  product miss is a CATALOGUE gap, not a keyword gap, and the row shape has no
  field to tell them apart — a list the owner cannot act on by adding a keyword
  makes the whole feature feel like noise. One-line change if that judgement
  turns out wrong.
- Never an order question, plus a belt-and-braces skip for any message
  containing an email address or a 5+ digit run. That heuristic will drop the
  occasional legitimate row: dropping a worklist entry costs nothing, storing a
  phone number costs a customer something.

Read by the admin screen (another agent's file) through **`get_unmatched_questions( $limit = 0 )`,
`get_unmatched_count()`, `clear_unmatched_questions()`, `unmatched_logging_enabled()`,
all `public static`.** The engine checks no capability and no nonce — those are
the screen's job. Renaming any of them silently empties the viewer, so the
harness pins them against the screen's own probe list.

### The cross-file seam with the admin screen (settled Aug 2026)

The viewer lives in `class-settings.php` (a different agent's file), so the
option NAMES are now duplicated on both sides — as engine constants and as
`DB_Chat_Settings::UNMATCHED_OPTION` / `UNMATCHED_ENABLED_OPTION`. A rename on
either side produces an empty list or a dead toggle, never an error. The lookup
harness asserts both constants and the `register( ..., '1', sanitize_bool )`
line against the engine's constants for exactly that reason.

Two traps found by actually calling the other file's code rather than reading it:

- **Double-reverse on a newest-first input.** `normalize_unmatched()` did
  `array_reverse()` then a stable `usort` by time descending, on the assumption
  that the engine appends. True of the raw option, false of
  `get_unmatched_questions()`, which is already newest-first. The `usort`
  hid it whenever timestamps differed — so it only surfaced on rows sharing a
  second, which then rendered backwards at the TOP of the list. FIXED Aug 2026:
  the reverse now lives in the option-fallback branch only. **General shape: a
  normaliser shared between a raw-storage path and an already-ordered path will
  silently be wrong for one of them, and a sort will usually mask it.**
- **A settings section rendered after `</form>` looks perfect and never saves.**
  The other agent caught this on their own checkbox. Worth checking placement
  before debugging a "broken" setting.

**A refactor silently invalidates any test that reached past it.** The probe
that FOUND the double-reverse called the private `normalize_unmatched()`
directly by reflection. Once the fix moved `array_reverse()` into the caller,
that probe was exercising a path that no longer existed — and it did not error
or skip, it ran and confidently reported the fallback as newly broken. It came
within one message of being sent back as a regression against a correct fix.

**Rule taken from it:** probe at the highest entry point that still isolates the
thing under test; use reflection only where there is genuinely no seam. To force
a specific branch, force it through the code's own extension point (here the
`db_chat_unmatched_accessors` filter) rather than by calling the branch. A
white-box test is coupled to the structure a refactor moves, and it fails OPEN.

### Mutation testing found 3 of my own assertions decorative (Aug 2026)

Runner: `mutate-lookup-engine.py` — copy the plugin, revert ONE fix, run the
harnesses, require RED. 22 mutations across all four features. First run: 19
detected, **3 not**. All three passed for reasons that had nothing to do with
the fix.

- **Fuzzy similarity floor.** Test used "rotar"; its correction target "rotor"
  matches no product in the fixture, so dropping the floor to 0 still produced
  a decline — corrected, then discarded for finding nothing. The assertion could
  not tell "refused to correct" from "corrected and found nothing". **Pick a
  below-threshold token whose winner RESOLVES**: `shoen`→`shoei` at 80.0%, just
  under the floor, and Shoei is a real product. Now the floor is the only thing
  between the customer and a wrong helmet.
- **Fuzzy sound/first-letter guard.** No test had a candidate differing in both
  first letter and metaphone. `ghinko`→`shinko` at 83.3% (over the floor, unique
  winner) is rejected *only* by that guard. Writing it also caught a second
  error: `"ghinko tyres"` fails against CORRECT code, because the exact search
  succeeds on "tyres" and fuzzy never runs. Use the bare token.
- **Order guard in `log_unmatched()`.** Reverting it alone: green. It is
  unreachable under current routing (order intent pre-empts the fallback), so a
  single mutation gives a FALSE all-clear — defence in depth masking a revert.
  The **double** mutation (guard + the routing that makes it reachable) fires
  exactly 4 log-privacy assertions. So it is genuine belt-and-braces, not dead
  code — proven, not asserted. **Mutate every mechanism of a redundant pair.**

**The rule, from the finder agent and worth the whole exchange: reproduce first,
then fix. A test derived from a red run is known to work; a test written after
the fix is only known to pass.** The one assertion of mine that survived
unchanged was the `dispatch-harness` guard — the only one I had deliberately
made fail before trusting it.

Now 22/22 detected.

### Then the same technique killed two of MY claims (the honest ending)

The finder agent's rule — **when two things appear to cover the same ground,
find the input where they diverge, or do not claim they are independent** —
applied to my own code found two overclaims. Both comments said "checked twice"
or implied two live defences. Measurement said otherwise:

- **`log_unmatched()`'s guard, `$this->order_turn || $signals['scores']['order']`.**
  With routing mutated so the line is reachable, the `$signals` half rescues 4
  log-privacy assertions; `$this->order_turn` rescues **zero**, on any input the
  suite can produce. It is future-proofing for a fall-through `answer_orders()`
  that does not exist yet — not a second check. `$signals` is the one that must
  not be broken.
- **`order_field()`'s `in_array()` allowlist.** Disabling it changes no output
  and no test: all six call sites pass string literals already on the list. What
  actually prevents an order-record leak is that **nothing ever iterates the
  record** — mutating the renderer to splice the values in is caught by 7
  assertions. Rely on that one.

Both retained, both comments rewritten to name which half is load-bearing and to
say the redundancy claim was disproved by measurement. They are recorded in the
runner as `KNOWN_UNDETECTED`, asserted to STAY undetected — so if one ever starts
failing, the code has gained a real divergence and the comment needs revisiting.

**The generalised failure this whole exercise kept surfacing (5 instances between
two agents): asserting that two things differ, or that two things are
independent, without ever finding the input where they diverge.** "Kept for
safety" is a claim like any other. An unmeasured claim is how you end up
believing you have two protections when you have one.

**Where reflection is genuinely unavoidable, guard it.** `dispatch-harness.php`
must enter `handle_with_lookup()` (private) because the public route needs a
WP_REST_Request, a nonce and the rate limiter. So it first asserts that the
behaviour under test is still INSIDE the method it enters — finder dispatch,
order flag, log call. Move any of them up into `handle_request()` and the
harness fails loudly instead of testing a dead path. The guard was itself
verified by mutating the source and confirming it fails; a guard nobody has
made fail is not known to work.
The permanent probe is `order-probe.php` — it drives `unmatched_questions()` and
asserts the invariant that actually matters: **both screen paths produce the
identical result**, on tied timestamps as well as distinct ones.

Their better judgement, worth repeating: when they proposed pointing their
fallback at the engine's real option name, the reflex answer ("don't churn your
file on my account") was wrong. A safety net aimed at an option that has never
existed is decorative. Try the real accessor first, keep the guess second.

Also confirmed by round-tripping every input: `sanitize_bool` emits only
`'1'`/`'0'`, and `unmatched_logging_enabled()` reads `'1'`→on,
`'0'`/`''`/`false`/`null`→off, unsaved→on.

## Compare intent (Aug 2026): the rules that are NOT visible in the code

`compare` answers "X vs Y" / "beza X dengan Y". The reasoning worth keeping:

**Separators are tiered, and the tiers are the whole safety.** `vs`/`versus`/
`lawan` always split. `or`/`atau` split only when the message carries NO
shipping/returns/warranty/payment/terms/contact/hours/location/order/identity
signal — otherwise "do you ship to Sabah or Sarawak" becomes a product
comparison. `and`/`dan`/`dengan` split ONLY alongside an explicit marker
(compare/beza/bandingkan/difference between), because on their own they are far
too common to be evidence of anything. Loosening any tier is not a small change.

**Compare, never rank — and the enforcement is structural, not wording.**
Nothing sorts (sides print in the order named; sorting by price is a ranking
with no word for it), nothing is computed across the two (no "RM 100 cheaper"),
and the decline-to-advise line is emitted on EVERY reply showing a product, not
only on recognised "which is better" phrasing — a guard keyed on known wording
is one a customer walks around by rephrasing. Being asked outright only adds the
WhatsApp pointer.

**Judgement words are scrubbed out of each side before it is searched OR
echoed.** "best pirelli vs shoei" would otherwise come back as: I could not find
anything for "best pirelli". That is our ranking word, in our bubble, in a
sentence we composed — a customer cannot tell it came from them.

**No fuzzy correction inside a comparison**, deliberately, though the product
path has it: "shinkoo vs shinko" would correct one side into the other and print
one product as if it were two.

**One string for the query and the echo.** The truncated side is what is
searched AND what is quoted back; there is no prettier second version. Same
lesson as the chip labels.

**A field the record lacks prints as "not listed", never omitted** — an absent
row reads as "the same" when it means "unknown". Unknown stock is said out loud
rather than defaulted to out-of-stock.

**The brief asked for a "Size options exist" row and it was NOT built.**
`DB_Chat_Product_Index::format_for_model()` emits name / permalink / categories /
brands / description / availability / stock_status / price and NO attribute data
at all, so there is no honest way to fill that row without reimplementing
retrieval — which the SPEC forbids in this file. A `note_size` caveat is emitted
instead when the message mentions size. If someone wants a real Size row, the
change belongs in the index, not here.

**A total miss on BOTH sides IS logged to the unmatched log**, unlike an
ordinary product miss. Different failure in kind: the likely cause is that the
split was wrong, which is a keyword gap the owner can close by adding a word.

### Two mutation-testing findings from this feature

- **The empty-side guard read as dead and was not.** Reverting it alone left the
  suite green, because the only input reaching it was also stopped by the topic
  guard. The realistic input that proves it: **"R&G vs Barkbusters"** — `R&G`
  normalises to `r g`, both tokens are dropped as single characters, so the left
  side is literally nothing and the comparison would search the catalogue with an
  EMPTY query (which `search_products()` answers with arbitrary products) and
  quote `""` back at the customer. The guard was real; the suite had no input
  that reached it. Overlapping guards must be mutated one at a time AND given an
  input each.
- **A "detected" mutation that was actually a crash.** One mutant showed
  `DETECTED, 0 failing`: the harness fatalled on `array_keys( $after[0] )` with
  an empty log, so the assertion under test never ran and every later assertion
  in the file was skipped. **A non-zero exit is not a result — read the failing
  count.** Test code that indexes into a result must read defensively, or the
  mutation runner reports success for the wrong reason.

Related: [[chatbot-plugin-architecture]], [[stateless-flow-replay]],
[[order-lookup-identity-from-session]], [[php-verification-honesty]].
