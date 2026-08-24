---
name: php-verification-honesty
description: lint with /opt/homebrew/opt/php@8.3/bin/php (matches production 8.3.33), not the 8.5.9 on PATH — and never claim a check you didn't run
metadata:
  type: feedback
---

**Lint with the 8.3 binary, not the one on PATH.** Two Homebrew PHPs are installed
(as of 2026-08-17 — earlier the same day there was none at all, so verify rather
than assume in either direction):

- `/opt/homebrew/opt/php@8.3/bin/php` — **8.3.33, the server's exact version. Use this one.**
- `/opt/homebrew/bin/php` — 8.5.9, what bare `php` resolves to.

`/opt/homebrew/opt/php@8.3/bin/php -l <file>` before reporting done. Always use
the absolute path; bare `php` gives you the wrong version, and on some shells is
not on PATH at all.

**Version gap — the thing that will bite:** linting with 8.5 happily accepts
8.4/8.5-only syntax that fatals on an 8.3.33 server, and nothing warns you.
Since the matching binary is right there, use it as the primary check. Grepping
for the obvious offenders — `readonly` promotion misuse, asymmetric visibility
(`public(set)`/`private(set)`), `#[\Deprecated]`, `new Foo()->bar()` chaining
without parens — is now belt and braces rather than the main defence. Write to 8.3.

**Still true:** there is no local WordPress install, so `php -l` is a *syntax*
check only. It does not catch undefined functions, wrong argument counts, type
errors, or anything requiring WP/WooCommerce to be loaded. Say so when
reporting, rather than implying the code has been exercised.

**Why:** during the chatbot build the coordinator explicitly asked for the
verification gap to be stated plainly instead of claiming a check that was not
run — a false "verified" on a live store taking real payments is worse than an
honest gap. That expectation stands even now that the linter exists.

Related: [[chatbot-plugin-architecture]], [[litespeed-cache-nonces]].
