---
name: repo-layout
description: This git repo's root is the child theme content, not a full WP install — child theme files live under motormania-child/ not wp-content/themes/
metadata:
  type: project
---

The git repo at /Users/superfly2026/sites/durianbikers is NOT a full WordPress install. The child theme lives at `motormania-child/` directly off the repo root (e.g. `motormania-child/functions.php`, `style.css`, `header.php`, `assets/js/custom.js`).

**Why:** CLAUDE.md and deploy notes talk in terms of `wp-content/themes/motormania-child/` (the server layout), but only the child theme is checked into this repo. There is no `wp-content/`, no parent `motormania/` theme, and no plugins in the repo. Parent theme is pristine/off-limits and not tracked here.

**How to apply:** Use `motormania-child/...` paths, not `wp-content/themes/...`, when reading/editing in this repo. You cannot measure or read parent theme / plugin files here — flag when an audit needs them.
