---
name: new-tool-check-old-prompt
description: When adding a capability to an LLM agent, grep the existing prompt for rules that told it to decline — a stale refusal rule silently suppresses the new tool
metadata:
  type: feedback
---

Adding a tool is not done when the tool works. Before reporting, grep the system
prompt for every rule of the shape "you cannot answer X", "direct X to a human",
"we have no data on X" and check each one against the new capability.

**Why:** on the `search_pages` build (Aug 2026) the prompt still said shipping,
delivery, returns and refunds all go to human contact — correct when products were
the only data source, wrong the moment pages became searchable. That one line would
have beaten the new tool: the model reads the refusal, never calls `search_pages`,
and the feature looks built while doing nothing. The coordinator caught it, not me.
A silently inert feature is worse than an unbuilt one, because nobody goes looking.

**How to apply:**
- Grep for `contact`, `human`, `cannot`, `CANNOT`, `go to`, `direct them` in the
  prompt and read every hit, not just the one you were pointed at.
- Move a now-answerable topic OUT of any "what you cannot answer" block. Sitting
  under that heading is itself an instruction, even if the bullet text is fine.
- Say the positive form explicitly: "these topics REQUIRE a tool call before you
  reply; declining without searching is a failure, not caution."
- Keep the refusals that are still true. On this plugin: fitment, sales/discounts and
  dimensions stay — no page fixes a missing product attribute.

**Second instance, Aug 2026 — and it is not only LLM prompts.** Adding the
`compare` intent to the FREE lookup engine left three places still telling
everyone the feature does not exist: `class-settings.php` (admin copy: "What it
cannot do: ... comparisons"), `DEPLOY.md` line ~145 and its "Compare, recommend
or explain" bullet. The settings string is the dangerous one — the STORE OWNER
reads it, concludes lookup mode cannot compare, and switches to the paid mode or
never mentions the feature. So the rule generalises: after adding a capability,
grep for the sentences that describe what the component *cannot* do, in prompts,
admin copy AND docs. Keep the half that is still true (no recommendations, no
fitment); fix the half that is not.

Also from the same review: verify offline. `php -l` proves nothing about behaviour.
A throwaway harness that stubs ~20 WP functions and asserts on the real class caught
three genuine bugs (ranking, HTML entities in titles) that linting could never see.
Watch out for one trap: in a top-level harness script, `$pages = ...` overwrites
`$GLOBALS['pages']`, so name fixture globals distinctly.

Related: [[chatbot-plugin-architecture]], [[php-verification-honesty]].
