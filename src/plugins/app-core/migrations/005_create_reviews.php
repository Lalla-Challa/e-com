<?php

declare(strict_types=1);

use AppCore\Db;

/**
 * 005_create_reviews — one review per user per product, 1–5 rating,
 * verified-purchase flag, composite (product_id, created_at) index.
 */
return static function (PDO $pdo): void {
    $products = Db::table('products');
    $users = Db::table('users');
    $reviews = Db::table('reviews');

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$reviews} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        rating TINYINT UNSIGNED NOT NULL,
        title VARCHAR(160) NOT NULL,
        body TEXT NOT NULL,
        is_verified_purchase BOOLEAN NOT NULL DEFAULT FALSE,
        is_approved BOOLEAN NOT NULL DEFAULT FALSE,
        created_at DATETIME(3) NOT NULL,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_reviews_product_user (product_id, user_id),
        KEY idx_reviews_product_created (product_id, created_at),
        KEY idx_reviews_user (user_id),
        KEY idx_reviews_moderation (is_approved, created_at),
        CONSTRAINT fk_reviews_product FOREIGN KEY (product_id) REFERENCES {$products} (id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_reviews_user FOREIGN KEY (user_id) REFERENCES {$users} (id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT chk_reviews_rating CHECK (rating BETWEEN 1 AND 5)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
