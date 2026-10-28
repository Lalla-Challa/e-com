<?php

declare(strict_types=1);

use AppCore\Auth;
use AppCore\Db;
use AppCore\Http;
use AppCore\Validate;
use AppCore\ValidationException;

require_once __DIR__ . '/_bootstrap.php';

/**
 * Attribute sets (fully configurable product attributes).
 *
 * GET  /api/attributes.php/sets              — products:read: list sets + items
 * GET  /api/attributes.php/sets/{id}         — products:read: single set
 * POST /api/attributes.php/sets              — products:manage: create set
 * POST /api/attributes.php/sets/{id}/items   — products:manage: add attribute item
 * PATCH /api/attributes.php/sets/{id}        — products:manage: update set
 * PATCH /api/attributes.php/sets/{id}/items/{item_id} — products:manage: update item
 * DELETE /api/attributes.php/sets/{id}       — products:manage: delete set
 * DELETE /api/attributes.php/sets/{id}/items/{item_id} — products:manage: delete item
 *
 * POST /api/attributes.php/products/{product_id}/set — products:manage: assign a set to a product
 * DELETE /api/attributes.php/products/{product_id}/set — products:manage: unassign
 */
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $user = Auth::currentUser();
    $pdo = Db::pdo();
    $sets = Db::table('attribute_sets');
    $setItems = Db::table('attribute_set_items');
    $productAttributes = Db::table('product_attributes');
    $products = Db::table('products');
    $now = 'UTC_TIMESTAMP(3)';

    $requireWrite = function () use ($user): void {
        if (!isset($user) || $user === null) {
            Auth::requireUser();
        }
        Auth::requirePermission('products:manage');
    };

    // ---- List sets ---------------------------------------------------------
    if ($method === 'GET' && !isset($_GET['set_id'])) {
        Auth::requirePermission('products:read');
        $rows = $pdo->query(
            'SELECT s.id, s.name, s.description, s.is_active, s.created_at, s.updated_at,
                    COUNT(i.id) AS item_count
             FROM ' . $sets . ' s
             LEFT JOIN ' . $setItems . ' i ON i.set_id = s.id
             GROUP BY s.id
             ORDER BY s.name ASC'
        )->fetchAll();
        Http::ok(['sets' => array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'name' => (string) $r['name'],
                'description' => $r['description'] !== null ? (string) $r['description'] : null,
                'is_active' => (bool) $r['is_active'],
                'item_count' => (int) $r['item_count'],
                'created_at' => (string) $r['created_at'],
                'updated_at' => (string) $r['updated_at'],
            ];
        }, $rows)]);
    }

    // ---- Single set with items --------------------------------------------
    if ($method === 'GET' && isset($_GET['set_id'])) {
        Auth::requirePermission('products:read');
        $setId = Validate::int($_GET['set_id'], 'set_id', 1, PHP_INT_MAX);
        \assert($setId !== null);
        $row = $pdo->prepare('SELECT * FROM ' . $sets . ' WHERE id = ? LIMIT 1');
        $row->execute([$setId]);
        $set = $row->fetch();
        if ($set === false) {
            Http::error(404, 'NOT_FOUND', 'Attribute set not found.');
        }
        $items = $pdo->prepare(
            'SELECT id, attribute_name, position, is_required FROM ' . $setItems . ' WHERE set_id = ? ORDER BY position ASC, id ASC'
        );
        $items->execute([$setId]);
        Http::ok([
            'set' => [
                'id' => (int) $set['id'],
                'name' => (string) $set['name'],
                'description' => $set['description'] !== null ? (string) $set['description'] : null,
                'is_active' => (bool) $set['is_active'],
                'created_at' => (string) $set['created_at'],
                'updated_at' => (string) $set['updated_at'],
            ],
            'items' => array_map(static function (array $r): array {
                return [
                    'id' => (int) $r['id'],
                    'attribute_name' => (string) $r['attribute_name'],
                    'position' => (int) $r['position'],
                    'is_required' => (bool) $r['is_required'],
                ];
            }, $items->fetchAll()),
        ]);
    }

    // ---- Write (products:manage) ------------------------------------------
    if (!isset($user) || $user === null) {
        Auth::requireUser();
    }
    Auth::requirePermission('products:manage');

    $body = Validate::jsonBody();

    if ($method === 'POST' && !isset($_GET['set_id'])) {
        $name = Validate::str($body['name'] ?? null, 'name', 1, 120);
        $description = Validate::str($body['description'] ?? null, 'description', 0, 300, false);
        \assert($name !== null);
        $pdo->prepare(
            'INSERT INTO ' . $sets . ' (name, description, is_active, created_at, updated_at)
             VALUES (?, ?, TRUE, ' . $now . ', ' . $now . ')'
        )->execute([$name, $description]);
        Http::created(['id' => (int) $pdo->lastInsertId(), 'name' => $name]);
    }

    if ($method === 'POST' && isset($_GET['set_id'], $_GET['items'])) {
        $setId = Validate::int($_GET['set_id'], 'set_id', 1, PHP_INT_MAX);
        $attributeName = Validate::str($body['attribute_name'] ?? null, 'attribute_name', 1, 120);
        $position = Validate::int($body['position'] ?? 0, 'position', 0, 1000, false) ?? 0;
        $isRequired = Validate::bool($body['is_required'] ?? true, 'is_required', false) ?? true;
        \assert($setId !== null && $attributeName !== null);
        $pdo->prepare(
            'INSERT INTO ' . $setItems . ' (set_id, attribute_name, position, is_required, created_at)
             VALUES (?, ?, ?, ?, ' . $now . ')'
        )->execute([$setId, $attributeName, $position, $isRequired]);
        Http::created(['id' => (int) $pdo->lastInsertId()]);
    }

    if ($method === 'PATCH' && isset($_GET['set_id'])) {
        $setId = Validate::int($_GET['set_id'], 'set_id', 1, PHP_INT_MAX);
        $name = Validate::str($body['name'] ?? null, 'name', 1, 120, false);
        $description = Validate::str($body['description'] ?? null, 'description', 0, 300, false);
        $isActive = Validate::bool($body['is_active'] ?? null, 'is_active', false);
        \assert($setId !== null);
        $setsql = [];
        $params = [];
        if ($name !== null) { $setsql[] = 'name = ?'; $params[] = $name; }
        if ($description !== null) { $setsql[] = 'description = ?'; $params[] = $description; }
        if ($isActive !== null) { $setsql[] = 'is_active = ?'; $params[] = $isActive ? 'TRUE' : 'FALSE'; }
        $setsql[] = 'updated_at = UTC_TIMESTAMP(3)';
        $params[] = $setId;
        if (count($setsql) > 1) {
            $pdo->prepare('UPDATE ' . $sets . ' SET ' . implode(', ', $setsql) . ' WHERE id = ?')->execute($params);
        }
        Http::ok(['id' => $setId]);
    }

    if ($method === 'PATCH' && isset($_GET['set_id'], $_GET['item_id'])) {
        $itemId = Validate::int($_GET['item_id'], 'item_id', 1, PHP_INT_MAX);
        $attributeName = Validate::str($body['attribute_name'] ?? null, 'attribute_name', 1, 120, false);
        $position = Validate::int($body['position'] ?? null, 'position', 0, 1000, false);
        $isRequired = Validate::bool($body['is_required'] ?? null, 'is_required', false);
        \assert($itemId !== null);
        $setsql = [];
        $params = [];
        if ($attributeName !== null) { $setsql[] = 'attribute_name = ?'; $params[] = $attributeName; }
        if ($position !== null) { $setsql[] = 'position = ?'; $params[] = $position; }
        if ($isRequired !== null) { $setsql[] = 'is_required = ?'; $params[] = $isRequired ? 'TRUE' : 'FALSE'; }
        $setsql[] = 'created_at = UTC_TIMESTAMP(3)';
        $params[] = $itemId;
        if (count($setsql) > 1) {
            $pdo->prepare('UPDATE ' . $setItems . ' SET ' . implode(', ', $setsql) . ' WHERE id = ?')->execute($params);
        }
        Http::ok(['id' => $itemId]);
    }

    if ($method === 'DELETE' && isset($_GET['set_id'])) {
        $setId = Validate::int($_GET['set_id'], 'set_id', 1, PHP_INT_MAX);
        \assert($setId !== null);
        $pdo->prepare('DELETE FROM ' . $sets . ' WHERE id = ?')->execute([$setId]);
        Http::ok(['id' => $setId]);
    }

    if ($method === 'DELETE' && isset($_GET['set_id'], $_GET['item_id'])) {
        $itemId = Validate::int($_GET['item_id'], 'item_id', 1, PHP_INT_MAX);
        \assert($itemId !== null);
        $pdo->prepare('DELETE FROM ' . $setItems . ' WHERE id = ?')->execute([$itemId]);
        Http::ok(['id' => $itemId]);
    }

    // ---- Assign / unassign set to product ---------------------------------
    if ($method === 'POST' && isset($_GET['products'], $_GET['product_id'], $_GET['set'])) {
        $productId = Validate::int($_GET['product_id'], 'product_id', 1, PHP_INT_MAX);
        $setId = Validate::int($body['set_id'] ?? null, 'set_id', 1, PHP_INT_MAX);
        \assert($productId !== null && $setId !== null);
        $pdo->prepare(
            'INSERT INTO ' . $productAttributes . ' (product_id, set_id, created_at)
             VALUES (?, ?, ' . $now . ')
             ON DUPLICATE KEY UPDATE set_id = VALUES(set_id), created_at = ' . $now . ''
        )->execute([$productId, $setId]);
        $pdo->prepare('UPDATE ' . $products . ' SET variant_mode = "variants", updated_at = UTC_TIMESTAMP(3) WHERE id = ?')
            ->execute([$productId]);
        Http::ok(['product_id' => $productId, 'set_id' => $setId]);
    }

    if ($method === 'DELETE' && isset($_GET['products'], $_GET['product_id'], $_GET['set'])) {
        $productId = Validate::int($_GET['product_id'], 'product_id', 1, PHP_INT_MAX);
        \assert($productId !== null);
        $pdo->prepare('DELETE FROM ' . $productAttributes . ' WHERE product_id = ?')->execute([$productId]);
        $pdo->prepare('UPDATE ' . $products . ' SET variant_mode = "none", updated_at = UTC_TIMESTAMP(3) WHERE id = ?')
            ->execute([$productId]);
        Http::ok(['product_id' => $productId]);
    }

    Http::error(405, 'METHOD_NOT_ALLOWED', 'Unsupported method for attributes.');
} catch (Throwable $e) {
    Http::fail($e);
}
