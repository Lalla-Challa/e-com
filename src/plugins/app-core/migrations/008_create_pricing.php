<?php

declare(strict_types=1);

use AppCore\Db;

/**
 * 008_create_pricing — tax rates and shipping methods.
 *
 * Tax is matched most-specific-first: country+region beats country-only, and
 * the most recently inserted row wins ties. Shipping is a flat rate plus an
 * optional per-item amount, with an optional free-over-subtotal threshold —
 * enough to model real carrier tiers without a rules engine.
 */
return static function (PDO $pdo): void {
    $taxRates = Db::table('tax_rates');
    $shipping = Db::table('shipping_methods');

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$taxRates} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        country CHAR(2) NOT NULL,
        region VARCHAR(100) NULL,
        name VARCHAR(60) NOT NULL DEFAULT 'Tax',
        rate DECIMAL(6,4) NOT NULL,
        is_active BOOLEAN NOT NULL DEFAULT TRUE,
        created_at DATETIME(3) NOT NULL,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        KEY idx_tax_rates_lookup (country, region, is_active),
        CONSTRAINT chk_tax_rate_range CHECK (rate >= 0 AND rate <= 100)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$shipping} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        code VARCHAR(64) NOT NULL,
        name VARCHAR(120) NOT NULL,
        description VARCHAR(255) NULL,
        base_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        per_item DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        free_over_subtotal DECIMAL(10,2) NULL,
        estimated_days_min INT UNSIGNED NULL,
        estimated_days_max INT UNSIGNED NULL,
        countries JSON NULL,
        is_active BOOLEAN NOT NULL DEFAULT TRUE,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME(3) NOT NULL,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_shipping_methods_code (code),
        KEY idx_shipping_methods_active (is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
