<?php

declare(strict_types=1);

use AppCore\Auth;
use AppCore\Db;
use AppCore\Http;
use AppCore\Money;
use AppCore\Validate;

require_once __DIR__ . '/_bootstrap.php';

/**
 * Admin customer directory.
 *
 * GET           — paginated list with order counts and lifetime spend
 * GET ?id=N     — one customer with addresses, recent orders, groups, loyalty
 * GET ?q=...    — search by email or name
 */
try {
    Auth::requireAdmin();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        Http::error(405, 'METHOD_NOT_ALLOWED', 'Read-only endpoint.');
    }

    $pdo = Db::pdo();
    $users = Db::table('users');
    $orders = Db::table('orders');
    $addresses = Db::table('customer_addresses');
    $customerGroups = Db::table('customer_groups');
    $customerGroupMembers = Db::table('customer_group_members');
    $loyaltyPoints = Db::table('loyalty_points');

    $id = isset($_GET['id']) ? Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX) : null;

    if ($id !== null) {
        $stmt = $pdo->prepare('SELECT id, email, full_name, role, is_active, created_at, last_login_at FROM ' . $users . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if ($user === false) {
            Http::error(404, 'NOT_FOUND', 'Customer not found.');
        }

        $totals = $pdo->prepare(
            'SELECT COUNT(*) AS order_count, COALESCE(SUM(total), 0) AS total_spent
             FROM ' . $orders . ' WHERE user_id = ? AND status NOT IN (\'cancelled\', \'refunded\')'
        );
        $totals->execute([$id]);
        $summary = $totals->fetch() ?: ['order_count' => 0, 'total_spent' => 0];

        $addrStmt = $pdo->prepare('SELECT * FROM ' . $addresses . ' WHERE user_id = ? ORDER BY is_default_shipping DESC, id ASC');
        $addrStmt->execute([$id]);

        $orderStmt = $pdo->prepare(
            'SELECT id, status, subtotal, shipping, tax, discount, total, currency, item_count, created_at
             FROM ' . $orders . ' WHERE user_id = ? ORDER BY id DESC LIMIT 20'
        );
        $orderStmt->execute([$id]);

        $mapOrder = static fn (array $r): array => [
            'id' => (int) $r['id'],
            'status' => (string) $r['status'],
            'subtotal' => Money::norm($r['subtotal']),
            'shipping' => Money::norm($r['shipping']),
            'tax' => Money::norm($r['tax']),
            'discount' => Money::norm($r['discount'] ?? 0),
            'total' => Money::norm($r['total']),
            'currency' => (string) $r['currency'],
            'item_count' => (int) ($r['item_count'] ?? 0),
            'created_at' => (string) $r['created_at'],
        ];

        // Customer groups.
        $groupStmt = $pdo->prepare(
            'SELECT g.code, g.name, g.price_markup_pct
             FROM ' . $customerGroupMembers . ' m
             JOIN ' . $customerGroups . ' g ON g.id = m.group_id
             WHERE m.user_id = ?'
        );
        $groupStmt->execute([$id]);
        $groups = array_map(static function (array $r): array {
            return [
                'code' => (string) $r['code'],
                'name' => (string) $r['name'],
                'price_markup_pct' => (string) $r['price_markup_pct'],
            ];
        }, $groupStmt->fetchAll());

        // Lifetime loyalty balance.
        $loyalty = $pdo->prepare(
            'SELECT COALESCE(SUM(CASE WHEN type = "earned" THEN points ELSE -points END), 0) AS balance
             FROM ' . $loyaltyPoints . ' WHERE user_id = ? AND type != "expired"'
        );
        $loyalty->execute([$id]);
        $loyaltyBalance = (int) ($loyalty->fetchColumn() ?? 0);

        Http::ok(['customer' => [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'full_name' => (string) $user['full_name'],
            'role' => (string) $user['role'],
            'is_active' => (bool) ($user['is_active'] ?? true),
            'created_at' => (string) $user['created_at'],
            'last_login_at' => $user['last_login_at'] !== null ? (string) $user['last_login_at'] : null,
            'groups' => $groups,
            'loyalty_points' => $loyaltyBalance,
            'order_count' => (int) $summary['order_count'],
            'total_spent' => Money::norm($summary['total_spent']),
            'addresses' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'label' => $r['label'] !== null ? (string) $r['label'] : null,
                'full_name' => (string) $r['full_name'],
                'line1' => (string) $r['line1'],
                'line2' => $r['line2'] !== null ? (string) $r['line2'] : null,
                'city' => (string) $r['city'],
                'region' => $r['region'] !== null ? (string) $r['region'] : null,
                'postal_code' => (string) $r['postal_code'],
                'country' => (string) $r['country'],
                'phone' => $r['phone'] !== null ? (string) $r['phone'] : null,
                'is_default_shipping' => (bool) $r['is_default_shipping'],
                'is_default_billing' => (bool) $r['is_default_billing'],
            ], $addrStmt->fetchAll()),
            'orders' => array_map($mapOrder, $orderStmt->fetchAll()),
        ]]);
    }

    // ---- Listing ----------------------------------------------------------
    $page = Validate::int($_GET['page'] ?? 1, 'page', 1, 100000) ?? 1;
    $perPage = Validate::int($_GET['per_page'] ?? 25, 'per_page', 1, 200) ?? 25;
    $q = isset($_GET['q']) ? Validate::str($_GET['q'], 'q', 1, 120) : null;

    $where = '1=1';
    $params = [];
    if ($q !== null) {
        $where .= ' AND (email LIKE ? OR full_name LIKE ?)';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }

    $count = $pdo->prepare('SELECT COUNT(*) FROM ' . $users . ' WHERE ' . $where);
    $count->execute($params);
    $total = (int) $count->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT u.id, u.email, u.full_name, u.role, u.is_active, u.created_at, u.last_login_at,
                COALESCE(o.order_count, 0) AS order_count,
                COALESCE(o.total_spent, 0) AS total_spent
         FROM ' . $users . ' u
         LEFT JOIN (
             SELECT user_id, COUNT(*) AS order_count, SUM(total) AS total_spent
             FROM ' . $orders . '
             WHERE status NOT IN (\'cancelled\', \'refunded\')
             GROUP BY user_id
         ) o ON o.user_id = u.id
         WHERE ' . $where . '
         ORDER BY u.created_at DESC
         LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
    );
    $stmt->execute($params);

    Http::ok(['customers' => array_map(static fn (array $r): array => [
        'id' => (int) $r['id'],
        'email' => (string) $r['email'],
        'full_name' => (string) $r['full_name'],
        'role' => (string) $r['role'],
        'is_active' => (bool) ($r['is_active'] ?? true),
        'created_at' => (string) $r['created_at'],
        'last_login_at' => $r['last_login_at'] !== null ? (string) $r['last_login_at'] : null,
        'order_count' => (int) $r['order_count'],
        'total_spent' => Money::norm($r['total_spent']),
    ], $stmt->fetchAll())], ['page' => $page, 'per_page' => $perPage, 'total' => $total]);
} catch (Throwable $e) {
    Http::fail($e);
}
