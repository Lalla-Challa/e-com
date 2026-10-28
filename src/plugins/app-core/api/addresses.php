<?php

declare(strict_types=1);

use AppCore\Auth;
use AppCore\Db;
use AppCore\Http;
use AppCore\Validate;
use AppCore\ValidationException;

require_once __DIR__ . '/_bootstrap.php';

/**
 * /api/addresses — signed-in customer address book.
 * GET (list), POST (create), PATCH {id, ...} (update), DELETE {id}.
 *
 * Every statement is scoped by user_id in the SQL itself, so a crafted id can
 * never reach another customer's row.
 */
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $user = Auth::requireUser();
    $pdo = Db::pdo();
    $table = Db::table('customer_addresses');

    $present = static fn (array $r): array => [
        'id' => (int) $r['id'],
        'label' => $r['label'] !== null ? (string) $r['label'] : null,
        'full_name' => (string) $r['full_name'],
        'line1' => (string) $r['line1'],
        'line2' => $r['line2'] !== null ? (string) $r['line2'] : null,
        'city' => (string) $r['city'],
        'region' => $r['region'] !== null ? (string) $r['region'] : null,
        'postal_code' => (string) $r['postal_code'],
        'country' => (string) $r['country'],
        'phone' => $r['phone'] !== null ? (string) $r['phone'] : null,
        'is_default_shipping' => (bool) $r['is_default_shipping'],
        'is_default_billing' => (bool) $r['is_default_billing'],
    ];

    $clearDefaults = static function (string $column) use ($pdo, $table, $user): void {
        $pdo->prepare('UPDATE ' . $table . ' SET ' . $column . ' = FALSE, updated_at = UTC_TIMESTAMP(3) WHERE user_id = ?')
            ->execute([$user['id']]);
    };

    if ($method === 'GET') {
        $stmt = $pdo->prepare(
            'SELECT * FROM ' . $table . ' WHERE user_id = ?
             ORDER BY is_default_shipping DESC, is_default_billing DESC, updated_at DESC'
        );
        $stmt->execute([$user['id']]);
        Http::ok(['addresses' => array_map($present, $stmt->fetchAll())]);
    }

    if ($method === 'POST') {
        $body = Validate::jsonBody();
        try {
            $address = Validate::address($body, 'address');
            $label = Validate::str($body['label'] ?? null, 'label', 1, 60, false);
            $isDefaultShipping = Validate::bool($body['is_default_shipping'] ?? false, 'is_default_shipping', false) ?? false;
            $isDefaultBilling = Validate::bool($body['is_default_billing'] ?? false, 'is_default_billing', false) ?? false;
        } catch (ValidationException $e) {
            Http::error(400, 'VALIDATION_FAILED', 'Please check the highlighted fields.', $e->fields);
        }
        \assert($address !== null);

        $pdo->beginTransaction();
        try {
            if ($isDefaultShipping) {
                $clearDefaults('is_default_shipping');
            }
            if ($isDefaultBilling) {
                $clearDefaults('is_default_billing');
            }
            $pdo->prepare(
                'INSERT INTO ' . $table . ' (user_id, label, full_name, line1, line2, city, region, postal_code, country, phone, is_default_shipping, is_default_billing, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))'
            )->execute([
                $user['id'], $label,
                $address['full_name'], $address['line1'], $address['line2'] ?? null,
                $address['city'], $address['region'] ?? null, $address['postal_code'],
                $address['country'], $address['phone'] ?? null,
                $isDefaultShipping ? 1 : 0, $isDefaultBilling ? 1 : 0,
            ]);
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $stmt = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$id, $user['id']]);
        Http::created(['address' => $present($stmt->fetch(\PDO::FETCH_ASSOC) ?: [])]);
    }

    if ($method === 'PATCH') {
        $body = Validate::jsonBody();
        try {
            $id = Validate::int($body['id'] ?? null, 'id', 1, PHP_INT_MAX);
        } catch (ValidationException $e) {
            Http::error(400, 'VALIDATION_FAILED', 'Invalid address id.', $e->fields);
        }
        \assert($id !== null);

        $sets = [];
        $params = [];
        foreach (['label' => [1, 60], 'full_name' => [1, 120], 'line1' => [1, 200], 'line2' => [0, 200], 'city' => [1, 100], 'region' => [0, 100], 'postal_code' => [1, 20], 'phone' => [0, 30]] as $key => [$min, $max]) {
            if (array_key_exists($key, $body)) {
                $sets[] = $key . ' = ?';
                $params[] = Validate::str($body[$key], $key, $min, $max, $min > 0);
            }
        }
        if (array_key_exists('country', $body)) {
            $sets[] = 'country = ?';
            $params[] = strtoupper((string) Validate::str($body['country'], 'country', 2, 2));
        }

        $pdo->beginTransaction();
        try {
            foreach (['is_default_shipping', 'is_default_billing'] as $flag) {
                if (array_key_exists($flag, $body) && Validate::bool($body[$flag], $flag) === true) {
                    $clearDefaults($flag);
                }
            }
            if ($sets === [] && !array_key_exists('is_default_shipping', $body) && !array_key_exists('is_default_billing', $body)) {
                $pdo->rollBack();
                Http::error(400, 'VALIDATION_FAILED', 'Nothing to update.', ['body' => 'provide at least one field']);
            }
            foreach (['is_default_shipping' => 'is_default_shipping', 'is_default_billing' => 'is_default_billing'] as $flag => $column) {
                if (array_key_exists($flag, $body)) {
                    $sets[] = $column . ' = ?';
                    $params[] = Validate::bool($body[$flag], $flag) ? 1 : 0;
                }
            }

            $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ', updated_at = UTC_TIMESTAMP(3) WHERE id = ? AND user_id = ?';
            $stmt = $pdo->prepare($sql);
            $params[] = $id;
            $params[] = $user['id'];
            $stmt->execute($params);
            $affected = $stmt->rowCount();

            if ($affected === 0) {
                $exists = $pdo->prepare('SELECT 1 FROM ' . $table . ' WHERE id = ? AND user_id = ? LIMIT 1');
                $exists->execute([$id, $user['id']]);
                if ($exists->fetchColumn() === false) {
                    $pdo->rollBack();
                    Http::error(404, 'NOT_FOUND', 'Address not found.');
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Http::ok(['address' => ['id' => $id]]);
    }

    if ($method === 'DELETE') {
        $id = isset($_GET['id']) ? Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX) : null;
        if ($id === null) {
            $body = Validate::jsonBody();
            try {
                $id = Validate::int($body['id'] ?? null, 'id', 1, PHP_INT_MAX);
            } catch (ValidationException $e) {
                Http::error(400, 'VALIDATION_FAILED', 'Invalid address id.', $e->fields);
            }
        }
        \assert($id !== null);

        $stmt = $pdo->prepare('DELETE FROM ' . $table . ' WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $user['id']]);
        if ($stmt->rowCount() === 0) {
            Http::error(404, 'NOT_FOUND', 'Address not found.');
        }
        Http::ok(['deleted' => true, 'id' => $id]);
    }

    Http::error(405, 'METHOD_NOT_ALLOWED', 'Unsupported method for addresses.');
} catch (Throwable $e) {
    Http::fail($e);
}
