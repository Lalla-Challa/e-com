<?php

declare(strict_types=1);

use AppCore\Auth;
use AppCore\Db;
use AppCore\Http;
use AppCore\Validate;

require_once __DIR__ . '/_bootstrap.php';

/**
 * Admin view of the transactional email queue.
 *
 * Nothing here sends anything — this is the inspection surface for the outbox,
 * so "did the customer get told?" is answerable without shell access to the
 * database.
 *
 * GET ?status=queued|sent|failed   — filter
 * GET ?id=N                        — one message with its body
 * GET ?q=...                       — search recipient or subject
 */
try {
    Auth::requireAdmin();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        Http::error(405, 'METHOD_NOT_ALLOWED', 'Read-only endpoint.');
    }

    $pdo = Db::pdo();
    $outbox = Db::table('email_outbox');

    $present = static fn (array $r): array => [
        'id' => (int) $r['id'],
        'to_email' => (string) $r['to_email'],
        'to_name' => $r['to_name'] !== null ? (string) $r['to_name'] : null,
        'subject' => (string) $r['subject'],
        'template' => (string) $r['template'],
        'status' => (string) $r['status'],
        'attempts' => (int) $r['attempts'],
        'last_error' => $r['last_error'] !== null ? (string) $r['last_error'] : null,
        'queued_at' => (string) $r['queued_at'],
        'sent_at' => $r['sent_at'] !== null ? (string) $r['sent_at'] : null,
    ];

    $id = isset($_GET['id']) ? Validate::int($_GET['id'], 'id', 1, PHP_INT_MAX) : null;
    if ($id !== null) {
        $stmt = $pdo->prepare('SELECT * FROM ' . $outbox . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            Http::error(404, 'NOT_FOUND', 'Message not found.');
        }
        \assert($row !== false);

        Http::ok(['message' => $present($row) + [
            'body_text' => (string) $row['body_text'],
            'body_html' => $row['body_html'] !== null ? (string) $row['body_html'] : null,
            'context' => $row['context'] !== null ? json_decode((string) $row['context'], true) : null,
        ]]);
    }

    $page = Validate::int($_GET['page'] ?? 1, 'page', 1, 100000) ?? 1;
    $perPage = Validate::int($_GET['per_page'] ?? 25, 'per_page', 1, 200) ?? 25;
    $status = isset($_GET['status']) ? Validate::enum($_GET['status'], 'status', ['queued', 'sending', 'sent', 'failed']) : null;
    $q = isset($_GET['q']) ? Validate::str($_GET['q'], 'q', 1, 190) : null;

    $where = '1=1';
    $params = [];
    if ($status !== null) {
        $where .= ' AND status = ?';
        $params[] = $status;
    }
    if ($q !== null) {
        $where .= ' AND (to_email LIKE ? OR subject LIKE ?)';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }

    $count = $pdo->prepare('SELECT COUNT(*) FROM ' . $outbox . ' WHERE ' . $where);
    $count->execute($params);
    $total = (int) $count->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT * FROM ' . $outbox . ' WHERE ' . $where . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
    );
    $stmt->execute($params);

    Http::ok(['messages' => array_map($present, $stmt->fetchAll())], ['page' => $page, 'per_page' => $perPage, 'total' => $total]);
} catch (Throwable $e) {
    Http::fail($e);
}
