<?php

declare(strict_types=1);

/**
 * Headless wp-config.php template.
 *
 * Copied to ./wp/wp-config.php by docker-compose. Values come from the
 * container environment provided by docker-compose.yml — no secrets in code.
 * The final wp-settings.php require is what the API layer neutralises with
 * its ABSPATH stub; WordPress itself boots normally for /wp-admin only.
 */

if (!defined('DB_HOST')) {
    if (getenv('WORDPRESS_DB_HOST') !== false) {
        define('DB_HOST', getenv('WORDPRESS_DB_HOST') ?: 'db:3306');
    } elseif (getenv('WP_DB_HOST') !== false) {
        define('DB_HOST', getenv('WP_DB_HOST') ?: 'db');
    } else {
        define('DB_HOST', 'db:3306');
    }
}
$dbName = getenv('WORDPRESS_DB_NAME') ?: (getenv('MYSQL_DATABASE') ?: 'wordpress_db');
$dbUser = getenv('WORDPRESS_DB_USER') ?: (getenv('MYSQL_USER') ?: 'wp_user');
$dbPass = getenv('WORDPRESS_DB_PASSWORD') ?: (getenv('MYSQL_PASSWORD') ?: 'wp_password');
if (!defined('DB_NAME')) define('DB_NAME', $dbName);
if (!defined('DB_USER')) define('DB_USER', $dbUser);
if (!defined('DB_PASSWORD')) define('DB_PASSWORD', $dbPass);
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');
if (!defined('DB_COLLATE')) define('DB_COLLATE', '');

// Salt-free local setup: WP auto-generates keys when none are defined, but be
// explicit so nothing ever phones home for values.
if (!defined('AUTH_KEY')) define('AUTH_KEY', getenv('WP_AUTH_KEY') ?: 'app-core-local-auth-key-not-for-production');
if (!defined('SECURE_AUTH_KEY')) define('SECURE_AUTH_KEY', getenv('WP_SECURE_AUTH_KEY') ?: 'app-core-local-secure-auth-key');
if (!defined('LOGGED_IN_KEY')) define('LOGGED_IN_KEY', getenv('WP_LOGGED_IN_KEY') ?: 'app-core-local-logged-in-key');
if (!defined('NONCE_KEY')) define('NONCE_KEY', getenv('WP_NONCE_KEY') ?: 'app-core-local-nonce-key');
if (!defined('AUTH_SALT')) define('AUTH_SALT', getenv('WP_AUTH_SALT') ?: 'app-core-local-auth-salt');
if (!defined('SECURE_AUTH_SALT')) define('SECURE_AUTH_SALT', getenv('WP_SECURE_AUTH_SALT') ?: 'app-core-local-secure-auth-salt');
if (!defined('LOGGED_IN_SALT')) define('LOGGED_IN_SALT', getenv('WP_LOGGED_IN_SALT') ?: 'app-core-local-logged-in-salt');
if (!defined('NONCE_SALT')) define('NONCE_SALT', getenv('WP_NONCE_SALT') ?: 'app-core-local-nonce-salt');

$table_prefix = getenv('WORDPRESS_TABLE_PREFIX') ?: (getenv('TABLE_PREFIX') ?: 'wp_');

define('WP_DEBUG', (getenv('WP_DEBUG') ?: '0') === '1');
define('WP_DEBUG_LOG', false);
define('WP_DEBUG_DISPLAY', false);
define('DISALLOW_FILE_EDIT', true);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once ABSPATH . 'wp-settings.php';
