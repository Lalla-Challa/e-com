<?php

declare(strict_types=1);

use AppCore\Db;

/**
 * 015_index_orders_coupon — index on orders.coupon_id.
 *
 * The column is intentionally FK-less (an order is an immutable snapshot and
 * a deleted coupon must not cascade-delete history), but "how much revenue did
 * this coupon drive" needs the index to avoid a full scan.
 */
return static function (PDO $pdo): void {
    $hasIndex = static function (string $table, string $index) use ($pdo): bool {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $stmt->execute([$table, $index]);

        return (int) $stmt->fetchColumn() > 0;
    };

    $orders = Db::table('orders');
    if (!$hasIndex($orders, 'idx_orders_coupon')) {
        $pdo->exec("ALTER TABLE {$orders} ADD INDEX idx_orders_coupon (coupon_id)");
    }
};
