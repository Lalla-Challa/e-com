<?php

declare(strict_types=1);

use AppCore\Auth;
use AppCore\Db;
use AppCore\Http;
use AppCore\Mailer;
use AppCore\Money;
use AppCore\Validate;
use AppCore\ValidationException;

require_once __DIR__ . '/_bootstrap.php';

/**
 * GET  /api/orders.php            — caller's order history (admin sees all; ?status= filter)
 * GET  /api/orders.php?id=        — single order with items + notes (owner or admin)
 * POST /api/orders.php {order_id, body, visibility?} — add a note (admin, or the owner)
 * PATCH /api/orders.php {id, status, note?}           — admin status transitions
 * PATCH /api/orders.php {id, action=fulfill, tracking_number, carrier, method} — admin fulfillment
 *
 * Status changes follow an explicit state machine rather than a blind write,
 * so an order cannot jump from pending straight to refunded. Cancelling an
 * order that was already paid puts the units back on the shelf and writes a
 * checkout_reversal ledger row, which is the inverse of what checkout did.
 */
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $user = Auth::requireUser();
    $pdo = Db::pdo();
    $orders = Db::table('orders');
    $orderItems = Db::table('order_items');
    $products = Db::table('products');
    $notes = Db::table('order_notes');
    $ledger = Db::table('stock_ledger');
    $users = Db::table('users');
    $fulfillments = Db::table('fulfillments');

    /** Allowed transitions. Same-status is treated as an idempotent no-op. */
    $transitions = [
        'pending' => ['paid', 'cancelled'],
        'paid' => ['shipped', 'cancelled', 'refunded'],
        'shipped' => ['delivered', 'cancelled', 'refunded'],
        'delivered' => ['refunded', 'returning'],
        'fulfilled' => ['cancelled', 'refunded', 'returning'],
        'cancelled' => [],
        'refunded' => [],
        'returning' => ['returned'],
        'returned' => [],
    ];
    $statuses = array_keys($transitions);

    $addNote = static function (int $orderId, ?int $authorId, ?string $authorName, string $visibility, string $body) use ($pdo, $notes): void {
        $pdo->prepare(
            'INSERT INTO ' . $notes . ' (order_id, author_user_id, author_name, visibility, body, created_at)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(3))'
        )->execute([$orderId, $authorId, $authorName, $visibility, $body]);
    };

    $orderPayload = static function (array $row) use ($pdo, $orderItems, $products, $notes, $user, $transitions): array {
        $itemsStmt = $pdo->prepare(
            'SELECT oi.product_id, oi.quantity, oi.unit_price, p.slug, p.name, p.image_url, p.metadata
             FROM ' . $orderItems . ' oi
             JOIN ' . $products . ' p ON p.id = oi.product_id
             WHERE oi.order_id = ? ORDER BY oi.id ASC'
        );
        $itemsStmt->execute([(int) $row['id']]);

        // A customer sees only customer-visible notes; an admin sees both.
        $noteSql = $user['role'] === 'admin'
            ? 'SELECT * FROM ' . $notes . ' WHERE order_id = ? ORDER BY id ASC'
            : 'SELECT * FROM ' . $notes . ' WHERE order_id = ? AND visibility = \'customer\' ORDER BY id ASC';
        $noteStmt = $pdo->prepare($noteSql);
        $noteStmt->execute([(int) $row['id']]);

        // Each line carries its selling unit so the record can say "3 m cut"
        // for cloth and "2 × spool" for a notion, instead of calling every
        // line a cut regardless of what was actually sold.
        $itemRows = [];
        foreach ($itemsStmt->fetchAll() as $r) {
            $meta = $r['metadata'] !== null ? json_decode((string) $r['metadata'], true) : null;
            $meta = \is_array($meta) ? $meta : [];
            $unit = isset($meta['unit']) && \is_string($meta['unit']) ? $meta['unit'] : null;
            $unitLabel = isset($meta['unit_label']) && \is_string($meta['unit_label']) ? $meta['unit_label'] : null;
            $itemRows[] = [
                'product_id' => (int) $r['product_id'],
                'slug' => (string) $r['slug'],
                'name' => (string) $r['name'],
                'image_url' => $r['image_url'] !== null ? (string) $r['image_url'] : null,
                'quantity' => (int) $r['quantity'],
                'unit' => $unit,
                'unit_label' => $unitLabel,
                'unit_price' => Money::norm($r['unit_price']),
                'line_total' => Money::mul(Money::norm($r['unit_price']), (string) (int) $r['quantity']),
            ];
        }

        return [
            'id' => (int) $row['id'],
            'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'status' => (string) $row['status'],
            'subtotal' => Money::norm($row['subtotal']),
            'discount' => Money::norm($row['discount'] ?? 0),
            'shipping' => Money::norm($row['shipping']),
            'tax' => Money::norm($row['tax']),
            'total' => Money::norm($row['total']),
            'currency' => (string) $row['currency'],
            'item_count' => (int) ($row['item_count'] ?? 0),
            'customer_email' => $row['customer_email'] ?? null,
            'coupon_code' => $row['coupon_code'] ?? null,
            'tax_rate' => $row['tax_rate'] !== null ? (string) $row['tax_rate'] : null,
            'tax_name' => $row['tax_name'] ?? null,
            'shipping_method_code' => $row['shipping_method_code'] ?? null,
            'shipping_method_name' => $row['shipping_method_name'] ?? null,
            'cancelled_at' => $row['cancelled_at'] ?? null,
            'refunded_at' => $row['refunded_at'] ?? null,
            'refunded_amount' => Money::norm($row['refunded_amount'] ?? 0),
            'shipping_address' => json_decode((string) ($row['shipping_address'] ?? 'null'), true),
            'billing_address' => json_decode((string) ($row['billing_address'] ?? 'null'), true),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) ($row['updated_at'] ?? $row['created_at']),
            'allowed_next' => $transitions[(string) $row['status']] ?? [],
            'items' => $itemRows,
            'notes' => array_map(static fn (array $n): array => [
                'id' => (int) $n['id'],
                'author' => $n['author_name'] !== null ? (string) $n['author_name'] : 'System',
                'visibility' => (string) $n['visibility'],
                'body' => (string) $n['body'],
                'created_at' => (string) $n['created_at'],
            ], $noteStmt->fetchAll()),
        ];
    };

    // ---- Add a note --------------------------------------------------------
    if ($method === 'POST') {
        $body = Validate::jsonBody();
        try {
            $orderId = Validate::int($body['order_id'] ?? null, 'order_id', 1, PHP_INT_MAX);
            $noteBody = Validate::str($body['body'] ?? null, 'body', 1, 2000);
            $visibility = Validate::enum($body['visibility'] ?? 'internal', 'visibility', ['internal', 'customer'], false) ?? 'internal';
        } catch (ValidationException $e) {
            Http::error(400, 'VALIDATION_FAILED', 'Please check the highlighted fields.', $e->fields);
        }
        \assert($orderId !== null && $noteBody !== null && $visibility !== null);

        $stmt = $pdo->prepare('SELECT id, user_id FROM ' . $orders . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if ($order === false) {
            Http::error(404, 'NOT_FOUND', 'Order not found.');
        }

        $isAdmin = $user['role'] === 'admin';
        if (!$isAdmin && (int) $order['user_id'] !== $user['id']) {
            Http::error(404, 'NOT_FOUND', 'Order not found.');
        }
        // Only staff may write internal notes; a customer's message is always
        // customer-visible so it can never be mistaken for staff commentary.
        if (!$isAdmin && $visibility === 'internal') {
            $visibility = 'customer';
        }

        $addNote($orderId, $user['id'], $user['full_name'], $visibility, $noteBody);
        Http::created(['note' => ['order_id' => $orderId, 'visibility' => $visibility]]);
    }

    // ---- Status transition / fulfillment -----------------------------------
    if ($method === 'PATCH') {
        if ($user['role'] !== 'admin') {
            Http::error(403, 'FORBIDDEN', 'Administrator access required.');
        }
        $body = Validate::jsonBody();
        try {
            $id = Validate::int($body['id'] ?? null, 'id', 1, PHP_INT_MAX);
        } catch (ValidationException $e) {
            Http::error(400, 'VALIDATION_FAILED', 'Invalid request.', $e->fields);
        }
        \assert($id !== null);

        $action = $body['action'] ?? null;

        // ---- Fulfillment ----------------------------------------------------
        if ($action === 'fulfill') {
            $trackingNumber = Validate::str($body['tracking_number'] ?? null, 'tracking_number', 0, 120, false);
            $carrier = Validate::str($body['carrier'] ?? null, 'carrier', 0, 100, false);
            $methodName = Validate::str($body['method'] ?? null, 'method', 0, 100, false);

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('SELECT * FROM ' . $orders . ' WHERE id = ? LIMIT 1 FOR UPDATE');
                $stmt->execute([$id]);
                $order = $stmt->fetch();
                if ($order === false) {
                    $pdo->rollBack();
                    Http::error(404, 'NOT_FOUND', 'Order not found.');
                }
                \assert($order !== false);

                $current = (string) $order['status'];
                if (!\in_array($current, ['paid', 'shipped', 'delivered'], true)) {
                    $pdo->rollBack();
                    Http::error(422, 'INVALID_TRANSITION', "Cannot fulfill an order in \"{$current}\" status.", [
                        'status' => 'order must be paid, shipped, or delivered',
                    ]);
                }

                $pdo->prepare(
                    'INSERT INTO ' . $fulfillments . ' (order_id, tracking_number, carrier, method, status, created_at, updated_at)
                     VALUES (?, ?, ?, ?, \'shipped\', ' . 'UTC_TIMESTAMP(3)' . ', ' . 'UTC_TIMESTAMP(3)' . ')'
                )->execute([$id, $trackingNumber, $carrier, $methodName]);

                $newStatus = 'fulfilled';
                $sets = ['status = ?', 'fulfilled_at = UTC_TIMESTAMP(3)', 'updated_at = UTC_TIMESTAMP(3)'];
                $params = [$newStatus, $id];
                $pdo->prepare('UPDATE ' . $orders . ' SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);

                $addNote($id, $user['id'], $user['full_name'], 'customer', 'Order fulfilled and shipped.' . ($trackingNumber !== '' ? ' Tracking: ' . $trackingNumber : ''));
                if ($methodName !== null && $methodName !== '') {
                    $addNote($id, $user['id'], $user['full_name'], 'internal', 'Carrier: ' . $carrier . ', Method: ' . $methodName);
                }

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            Http::ok(['order' => ['id' => $id, 'status' => 'fulfilled', 'changed' => true, 'action' => 'fulfilled']]);
        }

        // ---- Status transition ----------------------------------------------
        try {
            $status = Validate::enum($body['status'] ?? null, 'status', $statuses);
            $note = Validate::str($body['note'] ?? null, 'note', 1, 2000, false);
        } catch (ValidationException $e) {
            Http::error(400, 'VALIDATION_FAILED', 'Invalid status update.', $e->fields);
        }
        \assert($status !== null);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM ' . $orders . ' WHERE id = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([$id]);
            $order = $stmt->fetch();
            if ($order === false) {
                $pdo->rollBack();
                Http::error(404, 'NOT_FOUND', 'Order not found.');
            }
            \assert($order !== false);

            $current = (string) $order['status'];
            if ($current === $status) {
                $pdo->commit();
                Http::ok(['order' => ['id' => $id, 'status' => $current, 'changed' => false]]);
            }
            if (!\in_array($status, $transitions[$current] ?? [], true)) {
                $pdo->rollBack();
                Http::error(422, 'INVALID_TRANSITION', "An order cannot move from \"{$current}\" to \"{$status}\".", [
                    'status' => 'allowed from ' . $current . ': ' . implode(', ', $transitions[$current] ?? []) . (empty($transitions[$current]) ? 'nothing (terminal)' : ''),
                ]);
            }

            $sets = ['status = ?', 'updated_at = UTC_TIMESTAMP(3)'];
            $params = [$status];
            if ($status === 'cancelled') {
                $sets[] = 'cancelled_at = UTC_TIMESTAMP(3)';
            }
            if ($status === 'refunded') {
                $sets[] = 'refunded_at = UTC_TIMESTAMP(3)';
                $sets[] = 'refunded_amount = ?';
                $params[] = Money::norm($order['total']);
            }

            $update = $pdo->prepare('UPDATE ' . $orders . ' SET ' . implode(', ', $sets) . ' WHERE id = ?');
            $params[] = $id;
            $update->execute($params);

            // Cancelling puts the units back. Restock only happens if the order
            // had actually taken stock — a cancelled pending order never did.
            if ($status === 'cancelled' && \in_array($current, ['paid', 'shipped', 'delivered', 'fulfilled'], true)) {
                $itemStmt = $pdo->prepare('SELECT oi.product_id, oi.quantity, oi.variant_id FROM ' . $orderItems . ' WHERE order_id = ?');
                $itemStmt->execute([$id]);
                foreach ($itemStmt->fetchAll() as $line) {
                    $targetTable = $line['variant_id'] !== null ? $products : $products;
                    $pdo->prepare('UPDATE ' . $products . ' SET stock = stock + ?, updated_at = UTC_TIMESTAMP(3) WHERE id = ?')
                        ->execute([(int) $line['quantity'], (int) $line['product_id']]);
                    $pdo->prepare(
                        'INSERT INTO ' . $ledger . ' (product_id, variant_id, delta, reason, reference_id, created_at) VALUES (?, ?, ?, \'checkout_reversal\', ?, UTC_TIMESTAMP(3))'
                    )->execute([(int) $line['product_id'], $line['variant_id'] !== null ? (int) $line['variant_id'] : null, (int) $line['quantity'], $id]);
                }
            }

            if ($status === 'returning') {
                // Returned state is reached via the returns API; no extra
                // columns needed on orders beyond returned_at (added in 023).
            }

            $addNote($id, $user['id'], $user['full_name'], 'customer', 'Status changed from ' . $current . ' to ' . $status . '.');
            if ($note !== null) {
                $addNote($id, $user['id'], $user['full_name'], 'internal', $note);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $email = $order['customer_email'] ?? null;
        if ($email === null && $order['user_id'] !== null) {
            $userStmt = $pdo->prepare('SELECT email FROM ' . $users . ' WHERE id = ? LIMIT 1');
            $userStmt->execute([(int) $order['user_id']]);
            $email = $userStmt->fetchColumn() ?: null;
        }
        if ($email !== null && $email !== '') {
            Mailer::orderStatusChanged([
                'id' => $id, 'status' => $status, 'total' => (string) $order['total'], 'currency' => (string) $order['currency'],
            ], $email, $current, $status);
        }

        Http::ok(['order' => ['id' => $id, 'status' => $status, 'changed' => true]]);
    }

    if ($method !== 'GET') {
        Http::error(405, 'METHOD_NOT_ALLOWED', 'Unsupported method for orders.');
    }

    // ---- Single order ------------------------------------------------------
    $id = isset($_GET['id']) ? Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX) : null;
    if ($id !== null) {
        $stmt = $pdo->prepare('SELECT * FROM ' . $orders . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false || ($user['role'] !== 'admin' && ($row['user_id'] === null || (int) $row['user_id'] !== $user['id']))) {
            Http::error(404, 'NOT_FOUND', 'Order not found.');
        }
        \assert($row !== false);

        Http::ok(['order' => $orderPayload($row)]);
    }

    // ---- Listing -----------------------------------------------------------
    $page = Validate::int($_GET['page'] ?? 1, 'page', 1, 100000) ?? 1;
    $perPage = Validate::int($_GET['per_page'] ?? 20, 'per_page', 1, 100) ?? 20;
    $status = isset($_GET['status']) ? Validate::enum($_GET['status'], 'status', $statuses) : null;

    $where = $user['role'] === 'admin' ? '1=1' : 'user_id = ?';
    $params = $user['role'] === 'admin' ? [] : [$user['id']];
    if ($status !== null) {
        $where .= ' AND status = ?';
        $params[] = $status;
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $orders . ' WHERE ' . $where);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $listStmt = $pdo->prepare(
        'SELECT id, user_id, status, subtotal, discount, shipping, tax, total, currency, item_count, customer_email, coupon_code, created_at
         FROM ' . $orders . '
         WHERE ' . $where . '
         ORDER BY created_at DESC
         LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
    );
    $listStmt->execute($params);

    $items = array_map(static fn (array $row): array => [
        'id' => (int) $row['id'],
        'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
        'status' => (string) $row['status'],
        'subtotal' => Money::norm($row['subtotal']),
        'discount' => Money::norm($row['discount'] ?? 0),
        'shipping' => Money::norm($row['shipping']),
        'tax' => Money::norm($row['tax']),
        'total' => Money::norm($row['total']),
        'currency' => (string) $row['currency'],
        'item_count' => (int) ($row['item_count'] ?? 0),
        'customer_email' => $row['customer_email'] ?? null,
        'coupon_code' => $row['coupon_code'] ?? null,
        'created_at' => (string) $row['created_at'],
    ], $listStmt->fetchAll());

    Http::ok(['orders' => $items, 'statuses' => $statuses], ['page' => $page, 'per_page' => $perPage, 'total' => $total]);
} catch (Throwable $e) {
    Http::fail($e);
}
