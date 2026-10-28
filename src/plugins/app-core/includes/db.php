<?php

declare(strict_types=1);

namespace AppCore;

/**
 * PDO singleton built from WordPress credentials.
 *
 * The architecture mandates a literal `require_once wp-config.php` in the API
 * layer. wp-config.php's final statement is `require_once ABSPATH
 * . 'wp-settings.php';` — we pre-define ABSPATH to a stub directory shipped
 * with this plugin, so that final require resolves to an empty file and ZERO
 * wp-includes files are ever loaded. If wp-config.php cannot be located
 * (unit tests, CLI tooling), we fall back to PDO_DSN_FALLBACK.
 */
final class Db
{
    private static ?\PDO $pdo = null;

    private static ?string $tablePrefix = null;

    /**
     * @return array{dsn: string, user: string, password: string, prefix: string}
     */
    public static function credentials(): array
    {
        $fallbackDsn = Env::get('PDO_DSN_FALLBACK');
        if ($fallbackDsn !== null && $fallbackDsn !== '') {
            return [
                'dsn' => $fallbackDsn,
                'user' => (string) Env::get('PDO_USER', ''),
                'password' => (string) Env::get('PDO_PASSWORD', ''),
                'prefix' => (string) Env::get('TABLE_PREFIX', 'wp_'),
            ];
        }

        $configPath = self::locateWpConfig();
        if ($configPath === null) {
            throw new \RuntimeException('wp-config.php not found and PDO_DSN_FALLBACK not set.');
        }

        return self::evaluateWpConfig($configPath);
    }

    public static function tablePrefix(): string
    {
        if (self::$tablePrefix === null) {
            self::$tablePrefix = self::credentials()['prefix'];
        }

        return self::$tablePrefix;
    }

    /**
     * Fully-qualified name for one of our app tables, e.g. table('users') → wp_app_users.
     */
    public static function table(string $name): string
    {
        return self::tablePrefix() . 'app_' . $name;
    }

    public static function pdo(): \PDO
    {
        if (self::$pdo instanceof \PDO) {
            return self::$pdo;
        }

        $creds = self::credentials();

        try {
            $pdo = new \PDO($creds['dsn'], $creds['user'], $creds['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        } catch (\PDOException $e) {
            Http::logError('PDO connection failed: ' . $e->getMessage());
            throw new \RuntimeException('Database connection failed.', 0, $e);
        }

        $pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");

        return self::$pdo = $pdo;
    }

    public static function disconnect(): void
    {
        self::$pdo = null;
    }

    private static function locateWpConfig(): ?string
    {
        $candidates = [
            Env::projectRoot() . '/wp/wp-config.php',
            Env::projectRoot() . '/wp-config.php',
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Requires the real wp-config.php with WordPress neutralised and returns
     * the credential tuple it defines.
     *
     * @return array{dsn: string, user: string, password: string, prefix: string}
     */
    private static function evaluateWpConfig(string $path): array
    {
        // wp-settings.php begins with `if ( ! defined( 'ABSPATH' ) ) exit;` —
        // pre-defining ABSPATH makes its own guard pass, and pointing it at our
        // stub directory makes wp-config.php's final require resolve to an
        // empty file. Nothing from wp-includes or wp-content can execute.
        $stubDir = Env::pluginDir() . '/wp-stub';
        if (!defined('SHORTINIT')) {
            define('SHORTINIT', true);
        }
        if (!defined('ABSPATH')) {
            define('ABSPATH', $stubDir . '/');
        }

        $host = '127.0.0.1';
        $port = '3306';
        $name = '';
        $user = '';
        $password = '';
        $prefix = 'wp_';
        $table_prefix = 'wp_';

        require_once $path;

        // $table_prefix is a plain variable in wp-config.php; requiring inside
        // this function scope captures it. When WP itself already booted (API
        // reached through rewrite routing), read the global instead.
        if (!(isset($table_prefix) && \is_string($table_prefix) && $table_prefix !== '')) {
            $global = $GLOBALS['table_prefix'] ?? null;
            if (\is_string($global) && $global !== '') {
                $table_prefix = $global;
            }
        }
        if (isset($table_prefix) && \is_string($table_prefix) && $table_prefix !== '') {
            $prefix = $table_prefix;
        }

        if (defined('DB_HOST')) {
            [$host, $maybePort] = array_pad(explode(':', (string) constant('DB_HOST'), 2), 2, '3306');
            $host = $host !== '' ? $host : '127.0.0.1';
            if ($maybePort !== null && $maybePort !== '') {
                $port = $maybePort;
            }
        }
        $name = defined('DB_NAME') ? (string) constant('DB_NAME') : '';
        $user = defined('DB_USER') ? (string) constant('DB_USER') : $user;
        $password = defined('DB_PASSWORD') ? (string) constant('DB_PASSWORD') : $password;

        if ($name === '') {
            throw new \RuntimeException('wp-config.php does not declare DB_NAME.');
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

        return [
            'dsn' => $dsn,
            'user' => $user,
            'password' => $password,
            'prefix' => $prefix,
        ];
    }
}
