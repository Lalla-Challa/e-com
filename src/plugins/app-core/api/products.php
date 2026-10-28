<?php

declare(strict_types=1);

use AppCore\Auth;
use AppCore\Db;
use AppCore\Http;
use AppCore\Validate;
use AppCore\ValidationException;

require_once __DIR__ . '/_bootstrap.php';

/**
 * /api/products.php           — paginated listing (?page, per_page, category, q, sort, min_price, max_price, in_stock)
 * /api/products.php?id=       — single product by id
 * /api/products.php?slug=     — single product by slug (+ review summary)
 */
try {
    $pdo = Db::pdo();
    $products = Db::table('products');
    $categories = Db::table('categories');
    $reviews = Db::table('reviews');

    $id = isset($_GET['id']) ? Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX) : null;
    $slug = isset($_GET['slug']) ? Validate::str($_GET['slug'], 'slug', 1, 190) : null;

    if ($id !== null || $slug !== null) {
        $stmt = $pdo->prepare(
            'SELECT p.id, p.slug, p.sku, p.name, p.description, p.price, p.currency, p.stock,
                    p.category_id, p.image_url, p.metadata, p.is_active, p.created_at, p.updated_at,
                    c.slug AS category_slug, c.name AS category_name
             FROM ' . $products . ' p
             LEFT JOIN ' . $categories . ' c ON c.id = p.category_id
             WHERE ' . ($id !== null ? 'p.id = ?' : 'p.slug = ?') . ' AND p.is_active = TRUE
             LIMIT 1'
        );
        $stmt->execute([$id ?? $slug]);
        $product = $stmt->fetch();
        if ($product === false) {
            Http::error(404, 'NOT_FOUND', 'Product not found.');
        }

        $summary = $pdo->prepare('SELECT COUNT(*) AS review_count, COALESCE(AVG(rating), 0) AS rating_avg FROM ' . $reviews . ' WHERE product_id = ? AND is_approved = TRUE');
        $summary->execute([(int) $product['id']]);
        $summaryRow = $summary->fetch() ?: ['review_count' => 0, 'rating_avg' => 0];

        $specs = $product['metadata'] !== null ? json_decode((string) $product['metadata'], true) : null;

        Http::ok([
            'product' => [
                'id' => (int) $product['id'],
                'slug' => (string) $product['slug'],
                'sku' => (string) $product['sku'],
                'name' => (string) $product['name'],
                'description' => (string) ($product['description'] ?? ''),
                'price' => (string) $product['price'],
                'currency' => (string) $product['currency'],
                'stock' => (int) $product['stock'],
                'category' => $product['category_slug'] !== null ? ['slug' => (string) $product['category_slug'], 'name' => (string) $product['category_name']] : null,
                'image_url' => $product['image_url'] !== null ? (string) $product['image_url'] : null,
                'specs' => $specs,
                'is_active' => (bool) $product['is_active'],
                'created_at' => (string) $product['created_at'],
            ],
            'reviews_summary' => [
                'count' => (int) $summaryRow['review_count'],
                'average' => round((float) $summaryRow['rating_avg'], 2),
            ],
        ]);
    }

    // ---- Admin update ------------------------------------------------------
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'PATCH') {
        Auth::requireAdmin();
        $body = Validate::jsonBody();
        try {
            $id = Validate::int($body['id'] ?? null, 'id', 1, PHP_INT_MAX);
        } catch (ValidationException $e) {
            Http::error(400, 'VALIDATION_FAILED', 'Invalid product id.', $e->fields);
        }
        \assert($id !== null);

        $sets = [];
        $params = [];
        if (array_key_exists('name', $body)) {
            $sets[] = 'name = ?';
            $params[] = Validate::str($body['name'], 'name', 1, 190);
        }
        if (array_key_exists('price', $body)) {
            $newPrice = Validate::decimal($body['price'], 'price', 0, 99999999);
            $sets[] = 'price = ?';
            $params[] = $newPrice;
        }
        if (array_key_exists('stock', $body)) {
            $sets[] = 'stock = ?';
            $params[] = Validate::int($body['stock'], 'stock', 0, 999999);
        }
        if (array_key_exists('is_active', $body)) {
            $sets[] = 'is_active = ?';
            $params[] = Validate::bool($body['is_active'], 'is_active') ? 1 : 0;
        }
        if ($sets === []) {
            Http::error(400, 'VALIDATION_FAILED', 'Nothing to update.', ['body' => 'provide name, price, stock or is_active']);
        }

        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT id FROM ' . $products . ' WHERE id = ? LIMIT 1 FOR UPDATE');
            $lock->execute([$id]);
            if ($lock->fetchColumn() === false) {
                $pdo->rollBack();
                Http::error(404, 'NOT_FOUND', 'Product not found.');
            }
            $stmt = $pdo->prepare('UPDATE ' . $products . ' SET ' . implode(', ', $sets) . ', updated_at = UTC_TIMESTAMP(3) WHERE id = ?');
            $params[] = $id;
            $stmt->execute($params);
            if (isset($newPrice)) {
                $pdo->prepare('INSERT INTO ' . Db::table('price_history') . ' (product_id, price, currency, effective_at) VALUES (?, ?, \'USD\', UTC_TIMESTAMP(3))')
                    ->execute([$id, $newPrice]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Http::ok(['product' => ['id' => $id]]);
    }

    // ---- Listing -----------------------------------------------------------
    $page = Validate::int($_GET['page'] ?? 1, 'page', 1, 100000) ?? 1;
    $perPage = Validate::int($_GET['per_page'] ?? 24, 'per_page', 1, 100) ?? 24;
    $category = isset($_GET['category']) ? Validate::str($_GET['category'], 'category', 1, 190) : null;
    $q = isset($_GET['q']) ? Validate::str($_GET['q'], 'q', 1, 120) : null;
    $minPrice = isset($_GET['min_price']) ? Validate::decimal($_GET['min_price'], 'min_price', 0, 99999999) : null;
    $maxPrice = isset($_GET['max_price']) ? Validate::decimal($_GET['max_price'], 'max_price', 0, 99999999) : null;
    $inStock = isset($_GET['in_stock']) ? Validate::bool($_GET['in_stock'], 'in_stock') : null;
    $sort = Validate::enum($_GET['sort'] ?? 'created_at', 'sort', ['price', 'name', 'created_at', '-price', '-name', '-created_at']) ?? 'created_at';

    $where = ['p.is_active = TRUE'];
    $params = [];
    if ($category !== null) {
        $where[] = 'c.slug = ?';
        $params[] = $category;
    }
    if ($q !== null) {
        $where[] = '(p.name LIKE ? OR p.description LIKE ?)';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }
    if ($minPrice !== null) {
        $where[] = 'p.price >= ?';
        $params[] = $minPrice;
    }
    if ($maxPrice !== null) {
        $where[] = 'p.price <= ?';
        $params[] = $maxPrice;
    }
    if ($inStock === true) {
        $where[] = 'p.stock > 0';
    }
    $whereSql = implode(' AND ', $where);

    $sortMap = [
        'price' => 'p.price ASC',
        '-price' => 'p.price DESC',
        'name' => 'p.name ASC',
        '-name' => 'p.name DESC',
        'created_at' => 'p.created_at DESC',
        '-created_at' => 'p.created_at ASC',
    ];

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $products . ' p LEFT JOIN ' . $categories . ' c ON c.id = p.category_id WHERE ' . $whereSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $listStmt = $pdo->prepare(
        'SELECT p.id, p.slug, p.name, p.price, p.currency, p.stock, p.image_url, p.metadata, c.slug AS category_slug
         FROM ' . $products . ' p
         LEFT JOIN ' . $categories . ' c ON c.id = p.category_id
         WHERE ' . $whereSql . '
         ORDER BY ' . $sortMap[$sort] . '
         LIMIT ' . $perPage . ' OFFSET ' . $offset
    );
    $listStmt->execute($params);

    $items = array_map(static fn (array $row): array => [
        'id' => (int) $row['id'],
        'slug' => (string) $row['slug'],
        'name' => (string) $row['name'],
        'price' => (string) $row['price'],
        'currency' => (string) $row['currency'],
        'stock' => (float) $row['stock'],
        'image_url' => $row['image_url'] !== null ? (string) $row['image_url'] : null,
        'category' => $row['category_slug'] !== null ? (string) $row['category_slug'] : null,
        // Fabric specifications (fibre, origin, mill, gsm, width, unit,
        // reference, swatch price). Cards render these from the server so the
        // catalogue never re-authors catalogue facts in the client.
        'specs' => $row['metadata'] !== null ? json_decode((string) $row['metadata'], true) : null,
    ], $listStmt->fetchAll());

    Http::ok(['products' => $items], ['page' => $page, 'per_page' => $perPage, 'total' => $total]);
} catch (ValidationException $e) {
    Http::error(400, 'VALIDATION_FAILED', 'Invalid query parameters.', $e->fields);
} catch (Throwable $e) {
    Http::fail($e);
}
