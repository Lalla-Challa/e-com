# Storefront — Full Functionality Report & Frontend Page Inventory

Purpose: a complete map of (1) what the app can do today, (2) what is missing for
production, and (3) every page that should exist on the **store**, **customer
account**, and **admin panel** — with the API backing each one, so frontend
design can proceed against real contracts.

Status labels used below:
- **[LIVE-FE]** — backend + frontend both exist (may need redesign, not rebuild)
- **[API-ONLY]** — backend endpoint works and is smoke-tested; no frontend page yet
- **[MISSING]** — neither backend nor frontend; needs API work too
- **[PARTIAL]** — exists but with a known limitation (noted inline)

---

## 1. What exists today (one-paragraph summary)

Decoupled headless store: a passive WordPress core (no WP admin, no WP APIs)
behind a custom PHP plugin (`app-core`, 16 API endpoints, 22 DB tables) serving
a React/Vite SPA. Auth is JWT with rotating refresh tokens in http-only
cookies. Checkout is one atomic transaction with a real pricing engine (tax
matched most-specific-first, shipping tiers, coupons with redemption tracking),
a stock ledger that writes a row for every movement, an explicit order state
machine that restocks on cancel, verified-buyer review moderation, a queued
email outbox (DB-backed, inspectable, not actually sent), and a minimal
headless CMS (pages + settings). 98 smoke assertions pass; all money is
DECIMAL.

**Key architectural rule for design:** the server owns all maths. Totals, tax,
discounts, shipping, stock and permissions are computed server-side; the SPA
renders them. Never re-derive a price in the client.

---

## 2. Store (public storefront + checkout)

| # | Page | Route | Status | Backing API | Notes |
|---|------|-------|--------|-------------|-------|
| 1 | Home | `/` | LIVE-FE | GET /products, GET /settings, GET /pages | Hero copy is CMS-driven; featured grid hardcoded to 4 products |
| 2 | Shop / catalog | `/shop` | PARTIAL | GET /products, GET /categories | Has search + basic filters; **no** category nav UI, no sort, no pagination UI, no price filter |
| 3 | Category page | `/shop?category=slug` | MISSING (FE) | GET /products?category= | API supports it; no dedicated route/design |
| 4 | Product detail | `/product/:slug` | PARTIAL | GET /products?slug=, GET /reviews | Single image only; no gallery, no variants, no size/qty selector, related products absent |
| 5 | Search results | `/search?q=` | MISSING | GET /products?q= | API supports `q`; no dedicated page (Shop does inline) |
| 6 | Reviews index | `/reviews` | LIVE-FE | GET /reviews | Aggregated across products; needs design pass |
| 7 | Cart | `/cart` | LIVE-FE | GET/POST/PATCH/DELETE /cart | Server-priced lines; needs design pass |
| 8 | Checkout | `/checkout` | LIVE-FE | POST /checkout, POST /quote, /coupons, /addresses | 3-step: address → review → done. Address book prefill, coupon, shipping picker |
| 9 | Order confirmation | (inline in /checkout) | LIVE-FE | — | Should become its own route `/order/:id/confirmation` |
| 10 | Content pages | `/page/:slug` | LIVE-FE | GET /pages | Renders CMS blocks (heading/paragraph/list); About, Shipping & Returns seeded |
| 11 | Auth (sign in / register) | `/auth` | LIVE-FE | POST /auth.php?action= | No password reset, no email verification |

### Store pages needed for production (not yet designed)

| # | Page | Route | Status | Backing API needed |
|---|------|-------|--------|--------------------|
| 12 | **Wishlist** | `/wishlist` | MISSING | needs tables + API |
| 13 | **Gift card / registry** | — | MISSING | out of prototype scope |
| 14 | **Size/variant selector** | on product page | MISSING | needs `product_variants` table + API |
| 15 | **Recently viewed** | on home/PDP | MISSING | client-local or API |
| 16 | **Shipping policy / FAQ** | `/page/shipping-returns` | API-ONLY | already served by CMS |

---

## 3. Customer account (logged-in dashboard)

| # | Page / feature | Route | Status | Backing API | Notes |
|---|----------------|-------|--------|-------------|-------|
| 1 | Account overview | `/account` | PARTIAL | GET /orders | Shows name/email/role + order list; no spend summary, no default-address display |
| 2 | Order history | `/account` tab | LIVE-FE | GET /orders | Table with status pills; no filters, no search, no date range |
| 3 | Order detail | inline expand | PARTIAL | GET /orders?id= | Items, totals breakdown, status timeline, notes. **Should be its own route** `/account/orders/:id` for shareable URL + mobile |
| 4 | Address book | `/account` tab | LIVE-FE | GET/POST/PATCH/DELETE /addresses | Full CRUD, one default per type enforced server-side |
| 5 | Order notes (customer) | on order detail | LIVE-FE | POST /orders | Customer-visible notes thread |
| 6 | Reviews (write) | on product page | LIVE-FE | POST /reviews | Verified-buyer gate server-side |

### Customer account pages needed for production

| # | Page / feature | Route | Status | Backing API needed |
|---|----------------|-------|--------|--------------------|
| 7 | **Profile / change password** | `/account/profile` | MISSING | needs `PATCH /auth.php?action=profile` + password-change endpoint |
| 8 | **Forgot / reset password** | `/auth/reset` | MISSING | needs token table + email send (mailer already queues) |
| 9 | **Email verification** | `/auth/verify` | MISSING | needs token + verified_at column |
| 10 | **Payment methods (stored cards)** | `/account/payments` | MISSING | needs Stripe-shaped table; only with real payments |
| 11 | **Subscriptions / reorders** | `/account/orders/reorder` | MISSING | reorder endpoint (cart + products exist; endpoint trivial to add) |
| 12 | **Returns / RMA requests** | `/account/returns` | MISSING | needs `return_requests` table + API + admin queue |
| 13 | **Notifications prefs** | `/account/notifications` | MISSING | needs per-user email/SMS flags |
| 14 | **Loyalty / store credit** | `/account/credit` | MISSING | needs ledger table; coupon engine is the natural base |

---

## 4. Admin panel (`/admin`, single-page with tabs today)

Current tabs: orders, products, inventory, categories, coupons, customers, outbox, cms.

| # | Section | Status | Backing API | Notes |
|---|---------|--------|-------------|-------|
| 1 | Orders list + status filter | LIVE-FE | GET /orders?status= | Inline status dropdown; no date filter, no order search, no pagination |
| 2 | Order detail | MISSING (FE) | GET /orders?id= | Backend returns full detail incl. items, totals, notes, allowed_next — no admin UI for it |
| 3 | Product list inline edit | LIVE-FE | GET /products, PATCH /products | Name/price/stock/active editable; no create-product form, no image upload |
| 4 | Inventory: low-stock report | LIVE-FE | GET /inventory.php?report=low_stock | |
| 5 | Inventory: stock adjust | LIVE-FE | POST /inventory.php | Delta + reason + note, writes ledger row |
| 6 | Inventory: valuation | LIVE-FE | GET /inventory.php?report=valuation | Cost×stock total |
| 7 | Inventory: movement log | MISSING (FE) | GET /inventory.php?report=movements | API exists, no UI |
| 8 | Categories CRUD | LIVE-FE (partial) | GET/POST/PATCH/DELETE /categories | Create + list only; no rename/reorder/delete UI |
| 9 | Coupons CRUD | LIVE-FE (partial) | GET/POST/PATCH/DELETE /coupons | Create + list; no edit/deactivate UI |
| 10 | Customers list | LIVE-FE | GET /customers | Order count + lifetime spend; no search UI (API supports ?q=), no detail view (API supports ?id=N) |
| 11 | Email outbox | LIVE-FE | GET /outbox | List; **no message-body viewer** (API supports ?id=N) |
| 12 | CMS pages | LIVE-FE (partial) | GET/POST/PATCH/DELETE /pages | Title + status editable; **body blocks not editable** |
| 13 | CMS settings | LIVE-FE | GET/PATCH /settings | Flat key/value editor |

### Admin sections needed for production

| # | Section | Status | Backing API needed |
|---|---------|--------|--------------------|
| 14 | **Dashboard home / KPIs** | MISSING | `GET /reports.php` — revenue/day, orders/day, AOV, conversion, top products. Tables support every aggregate |
| 15 | **Product create/edit form + image upload** | MISSING | POST /products, multipart image endpoint |
| 16 | **Product variants (size/color)** | MISSING | `product_variants` table + API |
| 17 | **Inventory movement log UI** | API-ONLY | GET /inventory.php?report=movements |
| 18 | **Order detail + fulfilment view** | API-ONLY | GET /orders?id= (already returns everything) |
| 19 | **Returns / RMA queue** | MISSING | return_requests table + API |
| 20 | **Tax rate management** | MISSING | CRUD on tax_rates (quote engine already reads them) |
| 21 | **Shipping method management** | MISSING | CRUD on shipping_methods |
| 22 | **Customer detail + admin notes** | API-ONLY | GET /customers?id=N |
| 23 | **Role / staff management** | MISSING | users table has role enum; needs admin CRUD + middleware |
| 24 | **Sales reports / exports** | MISSING | revenue, margins (cost column exists), CSV export |
| 25 | **CMS block editor** | MISSING | PATCH /pages already accepts body blocks; needs a visual editor |
| 26 | **Discount/campaign scheduler** | PARTIAL | coupons have starts_at/ends_at; needs calendar UI |

---

## 5. Production integrations required (beyond prototype)

These are the pieces that turn the prototype into a real, sellable store.
Each lists what the backend needs and which pages unlock.

### 5.1 Payments (the big one)
- **Provider**: Stripe (or Adyen/PayPal Commerce). Backend needs: a
  `payments` table (provider, intent id, amount, currency, status,
  order_id), a checkout step that creates a PaymentIntent, a signed webhook
  handler (HMAC path already exists in `webhooks.php` — currently only
  understands `payment.succeeded`), and refund endpoints.
- **Pages unlocked**: Payment step in `/checkout` (card/Apple Pay/Google Pay),
  `/account/payments` (stored methods), admin refund button on order detail,
  admin payouts view.
- **3DS / SCA**, saved cards via Stripe Customer, and idempotency keys are
  required for EU.

### 5.2 Transactional email (outbox already queues; nothing sends)
- The `email_outbox` table + `Mailer::enqueue` already write every message.
  Production needs a worker (cron) that drains the queue through a real
  transport (Postmark/SES/Resend). Two templates exist (order placed, status
  changed); production wants: welcome, password reset, shipping confirmation
  with tracking, refund receipt, review request (N days after delivery).
- **Pages**: admin email log viewer (outbox tab exists — needs a body viewer),
  per-user notification prefs.

### 5.3 Auth hardening
- Password reset (token table + email), email verification, change password,
  optional 2FA. Rate limiting and refresh-token rotation are already in place.

### 5.4 Shipping & fulfilment
- Carrier rate quotes (the shipping_methods table + free-over-subtotal logic
  is a static stand-in), label generation, tracking numbers on orders,
  packing-slip print view. Backend: `tracking_number`, `label_url` columns
  on orders + a carrier API.

### 5.5 Tax compliance
- The tax_rates table is a manual rate table. Production in the US/EU wants
  an automated layer (TaxJar/Avalara/Stripe Tax): nexus tracking, product tax
  codes, VAT-inclusive display rules. Swap-in point is `Pricing::taxFor()`.

### 5.6 Search & discovery
- Product search is a SQL LIKE. At scale: Algolia/Meilisearch/typesense.
  Also: faceted filters, sort, synonyms.

### 5.7 Media
- Single `image_url` string per product. Production needs an image gallery
  (multiple images per product — `product_images` table), an upload endpoint
  (S3/R2/Cloudinary), and admin media library.

### 5.8 Analytics & compliance
- Cookie consent + GA4/privacy events, GDPR data export/delete (users can
  request their data — the tables support it), accessibility pass.

---

## 6. Suggested build priority (frontend design order)

1. **Admin order detail + product create/edit + image upload** — these are the
   daily-driver screens an operator cannot live without; all backend-ready.
2. **Store: product gallery + variant selector** — the highest-impact
   storefront gap; currently one image, no options.
3. **Customer: order detail as a real route + profile/password pages** — cheap
   and expected everywhere.
4. **Payments (Stripe)** — the only true blocker to selling.
5. **Transactional email worker + templates** — orders are silent today.
6. **Admin KPI dashboard + reports** — needed once there is real traffic.
7. **Returns/RMA, wishlist, loyalty** — retention features, post-launch.
