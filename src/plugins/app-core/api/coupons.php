<?php

declare(strict_types=1);

use AppCore\Auth;
use AppCore\Db;
use AppCore\Http;
use AppCore\Money;
use AppCore\Pricing;
use AppCore\Slug;
use AppCore\Validate;
use AppCore\ValidationException;

require_once __DIR__ . '/_bootstrap.php';

/**
 * /api/coupons.php                              — admin listing
 * /api/coupons.php?code=X&subtotal=Y — public code validation
 * POST   {code?, type, value, ...}   — admin create
 * PATCH  {id, ...}                   — admin update
 * DELETE {id}                        — admin delete (redemptions survive via CASCADE only on the coupon side)
 */
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $pdo = Db::pdo();
    $coupons = Db::table('coupons');

    $present = static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'type' => (string) $row['type'],
            'value' => Money::norm($row['value']),
            'min_subtotal' => Money::norm($row['min_subtotal']),
            'max_uses' => $row['max_uses'] !== null ? (int) $row['max_uses'] : null,
            'used_count' => (int) $row['used_count'],
            'per_user_limit' => $row['per_user_limit'] !== null ? (int) $row['per_user_limit'] : null,
            'starts_at' => $row['starts_at'] !== null ? (string) $row['starts_at'] : null,
            'ends_at' => $row['ends_at'] !== null ? (string) $row['ends_at'] : null,
            'is_active' => (bool) $row['is_active'],
        ];
    };

    // ---- Public validation: can this code apply to this subtotal? ----------
    if ($method === 'GET' && isset($_GET['code'])) {
        $code = Validate::str($_GET['code'], 'code', 1, 64);
        $subtotal = Validate::decimal($_GET['subtotal'] ?? '0', 'subtotal', 0, 99999999, false) ?? '0.00';
        $user = Auth::currentUser();
        \assert($code !== null);

        $coupon = Pricing::findCoupon($code);
        if ($coupon === null) {
            Http::error(404, 'COUPON_NOT_FOUND', 'That code is not valid.');
        }
        \assert($coupon !== null);

        $reason = Pricing::couponBlockedReason($coupon, $subtotal, $user['id'] ?? null);
        $discount = $reason === null ? Pricing::couponDiscount($coupon, $subtotal) : Money::ZERO;

        Http::ok([
            'valid' => $reason === null,
            'error' => $reason,
            'coupon' => [
                'code' => (string) $coupon['code'],
                'type' => (string) $coupon['type'],
                'value' => Money::norm($coupon['value']),
                'discount' => $discount,
                'min_subtotal' => Money::norm($coupon['min_subtotal']),
            ],
        ]);
    }

    if ($method === 'POST') {
        Auth::requireAdmin();
        $body = Validate::jsonBody();
        try {
            $type = Validate::enum($body['type'] ?? null, 'type', ['percent', 'fixed']);
            $value = Validate::decimal($body['value'] ?? null, 'value', 0, 99999999);
            $requestedCode = Validate::str($body['code'] ?? null, 'code', 2, 64, false);
            $minSubtotal = Validate::decimal($body['min_subtotal'] ?? '0', 'min_subtotal', 0, 99999999, false) ?? '0.00';
            $maxUses = Validate::int($body['max_uses'] ?? null, 'max_uses', 1, 1000000, false);
            $perUserLimit = Validate::int($body['per_user_limit'] ?? null, 'per_user_limit', 1, 100000, false);
            $startsAt = Validate::str($body['starts_at'] ?? null, 'starts_at', 1, 40, false);
            $endsAt = Validate::str($body['ends_at'] ?? null, 'ends_at', 1, 40, false);
            $isActive = Validate::bool($body['is_active'] ?? true, 'is_active', false) ?? true;
        } catch (ValidationException $e) {
            Http::error(400, 'VALIDATION_FAILED', 'Please check the highlighted fields.', $e->fields);
        }
        \assert($type !== null && $value !== null);

        if ($type === 'percent' && (float) $value > 100) {
            Http::error(400, 'VALIDATION_FAILED', 'Percentage discounts cannot exceed 100.', ['value' => 'must be between 0 and 100']);
        }

        $exists = static function (string $code) use ($pdo, $coupons): bool {
            $check = $pdo->prepare('SELECT 1 FROM ' . $coupons . ' WHERE code = ? LIMIT 1');
            $check->execute([$code]);
            return $check->fetchColumn() !== false;
        };
        $code = Slug::unique(strtoupper($requestedCode ?? 'COUPON'), static fn (string $s): bool => $exists(strtoupper($s)), 64);
        $code = strtoupper($code);

        $pdo->prepare(
            'INSERT INTO ' . $coupons . ' (code, type, value, min_subtotal, max_uses, used_count, per_user_limit, starts_at, ends_at, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))'
        )->execute([
            $code, $type, $value, $minSubtotal, $maxUses, $perUserLimit,
            $startsAt, $endsAt, $isActive ? 1 : 0,
        ]);

        $id = (int) $pdo->lastInsertId();
        Http::created(['coupon' => $present([
            'id' => $id, 'code' => $code, 'type' => $type, 'value' => $value, 'min_subtotal' => $minSubtotal,
            'max_uses' => $maxUses, 'used_count' => 0, 'per_user_limit' => $perUserLimit,
            'starts_at' => $startsAt, 'ends_at' => $endsAt, 'is_active' => $isActive,
        ])]);
    }

    if ($method === 'PATCH') {
        Auth::requireAdmin();
        $body = Validate::jsonBody();
        try {
            $id = Validate::int($body['id'] ?? null, 'id', 1, PHP_INT_MAX);
        } catch (ValidationException $e) {
            Http::error(400, 'VALIDATION_FAILED', 'Invalid coupon id.', $e->fields);
        }
        \assert($id !== null);

        $sets = [];
        $params = [];
        if (array_key_exists('value', $body)) {
            $value = Validate::decimal($body['value'], 'value', 0, 99999999);
            $sets[] = 'value = ?';
            $params[] = $value;
        }
        if (array_key_exists('min_subtotal', $body)) {
            $sets[] = 'min_subtotal = ?';
            $params[] = Validate::decimal($body['min_subtotal'], 'min_subtotal', 0, 99999999);
        }
        if (array_key_exists('max_uses', $body)) {
            $sets[] = 'max_uses = ?';
            $params[] = Validate::int($body['max_uses'], 'max_uses', 1, 1000000, false);
        }
        if (array_key_exists('per_user_limit', $body)) {
            $sets[] = 'per_user_limit = ?';
            $params[] = Validate::int($body['per_user_limit'], 'per_user_limit', 1, 100000, false);
        }
        if (array_key_exists('is_active', $body)) {
            $sets[] = 'is_active = ?';
            $params[] = Validate::bool($body['is_active'], 'is_active') ? 1 : 0;
        }
        if (array_key_exists('starts_at', $body)) {
            $sets[] = 'starts_at = ?';
            $params[] = Validate::str($body['starts_at'], 'starts_at', 1, 40, false);
        }
        if (array_key_exists('ends_at', $body)) {
            $sets[] = 'ends_at = ?';
            $params[] = Validate::str($body['ends_at'], 'ends_at', 1, 40, false);
        }
        if ($sets === []) {
            Http::error(400, 'VALIDATION_FAILED', 'Nothing to update.', ['body' => 'provide value, min_subtotal, max_uses, per_user_limit, is_active, starts_at or ends_at']);
        }

        $stmt = $pdo->prepare('UPDATE ' . $coupons . ' SET ' . implode(', ', $sets) . ', updated_at = UTC_TIMESTAMP(3) WHERE id = ?');
        $params[] = $id;
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) {
            $exists = $pdo->prepare('SELECT 1 FROM ' . $coupons . ' WHERE id = ? LIMIT 1');
            $exists->execute([$id]);
            if ($exists->fetchColumn() === false) {
                Http::error(404, 'NOT_FOUND', 'Coupon not found.');
            }
        }

        Http::ok(['coupon' => ['id' => $id]]);
    }

    if ($method === 'DELETE') {
        Auth::requireAdmin();
        $id = isset($_GET['id']) ? Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX) : null;
        if ($id === null) {
            $body = Validate::jsonBody();
            try {
                $id = Validate::int($body['id'] ?? null, 'id', 1, PHP_INT_MAX);
            } catch (ValidationException $e) {
                Http::error(400, 'VALIDATION_FAILED', 'Invalid coupon id.', $e->fields);
            }
        }
        \assert($id !== null);

        // A coupon that has actually been redeemed is disabled rather than
        // deleted, so the redemption history keeps a resolvable parent row.
        $used = $pdo->prepare('SELECT COUNT(*) FROM ' . Db::table('coupon_redemptions') . ' WHERE coupon_id = ?');
        $used->execute([$id]);
        if ((int) $used->fetchColumn() > 0) {
            $pdo->prepare('UPDATE ' . $coupons . ' SET is_active = FALSE, updated_at = UTC_TIMESTAMP(3) WHERE id = ?')->execute([$id]);
            Http::ok(['deactivated' => true, 'id' => $id]);
        }

        $stmt = $pdo->prepare('DELETE FROM ' . $coupons . ' WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            Http::error(404, 'NOT_FOUND', 'Coupon not found.');
        }
        Http::ok(['deleted' => true, 'id' => $id]);
    }

    // ---- Admin listing ----------------------------------------------------
    if ($method !== 'GET') {
        Http::error(405, 'METHOD_NOT_ALLOWED', 'Unsupported method for coupons.');
    }
    Auth::requireAdmin();

    $page = Validate::int($_GET['page'] ?? 1, 'page', 1, 100000) ?? 1;
    $perPage = Validate::int($_GET['per_page'] ?? 50, 'per_page', 1, 200) ?? 50;
    $q = isset($_GET['q']) ? Validate::str($_GET['q'], 'q', 1, 64) : null;

    $where = '1=1';
    $params = [];
    if ($q !== null) {
        $where .= ' AND code LIKE ?';
        $params[] = '%' . $q . '%';
    }

    $count = $pdo->prepare('SELECT COUNT(*) FROM ' . $coupons . ' WHERE ' . $where);
    $count->execute($params);
    $total = (int) $count->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT * FROM ' . $coupons . ' WHERE ' . $where . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
    );
    $stmt->execute($params);

    Http::ok(['coupons' => array_map($present, $stmt->fetchAll())], ['page' => $page, 'per_page' => $perPage, 'total' => $total]);
} catch (Throwable $e) {
    Http::fail($e);
}
