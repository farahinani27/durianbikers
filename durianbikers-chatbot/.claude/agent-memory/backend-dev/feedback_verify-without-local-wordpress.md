---
name: verify-without-local-wordpress
description: With no local WordPress, verify PHP work with offline harnesses that load the real files against stubbed WP functions — and say plainly what that does not prove
metadata:
  type: feedback
---

There is no local WordPress install, so "tested" has to be defined honestly. The pattern that works
here: a standalone PHP script that stubs the handful of WP functions the code touches, fakes the
retrieval services with objects returning the REAL record shapes, then `require`s the actual plugin
file and asserts against it.

**Why:** stubbing lets the shipped code run rather than a copy of it, so the test fails when the
code changes. Duplicating the logic into the test would prove nothing. Two harnesses were worth
having for the chatbot: one for engine behaviour, one that loads the whole plugin bootstrap to
exercise the mode/enabled truth table.

**How to apply:**

- Keep harnesses in the session scratchpad, not in the plugin directory — deployment is a manual
  file upload and anything in `includes/` goes to production.
- Strip comments (`token_get_all`) before grepping source for forbidden calls, or a rule written in
  a comment ("no wp_remote_*") fails its own assertion.
- Add coupling assertions: if code depends on another class's method signature or on a literal
  string in its output, assert the other file still contains it.
- Always state in the report that this is syntax + offline logic only; real WooCommerce queries,
  caching and the widget are unverified until it is on the site.
