---
name: litespeed-cache-nonces
description: durianbikers.com runs LiteSpeed with a 7-day TTL and REST caching ON — never bake a nonce into HTML, and use litespeed_control_set_nocache (headers are ignored)
metadata:
  type: project
---

`curl -sI https://durianbikers.com/` on 2026-08-17 returned:

```
server: LiteSpeed
x-litespeed-cache-control: public,max-age=604800
x-litespeed-tag: 308_front,308_URL.…,308_PGS
```

**Full-page caching is ON with a 7-day TTL.** WordPress nonces from
`wp_create_nonce()` live ~12–24h.

**Why this is a trap:** any nonce printed into page HTML — via
`wp_localize_script`, a hidden input, or a data attribute — is dead for the
overwhelming majority of pageviews. Worse, it does not self-heal: reloading
serves the *same cached HTML with the same dead nonce*. And it is invisible
during development, because logged-in users bypass the page cache. It works
perfectly for the operator and is broken for every real customer.

**How to apply:** for any public AJAX/REST feature on this site, do not put a
nonce in cached HTML. Instead:

1. Expose a tiny GET REST route that returns a fresh nonce — and make it
   uncacheable with **`do_action( 'litespeed_control_set_nocache', $reason )`
   fired at the very top of the handler**, before any output.

   **No-cache HEADERS DO NOT WORK on this site — verified against live
   2026-08-17.** LSCWP has REST API caching ON (responses carry a `308_REST`
   tag) and applies its own `public,max-age=604800`. It ignores
   `Cache-Control`, `Pragma` and `Expires` on REST responses, and it *strips*
   an `X-LiteSpeed-Cache-Control: no-cache` response header rather than obeying
   it — that header never reaches the client. A route with every correct
   no-store header still returned `x-litespeed-cache: hit`. Only the
   `litespeed_control_set_nocache` action works. It is a no-op when LSCWP is
   absent, so no guard is needed.

   Send the headers too, for other proxies and CDNs — but treat them as
   defence in depth, never as the protection.
2. Have the JS fetch it lazily on first interaction.
3. On the protected route, distinguish the two nonce failures: **missing**
   nonce → 403 (genuine rejection), **present but invalid** → 409 with a
   distinct error code, which the client treats as "re-fetch the nonce and
   retry once". A bare 403 for an expired nonce is an unrecoverable dead end.

**The same trap applies to any admin-editable copy that gets localized into the
page** (greeting text, labels, button copy). The owner edits it, reloads, sees
the change because logged-in users bypass the cache, and concludes it shipped —
while visitors keep the old text for up to a week. It reads as "the plugin is
broken", not "the cache is stale". Fix it at the source: hook
`update_option_{$option}` / `add_option_{$option}` for exactly the options that
reach the HTML and fire `do_action('litespeed_purge_all')` (a harmless no-op
when LiteSpeed is inactive). Keep it to one purge per request with a static
guard, exclude server-side-only settings so credential edits don't purge, and
never flush the object cache along with it.

**The rule generalises past nonces and copy: nothing whose value depends on WHEN
the page is viewed may be localized into HTML.** The third case to hit this was
an "is a human available right now" flag for the chatbot's WhatsApp handoff
button (Aug 2026) — it flips twice a day, so a cached one is wrong within hours
and tells customers someone is standing by at 3am. Same fix as the nonce: it
rides on the `/session` GET route, which already sends `no-store`, while the
static half (number, label, hours summary) stays localized where caching is
free. Split any such feature that way — **static config in the cached HTML,
time-varying state on the no-store route** — and give the client one rule for
"feature off": omit the key in *both* places, never emit a half-built object.

When the time-varying value can't be computed reliably, fail in the direction
that under-promises: malformed opening hours report CLOSED, never open. **But
that rule is for the server, which must emit *some* value. The client has a
third option — say nothing — and it beats both.** Defaulting the widget to
"closed" while `/session` is in flight asserts something false during business
hours on the majority path and then visibly flips; rendering the button with no
availability claim at all breaks no rule and reads fine. Distinguish "pick the
safe value" from "make no claim" before extending fail-closed to a UI.

The same deployment reality breaks naive rate limiting: behind LiteSpeed on
Plesk, `REMOTE_ADDR` may be a shared upstream address, so a single-tier IP
limiter can lock out every customer at once. Use a tight bucket keyed on
IP + a client-generated session token, plus a loose high-ceiling circuit
breaker on IP alone. Hash identities with `wp_hash()`; never store raw IPs.

Implemented in `durianbikers-chatbot` — see [[chatbot-plugin-architecture]].
