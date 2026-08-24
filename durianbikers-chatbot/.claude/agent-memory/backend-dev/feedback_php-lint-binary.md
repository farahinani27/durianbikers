---
name: php-lint-binary
description: Lint plugin/theme PHP with /opt/homebrew/opt/php@8.3/bin/php, never bare `php` on this Mac
metadata:
  type: feedback
---

Use `/opt/homebrew/opt/php@8.3/bin/php -l` for every PHP file touched in this repo.

**Why:** that binary is 8.3.33, the exact version the Exabytes/Plesk server runs. Bare `php` on the
Mac mini is 8.5.9, so it accepts and rejects different syntax than production — a clean lint there
proves nothing about the live store, and the store takes real payments.

**How to apply:** lint every file changed, before reporting done. On the Windows PC the path will
differ; check what is installed rather than assuming (see the global "never assume a tool is
installed" rule).
