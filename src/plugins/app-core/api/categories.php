<?php

declare(strict_types=1);

use AppCore\Auth;
use AppCore\Db;
use AppCore\Http;
use AppCore\Slug;
use AppCore\Validate;
use AppCore\ValidationException;

require_once __DIR__ . '/_bootstrap.php';

/**
 * /api/categories.php            — public category tree with product counts
 * /api/categories.php?flat=1     — flat list (for selects)
 * POST   {name, slug?, parent_id?}          — admin create
 * PATCH  {id, name?, slug?, parent_id?}     — admin update
 * DELETE {id}                               — admin delete (products survive via FK SET NULL)
 */
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $pdo = Db::pdo();
    $categories = Db::table('categories');
    $products = Db::table('products');

    $loadAll = static function () use ($pdo, $categories, $products): array {
        $stmt = $pdo->query(
            'SELECT c.id, c.slug, c.name, c.parent_id,
                    (SELECT COUNT(*) FROM ' . $products . ' p WHERE p.category_id = c.id AND p.is_active = TRUE) AS product_count
             FROM ' . $categories . ' c
             ORDER BY c.name ASC'
        );
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'slug' => (string) $r['slug'],
            'name' => (string) $r['name'],
            'parent_id' => $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
            'product_count' => (int) $r['product_count'],
        ], $stmt->fetchAll());
    };

    if ($method === 'POST') {
        Auth::requireAdmin();
        $body = Validate::jsonBody();
        try {
            $name = Validate::str($body['name'] ?? null, 'name', 1, 120);
            $parentId = Validate::int($body['parent_id'] ?? null, 'parent_id', 1, PHP_INT_MAX, false);
            $requestedSlug = Validate::str($body['slug'] ?? null, 'slug', 1, 190, false);
        } catch (ValidationException $e) {
            Http::error(400, 'VALIDATION_FAILED', 'Please check the highlighted fields.', $e->fields);
        }
        \assert($name !== null);

        $slugExists = static function (string $slug) use ($pdo, $categories): bool {
            $check = $pdo->prepare('SELECT 1 FROM ' . $categories . ' WHERE slug = ? LIMIT 1');
            $check->execute([$slug]);
            return $check->fetchColumn() !== false;
        };
        $slug = Slug::unique($requestedSlug ?? $name, $slugExists);

        if ($parentId !== null) {
            $parent = $pdo->prepare('SELECT id FROM ' . $categories . ' WHERE id = ? LIMIT 1');
            $parent->execute([$parentId]);
            if ($parent->fetchColumn() === false) {
                Http::error(400, 'VALIDATION_FAILED', 'Parent category not found.', ['parent_id' => 'not found']);
            }
        }

        $pdo->prepare('INSERT INTO ' . $categories . ' (slug, name, parent_id, created_at, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))')
            ->execute([$slug, $name, $parentId]);

        $id = (int) $pdo->lastInsertId();
        Http::created(['category' => ['id' => $id, 'slug' => $slug, 'name' => $name, 'parent_id' => $parentId, 'product_count' => 0]]);
    }

    if ($method === 'PATCH') {
        Auth::requireAdmin();
        $body = Validate::jsonBody();
        try {
            $id = Validate::int($body['id'] ?? null, 'id', 1, PHP_INT_MAX);
        } catch (ValidationException $e) {
            Http::error(400, 'VALIDATION_FAILED', 'Invalid category id.', $e->fields);
        }
        \assert($id !== null);

        $sets = [];
        $params = [];
        if (array_key_exists('name', $body)) {
            $sets[] = 'name = ?';
            $params[] = Validate::str($body['name'], 'name', 1, 120);
        }
        if (array_key_exists('slug', $body)) {
            $sets[] = 'slug = ?';
            $params[] = Validate::str($body['slug'], 'slug', 1, 190);
        }
        if (array_key_exists('parent_id', $body)) {
            $parentId = Validate::int($body['parent_id'], 'parent_id', 1, PHP_INT_MAX, false);
            if ($parentId === $id) {
                Http::error(400, 'VALIDATION_FAILED', 'A category cannot be its own parent.', ['parent_id' => 'invalid']);
            }
            $sets[] = 'parent_id = ?';
            $params[] = $parentId;
        }
        if ($sets === []) {
            Http::error(400, 'VALIDATION_FAILED', 'Nothing to update.', ['body' => 'provide name, slug or parent_id']);
        }

        $stmt = $pdo->prepare('UPDATE ' . $categories . ' SET ' . implode(', ', $sets) . ', updated_at = UTC_TIMESTAMP(3) WHERE id = ?');
        $params[] = $id;
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) {
            // Either the row is missing, or nothing actually changed.
            $exists = $pdo->prepare('SELECT 1 FROM ' . $categories . ' WHERE id = ? LIMIT 1');
            $exists->execute([$id]);
            if ($exists->fetchColumn() === false) {
                Http::error(404, 'NOT_FOUND', 'Category not found.');
            }
        }

        Http::ok(['category' => ['id' => $id]]);
    }

    if ($method === 'DELETE') {
        Auth::requireAdmin();
        $id = isset($_GET['id']) ? Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX) : null;
        if ($id === null) {
            $body = Validate::jsonBody();
            try {
                $id = Validate::int($body['id'] ?? null, 'id', 1, PHP_INT_MAX);
            } catch (ValidationException $e) {
                Http::error(400, 'VALIDATION_FAILED', 'Invalid category id.', $e->fields);
            }
        }
        \assert($id !== null);

        $stmt = $pdo->prepare('DELETE FROM ' . $categories . ' WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            Http::error(404, 'NOT_FOUND', 'Category not found.');
        }
        Http::ok(['deleted' => true, 'id' => $id]);
    }

    if ($method !== 'GET') {
        Http::error(405, 'METHOD_NOT_ALLOWED', 'Unsupported method for categories.');
    }

    $flat = $loadAll();

    if (isset($_GET['flat'])) {
        Http::ok(['categories' => $flat]);
    }

    // Build the tree in memory: one query, no recursive CTE, and the payload
    // stays identical whether a category is a root or a child.
    $byId = [];
    foreach ($flat as $row) {
        $row['children'] = [];
        $byId[$row['id']] = $row;
    }
    $tree = [];
    foreach ($byId as $row) {
        if ($row['parent_id'] !== null && isset($byId[$row['parent_id']])) {
            $byId[$row['parent_id']]['children'][] = $row;
        } else {
            $tree[] = $row;
        }
    }

    Http::ok(['categories' => $tree, 'total' => count($flat)]);
} catch (Throwable $e) {
    Http::fail($e);
}
