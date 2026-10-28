<?php

declare(strict_types=1);

use AppCore\Auth;
use AppCore\Db;
use AppCore\Http;
use AppCore\Validate;
use AppCore\ValidationException;

require_once __DIR__ . '/_bootstrap.php';

/**
 * Customer groups management.
 *
 * GET  /api/customer-groups.php              — settings:manage: list groups + members
 * POST /api/customer-groups.php              — settings:manage: create group
 * POST /api/customer-groups.php/members      — settings:manage: assign user to group
 * DELETE /api/customer-groups.php/members    — settings:manage: unassign user from group (?user_id=&group_id=)
 */
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $user = Auth::currentUser();
    if (!isset($user) || $user === null) {
        Auth::requireUser();
    }
    Auth::requirePermission('settings:manage');

    $pdo = Db::pdo();
    $groups = Db::table('customer_groups');
    $members = Db::table('customer_group_members');
    $users = Db::table('users');

    if (isset($_GET['members'])) {
        if ($method === 'POST') {
            $body = Validate::jsonBody();
            $userId = Validate::int($body['user_id'] ?? null, 'user_id', 1, PHP_INT_MAX);
            $groupId = Validate::int($body['group_id'] ?? null, 'group_id', 1, PHP_INT_MAX);
            \assert($userId !== null && $groupId !== null);
            $pdo->prepare(
                'INSERT IGNORE INTO ' . $members . ' (user_id, group_id) VALUES (?, ?)'
            )->execute([$userId, $groupId]);
            Http::created(['user_id' => $userId, 'group_id' => $groupId]);
        }
        if ($method === 'DELETE') {
            $userId = Validate::int($_GET['user_id'] ?? null, 'user_id', 1, PHP_INT_MAX);
            $groupId = Validate::int($_GET['group_id'] ?? null, 'group_id', 1, PHP_INT_MAX);
            \assert($userId !== null && $groupId !== null);
            $pdo->prepare('DELETE FROM ' . $members . ' WHERE user_id = ? AND group_id = ?')->execute([$userId, $groupId]);
            Http::ok(['user_id' => $userId, 'group_id' => $groupId]);
        }
        Http::error(405, 'METHOD_NOT_ALLOWED', 'Unsupported method for group members.');
    }

    if ($method === 'GET') {
        $rows = $pdo->query(
            'SELECT g.id, g.code, g.name, g.price_markup_pct, g.created_at, g.updated_at,
                    COUNT(m.user_id) AS member_count
             FROM ' . $groups . ' g
             LEFT JOIN ' . $members . ' m ON m.group_id = g.id
             GROUP BY g.id ORDER BY g.code ASC'
        )->fetchAll();
        Http::ok(['groups' => array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'code' => (string) $r['code'],
                'name' => (string) $r['name'],
                'price_markup_pct' => (string) $r['price_markup_pct'],
                'member_count' => (int) $r['member_count'],
                'created_at' => (string) $r['created_at'],
                'updated_at' => (string) $r['updated_at'],
            ];
        }, $rows)]);
    }

    if ($method === 'POST') {
        $body = Validate::jsonBody();
        $code = Validate::str($body['code'] ?? null, 'code', 1, 60);
        $name = Validate::str($body['name'] ?? null, 'name', 1, 120);
        $markup = Validate::decimal($body['price_markup_pct'] ?? '0.00', 'price_markup_pct', -100, 1000);
        \assert($code !== null && $name !== null && $markup !== null);
        $pdo->prepare(
            'INSERT INTO ' . $groups . ' (code, name, price_markup_pct, created_at, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))'
        )->execute([$code, $name, $markup]);
        Http::created(['id' => (int) $pdo->lastInsertId(), 'code' => $code]);
    }

    Http::error(405, 'METHOD_NOT_ALLOWED', 'Unsupported method for customer groups.');
} catch (Throwable $e) {
    Http::fail($e);
}
