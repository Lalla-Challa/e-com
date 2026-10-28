<?php

declare(strict_types=1);

use AppCore\Auth;
use AppCore\Db;
use AppCore\Http;
use AppCore\Validate;
use AppCore\ValidationException;

require_once __DIR__ . '/_bootstrap.php';

/** @param array<string, mixed>|null $meta */
$productUsesMetres = static function (?array $meta): bool {
    return \is_array($meta) && (($meta['unit'] ?? '') === 'metre');
};

/**
 * /api/cart — GET (read), POST {product_id, quantity} (add/merge),
 * PATCH {item_id, quantity} (0 = remove), DELETE (clear).
 * Carts belong to the signed-in user or to the signed guest cart cookie.
 */
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $pdo = Db::pdo();
    $identity = Auth::cartIdentity();
    $carts = Db::table('cart');
    $items = Db::table('cart_items');
    $products = Db::table('products');

    $findCart = static function () use ($pdo, $identity, $carts): ?array {
        if ($identity['user_id'] !== null) {
            $stmt = $pdo->prepare('SELECT id FROM ' . $carts . ' WHERE user_id = ? ORDER BY updated_at DESC LIMIT 1');
            $stmt->execute([$identity['user_id']]);
        } else {
            $stmt = $pdo->prepare('SELECT id FROM ' . $carts . ' WHERE session_token = ? LIMIT 1');
            $stmt->execute([$identity['session_token']]);
        }
        $row = $stmt->fetch();

        return $row === false ? null : ['id' => (int) $row['id']];
    };

    $ensureCart = static function () use ($pdo, $identity, $carts, $findCart): array {
        $cart = $findCart();
        if ($cart !== null) {
            return $cart;
        }
        $pdo->prepare('INSERT INTO ' . $carts . ' (user_id, session_token, created_at, updated_at) VALUES (?, ?, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))')
            ->execute([$identity['user_id'], $identity['user_id'] !== null ? Auth::randomToken(32) : $identity['session_token']]);

        return ['id' => (int) $pdo->lastInsertId()];
    };

    $loadCart = static function (int $cartId) use ($pdo, $items, $products): array {
        $stmt = $pdo->prepare(
            'SELECT i.id, i.product_id, i.quantity, i.unit_price, p.slug, p.name, p.image_url, p.stock, p.metadata,
                    p.price AS current_price, p.is_active
             FROM ' . $items . ' i
             JOIN ' . $products . ' p ON p.id = i.product_id
             WHERE i.cart_id = ?
             ORDER BY i.created_at ASC, i.id ASC'
        );
        $stmt->execute([$cartId]);

        $lines = [];
        $subtotal = '0.00';
        $count = 0.0;
        foreach ($stmt->fetchAll() as $row) {
            $qtyStr = (string) $row['quantity'];
            $lineTotal = bcmul((string) $row['unit_price'], $qtyStr, 2);
            $subtotal = bcadd($subtotal, $lineTotal, 2);
            $count += (float) $qtyStr;
            $lines[] = [
                'id' => (int) $row['id'],
                'product_id' => (int) $row['product_id'],
                'slug' => (string) $row['slug'],
                'name' => (string) $row['name'],
                'image_url' => $row['image_url'] !== null ? (string) $row['image_url'] : null,
                'quantity' => (float) $qtyStr,
                'unit_price' => (string) $row['unit_price'],
                'line_total' => $lineTotal,
                'stock' => (float) $row['stock'],
                // Fabric facts (unit, composition, weight) so the bag can speak
                // in metres rather than re-inventing the catalogue in the SPA.
                'specs' => $row['metadata'] !== null ? json_decode((string) $row['metadata'], true) : null,
                'price_changed' => bccomp((string) $row['unit_price'], (string) $row['current_price'], 2) !== 0,
                'is_active' => (bool) $row['is_active'],
            ];
        }

        return ['lines' => $lines, 'subtotal' => $subtotal, 'item_count' => $count];
    };

    if ($method === 'GET') {
        $cart = $findCart();
        $data = $cart === null
            ? ['lines' => [], 'subtotal' => '0.00', 'item_count' => 0]
            : $loadCart($cart['id']);
        Http::ok(['cart' => $data]);
    }

    if ($method === 'POST') {
        $body = Validate::jsonBody();
        try {
            $productId = Validate::int($body['product_id'] ?? null, 'product_id', 1, PHP_INT_MAX);
        } catch (ValidationException $e) {
            Http::error(400, 'VALIDATION_FAILED', 'Invalid cart payload.', $e->fields);
        }
        \assert($productId !== null);

        $stmt = $pdo->prepare('SELECT price, stock, is_active, metadata FROM ' . $products . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if ($product === false || !(bool) $product['is_active']) {
            Http::error(404, 'NOT_FOUND', 'Product not found.');
        }
        $meta = $product['metadata'] !== null ? json_decode((string) $product['metadata'], true) : null;
        $metreUnit = $productUsesMetres(\is_array($meta) ? $meta : null);
        try {
            $quantity = Validate::cartQuantity($body['quantity'] ?? 1, 'quantity', $metreUnit);
        } catch (ValidationException $e) {
            Http::error(400, 'VALIDATION_FAILED', 'Invalid cart payload.', $e->fields);
        }
        if (bccomp((string) $product['stock'], $quantity, 2) < 0 || bccomp((string) $product['stock'], '0', 2) <= 0) {
            Http::error(422, 'OUT_OF_STOCK', 'That product is out of stock.');
        }

        $cart = $ensureCart();
        $merge = $pdo->prepare('SELECT id, quantity FROM ' . $items . ' WHERE cart_id = ? AND product_id = ? LIMIT 1');
        $merge->execute([$cart['id'], $productId]);
        $existing = $merge->fetch();

        if ($existing !== false) {
            $newQty = bcadd((string) $existing['quantity'], $quantity, 2);
            if (bccomp($newQty, '999', 2) > 0) {
                $newQty = '999';
            }
            if (bccomp((string) $product['stock'], $newQty, 2) < 0) {
                Http::error(422, 'STOCK_CONFLICT', 'Not enough stock for that quantity.', ['quantity' => 'only ' . $product['stock'] . ' available']);
            }
            $pdo->prepare('UPDATE ' . $items . ' SET quantity = ?, unit_price = ?, updated_at = UTC_TIMESTAMP(3) WHERE id = ?')
                ->execute([$newQty, (string) $product['price'], (int) $existing['id']]);
        } else {
            $pdo->prepare('INSERT INTO ' . $items . ' (cart_id, product_id, quantity, unit_price, created_at, updated_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))')
                ->execute([$cart['id'], $productId, $quantity, (string) $product['price']]);
        }

        $pdo->prepare('UPDATE ' . $carts . ' SET updated_at = UTC_TIMESTAMP(3) WHERE id = ?')->execute([$cart['id']]);
        Http::created(['cart' => $loadCart($cart['id'])]);
    }

    if ($method === 'PATCH') {
        $body = Validate::jsonBody();
        try {
            $itemId = Validate::int($body['item_id'] ?? null, 'item_id', 1, PHP_INT_MAX);
        } catch (ValidationException $e) {
            Http::error(400, 'VALIDATION_FAILED', 'Invalid cart payload.', $e->fields);
        }
        \assert($itemId !== null);

        $cart = $findCart();
        if ($cart === null) {
            Http::error(404, 'NOT_FOUND', 'Cart is empty.');
        }

        $stmt = $pdo->prepare(
            'SELECT i.id, p.metadata, p.stock
             FROM ' . $items . ' i
             JOIN ' . $products . ' p ON p.id = i.product_id
             WHERE i.id = ? AND i.cart_id = ?
             LIMIT 1'
        );
        $stmt->execute([$itemId, $cart['id']]);
        $row = $stmt->fetch();
        if ($row === false) {
            Http::error(404, 'NOT_FOUND', 'Cart item not found.');
        }

        $rawQty = $body['quantity'] ?? null;
        if ($rawQty === 0 || $rawQty === '0' || $rawQty === 0.0) {
            $pdo->prepare('DELETE FROM ' . $items . ' WHERE id = ?')->execute([$itemId]);
        } else {
            $meta = $row['metadata'] !== null ? json_decode((string) $row['metadata'], true) : null;
            $metreUnit = $productUsesMetres(\is_array($meta) ? $meta : null);
            try {
                $quantity = Validate::cartQuantity($rawQty, 'quantity', $metreUnit);
            } catch (ValidationException $e) {
                Http::error(400, 'VALIDATION_FAILED', 'Invalid cart payload.', $e->fields);
            }
            if (bccomp((string) $row['stock'], $quantity, 2) < 0) {
                Http::error(422, 'STOCK_CONFLICT', 'Not enough stock for that quantity.', ['quantity' => 'only ' . $row['stock'] . ' available']);
            }
            $pdo->prepare('UPDATE ' . $items . ' SET quantity = ?, updated_at = UTC_TIMESTAMP(3) WHERE id = ?')->execute([$quantity, $itemId]);
        }
        $pdo->prepare('UPDATE ' . $carts . ' SET updated_at = UTC_TIMESTAMP(3) WHERE id = ?')->execute([$cart['id']]);

        Http::ok(['cart' => $loadCart($cart['id'])]);
    }

    if ($method === 'DELETE') {
        $cart = $findCart();
        if ($cart !== null) {
            $pdo->prepare('DELETE FROM ' . $items . ' WHERE cart_id = ?')->execute([$cart['id']]);
            $pdo->prepare('UPDATE ' . $carts . ' SET updated_at = UTC_TIMESTAMP(3) WHERE id = ?')->execute([$cart['id']]);
        }
        Http::ok(['cart' => ['lines' => [], 'subtotal' => '0.00', 'item_count' => 0]]);
    }

    Http::error(405, 'METHOD_NOT_ALLOWED', 'Unsupported method for cart.');
} catch (Throwable $e) {
    Http::fail($e);
}
