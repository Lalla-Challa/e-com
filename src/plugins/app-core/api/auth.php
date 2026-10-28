<?php

declare(strict_types=1);

use AppCore\Auth;
use AppCore\Db;
use AppCore\Http;
use AppCore\Validate;
use AppCore\ValidationException;

require_once __DIR__ . '/_bootstrap.php';

/**
 * /api/auth.php?action=register|login|refresh|logout|me
 * Frontend paths like /api/auth/register are rewritten here by Vite's proxy
 * rewrite (dev) and WordPress's rewrite block (production).
 */
try {
    $action = (string) ($_GET['action'] ?? '');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'POST' && $action === 'register') {
        Auth::enforceRateLimit('auth:register');
        $body = Validate::jsonBody();
        try {
            $email = Validate::email($body['email'] ?? null);
            $password = Validate::str($body['password'] ?? null, 'password', 8, 200);
            $fullName = Validate::str($body['full_name'] ?? null, 'full_name', 1, 120);
        } catch (ValidationException $e) {
            Http::error(400, 'VALIDATION_FAILED', 'Please check the highlighted fields.', $e->fields);
        }
        \assert(\is_string($email) && \is_string($password) && \is_string($fullName));

        $pdo = Db::pdo();
        $exists = $pdo->prepare('SELECT id FROM ' . Db::table('users') . ' WHERE email = ? LIMIT 1');
        $exists->execute([$email]);
        if ($exists->fetchColumn() !== false) {
            Http::error(409, 'EMAIL_TAKEN', 'An account with that email already exists.');
        }

        $stmt = $pdo->prepare('INSERT INTO ' . Db::table('users') . ' (email, password_hash, full_name, role, created_at, updated_at) VALUES (?, ?, ?, \'customer\', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))');
        $stmt->execute([$email, Auth::hashPassword($password), $fullName]);
        $userId = (int) $pdo->lastInsertId();

        Auth::issueSessionCookies($userId, 'customer');
        Http::created(['user' => ['id' => $userId, 'email' => $email, 'full_name' => $fullName, 'role' => 'customer']]);
    }

    if ($method === 'POST' && $action === 'login') {
        Auth::enforceRateLimit('auth:login');
        $body = Validate::jsonBody();
        try {
            $email = Validate::email($body['email'] ?? null);
            $password = Validate::str($body['password'] ?? null, 'password', 1, 200);
        } catch (ValidationException $e) {
            Http::error(400, 'VALIDATION_FAILED', 'Email and password are required.', $e->fields);
        }
        \assert(\is_string($email) && \is_string($password));

        $stmt = Db::pdo()->prepare('SELECT id, email, full_name, role, password_hash, is_active FROM ' . Db::table('users') . ' WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Constant-time-ish: always run a hash verify even when the user is missing.
        $hash = $user === false ? '$argon2id$v=19$m=65536,t=4,p=2$invalid$invalid' : (string) $user['password_hash'];
        $ok = Auth::verifyPassword($password, $hash);
        if ($user === false || !$ok) {
            Http::error(401, 'INVALID_CREDENTIALS', 'Email or password is incorrect.');
        }

        // Account state is only revealed to a caller who proved the password
        // (no user-enumeration oracle for wrong-password attackers).
        if (!filter_var($user['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            Http::error(403, 'ACCOUNT_DISABLED', 'This account has been deactivated. Contact the store.');
        }

        $userId = (int) $user['id'];
        Auth::issueSessionCookies($userId, (string) $user['role']);
        Http::ok(['user' => ['id' => $userId, 'email' => (string) $user['email'], 'full_name' => (string) $user['full_name'], 'role' => (string) $user['role']]]);
    }

    if ($method === 'POST' && $action === 'refresh') {
        $presented = $_COOKIE[Auth::REFRESH_COOKIE] ?? '';
        if (!\is_string($presented) || $presented === '') {
            Http::error(401, 'NO_REFRESH_TOKEN', 'No refresh token present.');
        }
        $rotated = Auth::rotateRefreshToken($presented);
        if ($rotated === null) {
            Http::clearCookie(Auth::REFRESH_COOKIE);
            Http::error(401, 'INVALID_REFRESH_TOKEN', 'Session expired. Sign in again.');
        }

        $access = Auth::signJwt(['sub' => (string) $rotated['user_id'], 'role' => $rotated['role']], Auth::ACCESS_TTL);
        Http::setCookie(Auth::ACCESS_COOKIE, $access, Auth::ACCESS_TTL);
        Http::setCookie(Auth::REFRESH_COOKIE, $rotated['token'], Auth::REFRESH_TTL);
        Http::ok(['user' => ['id' => $rotated['user_id'], 'role' => $rotated['role']]]);
    }

    if ($method === 'POST' && $action === 'logout') {
        $presented = $_COOKIE[Auth::REFRESH_COOKIE] ?? '';
        if (\is_string($presented) && $presented !== '') {
            Auth::revokeRefreshToken($presented);
        }
        Http::clearCookie(Auth::ACCESS_COOKIE);
        Http::clearCookie(Auth::REFRESH_COOKIE);
        Http::noContent();
    }

    if ($method === 'GET' && $action === 'me') {
        $user = Auth::requireUser();
        Http::ok(['user' => $user]);
    }

    Http::error(404, 'NOT_FOUND', 'Unknown auth action.');
} catch (Throwable $e) {
    Http::fail($e);
}
