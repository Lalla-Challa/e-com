<?php

declare(strict_types=1);

use AppCore\Db;

/**
 * 004_create_orders — orders and immutable line items.
 * order_items keeps purchase history even if a product is later deleted:
 * product_id has NO cascade.
 */
return static function (PDO $pdo): void {
    $users = Db::table('users');
    $products = Db::table('products');
    $orders = Db::table('orders');
    $items = Db::table('order_items');

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$orders} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NULL,
        status ENUM('pending','paid','shipped','delivered','cancelled','refunded') NOT NULL DEFAULT 'pending',
        subtotal DECIMAL(10,2) NOT NULL,
        shipping DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        tax DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        total DECIMAL(10,2) NOT NULL,
        currency CHAR(3) NOT NULL DEFAULT 'USD',
        shipping_address JSON NULL,
        billing_address JSON NULL,
        created_at DATETIME(3) NOT NULL,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        KEY idx_orders_user (user_id),
        KEY idx_orders_status_created (status, created_at),
        CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES {$users} (id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$items} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        quantity INT NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL,
        created_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        KEY idx_order_items_order (order_id),
        KEY idx_order_items_product (product_id),
        CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES {$orders} (id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES {$products} (id) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
