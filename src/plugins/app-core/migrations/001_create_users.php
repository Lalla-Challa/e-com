<?php

declare(strict_types=1);

use AppCore\Db;

/**
 * 001_create_users — customer/admin accounts + rotating refresh tokens.
 * Passwords are Argon2id hashes (bcrypt fallback), never wp_users.
 */
return static function (PDO $pdo): void {
    $users = Db::table('users');
    $tokens = Db::table('refresh_tokens');

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$users} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        email VARCHAR(190) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        full_name VARCHAR(120) NOT NULL,
        role ENUM('customer','admin') NOT NULL DEFAULT 'customer',
        created_at DATETIME(3) NOT NULL,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_users_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$tokens} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        selector CHAR(32) NOT NULL,
        validator_hash CHAR(64) NOT NULL,
        expires_at DATETIME(3) NOT NULL,
        created_at DATETIME(3) NOT NULL,
        revoked_at DATETIME(3) NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_refresh_selector (selector),
        KEY idx_refresh_user (user_id),
        CONSTRAINT fk_refresh_user FOREIGN KEY (user_id) REFERENCES {$users} (id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
