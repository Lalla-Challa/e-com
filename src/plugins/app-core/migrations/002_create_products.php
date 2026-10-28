<?php

declare(strict_types=1);

use AppCore\Db;

/**
 * 002_create_products — catalog, categories, price history and stock ledger.
 * No wp_posts / wp_postmeta anywhere.
 */
return static function (PDO $pdo): void {
    $categories = Db::table('categories');
    $products = Db::table('products');
    $history = Db::table('price_history');
    $ledger = Db::table('stock_ledger');

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$categories} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug VARCHAR(190) NOT NULL,
        name VARCHAR(120) NOT NULL,
        parent_id BIGINT UNSIGNED NULL,
        created_at DATETIME(3) NOT NULL,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_categories_slug (slug),
        KEY idx_categories_parent (parent_id),
        CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES {$categories} (id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$products} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug VARCHAR(190) NOT NULL,
        sku VARCHAR(64) NOT NULL,
        name VARCHAR(190) NOT NULL,
        description TEXT NULL,
        price DECIMAL(10,2) NOT NULL,
        currency CHAR(3) NOT NULL DEFAULT 'USD',
        stock INT NOT NULL DEFAULT 0,
        category_id BIGINT UNSIGNED NULL,
        image_url VARCHAR(500) NULL,
        metadata JSON NULL,
        is_active BOOLEAN NOT NULL DEFAULT TRUE,
        created_at DATETIME(3) NOT NULL,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_products_slug (slug),
        UNIQUE KEY uq_products_sku (sku),
        KEY idx_products_category (category_id),
        KEY idx_products_active_price (is_active, price),
        KEY idx_products_created (created_at),
        CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES {$categories} (id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$history} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id BIGINT UNSIGNED NOT NULL,
        price DECIMAL(10,2) NOT NULL,
        currency CHAR(3) NOT NULL DEFAULT 'USD',
        effective_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        KEY idx_price_history_product (product_id, effective_at),
        CONSTRAINT fk_price_history_product FOREIGN KEY (product_id) REFERENCES {$products} (id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$ledger} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id BIGINT UNSIGNED NOT NULL,
        delta INT NOT NULL,
        reason ENUM('restock','purchase','manual_adjustment','checkout_reversal') NOT NULL,
        reference_id BIGINT UNSIGNED NULL,
        created_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        KEY idx_stock_ledger_product (product_id, created_at),
        CONSTRAINT fk_stock_ledger_product FOREIGN KEY (product_id) REFERENCES {$products} (id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
