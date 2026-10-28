<?php

declare(strict_types=1);

use AppCore\Db;

/**
 * 006_create_rate_limits — fixed-window counters for auth/review throttling
 * (5 auth attempts per IP per 60s), as allowed by the security spec.
 */
return static function (PDO $pdo): void {
    $table = Db::table('rate_limits');

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        bucket VARCHAR(64) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        attempts_count INT NOT NULL DEFAULT 1,
        window_start DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        KEY idx_rate_limits_lookup (bucket, ip_address, window_start)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
