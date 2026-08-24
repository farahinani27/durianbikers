---
name: previewing-ui-locally
description: How to visually verify frontend work on this project given there is no local WordPress and staging redirects to live
metadata:
  type: project
---

There is no local WP install and staging.durianbikers.com 301s to live, so the
only safe way to see UI before deploying is a self-contained static mock page
that loads the real CSS/JS with a stubbed `fetch`. Ship one alongside any new
widget (see `durianbikers-chatbot/assets/preview.html` for the pattern).

**Why:** the owner cannot preview anything locally and every deploy touches a
production store that takes real payments, so "look at it on the site" is not
available as a review step.

**How to apply:** build the mock page next to the assets it exercises, give it
a scenario switcher for error/edge states, and point the owner at it instead of
asking them to deploy to look.

To check it myself, headless Chrome is on the Mac at
`/Applications/Google Chrome.app/Contents/MacOS/Google Chrome`, run with
`--headless=new --allow-file-access-from-files --window-size=W,H
--virtual-time-budget=N --screenshot=out.png file:///abs/path`. Two traps that
cost real time:

- Under `--virtual-time-budget`, `getComputedStyle()` returns the *start* value
  of any transitioning property while the painted screenshot shows the finished
  state. A "disabled button is still green" or "panel never opened" reading from
  computed style is usually this artifact, not a bug. Add
  `--force-prefers-reduced-motion` to kill transitions and get deterministic
  screenshots (and to exercise the reduced-motion path).
- `--window-size` is not the CSS viewport. At `--window-size=375,812` the layout
  viewport came out 500px wide (Chrome clamps it) and the PNG was clipped to
  375px. `--force-device-scale-factor` does not help either. Read
  `window.innerWidth` before concluding a media query is broken. For a genuine
  375px viewport, drive Chrome over CDP instead: launch with
  `--remote-debugging-port=N`, read the page target from
  `http://127.0.0.1:N/json/list`, connect with node's built-in `WebSocket`
  (no puppeteer installed, and node is only on the Mac), then
  `Emulation.setDeviceMetricsOverride {width, height, mobile:true}` ->
  `Page.navigate` -> `Page.captureScreenshot`. ~50 lines, also lets you
  `Runtime.evaluate` for assertions instead of the `--dump-dom` + `data-*`
  trick.

- Loading a page from a local `python3 -m http.server` in the assets dir is the
  reliable way to exercise relative script paths; `file://` breaks them. To
  script interaction, copy the page to a throwaway `_autotest.html` in the same
  served dir with an automation `<script>` appended, then delete it — do not
  add automation hooks to the real preview file.

- Some preview controls persist their state in `sessionStorage` and reload
  (the ones that change `dbChat`, which is only read at startup). That state
  survives `Page.navigate` in the same tab, so the mode left over from the END
  of one headless run silently poisons the START of the next — the first
  assertions fail against a scenario you never selected. Always set the mode
  explicitly and re-navigate before the first assertion, rather than trusting
  the default.

Two more things about driving the preview over CDP, learned the hard way:

- `Emulation.setEmulatedMedia {features:[{name:'prefers-reduced-motion',value:'reduce'}]}`
  is the clean way to exercise the reduced-motion path per-navigation, and unlike
  `--force-prefers-reduced-motion` it can be toggled mid-run.
- To answer "how much text fits before it wraps", mutate a REAL rendered node's
  textContent in a loop and watch its height, rather than calculating from font
  size and padding. The answer then comes from the widget's own CSS (including
  the shadow-root stylesheet and any max-width it inherits) and survives a
  restyle. Cheap, and it turns a design opinion into a number worth sending to
  another agent.
- The preview transcript ACCUMULATES. Every scenario you run leaves its rows in
  the log, so a whole-log count (`querySelectorAll('.db-chat-product').length`)
  silently sums every previous turn and fails an assertion that is actually fine.
  Assert against the LAST rendered group, not the log-wide total.

To drive a widget mounted in a shadow root from a test harness, reach it with
`document.getElementById('host').shadowRoot.querySelector(...)`, and surface
results by writing them to a `data-*` attribute on `body` and reading them back
with `--dump-dom` (console output is not captured by `--dump-dom`).

Related: [[repo-layout]]
