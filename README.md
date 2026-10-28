# Passive WordPress — Headless Custom Store

WordPress exists here **only** as a runtime wrapper and managed database
credential provider. Every byte of store logic — products, carts, orders,
reviews, auth — lives in custom `app_*` tables served by standalone PDO
endpoints. WooCommerce, `wp_posts`, `wp_postmeta`, `wp_users` and
`wp_sessions` are never touched.

On a new machine, install Docker, then:

```
git clone <this-repo>
cd <project>
docker compose up --build
```

The first start builds the images, creates the database, installs WordPress, and loads the demo shop. That can take several minutes. When the frontend container is running, open http://localhost:5173.

Demo logins (local only):

| who | email | password |
|---|---|---|
| store admin | `admin@appstore.test` | `admin-pass-123` |
| customer | `customer@appstore.test` | `customer-pass-123` |

No `.env` file is required for this local demo. Copy `.env.example` to `.env` only if you want your own passwords or secrets.

> Ports are 8081/3307 (not 8080/3306) so the stack can run alongside another
> WordPress container that already binds them.

## Layout extras

- `docker/wordpress-pdo/` and `docker/wordpress-cli-pdo/` — tiny images adding
  `pdo_mysql` on top of the official WordPress images (WP core itself uses
  mysqli, so the stock images omit the PDO driver our API requires).

## Layout

```
docker-compose.yml
.env.example                 JWT_SECRET, CORS_ORIGIN, PDO_DSN_FALLBACK, …
src/
├── frontend/                React 18 + Vite + zustand SPA (dist/ is production build)
└── plugins/app-core/
    ├── app-core.php         plugin shell: hooks + template_redirect trap (no logic)
    ├── includes/            db (PDO singleton), env, http, validate, auth-utils, migrations
    ├── api/                 standalone endpoints accessed directly over HTTP
    ├── migrations/          001…018 versioned schema (users, products, cart, orders, reviews, rate limits)
    ├── admin/dashboard.php  wp-admin mount point for the React dashboard
    ├── seed.php             CLI demo data (admin/customer + 6 products)
    └── wp-stub/wp-settings.php  neutralises WP boot when api files require wp-config.php
```

## First run

`docker compose up --build` does the database, WordPress install, plugin, and demo data by itself. Demo logins are turned on for that local start.

The seeder runs the migrations and creates:

| account | email | password |
|---|---|---|
| WP admin | `wpadmin` / `wpadmin@appstore.test` | `wpadmin-pass-123` |
| store admin | `admin@appstore.test` | `admin-pass-123` |
| customer | `customer@appstore.test` | `customer-pass-123` |

## API surface

Direct, WP-boot-free endpoints (Vite proxies `/api/...` here in dev; WP rewrite
rules route the same paths in production):

| route | file | notes |
|---|---|---|
| `/api/auth/register|login|refresh|logout|me` | api/auth.php | Argon2id (bcrypt fallback), HS256 access JWT 15 min, rotating refresh 30 days |
| `/api/products[?id|slug]` | api/products.php | filters: category, q, sort, min/max_price, in_stock |
| `/api/cart` GET/POST/PATCH/DELETE | api/cart.php | guest carts via signed cookie, price snapshot per line |
| `/api/checkout` POST | api/checkout.php | one transaction: lock → order → stock − → cart cleared |
| `/api/reviews` GET/POST | api/reviews.php | verified buyers only; posts publish immediately (auto-approved) |
| `/api/orders` GET/PATCH | api/orders.php | history, single order, admin status transitions |
| `/api/webhooks` POST | api/webhooks.php | `X-Timestamp` (unix s, ±300 s) + `X-Signature` = hex HMAC-SHA256 of `"<timestamp>.<raw body>"`; `payment.succeeded` → paid |

Every response uses the envelope `{ ok, data, meta }` or
`{ ok:false, error:{ code, message, fields? } }`.

## Smoke test the flow

One command runs the entire acceptance suite — boots the stack if needed,
installs/activates/seeds idempotently, builds the SPA if `dist/` is missing,
enables pretty permalinks if unset, then asserts the full contract (119
checks: auth incl. disabled accounts, pretty `/api` dispatch, `/app` SPA
routing + asset serving, cart, atomic checkout + rollback, timestamped webhook
signatures incl. stale/replay, auto-approved reviews, admin transitions, CORS,
rate limiting). Exits 0 green, 1 on failed assertions, 2 when the stack cannot
come up. Safe to run back-to-back.

```bash
bash scripts/smoke-test.sh          # or: BASE_URL=http://localhost:8080 bash scripts/smoke-test.sh
```

Manual walkthrough, if you want to poke at it by hand:

```bash
BASE=http://localhost:8081/wp-content/plugins/app-core/api
JAR=/tmp/cookies.txt

curl -sc $JAR -X POST $BASE/auth.php?action=register \
  -H 'Origin: http://localhost:5173' -H 'Content-Type: application/json' \
  -d '{"email":"buyer1@test.dev","password":"password123","full_name":"Buyer One"}'

curl -sb $JAR "$BASE/products.php?per_page=2"

curl -sc $JAR -X POST $BASE/cart.php -H 'Origin: http://localhost:5173' \
  -H 'Content-Type: application/json' -d '{"product_id":1,"quantity":2}'

curl -sb $JAR -X POST $BASE/checkout.php -H 'Origin: http://localhost:5173' \
  -H 'Content-Type: application/json' \
  -d '{"shipping_address":{"full_name":"B One","line1":"1 Linen St","city":"Portland","region":"OR","postal_code":"97201","country":"US"}}'
```

## Verify the headless guarantees

```bash
# no WP boot in the API path:
grep -rn "wp-load\|wp-includes" src/plugins/app-core/api/   # → no matches

# WP root answers JSON, not a theme:
curl -s http://localhost:8081/ | jq        # {"ok":true,"service":"app-core","status":"passive"}

# migrations are recorded:
MSYS_NO_PATHCONV=1 docker compose exec db sh -c \
  'mariadb -uwp_user -pwp_password wordpress_db -e "SELECT version FROM wp_app_migrations;"'
```

## Production notes

- `npm run build` in `src/frontend`; app-core streams `dist/` at `/app/**` and
  `/assets/**` (rewrite rules registered on activation), serving `index.html`
  with no-cache and hashed assets as immutable.
- Cookies are `HttpOnly; Secure; SameSite=Strict` — serve over HTTPS or browsers
  will drop them (loopback is exempt for localhost dev).
- `logs/error.log` inside the plugin dir collects API errors; rotate or ship it.
- Set `WEBHOOK_SECRET` before wiring any payment provider.
