<?php

declare(strict_types=1);

use AppCore\Auth;
use AppCore\Db;
use AppCore\Http;
use AppCore\Validate;
use AppCore\ValidationException;

require_once __DIR__ . '/_bootstrap.php';

/**
 * GET  /api/shipping.php               — settings:read: list zones + methods
 * GET  /api/shipping.php?calculate=1&country=XX&region=YY&weight_kg=N&subtotal=M
 *                                       — settings:read: applicable methods + cost
 * POST /api/shipping.php/zones         — settings:manage: create zone
 * POST /api/shipping.php/methods       — settings:manage: create method
 * PATCH /api/shipping.php/zones/{id}   — settings:manage: update zone
 * PATCH /api/shipping.php/methods/{id} — settings:manage: update method
 * DELETE /api/shipping.php/zones/{id}  — settings:manage: delete zone
 * DELETE /api/shipping.php/methods/{id}— settings:manage: delete method
 */
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $user = Auth::currentUser();
    $pdo = Db::pdo();
    $zones = Db::table('shipping_zones');
    $methods = Db::table('shipping_methods');

    // ---- Calculate applicable shipping methods -----------------------------
    if ($method === 'GET' && isset($_GET['calculate'])) {
        Auth::requirePermission('settings:read');
        $country = Validate::str($_GET['country'] ?? null, 'country', 2, 2);
        $region = Validate::str($_GET['region'] ?? null, 'region', 0, 100, false);
        $weightKg = Validate::decimal($_GET['weight_kg'] ?? '0', 'weight_kg', 0, 10000);
        $subtotal = Validate::decimal($_GET['subtotal'] ?? '0', 'subtotal', 0, 1000000);
        \assert($country !== null && $weightKg !== null && $subtotal !== null);

        // Resolve zone: exact country+region match in default zone first, then
        // any zone containing the country, then the default zone.
        $zoneId = null;
        $stmt = $pdo->prepare(
            'SELECT id FROM ' . $zones . '
             WHERE JSON_CONTAINS(countries, ?)
               AND (regions IS NULL OR JSON_CONTAINS(regions, ?))
             ORDER BY is_default DESC, id ASC
             LIMIT 1'
        );
        $countryJson = json_encode($country);
        $regionJson = $region !== null ? json_encode($region) : null;
        if ($regionJson !== null) {
            $stmt->execute([$countryJson, $regionJson]);
        } else {
            $stmt->execute([$countryJson, 'null']);
        }
        $zoneId = $stmt->fetchColumn();

        if ($zoneId === false) {
            $stmt = $pdo->prepare('SELECT id FROM ' . $zones . ' WHERE is_default = TRUE LIMIT 1');
            $stmt->execute();
            $zoneId = $stmt->fetchColumn();
        }

        if ($zoneId === false) {
            Http::ok(['methods' => [], 'zone' => null]);
        }

        $zone = $pdo->prepare('SELECT id, name FROM ' . $zones . ' WHERE id = ?');
        $zone->execute([$zoneId]);
        $zoneRow = $zone->fetch();

        $methodsRows = $pdo->prepare(
            'SELECT id, code, name, type, base_cost, cost_per_kg, min_order_free, max_weight_kg
             FROM ' . $methods . ' WHERE zone_id = ? AND is_active = TRUE ORDER BY sort_order ASC, id ASC'
        );
        $methodsRows->execute([$zoneId]);

        $out = [];
        foreach ($methodsRows->fetchAll() as $m) {
            $weight = (float) $weightKg;
            $sub = (float) $subtotal;
            $cost = '0.00';

            if ((string) $m['type'] === 'free') {
                if ($sub >= (float) $m['min_order_free']) {
                    $cost = '0.00';
                } else {
                    continue; // free not applicable
                }
            } elseif ((string) $m['type'] === 'flat_rate') {
                $cost = number_format((float) $m['base_cost'], 2, '.', '');
            } elseif ((string) $m['type'] === 'weight_based') {
                $cost = number_format((float) $m['base_cost'] + ($weight * (float) $m['cost_per_kg']), 2, '.', '');
                if ($m['max_weight_kg'] !== null && $weight > (float) $m['max_weight_kg']) {
                    continue; // exceeds weight cap
                }
            }

            $out[] = [
                'id' => (int) $m['id'],
                'code' => (string) $m['code'],
                'name' => (string) $m['name'],
                'type' => (string) $m['type'],
                'cost' => $cost,
            ];
        }

        Http::ok([
            'zone' => ['id' => (int) $zoneRow['id'], 'name' => (string) $zoneRow['name']],
            'methods' => $out,
        ]);
    }

    // ---- List zones + methods ----------------------------------------------
    if ($method === 'GET') {
        Auth::requirePermission('settings:read');
        $zoneRows = $pdo->query('SELECT id, name, countries, regions, is_default, created_at, updated_at FROM ' . $zones . ' ORDER BY is_default DESC, name ASC')->fetchAll();
        $methodRows = $pdo->query('SELECT id, zone_id, code, name, type, base_cost, cost_per_kg, min_order_free, max_weight_kg, sort_order, is_active, created_at, updated_at FROM ' . $methods . ' ORDER BY zone_id ASC, sort_order ASC, id ASC')->fetchAll();

        Http::ok([
            'zones' => array_map(static function (array $r): array {
                return [
                    'id' => (int) $r['id'],
                    'name' => (string) $r['name'],
                    'countries' => json_decode((string) ($r['countries'] ?? '[]'), true) ?? [],
                    'regions' => $r['regions'] !== null ? json_decode((string) $r['regions'], true) ?? [] : null,
                    'is_default' => (bool) $r['is_default'],
                    'created_at' => (string) $r['created_at'],
                    'updated_at' => (string) $r['updated_at'],
                ];
            }, $zoneRows),
            'methods' => array_map(static function (array $r): array {
                return [
                    'id' => (int) $r['id'],
                    'zone_id' => (int) $r['zone_id'],
                    'code' => (string) $r['code'],
                    'name' => (string) $r['name'],
                    'type' => (string) $r['type'],
                    'base_cost' => (string) $r['base_cost'],
                    'cost_per_kg' => (string) $r['cost_per_kg'],
                    'min_order_free' => (string) $r['min_order_free'],
                    'max_weight_kg' => $r['max_weight_kg'] !== null ? (string) $r['max_weight_kg'] : null,
                    'sort_order' => (int) $r['sort_order'],
                    'is_active' => (bool) $r['is_active'],
                    'created_at' => (string) $r['created_at'],
                    'updated_at' => (string) $r['updated_at'],
                ];
            }, $methodRows),
        ]);
    }

    // ---- Write endpoints (settings:manage) ---------------------------------
    if (!isset($user) || $user === null) {
        Auth::requireUser(); // 401
    }
    Auth::requirePermission('settings:manage');

    $body = Validate::jsonBody();

    if ($method === 'POST' && isset($_GET['zones'])) {
        $name = Validate::str($body['name'] ?? null, 'name', 1, 120);
        $countries = $body['countries'] ?? null;
        if (!is_array($countries) || $countries === []) {
            Http::error(400, 'VALIDATION_FAILED', '"countries" must be a non-empty array of ISO codes.', ['countries' => 'required']);
        }
        $regions = $body['regions'] ?? null;
        $isDefault = Validate::bool($body['is_default'] ?? false, 'is_default', false) ?? false;
        \assert($name !== null);

        if ($isDefault) {
            $pdo->prepare('UPDATE ' . $zones . ' SET is_default = FALSE')->execute();
        }

        $pdo->prepare(
            'INSERT INTO ' . $zones . ' (name, countries, regions, is_default, created_at, updated_at)
             VALUES (?, ?, ?, ?, ' . 'UTC_TIMESTAMP(3)' . ', ' . 'UTC_TIMESTAMP(3)' . ')'
        )->execute([$name, json_encode($countries), $regions !== null ? json_encode($regions) : null, $isDefault]);
        Http::created(['id' => (int) $pdo->lastInsertId()]);
    }

    if ($method === 'POST' && isset($_GET['methods'])) {
        $zoneId = Validate::int($body['zone_id'] ?? null, 'zone_id', 1, PHP_INT_MAX);
        $code = Validate::str($body['code'] ?? null, 'code', 1, 64);
        $name = Validate::str($body['name'] ?? null, 'name', 1, 120);
        $type = Validate::enum($body['type'] ?? 'flat_rate', 'type', ['flat_rate', 'weight_based', 'free']);
        $baseCost = Validate::decimal($body['base_cost'] ?? '0.00', 'base_cost', 0, 1000000);
        $costPerKg = Validate::decimal($body['cost_per_kg'] ?? '0.00', 'cost_per_kg', 0, 1000000);
        $minOrderFree = Validate::decimal($body['min_order_free'] ?? '0.00', 'min_order_free', 0, 1000000);
        $maxWeightKg = Validate::decimal($body['max_weight_kg'] ?? null, 'max_weight_kg', 0, 10000, false);
        $sortOrder = Validate::int($body['sort_order'] ?? 0, 'sort_order', 0, 10000, false) ?? 0;
        $isActive = Validate::bool($body['is_active'] ?? true, 'is_active', false) ?? true;
        \assert($zoneId !== null && $code !== null && $name !== null && $type !== null && $baseCost !== null);

        $pdo->prepare(
            'INSERT INTO ' . $methods . ' (zone_id, code, name, type, base_cost, cost_per_kg, min_order_free, max_weight_kg, sort_order, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ' . 'UTC_TIMESTAMP(3)' . ', ' . 'UTC_TIMESTAMP(3)' . ')'
        )->execute([$zoneId, $code, $name, $type, $baseCost, $costPerKg, $minOrderFree, $maxWeightKg, $sortOrder, $isActive]);
        Http::created(['id' => (int) $pdo->lastInsertId()]);
    }

    if ($method === 'PATCH' && isset($_GET['zones'], $_GET['id'])) {
        $id = Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX);
        $name = Validate::str($body['name'] ?? null, 'name', 1, 120, false);
        $isDefault = Validate::bool($body['is_default'] ?? null, 'is_default', false);
        \assert($id !== null);
        $sets = [];
        $params = [];
        if ($name !== null) {
            $sets[] = 'name = ?';
            $params[] = $name;
        }
        if ($isDefault !== null) {
            if ($isDefault) {
                $pdo->prepare('UPDATE ' . $zones . ' SET is_default = FALSE')->execute();
            }
            $sets[] = 'is_default = ?';
            $params[] = $isDefault ? 'TRUE' : 'FALSE';
        }
        $sets[] = 'updated_at = UTC_TIMESTAMP(3)';
        $params[] = $id;
        if (count($sets) > 1) {
            $pdo->prepare('UPDATE ' . $zones . ' SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        }
        Http::ok(['id' => $id]);
    }

    if ($method === 'PATCH' && isset($_GET['methods'], $_GET['id'])) {
        $id = Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX);
        $name = Validate::str($body['name'] ?? null, 'name', 1, 120, false);
        $type = Validate::enum($body['type'] ?? null, 'type', ['flat_rate', 'weight_based', 'free'], false);
        $baseCost = Validate::decimal($body['base_cost'] ?? null, 'base_cost', 0, 1000000, false);
        $isActive = Validate::bool($body['is_active'] ?? null, 'is_active', false);
        \assert($id !== null);
        $sets = [];
        $params = [];
        if ($name !== null) { $sets[] = 'name = ?'; $params[] = $name; }
        if ($type !== null) { $sets[] = 'type = ?'; $params[] = $type; }
        if ($baseCost !== null) { $sets[] = 'base_cost = ?'; $params[] = $baseCost; }
        if ($isActive !== null) { $sets[] = 'is_active = ?'; $params[] = $isActive ? 'TRUE' : 'FALSE'; }
        $sets[] = 'updated_at = UTC_TIMESTAMP(3)';
        $params[] = $id;
        if (count($sets) > 1) {
            $pdo->prepare('UPDATE ' . $methods . ' SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        }
        Http::ok(['id' => $id]);
    }

    if ($method === 'DELETE' && isset($_GET['zones'], $_GET['id'])) {
        $id = Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX);
        \assert($id !== null);
        $pdo->prepare('DELETE FROM ' . $zones . ' WHERE id = ?')->execute([$id]);
        Http::ok(['id' => $id]);
    }

    if ($method === 'DELETE' && isset($_GET['methods'], $_GET['id'])) {
        $id = Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX);
        \assert($id !== null);
        $pdo->prepare('DELETE FROM ' . $methods . ' WHERE id = ?')->execute([$id]);
        Http::ok(['id' => $id]);
    }

    Http::error(405, 'METHOD_NOT_ALLOWED', 'Unsupported method for shipping.');
} catch (Throwable $e) {
    Http::fail($e);
}
