<?php

declare(strict_types=1);

use AppCore\Auth;
use AppCore\Db;
use AppCore\Http;
use AppCore\Validate;
use AppCore\ValidationException;

require_once __DIR__ . '/_bootstrap.php';

/**
 * GET  /api/currencies.php             — settings:read: list currencies
 * POST /api/currencies.php             — settings:manage: create/update a currency
 * PATCH /api/currencies.php/{id}       — settings:manage: update currency (rate, active, default)
 * DELETE /api/currencies.php/{id}      — settings:manage: deactivate a currency
 *
 * rate_vs_default: 1 default currency unit = this many of code. E.g. default=USD,
 * EUR rate_vs_default=0.93 means 1 USD = 0.93 EUR. To convert an amount from
 * default to code: amount * rate_vs_default. To convert code to default:
 * amount / rate_vs_default.
 */
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $user = Auth::currentUser();
    $pdo = Db::pdo();
    $table = Db::table('currencies');

    if ($method === 'GET') {
        Auth::requirePermission('settings:read');
        $rows = $pdo->query(
            'SELECT id, code, symbol, rate_vs_default, is_default, is_active, updated_at
             FROM ' . $table . ' ORDER BY is_default DESC, code ASC'
        )->fetchAll();
        Http::ok(['currencies' => array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'code' => (string) $r['code'],
                'symbol' => (string) $r['symbol'],
                'rate_vs_default' => (string) $r['rate_vs_default'],
                'is_default' => (bool) $r['is_default'],
                'is_active' => (bool) $r['is_active'],
                'updated_at' => (string) $r['updated_at'],
            ];
        }, $rows)]);
    }

    if (!isset($user) || $user === null) {
        Auth::requireUser(); // 401
    }
    Auth::requirePermission('settings:manage');

    $body = Validate::jsonBody();

    if ($method === 'POST') {
        $code = Validate::str($body['code'] ?? null, 'code', 3, 3);
        $symbol = Validate::str($body['symbol'] ?? null, 'symbol', 1, 10, false) ?? '$';
        $rate = Validate::decimal($body['rate_vs_default'] ?? '1.00', 'rate_vs_default', 0.000001, 1000000);
        $isDefault = Validate::bool($body['is_default'] ?? false, 'is_default', false) ?? false;
        $isActive = Validate::bool($body['is_active'] ?? true, 'is_active', false) ?? true;
        \assert($code !== null && $symbol !== null && $rate !== null && $isDefault !== null && $isActive !== null);

        if ($isDefault) {
            $pdo->prepare('UPDATE ' . $table . ' SET is_default = FALSE')->execute();
        }

        $pdo->prepare(
            'INSERT INTO ' . $table . ' (code, symbol, rate_vs_default, is_default, is_active, updated_at)
             VALUES (?, ?, ?, ?, ?, ' . 'UTC_TIMESTAMP(3)' . ')
             ON DUPLICATE KEY UPDATE symbol = VALUES(symbol), rate_vs_default = VALUES(rate_vs_default),
                                     is_default = VALUES(is_default), is_active = VALUES(is_active),
                                     updated_at = ' . 'UTC_TIMESTAMP(3)' . ''
        )->execute([strtoupper($code), $symbol, $rate, $isDefault, $isActive]);

        $row = $pdo->prepare('SELECT id FROM ' . $table . ' WHERE code = ? LIMIT 1');
        $row->execute([strtoupper($code)]);
        Http::created(['id' => (int) $row->fetchColumn(), 'code' => strtoupper($code)]);
    }

    if ($method === 'PATCH' && isset($_GET['id'])) {
        $id = Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX);
        $rate = Validate::decimal($body['rate_vs_default'] ?? null, 'rate_vs_default', 0.000001, 1000000, false);
        $isDefault = Validate::bool($body['is_default'] ?? null, 'is_default', false);
        $isActive = Validate::bool($body['is_active'] ?? null, 'is_active', false);
        \assert($id !== null);

        if ($isDefault !== null && $isDefault) {
            $pdo->prepare('UPDATE ' . $table . ' SET is_default = FALSE WHERE id != ?')->execute([$id]);
        }

        $sets = [];
        $params = [];
        if ($rate !== null) { $sets[] = 'rate_vs_default = ?'; $params[] = $rate; }
        if ($isDefault !== null) { $sets[] = 'is_default = ?'; $params[] = $isDefault ? 'TRUE' : 'FALSE'; }
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
        $current = $pdo->prepare('SELECT is_default FROM ' . $table . ' WHERE id = ? LIMIT 1');
        $current->execute([$id]);
        if ($current->fetchColumn()) {
            Http::error(422, 'INVALID_OPERATION', 'Cannot deactivate the default currency.');
        }
        $pdo->prepare('UPDATE ' . $table . ' SET is_active = FALSE, updated_at = UTC_TIMESTAMP(3) WHERE id = ?')->execute([$id]);
        Http::ok(['id' => $id]);
    }

    Http::error(405, 'METHOD_NOT_ALLOWED', 'Unsupported method for currencies.');
} catch (Throwable $e) {
    Http::fail($e);
}
