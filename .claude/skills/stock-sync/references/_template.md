# Tab: <NAME>

**Status:** not yet reconciled.

Fill this in during the first run on this tab, then delete these prompts.

## Layout
Header row number and the exact column order. Layouts genuinely differ per tab — check with
`read_tab.py`, don't assume another tab's shape.

- Which row is the header? Which row does data start on?
- Is there a price column on this tab at all? (R&G has none — price lives on a separate tab.)
- Hidden rows? How many?
- An `OUT OF STOCK RANGE` or similar cutoff banner? Which row?
- Are stock cells floats? Is price text (`RM 155`) or a number (`2079`)?
- Do BRAND/SERIES cells repeat downward (blank = same as above)?
- Is it one table, or several side-by-side blocks across the columns?
- Does one product span multiple rows (per-year, per-size)? If so, which column is the
  sellable total?

## Matching
- Does the part/code column equal the WooCommerce SKU exactly?
- If there is no SKU column, what is the join key?
- Any prefix families that must not be substring-matched?

## Site query
- Brand taxonomy term name and **id**.
- Search stem that catches every spelling variant.
- Known false positives the search pulls in.

## Traps
- Duplicate codes in the tab?
- Items showing 0 on site while the masterlist has stock (lost sales — check this first)?
- Empty variable products with no variations?
- Stale duplicate tabs with a similar name?

## Scale
`<n>` masterlist codes → `<n>` matched on site.
