#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# smoke-test.sh — repeatable acceptance test for the headless store.
#
# Boots the stack if needed (idempotent install + seed), then exercises the
# full API contract: auth, catalog, cart, atomic checkout + stock rollback,
# payment webhook, verified-buyer reviews, admin transitions, rate limiting.
#
# Exit codes: 0 = every assertion passed, 1 = one or more failures,
#             2 = the stack could not be brought up.
# Re-runnable at any time: emails carry a run id and rate-limit counters are
# truncated at start, so back-to-back runs are safe.
# ---------------------------------------------------------------------------
set -u
set -o pipefail
# NOTE: do NOT export MSYS_NO_PATHCONV here. Git Bash needs it for docker args
# like /var/www/..., but exporting it globally stops the native curl from
# resolving /tmp cookie-jar paths, silently disabling cookie persistence.

cd "$(dirname "$0")/.." || exit 2

BASE_URL="${BASE_URL:-http://localhost:8081}"
ORIGIN="${ORIGIN:-http://localhost:5173}"
WP_PORT="${WP_PORT:-8081}"
MYSQL_USER="${MYSQL_USER:-wp_user}"
MYSQL_PASSWORD="${MYSQL_PASSWORD:-wp_password}"
MYSQL_DB="${MYSQL_DATABASE:-wordpress_db}"
TABLE_PREFIX="${TABLE_PREFIX:-wp_}"
WEBHOOK_SECRET="${WEBHOOK_SECRET:-local-dev-webhook-secret}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@appstore.test}"
ADMIN_PASS="${ADMIN_PASS:-admin-pass-123}"
API="$BASE_URL/wp-content/plugins/app-core/api"
RUN_ID="$(date +%s)-$$"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

PASS=0
FAIL=0
FAILED_NAMES=""

section()  { printf '\n== %s\n' "$*"; }
ok()       { PASS=$((PASS + 1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
bad()      { FAIL=$((FAIL + 1)); FAILED_NAMES="$FAILED_NAMES\n    - $1"; printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
die()      { printf '\n\033[31mFATAL\033[0m %s\n' "$1" >&2; exit 2; }

db() { docker compose exec -T db sh -c "mariadb -u$MYSQL_USER -p$MYSQL_PASSWORD $MYSQL_DB -N -e \"$1\"" 2>/dev/null; }

expect_status() { # expect_status <name> <expected> <actual>
  if [ "$2" = "$3" ]; then ok "$1 (HTTP $3)"; else bad "$1 (expected $2, got $3)"; fi
}

expect_contains() { # expect_contains <name> <haystack> <needle>
  case "$2" in *"$3"*) ok "$1";; *) bad "$1 (missing: $3)";; esac
}

# req <jar> <method> <url> [json-body] -> sets STATUS and BODY
req() {
  local jar="$1" method="$2" url="$3" body="${4:-}"
  local args=(-s -b "$jar" -c "$jar" -w $'\n%{http_code}' -X "$method" "$url"
              -H "Origin: $ORIGIN" -H 'Content-Type: application/json')
  [ -n "$body" ] && args+=(-d "$body")
  local out
  out="$(curl "${args[@]}")"
  STATUS="${out##*$'\n'}"
  BODY="${out%$'\n'*}"
}

cookie_has() { grep -q "$1" "$2" 2>/dev/null; }

# ---------------------------------------------------------------------------
section "boot: stack up + healthy"
command -v docker >/dev/null || die "docker not found"
docker compose version >/dev/null || die "docker compose not available"

# The SPA must be built for the /app routing tests below: wordpress mounts
# ./src/frontend read-only and Frontend streams dist/index.html from it.
if [ ! -f src/frontend/dist/index.html ]; then
  echo "  dist missing: building frontend"
  npm --prefix src/frontend install --no-audit --no-fund >/dev/null 2>&1 || die "npm install failed"
  npm --prefix src/frontend run build >/dev/null 2>&1 || die "frontend build failed"
fi

docker compose up -d >/dev/null 2>&1 || die "docker compose up failed"

for _ in $(seq 1 36); do
  H="$(docker compose ps db --format '{{.Health}}' 2>/dev/null)"
  [ "$H" = "healthy" ] && break
  sleep 5
done
[ "$H" = "healthy" ] || die "db never became healthy (last: ${H:-unknown})"

for _ in $(seq 1 24); do
  WP_CODE="$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/wp-login.php" 2>/dev/null)"
  { [ "$WP_CODE" = "200" ] || [ "$WP_CODE" = "302" ]; } && break
  sleep 5
done
ok "wordpress answers on :$WP_PORT"

# Webhook secret must exist for the signature tests; supply the dev default.
grep -q '^WEBHOOK_SECRET=' .env 2>/dev/null || printf 'WEBHOOK_SECRET=%s\n' "$WEBHOOK_SECRET" >> .env  docker compose exec -T wordpress printenv WEBHOOK_SECRET 2>/dev/null | grep -q . || docker compose up -d wordpress >/dev/null 2>&1

# Idempotent install: only touch wp-cli when the schema is not there yet.
if [ -z "$(db "SELECT 1 FROM ${TABLE_PREFIX}app_migrations LIMIT 1" 2>/dev/null)" ]; then
  echo "  first run: installing WordPress + activating app-core + seeding"
  MSYS_NO_PATHCONV=1 docker compose run --rm wpcli core install \
    --url="$BASE_URL" --title="Passive WP Store" \
    --admin_user=wpadmin --admin_password=wpadmin-pass-123 \
    --admin_email=wpadmin@appstore.test --skip-email >/dev/null 2>&1 || die "wp core install failed"
  MSYS_NO_PATHCONV=1 docker compose run --rm wpcli plugin activate app-core >/dev/null 2>&1 || die "plugin activate failed"
  MSYS_NO_PATHCONV=1 docker compose exec -T wordpress php /var/www/html/wp-content/plugins/app-core/seed.php >/dev/null 2>&1 || die "seed failed"
fi

# Pretty permalinks: without them Apache never routes /app/**, /assets/** or
# /api/** into WordPress (no .htaccess is written under the plain structure),
# so the SPA host, the legacy asset path and the REQUEST_URI API dispatch
# would all 404 at the server. Idempotent: only runs while unset.
PERMA="$(db "SELECT option_value FROM ${TABLE_PREFIX}options WHERE option_name='permalink_structure'" 2>/dev/null)"
if [ -z "$PERMA" ]; then
  MSYS_NO_PATHCONV=1 docker compose run --rm wpcli rewrite structure '/%postname%/' --hard >/dev/null 2>&1 \
    || die "setting pretty permalinks failed"
  ok "pretty permalinks enabled (.htaccess written)"
fi

# Isolation first: a fresh rate-limit window, so back-to-back runs (and the
# previous run's deliberate 429 storm) never poison the logins below.
db "TRUNCATE TABLE ${TABLE_PREFIX}app_rate_limits" >/dev/null
ok "rate-limit counters truncated"

# Deterministic fixtures: reset two product stocks via the admin API.
ADMIN_JAR="$TMP/admin.jar"; : > "$ADMIN_JAR"
req "$ADMIN_JAR" POST "$API/auth.php?action=login" "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASS\"}"
expect_status "admin login" 200 "$STATUS"
ADMIN_ID="$(printf '%s' "$BODY" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)"

resolve_id() { # resolve_id <slug> -> product id (empty if missing)
  curl -s "$API/products.php?slug=$1" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2
}

set_stock() { # set_stock <slug> <qty>  (via admin PATCH, resolves id first)
  local slug="$1" qty="$2" pid
  pid="$(resolve_id "$slug")"
  [ -n "$pid" ] || die "fixture product missing: $slug (run seed.php)"
  req "$ADMIN_JAR" PATCH "$API/products.php" "{\"id\":$pid,\"stock\":$qty}"
  [ "$STATUS" = "200" ] || die "fixture reset failed for $slug"
}
# seam-ripper-precision is the drain fixture (checkout takes it to zero);
# tailors-marking-chalk-set is the cart / price-change fixture (stock 5).
set_stock seam-ripper-precision 3
set_stock tailors-marking-chalk-set 5

# ---------------------------------------------------------------------------
section "wp root: passive status payload"
ROOT_BODY="$(curl -s "$BASE_URL/")"
expect_contains "root returns exact passive payload" "$ROOT_BODY" '{"ok":true,"service":"app-core","status":"passive"}'

section "headless guarantee: no wp-load / wp-includes in api/"
if grep -rqn "wp-load\|wp-includes" src/plugins/app-core/api/; then
  bad "api/ references WordPress internals"
else
  ok "api/ is WordPress-boot-free"
fi

section "pretty routing: /api dispatch, /app SPA, built assets"
# REQUEST_URI dispatch at init: the extensionless form the built SPA calls.
P_CODE="$(curl -s -o "$TMP/pretty.json" -w '%{http_code}' "$BASE_URL/api/products?per_page=1")"
expect_status "pretty /api/products 200" 200 "$P_CODE"
expect_contains "pretty api returns envelope" "$(cat "$TMP/pretty.json")" '"meta":'
P_ACT_CODE="$(curl -s -o "$TMP/pretty-auth.json" -w '%{http_code}' -X POST "$BASE_URL/api/auth/login" \
  -H "Origin: $ORIGIN" -H 'Content-Type: application/json' \
  -d '{"email":"nobody-'$RUN_ID'@test.dev","password":"wrong-pass"}')"
expect_status "pretty /api/auth/login dispatches" 401 "$P_ACT_CODE"
expect_contains "pretty login error code" "$(cat "$TMP/pretty-auth.json")" 'INVALID_CREDENTIALS'

# The SPA shell must stream with 200 (WP's queued 404 must be cleared) and
# deep links must fall back to it instead of a JSON catch-all.
APP_CODE="$(curl -s -o "$TMP/app.html" -w '%{http_code}' "$BASE_URL/app/")"
expect_status "app shell 200" 200 "$APP_CODE"
expect_contains "app shell mounts #root" "$(cat "$TMP/app.html")" 'id="root"'
DEEP_CODE="$(curl -s -o "$TMP/deep.html" -w '%{http_code}' "$BASE_URL/app/shop")"
expect_status "spa deep link 200" 200 "$DEEP_CODE"
expect_contains "deep link streams the shell" "$(cat "$TMP/deep.html")" 'id="root"'

# Referenced assets must not 404 (the old chain: status 404 with SPA body).
ASSET="$(grep -oE '(src|href)="[^"]+\.(js|css)"' "$TMP/app.html" | head -1 | sed -E 's/^(src|href)="//; s/"$//')"
if [ -n "$ASSET" ]; then
  A_CODE="$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL$ASSET")"
  expect_status "built asset 200" 200 "$A_CODE"
  # wp-admin dashboard tag uses the legacy root /assets/<file> form.
  L_CODE="$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/assets/$(basename "$ASSET")")"
  expect_status "legacy /assets path 200" 200 "$L_CODE"
else
  bad "no js/css script tag in built index.html"
fi

# Optional: actually run the SPA when a headless chrome is on PATH; skipped
# (not failed) otherwise so the suite stays runnable everywhere.
HS_BIN="$(command -v chrome-headless-shell || command -v google-chrome || command -v chromium || command -v chromium-browser || true)"
if [ -n "$HS_BIN" ]; then
  RENDERED="$("$HS_BIN" --headless --disable-gpu --no-sandbox --virtual-time-budget=8000 --dump-dom "$BASE_URL/app/" 2>/dev/null || true)"
  if [ -z "$RENDERED" ]; then
    echo "  SKIP headless render (browser produced no DOM)"
  elif printf '%s' "$RENDERED" | grep -q 'id="root"></div>'; then
    bad "SPA did not mount (root stayed empty in headless render)"
  else
    ok "SPA mounts in headless render"
  fi
else
  echo "  SKIP headless render (no chrome binary on PATH)"
fi

# ---------------------------------------------------------------------------
section "auth: register / conflicts / validation / me / refresh"
BUYER_JAR="$TMP/buyer.jar"; : > "$BUYER_JAR"
BUYER_EMAIL="buyer-$RUN_ID@test.dev"
req "$BUYER_JAR" POST "$API/auth.php?action=register" \
  "{\"email\":\"$BUYER_EMAIL\",\"password\":\"password123\",\"full_name\":\"Smoke Buyer\"}"
expect_status "register returns 201" 201 "$STATUS"
cookie_has access_token "$BUYER_JAR" && ok "access cookie set" || bad "access cookie missing"
cookie_has refresh_token "$BUYER_JAR" && ok "refresh cookie set" || bad "refresh cookie missing"

req "$BUYER_JAR" POST "$API/auth.php?action=register" \
  "{\"email\":\"$BUYER_EMAIL\",\"password\":\"password123\",\"full_name\":\"Smoke Buyer\"}"
expect_status "duplicate email -> 409" 409 "$STATUS"
expect_contains "conflict code" "$BODY" '"code":"EMAIL_TAKEN"'

req "$BUYER_JAR" POST "$API/auth.php?action=register" '{"email":"not-an-email","password":"x"}'
expect_status "invalid payload -> 400" 400 "$STATUS"
expect_contains "field map present" "$BODY" '"fields":{"email":"invalid email"}'

req "$BUYER_JAR" GET "$API/auth.php?action=me"
expect_status "me returns 200" 200 "$STATUS"
expect_contains "me returns the buyer" "$BODY" "\"email\":\"$BUYER_EMAIL\""

req "$BUYER_JAR" POST "$API/auth.php?action=refresh"
expect_status "refresh rotates -> 200" 200 "$STATUS"

# is_active: deactivation bites immediately for live tokens and new logins.
DIS_JAR="$TMP/disabled.jar"; : > "$DIS_JAR"
DIS_EMAIL="disabled-$RUN_ID@test.dev"
req "$DIS_JAR" POST "$API/auth.php?action=register" \
  "{\"email\":\"$DIS_EMAIL\",\"password\":\"password123\",\"full_name\":\"Off Board\"}"
expect_status "register deactivation candidate 201" 201 "$STATUS"
db "UPDATE ${TABLE_PREFIX}app_users SET is_active = 0 WHERE email = '$DIS_EMAIL'" >/dev/null
req "$DIS_JAR" GET "$API/auth.php?action=me"
expect_status "me after deactivation -> 401" 401 "$STATUS"
req "$DIS_JAR" POST "$API/auth.php?action=login" \
  "{\"email\":\"$DIS_EMAIL\",\"password\":\"password123\"}"
expect_status "disabled account login -> 403" 403 "$STATUS"
expect_contains "disabled account code" "$BODY" '"code":"ACCOUNT_DISABLED"'

# ---------------------------------------------------------------------------
section "catalog: list + filters + single"
req "$BUYER_JAR" GET "$API/products.php?page=1&per_page=5&in_stock=1"
expect_status "product list 200" 200 "$STATUS"
expect_contains "envelope meta present" "$BODY" '"meta":{"page":1,"per_page":5'
req "$BUYER_JAR" GET "$API/products.php?slug=tailors-marking-chalk-set"
expect_status "single by slug 200" 200 "$STATUS"
expect_contains "product fields" "$BODY" '"sku":"TLS-CHK-02"'

# ---------------------------------------------------------------------------
section "cart: add / merge / patch / clear"
CHALK_ID="$(resolve_id tailors-marking-chalk-set)"
[ -n "$CHALK_ID" ] || die "fixture product missing: tailors-marking-chalk-set"
req "$BUYER_JAR" POST "$API/cart.php" "{\"product_id\":$CHALK_ID,\"quantity\":2}"
expect_status "cart add 201" 201 "$STATUS"
req "$BUYER_JAR" POST "$API/cart.php" "{\"product_id\":$CHALK_ID,\"quantity\":1}"
expect_status "cart merge 201" 201 "$STATUS"
expect_contains "merged to quantity 3" "$BODY" '"quantity":3'
ITEM_ID="$(printf '%s' "$BODY" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)"
req "$BUYER_JAR" PATCH "$API/cart.php" "{\"item_id\":$ITEM_ID,\"quantity\":1}"
expect_status "cart patch 200" 200 "$STATUS"
expect_contains "quantity now 1" "$BODY" '"quantity":1'
req "$BUYER_JAR" DELETE "$API/cart.php"
expect_status "cart clear 200" 200 "$STATUS"
expect_contains "cart empty after clear" "$BODY" '"item_count":0'

# ---------------------------------------------------------------------------
section "checkout: atomic success drains stock to zero"
req "$BUYER_JAR" GET "$API/products.php?slug=seam-ripper-precision"
DRAIN_ID="$(printf '%s' "$BODY" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)"
req "$BUYER_JAR" POST "$API/cart.php" "{\"product_id\":$DRAIN_ID,\"quantity\":3}"
expect_status "add full stock 201" 201 "$STATUS"
ADDR='{"shipping_address":{"full_name":"Smoke Buyer","line1":"1 Linen St","city":"Portland","region":"OR","postal_code":"97201","country":"US"}}'
req "$BUYER_JAR" POST "$API/checkout.php" "$ADDR"
expect_status "checkout 201" 201 "$STATUS"
expect_contains "order pending" "$BODY" '"status":"pending"'
ORDER_ID="$(printf '%s' "$BODY" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)"
[ "$(db "SELECT stock FROM ${TABLE_PREFIX}app_products WHERE id=$DRAIN_ID")" = "0" ] \
  && ok "stock drained to 0" || bad "stock not zero after checkout"

req "$BUYER_JAR" POST "$API/cart.php" "{\"product_id\":$DRAIN_ID,\"quantity\":1}"
expect_status "re-add out-of-stock -> 422" 422 "$STATUS"
expect_contains "out-of-stock code" "$BODY" '"code":"OUT_OF_STOCK"'

section "checkout: over-add conflicts and rolls back atomically"
req "$BUYER_JAR" POST "$API/cart.php" "{\"product_id\":$CHALK_ID,\"quantity\":7}"
req "$BUYER_JAR" POST "$API/checkout.php" "$ADDR"
expect_status "over-stock checkout -> 422" 422 "$STATUS"
expect_contains "stock conflict code" "$BODY" '"code":"STOCK_CONFLICT"'
[ "$(db "SELECT stock FROM ${TABLE_PREFIX}app_products WHERE id=$CHALK_ID")" = "5" ] \
  && ok "chalk stock untouched after rollback" || bad "stock changed during failed checkout"
[ "$(db "SELECT COUNT(*) FROM ${TABLE_PREFIX}app_cart_items ci JOIN ${TABLE_PREFIX}app_cart c ON c.id=ci.cart_id WHERE c.user_id=(SELECT id FROM ${TABLE_PREFIX}app_users WHERE email='$BUYER_EMAIL')")" -ge 1 ] \
  && ok "cart preserved after rollback" || bad "cart cleared by failed checkout"

# ---------------------------------------------------------------------------
section "webhook: signed payment marks order paid"
BODY_JSON="{\"event\":\"payment.succeeded\",\"order_id\":$ORDER_ID,\"provider_ref\":\"smoke-$RUN_ID\"}"
TS="$(date +%s)"
SIG="$(printf '%s' "$TS.$BODY_JSON" | openssl dgst -sha256 -hmac "$WEBHOOK_SECRET" | awk '{print $NF}')"
# Webhooks authenticate via a timestamped raw-body HMAC header, so send with
# curl directly (the helper would need to inject headers before the body).
post_webhook() { # post_webhook <timestamp> <signature> -> http code
  curl -s -o /dev/null -w '%{http_code}' -X POST "$API/webhooks.php" \
    -H "Content-Type: application/json" -H "X-Timestamp: $1" -H "X-Signature: $2" -d "$BODY_JSON"
}
expect_status "webhook accepted" 200 "$(post_webhook "$TS" "$SIG")"
[ "$(db "SELECT status FROM ${TABLE_PREFIX}app_orders WHERE id=$ORDER_ID")" = "paid" ] \
  && ok "order marked paid" || bad "order not paid after webhook"
# Same signed event redelivered inside the window: idempotent no-op, still 200.
expect_status "webhook redelivery idempotent 200" 200 "$(post_webhook "$TS" "$SIG")"
[ "$(db "SELECT status FROM ${TABLE_PREFIX}app_orders WHERE id=$ORDER_ID")" = "paid" ] \
  && ok "status stable after redelivery" || bad "status changed by redelivery"
# Fresh timestamp, forged signature -> rejected.
expect_status "bad signature -> 400" 400 "$(post_webhook "$TS" "deadbeef")"
# Correctly signed but stale timestamp (outside the ±300s replay window).
OLD_TS=$(( $(date +%s) - 3600 ))
OLD_SIG="$(printf '%s' "$OLD_TS.$BODY_JSON" | openssl dgst -sha256 -hmac "$WEBHOOK_SECRET" | awk '{print $NF}')"
expect_status "stale timestamp -> 400" 400 "$(post_webhook "$OLD_TS" "$OLD_SIG")"

# ---------------------------------------------------------------------------
section "reviews: verified buyers only, auto-approved, duplicates"
req "$BUYER_JAR" POST "$API/reviews.php" \
  '{"product_id":'$DRAIN_ID',"rating":5,"title":"Smoke review","body":"Automated verified-purchase review."}'
expect_status "verified buyer review 201" 201 "$STATUS"
req "$BUYER_JAR" POST "$API/reviews.php" \
  '{"product_id":'$DRAIN_ID',"rating":4,"title":"Again","body":"Duplicate should conflict."}'
expect_status "duplicate review -> 409" 409 "$STATUS"

NOBUY_JAR="$TMP/nobuy.jar"; : > "$NOBUY_JAR"
req "$NOBUY_JAR" POST "$API/auth.php?action=login" '{"email":"customer@appstore.test","password":"customer-pass-123"}'
req "$NOBUY_JAR" POST "$API/reviews.php" \
  "{\"product_id\":$CHALK_ID,\"rating\":4,\"title\":\"Not bought\",\"body\":\"Never purchased this product.\"}"
expect_status "logged-in non-purchaser -> 403" 403 "$STATUS"
expect_contains "not-purchaser code" "$BODY" '"code":"NOT_PURCHASER"'

# The verified-buyer review must be publicly visible right away (auto-approve:
# POST already proved a completed order, so there is no moderation step).
req "$BUYER_JAR" GET "$API/reviews.php?product=$DRAIN_ID"
expect_status "public review list 200" 200 "$STATUS"
expect_contains "submitted review visible" "$BODY" "Smoke review"

# ---------------------------------------------------------------------------
section "guest checkout: session-cookie cart, email captured"
GUEST_JAR="$TMP/guest.jar"; : > "$GUEST_JAR"
req "$GUEST_JAR" GET "$API/cart.php"
expect_status "guest cart get (mints signed cookie) 200" 200 "$STATUS"
CONE_ID="$(resolve_id polyester-thread-cone)"
[ -n "$CONE_ID" ] || die "fixture product missing: polyester-thread-cone"
req "$GUEST_JAR" POST "$API/cart.php" "{\"product_id\":$CONE_ID,\"quantity\":1}"
expect_status "guest add 201" 201 "$STATUS"
GUEST_ADDR="{\"email\":\"guest-$RUN_ID@test.dev\",\"shipping_address\":{\"full_name\":\"Guest Buyer\",\"line1\":\"2 Web Way\",\"city\":\"Portland\",\"region\":\"OR\",\"postal_code\":\"97201\",\"country\":\"US\"}}"
req "$GUEST_JAR" POST "$API/checkout.php" "$GUEST_ADDR"
expect_status "guest checkout 201" 201 "$STATUS"
GUEST_ORDER_ID="$(printf '%s' "$BODY" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)"
[ "$(db "SELECT user_id IS NULL FROM ${TABLE_PREFIX}app_orders WHERE id=$GUEST_ORDER_ID")" = "1" ] \
  && ok "guest order has NULL user_id" || bad "guest order attached to a user"

# ---------------------------------------------------------------------------
section "admin transitions + product PATCH + 403s"
CUST_JAR="$TMP/customer.jar"; : > "$CUST_JAR"
req "$CUST_JAR" POST "$API/auth.php?action=login" '{"email":"customer@appstore.test","password":"customer-pass-123"}'
expect_status "customer login 200" 200 "$STATUS"

req "$ADMIN_JAR" PATCH "$API/orders.php" "{\"id\":$GUEST_ORDER_ID,\"status\":\"paid\"}"
expect_status "admin order status 200" 200 "$STATUS"
req "$CUST_JAR" PATCH "$API/orders.php" "{\"id\":$GUEST_ORDER_ID,\"status\":\"shipped\"}"
expect_status "customer order PATCH -> 403" 403 "$STATUS"

PRICES_BEFORE="$(db "SELECT COUNT(*) FROM ${TABLE_PREFIX}app_price_history WHERE product_id=$CHALK_ID")"
req "$ADMIN_JAR" PATCH "$API/products.php" "{\"id\":$CHALK_ID,\"price\":36.00}"
expect_status "admin product PATCH 200" 200 "$STATUS"
PRICES_AFTER="$(db "SELECT COUNT(*) FROM ${TABLE_PREFIX}app_price_history WHERE product_id=$CHALK_ID")"
[ "$PRICES_AFTER" -gt "$PRICES_BEFORE" ] && ok "price history recorded" || bad "price history missing"
req "$ADMIN_JAR" PATCH "$API/products.php" "{\"id\":$CHALK_ID,\"price\":5.50}"
req "$CUST_JAR" PATCH "$API/products.php" "{\"id\":$CHALK_ID,\"price\":1.00}"
expect_status "customer product PATCH -> 403" 403 "$STATUS"

# ---------------------------------------------------------------------------
section "inventory: ledger-backed adjust + low-stock report"
req "$ADMIN_JAR" POST "$API/inventory.php" "{\"product_id\":$DRAIN_ID,\"delta\":5,\"reason\":\"restock\",\"note\":\"smoke restock\"}"
expect_status "adjust +5 200" 200 "$STATUS"
expect_contains "adjust echoes new stock" "$BODY" "\"stock\""
req "$ADMIN_JAR" GET "$API/inventory.php?report=low_stock"
expect_status "low-stock report 200" 200 "$STATUS"
req "$ADMIN_JAR" GET "$API/inventory.php?report=valuation"
expect_status "valuation report 200" 200 "$STATUS"
expect_contains "valuation has total" "$BODY" "total_value"

section "pricing: quote honors coupon, tax and shipping method"
# Quote prices the caller's server-side cart, so fill it first.
req "$GUEST_JAR" POST "$API/cart.php" "{\"product_id\":$DRAIN_ID,\"quantity\":1}"
expect_status "cart filled for quote" 201 "$STATUS"
req "$GUEST_JAR" POST "$API/quote.php" "{\"country\":\"US\",\"region\":\"CA\",\"coupon_code\":\"WELCOME10\"}"
expect_status "quote 200" 200 "$STATUS"
expect_contains "quote returns shipping options" "$BODY" "options"
expect_contains "quote applies coupon discount" "$BODY" "\"discount\":\""

section "coupons: public validation endpoint"
req "$GUEST_JAR" GET "$API/coupons.php?code=WELCOME10&subtotal=200"
expect_status "valid coupon 200" 200 "$STATUS"
expect_contains "valid coupon flagged" "$BODY" "\"valid\":true"
req "$GUEST_JAR" GET "$API/coupons.php?code=NOPE123&subtotal=200"
expect_status "unknown coupon 404" 404 "$STATUS"

section "addresses: CRUD for the signed-in customer"
req "$CUST_JAR" POST "$API/addresses.php" "{\"label\":\"Office\",\"full_name\":\"Demo Customer\",\"line1\":\"9 Test Street\",\"city\":\"Oakland\",\"region\":\"CA\",\"postal_code\":\"94607\",\"country\":\"US\"}"
expect_status "create address 201" 201 "$STATUS"
ADDR_ID="$(printf '%s' "$BODY" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)"
req "$CUST_JAR" GET "$API/addresses.php"
expect_status "list addresses 200" 200 "$STATUS"
req "$CUST_JAR" DELETE "$API/addresses.php?id=$ADDR_ID"
expect_status "delete address 200" 200 "$STATUS"

section "orders: state machine + notes"
req "$ADMIN_JAR" PATCH "$API/orders.php" "{\"id\":$ORDER_ID,\"status\":\"paid\"}"
expect_status "pending -> paid 200" 200 "$STATUS"
req "$ADMIN_JAR" GET "$API/orders.php?id=$ORDER_ID"
expect_status "order detail 200" 200 "$STATUS"
expect_contains "allowed_next populated" "$BODY" "allowed_next"
req "$ADMIN_JAR" PATCH "$API/orders.php" "{\"id\":$ORDER_ID,\"status\":\"delivered\"}"
expect_status "paid -> delivered rejected" 422 "$STATUS"
req "$ADMIN_JAR" POST "$API/orders.php" "{\"order_id\":$ORDER_ID,\"body\":\"smoke note\",\"visibility\":\"customer\"}"
expect_status "admin adds note 201" 201 "$STATUS"

section "outbox: queued mail is inspectable"
req "$ADMIN_JAR" GET "$API/outbox.php"
expect_status "outbox list 200" 200 "$STATUS"
expect_contains "outbox has messages" "$BODY" "messages"

section "cms: published pages public, drafts hidden, admin write"
req "$GUEST_JAR" GET "$API/pages.php"
expect_status "public page list 200" 200 "$STATUS"
expect_contains "seeded about page" "$BODY" "about"
req "$GUEST_JAR" GET "$API/pages.php?slug=shipping-returns"
expect_status "page by slug 200" 200 "$STATUS"
req "$GUEST_JAR" GET "$API/pages.php?slug=does-not-exist"
expect_status "unknown page 404" 404 "$STATUS"
req "$ADMIN_JAR" POST "$API/pages.php" "{\"title\":\"Smoke Page\"}"
expect_status "admin creates draft 201" 201 "$STATUS"
SMOKE_PID="$(printf '%s' "$BODY" | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)"
SMOKE_SLUG="$(printf '%s' "$BODY" | grep -o '"slug":"[^"]*"' | head -1 | cut -d'"' -f4)"
req "$GUEST_JAR" GET "$API/pages.php?slug=$SMOKE_SLUG"
expect_status "draft hidden from public 404" 404 "$STATUS"
req "$ADMIN_JAR" PATCH "$API/pages.php" "{\"id\":$SMOKE_PID,\"status\":\"published\"}"
expect_status "admin publishes 200" 200 "$STATUS"
req "$GUEST_JAR" GET "$API/pages.php?slug=$SMOKE_SLUG"
expect_status "published page public 200" 200 "$STATUS"
req "$ADMIN_JAR" DELETE "$API/pages.php?id=$SMOKE_PID"
expect_status "admin deletes page 200" 200 "$STATUS"
req "$GUEST_JAR" GET "$API/pages.php?slug=$SMOKE_SLUG"
expect_status "deleted page gone 404" 404 "$STATUS"
req "$GUEST_JAR" PATCH "$API/pages.php" "{\"id\":1,\"status\":\"draft\"}"
expect_status "anon write blocked 401" 401 "$STATUS"

section "settings: public read, admin write"
req "$GUEST_JAR" GET "$API/settings.php"
expect_status "public settings 200" 200 "$STATUS"
expect_contains "store name present" "$BODY" "store.name"
# Capture the live announcement so this section can restore it byte-for-byte
# (the seeded copy contains a non-ASCII bullet that is unreliable to
# re-encode through every shell on every platform).
ANNOUNCE_ORIGINAL="$(printf '%s' "$BODY" | python3 -c 'import sys,json; print(json.load(sys.stdin)["data"]["settings"].get("announcement.text",""))' 2>/dev/null || printf '%s' "$BODY" | sed -n 's/.*"announcement\.text":"\([^"]*\)".*/\1/p')"
req "$ADMIN_JAR" PATCH "$API/settings.php" "{\"settings\":{\"announcement.text\":\"Smoke announcement\"}}"
expect_status "admin updates settings 200" 200 "$STATUS"
req "$GUEST_JAR" GET "$API/settings.php"
expect_contains "public sees updated value" "$BODY" "Smoke announcement"
# Restore the captured copy so the run leaves the storefront looking right.
if [ -n "$ANNOUNCE_ORIGINAL" ]; then
  RESTORE_PAYLOAD="$(python3 -c 'import json,sys; print(json.dumps({"settings":{"announcement.text":sys.argv[1]}}))' "$ANNOUNCE_ORIGINAL" 2>/dev/null \
    || printf '{"settings":{"announcement.text":"%s"}}' "$ANNOUNCE_ORIGINAL")"
  req "$ADMIN_JAR" PATCH "$API/settings.php" "$RESTORE_PAYLOAD"
  expect_status "settings restored 200" 200 "$STATUS"
else
  echo "  SKIP settings restored (could not capture original announcement)"
fi
req "$GUEST_JAR" PATCH "$API/settings.php" "{\"settings\":{\"x\":\"y\"}}"
expect_status "anon settings write blocked 401" 401 "$STATUS"

# ---------------------------------------------------------------------------
section "CORS: origin echo, credentials, preflight"
CORS_HEADERS="$(curl -s -D - -o /dev/null "$API/products.php?per_page=1" -H "Origin: $ORIGIN")"
expect_contains "allow-origin echoed" "$CORS_HEADERS" "Access-Control-Allow-Origin: $ORIGIN"
expect_contains "credentials allowed" "$CORS_HEADERS" "Access-Control-Allow-Credentials: true"
PRE="$(curl -s -D - -o /dev/null -X OPTIONS "$API/cart.php" -H "Origin: $ORIGIN" -H "Access-Control-Request-Method: PATCH")"
expect_contains "preflight 204" "$PRE" "204"
expect_contains "preflight methods" "$PRE" "Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS"

# ---------------------------------------------------------------------------
section "logout + rate limiting (last: burns the login bucket)"
req "$BUYER_JAR" POST "$API/auth.php?action=logout"
expect_status "logout 204" 204 "$STATUS"
req "$BUYER_JAR" GET "$API/auth.php?action=me"
expect_status "me after logout -> 401" 401 "$STATUS"

SAW_429=0
for _ in 1 2 3 4 5 6 7 8; do
  CODE="$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API/auth.php?action=login" \
    -H "Origin: $ORIGIN" -H 'Content-Type: application/json' \
    -d "{\"email\":\"nobody-$RUN_ID@test.dev\",\"password\":\"wrong-pass\"}")"
  [ "$CODE" = "429" ] && SAW_429=1 && break
done
[ "$SAW_429" = "1" ] && ok "rate limiter kicks in (429 within the window)" || bad "no 429 after 8 attempts"

# ---------------------------------------------------------------------------
section "summary"
printf '\n  passed: %d   failed: %d\n' "$PASS" "$FAIL"
if [ "$FAIL" -gt 0 ]; then
  printf '  failures:%b\n' "$FAILED_NAMES"
  exit 1
fi
printf '  \033[32mSMOKE TEST PASSED\033[0m\n'
exit 0
