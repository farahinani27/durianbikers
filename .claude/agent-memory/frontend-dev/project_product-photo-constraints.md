---
name: product-photo-constraints
description: Catalogue photos are 800x800 but MIXED — some are white-bg WebP with no alpha, others are transparent PNG. Always check before promising a dark design.
metadata:
  type: project
---

The catalogue is **not consistent**. Both verified Aug 2026, same store:

- **MT helmet 58072** (`.webp`) — 800×800 RGB, hard white background, **no alpha**.
- **Axxis Draken S Parrot 23477** (`.png`) — 800×800 RGBA, **real alpha**, clean
  cut-out that sits perfectly on black.

So brand/import batch determines whether transparency exists. `full` is 800px in
both cases.

**Why it matters:** any design that floats a product over a dark background —
the "premium black hero" genre the owner likes — works for free on the PNG
products and renders as a white box on the WebP ones. On a dark background there
is no CSS fix for the latter: `multiply` turns the product black, `screen` keeps
the white background. On a *light* background `mix-blend-mode: multiply` does
dissolve the white cleanly.

**How to apply:**
- **Check the actual file before designing.** One `curl` + PIL check on the
  product's image tells you `mode` and whether alpha is present. Do not assume
  either way from the earlier MT finding.
- If the chosen products have alpha, a dark treatment is free — offer it.
- If they don't, propose light-background compositions and quote the cut-out
  cost before promising a dark one.
- When using multiply, the image's ancestor must not create a stacking context
  (no `z-index` on the wrapper) or the blend is isolated and does nothing.
- 800px is thin for a hero — fine at 1×, soft on retina. Flag it, don't upscale.
- Product `alt` text is the filename slug store-wide, so emit `alt` from the
  product name in any new template rather than trusting the attachment.

Related: [[previewing-ui-locally]]
