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
 * Admin inventory control.
 *
 * GET  ?product_id=N[&variant_id=M]         — stock summary + paginated ledger
 * GET  ?report=low_stock                    — active products at/below threshold
 * GET  ?report=valuation                    — on-hand stock valued at unit cost
 * GET  ?report=movements                    — paginated stock ledger
 * GET  ?report=reservations                 — active stock reservations
 * GET  ?report=reorder                      — products/variants needing restock
 * POST {product_id, variant_id?, delta, reason, note?, location_id?}
 *                                            — adjust stock + ledger row
 *
 * Stock and its ledger entry are written in one transaction with the row
 * locked, so concurrent adjustments cannot lose writes.
 */
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!isset($_GET['report']) || $_GET['report'] === '') {
        Auth::requirePermission('products:manage_inventory');
    } else {
        Auth::requirePermission('products:manage_inventory');
    }
    $pdo = Db::pdo();
    $products = Db::table('products');
    $variants = Db::table('product_variants');
    $ledger = Db::table('stock_ledger');
    $locations = Db::table('inventory_locations');
    $reservations = Db::table('stock_reservations');

    // ---- POST: stock adjustment -------------------------------------------
    if ($method === 'POST') {
        $body = Validate::jsonBody();
        try {
            $productId = Validate::int($body['product_id'] ?? null, 'product_id', 1, PHP_INT_MAX);
            $variantId = Validate::int($body['variant_id'] ?? null, 'variant_id', 1, PHP_INT_MAX, false);
            $delta = Validate::int($body['delta'] ?? null, 'delta', -1000000, 1000000);
            $reason = Validate::enum($body['reason'] ?? null, 'reason', ['restock', 'manual_adjustment', 'purchase']);
            $note = Validate::str($body['note'] ?? null, 'note', 0, 500, false);
            $locationId = Validate::int($body['location_id'] ?? null, 'location_id', 1, PHP_INT_MAX, false);
        } catch (ValidationException $e) {
            Http::error(400, 'VALIDATION_FAILED', 'Please check the highlighted fields.', $e->fields);
        }
        \assert($productId !== null && $delta !== null && $reason !== null);

        if ($delta === 0) {
            Http::error(400, 'VALIDATION_FAILED', 'Adjustment must change the quantity.', ['delta' => 'must not be zero']);
        }

        // Variant adjustments lock the variant row; product adjustments lock the product row.
        if ($variantId !== null) {
            $lock = $pdo->prepare('SELECT id, product_id, stock FROM ' . $variants . ' WHERE id = ? LIMIT 1 FOR UPDATE');
            $lock->execute([$variantId]);
            $row = $lock->fetch();
            if ($row === false) {
                Http::error(404, 'NOT_FOUND', 'Variant not found.');
            }
            $targetId = (int) $row['id'];
            $targetType = 'variant';
            $currentStock = (int) $row['stock'];
            $targetProductId = (int) $row['product_id'];
        } else {
            $lock = $pdo->prepare('SELECT id, stock FROM ' . $products . ' WHERE id = ? LIMIT 1 FOR UPDATE');
            $lock->execute([$productId]);
            $row = $lock->fetch();
            if ($row === false) {
                Http::error(404, 'NOT_FOUND', 'Product not found.');
            }
            $targetId = $productId;
            $targetType = 'product';
            $currentStock = (int) $row['stock'];
            $targetProductId = $productId;
        }

        $newStock = $currentStock + $delta;
        if ($newStock < 0) {
            Http::error(422, 'STOCK_ADJUSTMENT_INVALID', 'Adjustment would take stock below zero.', [
                'delta' => 'only ' . $currentStock . ' units on hand',
            ]);
        }

        $pdo->beginTransaction();
        try {
            if ($variantId !== null) {
                $pdo->prepare('UPDATE ' . $variants . ' SET stock = ?, updated_at = UTC_TIMESTAMP(3) WHERE id = ?')
                    ->execute([$newStock, $variantId]);
            } else {
                $pdo->prepare('UPDATE ' . $products . ' SET stock = ?, updated_at = UTC_TIMESTAMP(3) WHERE id = ?')
                    ->execute([$newStock, $productId]);
            }

            $pdo->prepare(
                'INSERT INTO ' . $ledger . ' (product_id, variant_id, location_id, delta, reason, reference_id, note, created_at)
                 VALUES (?, ?, ?, ?, ?, NULL, ?, UTC_TIMESTAMP(3))'
            )->execute([$targetProductId, $variantId, $locationId, $delta, $reason, $note]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Http::ok([
            'target_type' => $targetType,
            'target_id' => $targetId,
            'stock' => $newStock,
            'delta' => $delta,
        ]);
    }

    if ($method !== 'GET') {
        Http::error(405, 'METHOD_NOT_ALLOWED', 'Unsupported method for inventory.');
    }

    $report = isset($_GET['report']) ? Validate::enum($_GET['report'], 'report', ['low_stock', 'valuation', 'movements', 'reservations', 'reorder']) : null;

    // ---- Report: low_stock -------------------------------------------------
    if ($report === 'low_stock') {
        $stmt = $pdo->query(
            'SELECT p.id, p.slug, p.sku, p.name, p.stock, p.low_stock_threshold, p.is_active
             FROM ' . $products . ' p
             WHERE p.is_active = TRUE AND p.stock <= p.low_stock_threshold
             ORDER BY (CAST(p.stock AS SIGNED) - CAST(p.low_stock_threshold AS SIGNED)) ASC, p.stock ASC
             LIMIT 200'
        );
        Http::ok(['products' => array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'slug' => (string) $r['slug'],
            'sku' => (string) $r['sku'],
            'name' => (string) $r['name'],
            'stock' => (int) $r['stock'],
            'low_stock_threshold' => (int) $r['low_stock_threshold'],
            'is_active' => (bool) $r['is_active'],
            'low_stock' => true,
        ], $stmt->fetchAll())]);
    }

    // ---- Report: valuation -------------------------------------------------
    if ($report === 'valuation') {
        $stmt = $pdo->query(
            'SELECT p.id, p.sku, p.name, p.stock, p.cost
             FROM ' . $products . ' p
             WHERE p.is_active = TRUE
             ORDER BY p.name ASC'
        );
        $rows = [];
        $totalValue = Money::ZERO;
        $unpriced = 0;
        foreach ($stmt->fetchAll() as $r) {
            $stock = (int) $r['stock'];
            $cost = $r['cost'] !== null ? Money::norm($r['cost']) : null;
            if ($cost === null) {
                $unpriced += $stock;
                $value = null;
            } else {
                $value = Money::mul($cost, (string) $stock);
                $totalValue = Money::add($totalValue, $value);
            }
            $rows[] = [
                'id' => (int) $r['id'],
                'sku' => (string) $r['sku'],
                'name' => (string) $r['name'],
                'stock' => $stock,
                'cost' => $cost,
                'value' => $value,
            ];
        }
        Http::ok([
            'products' => $rows,
            'summary' => [
                'total_value' => $totalValue,
                'currency' => 'USD',
                'units_unpriced' => $unpriced,
                'product_count' => count($rows),
            ],
        ]);
    }

    // ---- Report: movements -------------------------------------------------
    if ($report === 'movements') {
        $page = Validate::int($_GET['page'] ?? 1, 'page', 1, 100000) ?? 1;
        $perPage = Validate::int($_GET['per_page'] ?? 50, 'per_page', 1, 200) ?? 50;
        $count = $pdo->query('SELECT COUNT(*) FROM ' . $ledger);
        $total = (int) $count->fetchColumn();
        $stmt = $pdo->query(
            'SELECT l.id, l.product_id, l.variant_id, l.location_id, l.delta, l.reason, l.reference_id, l.note, l.created_at,
                    p.name AS product_name, p.sku AS product_sku, v.sku AS variant_sku, lc.name AS location_name
             FROM ' . $ledger . ' l
             JOIN ' . $products . ' p ON p.id = l.product_id
             LEFT JOIN ' . $variants . ' v ON v.id = l.variant_id
             LEFT JOIN ' . $locations . ' lc ON lc.id = l.location_id
             ORDER BY l.id DESC
             LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
        );
        Http::ok(['movements' => array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'product_id' => (int) $r['product_id'],
            'product_name' => (string) $r['product_name'],
            'product_sku' => (string) $r['product_sku'],
            'variant_id' => $r['variant_id'] !== null ? (int) $r['variant_id'] : null,
            'variant_sku' => $r['variant_sku'] !== null ? (string) $r['variant_sku'] : null,
            'location_id' => $r['location_id'] !== null ? (int) $r['location_id'] : null,
            'location_name' => $r['location_name'] !== null ? (string) $r['location_name'] : null,
            'delta' => (int) $r['delta'],
            'reason' => (string) $r['reason'],
            'note' => $r['note'] !== null ? (string) $r['note'] : ($r['reference_id'] !== null ? (string) $r['reference_id'] : null),
            'created_at' => (string) $r['created_at'],
        ], $stmt->fetchAll())], ['page' => $page, 'per_page' => $perPage, 'total' => $total]);
    }

    // ---- Report: reservations ----------------------------------------------
    if ($report === 'reservations') {
        $stmt = $pdo->query(
            'SELECT r.id, r.variant_id, r.product_id, r.cart_id, r.order_id, r.quantity, r.expires_at, r.status,
                    p.name AS product_name, p.sku AS product_sku, v.sku AS variant_sku
             FROM ' . $reservations . ' r
             JOIN ' . $products . ' p ON p.id = r.product_id
             LEFT JOIN ' . $variants . ' v ON v.id = r.variant_id
             WHERE r.status = \'active\'
             ORDER BY r.expires_at ASC'
        );
        Http::ok(['reservations' => array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'variant_id' => $r['variant_id'] !== null ? (int) $r['variant_id'] : null,
            'product_id' => (int) $r['product_id'],
            'product_name' => (string) $r['product_name'],
            'product_sku' => (string) $r['product_sku'],
            'variant_sku' => $r['variant_sku'] !== null ? (string) $r['variant_sku'] : null,
            'cart_id' => $r['cart_id'] !== null ? (int) $r['cart_id'] : null,
            'order_id' => $r['order_id'] !== null ? (int) $r['order_id'] : null,
            'quantity' => (int) $r['quantity'],
            'expires_at' => (string) $r['expires_at'],
            'status' => (string) $r['status'],
        ], $stmt->fetchAll())]);
    }

    // ---- Report: reorder ---------------------------------------------------
    if ($report === 'reorder') {
        $stmt = $pdo->query(
            'SELECT p.id, p.slug, p.sku, p.name, p.stock, p.low_stock_threshold, p.cost
             FROM ' . $products . ' p
             WHERE p.is_active = TRUE AND p.low_stock_threshold > 0 AND p.stock <= p.low_stock_threshold
             ORDER BY (CAST(p.stock AS SIGNED) - CAST(p.low_stock_threshold AS SIGNED)) ASC
             LIMIT 200'
        );
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $stock = (int) $r['stock'];
            $threshold = (int) $r['low_stock_threshold'];
            $rows[] = [
                'id' => (int) $r['id'],
                'slug' => (string) $r['slug'],
                'sku' => (string) $r['sku'],
                'name' => (string) $r['name'],
                'stock' => $stock,
                'low_stock_threshold' => $threshold,
                'shortfall' => max(0, $threshold - $stock),
                'cost' => $r['cost'] !== null ? Money::norm($r['cost']) : null,
                'reorder_cost' => $r['cost'] !== null ? Money::mul(Money::norm($r['cost']), (string) max(0, $threshold - $stock)) : null,
            ];
        }
        Http::ok(['reorder' => $rows]);
    }

    // ---- Default: one product/variant's stock summary + ledger -------------
    $productId = isset($_GET['product_id']) ? Validate::int($_GET['product_id'], 'product_id', 1, PHP_INT_MAX) : null;
    $variantId = isset($_GET['variant_id']) ? Validate::int($_GET['variant_id'], 'variant_id', 1, PHP_INT_MAX) : null;
    if ($productId === null && $variantId === null) {
        Http::error(400, 'VALIDATION_FAILED', 'Provide product_id or variant_id.', ['product_id' => 'required']);
    }

    if ($variantId !== null) {
        $stmt = $pdo->prepare(
            'SELECT v.id, v.sku, v.attributes, v.stock, v.low_stock_threshold, v.cost, v.weight_grams, v.is_active,
                    p.id AS product_id, p.slug AS product_slug, p.name AS product_name
             FROM ' . $variants . ' v
             JOIN ' . $products . ' p ON p.id = v.product_id
             WHERE v.id = ? LIMIT 1'
        );
        $stmt->execute([$variantId]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT p.id, p.slug, p.sku, p.name, p.stock, p.low_stock_threshold, p.cost, p.weight_grams, p.is_active,
                    NULL AS product_id, NULL AS product_slug, NULL AS product_name
             FROM ' . $products . ' p
             WHERE p.id = ? LIMIT 1'
        );
        $stmt->execute([$productId]);
    }
    $row = $stmt->fetch();
    if ($row === false) {
        Http::error(404, 'NOT_FOUND', 'Product or variant not found.');
    }

    $page = Validate::int($_GET['page'] ?? 1, 'page', 1, 100000) ?? 1;
    $perPage = Validate::int($_GET['per_page'] ?? 25, 'per_page', 1, 200) ?? 25;

    // Resolve the product_id used to filter ledger entries: variant rows carry
    // their product_id from the join; product rows use their own id.
    $ledgerProductId = $row['product_id'] !== null ? (int) $row['product_id'] : (int) ($row['id'] ?? $productId);

    $where = 'product_id = ?';
    $params = [$ledgerProductId];
    if ($variantId !== null) {
        $where .= ' AND variant_id = ?';
        $params[] = $variantId;
    }

    $count = $pdo->prepare('SELECT COUNT(*) FROM ' . $ledger . ' WHERE ' . $where);
    $count->execute($params);
    $total = (int) $count->fetchColumn();

    $entries = $pdo->prepare(
        'SELECT id, variant_id, location_id, delta, reason, reference_id, note, created_at FROM ' . $ledger . '
         WHERE ' . $where . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
    );
    $entries->execute($params);

    $stock = (int) ($row['stock'] ?? 0);
    $threshold = (int) ($row['low_stock_threshold'] ?? 5);

    Http::ok([
        'target' => [
            'id' => (int) $row['id'],
            'sku' => (string) ($row['sku'] ?? ''),
            'name' => (string) ($row['product_name'] ?? $row['name'] ?? ''),
            'slug' => $row['product_slug'] !== null ? (string) $row['product_slug'] : (string) ($row['slug'] ?? ''),
            'stock' => $stock,
            'low_stock_threshold' => $threshold,
            'low_stock' => $stock <= $threshold,
            'variant_id' => $variantId,
            'location_id' => null,
            'cost' => $row['cost'] !== null ? Money::norm($row['cost']) : null,
            'value' => $row['cost'] !== null ? Money::mul(Money::norm($row['cost']), (string) $stock) : null,
            'weight_grams' => $row['weight_grams'] !== null ? (int) $row['weight_grams'] : null,
            'is_active' => (bool) $row['is_active'],
        ],
        'entries' => array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'variant_id' => $r['variant_id'] !== null ? (int) $r['variant_id'] : null,
            'location_id' => $r['location_id'] !== null ? (int) $r['location_id'] : null,
            'delta' => (int) $r['delta'],
            'reason' => (string) $r['reason'],
            'note' => $r['note'] !== null ? (string) $r['note'] : ($r['reference_id'] !== null ? (string) $r['reference_id'] : null),
            'created_at' => (string) $r['created_at'],
        ], $entries->fetchAll()),
    ], ['page' => $page, 'per_page' => $perPage, 'total' => $total]);
} catch (Throwable $e) {
    Http::fail($e);
}
