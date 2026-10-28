<?php

declare(strict_types=1);

use AppCore\Auth;
use AppCore\Db;
use AppCore\Http;
use AppCore\Money;
use AppCore\Validate;
use AppCore\ValidationException;

require_once __DIR__ . '/_bootstrap.php';

/**
 * POST /api/invoices.php?order_id=N   — settings:manage: generate invoice for order
 * GET  /api/invoices.php              — settings:read: list invoices
 * GET  /api/invoices.php?id=N         — settings:read: single invoice (incl. pdf_path)
 * GET  /api/invoices.php?id=N&download=1 — settings:read: serve the invoice file
 * PATCH /api/invoices.php?id=N        — settings:manage: mark paid/voided
 *
 * Invoices are HTML files written to src/uploads/invoices/ and served from the
 * uploads mount. The html renders a printable invoice (Ctrl+P → Save as PDF).
 */
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $user = Auth::currentUser();
    $pdo = Db::pdo();
    $invoices = Db::table('invoices');
    $orders = Db::table('orders');
    $orderItems = Db::table('order_items');
    $products = Db::table('products');
    $currencies = Db::table('currencies');
    $users = Db::table('users');
    $now = 'UTC_TIMESTAMP(3)';
    $uploadDir = Env::projectRoot() . '/src/uploads/invoices';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0775, true);
    }

    // ---- Serve invoice file ------------------------------------------------
    if ($method === 'GET' && isset($_GET['download'], $_GET['id'])) {
        Auth::requirePermission('invoices:read');
        $id = Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX);
        \assert($id !== null);
        $row = $pdo->prepare('SELECT pdf_path FROM ' . $invoices . ' WHERE id = ? LIMIT 1');
        $row->execute([$id]);
        $invoice = $row->fetch();
        if ($invoice === false || $invoice['pdf_path'] === null || $invoice['pdf_path'] === '') {
            Http::error(404, 'NOT_FOUND', 'Invoice file not found.');
        }
        $path = $invoice['pdf_path'];
        // Prevent path traversal: must live under the invoices dir.
        $real = realpath($path);
        $base = realpath($uploadDir);
        if ($real === false || $base === false || strpos($real, $base . '/') !== 0) {
            Http::error(403, 'FORBIDDEN', 'Invalid invoice path.');
        }
        if (!is_file($real)) {
            Http::error(404, 'NOT_FOUND', 'Invoice file not found.');
        }
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($real) . '"');
        header('Content-Length: ' . filesize($real));
        readfile($real);
        exit;
    }

    // ---- List invoices -----------------------------------------------------
    if ($method === 'GET') {
        Auth::requirePermission('invoices:read');
        $page = Validate::int($_GET['page'] ?? 1, 'page', 1, 100000) ?? 1;
        $perPage = Validate::int($_GET['per_page'] ?? 20, 'per_page', 1, 100) ?? 20;
        $count = $pdo->query('SELECT COUNT(*) FROM ' . $invoices);
        $total = (int) $count->fetchColumn();
        $rows = $pdo->prepare(
            'SELECT i.id, i.invoice_number, i.status, i.subtotal, i.tax, i.total,
                    i.currency_code, i.currency_rate, i.issued_at, i.paid_at,
                    i.order_id, o.user_id, o.status AS order_status, o.created_at AS order_created
             FROM ' . $invoices . ' i
             JOIN ' . $orders . ' o ON o.id = i.order_id
             ORDER BY i.created_at DESC
             LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
        );
        $rows->execute();
        Http::ok([
            'invoices' => array_map(static function (array $r): array {
                return [
                    'id' => (int) $r['id'],
                    'invoice_number' => (string) $r['invoice_number'],
                    'status' => (string) $r['status'],
                    'subtotal' => Money::norm($r['subtotal']),
                    'tax' => Money::norm($r['tax']),
                    'total' => Money::norm($r['total']),
                    'currency_code' => (string) $r['currency_code'],
                    'currency_rate' => (string) $r['currency_rate'],
                    'issued_at' => $r['issued_at'] !== null ? (string) $r['issued_at'] : null,
                    'paid_at' => $r['paid_at'] !== null ? (string) $r['paid_at'] : null,
                    'order_id' => (int) $r['order_id'],
                    'order_status' => (string) $r['order_status'],
                    'order_created' => (string) $r['order_created'],
                ];
            }, $rows->fetchAll()),
        ], ['page' => (int) $page, 'per_page' => (int) $perPage, 'total' => $total]);
    }

    if (!isset($user) || $user === null) {
        Auth::requireUser(); // 401
    }
    Auth::requirePermission('invoices:manage');

    // ---- Generate invoice for an order -------------------------------------
    if ($method === 'POST' && isset($_GET['order_id'])) {
        $orderId = Validate::int($_GET['order_id'], 'order_id', 1, PHP_INT_MAX);
        \assert($orderId !== null);

            $pdo->beginTransaction();
            try {
                $orderStmt = $pdo->prepare('SELECT * FROM ' . $orders . ' WHERE id = ? LIMIT 1 FOR UPDATE');
                $orderStmt->execute([$orderId]);
                $orderRow = $orderStmt->fetch();
                if ($orderRow === false) {
                    $pdo->rollBack();
                    Http::error(404, 'NOT_FOUND', 'Order not found.');
                }

                // Attach line items for the invoice render.
                $itemsStmt = $pdo->prepare(
                    'SELECT oi.product_id, oi.quantity, oi.unit_price, p.slug, p.name, p.image_url
                     FROM ' . $orderItems . ' oi
                     JOIN ' . $products . ' p ON p.id = oi.product_id
                     WHERE oi.order_id = ? ORDER BY oi.id ASC'
                );
                $itemsStmt->execute([$orderId]);
                $orderRow['items'] = array_map(static function (array $r): array {
                    return [
                        'product_id' => (int) $r['product_id'],
                        'quantity' => (int) $r['quantity'],
                        'unit_price' => Money::norm($r['unit_price']),
                        'line_total' => Money::mul(Money::norm($r['unit_price']), (string) (int) $r['quantity']),
                        'name' => (string) $r['name'],
                        'slug' => (string) $r['slug'],
                        'image_url' => $r['image_url'] !== null ? (string) $r['image_url'] : null,
                    ];
                }, $itemsStmt->fetchAll());

                // Idempotent: return existing invoice if already issued.
                $existing = $pdo->prepare('SELECT id, invoice_number, pdf_path FROM ' . $invoices . ' WHERE order_id = ? LIMIT 1');
                $existing->execute([$orderId]);
                $existingInv = $existing->fetch();
                if ($existingInv !== false) {
                    $pdo->commit();
                    Http::ok([
                        'id' => (int) $existingInv['id'],
                        'invoice_number' => (string) $existingInv['invoice_number'],
                        'status' => (string) $existingInv['status'],
                        'pdf_path' => $existingInv['pdf_path'],
                    ]);
                }

            // Resolve default currency + rate.
            $curr = $pdo->prepare('SELECT code, rate_vs_default FROM ' . $currencies . ' WHERE is_default = TRUE AND is_active = TRUE LIMIT 1');
            $curr->execute();
            $currRow = $curr->fetch();
            $currencyCode = $currRow !== false ? (string) $currRow['code'] : 'USD';
            $currencyRate = $currRow !== false ? (string) $currRow['rate_vs_default'] : '1.000000';

            $invoiceNumber = (string) (Env::get('INVOICE_PREFIX', 'INV') ?? 'INV') . '-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);

            $subtotal = Money::norm($orderRow['subtotal']);
            $tax = Money::norm($orderRow['tax'] ?? 0);
            $total = Money::norm($orderRow['total']);

            $pdfPath = $uploadDir . '/' . $invoiceNumber . '.html';
            $html = buildInvoiceHtml($invoiceNumber, $orderRow, $currencyCode, $currencyRate);
            file_put_contents($pdfPath, $html);

            $issuedAt = gmdate('Y-m-d H:i:s.v');
            $pdo->prepare(
                'INSERT INTO ' . $invoices . ' (order_id, invoice_number, status, subtotal, tax, total, currency_code, currency_rate, issued_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ' . 'UTC_TIMESTAMP(3)' . ', ' . 'UTC_TIMESTAMP(3)' . ')'
            )->execute([
                $orderId,
                $invoiceNumber,
                'issued',
                $subtotal,
                $tax,
                $total,
                $currencyCode,
                $currencyRate,
                $issuedAt,
            ]);
            $invoiceId = (int) $pdo->lastInsertId();

            $pdo->commit();

            Http::created([
                'id' => $invoiceId,
                'invoice_number' => $invoiceNumber,
                'status' => 'issued',
                'pdf_path' => $pdfPath,
            ]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ---- Single invoice -----------------------------------------------------
    if ($method === 'GET' && isset($_GET['id'])) {
        Auth::requirePermission('invoices:read');
        $id = Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX);
        \assert($id !== null);
        $row = $pdo->prepare(
            'SELECT i.*, o.user_id, o.status AS order_status, o.created_at AS order_created
             FROM ' . $invoices . ' i
             JOIN ' . $orders . ' o ON o.id = i.order_id
             WHERE i.id = ? LIMIT 1'
        );
        $row->execute([$id]);
        $inv = $row->fetch();
        if ($inv === false) {
            Http::error(404, 'NOT_FOUND', 'Invoice not found.');
        }
        Http::ok([
            'invoice' => [
                'id' => (int) $inv['id'],
                'invoice_number' => (string) $inv['invoice_number'],
                'status' => (string) $inv['status'],
                'subtotal' => Money::norm($inv['subtotal']),
                'tax' => Money::norm($inv['tax']),
                'total' => Money::norm($inv['total']),
                'currency_code' => (string) $inv['currency_code'],
                'currency_rate' => (string) $inv['currency_rate'],
                'issued_at' => $inv['issued_at'] !== null ? (string) $inv['issued_at'] : null,
                'paid_at' => $inv['paid_at'] !== null ? (string) $inv['paid_at'] : null,
                'pdf_path' => $inv['pdf_path'],
                'order_id' => (int) $inv['order_id'],
                'order_status' => (string) $inv['order_status'],
                'order_created' => (string) $inv['order_created'],
            ],
        ]);
    }

    // ---- Mark paid / voided -------------------------------------------------
    if ($method === 'PATCH' && isset($_GET['id'])) {
        $id = Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX);
        $body = Validate::jsonBody();
        $status = Validate::enum($body['status'] ?? null, 'status', ['issued', 'paid', 'voided']);
        \assert($id !== null && $status !== null);

        $sets = ['status = ?', 'updated_at = UTC_TIMESTAMP(3)'];
        $params = [$status];
        if ($status === 'paid') {
            $sets[] = 'paid_at = UTC_TIMESTAMP(3)';
        }
        $params[] = $id;
        $pdo->prepare('UPDATE ' . $invoices . ' SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        Http::ok(['id' => $id, 'status' => $status]);
    }

    Http::error(405, 'METHOD_NOT_ALLOWED', 'Unsupported method for invoices.');
} catch (Throwable $e) {
    Http::fail($e);
}

/**
 * Build an HTML invoice document.
 */
function buildInvoiceHtml(string $invoiceNumber, array $order, string $currencyCode, string $currencyRate): string
{
    $storeName = htmlspecialchars((string) (Env::get('STORE_NAME') ?? 'Passive Store'), ENT_QUOTES);
    $storeEmail = htmlspecialchars((string) (Env::get('STORE_EMAIL') ?? ''), ENT_QUOTES);
    $storeAddress = htmlspecialchars((string) (Env::get('STORE_ADDRESS') ?? ''), ENT_QUOTES);

    $shippingAddress = $order['shipping_address'] !== null ? json_decode((string) $order['shipping_address'], true) : [];
    $shippingAddress = \is_array($shippingAddress) ? $shippingAddress : [];

    $esc = static function (mixed $v): string {
        return htmlspecialchars((string) $v, ENT_QUOTES);
    };

    $invoiceDate = gmdate('Y-m-d');
    $subtotal = $esc($order['subtotal'] ?? '0.00');
    $tax = $esc($order['tax'] ?? '0.00');
    $total = $esc($order['total'] ?? '0.00');

    $itemsHtml = '';
    if (isset($order['items']) && \is_array($order['items'])) {
        foreach ($order['items'] as $item) {
            $itemsHtml .= '<tr>'
                . '<td>' . $esc($item['name'] ?? '') . '</td>'
                . '<td style="text-align:center">' . (int) ($item['quantity'] ?? 0) . '</td>'
                . '<td style="text-align:right">' . $esc($item['unit_price'] ?? '0.00') . '</td>'
                . '<td style="text-align:right">' . $esc($item['line_total'] ?? '0.00') . '</td>'
                . '</tr>';
        }
    }

    $line2Html = '';
    if (isset($shippingAddress['line2']) && $shippingAddress['line2'] !== '') {
        $line2Html = '<div>' . $esc($shippingAddress['line2']) . '</div>';
    }

    $css = <<<CSS
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 12px; color: #1a1a1a; line-height: 1.4; }
  .invoice { max-width: 800px; margin: 40px auto; padding: 24px; border: 1px solid #ddd; }
  .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 32px; }
  .brand h1 { margin: 0; font-size: 24px; letter-spacing: 2px; text-transform: uppercase; }
  .brand p { margin: 2px 0 0; color: #666; font-size: 11px; }
  .invoice-meta { text-align: right; }
  .invoice-meta .number { font-size: 18px; font-weight: 700; letter-spacing: 1px; }
  .invoice-meta .date { color: #666; font-size: 11px; margin-top: 4px; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
  th { text-align: left; padding: 8px; border-bottom: 2px solid #ddd; color: #666; font-size: 11px; text-transform: uppercase; }
  td { padding: 10px 8px; border-bottom: 1px solid #eee; vertical-align: top; }
  .address { margin-bottom: 24px; }
  .address h3 { margin: 0 0 6px; font-size: 12px; text-transform: uppercase; color: #666; letter-spacing: 1px; }
  .totals { display: flex; flex-direction: column; align-items: flex-end; width: 100%; }
  .totals .row { display: flex; justify-content: space-between; width: 100%; padding: 6px 0; font-size: 12px; color: #333; }
  .totals .total { font-size: 16px; font-weight: 700; border-top: 2px solid #1a1a1a; padding-top: 10px; margin-top: 8px; }
  .footer { margin-top: 32px; padding-top: 16px; border-top: 1px solid #ddd; font-size: 10px; color: #888; text-align: center; }
  .currency-note { font-size: 10px; color: #888; margin-top: 8px; }
CSS;

    return '<!DOCTYPE html>'
        . '<html lang="en">'
        . '<head><meta charset="utf-8"><title>Invoice ' . $esc($invoiceNumber) . '</title>'
        . '<style>' . $css . '</style></head>'
        . '<body>'
        . '<div class="invoice">'
        . '<div class="header">'
        . '<div class="brand">'
        . '<h1>' . $storeName . '</h1>'
        . '<p>' . $storeEmail . '</p>'
        . '<p>' . $storeAddress . '</p>'
        . '</div>'
        . '<div class="invoice-meta">'
        . '<div class="number">Invoice ' . $esc($invoiceNumber) . '</div>'
        . '<div class="date">Date: ' . $esc($invoiceDate) . '</div>'
        . '</div>'
        . '</div>'

        . '<div class="address">'
        . '<h3>Ship to</h3>'
        . '<div>' . $esc($shippingAddress['full_name'] ?? '') . '</div>'
        . '<div>' . $esc($shippingAddress['line1'] ?? '') . '</div>'
        . $line2Html
        . '<div>' . $esc($shippingAddress['city'] ?? '') . ', ' . $esc($shippingAddress['region'] ?? '') . ' ' . $esc($shippingAddress['postal_code'] ?? '') . '</div>'
        . '<div>' . $esc($shippingAddress['country'] ?? '') . '</div>'
        . '</div>'

        . '<table>'
        . '<thead><tr>'
        . '<th>Item</th>'
        . '<th style="text-align:center">Qty</th>'
        . '<th style="text-align:right">Unit Price</th>'
        . '<th style="text-align:right">Total</th>'
        . '</tr></thead>'
        . '<tbody>' . $itemsHtml . '</tbody>'
        . '</table>'

        . '<div class="totals">'
        . '<div class="row"><span>Subtotal</span><span>' . $subtotal . ' ' . $esc($currencyCode) . '</span></div>'
        . '<div class="row"><span>Tax</span><span>' . $tax . ' ' . $esc($currencyCode) . '</span></div>'
        . '<div class="row total"><span>Total</span><span>' . $total . ' ' . $esc($currencyCode) . '</span></div>'
        . '<div class="currency-note">Currency: ' . $esc($currencyCode) . ' (rate vs default: ' . $esc($currencyRate) . ')</div>'
        . '</div>'

        . '<div class="footer">'
        . 'Thank you for your order. This invoice was generated by ' . $storeName . '.'
        . '</div>'
        . '</div>'
        . '</body>'
        . '</html>';
}
