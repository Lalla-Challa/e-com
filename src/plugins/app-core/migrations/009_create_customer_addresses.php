<?php

declare(strict_types=1);

use AppCore\Db;

/**
 * 009_create_customer_addresses — reusable address book.
 *
 * Previously addresses only existed as a JSON blob frozen onto each order;
 * this makes them first-class rows a customer can manage and reuse. MySQL has
 * no partial unique indexes, so the "only one default per user" rule is
 * enforced by the API inside a transaction rather than by a constraint.
 */
return static function (PDO $pdo): void {
    $addresses = Db::table('customer_addresses');
    $users = Db::table('users');

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$addresses} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        label VARCHAR(60) NULL,
        full_name VARCHAR(120) NOT NULL,
        line1 VARCHAR(200) NOT NULL,
        line2 VARCHAR(200) NULL,
        city VARCHAR(100) NOT NULL,
        region VARCHAR(100) NULL,
        postal_code VARCHAR(20) NOT NULL,
        country CHAR(2) NOT NULL,
        phone VARCHAR(30) NULL,
        is_default_shipping BOOLEAN NOT NULL DEFAULT FALSE,
        is_default_billing BOOLEAN NOT NULL DEFAULT FALSE,
        created_at DATETIME(3) NOT NULL,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        KEY idx_customer_addresses_user (user_id),
        CONSTRAINT fk_customer_addresses_user FOREIGN KEY (user_id) REFERENCES {$users} (id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
