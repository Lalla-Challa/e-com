<?php

declare(strict_types=1);

use AppCore\Db;

/**
 * 024_customer_experience — customer groups, loyalty points, and wishlists.
 * Single-store entity: every customer belongs to the store; groups provide
 * tiered pricing/wholesale tiers, loyalty rewards repeat purchases, and
 * wishlists let customers save products for later.
 */
return static function (PDO $pdo): void {
    $users = Db::table('users');
    $products = Db::table('products');
    $orders = Db::table('orders');
    $now = 'UTC_TIMESTAMP(3)';

    $customerGroups = Db::table('customer_groups');
    $customerGroupMembers = Db::table('customer_group_members');
    $loyaltyPoints = Db::table('loyalty_points');
    $wishlists = Db::table('wishlists');

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$customerGroups} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        code VARCHAR(60) NOT NULL,
        name VARCHAR(120) NOT NULL,
        price_markup_pct DECIMAL(6,4) NOT NULL DEFAULT 0.0000 COMMENT 'Markup applied to base price for this group (e.g. wholesale = negative)',
        created_at DATETIME(3) NOT NULL,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_customer_groups_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$customerGroupMembers} (
        user_id BIGINT UNSIGNED NOT NULL,
        group_id BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (user_id, group_id),
        KEY idx_customer_group_members_group (group_id),
        CONSTRAINT fk_customer_group_members_user FOREIGN KEY (user_id) REFERENCES {$users} (id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_customer_group_members_group FOREIGN KEY (group_id) REFERENCES {$customerGroups} (id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$loyaltyPoints} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        order_id BIGINT UNSIGNED NULL,
        points INT NOT NULL,
        type ENUM('earned','redeemed','expired') NOT NULL DEFAULT 'earned',
        expires_at DATETIME(3) NULL,
        created_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        KEY idx_loyalty_points_user (user_id, type),
        KEY idx_loyalty_points_expires (expires_at),
        CONSTRAINT fk_loyalty_points_user FOREIGN KEY (user_id) REFERENCES {$users} (id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_loyalty_points_order FOREIGN KEY (order_id) REFERENCES {$orders} (id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$wishlists} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_wishlists_user_product (user_id, product_id),
        KEY idx_wishlists_product (product_id),
        CONSTRAINT fk_wishlists_user FOREIGN KEY (user_id) REFERENCES {$users} (id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_wishlists_product FOREIGN KEY (product_id) REFERENCES {$products} (id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Seed default customer group.
    $pdo->prepare(
        'INSERT IGNORE INTO ' . $customerGroups . ' (code, name, price_markup_pct, created_at, updated_at)
         VALUES (?, ?, 0.0000, ' . $now . ', ' . $now . ')'
    )->execute(['retail', 'Retail']);
};
