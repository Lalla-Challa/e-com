<?php

declare(strict_types=1);

use AppCore\Db;

/**
 * 010_create_order_notes — per-order activity trail.
 *
 * `visibility` separates internal staff notes from anything the customer is
 * allowed to read, so the same table serves both the admin order detail view
 * and a customer-facing order timeline. author_user_id is NULL for system
 * notes (status changes written by the API itself).
 */
return static function (PDO $pdo): void {
    $notes = Db::table('order_notes');
    $orders = Db::table('orders');
    $users = Db::table('users');

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$notes} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id BIGINT UNSIGNED NOT NULL,
        author_user_id BIGINT UNSIGNED NULL,
        author_name VARCHAR(120) NULL,
        visibility ENUM('internal','customer') NOT NULL DEFAULT 'internal',
        body TEXT NOT NULL,
        created_at DATETIME(3) NOT NULL,
        PRIMARY KEY (id),
        KEY idx_order_notes_order (order_id, created_at),
        CONSTRAINT fk_order_notes_order FOREIGN KEY (order_id) REFERENCES {$orders} (id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_order_notes_author FOREIGN KEY (author_user_id) REFERENCES {$users} (id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
