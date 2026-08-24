---
description: Reconcile one Qbee masterlist tab against WooCommerce (read-only audit, approval gate before any write)
argument-hint: <TAB> e.g. HELMETS, SPAREPARTS, BMC, R&G
---

Run a stock reconciliation for masterlist tab: **$ARGUMENTS**

Use the `agent-stock-sync` agent. It must:

1. Read `.claude/skills/stock-sync/SKILL.md` — the shared procedure and safety rules.
2. Read `.claude/skills/stock-sync/references/<tab>.md` for this tab. If none exists, work from
   `references/_template.md`, map the layout with `scripts/read_tab.py`, and write the new
   reference file at the end of the run.
3. Follow SKILL.md Steps 1–4, then **stop at the approval gate**.

If no tab was given above, ask which tab — do not assume HELMETS.

Read-only. Produce the audit tables and stop. Do not write to WooCommerce or to the Google
Sheet without explicit per-item approval from the operator.
