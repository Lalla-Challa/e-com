<?php

declare(strict_types=1);

namespace AppCore;

/**
 * Transactional email, queued rather than sent.
 *
 * Messages are written to app_email_outbox inside the request that triggered
 * them; delivery is somebody else's problem (a cron worker, an SMTP provider, a
 * dev console). Callers never wait on a network hop and a failed provider can
 * never roll back an order that already succeeded.
 */
final class Mailer
{
    /** @param array<string, mixed> $context */
    public static function enqueue(
        string $template,
        string $toEmail,
        string $subject,
        string $text,
        array $context = [],
        ?string $toName = null,
        ?string $html = null
    ): void {
        try {
            $stmt = Db::pdo()->prepare(
                'INSERT INTO ' . Db::table('email_outbox') . '
                 (to_email, to_name, subject, body_text, body_html, template, context, status, attempts, queued_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, \'queued\', 0, UTC_TIMESTAMP(3))'
            );
            $stmt->execute([
                strtolower(trim($toEmail)),
                $toName,
                $subject,
                $text,
                $html,
                $template,
                $context === [] ? null : json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            // An outbox write must never fail the business operation it documents.
            Http::logError("mailer enqueue failed ({$template}): " . $e->getMessage());
        }
    }

    /** @param array<string, mixed> $order */
    public static function orderPlaced(array $order, string $email): void
    {
        $lines = [];
        foreach (($order['lines'] ?? []) as $line) {
            $lines[] = sprintf(
                '  %-32s x%d  %s',
                mb_substr((string) $line['name'], 0, 32),
                (int) $line['quantity'],
                Money::mul(Money::norm($line['unit_price']), (string) (int) $line['quantity'])
            );
        }

        $text = "Thanks for your order!\n\n"
            . 'Order #' . $order['id'] . "\n"
            . implode("\n", $lines) . "\n\n"
            . 'Subtotal:  ' . Money::norm($order['subtotal']) . "\n"
            . 'Discount:  -' . Money::norm($order['discount'] ?? '0.00') . "\n"
            . 'Shipping:  ' . Money::norm($order['shipping']) . "\n"
            . 'Tax:       ' . Money::norm($order['tax']) . "\n"
            . 'Total:     ' . Money::norm($order['total']) . ' ' . (string) ($order['currency'] ?? 'USD') . "\n\n"
            . "We'll email you again when it ships.\n";

        self::enqueue(
            'order_placed',
            $email,
            sprintf('Order #%d confirmed', $order['id']),
            $text,
            [
                'order_id' => (int) $order['id'],
                'total' => Money::norm($order['total']),
                'currency' => (string) ($order['currency'] ?? 'USD'),
                'item_count' => (int) ($order['item_count'] ?? 0),
            ]
        );
    }

    /** @param array<string, mixed> $order */
    public static function orderStatusChanged(array $order, string $email, string $from, string $to): void
    {
        self::enqueue(
            'order_status_changed',
            $email,
            sprintf('Order #%d is now %s', $order['id'], $to),
            "Your order #{$order['id']} moved from \"{$from}\" to \"{$to}\".\n"
            . 'Total: ' . Money::norm($order['total']) . ' ' . (string) ($order['currency'] ?? 'USD') . "\n",
            [
                'order_id' => (int) $order['id'],
                'from' => $from,
                'to' => $to,
                'total' => Money::norm($order['total']),
            ]
        );
    }
}
