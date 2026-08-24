---
name: stateless-flow-replay
description: How the chatbot's guided product finder holds multi-step state with no session store — replay the transcript instead of trusting a step number; plus the search_products fallback trap
metadata:
  type: project
---

Built Aug 2026 as `durianbikers-chatbot/includes/class-product-finder.php` (a
chip-driven category → brand → budget flow). Three decisions here outlive that
one feature.

## 1. Multi-step state with no session store: replay, don't trust

The endpoint already receives conversation history, so the flow's step is
**reconstructed** from it, never read out of it. History is client-supplied, so
"what step am I on" is user input.

The rule that makes it safe: accept the derived step **only if the entire
transcript is something the state machine could have produced**.

- Find the most recent user turn that is a trigger; anything earlier is a dead
  flow and cannot resume.
- After it, turns must alternate assistant, user, assistant, user. Any other
  shape is impossible and is rejected.
- Each assistant turn must be *exactly the question the machine would have asked*
  given the selections so far. A forged "Which brand?" before a category was
  chosen fails here.
- Each user turn must be a legal option **recomputed live from the catalogue**,
  not read back from the transcript.

Any failure → return null → the normal engine answers. Identical to the path a
customer takes when they change the subject, so abandonment and tampering share
one code path and there is no separate "trapped" state to get wrong.

**Why this is enough, and be honest about the limit:** a client can still replay
a *valid* transcript it built itself. The checks make a transcript consistent,
they do not prove it happened. That was acceptable **only because** reaching the
last step by forgery yields published, in-stock products — the same thing the
shop pages give anonymous visitors. Nothing is written, no personal data is in
reach. **If a flow like this ever touches an order, a price override, a discount
or anything writable, replay validation is NOT sufficient** — that needs a real
server-side token. Check this before reusing the pattern.

## 2. `DB_Chat_Product_Index::search_products()` has a filter-dropping fallback

It is right for keyword search and **catastrophic for anything asserting a
branch is non-empty**:

```php
// Last resort: drop the filters, keep the query.
if ( empty( $products ) && ( '' !== $category || '' !== $brand ) ) {
    $products = $this->run_query( $query, '', '', $in_stock_only );
}
```

An empty category therefore returns arbitrary products instead of nothing. The
finder runs its own `wc_get_products()` with **no fallbacks of any kind** for
this reason. Anyone reusing `search_products()` to answer "does this branch have
anything?" will get a confident yes for an empty branch.

Related trap, same file: `wc_get_products()` has no brand argument. Brand
filtering injects a `tax_query` via
`woocommerce_product_data_store_cpt_get_products_query` and removes it in a
`finally` block. Copy the `finally`, not just the `add_filter`.

## 3. "Buyable", not "published", is the test that excludes the broken imports

~1,450 of 5,283 products are zero-variation Shopify import artifacts (see
[[catalogue-data-quality]]). A term's `count` includes them, so a category can
look populated with nothing purchasable in it. The finder's `is_buyable()`
requires published + visible + in stock + **a real price**, and options are
*derived from buyable products* rather than listed and then checked — which
makes "no empty branches" structural instead of a validation step someone can
forget. `stock_quantity` is still never read; in stock or not is the whole
question.

## Chips contract (agreed with frontend-dev + coordinator, Aug 2026)

`chips` sits alongside `reply`/`products`/`pages` on the 200 response: a **flat
array of plain strings, max 6**. Tapping a chip sends the label verbatim as the
next user message — no hidden value, no client-side flow state, so a typed
answer and a tap are indistinguishable server-side (that is what makes stateless
replay work at all).

**The widget renders the first 6 in array order and silently drops the rest.**
It deliberately has no "always keep the last item" logic. So the escape option
("Any brand" / "Tunjukkan semua") must be built **within the first six entries**
— cap real options at 5 and append the escape as the 6th. Emitting 21 brands
plus an escape at the end truncates away exactly the thing that prevents a dead
end, and it fails silently on the longest lists only.

**Position is the only marker the frontend has**, since it receives bare
strings: the escape chip is identified as "the last one". That makes
escape-last a contract, not an implementation detail, and it had two silent
holes worth remembering because both look fine in normal data:

- A real option whose label equals the escape label (a brand literally named
  "Any brand") gets deduped against the escape, and it is the *escape* that
  disappears — leaving a chip row with no way out. Filter colliding real
  options out *before* appending the escape.
- Dedupe must key on the **truncated** label, not the full one. Keying on the
  full string and shortening afterwards lets two labels differing only past
  char 40 both survive and render as visibly identical chips — two taps that
  look the same and do different things. Dropping one is the correct outcome;
  the customer can still type the full name.

Both only bite on the widest branches and the longest names, which is exactly
where nobody looks. Test with a deliberately colliding label, not real data.

**The one that actually shipped broken: truncating a label silently breaks the
matcher.** Because the label IS the value, shortening a chip for display changes
what a tap sends. The matcher knew only the full brand name, so a brand called
"Helmet Visors & Replacement Parts Accessories" rendered a chip that returned
null when tapped — a dead end, in the class whose entire purpose is not having
dead ends. Typing the full name worked; tapping the chip did not, which is the
worst possible split because the tap is the path being advertised.

Rules that came out of it:

- **Display truncation and match truncation must be the same function.** One
  `chip_label()` used by both, so they cannot drift.
- **Announce the truncation** (ellipsis) and **cut on a word boundary**. A cut
  landing mid-word ("…& Fitting Ki") reads as a glitch and is harmless; one
  landing on a boundary ("Helmet Visors & Replacement Parts Access") reads as a
  complete, legitimate name and the customer cannot tell. Self-announcing
  truncation is cosmetic; silent truncation is a correctness bug.
- **Match a trailing ellipsis as a prefix, gated on the ellipsis being present.**
  Ungated prefix matching lets "hel" select "Helmets". Gating also survives the
  cap changing between the turn that drew the chip and the turn that receives it.
- The cap is 32, **measured** by frontend-dev against the real rendered chip
  (36 chars fit one line at 375px, the binding constraint), not chosen. Don't
  "tidy" it back to a round 40.

## The recurring failure: two paths that agreed once, then drifted

Three separate bugs on this feature were the same shape. Worth naming, because
each was invisible in normal data and each was found only by *executing* the
other side rather than reading it.

1. **Display vs matching.** Chip labels were truncated for display but matched
   against the full name — a tapped chip resolved to null while typing the same
   name worked.
2. **Sanitise vs dedupe.** Dedupe keyed the full label, then truncated.
   **CORRECTED — this one was not a bug.** Mutation testing showed both orders
   produce identical output: the truncated string becomes the array key either
   way, so the second label collapses onto the first regardless. It was a
   clarity fix. Recorded because the original write-up claimed "two identical
   chips could render", which was wrong, and a confident wrong entry in memory
   is worse than no entry.
3. **A normaliser shared between a raw-storage path and an already-ordered
   path.** `normalize_unmatched()` did `array_reverse()` then a stable sort,
   which is right for the append-ordered raw option and wrong for
   `get_unmatched_questions()`, which already returns newest-first. **The sort
   masked it whenever timestamps differed**, so it survived every test with
   realistic data; only rows sharing the same second exposed it, and they
   rendered backwards at the *top* of a list whose whole purpose is "most
   recent first".

4. **A guard and the thing it guards.** (The engine agent's, same feature.) Order
   suppression keyed on a flag set only by the Claude executor; adding a second
   brain that could also return order data diverged them silently and failed
   open into an error log.
5. **Code and its own test.** A probe that called the private normaliser by
   reflection kept passing after the ordering moved out to the caller — it was
   measuring a path the screen no longer uses, and reported a confident false
   regression. This is the worst member of the family, because the test is the
   thing you would normally use to detect divergence.

Generalisation: **a shared normaliser fed by both raw storage and an
already-normalised source will be silently wrong for one of them, and a
subsequent sort usually hides it.** Put the ordering assumption in the branch
where it actually holds, not in the shared function. And when testing an
ordering rule, use **tied keys** — distinct keys prove nothing, because the sort
does the work regardless.

**Testing rule that came out of #5:** enter at the highest point that still
isolates what is under test, and force a branch through the code's own extension
point (a filter) rather than by calling the branch directly. Reflection into a
private method couples the test to the structure a refactor moves, and it fails
*open* — the method still exists and still returns something plausible.

**The unifying statement, worth more than the five instances:** the bug is never
in either path. It is in the unexamined assumption that they still agree. All
five pairs agreed on the day they were written.

**Corollary — where a cross-boundary invariant has to live.** An agreement
reached in conversation protects nothing once the conversation ends. If two
files must agree, the constraint has to be pinned in *both*, next to the code
that would violate it. Concretely here: the trailing ellipsis on a truncated
chip is a protocol marker, not decoration — `class-product-finder.php` gates
prefix matching on it, and `assets/chatbot.js` `chipLabels()` must send the
label byte-for-byte. A perfectly reasonable-looking Unicode tidy-up in the JS
would silently reintroduce the dead-end tap, in a different file, in a different
language, months later. Both sides now carry a do-not-touch block naming the
other. A `32` with no explanation is the same problem in miniature.

Three practices that make a cross-file pin actually work:

- **Name the file and the symbol, not the behaviour.** "The finder matches by
  detecting the ellipsis" is a description; someone about to break it cannot
  grep for it. `includes/class-product-finder.php`, `match_option()` is a
  pointer. Skip line numbers — they rot faster than names.
- **Verify the peer's symbol exists before writing it into a permanent
  comment.** A do-not-touch block containing a stale pointer is exactly the
  failure it was written to prevent, and in a multi-agent build the file may not
  have existed when the peer described it. Cite nothing rather than cite
  something that has moved.
- **Verify the peer's *behaviour*, not just their symbol.** The claim "we only
  trim whitespace" is the whole invariant; read the function. Here
  `chipLabels()` in `assets/chatbot.js` was confirmed to do `.trim()` and
  nothing else — no Unicode pass, no punctuation stripping — which is what makes
  the ellipsis gate safe.

## Mutation-test regression tests, or they are decorations

**A test written *after* the fix has never been seen to fail.** Revert the fix
in a scratch copy and confirm the test goes red. On this feature that exercise
took ten minutes and found **three of four regression tests were decorative**:

- **State pollution.** The escape-collision fixture injected a 1-product brand
  into a catalogue that earlier sections had already loaded with seven others,
  so it never reached the top-5 slice and the collision under test never
  occurred. Shared mutable fixtures in a linear harness silently defeat later
  tests. Snapshot and restore globals around any test that needs a specific
  catalogue shape.
- **Asserting the wrong property.** The same test checked the escape chip's
  *label and position*, which are identical with and without the fix, instead of
  what the chip *does* (escape = no brand filter; brand option = filter to that
  brand). Assert behaviour, not appearance — appearance is exactly what a
  dedupe collision preserves.
- **Two mechanisms masking a revert — and "redundancy" is itself a claim to
  test.** Truncated-chip matching has a chip-form exact match and an
  ellipsis-gated prefix match. Removing either alone leaves the suite green;
  only removing both goes red. So a single-mutation check reports a false
  all-clear — mutate every mechanism together.

  **But do not stop there, because "genuine defence in depth" was also wrong.**
  Diffing single against double mutation showed *no* assertion that either
  mechanism uniquely rescues. Probing the realistic divergence — a frontend
  that truncates at its own cap — settled it:

      client cut == ours          both resolve
      client cut at another size  ONLY prefix matching resolves

  Prefix matching subsumes the other entirely. It is one load-bearing mechanism
  plus a fast path, not two. That was the **third** overclaim on this feature
  caught the same way, after the dedupe-ordering one. Pattern: when two things
  appear to cover the same ground, find the input where they diverge, or do not
  claim they are independent.

The one test that did bite immediately was the ordering one — because it was
written from a **reproduction** of the failure, before the fix existed. That is
the general rule: reproduce first, then fix; a test derived from a red run is
known to work, a test written afterwards is only known to pass.

**Anti-lesson worth keeping:** I asserted frontend-dev "identifies the escape
chip by position" when they had only said last position *reads* as "the other
one" — a remark about human perception, not a code dependency. I then hardened
a dependency that did not exist. The hardening was cheap and the bugs it found
were real, so no harm, but restating a peer's claim more strongly than they made
it is how phantom constraints get built into a system. Quote peers, don't
paraphrase them into requirements.

## Reuse the engine's public statics, don't copy the word lists

`DB_Chat_Lookup_Engine::malay_markers()`, `::stopwords()` and `::variants()` are
`public static` and filterable. The finder calls them so both stay steered by
`db_chat_lookup_malay_markers` etc. `::detect_language( $normalized )` was also
made `public static` (Aug 2026) so there is ONE detector rather than two that
drift — it expects the normalised form and does not fold spelling variants, so
normalise before calling it. **But** the engine's marker list does not
contain finder vocabulary — "cadangkan" is not in it, so language detection
answered a Malay trigger in English until the finder's own Malay triggers were
added as markers. Reusing a shared table does not mean it covers your inputs;
test the detection, not just the wiring.

Related: [[chatbot-plugin-architecture]], [[php-verification-honesty]].
