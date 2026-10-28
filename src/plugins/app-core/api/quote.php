<?php

declare(strict_types=1);

use AppCore\Auth;
use AppCore\Db;
use AppCore\Http;
use AppCore\Pricing;
use AppCore\Validate;
use AppCore\ValidationException;

require_once __DIR__ . '/_bootstrap.php';

/**
 * POST /api/quote — price a cart before checkout.
 *
 * Body: { coupon_code?, shipping_code?, shipping_address?: {country, region} }
 * Guests are welcome; a signed-in caller gets per-user coupon limits applied.
 *
 * The same Pricing::quote() result is what checkout persists, so what the cart
 * page displays and what the order records are computed by identical code.
 */
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        Http::error(405, 'METHOD_NOT_ALLOWED', 'Use POST for quotes.');
    }

    $identity = Auth::cartIdentity();
    $user = Auth::currentUser();
    $body = Validate::jsonBody();

    try {
        $couponCode = Validate::str($body['coupon_code'] ?? null, 'coupon_code', 1, 64, false);
        $shippingCode = Validate::str($body['shipping_code'] ?? null, 'shipping_code', 1, 64, false);
        $country = Validate::str($body['country'] ?? null, 'country', 2, 2, false) ?? 'US';
        $region = Validate::str($body['region'] ?? null, 'region', 1, 100, false);
    } catch (ValidationException $e) {
        Http::error(400, 'VALIDATION_FAILED', 'Please check the highlighted fields.', $e->fields);
    }

    $pdo = Db::pdo();
    $stmt = $pdo->prepare(
        'SELECT i.unit_price, i.quantity
         FROM ' . Db::table('cart_items') . ' i
         JOIN ' . Db::table('cart') . ' c ON c.id = i.cart_id
         WHERE ' . ($identity['user_id'] !== null ? 'c.user_id = ?' : 'c.session_token = ?') . '
         ORDER BY i.id ASC'
    );
    $stmt->execute([$identity['user_id'] ?? $identity['session_token']]);
    $lines = array_map(static fn (array $r): array => [
        'unit_price' => (string) $r['unit_price'],
        'quantity' => (int) $r['quantity'],
    ], $stmt->fetchAll());

    $quote = Pricing::quote($lines, $country, $region, $couponCode, $shippingCode, $user['id'] ?? null);

    Http::ok(['quote' => $quote]);
} catch (Throwable $e) {
    Http::fail($e);
}
