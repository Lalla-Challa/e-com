<?php

declare(strict_types=1);

use AppCore\Auth;
use AppCore\Db;
use AppCore\Http;
use AppCore\Validate;
use AppCore\ValidationException;

require_once __DIR__ . '/_bootstrap.php';

/**
 * Promotion rules.
 *
 * GET  /api/promotions.php              — settings:manage: list rules
 * POST /api/promotions.php              — settings:manage: create rule
 * PATCH /api/promotions.php?id=N        — settings:manage: update rule
 * DELETE /api/promotions.php?id=N       — settings:manage: delete rule
 */
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $user = Auth::currentUser();
    if (!isset($user) || $user === null) {
        Auth::requireUser();
    }
    Auth::requirePermission('settings:manage');

    $pdo = Db::pdo();
    $table = Db::table('promotion_rules');

    if ($method === 'GET') {
        $rows = $pdo->query(
            'SELECT id, code, type, conditions, actions, starts_at, ends_at, usage_limit, per_user_limit, is_active, created_at, updated_at
             FROM ' . $table . ' ORDER BY is_active DESC, code ASC'
        )->fetchAll();
        Http::ok(['rules' => array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'code' => (string) $r['code'],
                'type' => (string) $r['type'],
                'conditions' => $r['conditions'] !== null ? json_decode((string) $r['conditions'], true) : [],
                'actions' => $r['actions'] !== null ? json_decode((string) $r['actions'], true) : [],
                'starts_at' => $r['starts_at'] !== null ? (string) $r['starts_at'] : null,
                'ends_at' => $r['ends_at'] !== null ? (string) $r['ends_at'] : null,
                'usage_limit' => $r['usage_limit'] !== null ? (int) $r['usage_limit'] : null,
                'per_user_limit' => $r['per_user_limit'] !== null ? (int) $r['per_user_limit'] : null,
                'is_active' => (bool) $r['is_active'],
                'created_at' => (string) $r['created_at'],
                'updated_at' => (string) $r['updated_at'],
            ];
        }, $rows)]);
    }

    if ($method === 'POST') {
        $body = Validate::jsonBody();
        $code = Validate::str($body['code'] ?? null, 'code', 1, 64);
        $type = Validate::enum($body['type'] ?? 'cart_discount', 'type', ['cart_discount', 'bogo', 'tiered', 'free_shipping']);
        $conditions = $body['conditions'] ?? [];
        $actions = $body['actions'] ?? [];
        if (!is_array($conditions) || !is_array($actions)) {
            Http::error(400, 'VALIDATION_FAILED', 'conditions and actions must be objects.', ['body' => 'invalid']);
        }
        $startsAt = Validate::str($body['starts_at'] ?? null, 'starts_at', 0, 50, false);
        $endsAt = Validate::str($body['ends_at'] ?? null, 'ends_at', 0, 50, false);
        $usageLimit = Validate::int($body['usage_limit'] ?? null, 'usage_limit', 1, 1000000, false);
        $perUserLimit = Validate::int($body['per_user_limit'] ?? null, 'per_user_limit', 1, 100000, false);
        $isActive = Validate::bool($body['is_active'] ?? true, 'is_active', false) ?? true;
        \assert($code !== null && $type !== null);
        $pdo->prepare(
            'INSERT INTO ' . $table . ' (code, type, conditions, actions, starts_at, ends_at, usage_limit, per_user_limit, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))'
        )->execute([$code, $type, json_encode($conditions), json_encode($actions), $startsAt, $endsAt, $usageLimit, $perUserLimit, $isActive]);
        Http::created(['id' => (int) $pdo->lastInsertId(), 'code' => $code]);
    }

    if ($method === 'PATCH' && isset($_GET['id'])) {
        $id = Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX);
        $body = Validate::jsonBody();
        $code = Validate::str($body['code'] ?? null, 'code', 1, 64, false);
        $type = Validate::enum($body['type'] ?? null, 'type', ['cart_discount', 'bogo', 'tiered', 'free_shipping'], false);
        $conditions = $body['conditions'] ?? null;
        $actions = $body['actions'] ?? null;
        if ($conditions !== null && !is_array($conditions)) {
            Http::error(400, 'VALIDATION_FAILED', 'conditions must be an object.', ['conditions' => 'invalid']);
        }
        if ($actions !== null && !is_array($actions)) {
            Http::error(400, 'VALIDATION_FAILED', 'actions must be an object.', ['actions' => 'invalid']);
        }
        $isActive = Validate::bool($body['is_active'] ?? null, 'is_active', false);
        \assert($id !== null);
        $sets = [];
        $params = [];
        if ($code !== null) { $sets[] = 'code = ?'; $params[] = $code; }
        if ($type !== null) { $sets[] = 'type = ?'; $params[] = $type; }
        if ($conditions !== null) { $sets[] = 'conditions = ?'; $params[] = json_encode($conditions); }
        if ($actions !== null) { $sets[] = 'actions = ?'; $params[] = json_encode($actions); }
        if ($isActive !== null) { $sets[] = 'is_active = ?'; $params[] = $isActive ? 'TRUE' : 'FALSE'; }
        $sets[] = 'updated_at = UTC_TIMESTAMP(3)';
        $params[] = $id;
        if (count($sets) > 1) {
            $pdo->prepare('UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        }
        Http::ok(['id' => $id]);
    }

    if ($method === 'DELETE' && isset($_GET['id'])) {
        $id = Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX);
        \assert($id !== null);
        $pdo->prepare('DELETE FROM ' . $table . ' WHERE id = ?')->execute([$id]);
        Http::ok(['id' => $id]);
    }

    Http::error(405, 'METHOD_NOT_ALLOWED', 'Unsupported method for promotions.');
} catch (Throwable $e) {
    Http::fail($e);
}
