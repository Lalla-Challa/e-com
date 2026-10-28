<?php

declare(strict_types=1);

use AppCore\Db;

/**
 * 022_product_attributes — configurable attribute sets, product variants,
 * inventory locations, stock reservations, and purchase orders.
 *
 * Additive columns on products/stock_ledger are guarded by information_schema
 * probes (same pattern as migration 012). Fully configurable attributes let
 * the admin define any attribute set (fabric width, color, size, etc.) and
 * generate variants with independent stock per variant.
 */
return static function (PDO $pdo): void {
    $products = Db::table('products');
    $ledger = Db::table('stock_ledger');
    $orders = Db::table('orders');
    $now = 'UTC_TIMESTAMP(3)';

    // ---- Additive product columns -----------------------------------------
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

    $add($products, 'attribute_set_id', 'BIGINT UNSIGNED NULL');
    $add($products, 'variant_mode', "ENUM('none','variants') NOT NULL DEFAULT 'none'");
    $pdo->exec("ALTER TABLE {$products} ADD KEY idx_products_attribute_set (attribute_set_id)");

    $add($ledger, 'variant_id', 'BIGINT UNSIGNED NULL');
    $add($ledger, 'location_id', 'BIGINT UNSIGNED NULL');
    $add($ledger, 'reference_type', "ENUM('checkout','adjustment','purchase','reservation','release','checkout_reversal') NOT NULL DEFAULT 'adjustment'");
    $pdo->exec("ALTER TABLE {$ledger} ADD KEY idx_stock_ledger_variant (variant_id, created_at)");
    $pdo->exec("ALTER TABLE {$ledger} ADD KEY idx_stock_ledger_location (location_id, created_at)");

    // ---- Attribute sets ----------------------------------------------------
    $attributeSets = Db::table('attribute_sets');
    $attributeSetItems = Db::table('attribute_set_items');
    $productAttributes = Db::table('product_attributes');
    $productVariants = Db::table('product_variants');
    $inventoryLocations = Db::table('inventory_locations');
    $stockReservations = Db::table('stock_reservations');
    $purchaseOrders = Db::table('purchase_orders');
    $purchaseOrderItems = Db::table('purchase_order_items');
    $users = Db::table('users');

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$attributeSets} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(120) NOT NULL,
        description VARCHAR(300) NULL,
        is_active BOOLEAN NOT NULL DEFAULT TRUE,
        created_at DATETIME(3) NOT NULL,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_attribute_sets_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$attributeSetItems} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        set_id BIGINT UNSIGNED NOT NULL,
        attribute_name VARCHAR(120) NOT NULL COMMENT 'e.g. color, size, width, fiber',
        position INT NOT NULL DEFAULT 0,
        is_required BOOLEAN NOT NULL DEFAULT TRUE,
        created_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        KEY idx_attribute_set_items_set (set_id, position),
        CONSTRAINT fk_attribute_set_items_set FOREIGN KEY (set_id) REFERENCES {$attributeSets} (id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$productAttributes} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id BIGINT UNSIGNED NOT NULL,
        set_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_product_attributes_product (product_id),
        KEY idx_product_attributes_set (set_id),
        CONSTRAINT fk_product_attributes_product FOREIGN KEY (product_id) REFERENCES {$products} (id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_product_attributes_set FOREIGN KEY (set_id) REFERENCES {$attributeSets} (id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$productVariants} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id BIGINT UNSIGNED NOT NULL,
        sku VARCHAR(64) NOT NULL,
        attributes JSON NOT NULL COMMENT 'Attribute values keyed by set attribute names',
        price_adjustment DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Added to base product price',
        stock INT NOT NULL DEFAULT 0,
        low_stock_threshold INT UNSIGNED NULL,
        weight_grams INT UNSIGNED NULL,
        is_active BOOLEAN NOT NULL DEFAULT TRUE,
        created_at DATETIME(3) NOT NULL,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_product_variants_sku (sku),
        KEY idx_product_variants_product (product_id),
        KEY idx_product_variants_active_stock (is_active, stock),
        CONSTRAINT fk_product_variants_product FOREIGN KEY (product_id) REFERENCES {$products} (id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$inventoryLocations} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(120) NOT NULL,
        code VARCHAR(40) NOT NULL COMMENT 'short code e.g. WH-01, SHELF-A',
        address VARCHAR(300) NULL,
        is_default BOOLEAN NOT NULL DEFAULT FALSE,
        is_active BOOLEAN NOT NULL DEFAULT TRUE,
        created_at DATETIME(3) NOT NULL,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_inventory_locations_code (code),
        KEY idx_inventory_locations_default (is_default)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$stockReservations} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        variant_id BIGINT UNSIGNED NULL,
        product_id BIGINT UNSIGNED NULL,
        cart_id BIGINT UNSIGNED NULL,
        order_id BIGINT UNSIGNED NULL,
        quantity INT NOT NULL,
        expires_at DATETIME(3) NOT NULL,
        status ENUM('active','released','fulfilled') NOT NULL DEFAULT 'active',
        created_at DATETIME(3) NOT NULL,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        KEY idx_stock_reservations_variant (variant_id, status, expires_at),
        KEY idx_stock_reservations_cart (cart_id),
        KEY idx_stock_reservations_order (order_id),
        KEY idx_stock_reservations_expires (expires_at),
        CONSTRAINT fk_stock_reservations_variant FOREIGN KEY (variant_id) REFERENCES {$productVariants} (id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_stock_reservations_product FOREIGN KEY (product_id) REFERENCES {$products} (id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_stock_reservations_order FOREIGN KEY (order_id) REFERENCES {$orders} (id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ---- Default location --------------------------------------------------
    $pdo->prepare(
        'INSERT IGNORE INTO ' . $inventoryLocations . ' (name, code, is_default, is_active, created_at, updated_at)
         VALUES (?, ?, TRUE, TRUE, ' . $now . ', ' . $now . ')'
    )->execute(['Default Warehouse', 'WH-01']);

    // ---- Seed attribute set: Fabric ---------------------------------------
    $pdo->prepare('INSERT IGNORE INTO ' . $attributeSets . ' (name, description, is_active, created_at, updated_at) VALUES (?, ?, TRUE, ' . $now . ', ' . $now . ')')
        ->execute(['Fabric', 'Cloth sold by the metre: width, fiber, weight.']);
    $fabricSetId = (int) $pdo->query('SELECT id FROM ' . $attributeSets . ' WHERE name = "Fabric"')->fetchColumn();

    foreach (['width_cm', 'fiber', 'weight_gsm'] as $pos => $attrName) {
        $pdo->prepare(
            'INSERT INTO ' . $attributeSetItems . ' (set_id, attribute_name, position, is_required, created_at) VALUES (?, ?, ?, TRUE, ' . $now . ')'
        )->execute([$fabricSetId, $attrName, $pos]);
    }
};
