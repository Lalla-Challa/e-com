<?php

declare(strict_types=1);

use AppCore\Db;

/**
 * 021_business_settings — store identity, tax classes/rates, shipping zones &
 * methods, currencies, and invoice records for a single-store entity.
 *
 * All statements are idempotent. Tables that already exist (e.g. tax_rates
 * from a prior schema) are extended with ALTER TABLE rather than recreated.
 * Orders already snapshot tax_rate, tax_name, shipping_method_code/name via
 * migration 012; this phase adds the master tables those values are resolved
 * from.
 */
return static function (PDO $pdo): void {
    $settings = Db::table('store_settings');
    $taxClasses = Db::table('tax_classes');
    $taxRates = Db::table('tax_rates');
    $shippingZones = Db::table('shipping_zones');
    $shippingMethods = Db::table('shipping_methods');
    $currencies = Db::table('currencies');
    $invoices = Db::table('invoices');
    $orders = Db::table('orders');
    $now = 'UTC_TIMESTAMP(3)';

    $hasColumn = static function (string $table, string $column) use ($pdo): bool {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    };
    $hasIndex = static function (string $table, string $index) use ($pdo): bool {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
        );
        $stmt->execute([$table, $index]);
        return (int) $stmt->fetchColumn() > 0;
    };
    $add = static function (string $table, string $column, string $definition) use ($pdo, $hasColumn): void {
        if (!$hasColumn($table, $column)) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    };
    $addIndex = static function (string $table, string $definition, string $indexName) use ($pdo, $hasIndex): void {
        if (!$hasIndex($table, $indexName)) {
            $pdo->exec("ALTER TABLE {$table} ADD {$definition}");
        }
    };
    $addFk = static function (string $table, string $definition, string $fkName) use ($pdo): void {
        // MySQL has no IF NOT EXISTS for constraints; guard via information_schema.
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?'
        );
        $stmt->execute([$table, $fkName]);
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE {$table} ADD {$definition}");
        }
    };

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$settings} (
        setting_key VARCHAR(120) NOT NULL,
        setting_value TEXT NULL,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$taxClasses} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        code VARCHAR(60) NOT NULL,
        label VARCHAR(120) NOT NULL,
        default_rate_id BIGINT UNSIGNED NULL,
        created_at DATETIME(3) NOT NULL,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_tax_classes_code (code),
        KEY idx_tax_classes_default_rate (default_rate_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$taxRates} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        country CHAR(2) NOT NULL,
        rate DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
        created_at DATETIME(3) NOT NULL,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Extend an existing tax_rates table with columns this migration needs.
    $add($taxRates, 'class_id', 'BIGINT UNSIGNED NULL');
    $add($taxRates, 'region', 'VARCHAR(100) NULL');
    $add($taxRates, 'postal_code', 'VARCHAR(20) NULL');
    $add($taxRates, 'is_default', 'BOOLEAN NOT NULL DEFAULT FALSE');
    $add($taxRates, 'rate', 'DECIMAL(6,4) NOT NULL DEFAULT 0.0000');
    if (!$hasColumn($taxRates, 'class_id') || !$hasColumn($taxRates, 'country')) {
        // Nothing to do — columns added above.
    }
    $addIndex($taxRates, 'INDEX idx_tax_rates_class (class_id)', 'idx_tax_rates_class');
    $addIndex($taxRates, 'INDEX idx_tax_rates_lookup (country, region, postal_code)', 'idx_tax_rates_lookup');
    $addFk($taxRates, 'CONSTRAINT fk_tax_rates_class FOREIGN KEY (class_id) REFERENCES ' . $taxClasses . ' (id) ON DELETE CASCADE ON UPDATE CASCADE', 'fk_tax_rates_class');

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$shippingZones} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(120) NOT NULL,
        countries JSON NOT NULL COMMENT 'ISO country codes included in this zone',
        regions JSON NULL COMMENT 'ISO region codes included; null means all',
        is_default BOOLEAN NOT NULL DEFAULT FALSE,
        created_at DATETIME(3) NOT NULL,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        KEY idx_shipping_zones_default (is_default)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$shippingMethods} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        zone_id BIGINT UNSIGNED NULL,
        code VARCHAR(64) NOT NULL,
        name VARCHAR(120) NOT NULL,
        type ENUM('flat_rate','weight_based','free') NOT NULL DEFAULT 'flat_rate',
        base_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Flat rate or minimum charge',
        cost_per_kg DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Per-kg surcharge for weight_based',
        min_order_free DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Subtotal above this = free shipping',
        max_weight_kg DECIMAL(10,2) NULL COMMENT 'Null = no weight cap',
        sort_order INT NOT NULL DEFAULT 0,
        is_active BOOLEAN NOT NULL DEFAULT TRUE,
        created_at DATETIME(3) NOT NULL,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        KEY idx_shipping_methods_zone (zone_id, is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Extend an existing shipping_methods table with columns this migration needs.
    $add($shippingMethods, 'zone_id', 'BIGINT UNSIGNED NULL');
    $add($shippingMethods, 'type', "ENUM('flat_rate','weight_based','free') NOT NULL DEFAULT 'flat_rate'");
    $add($shippingMethods, 'base_cost', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00');
    $add($shippingMethods, 'cost_per_kg', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00');
    $add($shippingMethods, 'min_order_free', 'DECIMAL(10,2) NOT NULL DEFAULT 0.00');
    $add($shippingMethods, 'max_weight_kg', 'DECIMAL(10,2) NULL');

    $addIndex($shippingMethods, 'INDEX idx_shipping_methods_zone (zone_id, is_active, sort_order)', 'idx_shipping_methods_zone');
    $addFk($shippingMethods, 'CONSTRAINT fk_shipping_methods_zone FOREIGN KEY (zone_id) REFERENCES ' . $shippingZones . ' (id) ON DELETE CASCADE ON UPDATE CASCADE', 'fk_shipping_methods_zone');

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$currencies} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        code CHAR(3) NOT NULL,
        symbol VARCHAR(10) NOT NULL DEFAULT '$',
        rate_vs_default DECIMAL(12,6) NOT NULL DEFAULT 1.000000 COMMENT '1 default currency unit = this many of code',
        is_default BOOLEAN NOT NULL DEFAULT FALSE,
        is_active BOOLEAN NOT NULL DEFAULT TRUE,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_currencies_code (code),
        KEY idx_currencies_default (is_default, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$invoices} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id BIGINT UNSIGNED NOT NULL,
        invoice_number VARCHAR(64) NOT NULL,
        status ENUM('draft','issued','paid','voided') NOT NULL DEFAULT 'draft',
        subtotal DECIMAL(10,2) NOT NULL,
        tax DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        total DECIMAL(10,2) NOT NULL,
        currency_code CHAR(3) NOT NULL,
        currency_rate DECIMAL(12,6) NOT NULL DEFAULT 1.000000,
        issued_at DATETIME(3) NULL,
        paid_at DATETIME(3) NULL,
        pdf_path VARCHAR(500) NULL,
        created_at DATETIME(3) NOT NULL,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_invoices_number (invoice_number),
        KEY idx_invoices_order (order_id),
        KEY idx_invoices_status (status),
        CONSTRAINT fk_invoices_order FOREIGN KEY (order_id) REFERENCES {$orders} (id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Seed default currency USD.
    $pdo->prepare('INSERT IGNORE INTO ' . $currencies . ' (code, symbol, rate_vs_default, is_default, is_active, updated_at) VALUES (?, ?, 1.000000, TRUE, TRUE, ' . $now . ')')
        ->execute(['USD', '$']);

    // Seed default tax class + rate (standard, worldwide default 0).
    $pdo->prepare('INSERT IGNORE INTO ' . $taxClasses . ' (code, label, created_at, updated_at) VALUES (?, ?, ' . $now . ', ' . $now . ')')
        ->execute(['standard', 'Standard']);
    $standardClassId = (int) $pdo->query('SELECT id FROM ' . $taxClasses . ' WHERE code = "standard"')->fetchColumn();

    $pdo->prepare('INSERT IGNORE INTO ' . $taxRates . ' (class_id, country, rate, is_default, created_at, updated_at) VALUES (?, ?, 0.0000, TRUE, ' . $now . ', ' . $now . ')')
        ->execute([$standardClassId, 'XX']);

    $pdo->prepare('UPDATE ' . $taxClasses . ' SET default_rate_id = ? WHERE id = ? AND default_rate_id IS NULL')
        ->execute([$standardClassId, $standardClassId]);

    // Seed default shipping zone (worldwide) + flat rate method.
    $pdo->prepare('INSERT IGNORE INTO ' . $shippingZones . ' (name, countries, is_default, created_at, updated_at) VALUES (?, ?, TRUE, ' . $now . ', ' . $now . ')')
        ->execute(['Worldwide', json_encode(['*'])]);
    $worldwideZoneId = (int) $pdo->query('SELECT id FROM ' . $shippingZones . ' WHERE is_default = TRUE LIMIT 1')->fetchColumn();

    $pdo->prepare('INSERT IGNORE INTO ' . $shippingMethods . ' (zone_id, code, name, type, base_cost, sort_order, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 0, TRUE, ' . $now . ', ' . $now . ')')
        ->execute([$worldwideZoneId, 'flat_rate', 'Standard Shipping', 'flat_rate', 0.00]);

    // Seed core store settings.
    $seedSettings = [
        'store_name' => 'Passive Store',
        'store_email' => '',
        'store_address' => '',
        'store_phone' => '',
        'default_currency' => 'USD',
        'default_tax_class' => 'standard',
        'invoice_prefix' => 'INV',
    ];
    foreach ($seedSettings as $key => $value) {
        $pdo->prepare('INSERT INTO ' . $settings . ' (setting_key, setting_value, updated_at) VALUES (?, ?, ' . $now . ')
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)')
            ->execute([$key, $value]);
    }
};
