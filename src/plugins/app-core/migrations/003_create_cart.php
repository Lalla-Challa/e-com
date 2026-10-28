<?php

declare(strict_types=1);

use AppCore\Db;

/**
 * 003_create_cart — persistent carts for signed-in users AND guests
 * (guests keyed by a signed session cookie), plus line items with
 * add-time price snapshots.
 */
return static function (PDO $pdo): void {
    $users = Db::table('users');
    $products = Db::table('products');
    $carts = Db::table('cart');
    $items = Db::table('cart_items');

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$carts} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NULL,
        session_token CHAR(64) NOT NULL,
        created_at DATETIME(3) NOT NULL,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_cart_session (session_token),
        KEY idx_cart_user (user_id),
        CONSTRAINT fk_cart_user FOREIGN KEY (user_id) REFERENCES {$users} (id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$items} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        cart_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        quantity INT NOT NULL,
        unit_price DECIMAL(10,2) NOT NULL,
        created_at DATETIME(3) NOT NULL,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_cart_items_cart_product (cart_id, product_id),
        KEY idx_cart_items_product (product_id),
        CONSTRAINT fk_cart_items_cart FOREIGN KEY (cart_id) REFERENCES {$carts} (id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_cart_items_product FOREIGN KEY (product_id) REFERENCES {$products} (id) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
