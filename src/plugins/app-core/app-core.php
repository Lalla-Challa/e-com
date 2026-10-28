<?php

declare(strict_types=1);

/**
 * Plugin Name:       app-core
 * Description:       Passive WordPress wrapper: custom normalized store tables, standalone PDO API endpoints, and headless SPA hosting. Store logic never touches wp_posts, wp_users, or WooCommerce.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            app-core contributors
 * Text Domain:       app-core
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/env.php';
require_once __DIR__ . '/includes/http.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/validate.php';
require_once __DIR__ . '/includes/auth-utils.php';
require_once __DIR__ . '/includes/migrations.php';
require_once __DIR__ . '/includes/activator.php';
require_once __DIR__ . '/includes/frontend.php';
require_once __DIR__ . '/includes/api-router.php';

register_activation_hook(__FILE__, ['AppCore\Activator', 'activate']);
register_deactivation_hook(__FILE__, ['AppCore\Deactivator', 'deactivate']);

// wp-admin mount point for the built React dashboard (admin/dashboard.php).
add_action('admin_menu', static function (): void {
    add_menu_page(
        'Store Dashboard',
        'Store Dashboard',
        'manage_options',
        'app-core-dashboard',
        static function (): void {
            require __DIR__ . '/admin/dashboard.php';
        },
        'dashicons-store',
        3
    );
});

// Flush rewrite rules on (de)activation so /app/** and /assets/** resolve.
// (No /api/ rule: API dispatch matches REQUEST_URI at init, before WP's
// parse_request ever runs — rewrite query vars do not exist at that point.)
add_action('init', static function (): void {
    add_rewrite_rule('^app/?$', 'index.php?app_spa=1', 'top');
    add_rewrite_rule('^app/(.*)$', 'index.php?app_spa=1&app_spa_path=$matches[1]', 'top');
    add_rewrite_rule('^assets/([^.]+\.(?:js|css|woff2?|png|jpe?g|svg|ico|webp|json|map))$', 'index.php?app_asset=$matches[1]', 'top');
    add_filter('query_vars', static function (array $vars): array {
        $vars[] = 'app_spa';
        $vars[] = 'app_spa_path';
        $vars[] = 'app_asset';

        return $vars;
    }, 5);
}, 5);

/**
 * /api/<module>[/<action>] dispatch. WordPress fires `init` BEFORE wp() runs
 * parse_request, so the rewrite-based query vars are never populated here —
 * the path is matched against REQUEST_URI directly instead. The router
 * re-enters the api/ directory WITHOUT booting WP again.
 */
add_action('init', static function (): void {
    AppCore\ApiRouter::dispatchFromRequest();
}, 1);

/**
 * The template_redirect trap. WordPress boots for the site root only:
 *   "/"           → JSON status payload (never theme HTML)
 *   /app/**       → streams the built SPA's index.html (production hosting)
 *   /assets/**    → streams built SPA assets with correct MIME types
 *   anything else → JSON 404 in the standard envelope
 *
 * Paths are derived from REQUEST_URI (with the rewrite query vars as a
 * secondary source), so the trap works regardless of permalink structure.
 * Registered at priority 1 to run before redirect_canonical (10).
 *
 * /wp-admin, /wp-login.php and /wp-json never reach template_redirect, so
 * they remain untouched WP surface.
 */
add_action('template_redirect', static function (): void {
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $request = rtrim(\is_string($path) ? $path : '/', '/');

    // /assets/<file> → the built asset (rewrite var, else the path itself).
    $asset = (string) get_query_var('app_asset');
    if ($asset === '' && preg_match('#^/assets/(.+)$#', $request, $matches) === 1) {
        $asset = $matches[1];
    }
    if ($asset !== '') {
        AppCore\Frontend::serveAsset($asset);
    }

    // "/" | "/app" | "/app/**" → SPA (rewrite vars, else the path itself).
    $spaPath = (string) get_query_var('app_spa_path');
    $isApp = $request === '/app' || str_starts_with($request, '/app/') || (bool) get_query_var('app_spa');
    if ($spaPath === '' && str_starts_with($request, '/app/')) {
        $spaPath = substr($request, \strlen('/app/'));
    }

    if ($request === '' || $isApp) {
        AppCore\Frontend::serveRootOrSpa($request, $spaPath);
    }

    AppCore\Http::error(404, 'NOT_FOUND', 'Headless: no frontend at this path.');
}, 1);
