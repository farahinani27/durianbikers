# Page 58450 — "Home" (durianbikers.com/{store}/home/)

The real storefront homepage — what the store selector at `/` links to. An
Elementor page (`_elementor_edit_mode` = `builder`, template
`custom-page-without-breadcrumb.php`), so it renders from the `_elementor_data`
post meta and **editing `post_content` changes nothing**.

Almost the whole page is ONE raw HTML widget (id `7a1c40e2`, ~251 KB). Like
page 55435, that markup lived only in the database and was tracked nowhere.

| file | what it is |
|---|---|
| `hero.before-h1-fix.html` | as live before 2026-10-13 |
| `hero.current.html` | what is live now |

## The change (2026-10-13)

The page had **three `<h1>` tags**: the hero ("On Track") plus two inside the
product showcase — `MT Helmet THUNDER 4 R SV` and `YAMANAKA 26 SEASON C5 GLOSS`.
A page should have one H1, and those two are a product name and its colourway,
not the subject of the page.

Both were changed from `<h1>` to `<h2>`, matching the H2s already used all around
them (`Big Names.OneGarage.`, `HIRP Shell`, `Ventilation`, …). The hero keeps the
only H1. **The diff is exactly 4 bytes** — two tag names, nothing else.

The resulting outline is: h1 "Track" → h2 section headings → h3 sub-items.

## How it was applied

Written to the `_elementor_data` meta via the WordPress REST API
(`POST /wp/v2/pages/58450` with `meta._elementor_data`), which is registered and
writable — no shell write to the database, and the stored value was read back and
compared byte-for-byte against what was sent.

Server-side backup of the pre-change meta:
`/var/www/vhosts/durianbikers.com/elementor-58450-backup-20261013-1307.json`

## If you edit this page in Elementor

Elementor will rewrite the whole widget. Re-check the H1 count afterwards —
`curl -s https://durianbikers.com/my/home/ | grep -c '<h1'` should return 1.
