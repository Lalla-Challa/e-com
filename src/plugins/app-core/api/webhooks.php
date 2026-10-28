<?php

declare(strict_types=1);

use AppCore\Db;
use AppCore\Http;

require_once __DIR__ . '/_bootstrap.php';

/**
 * Payment-provider webhook receiver. Keeps the store's no-WP-boot guarantee:
 * signature verification happens before any DB write; failures return 400.
 *
 * Contract:
 *   X-Timestamp: unix seconds, at most 300s from server time (replay window)
 *   X-Signature: hex hmac-sha256 of "<X-Timestamp>.<raw body>" with SECRET
 *   body:        {"event": "payment.succeeded", "order_id": int, ...}
 * Redelivery inside the window is safe: the status transition only applies to
 * pending orders, so a replayed event is an idempotent no-op.
 *
 * Configure SECRET as the WEBHOOK_SECRET environment variable (same value as
 * the one configured at the payment provider). Unset = webhooks disabled.
 */
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        Http::error(405, 'METHOD_NOT_ALLOWED', 'Webhooks accept POST only.');
    }

    $secret = AppCore\Env::get('WEBHOOK_SECRET');
    if ($secret === null || $secret === '') {
        Http::error(404, 'NOT_FOUND', 'Unknown endpoint.');
    }

    $raw = file_get_contents('php://input');
    if (!\is_string($raw) || $raw === '') {
        Http::error(400, 'VALIDATION_FAILED', 'Empty payload.');
    }

    $timestamp = $_SERVER['HTTP_X_TIMESTAMP'] ?? '';
    if (!\is_string($timestamp) || preg_match('/^\d{10}$/', $timestamp) !== 1) {
        Http::error(400, 'INVALID_TIMESTAMP', 'X-Timestamp header (unix seconds) is required.');
    }
    $skew = time() - (int) $timestamp;
    if ($skew < -300 || $skew > 300) {
        Http::error(400, 'STALE_TIMESTAMP', 'Webhook timestamp outside the ±300s acceptance window.');
    }

    $header = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
    $expected = hash_hmac('sha256', $timestamp . '.' . $raw, $secret);
    if (!\is_string($header) || !hash_equals($expected, $header)) {
        Http::error(400, 'INVALID_SIGNATURE', 'Signature verification failed.');
    }

    $event = json_decode($raw, true);
    if (!\is_array($event) || !\is_string($event['event'] ?? null) || !isset($event['order_id'])) {
        Http::error(400, 'VALIDATION_FAILED', 'Unrecognised webhook payload.');
    }

    $pdo = Db::pdo();
    $orders = Db::table('orders');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, status FROM ' . $orders . ' WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([(int) $event['order_id']]);
        $order = $stmt->fetch();

        if ($order === false) {
            $pdo->rollBack();
            Http::error(404, 'NOT_FOUND', 'Order not found.');
        }

        $status = (string) $order['status'];
        $newStatus = match ($event['event']) {
            'payment.succeeded' => 'paid',
            'payment.refunded' => 'refunded',
            default => null,
        };

        if ($newStatus !== null && $status === 'pending') {
            $pdo->prepare('UPDATE ' . $orders . ' SET status = ?, updated_at = UTC_TIMESTAMP(3) WHERE id = ?')
                ->execute([$newStatus, (int) $order['id']]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    Http::ok(['received' => true]);
} catch (Throwable $e) {
    Http::fail($e);
}
