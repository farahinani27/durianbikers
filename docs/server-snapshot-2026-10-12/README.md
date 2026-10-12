# Server snapshot — motormania-child, 2026-10-12

Pulled from the LIVE theme over SSH:
`/var/www/vhosts/durianbikers.com/staging.durianbikers.com/wp-content/themes/motormania-child/`

These are the five files where the live theme and this repo DIVERGE. They are a
reference copy for merging — they have deliberately NOT been copied over the
repo's versions, because the divergence is two-way and a one-way sync would
destroy work in either direction.

| file here | local lines | server lines | local-only lines | server-only lines |
|---|---|---|---|---|
| `functions.php` | 563 | 1077 | 108 | 433 |
| `style.css` | 5182 | 5442 | 73 | 333 |
| `custom.js` | 275 | 356 | 16 | 97 |
| `footer.php` | — | — | 2 | 2 |
| `template-parts_header_header-2.php` | — | — | 66 | 26 |

`template-parts_header_header-2.php` belongs at
`motormania-child/template-parts/header/header-2.php` (flattened here to keep
the snapshot one directory deep).

Merging is a per-file judgement call for whoever owns that work — the local-only
lines are unreleased local development, the server-only lines are live edits made
directly on the server. Diff before deciding anything.
