<?php

declare(strict_types=1);

namespace AppCore;

/**
 * Auth primitives: password hashing (Argon2id w/ bcrypt fallback), HS256 JWTs,
 * rotating refresh tokens, request auth context, guest/cart tokens.
 */
final class Auth
{
    public const ACCESS_COOKIE = 'access_token';

    public const REFRESH_COOKIE = 'refresh_token';

    public const CART_COOKIE = 'cart_token';

    public const ACCESS_TTL = 15 * 60;

    public const REFRESH_TTL = 30 * 24 * 60 * 60;

    /**
     * Window (seconds) in which re-presenting a just-rotated token is treated
     * as a concurrent legitimate refresh (two tabs racing the same cookie)
     * rather than theft. Beyond it, reuse revokes the user's whole family.
     *
     * The grace must only ever apply to tokens revoked BY ROTATION. Explicit
     * kills (logout, family revocation after theft) therefore backdate
     * revoked_at past this window — see KILL_BACKDATE_SECONDS — so a nuclear
     * revocation can never be reopened by the grace period.
     */
    private const ROTATION_GRACE = 10;

    /** Backdating applied to non-rotation revocations (must exceed ROTATION_GRACE). */
    private const KILL_BACKDATE_SECONDS = 60;

    public static function passwordAlgo(): int|string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    /** @return array<string, int|int[]> */
    public static function passwordOptions(): array
    {
        if (self::passwordAlgo() === PASSWORD_ARGON2ID) {
            return ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 2];
        }

        return ['cost' => 12];
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, self::passwordAlgo(), self::passwordOptions());
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $data): string
    {
        $padded = strtr($data, '-_', '+/');
        $pad = strlen($padded) % 4;
        if ($pad > 0) {
            $padded .= str_repeat('=', 4 - $pad);
        }

        return (string) base64_decode($padded, true);
    }

    /**
     * @param array<string, mixed> $claims
     */
    public static function signJwt(array $claims, int $ttlSeconds): string
    {
        $secret = Env::jwtSecret();
        $now = time();
        $payload = array_merge([
            'iss' => 'app-core',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttlSeconds,
            'jti' => bin2hex(random_bytes(8)),
        ], $claims);

        $header = self::b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $body = self::b64url(json_encode($payload, JSON_THROW_ON_ERROR));
        $sig = self::b64url(hash_hmac('sha256', "{$header}.{$body}", $secret, true));

        return "{$header}.{$body}.{$sig}";
    }

    /**
     * @return array<string, mixed>|null null when signature or dates are invalid
     */
    public static function verifyJwt(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$header, $body, $sig] = $parts;

        $headerData = json_decode(self::b64urlDecode($header), true);
        if (!\is_array($headerData) || ($headerData['alg'] ?? '') !== 'HS256') {
            return null;
        }

        $expected = hash_hmac('sha256', "{$header}.{$body}", Env::jwtSecret(), true);
        if (!hash_equals($expected, self::b64urlDecode($sig))) {
            return null;
        }

        $payload = json_decode(self::b64urlDecode($body), true);
        if (!\is_array($payload)) {
            return null;
        }

        $now = time();
        if (!isset($payload['exp'], $payload['nbf']) || !\is_int($payload['exp']) || !\is_int($payload['nbf'])) {
            return null;
        }
        if ($payload['exp'] < $now || $payload['nbf'] > $now) {
            return null;
        }

        return $payload;
    }

    public static function issueSessionCookies(int $userId, string $role): void
    {
        $access = self::signJwt(['sub' => (string) $userId, 'role' => $role], self::ACCESS_TTL);
        $refresh = self::createRefreshToken($userId);
        Http::setCookie(self::ACCESS_COOKIE, $access, self::ACCESS_TTL);
        Http::setCookie(self::REFRESH_COOKIE, $refresh['token'], self::REFRESH_TTL);
    }

    /** @return array{token: string, expires_at: string} */
    public static function createRefreshToken(int $userId): array
    {
        $selector = self::randomToken(16);
        $validator = self::randomToken(32);
        $token = $selector . ':' . $validator;
        $hash = hash('sha256', $validator);
        $expiresAt = gmdate('Y-m-d H:i:s.v', time() + self::REFRESH_TTL);

        Db::pdo()
            ->prepare('INSERT INTO ' . Db::table('refresh_tokens') . ' (user_id, selector, validator_hash, expires_at, created_at, revoked_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(3), NULL)')
            ->execute([$userId, $selector, $hash, $expiresAt]);

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * Validates and ROTATES the presented refresh token inside one locked
     * transaction: the row is taken FOR UPDATE, revoked exactly once
     * (rowCount guard), and a fresh token is inserted before commit — so two
     * concurrent presentations can never both mint a live successor.
     *
     * Reuse of a token revoked beyond the grace window revokes every refresh
     * token the user holds (theft response). A validator mismatch fails
     * WITHOUT revoking: holding the selector alone must not let anyone kill
     * other people's sessions.
     *
     * @return array{user_id: int, token: string, expires_at: string, role: string}|null
     */
    public static function rotateRefreshToken(string $presented): ?array
    {
        $parts = explode(':', $presented, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$selector, $validator] = $parts;

        $pdo = Db::pdo();
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT id, user_id, validator_hash, expires_at, revoked_at FROM ' . Db::table('refresh_tokens') . ' WHERE selector = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([$selector]);
            $row = $stmt->fetch();
            if ($row === false) {
                $pdo->rollBack();

                return null;
            }

            if (!hash_equals((string) $row['validator_hash'], hash('sha256', $validator))) {
                $pdo->rollBack();

                return null;
            }

            $userId = (int) $row['user_id'];
            $expired = strtotime((string) $row['expires_at']) <= time();

            if ($row['revoked_at'] !== null) {
                $withinGrace = strtotime((string) $row['revoked_at']) + self::ROTATION_GRACE > time();
                if ($expired || !$withinGrace) {
                    // Reuse after rotation: assume the token leaked. Burn the
                    // entire family so neither the thief nor the victim keeps
                    // a chain alive. Backdated past ROTATION_GRACE so none of
                    // these kills can be reopened by the grace window below.
                    $pdo->prepare('UPDATE ' . Db::table('refresh_tokens') . ' SET revoked_at = UTC_TIMESTAMP(3) - INTERVAL ' . self::KILL_BACKDATE_SECONDS . ' SECOND WHERE user_id = ? AND revoked_at IS NULL')
                        ->execute([$userId]);
                    $pdo->commit();

                    return null;
                }
                // Inside the grace window the row is already revoked by its
                // first presentation; fall through and mint another successor.
            } else {
                if ($expired) {
                    $pdo->prepare('UPDATE ' . Db::table('refresh_tokens') . ' SET revoked_at = UTC_TIMESTAMP(3) WHERE id = ? AND revoked_at IS NULL')
                        ->execute([(int) $row['id']]);
                    $pdo->commit();

                    return null;
                }

                $update = $pdo->prepare('UPDATE ' . Db::table('refresh_tokens') . ' SET revoked_at = UTC_TIMESTAMP(3) WHERE id = ? AND revoked_at IS NULL');
                $update->execute([(int) $row['id']]);
                if ($update->rowCount() !== 1) {
                    // Lost the race despite the row lock — refuse rather than
                    // leave two live successors.
                    $pdo->rollBack();

                    return null;
                }
            }

            $active = $pdo->prepare('SELECT is_active FROM ' . Db::table('users') . ' WHERE id = ? LIMIT 1');
            $active->execute([$userId]);
            $isActive = $active->fetchColumn();
            if ($isActive === false || !filter_var($isActive, FILTER_VALIDATE_BOOLEAN)) {
                $pdo->rollBack();

                return null;
            }

            $fresh = self::createRefreshToken($userId);
            $role = self::roleFor($userId) ?? 'customer';
            $pdo->commit();

            return ['user_id' => $userId, 'token' => $fresh['token'], 'expires_at' => $fresh['expires_at'], 'role' => $role];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function revokeRefreshToken(string $presented): bool
    {
        $parts = explode(':', $presented, 2);
        if (count($parts) !== 2) {
            return false;
        }
        // Logout is an immediate kill: backdate past ROTATION_GRACE so a
        // replayed token cannot be resurrected as a "concurrent refresh".
        $stmt = Db::pdo()->prepare('UPDATE ' . Db::table('refresh_tokens') . ' SET revoked_at = UTC_TIMESTAMP(3) - INTERVAL ' . self::KILL_BACKDATE_SECONDS . ' SECOND WHERE selector = ? AND revoked_at IS NULL');
        $stmt->execute([$parts[0]]);

        return $stmt->rowCount() > 0;
    }

    public static function roleFor(int $userId): ?string
    {
        $stmt = DB::pdo()->prepare('SELECT role FROM ' . Db::table('users') . ' WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $role = $stmt->fetchColumn();

        return $role === false ? null : (string) $role;
    }

    /**
     * Resolve every permission code the user holds, as a flat list of strings.
     * Union of all roles assigned in app_user_roles.
     *
     * @return list<string>
     */
    public static function userPermissions(int $userId): array
    {
        static $cache = [];

        if (isset($cache[$userId])) {
            return $cache[$userId];
        }

        $perms = [];
        $stmt = Db::pdo()->prepare(
            'SELECT DISTINCT p.code
             FROM ' . Db::table('user_roles') . ' ur
             JOIN ' . Db::table('role_permissions') . ' rp ON rp.role_id = ur.role_id
             JOIN ' . Db::table('permissions') . ' p ON p.id = rp.permission_id
             WHERE ur.user_id = ?'
        );
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $code) {
            $perms[] = (string) $code;
        }

        $cache[$userId] = $perms;

        return $perms;
    }

    /**
     * Require that the caller holds a specific permission code; 403 otherwise.
     *
     * @return array{id: int, email: string, role: string, full_name: string}
     */
    public static function requirePermission(string $code): array
    {
        $user = self::requireUser();
        if (!\in_array($code, self::userPermissions($user['id']), true)) {
            Http::error(403, 'FORBIDDEN', 'Permission required: ' . $code);
        }
        \assert($user !== null);

        return $user;
    }

    /**
     * Resolves the caller from cookies or the Authorization header.
     *
     * @return array{id: int, email: string, role: string, full_name: string}|null
     */
    public static function currentUser(): ?array
    {
        $token = null;
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);
        if (\is_string($header) && preg_match('/^Bearer\s+(.+)$/i', $header, $m) === 1) {
            $token = trim($m[1]);
        } elseif (isset($_COOKIE[self::ACCESS_COOKIE]) && \is_string($_COOKIE[self::ACCESS_COOKIE]) && $_COOKIE[self::ACCESS_COOKIE] !== '') {
            $token = $_COOKIE[self::ACCESS_COOKIE];
        }
        if ($token === null) {
            return null;
        }

        $claims = self::verifyJwt($token);
        if ($claims === null || !isset($claims['sub'])) {
            return null;
        }

        // Deactivated accounts lose access immediately, even with a live
        // access token: the row lookup doubles as the is_active gate.
        $stmt = Db::pdo()->prepare('SELECT id, email, role, full_name FROM ' . Db::table('users') . ' WHERE id = ? AND is_active = TRUE LIMIT 1');
        $stmt->execute([(int) $claims['sub']]);
        $user = $stmt->fetch();
        if ($user === false) {
            return null;
        }

        return [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'role' => (string) $user['role'],
            'full_name' => (string) $user['full_name'],
        ];
    }

    /** @return array{id: int, email: string, role: string, full_name: string} */
    public static function requireUser(): array
    {
        $user = self::currentUser();
        if ($user === null) {
            Http::error(401, 'UNAUTHENTICATED', 'Sign in to continue.');
        }
        \assert($user !== null);

        return $user;
    }

    /** Require the system:admin permission (covers the legacy admin role). */
    public static function requireAdmin(): array
    {
        return self::requirePermission('system:admin');
    }

    /**
     * Identity for cart ownership: signed-in user, else a stable per-browser
     * cart token issued in a signed cookie.
     */
    public static function cartIdentity(): array
    {
        $user = self::currentUser();

        $raw = $_COOKIE[self::CART_COOKIE] ?? '';
        $token = null;
        if (\is_string($raw) && str_contains($raw, '.')) {
            [$value, $sig] = explode('.', $raw, 2);
            if (preg_match('/^[a-f0-9]{64}$/', $value) === 1 && hash_equals(self::cartSignature($value), $sig)) {
                $token = $value;
            }
        }

        if ($token === null) {
            $token = self::randomToken(32);
            Http::setCookie(self::CART_COOKIE, $token . '.' . self::cartSignature($token), 30 * 24 * 60 * 60);
        }

        return ['user_id' => $user['id'] ?? null, 'session_token' => $token];
    }

    private static function cartSignature(string $value): string
    {
        return hash_hmac('sha256', 'cart:' . $value, Env::jwtSecret());
    }

    /** Rate limiting: 5 auth attempts per IP per rolling 60s window. */
    public static function enforceRateLimit(string $bucket, int $max = 5, int $windowSeconds = 60): void
    {
        $ip = self::clientIp();
        $pdo = Db::pdo();
        $table = Db::table('rate_limits');

        // Opportunistic pruning: a counter only matters inside its window, and
        // nothing else ever deletes rows — without this the table grows by one
        // row per (bucket, IP) per minute, forever. Everything older than an
        // hour is far past any window and is safe to drop (tiny scan).
        $pdo->exec('DELETE FROM ' . $table . ' WHERE window_start < UTC_TIMESTAMP(3) - INTERVAL 1 HOUR');

        $cutoff = gmdate('Y-m-d H:i:s.v', time() - $windowSeconds);
        $stmt = $pdo->prepare(
            'SELECT attempts_count, window_start FROM ' . $table . ' WHERE bucket = ? AND ip_address = ? AND window_start > ? ORDER BY window_start DESC LIMIT 1'
        );
        $stmt->execute([$bucket, $ip, $cutoff]);
        $row = $stmt->fetch();

        if ($row !== false && (int) $row['attempts_count'] >= $max) {
            Http::error(429, 'RATE_LIMITED', 'Too many attempts. Try again in a minute.');
        }

        if ($row !== false) {
            $pdo->prepare('UPDATE ' . $table . ' SET attempts_count = attempts_count + 1 WHERE bucket = ? AND ip_address = ? AND window_start = ?')
                ->execute([$bucket, $ip, $row['window_start']]);
        } else {
            $pdo->prepare('INSERT INTO ' . $table . ' (bucket, ip_address, attempts_count, window_start) VALUES (?, ?, 1, UTC_TIMESTAMP(3))')
                ->execute([$bucket, $ip]);
        }
    }

    public static function clientIp(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        return filter_var($ip, FILTER_VALIDATE_IP) === false ? '0.0.0.0' : $ip;
    }
}
