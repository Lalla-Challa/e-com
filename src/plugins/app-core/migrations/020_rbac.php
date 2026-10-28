<?php

declare(strict_types=1);

use AppCore\Db;

/**
 * 020_rbac — roles, permissions, and user-role assignments.
 *
 * Keeps the existing users.role ENUM (customer/admin) for backward
 * compatibility, but all new authorization goes through app_user_roles ->
 * app_role_permissions -> app_permissions. A user can hold multiple roles;
 * permissions are the union of all held roles.
 *
 * Every statement is idempotent. Existing users are backfilled into the role
 * matching their current users.role value.
 */
return static function (PDO $pdo): void {
    $roles = Db::table('roles');
    $permissions = Db::table('permissions');
    $rolePermissions = Db::table('role_permissions');
    $userRoles = Db::table('user_roles');
    $users = Db::table('users');

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$roles} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(64) NOT NULL,
        label VARCHAR(120) NOT NULL,
        is_system BOOLEAN NOT NULL DEFAULT TRUE,
        created_at DATETIME(3) NOT NULL,
        updated_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_roles_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$permissions} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        code VARCHAR(120) NOT NULL,
        label VARCHAR(160) NOT NULL,
        module VARCHAR(60) NOT NULL DEFAULT 'core',
        created_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_permissions_code (code),
        KEY idx_permissions_module (module)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$rolePermissions} (
        role_id BIGINT UNSIGNED NOT NULL,
        permission_id BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (role_id, permission_id),
        KEY idx_role_permissions_permission (permission_id),
        CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES {$roles} (id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES {$permissions} (id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$userRoles} (
        user_id BIGINT UNSIGNED NOT NULL,
        role_id BIGINT UNSIGNED NOT NULL,
        assigned_at DATETIME(3) NOT NULL,
        PRIMARY KEY (user_id, role_id),
        KEY idx_user_roles_role (role_id),
        CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES {$users} (id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES {$roles} (id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $now = 'UTC_TIMESTAMP(3)';

    // ---- Seed roles --------------------------------------------------------
    $seedRoles = [
        'customer' => 'Customer',
        'admin' => 'Administrator',
    ];
    $roleIds = [];
    foreach ($seedRoles as $name => $label) {
        $stmt = $pdo->prepare('SELECT id FROM ' . $roles . ' WHERE name = ?');
        $stmt->execute([$name]);
        $found = $stmt->fetchColumn();
        if ($found !== false) {
            $roleIds[$name] = (int) $found;
            continue;
        }
        $pdo->prepare('INSERT INTO ' . $roles . ' (name, label, is_system, created_at, updated_at) VALUES (?, ?, TRUE, ' . $now . ', ' . $now . ')')
            ->execute([$name, $label]);
        $roleIds[$name] = (int) $pdo->lastInsertId();
    }

    // ---- Seed permissions --------------------------------------------------
    $seedPermissions = [
        // customers
        ['customers:read_own', 'Read own account', 'customers'],
        ['customers:edit_own', 'Edit own account', 'customers'],
        ['customers:read_all', 'Read all customer accounts', 'customers'],
        ['customers:edit_all', 'Edit any customer account', 'customers'],
        // products
        ['products:read', 'Read products', 'products'],
        ['products:create', 'Create products', 'products'],
        ['products:edit', 'Edit products', 'products'],
        ['products:delete', 'Delete products', 'products'],
        ['products:manage_inventory', 'Manage product stock', 'products'],
        // orders
        ['orders:read_own', 'Read own orders', 'orders'],
        ['orders:read_all', 'Read all orders', 'orders'],
        ['orders:manage', 'Manage any order (status, notes)', 'orders'],
        ['orders:fulfill', 'Create fulfillments and tracking', 'orders'],
        // returns
        ['returns:request', 'Request a return on own orders', 'returns'],
        ['returns:manage', 'Approve or reject any return', 'returns'],
        // coupons / promotions
        ['coupons:manage', 'Create and manage coupons', 'promotions'],
        // tax / shipping / store settings
        ['settings:read', 'Read store settings', 'settings'],
        ['settings:manage', 'Edit store settings, tax and shipping', 'settings'],
        // invoices
        ['invoices:read', 'Read invoices', 'invoices'],
        ['invoices:manage', 'Generate and mark invoices paid', 'invoices'],
        // system / rbac
        ['system:admin', 'Full system access', 'system'],
        ['rbac:manage', 'Manage roles and permissions', 'system'],
    ];
    $permIds = [];
    foreach ($seedPermissions as [$code, $label, $module]) {
        $stmt = $pdo->prepare('SELECT id FROM ' . $permissions . ' WHERE code = ?');
        $stmt->execute([$code]);
        $found = $stmt->fetchColumn();
        if ($found !== false) {
            $permIds[$code] = (int) $found;
            continue;
        }
        $pdo->prepare('INSERT INTO ' . $permissions . ' (code, label, module, created_at) VALUES (?, ?, ?, ' . $now . ')')
            ->execute([$code, $label, $module]);
        $permIds[$code] = (int) $pdo->lastInsertId();
    }

    // ---- Role -> permission grants ----------------------------------------
    $grant = static function (string $role, array $codes) use ($pdo, $rolePermissions, $roleIds, $permIds, $now): void {
        $roleId = $roleIds[$role];
        foreach ($codes as $code) {
            $permissionId = $permIds[$code] ?? null;
            if ($permissionId === null) {
                continue;
            }
            $pdo->prepare('INSERT IGNORE INTO ' . $rolePermissions . ' (role_id, permission_id) VALUES (?, ?)')
                ->execute([$roleId, $permissionId]);
        }
    };

    $grant('customer', [
        'customers:read_own',
        'customers:edit_own',
        'products:read',
        'orders:read_own',
        'returns:request',
        'coupons:manage',
    ]);
    $grant('admin', array_keys($seedPermissions));

    // ---- Backfill existing users into their current role ------------------
    $existingRoles = [];
    $stmt = $pdo->query('SELECT id, role FROM ' . $users);
    foreach ($stmt->fetchAll() as $row) {
        $roleName = (string) ($row['role'] ?? 'customer');
        if (!isset($roleIds[$roleName])) {
            $roleName = 'customer';
        }
        $existingRoles[(int) $row['id']] = $roleIds[$roleName];
    }
    foreach ($existingRoles as $userId => $roleId) {
        $pdo->prepare('INSERT IGNORE INTO ' . $userRoles . ' (user_id, role_id, assigned_at) VALUES (?, ?, ' . $now . ')')
            ->execute([$userId, $roleId]);
    }
};
