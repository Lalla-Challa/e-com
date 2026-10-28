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
 * Purchase orders (stock replenishment from suppliers).
 *
 * GET  /api/purchase-orders.php              — products:manage_inventory: list POs
 * GET  /api/purchase-orders.php?id=N         — products:manage_inventory: single PO + items
 * POST /api/purchase-orders.php              — products:manage_inventory: create PO + items
 * PATCH /api/purchase-orders.php?id=N        — products:manage_inventory: update PO
 * POST /api/purchase-orders.php?id=N/receive — products:manage_inventory: receive items (increments stock + ledger)
 */
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $user = Auth::currentUser();
    $pdo = Db::pdo();
    $pos = Db::table('purchase_orders');
    $poItems = Db::table('purchase_order_items');
    $variants = Db::table('product_variants');
    $products = Db::table('products');
    $ledger = Db::table('stock_ledger');
    $defaultLocation = Db::table('inventory_locations');
    $now = 'UTC_TIMESTAMP(3)';

    if (!isset($user) || $user === null) {
        Auth::requireUser();
    }
    Auth::requirePermission('products:manage_inventory');

    // ---- List POs -----------------------------------------------------------
    if ($method === 'GET' && !isset($_GET['id'])) {
        $page = Validate::int($_GET['page'] ?? 1, 'page', 1, 100000) ?? 1;
        $perPage = Validate::int($_GET['per_page'] ?? 20, 'per_page', 1, 100) ?? 20;
        $status = isset($_GET['status']) ? Validate::enum($_GET['status'], 'status', ['draft', 'ordered', 'received', 'cancelled']) : null;

        $where = '1=1';
        $params = [];
        if ($status !== null) {
            $where .= ' AND status = ?';
            $params[] = $status;
        }

        $count = $pdo->prepare('SELECT COUNT(*) FROM ' . $pos . ' WHERE ' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $rows = $pdo->prepare(
            'SELECT id, supplier_name, reference, status, expected_at, received_at, total_cost, notes, created_at, updated_at
             FROM ' . $pos . ' WHERE ' . $where . '
             ORDER BY created_at DESC
             LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
        );
        $rows->execute($params);
        Http::ok(['purchase_orders' => array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'supplier_name' => (string) $r['supplier_name'],
                'reference' => $r['reference'] !== null ? (string) $r['reference'] : null,
                'status' => (string) $r['status'],
                'expected_at' => $r['expected_at'] !== null ? (string) $r['expected_at'] : null,
                'received_at' => $r['received_at'] !== null ? (string) $r['received_at'] : null,
                'total_cost' => Money::norm($r['total_cost']),
                'notes' => $r['notes'] !== null ? (string) $r['notes'] : null,
                'created_at' => (string) $r['created_at'],
                'updated_at' => (string) $r['updated_at'],
            ];
        }, $rows->fetchAll())], ['page' => $page, 'per_page' => $perPage, 'total' => $total]);
    }

    // ---- Single PO with items ----------------------------------------------
    if ($method === 'GET' && isset($_GET['id'])) {
        $id = Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX);
        \assert($id !== null);
        $row = $pdo->prepare('SELECT * FROM ' . $pos . ' WHERE id = ? LIMIT 1');
        $row->execute([$id]);
        $po = $row->fetch();
        if ($po === false) {
            Http::error(404, 'NOT_FOUND', 'Purchase order not found.');
        }
        $items = $pdo->prepare(
            'SELECT id, variant_id, quantity_ordered, quantity_received, unit_cost
             FROM ' . $poItems . ' WHERE purchase_order_id = ? ORDER BY id ASC'
        );
        $items->execute([$id]);
        Http::ok(['purchase_order' => [
            'id' => (int) $po['id'],
            'supplier_name' => (string) $po['supplier_name'],
            'reference' => $po['reference'] !== null ? (string) $po['reference'] : null,
            'status' => (string) $po['status'],
            'expected_at' => $po['expected_at'] !== null ? (string) $po['expected_at'] : null,
            'received_at' => $po['received_at'] !== null ? (string) $po['received_at'] : null,
            'total_cost' => Money::norm($po['total_cost']),
            'notes' => $po['notes'] !== null ? (string) $po['notes'] : null,
            'created_at' => (string) $po['created_at'],
            'updated_at' => (string) $po['updated_at'],
            'items' => array_map(static function (array $r): array {
                return [
                    'id' => (int) $r['id'],
                    'variant_id' => (int) $r['variant_id'],
                    'quantity_ordered' => (int) $r['quantity_ordered'],
                    'quantity_received' => (int) $r['quantity_received'],
                    'unit_cost' => Money::norm($r['unit_cost']),
                ];
            }, $items->fetchAll()),
        ]]);
    }

    // ---- Create PO -----------------------------------------------------------
    if ($method === 'POST' && !isset($_GET['receive'])) {
        $body = Validate::jsonBody();
        try {
            $supplierName = Validate::str($body['supplier_name'] ?? null, 'supplier_name', 1, 200);
            $reference = Validate::str($body['reference'] ?? null, 'reference', 0, 100, false);
            $expectedAt = Validate::str($body['expected_at'] ?? null, 'expected_at', 0, 50, false);
            $notes = Validate::str($body['notes'] ?? null, 'notes', 0, 1000, false);
            $items = $body['items'] ?? null;
            if (!is_array($items) || $items === []) {
                Http::error(400, 'VALIDATION_FAILED', '"items" must be a non-empty array.', ['items' => 'required']);
            }
        } catch (ValidationException $e) {
            Http::error(400, 'VALIDATION_FAILED', 'Please check the highlighted fields.', $e->fields);
        }
        \assert($supplierName !== null);

        $totalCost = '0.00';
        $validatedItems = [];
        foreach ($items as $idx => $item) {
            $variantId = Validate::int($item['variant_id'] ?? null, 'items[' . $idx . '].variant_id', 1, PHP_INT_MAX);
            $qtyOrdered = Validate::int($item['quantity_ordered'] ?? null, 'items[' . $idx . '].quantity_ordered', 1, 100000);
            $unitCost = Validate::decimal($item['unit_cost'] ?? null, 'items[' . $idx . '].unit_cost', 0, 1000000);
            \assert($variantId !== null && $qtyOrdered !== null && $unitCost !== null);

            // Verify variant exists.
            $v = $pdo->prepare('SELECT id FROM ' . $variants . ' WHERE id = ? LIMIT 1');
            $v->execute([$variantId]);
            if ($v->fetch() === false) {
                Http::error(404, 'NOT_FOUND', 'Variant not found: ' . $variantId);
            }

            $lineCost = Money::mul($unitCost, (string) $qtyOrdered);
            $totalCost = Money::add($totalCost, $lineCost);
            $validatedItems[] = ['variant_id' => $variantId, 'quantity_ordered' => $qtyOrdered, 'unit_cost' => $unitCost];
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO ' . $pos . ' (supplier_name, reference, status, expected_at, total_cost, notes, created_at, updated_at)
                 VALUES (?, ?, \'draft\', ?, ?, ?, ' . $now . ', ' . $now . ')'
            )->execute([$supplierName, $reference, $expectedAt, $totalCost, $notes]);
            $poId = (int) $pdo->lastInsertId();

            $insertItem = $pdo->prepare(
                'INSERT INTO ' . $poItems . ' (purchase_order_id, variant_id, quantity_ordered, quantity_received, unit_cost)
                 VALUES (?, ?, ?, 0, ?)'
            );
            foreach ($validatedItems as $it) {
                $insertItem->execute([$poId, $it['variant_id'], $it['quantity_ordered'], $it['unit_cost']]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Http::created(['id' => $poId, 'total_cost' => $totalCost]);
    }

    // ---- Update PO -----------------------------------------------------------
    if ($method === 'PATCH' && isset($_GET['id'])) {
        $id = Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX);
        $body = Validate::jsonBody();
        $supplierName = Validate::str($body['supplier_name'] ?? null, 'supplier_name', 1, 200, false);
        $reference = Validate::str($body['reference'] ?? null, 'reference', 0, 100, false);
        $expectedAt = Validate::str($body['expected_at'] ?? null, 'expected_at', 0, 50, false);
        $status = Validate::enum($body['status'] ?? null, 'status', ['draft', 'ordered', 'received', 'cancelled'], false);
        $notes = Validate::str($body['notes'] ?? null, 'notes', 0, 1000, false);
        \assert($id !== null);

        $sets = [];
        $params = [];
        if ($supplierName !== null) { $sets[] = 'supplier_name = ?'; $params[] = $supplierName; }
        if ($reference !== null) { $sets[] = 'reference = ?'; $params[] = $reference; }
        if ($expectedAt !== null) { $sets[] = 'expected_at = ?'; $params[] = $expectedAt; }
        if ($status !== null) { $sets[] = 'status = ?'; $params[] = $status; }
        if ($notes !== null) { $sets[] = 'notes = ?'; $params[] = $notes; }
        $sets[] = 'updated_at = UTC_TIMESTAMP(3)';
        $params[] = $id;
        if (count($sets) > 1) {
            $pdo->prepare('UPDATE ' . $pos . ' SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        }
        Http::ok(['id' => $id]);
    }

    // ---- Receive items (increment stock + ledger) ----------------------------
    if ($method === 'POST' && isset($_GET['receive'], $_GET['id'])) {
        $id = Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX);
        \assert($id !== null);

        $pdo->beginTransaction();
        try {
            $po = $pdo->prepare('SELECT * FROM ' . $pos . ' WHERE id = ? LIMIT 1 FOR UPDATE');
            $po->execute([$id]);
            $poRow = $po->fetch();
            if ($poRow === false) {
                $pdo->rollBack();
                Http::error(404, 'NOT_FOUND', 'Purchase order not found.');
            }
            if ((string) $poRow['status'] === 'received') {
                $pdo->rollBack();
                Http::error(422, 'INVALID_OPERATION', 'Purchase order already received.');
            }

            // Resolve default location.
            $loc = $pdo->prepare('SELECT id FROM ' . $defaultLocation . ' WHERE is_default = TRUE AND is_active = TRUE LIMIT 1');
            $loc->execute();
            $locationId = $loc->fetchColumn();

            $items = $pdo->prepare(
                'SELECT id, variant_id, quantity_ordered, quantity_received FROM ' . $poItems . ' WHERE purchase_order_id = ?'
            );
            $items->execute([$id]);

            $totalReceived = 0;
            foreach ($items->fetchAll() as $item) {
                $remaining = (int) $item['quantity_ordered'] - (int) $item['quantity_received'];
                if ($remaining <= 0) {
                    continue;
                }
                $receiveQty = $remaining; // receive all remaining in one go

                $pdo->prepare('UPDATE ' . $variants . ' SET stock = stock + ?, updated_at = UTC_TIMESTAMP(3) WHERE id = ?')
                    ->execute([$receiveQty, (int) $item['variant_id']]);
                $pdo->prepare('UPDATE ' . $poItems . ' SET quantity_received = quantity_received + ? WHERE id = ?')
                    ->execute([$receiveQty, (int) $item['id']]);
                $pdo->prepare(
                    'INSERT INTO ' . $ledger . ' (product_id, variant_id, location_id, delta, reason, reference_id, created_at)
                     SELECT p.id, ?, ?, ?, \'purchase\', ?, UTC_TIMESTAMP(3)
                     FROM ' . $products . ' p WHERE p.id = (SELECT product_id FROM ' . $variants . ' WHERE id = ?)'
                )->execute([(int) $item['variant_id'], $locationId, $receiveQty, $id, (int) $item['variant_id']]);

                $totalReceived += $receiveQty;
            }

            $newStatus = 'received';
            $pdo->prepare('UPDATE ' . $pos . ' SET status = ?, received_at = UTC_TIMESTAMP(3), updated_at = UTC_TIMESTAMP(3) WHERE id = ?')
                ->execute([$newStatus, $id]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Http::ok(['id' => $id, 'status' => 'received', 'units_received' => $totalReceived]);
    }

    Http::error(405, 'METHOD_NOT_ALLOWED', 'Unsupported method for purchase orders.');
} catch (Throwable $e) {
    Http::fail($e);
}
