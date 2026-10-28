<?php

declare(strict_types=1);

use AppCore\Db;

/**
 * 007_create_coupons — discount codes and their redemption history.
 *
 * `value` is a percentage (0-100) for type='percent' and a plain money amount
 * for type='fixed'. `used_count` is a denormalised counter kept inside the
 * same transaction as a redemption row, so the "code is exhausted" race is
 * decided by the coupon row lock rather than by counting child rows.
 */
return static function (PDO $pdo): void {
    $coupons = Db::table('coupons');
    $redemptions = Db::table('coupon_redemptions');
    $orders = Db::table('orders');
    $users = Db::table('users');

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$coupons} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        code VARCHAR(64) NOT NULL,
        type ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
        value DECIMAL(10,2) NOT NULL,
        min_subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        max_uses BIGINT UNSIGNED NULL,
        used_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
        per_user_limit INT UNSIGNED NULL,
        starts_at DATETIME(3) NULL,
        ends_at DATETIME(3) NULL,
        is_active BOOLEAN NOT NULL DEFAULT TRUE,
        created_at DATETIME(3) NOT NULL,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_coupons_code (code),
        KEY idx_coupons_active (is_active, code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$redemptions} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        coupon_id BIGINT UNSIGNED NOT NULL,
        order_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NULL,
        discount DECIMAL(10,2) NOT NULL,
        created_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_coupon_redemption_order (order_id, coupon_id),
        KEY idx_coupon_redemptions_coupon (coupon_id),
        KEY idx_coupon_redemptions_user (coupon_id, user_id),
        CONSTRAINT fk_coupon_redemptions_coupon FOREIGN KEY (coupon_id) REFERENCES {$coupons} (id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_coupon_redemptions_order FOREIGN KEY (order_id) REFERENCES {$orders} (id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_coupon_redemptions_user FOREIGN KEY (user_id) REFERENCES {$users} (id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
