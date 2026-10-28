<?php

declare(strict_types=1);

use AppCore\Db;

/**
 * 023_order_fulfillment — order fulfillments (tracking), customer-initiated
 * returns (RMA), and return items. Extends the existing order status machine:
 *
 *   pending → paid → shipped → delivered
 *   paid/shipped/delivered → cancelled (restock)
 *   delivered → returned (via RMA approval)
 *
 * No multi-vendor: this is a single-store entity.
 */
return static function (PDO $pdo): void {
    $orders = Db::table('orders');
    $orderItems = Db::table('order_items');
    $products = Db::table('products');
    $variants = Db::table('product_variants');
    $users = Db::table('users');
    $ledger = Db::table('stock_ledger');
    $now = 'UTC_TIMESTAMP(3)';

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

    // Extend orders.status ENUM to include 'fulfilled' and 'returning'.
    // MariaDB: MODIFY COLUMN to redefine the ENUM with new values.
    $pdo->exec("ALTER TABLE {$orders} MODIFY COLUMN status ENUM('pending','paid','shipped','delivered','fulfilled','cancelled','refunded','returning','returned') NOT NULL DEFAULT 'pending'");

    $add($orders, 'fulfilled_at', 'DATETIME(3) NULL');
    $add($orders, 'returned_at', 'DATETIME(3) NULL');

    $fulfillments = Db::table('fulfillments');
    $returns = Db::table('returns');
    $returnItems = Db::table('return_items');

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$fulfillments} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id BIGINT UNSIGNED NOT NULL,
        tracking_number VARCHAR(120) NULL,
        carrier VARCHAR(100) NULL,
        method VARCHAR(100) NULL,
        status ENUM('prepared','shipped','delivered','exception') NOT NULL DEFAULT 'prepared',
        shipped_at DATETIME(3) NULL,
        delivered_at DATETIME(3) NULL,
        created_at DATETIME(3) NOT NULL,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        KEY idx_fulfillments_order (order_id),
        KEY idx_fulfillments_status (status),
        CONSTRAINT fk_fulfillments_order FOREIGN KEY (order_id) REFERENCES {$orders} (id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$returns} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        status ENUM('requested','approved','shipped','refunded','rejected') NOT NULL DEFAULT 'requested',
        reason VARCHAR(255) NOT NULL,
        refund_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        created_at DATETIME(3) NOT NULL,
        processed_at DATETIME(3) NULL,
        processed_by BIGINT UNSIGNED NULL,
        PRIMARY KEY (id),
        KEY idx_returns_order (order_id),
        KEY idx_returns_user (user_id),
        KEY idx_returns_status (status),
        CONSTRAINT fk_returns_order FOREIGN KEY (order_id) REFERENCES {$orders} (id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_returns_user FOREIGN KEY (user_id) REFERENCES {$users} (id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_returns_processed_by FOREIGN KEY (processed_by) REFERENCES {$users} (id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$returnItems} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        return_id BIGINT UNSIGNED NOT NULL,
        order_item_id BIGINT UNSIGNED NULL,
        variant_id BIGINT UNSIGNED NULL,
        quantity INT NOT NULL,
        item_condition ENUM('resellable','damaged','defective') NOT NULL DEFAULT 'resellable',
        restock BOOLEAN NOT NULL DEFAULT FALSE,
        created_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        KEY idx_return_items_return (return_id),
        KEY idx_return_items_order_item (order_item_id),
        KEY idx_return_items_variant (variant_id),
        CONSTRAINT fk_return_items_return FOREIGN KEY (return_id) REFERENCES {$returns} (id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_return_items_order_item FOREIGN KEY (order_item_id) REFERENCES {$orderItems} (id) ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT fk_return_items_variant FOREIGN KEY (variant_id) REFERENCES {$variants} (id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
