<?php

declare(strict_types=1);

use AppCore\Db;

/**
 * 011_create_email_outbox — transactional email queue.
 *
 * Nothing is delivered by the app itself: messages are written here inside the
 * same request that triggered them, and a worker (or a future SMTP provider)
 * drains the queue. This keeps checkout free of SMTP latency while giving us a
 * durable, inspectable record of every message the store intended to send.
 */
return static function (PDO $pdo): void {
    $outbox = Db::table('email_outbox');

    $pdo->exec("CREATE TABLE IF NOT EXISTS {$outbox} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        to_email VARCHAR(190) NOT NULL,
        to_name VARCHAR(120) NULL,
        subject VARCHAR(255) NOT NULL,
        body_text TEXT NOT NULL,
        body_html TEXT NULL,
        template VARCHAR(64) NOT NULL,
        context JSON NULL,
        status ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        last_error VARCHAR(500) NULL,
        queued_at DATETIME(3) NOT NULL,
        sent_at DATETIME(3) NULL,
        PRIMARY KEY (id),
        KEY idx_email_outbox_status (status, queued_at),
        KEY idx_email_outbox_to (to_email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
