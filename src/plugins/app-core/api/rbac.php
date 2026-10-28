<?php

declare(strict_types=1);

use AppCore\Auth;
use AppCore\Db;
use AppCore\Http;
use AppCore\Validate;
use AppCore\ValidationException;

require_once __DIR__ . '/_bootstrap.php';

/**
 * RBAC management — roles, permissions, and user-role assignments.
 *
 * GET  /api/rbac.php/roles             — rbac:manage: list roles
 * GET  /api/rbac.php/permissions       — rbac:manage: list permissions
 * GET  /api/rbac.php/user-roles        — rbac:manage: list user-role assignments
 * POST /api/rbac.php/roles             — rbac:manage: create role
 * POST /api/rbac.php/permissions       — rbac:manage: create permission
 * POST /api/rbac.php/grant             — rbac:manage: grant permission to role
 * POST /api/rbac.php/user-roles        — rbac:manage: assign role to user
 * DELETE /api/rbac.php/grant           — rbac:manage: revoke permission from role (?role_id=&permission_id=)
 * DELETE /api/rbac.php/user-roles      — rbac:manage: unassign role from user (?user_id=&role_id=)
 */
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $user = Auth::currentUser();
    if (!isset($user) || $user === null) {
        Auth::requireUser(); // 401
    }
    Auth::requirePermission('rbac:manage');

    $pdo = Db::pdo();
    $roles = Db::table('roles');
    $permissions = Db::table('permissions');
    $rolePermissions = Db::table('role_permissions');
    $userRoles = Db::table('user_roles');
    $users = Db::table('users');

    // ---- Roles ---------------------------------------------------------------
    if (isset($_GET['roles'])) {
        if ($method === 'GET') {
            $rows = $pdo->query("SELECT id, name, label, is_system, created_at, updated_at FROM {$roles} ORDER BY name ASC")->fetchAll();
            Http::ok(['roles' => array_map(static function (array $r): array {
                return [
                    'id' => (int) $r['id'],
                    'name' => (string) $r['name'],
                    'label' => (string) $r['label'],
                    'is_system' => (bool) $r['is_system'],
                    'created_at' => (string) $r['created_at'],
                    'updated_at' => (string) $r['updated_at'],
                ];
            }, $rows)]);
        }
        if ($method === 'POST') {
            $body = Validate::jsonBody();
            $name = Validate::str($body['name'] ?? null, 'name', 1, 64);
            $label = Validate::str($body['label'] ?? null, 'label', 1, 120);
            \assert($name !== null && $label !== null);
            $pdo->prepare(
                "INSERT INTO {$roles} (name, label, is_system, created_at, updated_at) VALUES (?, ?, FALSE, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3))"
            )->execute([$name, $label]);
            Http::created(['id' => (int) $pdo->lastInsertId(), 'name' => $name]);
        }
    }

    // ---- Permissions ---------------------------------------------------------
    if (isset($_GET['permissions'])) {
        if ($method === 'GET') {
            $rows = $pdo->query("SELECT id, code, label, module, created_at FROM {$permissions} ORDER BY code ASC")->fetchAll();
            Http::ok(['permissions' => array_map(static function (array $r): array {
                return [
                    'id' => (int) $r['id'],
                    'code' => (string) $r['code'],
                    'label' => (string) $r['label'],
                    'module' => (string) $r['module'],
                    'created_at' => (string) $r['created_at'],
                ];
            }, $rows)]);
        }
        if ($method === 'POST') {
            $body = Validate::jsonBody();
            $code = Validate::str($body['code'] ?? null, 'code', 1, 120);
            $label = Validate::str($body['label'] ?? null, 'label', 1, 160);
            $module = Validate::str($body['module'] ?? 'core', 'module', 1, 60);
            \assert($code !== null && $label !== null && $module !== null);
            $pdo->prepare(
                "INSERT INTO {$permissions} (code, label, module, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP(3))"
            )->execute([$code, $label, $module]);
            Http::created(['id' => (int) $pdo->lastInsertId(), 'code' => $code]);
        }
    }

    // ---- User-role assignments ----------------------------------------------
    if (isset($_GET['user-roles'])) {
        if ($method === 'GET') {
            $rows = $pdo->query(
                "SELECT ur.user_id, ur.role_id, ur.assigned_at, u.email, u.full_name, r.name AS role_name
                 FROM {$userRoles} ur
                 JOIN {$users} u ON u.id = ur.user_id
                 JOIN {$roles} r ON r.id = ur.role_id
                 ORDER BY u.email ASC, r.name ASC"
            )->fetchAll();
            Http::ok(array_map(static function (array $r): array {
                return [
                    'user_id' => (int) $r['user_id'],
                    'role_id' => (int) $r['role_id'],
                    'assigned_at' => (string) $r['assigned_at'],
                    'email' => (string) $r['email'],
                    'full_name' => (string) $r['full_name'],
                    'role_name' => (string) $r['role_name'],
                ];
            }, $rows));
        }
        if ($method === 'POST') {
            $body = Validate::jsonBody();
            $userId = Validate::int($body['user_id'] ?? null, 'user_id', 1, PHP_INT_MAX);
            $roleId = Validate::int($body['role_id'] ?? null, 'role_id', 1, PHP_INT_MAX);
            \assert($userId !== null && $roleId !== null);
            $pdo->prepare(
                "INSERT IGNORE INTO {$userRoles} (user_id, role_id, assigned_at) VALUES (?, ?, UTC_TIMESTAMP(3))"
            )->execute([$userId, $roleId]);
            Http::created(['user_id' => $userId, 'role_id' => $roleId]);
        }
        if ($method === 'DELETE') {
            $userId = Validate::int($_GET['user_id'] ?? null, 'user_id', 1, PHP_INT_MAX);
            $roleId = Validate::int($_GET['role_id'] ?? null, 'role_id', 1, PHP_INT_MAX);
            \assert($userId !== null && $roleId !== null);
            $pdo->prepare("DELETE FROM {$userRoles} WHERE user_id = ? AND role_id = ?")->execute([$userId, $roleId]);
            Http::ok(['user_id' => $userId, 'role_id' => $roleId]);
        }
    }

    // ---- Grant / revoke role-permission -------------------------------------
    if (isset($_GET['grant'])) {
        if ($method === 'POST') {
            $body = Validate::jsonBody();
            $roleId = Validate::int($body['role_id'] ?? null, 'role_id', 1, PHP_INT_MAX);
            $permissionId = Validate::int($body['permission_id'] ?? null, 'permission_id', 1, PHP_INT_MAX);
            \assert($roleId !== null && $permissionId !== null);
            $pdo->prepare(
                "INSERT IGNORE INTO {$rolePermissions} (role_id, permission_id) VALUES (?, ?)"
            )->execute([$roleId, $permissionId]);
            Http::created(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
        if ($method === 'DELETE') {
            $roleId = Validate::int($_GET['role_id'] ?? null, 'role_id', 1, PHP_INT_MAX);
            $permissionId = Validate::int($_GET['permission_id'] ?? null, 'permission_id', 1, PHP_INT_MAX);
            \assert($roleId !== null && $permissionId !== null);
            $pdo->prepare("DELETE FROM {$rolePermissions} WHERE role_id = ? AND permission_id = ?")->execute([$roleId, $permissionId]);
            Http::ok(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    Http::error(405, 'METHOD_NOT_ALLOWED', 'Unsupported method for rbac.');
} catch (Throwable $e) {
    Http::fail($e);
}
