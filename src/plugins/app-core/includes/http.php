<?php

declare(strict_types=1);

namespace AppCore;

/**
 * The ONLY place allowed to emit an HTTP response body.
 * Every endpoint returns the envelope defined in the API contract.
 */
final class Http
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function send(int $status, array $payload): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($status >= 500) {
            self::logError('500 response: ' . ($body === false ? 'json_encode failed' : $body));
        }
        echo $body === false ? '{"ok":false,"error":{"code":"INTERNAL","message":"Encoding failure."}}' : $body;
        exit;
    }

    public static function ok(mixed $data, ?array $meta = null): never
    {
        self::send(200, ['ok' => true, 'data' => $data, 'meta' => $meta]);
    }

    public static function created(mixed $data, ?array $meta = null): never
    {
        self::send(201, ['ok' => true, 'data' => $data, 'meta' => $meta]);
    }

    public static function noContent(): never
    {
        http_response_code(204);
        exit;
    }

    public static function error(int $status, string $code, string $message, array $fields = []): never
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== []) {
            $error['fields'] = $fields;
        }
        self::send($status, ['ok' => false, 'error' => $error]);
    }

    /**
     * CORS for the configured origins, with credentials. Also terminates
     * preflight requests.
     */
    public static function cors(): void
    {
        $allowed = Env::corsOrigins();
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? rtrim((string) $_SERVER['HTTP_ORIGIN'], '/') : '';

        if ($origin !== '' && in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization');
            header('Access-Control-Max-Age: 600');
            http_response_code(204);
            exit;
        }
    }

    /**
     * HttpOnly + Secure + SameSite cookie helper.
     */
    public static function setCookie(string $name, string $value, int $maxAge, string $sameSite = 'Strict'): void
    {
        setcookie($name, $value, [
            'expires' => time() + $maxAge,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
    }

    public static function clearCookie(string $name, string $sameSite = 'Strict'): void
    {
        setcookie($name, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
    }

    public static function logError(string $message): void
    {
        $dir = Env::logsDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/error.log';
        if (is_file($file) && (int) @filesize($file) > 5 * 1024 * 1024) {
            @rename($file, $file . '.1');
        }
        @file_put_contents(
            $file,
            sprintf("[%s] %s\n", gmdate('c'), $message),
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Top-level failure handler: full detail to the log, generic 500 to the client.
     *
     * A ValidationException that escapes an endpoint is a bad *request*, not a
     * broken server: answer 400 with the offending fields so a GET with a
     * missing/invalid query parameter (e.g. /api/reviews without ?product)
     * reports itself honestly instead of masquerading as an internal error.
     */
    public static function fail(\Throwable $e): never
    {
        if ($e instanceof ValidationException) {
            self::logError('400 response: ' . $e->getMessage() . ' (' . json_encode($e->fields) . ')');
            self::error(400, 'VALIDATION_FAILED', 'Please check the highlighted fields.', $e->fields);
        }

        self::logError(sprintf(
            '%s: %s in %s:%d%s',
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getPrevious() !== null ? ' | previous: ' . $e->getPrevious()->getMessage() : ''
        ));
        self::error(500, 'INTERNAL', 'Something went wrong. Please try again.');
    }
}
