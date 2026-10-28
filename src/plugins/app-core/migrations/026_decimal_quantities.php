<?php

declare(strict_types=1);

use AppCore\Db;

/**
 * 026_decimal_quantities — metre goods sell in 0.5 steps; cart, orders,
 * on-hand stock and ledger deltas must carry fractional quantities.
 */
return static function (PDO $pdo): void {
    $items = Db::table('cart_items');
    $orderItems = Db::table('order_items');
    $products = Db::table('products');
    $ledger = Db::table('stock_ledger');
    $orders = Db::table('orders');

    $pdo->exec("ALTER TABLE {$items} MODIFY quantity DECIMAL(10,2) NOT NULL");
    $pdo->exec("ALTER TABLE {$orderItems} MODIFY quantity DECIMAL(10,2) NOT NULL");
    $pdo->exec("ALTER TABLE {$products} MODIFY stock DECIMAL(10,2) NOT NULL DEFAULT 0");
    $pdo->exec("ALTER TABLE {$ledger} MODIFY delta DECIMAL(10,2) NOT NULL");
    $pdo->exec("ALTER TABLE {$orders} MODIFY item_count DECIMAL(10,2) NOT NULL DEFAULT 0");
};
