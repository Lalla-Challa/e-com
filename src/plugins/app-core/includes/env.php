<?php

declare(strict_types=1);

namespace AppCore;

/**
 * Environment bootstrapping shared by the API layer and the plugin shell.
 *
 * Values resolve in this order (first hit wins):
 *   1. Real environment variables (docker-compose, shell).
 *   2. A .env file walking upward from this file (repo root).
 * Values that must never be guessed (JWT_SECRET) fail closed when missing.
 */
final class Env
{
    /** @var array<string, string>|null */
    private static ?array $cache = null;

    private static string $projectRoot = '';

    public static function projectRoot(): string
    {
        if (self::$projectRoot === '') {
            self::$projectRoot = dirname(__DIR__, 4);
        }

        return self::$projectRoot;
    }

    public static function pluginDir(): string
    {
        return dirname(__DIR__);
    }

    public static function logsDir(): string
    {
        return self::pluginDir() . '/logs';
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $env = self::all();
        if (array_key_exists($key, $env) && $env[$key] !== '') {
            return $env[$key];
        }

        return $default;
    }

    public static function requireKey(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new \RuntimeException("Missing required environment variable: {$key}");
        }

        return $value;
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = self::load();
        }

        return self::$cache;
    }

    /** @return array<string, string> */
    private static function load(): array
    {
        $values = [];

        foreach ($_SERVER as $key => $value) {
            if (\is_string($value) && $key !== '' && !str_contains($key, ' ')) {
                $values[$key] = $value;
            }
        }
        foreach ($_ENV as $key => $value) {
            if (\is_string($value)) {
                $values[$key] = $value;
            }
        }
        foreach (getenv() as $key => $value) {
            if (\is_string($value)) {
                $values[$key] = $value;
            }
        }

        $dotenv = self::findDotEnv();
        if ($dotenv !== null) {
            foreach (self::parseDotEnv($dotenv) as $key => $value) {
                if (!isset($values[$key]) || $values[$key] === '') {
                    $values[$key] = $value;
                }
            }
        }

        return $values;
    }

    private static function findDotEnv(): ?string
    {
        $dir = self::pluginDir();
        for ($i = 0; $i < 6; $i++) {
            $candidate = $dir . '/.env';
            if (is_file($candidate)) {
                return $candidate;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }

    /** @return array<string, string> */
    private static function parseDotEnv(string $path): array
    {
        $values = [];
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return $values;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $line = ltrim($line, 'export ');
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1));
            $len = strlen($value);
            if ($len >= 2 && (($value[0] === '"' && $value[$len - 1] === '"') || ($value[0] === "'" && $value[$len - 1] === "'"))) {
                $value = substr($value, 1, -1);
            }
            if ($key !== '') {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /** @return list<string> */
    public static function corsOrigins(): array
    {
        $raw = self::get('CORS_ORIGIN', 'http://localhost:5173') ?? 'http://localhost:5173';
        $origins = [];
        foreach (explode(',', $raw) as $origin) {
            $origin = rtrim(trim($origin), '/');
            if ($origin !== '' && str_starts_with($origin, 'http')) {
                $origins[] = $origin;
            }
        }

        return $origins === [] ? ['http://localhost:5173'] : $origins;
    }

    public static function jwtSecret(): string
    {
        return self::requireKey('JWT_SECRET');
    }
}
