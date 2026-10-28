<?php

declare(strict_types=1);

use AppCore\Db;

/**
 * 012_extend_orders_products — additive columns on existing tables.
 *
 * ALTERs are guarded by an information_schema probe rather than
 * `ADD COLUMN IF NOT EXISTS`, which is MariaDB-only; this keeps the migration
 * portable across MariaDB and MySQL.
 *
 * Orders snapshot the pricing decision (tax rate, shipping code, discount,
 * coupon) at placement time so a later change to the rate tables never
 * rewrites history. The new order columns intentionally carry NO foreign key:
 * an order row is an immutable historical record.
 */
return static function (PDO $pdo): void {
    $hasColumn = static function (string $table, string $column) use ($pdo): bool {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);

        return (int) $stmt->fetchColumn() > 0;
    };

    $add = static function (string $table, string $column, string $definition) use ($pdo, $hasColumn): void {
        if (!$hasColumn($table, $column)) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    };

    $products = Db::table('products');
    $add($products, 'cost', "DECIMAL(10,2) NULL COMMENT 'Unit cost / COGS for margin reporting'");
    $add($products, 'low_stock_threshold', 'INT UNSIGNED NOT NULL DEFAULT 5');
    $add($products, 'weight_grams', 'INT UNSIGNED NULL');
    $add($products, 'meta_title', 'VARCHAR(190) NULL');
    $add($products, 'meta_description', 'VARCHAR(300) NULL');

    $hasIndex = static function (string $table, string $index) use ($pdo): bool {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $stmt->execute([$table, $index]);

        return (int) $stmt->fetchColumn() > 0;
    };

    if (!$hasIndex($products, 'idx_products_low_stock')) {
        $pdo->exec("ALTER TABLE {$products} ADD INDEX idx_products_low_stock (is_active, stock)");
    }

    $orders = Db::table('orders');
    $add($orders, 'discount', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00');
    $add($orders, 'coupon_id', 'BIGINT UNSIGNED NULL');
    $add($orders, 'coupon_code', 'VARCHAR(64) NULL');
    $add($orders, 'tax_rate', 'DECIMAL(6,4) NULL');
    $add($orders, 'tax_name', 'VARCHAR(60) NULL');
    $add($orders, 'shipping_method_code', 'VARCHAR(64) NULL');
    $add($orders, 'shipping_method_name', 'VARCHAR(120) NULL');
    $add($orders, 'item_count', 'INT UNSIGNED NOT NULL DEFAULT 0');
    $add($orders, 'customer_email', 'VARCHAR(190) NULL');
    $add($orders, 'cancelled_at', 'DATETIME(3) NULL');
    $add($orders, 'refunded_at', 'DATETIME(3) NULL');
    $add($orders, 'refunded_amount', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00');

    $users = Db::table('users');
    $add($users, 'is_active', 'BOOLEAN NOT NULL DEFAULT TRUE');
    $add($users, 'last_login_at', 'DATETIME(3) NULL');
    $add($users, 'email_verified_at', 'DATETIME(3) NULL');
};
