<?php

declare(strict_types=1);

use AppCore\Auth;
use AppCore\Db;
use AppCore\Http;
use AppCore\Validate;
use AppCore\ValidationException;

require_once __DIR__ . '/_bootstrap.php';

/**
 * GET  /api/settings.php             — public: key/value map of public settings
 * PATCH {settings: {key: value, ...}} — admin: upsert any setting
 * GET  /api/settings.php?all=1       — admin: include private settings too
 * GET  /api/settings.php?store=1     — settings:read: business settings (tax/shipping/currency/etc.)
 * PATCH {store_settings: {...}}       — settings:manage: upsert business settings
 *
 * This is the store's content/config surface the admin can edit without a
 * deploy: hero copy, announcement bar, feature flags.
 */
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $user = Auth::currentUser();
    $pdo = Db::pdo();
    $cmsSettings = Db::table('cms_settings');
    $storeSettings = Db::table('store_settings');

    // ---- Business settings write -------------------------------------------
    if ($method === 'PATCH' && isset($_GET['store_settings'])) {
        Auth::requirePermission('settings:manage');
        $body = Validate::jsonBody();
        $map = $body['store_settings'] ?? null;
        if (!is_array($map) || $map === []) {
            Http::error(400, 'VALIDATION_FAILED', 'Send a non-empty "store_settings" object.', ['store_settings' => 'required']);
        }

        $upsert = $pdo->prepare(
            'INSERT INTO ' . $storeSettings . ' (setting_key, setting_value, updated_at)
             VALUES (?, ?, UTC_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)'
        );
        foreach ($map as $key => $value) {
            if (!is_string($key) || $key === '' || mb_strlen($key) > 120) {
                Http::error(400, 'VALIDATION_FAILED', 'Setting keys must be 1–120 characters.', ['store_settings' => 'bad key']);
            }
            $upsert->execute([
                $key,
                is_scalar($value) || $value === null ? ($value === null ? null : (string) $value) : json_encode($value, JSON_UNESCAPED_UNICODE),
            ]);
        }

        Http::ok(['updated' => array_keys($map)]);
    }

    // ---- Business settings read --------------------------------------------
    if ($method === 'GET' && isset($_GET['store_settings'])) {
        Auth::requirePermission('settings:read');
        $sql = 'SELECT setting_key, setting_value FROM ' . $storeSettings . ' ORDER BY setting_key ASC';
        $map = [];
        foreach ($pdo->query($sql)->fetchAll() as $row) {
            $raw = $row['setting_value'];
            $decoded = json_decode((string) $raw, true);
            $map[(string) $row['setting_key']] = json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
        }
        Http::ok(['store_settings' => $map]);
    }

    // ---- Existing CMS settings ---------------------------------------------
    if ($method === 'PATCH') {
        Auth::requireAdmin();
        $body = Validate::jsonBody();
        $map = $body['settings'] ?? null;
        if (!is_array($map) || $map === []) {
            Http::error(400, 'VALIDATION_FAILED', 'Send a non-empty "settings" object.', ['settings' => 'required']);
        }

        $upsert = $pdo->prepare(
            'INSERT INTO ' . $cmsSettings . ' (setting_key, setting_value, is_public, updated_at)
             VALUES (?, ?, ?, UTC_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)'
        );
        foreach ($map as $key => $value) {
            if (!is_string($key) || $key === '' || mb_strlen($key) > 120) {
                Http::error(400, 'VALIDATION_FAILED', 'Setting keys must be 1–120 characters.', ['settings' => 'bad key']);
            }
            $upsert->execute([
                $key,
                is_scalar($value) || $value === null ? ($value === null ? null : (string) $value) : json_encode($value, JSON_UNESCAPED_UNICODE),
                true,
            ]);
        }

        Http::ok(['updated' => array_keys($map)]);
    }

    $all = isset($_GET['all']) && $user !== null && $user['role'] === 'admin';
    $sql = $all
        ? 'SELECT setting_key, setting_value FROM ' . $cmsSettings . ' ORDER BY setting_key ASC'
        : 'SELECT setting_key, setting_value FROM ' . $cmsSettings . ' WHERE is_public = TRUE ORDER BY setting_key ASC';

    $map = [];
    foreach ($pdo->query($sql)->fetchAll() as $row) {
        $raw = $row['setting_value'];
        $decoded = json_decode((string) $raw, true);
        $map[(string) $row['setting_key']] = json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }

    Http::ok(['settings' => $map]);
} catch (Throwable $e) {
    Http::fail($e);
}
