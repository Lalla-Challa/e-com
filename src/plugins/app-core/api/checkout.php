<?php

declare(strict_types=1);

use AppCore\Auth;
use AppCore\Db;
use AppCore\Http;
use AppCore\Mailer;
use AppCore\Money;
use AppCore\Pricing;
use AppCore\Validate;
use AppCore\ValidationException;

require_once __DIR__ . '/_bootstrap.php';

/**
 * POST /api/checkout — single atomic transaction:
 * validate stock → resolve coupon → create order + items → lodge the
 * packaging directive → decrement stock → record redemption → clear cart.
 *
 * The packaging directive is written as a customer-visible order note inside
 * this transaction rather than through a follow-up call to /api/orders:
 * that endpoint requires the signed-in owner, so a guest's directive would
 * silently vanish. Here both guest and signed-in consignments carry it.
 *
 * Pricing is delegated to Pricing::quote(), the same function the storefront
 * calls, so the total displayed in the cart is the total that gets persisted.
 * The coupon row is locked before it is applied, which is what makes a
 * usage-limited code safe under concurrent checkouts.
 */
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        Http::error(405, 'METHOD_NOT_ALLOWED', 'Use POST for checkout.');
    }

    $identity = Auth::cartIdentity();
    $body = Validate::jsonBody();

    // Packaging choices offered on the destination step. The server owns the
    // persisted wording so the record cannot drift from what the UI offers.
    $packagingDirectives = [
        'tube' => 'Continuous tube roll — Rolled on a rigid core to preserve fibre nap and avoid cross-grain creasing. Ideal when pattern matching.',
        'box' => 'Flat archival box — Acid-free Japanese tissue interleaved fold. Compact footprint for smaller lengths and notions.',
    ];

    try {
        $shipping = Validate::address($body['shipping_address'] ?? null, 'shipping_address');
        $billing = isset($body['billing_address']) && $body['billing_address'] !== null
            ? Validate::address($body['billing_address'], 'billing_address')
            : $shipping;
        $email = $identity['user_id'] !== null ? null : Validate::email($body['email'] ?? null);
        $couponCode = Validate::str($body['coupon_code'] ?? null, 'coupon_code', 1, 64, false);
        $shippingCode = Validate::str($body['shipping_code'] ?? null, 'shipping_code', 1, 64, false);
        $packaging = Validate::enum($body['packaging'] ?? null, 'packaging', array_keys($packagingDirectives), false);
    } catch (ValidationException $e) {
        Http::error(400, 'VALIDATION_FAILED', 'Please check the highlighted fields.', $e->fields);
    }
    \assert($shipping !== null && $billing !== null);

    $pdo = Db::pdo();
    $carts = Db::table('cart');
    $cartItems = Db::table('cart_items');
    $products = Db::table('products');
    $orders = Db::table('orders');
    $orderItems = Db::table('order_items');
    $ledger = Db::table('stock_ledger');
    $redemptions = Db::table('coupon_redemptions');
    $users = Db::table('users');
    $notes = Db::table('order_notes');

    $cartStmt = $identity['user_id'] !== null
        ? null
        : $pdo->prepare('SELECT id FROM ' . $carts . ' WHERE session_token = ? LIMIT 1');

    $quote = null;
    $customerId = $identity['user_id'];

    $pdo->beginTransaction();
    try {
        // Lock the cart row (or take a consistent read) so concurrent checkouts
        // of the same cart serialise.
        if ($identity['user_id'] !== null) {
            $stmt = $pdo->prepare('SELECT id FROM ' . $carts . ' WHERE user_id = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([$identity['user_id']]);
        } else {
            \assert($cartStmt !== null);
            $cartStmt->execute([$identity['session_token']]);
            $stmt = $cartStmt;
        }
        $cartRow = $stmt->fetch();

        if ($cartRow === false) {
            $pdo->commit();
            Http::error(422, 'EMPTY_CART', 'Your cart is empty.');
        }
        $cartId = (int) $cartRow['id'];

        $itemStmt = $pdo->prepare(
            'SELECT i.id, i.product_id, i.quantity, i.unit_price, p.stock, p.is_active, p.name
             FROM ' . $cartItems . ' i
             JOIN ' . $products . ' p ON p.id = i.product_id
             WHERE i.cart_id = ?
             ORDER BY i.id ASC
             FOR UPDATE'
        );
        $itemStmt->execute([$cartId]);
        $lines = $itemStmt->fetchAll();

        if ($lines === []) {
            $pdo->commit();
            Http::error(422, 'EMPTY_CART', 'Your cart is empty.');
        }

        $fields = [];
        foreach ($lines as $line) {
            if (!(bool) $line['is_active']) {
                $fields['product_' . $line['product_id']] = 'no longer available';
            } elseif (bccomp((string) $line['stock'], (string) $line['quantity'], 2) < 0) {
                $fields['product_' . $line['product_id']] = 'only ' . $line['stock'] . ' left in stock';
            }
        }
        if ($fields !== []) {
            $pdo->commit();
            Http::error(422, 'STOCK_CONFLICT', 'Some items in your cart are unavailable.', $fields);
        }

        $pricedLines = array_map(static fn (array $l): array => [
            'unit_price' => (string) $l['unit_price'],
            'quantity' => (string) $l['quantity'],
        ], $lines);
        $itemCount = 0.0;
        foreach ($pricedLines as $line) {
            $itemCount += (float) $line['quantity'];
        }

        // Subtotal must be known before a coupon can be judged, so compute it
        // once here and hand the resolved coupon to the quote engine.
        $subtotal = Money::ZERO;
        foreach ($pricedLines as $line) {
            $subtotal = Money::add($subtotal, Money::mul(Money::norm($line['unit_price']), $line['quantity']));
        }

        // Resolve the coupon against the locked cart. FOR UPDATE serialises
        // concurrent redemptions of a usage-limited code: the loser blocks on
        // the row lock, then sees the incremented counter and is rejected.
        $resolved = Pricing::resolveCoupon($couponCode ?? '', $subtotal, $identity['user_id'], true);
        if ($resolved['error'] !== null) {
            $pdo->rollBack();
            Http::error(422, $resolved['error']['code'], $resolved['error']['message']);
        }

        $quote = Pricing::quote(
            $pricedLines,
            $shipping['country'],
            $shipping['region'] ?? null,
            $couponCode,
            $shippingCode,
            $identity['user_id'],
            $resolved
        );

        if ($quote['coupon_error'] !== null) {
            $pdo->rollBack();
            Http::error(422, $quote['coupon_error']['code'], $quote['coupon_error']['message']);
        }

        // Guest checkouts carry the email on the order; signed-in ones look it
        // up so the confirmation has somewhere to go.
        if ($customerId === null) {
            $customerEmail = $email;
            $customerName = $shipping['full_name'];
        } else {
            $userStmt = $pdo->prepare('SELECT email, full_name FROM ' . $users . ' WHERE id = ? LIMIT 1 FOR UPDATE');
            $userStmt->execute([$customerId]);
            $userRow = $userStmt->fetch();
            $customerEmail = $userRow !== false ? (string) $userRow['email'] : null;
            $customerName = $userRow !== false ? (string) $userRow['full_name'] : $shipping['full_name'];
        }

        $now = gmdate('Y-m-d H:i:s.v');
        $insertOrder = $pdo->prepare(
            'INSERT INTO ' . $orders . ' (user_id, status, subtotal, discount, shipping, tax, total, currency,
                                          shipping_address, billing_address, created_at, updated_at,
                                          coupon_id, coupon_code, tax_rate, tax_name,
                                          shipping_method_code, shipping_method_name, item_count, customer_email)
             VALUES (?, \'pending\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                     ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertOrder->execute([
            $customerId,
            $quote['subtotal'],
            $quote['discount'],
            $quote['shipping']['amount'],
            $quote['tax']['amount'],
            $quote['total'],
            $quote['currency'],
            json_encode($shipping, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            json_encode($billing, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $now,
            $now,
            $quote['coupon']['id'] ?? null,
            $quote['coupon']['code'] ?? null,
            $quote['tax']['rate'],
            $quote['tax']['name'],
            $quote['shipping']['code'],
            $quote['shipping']['name'],
            $itemCount,
            $customerEmail,
        ]);
        $orderId = (int) $pdo->lastInsertId();

        // Lodge the packaging directive on the record — guests included. The
        // author is the buyer (null user id is allowed by the schema), and the
        // note is customer-visible so it shows up in the order's trail.
        if ($packaging !== null) {
            $pdo->prepare(
                'INSERT INTO ' . $notes . ' (order_id, author_user_id, author_name, visibility, body, created_at)
                 VALUES (?, ?, ?, \'customer\', ?, ?)'
            )->execute([
                $orderId,
                $customerId,
                $customerName,
                'Packaging directive: ' . $packagingDirectives[$packaging],
                $now,
            ]);
        }

        $insertItem = $pdo->prepare(
            'INSERT INTO ' . $orderItems . ' (order_id, product_id, quantity, unit_price, created_at) VALUES (?, ?, ?, ?, ?)'
        );
        $decStock = $pdo->prepare('UPDATE ' . $products . ' SET stock = stock - ?, updated_at = ? WHERE id = ? AND stock >= ?');
        $ledgerStmt = $pdo->prepare(
            'INSERT INTO ' . $ledger . ' (product_id, delta, reason, reference_id, created_at) VALUES (?, ?, \'purchase\', ?, ?)'
        );

        foreach ($lines as $line) {
            $qty = (string) $line['quantity'];
            $insertItem->execute([$orderId, (int) $line['product_id'], $qty, (string) $line['unit_price'], $now]);
            $decStock->execute([$qty, $now, (int) $line['product_id'], $qty]);
            if ($decStock->rowCount() !== 1) {
                throw new RuntimeException('Stock changed while checking out (product ' . $line['product_id'] . ').');
            }
            $ledgerStmt->execute([(int) $line['product_id'], bcmul($qty, '-1', 2), $orderId, $now]);
        }

        if ($quote['coupon'] !== null) {
            $pdo->prepare(
                'INSERT INTO ' . $redemptions . ' (coupon_id, order_id, user_id, discount, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(3))'
            )->execute([$quote['coupon']['id'], $orderId, $customerId, $quote['coupon']['discount']]);
            $pdo->prepare('UPDATE ' . Db::table('coupons') . ' SET used_count = used_count + 1, updated_at = UTC_TIMESTAMP(3) WHERE id = ?')
                ->execute([$quote['coupon']['id']]);
        }

        $pdo->prepare('DELETE FROM ' . $cartItems . ' WHERE cart_id = ?')->execute([$cartId]);
        $pdo->prepare('UPDATE ' . $carts . ' SET updated_at = ? WHERE id = ?')->execute([$now, $cartId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // Read back outside the write transaction for a clean response shape.
    $orderStmt = $pdo->prepare(
        'SELECT id, status, subtotal, discount, shipping, tax, total, currency, item_count, created_at
         FROM ' . $orders . ' WHERE id = ?'
    );
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch();

    $itemsStmt = $pdo->prepare(
        'SELECT oi.product_id, oi.quantity, oi.unit_price, p.name
         FROM ' . $orderItems . ' oi
         JOIN ' . $products . ' p ON p.id = oi.product_id
         WHERE oi.order_id = ? ORDER BY oi.id ASC'
    );
    $itemsStmt->execute([$orderId]);
    $orderItemsRows = $itemsStmt->fetchAll();

    \assert($order !== false && $quote !== null);
    $orderPayload = [
        'id' => (int) $order['id'],
        'status' => (string) $order['status'],
        'subtotal' => Money::norm($order['subtotal']),
        'discount' => Money::norm($order['discount'] ?? 0),
        'shipping' => Money::norm($order['shipping']),
        'tax' => Money::norm($order['tax']),
        'total' => Money::norm($order['total']),
        'currency' => (string) $order['currency'],
        'item_count' => (int) ($order['item_count'] ?? 0),
        'created_at' => (string) $order['created_at'],
        'coupon' => $quote['coupon'] === null ? null : ['code' => $quote['coupon']['code'], 'discount' => $quote['coupon']['discount']],
        'tax_summary' => $quote['tax'],
        'shipping_summary' => [
            'code' => $quote['shipping']['code'],
            'name' => $quote['shipping']['name'],
            'amount' => $quote['shipping']['amount'],
        ],
        'items' => array_map(static fn (array $r): array => [
            'product_id' => (int) $r['product_id'],
            'name' => (string) $r['name'],
            'quantity' => (float) $r['quantity'],
            'unit_price' => Money::norm($r['unit_price']),
        ], $orderItemsRows),
    ];

    // Queued, not sent — the order is already committed and must not depend on
    // a mail transport being reachable.
    if ($customerEmail !== null && $customerEmail !== '') {
        Mailer::orderPlaced($orderPayload, $customerEmail);
    }

    Http::created(['order' => $orderPayload]);
} catch (Throwable $e) {
    Http::fail($e);
}
