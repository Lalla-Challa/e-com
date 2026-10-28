<?php

declare(strict_types=1);

namespace AppCore;

/**
 * Maps /api/<module>[/<action>] onto the standalone endpoint files in ../api/.
 * Re-enters the API layer without booting WordPress again.
 */
final class ApiRouter
{
    /**
     * Matches both the pretty form (/api/products, /api/auth/login) and the
     * file form (/api/auth.php?action=…). Kept byte-compatible with Vite's
     * dev proxy rewrite so dev and production route identically.
     */
    private const REQUEST_PATTERN = '#^/api/([a-z][a-z0-9_-]*)(?:\.php)?(?:/([a-z-]+))?/?$#';

    /** Modules discovered from disk so a new endpoint can never be forgotten. */
    private static ?array $modules = null;

    /**
     * Entry point for the init-time dispatch: match the request path and hand
     * off. Anything that is not an /api/ path returns and lets WordPress
     * continue booting normally.
     */
    public static function dispatchFromRequest(): void
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if (!\is_string($path) || preg_match(self::REQUEST_PATTERN, $path, $matches) !== 1) {
            return;
        }

        self::dispatch($matches[1], $matches[2] ?? '');
    }

    public static function dispatch(string $module, string $action): never
    {
        if (!\in_array($module, self::modules(), true)) {
            Http::error(404, 'NOT_FOUND', 'Unknown API module.');
        }

        // The endpoint scripts read $_GET themselves (auth.php switches on
        // ?action=; products.php on ?id/?slug). Only overwrite a path-supplied
        // action; a query-string action (…/auth.php?action=login) must survive.
        if ($action !== '') {
            $_GET['action'] = $action;
        }

        require dirname(__DIR__) . '/api/' . $module . '.php';
        exit;
    }

    /** @return list<string> */
    private static function modules(): array
    {
        if (self::$modules === null) {
            self::$modules = [];
            foreach (glob(dirname(__DIR__) . '/api/*.php') ?: [] as $file) {
                $name = basename($file, '.php');
                if (!str_starts_with($name, '_')) {
                    self::$modules[] = $name;
                }
            }
        }

        return self::$modules;
    }
}
