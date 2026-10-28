<?php

declare(strict_types=1);

use AppCore\Auth;
use AppCore\Db;
use AppCore\Http;
use AppCore\Validate;
use AppCore\ValidationException;

require_once __DIR__ . '/_bootstrap.php';

/**
 * GET  /api/reviews.php?product={id|slug} — approved reviews for one product.
 * GET  /api/reviews.php?rating=&product=  — omitted product returns the
 *      aggregate ledger across the whole catalogue (used by the reviews index),
 *      paginated, with a score distribution in the meta.
 * POST /api/reviews.php {product_id|slug, rating, title, body} — auth required;
 * only customers with a paid/shipped/delivered order containing the product.
 */
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $pdo = Db::pdo();
    $reviews = Db::table('reviews');
    $products = Db::table('products');
    $orders = Db::table('orders');
    $orderItems = Db::table('order_items');
    $users = Db::table('users');

    if ($method === 'GET') {
        // `product` is optional: without it the endpoint serves the aggregate
        // ledger. Requiring it would make the index page an error by design.
        $productRef = Validate::str($_GET['product'] ?? null, 'product', 1, 190, false);
        $page = Validate::int($_GET['page'] ?? 1, 'page', 1, 100000) ?? 1;
        $perPage = Validate::int($_GET['per_page'] ?? 10, 'per_page', 1, 50) ?? 10;
        $ratingFilter = Validate::int($_GET['rating'] ?? null, 'rating', 1, 5, false);

        $shape = static fn (array $row): array => [
            'id' => (int) $row['id'],
            'rating' => (int) $row['rating'],
            'title' => (string) $row['title'],
            'body' => (string) $row['body'],
            'is_verified_purchase' => (bool) $row['is_verified_purchase'],
            'author' => (string) $row['author'],
            'created_at' => (string) $row['created_at'],
        ];

        if ($productRef !== null) {
            // Accept numeric id or slug.
            $isId = preg_match('/^\d+$/', $productRef) === 1;
            $pStmt = $pdo->prepare('SELECT id FROM ' . $products . ' WHERE ' . ($isId ? 'id = ?' : 'slug = ?') . ' LIMIT 1');
            $pStmt->execute([$productRef]);
            $productId = $pStmt->fetchColumn();
            if ($productId === false) {
                Http::error(404, 'NOT_FOUND', 'Product not found.');
            }
            $productId = (int) $productId;

            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $reviews . ' WHERE product_id = ? AND is_approved = TRUE');
            $countStmt->execute([$productId]);
            $total = (int) $countStmt->fetchColumn();

            $listStmt = $pdo->prepare(
                'SELECT r.id, r.rating, r.title, r.body, r.is_verified_purchase, r.created_at, u.full_name AS author
                 FROM ' . $reviews . ' r
                 JOIN ' . $users . ' u ON u.id = r.user_id
                 WHERE r.product_id = ? AND r.is_approved = TRUE
                 ORDER BY r.created_at DESC
                 LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
            );
            $listStmt->execute([$productId]);

            Http::ok(
                ['reviews' => array_map($shape, $listStmt->fetchAll())],
                ['page' => $page, 'per_page' => $perPage, 'total' => $total]
            );
        }

        // Aggregate ledger: every approved appraisal, newest first, joined to
        // the product it was written about so the card can label the cloth.
        $where = ['r.is_approved = TRUE', 'p.is_active = TRUE'];
        $params = [];
        if ($ratingFilter !== null) {
            $where[] = 'r.rating = ?';
            $params[] = $ratingFilter;
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM ' . $reviews . ' r JOIN ' . $products . ' p ON p.id = r.product_id WHERE ' . $whereSql
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $listStmt = $pdo->prepare(
            'SELECT r.id, r.rating, r.title, r.body, r.is_verified_purchase, r.created_at, u.full_name AS author,
                    p.id AS product_id, p.slug AS product_slug, p.name AS product_name, p.image_url AS product_image
             FROM ' . $reviews . ' r
             JOIN ' . $users . ' u ON u.id = r.user_id
             JOIN ' . $products . ' p ON p.id = r.product_id
             WHERE ' . $whereSql . '
             ORDER BY r.created_at DESC, r.id DESC
             LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
        );
        $listStmt->execute($params);

        $items = array_map(static function (array $row) use ($shape): array {
            $review = $shape($row);
            $review['product'] = [
                'id' => (int) $row['product_id'],
                'slug' => (string) $row['product_slug'],
                'name' => (string) $row['product_name'],
                'image_url' => $row['product_image'] !== null ? (string) $row['product_image'] : null,
            ];

            return $review;
        }, $listStmt->fetchAll());

        // Distribution + average across the whole (filtered) ledger, not just
        // the current page — the summary must not change as pages turn.
        $aggStmt = $pdo->prepare(
            'SELECT r.rating, COUNT(*) AS n FROM ' . $reviews . ' r JOIN ' . $products . ' p ON p.id = r.product_id
             WHERE ' . $whereSql . ' GROUP BY r.rating'
        );
        $aggStmt->execute($params);
        $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $weighted = 0;
        $counted = 0;
        foreach ($aggStmt->fetchAll() as $row) {
            $stars = (int) $row['rating'];
            $n = (int) $row['n'];
            if (isset($distribution[$stars])) {
                $distribution[$stars] = $n;
            }
            $weighted += $stars * $n;
            $counted += $n;
        }

        Http::ok(
            ['reviews' => $items],
            [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'summary' => [
                    'count' => $counted,
                    'average' => $counted > 0 ? round($weighted / $counted, 2) : 0.0,
                    'distribution' => $distribution,
                    'verified' => $counted,
                ],
            ]
        );
    }

    if ($method === 'POST') {
        $user = Auth::requireUser();
        Auth::enforceRateLimit('reviews:' . $user['id']);

        $body = Validate::jsonBody();
        try {
            $productRef = $body['product_id'] ?? $body['slug'] ?? null;
            $isId = \is_int($productRef) || (\is_string($productRef) && preg_match('/^\d+$/', $productRef) === 1);
            $productId = $isId ? Validate::int($productRef, 'product', 1, PHP_INT_MAX) : Validate::str($productRef, 'product', 1, 190);
            $rating = Validate::int($body['rating'] ?? null, 'rating', 1, 5);
            $title = Validate::str($body['title'] ?? null, 'title', 3, 160);
            $text = Validate::str($body['body'] ?? null, 'body', 10, 5000);
        } catch (ValidationException $e) {
            Http::error(400, 'VALIDATION_FAILED', 'Please check the highlighted fields.', $e->fields);
        }
        \assert($rating !== null && $title !== null && $text !== null && $productId !== null);

        if (\is_string($productId)) {
            $pStmt = $pdo->prepare('SELECT id FROM ' . $products . ' WHERE slug = ? LIMIT 1');
            $pStmt->execute([$productId]);
            $found = $pStmt->fetchColumn();
            if ($found === false) {
                Http::error(404, 'NOT_FOUND', 'Product not found.');
            }
            $productId = (int) $found;
        }

        // Only verified buyers may review.
        $purchaseStmt = $pdo->prepare(
            'SELECT oi.id
             FROM ' . $orderItems . ' oi
             JOIN ' . $orders . ' o ON o.id = oi.order_id
             WHERE o.user_id = ? AND oi.product_id = ? AND o.status IN (\'paid\',\'shipped\',\'delivered\')
             LIMIT 1'
        );
        $purchaseStmt->execute([$user['id'], $productId]);
        $verified = $purchaseStmt->fetchColumn() !== false;
        if (!$verified) {
            Http::error(403, 'NOT_PURCHASER', 'Only verified purchasers can review this product.');
        }

        try {
            // Verified buyers are the quality gate: POST already proved a
            // paid/shipped/delivered order, so the review publishes at once.
            // is_approved stays in the schema for future admin moderation.
            $stmt = $pdo->prepare(
                'INSERT INTO ' . $reviews . ' (product_id, user_id, rating, title, body, is_verified_purchase, is_approved, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, TRUE, TRUE, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))'
            );
            $stmt->execute([$productId, $user['id'], $rating, $title, $text]);
        } catch (PDOException $e) {
            // 23000 = duplicate (product_id, user_id) unique key.
            if ($e->getCode() === '23000') {
                Http::error(409, 'ALREADY_REVIEWED', 'You have already reviewed this product.');
            }
            throw $e;
        }

        Http::created(['review' => [
            'id' => (int) $pdo->lastInsertId(),
            'product_id' => $productId,
            'rating' => $rating,
            'title' => $title,
            'body' => $text,
            'is_verified_purchase' => true,
            'is_approved' => true,
            'created_at' => gmdate('c'),
        ]]);
    }

    Http::error(405, 'METHOD_NOT_ALLOWED', 'Unsupported method for reviews.');
} catch (Throwable $e) {
    Http::fail($e);
}
