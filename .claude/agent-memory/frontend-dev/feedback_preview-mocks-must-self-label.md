---
name: preview-mocks-must-self-label
description: Any stubbed/mock preview page must label every fake output inline, not just in a side panel — the owner mistook canned replies for a broken bot
metadata:
  type: feedback
---

Any preview/mock page with stubbed responses must announce the stub **at the
point of the output**, not only in a control panel. Minimum: a loud banner at
the top of the page, an honest control label ("Canned reply to send next" +
"Whatever you type, this is the reply you get"), and a per-output tag on every
faked response.

**Why:** on 2026-08-17 the store owner opened
`durianbikers-chatbot/assets/preview.html`, typed "do you ship to Sabah?" and
"do you ship to Singapore?", got the same canned reply both times, and
concluded the chatbot was broken and ignoring customers. The only disclaimer
was a small "Static mock. fetch is stubbed" line in a side panel. He was
looking at the chat window, not the panel. Treat that as a design failure of
the preview, not a user error.

**How to apply:** when a mock's output is indistinguishable from real output,
inject scaffolding markers styled deliberately unlike the product (dashed amber
box, monospace, uppercase) so the widget's true appearance can still be judged.
For a shadow-DOM widget, inject them from the preview file with a
MutationObserver on the message log plus a `<style>` appended to the shadow root
— never by editing the widget's own JS/CSS. Also give the mock a scenario that
matches the question the owner actually asks, so the preview answers their real
doubt.

Related: [[previewing-ui-locally]]
