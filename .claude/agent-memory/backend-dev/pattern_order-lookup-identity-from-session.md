---
name: order-lookup-identity-from-session
description: The rule for any LLM tool that touches personal data — identity comes from the session, never from the tool schema; plus why the caller-supplied-identity shape is the failure mode
metadata:
  type: project
---

When a tool an LLM can call reaches personal data, the identity of *whose* data
must never be expressible in the tool's input schema.

**Why:** the obvious design — `get_orders(customer_id)` — makes the model the
authority on whose data to read. Everything downstream then becomes prompt
engineering: the model can be argued into passing someone else's ID by a
customer, by injected text in a product description, or just by hallucinating.
A schema with no identifier field has nothing to argue about. On the Aug 2026
`get_my_orders` build the coordinator called this "the single most important
rule", and it is the part to carry to any future integration, not just this one.

**How to apply** (the shape used in `durianbikers-chatbot/includes/class-order-lookup.php`):

- Schema carries at most a *narrowing* parameter (`order_number`), never an
  identifying one. No `customer_id`, `email`, `user_id`.
- `$uid = get_current_user_id()` as the first statement. `0` returns "not signed
  in" and **runs no query at all** — `wc_get_orders( customer_id => 0 )` matches
  guest orders, so an early return is not merely tidy, it is the control.
- Re-assert ownership on every loaded object (`(int) $order->get_customer_id()
  === $uid`) before emitting a field. Assume the query can be wrong.
- A narrowing parameter filters a set already pinned to the user; it is never a
  lookup key. So a guessed foreign order number returns "not found", and the
  not-found wording must be identical whether the record belongs to nobody or to
  somebody else — the difference is itself information about another person.
- Emit a minimal field allowlist, and leave out the person's own name. They know
  it; it is leak surface for nothing.
- Never cache and never log. Withhold BOTH sides of a logged conversation, not
  just the answer: the question routinely contains the order number. Drop
  exception messages rather than redacting them — a data-store exception can
  carry query fragments.
- Prompt rule that is easy to miss: when not signed in, the assistant must NOT
  offer "give me your order number / email instead". Asking for identifying
  details in a chat widget normalises exactly the phishing behaviour we don't
  want, and the tool cannot use them anyway.

Verified with a throwaway harness that includes a *deliberately leaky*
`wc_get_orders` stub returning foreign orders — that is the test that proves the
per-object re-check earns its place. See [[new-tool-check-old-prompt]] for the
harness habit and [[chatbot-plugin-architecture]] for the plugin context.
