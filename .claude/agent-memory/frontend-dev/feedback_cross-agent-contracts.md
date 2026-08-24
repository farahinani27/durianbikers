---
name: cross-agent-contracts
description: When my code and another agent's code must agree, pin the invariant as a comment at BOTH violation sites and verify the peer's symbol exists before citing it
metadata:
  type: feedback
---

When frontend code and another agent's backend code depend on each other's
behaviour, the agreement is not done when we agree in messages. Write a
do-not-touch comment at the site in MY file that would violate it, name the
other file and function explicitly, and ask the other agent to do the reciprocal
pin. Each side names the other.

**Why:** on the chatbot follow-up chips (Aug 2026) the finder truncates long
labels and gates its matching on the trailing ellipsis surviving the round trip.
Nothing in `chatbot.js` said so. Any later "tidying" of the label — Unicode
normalisation, stripping trailing punctuation — would have looked harmless in
review and silently turned every truncated chip into a tap that resolved to
nothing while typing still worked. What makes this class of bug expensive is not
difficulty, it is **distance**: the violating change is in a different file, a
different language, and months later. A comment at the violation site is the
only thing that survives that distance; a chat thread does not. The same feature
produced five instances of the same shape, all pairs that agreed on the day they
were written.

**How to apply:**
- Triggered by any agreed data shape, sentinel, ordering or encoding that
  crosses an ownership boundary. A cap or magic number gets the measurement and
  a "do not raise / do not lower" with reasons, or the next reader treats it as
  arbitrary.
- **Verify before citing.** Read the peer's file and grep the function name
  before writing it into a permanent comment — they build concurrently and the
  symbol may not exist, may be private, or may have moved. A stale pointer in a
  do-not-touch block is the exact failure it was written to prevent.
- Restating a peer's design observation as a requirement invents phantom
  constraints. Re-read their actual words before hardening anything.
- **Never write a point-in-time fact into a permanent artifact** — a code
  comment, a memory file, or a caveat carried between reports. "Every backend
  in production right now omits this key" was true the day I wrote it and wrong
  within days, because a peer wired the endpoint up. State the STRUCTURAL
  reason instead ("only the finder's own turns emit the key; the Claude path
  omits it"), which stays true through wiring rounds. When a caveat has been
  restated across several reports, re-read the source before repeating it —
  that is the same drift, just in prose.

Related: [[previewing-ui-locally]], [[preview-mocks-must-self-label]]
