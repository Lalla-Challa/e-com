<?php

declare(strict_types=1);

use AppCore\Auth;
use AppCore\Db;
use AppCore\Http;
use AppCore\Money;
use AppCore\Validate;
use AppCore\ValidationException;

require_once __DIR__ . '/_bootstrap.php';

/**
 * Customer-initiated returns (RMA).
 *
 * GET  /api/returns.php                    — returns:read_own (caller) / read_all (admin)
 * GET  /api/returns.php?id=N               — single return (owner or admin)
 * POST /api/returns.php                    — returns:request: customer requests a return
 * PATCH /api/returns.php?id=N              — returns:manage: admin approves/rejects
 * POST /api/returns.php?id=N/items         — returns:manage: add return items (admin)
 *
 * Flow: customer requests (delivered orders only, within window) → admin
 * approves (status: approved) → customer ships back → admin receives
 * (status: refunded, restock resellable items).
 */
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $user = Auth::requireUser();
    $pdo = Db::pdo();
    $returns = Db::table('returns');
    $returnItems = Db::table('return_items');
    $orders = Db::table('orders');
    $orderItems = Db::table('order_items');
    $products = Db::table('products');
    $variants = Db::table('product_variants');
    $ledger = Db::table('stock_ledger');
    $users = Db::table('users');
    $now = 'UTC_TIMESTAMP(3)';

    // ---- List returns -------------------------------------------------------
    if ($method === 'GET' && !isset($_GET['id'])) {
        $isAdmin = $user['role'] === 'admin';
        $page = Validate::int($_GET['page'] ?? 1, 'page', 1, 100000) ?? 1;
        $perPage = Validate::int($_GET['per_page'] ?? 20, 'per_page', 1, 100) ?? 20;
        $status = isset($_GET['status']) ? Validate::enum($_GET['status'], 'status', ['requested', 'approved', 'shipped', 'refunded', 'rejected']) : null;

        $where = $isAdmin ? '1=1' : 'user_id = ?';
        $params = $isAdmin ? [] : [$user['id']];
        if ($status !== null) {
            $where .= ' AND status = ?';
            $params[] = $status;
        }

        $count = $pdo->prepare('SELECT COUNT(*) FROM ' . $returns . ' WHERE ' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $rows = $pdo->prepare(
            'SELECT r.id, r.order_id, r.user_id, r.status, r.reason, r.refund_amount,
                    r.created_at, r.processed_at, r.processed_by,
                    o.status AS order_status, o.total AS order_total
             FROM ' . $returns . ' r
             JOIN ' . $orders . ' o ON o.id = r.order_id
             WHERE ' . $where . '
             ORDER BY r.created_at DESC
             LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
        );
        $rows->execute($params);
        Http::ok(['returns' => array_map(static function (array $r) use ($isAdmin): array {
            return [
                'id' => (int) $r['id'],
                'order_id' => (int) $r['order_id'],
                'user_id' => (int) $r['user_id'],
                'status' => (string) $r['status'],
                'reason' => (string) $r['reason'],
                'refund_amount' => Money::norm($r['refund_amount']),
                'created_at' => (string) $r['created_at'],
                'processed_at' => $r['processed_at'] !== null ? (string) $r['processed_at'] : null,
                'order_status' => (string) $r['order_status'],
                'order_total' => Money::norm($r['order_total']),
                'can_manage' => $isAdmin,
            ];
        }, $rows->fetchAll())], ['page' => $page, 'per_page' => $perPage, 'total' => $total]);
    }

    // ---- Single return ------------------------------------------------------
    if ($method === 'GET' && isset($_GET['id'])) {
        $id = Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX);
        \assert($id !== null);
        $isAdmin = $user['role'] === 'admin';
        $where = $isAdmin ? 'id = ?' : 'id = ? AND user_id = ?';
        $params = $isAdmin ? [$id] : [$id, $user['id']];
        $row = $pdo->prepare('SELECT * FROM ' . $returns . ' WHERE ' . $where . ' LIMIT 1');
        $row->execute($params);
        $ret = $row->fetch();
        if ($ret === false) {
            Http::error(404, 'NOT_FOUND', 'Return not found.');
        }
        $items = $pdo->prepare(
            'SELECT ri.id, ri.order_item_id, ri.variant_id, ri.quantity, ri.item_condition AS condition, ri.restock,
                    oi.product_id, oi.unit_price, p.name AS product_name, v.sku AS variant_sku
             FROM ' . $returnItems . ' ri
             LEFT JOIN ' . $orderItems . ' oi ON oi.id = ri.order_item_id
             LEFT JOIN ' . $products . ' p ON p.id = oi.product_id
             LEFT JOIN ' . $variants . ' v ON v.id = ri.variant_id
             WHERE ri.return_id = ? ORDER BY ri.id ASC'
        );
        $items->execute([$id]);
        Http::ok(['return' => [
            'id' => (int) $ret['id'],
            'order_id' => (int) $ret['order_id'],
            'user_id' => (int) $ret['user_id'],
            'status' => (string) $ret['status'],
            'reason' => (string) $ret['reason'],
            'refund_amount' => Money::norm($ret['refund_amount']),
            'created_at' => (string) $ret['created_at'],
            'processed_at' => $ret['processed_at'] !== null ? (string) $ret['processed_at'] : null,
            'processed_by' => $ret['processed_by'] !== null ? (int) $ret['processed_by'] : null,
            'items' => array_map(static function (array $r): array {
                return [
                    'id' => (int) $r['id'],
                    'order_item_id' => $r['order_item_id'] !== null ? (int) $r['order_item_id'] : null,
                    'variant_id' => $r['variant_id'] !== null ? (int) $r['variant_id'] : null,
                    'quantity' => (int) $r['quantity'],
                    'condition' => (string) $r['condition'],
                    'restock' => (bool) $r['restock'],
                    'product_name' => $r['product_name'] !== null ? (string) $r['product_name'] : null,
                    'variant_sku' => $r['variant_sku'] !== null ? (string) $r['variant_sku'] : null,
                ];
            }, $items->fetchAll()),
        ]]);
    }

    // ---- Customer requests a return -----------------------------------------
    if ($method === 'POST' && !isset($_GET['items'])) {
        Auth::requirePermission('returns:request');
        $body = Validate::jsonBody();
        try {
            $orderId = Validate::int($body['order_id'] ?? null, 'order_id', 1, PHP_INT_MAX);
            $reason = Validate::str($body['reason'] ?? null, 'reason', 1, 255);
        } catch (ValidationException $e) {
            Http::error(400, 'VALIDATION_FAILED', 'Please check the highlighted fields.', $e->fields);
        }
        \assert($orderId !== null && $reason !== null);

        // Order must exist, belong to caller, and be delivered.
        $order = $pdo->prepare('SELECT id, user_id, status, total, delivered_at FROM ' . $orders . ' WHERE id = ? LIMIT 1');
        $order->execute([$orderId]);
        $orderRow = $order->fetch();
        if ($orderRow === false) {
            Http::error(404, 'NOT_FOUND', 'Order not found.');
        }
        if ((int) $orderRow['user_id'] !== $user['id']) {
            Http::error(404, 'NOT_FOUND', 'Order not found.');
        }
        if ((string) $orderRow['status'] !== 'delivered') {
            Http::error(422, 'INVALID_OPERATION', 'Only delivered orders can be returned.');
        }

        // 30-day return window from delivery.
        $deliveredAt = strtotime((string) $orderRow['delivered_at']);
        if ($deliveredAt === false || $deliveredAt + 30 * 24 * 60 * 60 < time()) {
            Http::error(422, 'INVALID_OPERATION', 'Return window has expired (30 days from delivery).');
        }

        // No existing non-rejected return for this order.
        $existing = $pdo->prepare('SELECT id, status FROM ' . $returns . ' WHERE order_id = ? AND status != "rejected" LIMIT 1');
        $existing->execute([$orderId]);
        if ($existing->fetch() !== false) {
            Http::error(422, 'INVALID_OPERATION', 'A return already exists for this order.');
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO ' . $returns . ' (order_id, user_id, status, reason, created_at)
                 VALUES (?, ?, \'requested\', ?, ' . $now . ')'
            )->execute([$orderId, $user['id'], $reason]);
            $returnId = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Http::created(['id' => $returnId, 'status' => 'requested']);
    }

    // ---- Admin: approve / reject / manage -----------------------------------
    if ($method === 'PATCH' && isset($_GET['id'])) {
        if ($user['role'] !== 'admin') {
            Http::error(403, 'FORBIDDEN', 'Administrator access required.');
        }
        $id = Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX);
        $body = Validate::jsonBody();
        $status = Validate::enum($body['status'] ?? null, 'status', ['approved', 'rejected', 'shipped', 'refunded']);
        $refundAmount = Validate::decimal($body['refund_amount'] ?? null, 'refund_amount', 0, 1000000, false);
        \assert($id !== null && $status !== null);

        $pdo->beginTransaction();
        try {
            $ret = $pdo->prepare('SELECT * FROM ' . $returns . ' WHERE id = ? LIMIT 1 FOR UPDATE');
            $ret->execute([$id]);
            $returnRow = $ret->fetch();
            if ($returnRow === false) {
                $pdo->rollBack();
                Http::error(404, 'NOT_FOUND', 'Return not found.');
            }

            $sets = ['status = ?', 'processed_at = UTC_TIMESTAMP(3)', 'processed_by = ?'];
            $params = [$status, $user['id']];
            if ($refundAmount !== null) {
                $sets[] = 'refund_amount = ?';
                $params[] = $refundAmount;
            }
            $params[] = $id;
            $pdo->prepare('UPDATE ' . $returns . ' SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);

            // On refund: restock resellable items + write reversal ledger rows.
            if ($status === 'refunded') {
                $items = $pdo->prepare('SELECT * FROM ' . $returnItems . ' WHERE return_id = ?');
                $items->execute([$id]);
                foreach ($items->fetchAll() as $item) {
                    if ((bool) $item['restock'] && $item['variant_id'] !== null) {
                        $pdo->prepare('UPDATE ' . $variants . ' SET stock = stock + ?, updated_at = UTC_TIMESTAMP(3) WHERE id = ?')
                            ->execute([(int) $item['quantity'], (int) $item['variant_id']]);
                        $pdo->prepare(
                            'INSERT INTO ' . $ledger . ' (product_id, variant_id, delta, reason, reference_id, created_at)
                             SELECT p.id, ?, ?, \'checkout_reversal\', ?, UTC_TIMESTAMP(3)
                             FROM ' . $products . ' p WHERE p.id = (SELECT product_id FROM ' . $variants . ' WHERE id = ?)'
                        )->execute([(int) $item['variant_id'], (int) $item['quantity'], $id, (int) $item['variant_id']]);
                    }
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Http::ok(['id' => $id, 'status' => $status]);
    }

    // ---- Admin: add return items -------------------------------------------
    if ($method === 'POST' && isset($_GET['items'], $_GET['id'])) {
        if ($user['role'] !== 'admin') {
            Http::error(403, 'FORBIDDEN', 'Administrator access required.');
        }
        $returnId = Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX);
        $body = Validate::jsonBody();
        $items = $body['items'] ?? null;
        if (!is_array($items) || $items === []) {
            Http::error(400, 'VALIDATION_FAILED', '"items" must be a non-empty array.', ['items' => 'required']);
        }
        \assert($returnId !== null);

        $pdo->beginTransaction();
        try {
            $ret = $pdo->prepare('SELECT id FROM ' . $returns . ' WHERE id = ? LIMIT 1');
            $ret->execute([$returnId]);
            if ($ret->fetch() === false) {
                $pdo->rollBack();
                Http::error(404, 'NOT_FOUND', 'Return not found.');
            }

            $insert = $pdo->prepare(
                'INSERT INTO ' . $returnItems . ' (return_id, order_item_id, variant_id, quantity, item_condition, restock, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ' . $now . ')'
            );
            foreach ($items as $idx => $it) {
                $orderItemId = Validate::int($it['order_item_id'] ?? null, 'items[' . $idx . '].order_item_id', 1, PHP_INT_MAX, false);
                $variantId = Validate::int($it['variant_id'] ?? null, 'items[' . $idx . '].variant_id', 1, PHP_INT_MAX, false);
                $quantity = Validate::int($it['quantity'] ?? null, 'items[' . $idx . '].quantity', 1, 10000);
                $condition = Validate::enum($it['condition'] ?? 'resellable', 'items[' . $idx . '].condition', ['resellable', 'damaged', 'defective']);
                $restock = Validate::bool($it['restock'] ?? false, 'items[' . $idx . '].restock', false) ?? false;
                $insert->execute([$returnId, $orderItemId, $variantId, $quantity, $condition, $restock]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Http::created(['return_id' => $returnId]);
    }

    Http::error(405, 'METHOD_NOT_ALLOWED', 'Unsupported method for returns.');
} catch (Throwable $e) {
    Http::fail($e);
}
