<?php

declare(strict_types=1);

use AppCore\Auth;
use AppCore\Db;
use AppCore\Http;
use AppCore\Validate;
use AppCore\ValidationException;

require_once __DIR__ . '/_bootstrap.php';

/**
 * Product variants.
 *
 * GET  /api/variants.php?product_id=N        — products:read: list variants for a product
 * GET  /api/variants.php?id=N                — products:read: single variant
 * POST /api/variants.php                     — products:manage: create variant
 * PATCH /api/variants.php?id=N               — products:manage: update variant
 * DELETE /api/variants.php?id=N              — products:manage: delete variant
 */
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $user = Auth::currentUser();
    $pdo = Db::pdo();
    $variants = Db::table('product_variants');
    $products = Db::table('products');
    $ledger = Db::table('stock_ledger');
    $now = 'UTC_TIMESTAMP(3)';

    $requireWrite = function () use ($user): void {
        if (!isset($user) || $user === null) {
            Auth::requireUser();
        }
        Auth::requirePermission('products:manage');
    };

    // ---- List variants for a product ---------------------------------------
    if ($method === 'GET' && !isset($_GET['id'])) {
        Auth::requirePermission('products:read');
        $productId = Validate::int($_GET['product_id'] ?? null, 'product_id', 1, PHP_INT_MAX);
        if ($productId === null) {
            Http::error(400, 'VALIDATION_FAILED', 'product_id is required.', ['product_id' => 'required']);
        }
        $product = $pdo->prepare('SELECT variant_mode FROM ' . $products . ' WHERE id = ? LIMIT 1');
        $product->execute([$productId]);
        if ($product->fetch() === false) {
            Http::error(404, 'NOT_FOUND', 'Product not found.');
        }
        $rows = $pdo->prepare(
            'SELECT id, sku, attributes, price_adjustment, stock, low_stock_threshold, weight_grams, is_active, created_at, updated_at
             FROM ' . $variants . ' WHERE product_id = ? ORDER BY id ASC'
        );
        $rows->execute([$productId]);
        Http::ok(['variants' => array_map(static function (array $r): array {
            $attrs = $r['attributes'] !== null ? json_decode((string) $r['attributes'], true) : null;
            return [
                'id' => (int) $r['id'],
                'sku' => (string) $r['sku'],
                'attributes' => \is_array($attrs) ? $attrs : [],
                'price_adjustment' => (string) $r['price_adjustment'],
                'stock' => (int) $r['stock'],
                'low_stock_threshold' => $r['low_stock_threshold'] !== null ? (int) $r['low_stock_threshold'] : null,
                'weight_grams' => $r['weight_grams'] !== null ? (int) $r['weight_grams'] : null,
                'is_active' => (bool) $r['is_active'],
                'created_at' => (string) $r['created_at'],
                'updated_at' => (string) $r['updated_at'],
            ];
        }, $rows->fetchAll())]);
    }

    // ---- Single variant -----------------------------------------------------
    if ($method === 'GET' && isset($_GET['id'])) {
        Auth::requirePermission('products:read');
        $id = Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX);
        \assert($id !== null);
        $row = $pdo->prepare('SELECT * FROM ' . $variants . ' WHERE id = ? LIMIT 1');
        $row->execute([$id]);
        $v = $row->fetch();
        if ($v === false) {
            Http::error(404, 'NOT_FOUND', 'Variant not found.');
        }
        $attrs = $v['attributes'] !== null ? json_decode((string) $v['attributes'], true) : null;
        Http::ok(['variant' => [
            'id' => (int) $v['id'],
            'product_id' => (int) $v['product_id'],
            'sku' => (string) $v['sku'],
            'attributes' => \is_array($attrs) ? $attrs : [],
            'price_adjustment' => (string) $v['price_adjustment'],
            'stock' => (int) $v['stock'],
            'low_stock_threshold' => $v['low_stock_threshold'] !== null ? (int) $v['low_stock_threshold'] : null,
            'weight_grams' => $v['weight_grams'] !== null ? (int) $v['weight_grams'] : null,
            'is_active' => (bool) $v['is_active'],
            'created_at' => (string) $v['created_at'],
            'updated_at' => (string) $v['updated_at'],
        ]]);
    }

    // ---- Write (products:manage) ------------------------------------------
    if (!isset($user) || $user === null) {
        Auth::requireUser();
    }
    Auth::requirePermission('products:manage');

    $body = Validate::jsonBody();

    if ($method === 'POST') {
        $productId = Validate::int($body['product_id'] ?? null, 'product_id', 1, PHP_INT_MAX);
        $sku = Validate::str($body['sku'] ?? null, 'sku', 1, 64);
        $attributes = $body['attributes'] ?? [];
        if (!is_array($attributes)) {
            Http::error(400, 'VALIDATION_FAILED', '"attributes" must be an object.', ['attributes' => 'required']);
        }
        $priceAdjustment = Validate::decimal($body['price_adjustment'] ?? '0.00', 'price_adjustment', -1000000, 1000000);
        $stock = Validate::int($body['stock'] ?? 0, 'stock', 0, 999999, false) ?? 0;
        $lowStockThreshold = Validate::int($body['low_stock_threshold'] ?? null, 'low_stock_threshold', 0, 1000000, false);
        $weightGrams = Validate::int($body['weight_grams'] ?? null, 'weight_grams', 0, 10000000, false);
        $isActive = Validate::bool($body['is_active'] ?? true, 'is_active', false) ?? true;
        \assert($productId !== null && $sku !== null && $priceAdjustment !== null);

        $pdo->prepare(
            'INSERT INTO ' . $variants . ' (product_id, sku, attributes, price_adjustment, stock, low_stock_threshold, weight_grams, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ' . $now . ', ' . $now . ')'
        )->execute([
            $productId,
            $sku,
            json_encode($attributes, JSON_UNESCAPED_UNICODE),
            $priceAdjustment,
            $stock,
            $lowStockThreshold,
            $weightGrams,
            $isActive,
        ]);
        Http::created(['id' => (int) $pdo->lastInsertId(), 'sku' => $sku]);
    }

    if ($method === 'PATCH' && isset($_GET['id'])) {
        $id = Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX);
        $sku = Validate::str($body['sku'] ?? null, 'sku', 1, 64, false);
        $attributes = $body['attributes'] ?? null;
        if ($attributes !== null && !is_array($attributes)) {
            Http::error(400, 'VALIDATION_FAILED', '"attributes" must be an object.', ['attributes' => 'required']);
        }
        $priceAdjustment = Validate::decimal($body['price_adjustment'] ?? null, 'price_adjustment', -1000000, 1000000, false);
        $stock = Validate::int($body['stock'] ?? null, 'stock', 0, 999999, false);
        $lowStockThreshold = Validate::int($body['low_stock_threshold'] ?? null, 'low_stock_threshold', 0, 1000000, false);
        $weightGrams = Validate::int($body['weight_grams'] ?? null, 'weight_grams', 0, 10000000, false);
        $isActive = Validate::bool($body['is_active'] ?? null, 'is_active', false);
        \assert($id !== null);
        $sets = [];
        $params = [];
        if ($sku !== null) { $sets[] = 'sku = ?'; $params[] = $sku; }
        if ($attributes !== null) { $sets[] = 'attributes = ?'; $params[] = json_encode($attributes, JSON_UNESCAPED_UNICODE); }
        if ($priceAdjustment !== null) { $sets[] = 'price_adjustment = ?'; $params[] = $priceAdjustment; }
        if ($stock !== null) { $sets[] = 'stock = ?'; $params[] = $stock; }
        if ($lowStockThreshold !== null) { $sets[] = 'low_stock_threshold = ?'; $params[] = $lowStockThreshold; }
        if ($weightGrams !== null) { $sets[] = 'weight_grams = ?'; $params[] = $weightGrams; }
        if ($isActive !== null) { $sets[] = 'is_active = ?'; $params[] = $isActive ? 'TRUE' : 'FALSE'; }
        $sets[] = 'updated_at = UTC_TIMESTAMP(3)';
        $params[] = $id;
        if (count($sets) > 1) {
            $pdo->prepare('UPDATE ' . $variants . ' SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        }
        Http::ok(['id' => $id]);
    }

    if ($method === 'DELETE' && isset($_GET['id'])) {
        $id = Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX);
        \assert($id !== null);
        $pdo->prepare('DELETE FROM ' . $variants . ' WHERE id = ?')->execute([$id]);
        Http::ok(['id' => $id]);
    }

    Http::error(405, 'METHOD_NOT_ALLOWED', 'Unsupported method for variants.');
} catch (Throwable $e) {
    Http::fail($e);
}
