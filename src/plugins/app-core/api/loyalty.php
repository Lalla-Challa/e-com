<?php

declare(strict_types=1);

use AppCore\Auth;
use AppCore\Db;
use AppCore\Http;
use AppCore\Validate;
use AppCore\ValidationException;

require_once __DIR__ . '/_bootstrap.php';

/**
 * Loyalty points.
 *
 * GET  /api/loyalty.php                    — settings:read: list all points (admin)
 * GET  /api/loyalty.php/me                 — returns:read_own: caller's balance
 * POST /api/loyalty.php                    — settings:manage: award points
 */
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $user = Auth::currentUser();
    $pdo = Db::pdo();
    $points = Db::table('loyalty_points');
    $users = Db::table('users');

    if ($method === 'GET' && isset($_GET['me'])) {
        Auth::requirePermission('returns:request');
        $rows = $pdo->prepare(
            'SELECT id, order_id, points, type, expires_at, created_at FROM ' . $points . ' WHERE user_id = ? ORDER BY created_at DESC LIMIT 100'
        );
        $rows->execute([$user['id']]);
        $balance = $pdo->prepare(
            'SELECT COALESCE(SUM(CASE WHEN type = "earned" THEN points ELSE -points END), 0) AS balance FROM ' . $points . ' WHERE user_id = ? AND type != "expired"'
        );
        $balance->execute([$user['id']]);
        Http::ok([
            'balance' => (int) ($balance->fetchColumn() ?? 0),
            'entries' => array_map(static function (array $r): array {
                return [
                    'id' => (int) $r['id'],
                    'order_id' => $r['order_id'] !== null ? (int) $r['order_id'] : null,
                    'points' => (int) $r['points'],
                    'type' => (string) $r['type'],
                    'expires_at' => $r['expires_at'] !== null ? (string) $r['expires_at'] : null,
                    'created_at' => (string) $r['created_at'],
                ];
            }, $rows->fetchAll()),
        ]);
    }

    if (!isset($user) || $user === null) {
        Auth::requireUser();
    }
    Auth::requirePermission('settings:manage');

    if ($method === 'GET') {
        $rows = $pdo->query(
            'SELECT lp.id, lp.user_id, lp.order_id, lp.points, lp.type, lp.expires_at, lp.created_at,
                    u.email, u.full_name
             FROM ' . $points . ' lp
             JOIN ' . $users . ' u ON u.id = lp.user_id
             ORDER BY lp.created_at DESC LIMIT 200'
        )->fetchAll();
        Http::ok(['entries' => array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'user_id' => (int) $r['user_id'],
                'email' => (string) $r['email'],
                'full_name' => (string) $r['full_name'],
                'order_id' => $r['order_id'] !== null ? (int) $r['order_id'] : null,
                'points' => (int) $r['points'],
                'type' => (string) $r['type'],
                'expires_at' => $r['expires_at'] !== null ? (string) $r['expires_at'] : null,
                'created_at' => (string) $r['created_at'],
            ];
        }, $rows)]);
    }

    if ($method === 'POST') {
        $body = Validate::jsonBody();
        $userId = Validate::int($body['user_id'] ?? null, 'user_id', 1, PHP_INT_MAX);
        $pointsVal = Validate::int($body['points'] ?? null, 'points', 1, 1000000);
        $orderId = Validate::int($body['order_id'] ?? null, 'order_id', 1, PHP_INT_MAX, false);
        $type = Validate::enum($body['type'] ?? 'earned', 'type', ['earned', 'redeemed', 'expired']);
        $expiresAt = Validate::str($body['expires_at'] ?? null, 'expires_at', 0, 50, false);
        \assert($userId !== null && $pointsVal !== null && $type !== null);
        $pdo->prepare(
            'INSERT INTO ' . $points . ' (user_id, order_id, points, type, expires_at, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(3))'
        )->execute([$userId, $orderId, $pointsVal, $type, $expiresAt]);
        Http::created(['id' => (int) $pdo->lastInsertId()]);
    }

    Http::error(405, 'METHOD_NOT_ALLOWED', 'Unsupported method for loyalty.');
} catch (Throwable $e) {
    Http::fail($e);
}
