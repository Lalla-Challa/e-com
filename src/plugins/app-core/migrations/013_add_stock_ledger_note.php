<?php

declare(strict_types=1);

use AppCore\Db;

/**
 * 013_add_stock_ledger_note — free-text reason on a stock movement.
 *
 * `reference_id` points at whatever caused the movement (an order id, a
 * purchase order). A manual adjustment has no such entity, but an auditor
 * still needs to know who changed the number and why, so the note is its own
 * column rather than being squeezed into an integer foreign key.
 */
return static function (PDO $pdo): void {
    $ledger = Db::table('stock_ledger');

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$ledger, 'note']);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }

    $pdo->exec("ALTER TABLE {$ledger} ADD COLUMN note VARCHAR(500) NULL AFTER reference_id");
};
