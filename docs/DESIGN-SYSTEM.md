# Storefront design — TÈSSILE Atelier

The original "warm linen home goods" storefront has been replaced end to end:
design system, page structure, navigation and copy. The store now reads as a
textile atelier — cloth sold by the metre, haberdashery, tools and patterns —
and every screen the SPA exposes has been rebuilt against it.

## Backend decision

**The PHP/MySQL API in `src/plugins/app-core` is kept and extended; it is not
rewritten.** It already owns auth, cart, pricing, checkout, inventory, orders
and the CMS, and it is covered by 99 passing smoke assertions. Re-implementing
that to match a visual redesign would have traded working, tested behaviour for
risk with no user-visible benefit.

What changed on the server instead:

| Change | Why |
| --- | --- |
| `GET /api/reviews` without `?product` returns the aggregate ledger (product join, pagination, rating filter, `meta.summary` with count/average/distribution) | The index page used to 500: `product` was validated as required, so the storefront's own reviews page was an error by design |
| `Http::fail()` maps an escaping `ValidationException` to **400 VALIDATION_FAILED** instead of a 500 | Same class of bug for any endpoint that validates query parameters |
| `products` list/detail expose `specs` (decoded `metadata`) | Fibre facts render from the server, never re-authored in the client |
| `cart` lines expose `specs` | The bag can speak in metres and show composition without re-fetching each product |
| Migration `016_tessile_catalogue` — chapters, 12 cloth products, storefront copy | The catalogue and its copy are content, not markup |
| Migration `017_cloth_dossiers` — tailoring/care dossier in `metadata` | The product page's technical sections are catalogue facts |
| Migration `018_appraisal_ledger` — tailored appraisals on four cloths | The review ledger needs cloth in it to be readable |

Prices, tax, carriage and discounts remain server-computed; the SPA only
formats them. `metadata` JSON carries the fabric specification, so no schema
change was needed for GSM, width, weave, origin or reference numbers.

### Settlement

No payment provider is connected. Checkout collects the destination and
carriage method, lodges the order, and says plainly that settlement is by
invoice — the design's own "guild trade account" clause. Nothing pretends a
card was charged.

## Design system

One stylesheet, [`src/frontend/src/styles/global.css`](../src/frontend/src/styles/global.css),
implementing the tokens from `frontend_design/t_ssile_atelier/DESIGN.md`:

- **Surfaces** `#FAFAF7` canvas, `#FFFFFF` sheet, `#F3F2ED` hover, `#E8E6E0` hairline.
- **Ink** `#1A1A1A` structure, `#6B6B68` metadata; accents `#8B6F47` saddle, `#B33A3A` madder, `#3F6B4A` moss.
- **Type** EB Garamond for editorial display and cloth names, Hanken Grotesk for everything utilitarian; 11px uppercase eyebrows at `0.2em`; all prices and measurements in tabular figures.
- **Geometry** sharp corners (0px, 2px micro-radius on buttons/swatches), 1px hairlines, no drop shadows, no lift-on-hover — state reads as a border or background shift.
- **Layout** 1440px cap, 4rem desktop / 1.25rem mobile margins, hairline-divided sections, 4:5 product plates.

Component vocabulary: `.masthead` (centred wordmark between two nav groups),
`.cloth-card`, `.archive-card`, `.ledger-row`, `.spec-line`, `.tag`, `.totals`,
`.review-item`, `.kpi`, `.data-table`.

## Pages

| Route | Screen |
| --- | --- |
| `/` | Hero, archive plate, category directory, featured yardage, discipline tenets, dispatches, correspondence |
| `/shop` | Cloth catalogue — chapter/search/price/stock filters, grid and ledger views |
| `/product/:slug` | Cloth dossier — specification, batch record, tailoring and care protocol, appraisals, related cloth |
| `/cart` | Atelier bag — metre-based cut ledger, server quote, trade code |
| `/checkout` | Three-step dispatch: dossier → destination & packaging → settlement, then the confirmation ledger |
| `/reviews` | Archival appraisal ledger with score distribution and rating filter |
| `/page/:slug` | CMS dossier (eyebrow, heading, paragraph, list, quote, figure blocks) |
| `/auth` | Client registry — sign in / establish a dossier |
| `/account` | Client dossier — overview metrics, order ledger with detail, dispatch directives, profile |
| `/admin` | Console — KPIs, fulfilment, products, inventory + movement log, privilege codes, clients, outbox inspector, CMS |

## Known gaps (unchanged by this work)

- No payment provider, no password reset or email verification, no variants,
  no wishlist, no image uploads, no carrier tracking numbers.
- Search is a SQL `LIKE` behind `/shop`; there is no dedicated `/search` route
  (the masthead "Search" focuses the catalogue search field).
- The catalogue still carries the original six home-goods products — the smoke
  suite asserts their slugs, prices and stock, so they were left listed rather
  than deactivated.
- `frontend_design/` holds the reference mockups; nothing at runtime reads it.
