<?php

declare(strict_types=1);

use AppCore\Db;

/**
 * 025_promotions — promotion rules (cart discounts, BOGO, tiered, free
 * shipping) and affiliate referrals. Single-store only; affiliates earn
 * commissions on referred orders.
 */
return static function (PDO $pdo): void {
    $orders = Db::table('orders');
    $users = Db::table('users');
    $now = 'UTC_TIMESTAMP(3)';

    $promotionRules = Db::table('promotion_rules');
    $affiliates = Db::table('affiliates');
    $affiliateConversions = Db::table('affiliate_conversions');

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$promotionRules} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        code VARCHAR(64) NOT NULL,
        type ENUM('cart_discount','bogo','tiered','free_shipping') NOT NULL DEFAULT 'cart_discount',
        conditions JSON NOT NULL COMMENT 'e.g. {min_subtotal: 50, categories: [1,2]}',
        actions JSON NOT NULL COMMENT 'e.g. {discount_percent: 10, max_discount: 50}',
        starts_at DATETIME(3) NULL,
        ends_at DATETIME(3) NULL,
        usage_limit BIGINT UNSIGNED NULL,
        per_user_limit INT UNSIGNED NULL,
        is_active BOOLEAN NOT NULL DEFAULT TRUE,
        created_at DATETIME(3) NOT NULL,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_promotion_rules_code (code),
        KEY idx_promotion_rules_active (is_active, starts_at, ends_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$affiliates} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        code VARCHAR(64) NOT NULL,
        commission_pct DECIMAL(6,4) NOT NULL DEFAULT 5.0000,
        status ENUM('active','paused','banned') NOT NULL DEFAULT 'active',
        total_commission DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        paid_commission DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        created_at DATETIME(3) NOT NULL,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_affiliates_code (code),
        UNIQUE KEY uq_affiliates_user (user_id),
        KEY idx_affiliates_status (status),
        CONSTRAINT fk_affiliates_user FOREIGN KEY (user_id) REFERENCES {$users} (id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$affiliateConversions} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        affiliate_id BIGINT UNSIGNED NOT NULL,
        order_id BIGINT UNSIGNED NOT NULL,
        commission_amount DECIMAL(10,2) NOT NULL,
        status ENUM('pending','paid','voided') NOT NULL DEFAULT 'pending',
        created_at DATETIME(3) NOT NULL,
        paid_at DATETIME(3) NULL,
        PRIMARY KEY (id),
        KEY idx_affiliate_conversions_affiliate (affiliate_id, status),
        KEY idx_affiliate_conversions_order (order_id),
        CONSTRAINT fk_affiliate_conversions_affiliate FOREIGN KEY (affiliate_id) REFERENCES {$affiliates} (id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_affiliate_conversions_order FOREIGN KEY (order_id) REFERENCES {$orders} (id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
