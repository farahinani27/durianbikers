# Page 55435 — "Choose Your Store" (durianbikers.com root)

The site root. An Elementor **Canvas** page whose single HTML widget holds the
entire two-panel store chooser. `_elementor_edit_mode` is `builder`, so Elementor
renders from the `_elementor_data` post meta — **editing `post_content` changes
nothing on the front end.**

Until 2026-10-12 this markup lived ONLY in the database and was tracked nowhere.
These files exist so it is reviewable and recoverable.

| file | what it is |
|---|---|
| `selector.current-live.html` | exactly what was live on 2026-10-12, before any change |
| `selector.with-crawlable-links.html` | the same, plus the three SEO changes below |

## Why it needed changing

The page had **zero `<a href>`**. Both panels were
`<section class="dbk-panel" data-url="...">` and navigation ran through
`window.location.href` in JS. Google does not follow `data-url` + a click
handler, so the site's most authoritative page gave crawlers no path in and
passed link equity nowhere.

## The three changes

1. Each panel's `.dbk-cta` becomes a real `<a href>` — `/my/home/` and
   `/gb/home/`, taken from that panel's own `data-url`.
2. The CTA click branch gains `e.preventDefault()` so `commit()` keeps control
   of navigation and the GSAP animation still runs — guarded on modifier keys
   and `e.button`, so ctrl/cmd/shift-click and middle-click still open a new tab.
3. CSS: `text-decoration:none` and `color:inherit` on `a.dbk-cta`, so the anchor
   renders identically to the span it replaced.

The panels themselves were deliberately NOT turned into anchors: each contains
`<button class="dbk-back">`, and interactive content inside an `<a>` is invalid
HTML. No hidden links were added — the page is full-screen `overflow:hidden`, so
any extra link would be invisible to users, which is cloaking.

## How to apply

Paste the contents of `selector.with-crawlable-links.html` into the page's
Elementor HTML widget and Update. Do NOT write `_elementor_data` directly unless
you have to — Elementor handles the slashing and regenerates its CSS on save.
A backup of the pre-change meta is on the server at
`/var/www/vhosts/durianbikers.com/elementor-55435-backup-20261012-1514.json`.

Verify afterwards by view-source on https://durianbikers.com/ — both `href`s must
be present in the served HTML. That is the entire point of the change.
