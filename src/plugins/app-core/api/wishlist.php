<?php

declare(strict_types=1);

use AppCore\Auth;
use AppCore\Db;
use AppCore\Http;
use AppCore\Validate;
use AppCore\ValidationException;

require_once __DIR__ . '/_bootstrap.php';

/**
 * Customer wishlist.
 *
 * GET  /api/wishlist.php             — returns:read_own: caller's wishlist with products
 * POST /api/wishlist.php             — returns:request: add product to wishlist
 * DELETE /api/wishlist.php?id=N      — returns:request: remove product from wishlist
 *
 * Body for POST: { product_id }
 */
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $user = Auth::requireUser();
    $pdo = Db::pdo();
    $wishlists = Db::table('wishlists');
    $products = Db::table('products');

    if ($method === 'GET') {
        $page = Validate::int($_GET['page'] ?? 1, 'page', 1, 100000) ?? 1;
        $perPage = Validate::int($_GET['per_page'] ?? 50, 'per_page', 1, 200) ?? 50;

        $count = $pdo->prepare('SELECT COUNT(*) FROM ' . $wishlists . ' WHERE user_id = ?');
        $count->execute([$user['id']]);
        $total = (int) $count->fetchColumn();

        $rows = $pdo->prepare(
            'SELECT w.id, w.product_id, w.created_at,
                    p.slug, p.sku, p.name, p.price, p.currency, p.image_url, p.stock, p.is_active
             FROM ' . $wishlists . ' w
             JOIN ' . $products . ' p ON p.id = w.product_id
             WHERE w.user_id = ?
             ORDER BY w.created_at DESC
             LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
        );
        $rows->execute([$user['id']]);
        Http::ok(['wishlist' => array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'product_id' => (int) $r['product_id'],
                'slug' => (string) $r['slug'],
                'sku' => (string) $r['sku'],
                'name' => (string) $r['name'],
                'price' => (string) $r['price'],
                'currency' => (string) $r['currency'],
                'image_url' => $r['image_url'] !== null ? (string) $r['image_url'] : null,
                'stock' => (int) $r['stock'],
                'is_active' => (bool) $r['is_active'],
                'created_at' => (string) $r['created_at'],
            ];
        }, $rows->fetchAll())], ['page' => $page, 'per_page' => $perPage, 'total' => $total]);
    }

    if ($method === 'POST') {
        $body = Validate::jsonBody();
        try {
            $productId = Validate::int($body['product_id'] ?? null, 'product_id', 1, PHP_INT_MAX);
        } catch (ValidationException $e) {
            Http::error(400, 'VALIDATION_FAILED', 'product_id is required.', ['product_id' => 'required']);
        }
        \assert($productId !== null);

        $product = $pdo->prepare('SELECT id FROM ' . $products . ' WHERE id = ? AND is_active = TRUE LIMIT 1');
        $product->execute([$productId]);
        if ($product->fetch() === false) {
            Http::error(404, 'NOT_FOUND', 'Product not found.');
        }

        $pdo->prepare(
            'INSERT IGNORE INTO ' . $wishlists . ' (user_id, product_id, created_at) VALUES (?, ?, ' . 'UTC_TIMESTAMP(3)' . ')'
        )->execute([$user['id'], $productId]);
        Http::created(['product_id' => $productId]);
    }

    if ($method === 'DELETE' && isset($_GET['id'])) {
        $id = Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX);
        \assert($id !== null);
        $pdo->prepare('DELETE FROM ' . $wishlists . ' WHERE id = ? AND user_id = ?')
            ->execute([$id, $user['id']]);
        Http::ok(['id' => $id]);
    }

    Http::error(405, 'METHOD_NOT_ALLOWED', 'Unsupported method for wishlist.');
} catch (Throwable $e) {
    Http::fail($e);
}
