#!/bin/sh
# First-boot setup for a fresh clone. Safe to run again: each step skips work
# that is already done. WordPress files are copied by the wordpress container
# into the shared volume, so this waits until that copy finishes.
set -eu

echo "Waiting for WordPress files..."
i=0
while [ ! -f /var/www/html/wp-load.php ]; do
  i=$((i + 1))
  if [ "$i" -gt 90 ]; then
    echo "WordPress files were not ready in time." >&2
    exit 1
  fi
  sleep 2
done

echo "Waiting for the database..."
i=0
until wp db query "SELECT 1" --skip-column-names >/dev/null 2>&1; do
  i=$((i + 1))
  if [ "$i" -gt 90 ]; then
    echo "Database was not ready in time." >&2
    exit 1
  fi
  sleep 2
done

if ! wp core is-installed >/dev/null 2>&1; then
  echo "Installing WordPress..."
  wp core install \
    --url="http://localhost:8081" \
    --title="Buttonwalay" \
    --admin_user=wpadmin \
    --admin_password=wpadmin-pass-123 \
    --admin_email=wpadmin@appstore.test \
    --skip-email
fi

echo "Activating the store plugin..."
wp plugin activate app-core
wp rewrite structure '/%postname%/' --hard
wp rewrite flush --hard

count=$(wp db query "SELECT COUNT(*) FROM wp_app_users" --skip-column-names --silent 2>/dev/null | tr -d '[:space:]' || true)
if [ "$count" = "0" ] || [ -z "$count" ]; then
  echo "Loading the demo shop..."
  php /var/www/html/wp-content/plugins/app-core/seed.php
else
  echo "Demo shop already loaded."
fi

echo "Store is ready."
