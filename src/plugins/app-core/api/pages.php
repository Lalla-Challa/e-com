<?php

declare(strict_types=1);

use AppCore\Auth;
use AppCore\Db;
use AppCore\Http;
use AppCore\Slug;
use AppCore\Validate;
use AppCore\ValidationException;

require_once __DIR__ . '/_bootstrap.php';

/**
 * GET  /api/pages.php            — public: published pages (id, title, slug, excerpt)
 * GET  /api/pages.php?slug=about  — public: one published page with its body blocks
 * GET  /api/pages.php?all=1      — admin: every page including drafts
 * POST   {title, slug?, excerpt?, body?, status?}  — admin create
 * PATCH  {id, title?, slug?, excerpt?, body?, status?} — admin update
 * DELETE {id} — admin delete
 *
 * The body is structured JSON (blocks), never raw HTML, so the storefront can
 * render it without a sanitiser.
 */
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $user = Auth::currentUser();
    $pdo = Db::pdo();
    $pages = Db::table('cms_pages');

    $present = static function (array $r, bool $withBody = true): array {
        return [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'slug' => (string) $r['slug'],
            'excerpt' => $r['excerpt'] !== null ? (string) $r['excerpt'] : null,
            'status' => (string) $r['status'],
            'published_at' => $r['published_at'] !== null ? (string) $r['published_at'] : null,
            'created_at' => (string) $r['created_at'],
            'updated_at' => (string) $r['updated_at'],
            'body' => $withBody ? json_decode((string) ($r['body'] ?? 'null'), true) : null,
        ];
    };

    if ($method === 'POST') {
        Auth::requireAdmin();
        $body = Validate::jsonBody();
        try {
            $title = Validate::str($body['title'] ?? null, 'title', 1, 190);
            $requestedSlug = Validate::str($body['slug'] ?? null, 'slug', 1, 190, false);
            $excerpt = Validate::str($body['excerpt'] ?? null, 'excerpt', 0, 300, false);
            $status = Validate::enum($body['status'] ?? 'draft', 'status', ['draft', 'published'], false) ?? 'draft';
        } catch (ValidationException $e) {
            Http::error(400, 'VALIDATION_FAILED', 'Please check the highlighted fields.', $e->fields);
        }
        \assert($title !== null);

        $slug = Slug::unique($requestedSlug ?? $title, static function (string $s) use ($pdo, $pages): bool {
            $check = $pdo->prepare('SELECT 1 FROM ' . $pages . ' WHERE slug = ? LIMIT 1');
            $check->execute([$s]);

            return $check->fetchColumn() !== false;
        });

        $content = json_encode(is_array($body['body'] ?? null) ? $body['body'] : [], JSON_UNESCAPED_UNICODE);

        // A page published at creation gets a published_at immediately; a
        // draft gets NULL and picks one up on its first publish.
        $pdo->prepare(
            'INSERT INTO ' . $pages . ' (title, slug, excerpt, body, status, published_at, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ' . ($status === 'published' ? 'UTC_TIMESTAMP(3)' : 'NULL') . ', ?, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))'
        )->execute([
            $title, $slug, $excerpt, $content, $status,
            $user['id'] ?? null,
        ]);

        $id = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT * FROM ' . $pages . ' WHERE id = ?');
        $stmt->execute([$id]);
        Http::created(['page' => $present($stmt->fetch())]);
    }

    if ($method === 'PATCH') {
        Auth::requireAdmin();
        $body = Validate::jsonBody();
        try {
            $id = Validate::int($body['id'] ?? null, 'id', 1, PHP_INT_MAX);
        } catch (ValidationException $e) {
            Http::error(400, 'VALIDATION_FAILED', 'Invalid page id.', $e->fields);
        }
        \assert($id !== null);

        $sets = [];
        $params = [];
        if (array_key_exists('title', $body)) {
            $sets[] = 'title = ?';
            $params[] = Validate::str($body['title'], 'title', 1, 190);
        }
        if (array_key_exists('slug', $body)) {
            $sets[] = 'slug = ?';
            $params[] = Validate::str($body['slug'], 'slug', 1, 190);
        }
        if (array_key_exists('excerpt', $body)) {
            $sets[] = 'excerpt = ?';
            $params[] = Validate::str($body['excerpt'], 'excerpt', 0, 300, false);
        }
        if (array_key_exists('body', $body)) {
            $sets[] = 'body = ?';
            $params[] = json_encode(is_array($body['body']) ? $body['body'] : [], JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('status', $body)) {
            $status = Validate::enum($body['status'], 'status', ['draft', 'published']);
            $sets[] = 'status = ?';
            $params[] = $status;
            if ($status === 'published') {
                $sets[] = 'published_at = COALESCE(published_at, UTC_TIMESTAMP(3))';
            }
        }
        if ($sets === []) {
            Http::error(400, 'VALIDATION_FAILED', 'Nothing to update.');
        }
        $sets[] = 'updated_at = UTC_TIMESTAMP(3)';
        $params[] = $id;

        $pdo->prepare('UPDATE ' . $pages . ' SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);

        $stmt = $pdo->prepare('SELECT * FROM ' . $pages . ' WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            Http::error(404, 'NOT_FOUND', 'Page not found.');
        }
        Http::ok(['page' => $present($row)]);
    }

    if ($method === 'DELETE') {
        Auth::requireAdmin();
        $id = Validate::int($_GET['id'] ?? null, 'id', 1, PHP_INT_MAX);
        $pdo->prepare('DELETE FROM ' . $pages . ' WHERE id = ?')->execute([$id]);
        Http::ok(['deleted' => $id]);
    }

    // ---- Reads -------------------------------------------------------------
    $isAdminView = $user !== null && $user['role'] === 'admin' && isset($_GET['all']);

    if (isset($_GET['slug'])) {
        $slug = Validate::str($_GET['slug'], 'slug', 1, 190);
        $sql = $isAdminView
            ? 'SELECT * FROM ' . $pages . ' WHERE slug = ? LIMIT 1'
            : 'SELECT * FROM ' . $pages . " WHERE slug = ? AND status = 'published' LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        if ($row === false) {
            Http::error(404, 'NOT_FOUND', 'Page not found.');
        }
        Http::ok(['page' => $present($row)]);
    }

    $sql = $isAdminView
        ? 'SELECT * FROM ' . $pages . ' ORDER BY updated_at DESC'
        : 'SELECT * FROM ' . $pages . " WHERE status = 'published' ORDER BY published_at DESC";
    Http::ok(['pages' => array_map($present, $pdo->query($sql)->fetchAll())]);
} catch (Throwable $e) {
    Http::fail($e);
}
