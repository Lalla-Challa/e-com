<?php

declare(strict_types=1);

use AppCore\Db;

/**
 * 014_create_cms — content pages and key/value store settings.
 *
 * cms_pages is a minimal headless CMS: a title, a slug, and a body stored as
 * JSON so the storefront can render structured blocks instead of raw HTML
 * (no sanitiser needed — the frontend never uses dangerouslySetInnerHTML).
 * Publishing is explicit: a draft is invisible to the public API.
 *
 * cms_settings holds storefront copy and feature flags the admin can edit
 * without a deploy. Keys are namespaced with a dot; `public` marks the ones
 * the storefront is allowed to read.
 */
return static function (PDO $pdo): void {
    $pages = Db::table('cms_pages');
    $settings = Db::table('cms_settings');

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$pages} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        title VARCHAR(190) NOT NULL,
        slug VARCHAR(190) NOT NULL,
        excerpt VARCHAR(300) NULL,
        body JSON NULL,
        status ENUM('draft','published') NOT NULL DEFAULT 'draft',
        published_at DATETIME(3) NULL,
        created_by BIGINT UNSIGNED NULL,
        created_at DATETIME(3) NOT NULL,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_cms_pages_slug (slug),
        KEY idx_cms_pages_status (status, published_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$settings} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        setting_key VARCHAR(120) NOT NULL,
        setting_value TEXT NULL,
        is_public BOOLEAN NOT NULL DEFAULT TRUE,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_cms_settings_key (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
