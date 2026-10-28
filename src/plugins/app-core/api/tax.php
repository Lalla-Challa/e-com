<?php

declare(strict_types=1);

use AppCore\Auth;
use AppCore\Db;
use AppCore\Http;
use AppCore\Validate;
use AppCore\ValidationException;

require_once __DIR__ . '/_bootstrap.php';

/**
 * GET  /api/tax.php                    — settings:read: list tax classes + rates
 * GET  /api/tax.php?calculate=1&country=XX&region=YY&class=standard
 *                                       — settings:read: effective rate for an address/class
 * POST /api/tax.php/classes            — settings:manage: create tax class
 * POST /api/tax.php/rates              — settings:manage: create tax rate
 * PATCH /api/tax.php/classes/{id}      — settings:manage: update tax class
 * PATCH /api/tax.php/rates/{id}        — settings:manage: update tax rate
 * DELETE /api/tax.php/classes/{id}     — settings:manage: delete tax class
 * DELETE /api/tax.php/rates/{id}       — settings:manage: delete tax rate
 */
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $user = Auth::currentUser();
    $pdo = Db::pdo();
    $classes = Db::table('tax_classes');
    $rates = Db::table('tax_rates');

    // ---- Calculate effective rate ------------------------------------------
    if ($method === 'GET' && isset($_GET['calculate'])) {
        Auth::requirePermission('settings:read');
        $country = Validate::str($_GET['country'] ?? null, 'country', 2, 2);
        $region = Validate::str($_GET['region'] ?? null, 'region', 0, 100, false);
        $class = Validate::str($_GET['class'] ?? 'standard', 'class', 1, 60, false) ?? 'standard';
        \assert($country !== null && $class !== null);

        $cls = $pdo->prepare('SELECT id FROM ' . $classes . ' WHERE code = ? LIMIT 1');
        $cls->execute([$class]);
        $classId = $cls->fetchColumn();
        if ($classId === false) {
            Http::error(404, 'NOT_FOUND', 'Tax class not found.');
        }

        // Most specific match: exact country+region+postal, then country+region,
        // then country wildcard, then default rate for the class.
        $rate = null;
        $postal = $_GET['postal_code'] ?? null;

        $stmt = $pdo->prepare(
            'SELECT rate FROM ' . $rates . '
             WHERE class_id = ? AND country = ?
               AND (region IS NULL OR region = ?)
               AND (postal_code IS NULL OR postal_code = ?)
             ORDER BY (postal_code IS NOT NULL) DESC, (region IS NOT NULL) DESC
             LIMIT 1'
        );
        $stmt->execute([$classId, $country, $region, $postal]);
        $rate = $stmt->fetchColumn();

        if ($rate === false) {
            $stmt = $pdo->prepare(
                'SELECT rate FROM ' . $rates . '
                 WHERE class_id = ? AND country = ? AND region IS NULL AND postal_code IS NULL
                 LIMIT 1'
            );
            $stmt->execute([$classId, $country]);
            $rate = $stmt->fetchColumn();
        }
        if ($rate === false) {
            $stmt = $pdo->prepare(
                'SELECT rate FROM ' . $rates . '
                 WHERE class_id = ? AND country = ? AND is_default = TRUE
                 LIMIT 1'
            );
            $stmt->execute([$classId, $country]);
            $rate = $stmt->fetchColumn();
        }
        if ($rate === false) {
            $stmt = $pdo->prepare(
                'SELECT rate FROM ' . $rates . '
                 WHERE class_id = ? AND is_default = TRUE
                 LIMIT 1'
            );
            $stmt->execute([$classId]);
            $rate = $stmt->fetchColumn();
        }

        Http::ok([
            'class' => $class,
            'country' => $country,
            'region' => $region !== null ? $region : null,
            'rate' => $rate !== false ? (string) $rate : '0.0000',
        ]);
    }

    // ---- List classes + rates ----------------------------------------------
    if ($method === 'GET') {
        Auth::requirePermission('settings:read');
        $classRows = $pdo->query(
            'SELECT c.id, c.code, c.label, c.default_rate_id, c.created_at, c.updated_at,
                    r.rate AS default_rate
             FROM ' . $classes . ' c
             LEFT JOIN ' . $rates . ' r ON r.id = c.default_rate_id
             ORDER BY c.code ASC'
        )->fetchAll();

        $rateRows = $pdo->query(
            'SELECT r.id, r.class_id, r.country, r.region, r.postal_code, r.rate, r.is_default, r.created_at, r.updated_at
             FROM ' . $rates . ' r ORDER BY r.country ASC, r.region ASC, r.id ASC'
        )->fetchAll();

        Http::ok([
            'classes' => array_map(static function (array $r) use (&$rateRows): array {
                return [
                    'id' => (int) $r['id'],
                    'code' => (string) $r['code'],
                    'label' => (string) $r['label'],
                    'default_rate_id' => $r['default_rate_id'] !== null ? (int) $r['default_rate_id'] : null,
                    'default_rate' => $r['default_rate'] !== null ? (string) $r['default_rate'] : null,
                    'created_at' => (string) $r['created_at'],
                    'updated_at' => (string) $r['updated_at'],
                ];
            }, $classRows),
            'rates' => array_map(static function (array $r): array {
                return [
                    'id' => (int) $r['id'],
                    'class_id' => (int) $r['class_id'],
                    'country' => (string) $r['country'],
                    'region' => $r['region'] !== null ? (string) $r['region'] : null,
                    'postal_code' => $r['postal_code'] !== null ? (string) $r['postal_code'] : null,
                    'rate' => (string) $r['rate'],
                    'is_default' => (bool) $r['is_default'],
                    'created_at' => (string) $r['created_at'],
                    'updated_at' => (string) $r['updated_at'],
                ];
            }, $rateRows),
        ]);
    }

    // ---- Write endpoints (settings:manage) ---------------------------------
    if (!isset($user) || $user === null) {
        Auth::requireUser(); // 401
    }
    Auth::requirePermission('settings:manage');

    $body = Validate::jsonBody();

    if ($method === 'POST' && isset($_GET['classes'])) {
        $code = Validate::str($body['code'] ?? null, 'code', 1, 60);
        $label = Validate::str($body['label'] ?? null, 'label', 1, 120);
        \assert($code !== null && $label !== null);
        $pdo->prepare(
            'INSERT INTO ' . $classes . ' (code, label, created_at, updated_at) VALUES (?, ?, ' . 'UTC_TIMESTAMP(3)' . ', ' . 'UTC_TIMESTAMP(3)' . ')'
        )->execute([$code, $label]);
        Http::created(['id' => (int) $pdo->lastInsertId(), 'code' => $code]);
    }

    if ($method === 'POST' && isset($_GET['rates'])) {
        $classId = Validate::int($body['class_id'] ?? null, 'class_id', 1, PHP_INT_MAX);
        $country = Validate::str($body['country'] ?? null, 'country', 2, 2);
        $region = Validate::str($body['region'] ?? null, 'region', 0, 100, false);
        $postalCode = Validate::str($body['postal_code'] ?? null, 'postal_code', 0, 20, false);
        $rate = Validate::decimal($body['rate'] ?? null, 'rate', 0, 100);
        $isDefault = Validate::bool($body['is_default'] ?? false, 'is_default', false) ?? false;
        \assert($classId !== null && $country !== null && $rate !== null && $isDefault !== null);

        if ($isDefault) {
            $pdo->prepare('UPDATE ' . $rates . ' SET is_default = FALSE WHERE class_id = ?')->execute([$classId]);
        }

        $pdo->prepare(
            'INSERT INTO ' . $rates . ' (class_id, country, region, postal_code, rate, is_default, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ' . 'UTC_TIMESTAMP(3)' . ', ' . 'UTC_TIMESTAMP(3)' . ')'
        )->execute([$classId, $country, $region, $postalCode, $rate, $isDefault]);
        Http::created(['id' => (int) $pdo->lastInsertId()]);
    }

    if ($method === 'PATCH' && isset($_GET['classes'], $_GET['id'])) {
        $id = Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX);
        $label = Validate::str($body['label'] ?? null, 'label', 1, 120, false);
        \assert($id !== null);
        $sets = [];
        $params = [];
        if ($label !== null) {
            $sets[] = 'label = ?';
            $params[] = $label;
        }
        $sets[] = 'updated_at = UTC_TIMESTAMP(3)';
        $params[] = $id;
        if (count($sets) > 1) {
            $pdo->prepare('UPDATE ' . $classes . ' SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        }
        Http::ok(['id' => $id]);
    }

    if ($method === 'PATCH' && isset($_GET['rates'], $_GET['id'])) {
        $id = Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX);
        $rate = Validate::decimal($body['rate'] ?? null, 'rate', 0, 100, false);
        $isDefault = Validate::bool($body['is_default'] ?? null, 'is_default', false);
        \assert($id !== null);
        $sets = [];
        $params = [];
        if ($rate !== null) {
            $sets[] = 'rate = ?';
            $params[] = $rate;
        }
        if ($isDefault !== null) {
            if ($isDefault) {
                $pdo->prepare('UPDATE ' . $rates . ' SET is_default = FALSE WHERE class_id = (SELECT class_id FROM ' . $rates . ' WHERE id = ?)')->execute([$id]);
            }
            $sets[] = 'is_default = ?';
            $params[] = $isDefault ? 'TRUE' : 'FALSE';
        }
        $sets[] = 'updated_at = UTC_TIMESTAMP(3)';
        $params[] = $id;
        if (count($sets) > 1) {
            $pdo->prepare('UPDATE ' . $rates . ' SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        }
        Http::ok(['id' => $id]);
    }

    if ($method === 'DELETE' && isset($_GET['classes'], $_GET['id'])) {
        $id = Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX);
        \assert($id !== null);
        $pdo->prepare('DELETE FROM ' . $classes . ' WHERE id = ?')->execute([$id]);
        Http::ok(['id' => $id]);
    }

    if ($method === 'DELETE' && isset($_GET['rates'], $_GET['id'])) {
        $id = Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX);
        \assert($id !== null);
        $pdo->prepare('DELETE FROM ' . $rates . ' WHERE id = ?')->execute([$id]);
        Http::ok(['id' => $id]);
    }

    Http::error(405, 'METHOD_NOT_ALLOWED', 'Unsupported method for tax.');
} catch (Throwable $e) {
    Http::fail($e);
}
