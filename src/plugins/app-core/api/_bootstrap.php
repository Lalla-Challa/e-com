<?php

declare(strict_types=1);

use AppCore\Db;
use AppCore\Http;

require_once dirname(__DIR__) . '/includes/env.php';
require_once dirname(__DIR__) . '/includes/http.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/validate.php';
require_once dirname(__DIR__) . '/includes/money.php';
require_once dirname(__DIR__) . '/includes/slug.php';
require_once dirname(__DIR__) . '/includes/auth-utils.php';
require_once dirname(__DIR__) . '/includes/migrations.php';
require_once dirname(__DIR__) . '/includes/pricing.php';
require_once dirname(__DIR__) . '/includes/mailer.php';

// Fail fast on a missing secret — never boot a token-signing endpoint without it.
try {
    AppCore\Env::jwtSecret();
} catch (Throwable $e) {
    Http::logError('bootstrap: ' . $e->getMessage());
    Http::error(500, 'CONFIG_ERROR', 'Server configuration error.');
}

Http::cors();

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (($severity & (E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE)) !== 0) {
        Http::logError("php [{$severity}] {$message} in {$file}:{$line}");

        return true;
    }

    return false;
});

try {
    Db::pdo();
} catch (Throwable $e) {
    Http::fail($e);
}
