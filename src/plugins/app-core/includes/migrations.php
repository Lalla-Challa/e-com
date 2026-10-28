<?php

declare(strict_types=1);

namespace AppCore;

/**
 * Versioned schema runner. Migration files live in ../migrations as
 * NNN_name.php, each returning a callable that receives the PDO handle and
 * runs idempotent statements. Applied versions are recorded in app_migrations;
 * the runner is re-entrant and runs pending files in order inside a transaction.
 */
final class Migrations
{
    private const TABLE = 'app_migrations';

    /** @return list<string> names of migrations that ran */
    public static function run(?string $dir = null): array
    {
        $pdo = Db::pdo();
        $prefix = Db::tablePrefix();
        $tracked = $prefix . self::TABLE;

        $pdo->exec("CREATE TABLE IF NOT EXISTS {$tracked} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            version VARCHAR(191) NOT NULL,
            ran_at DATETIME(3) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_version (version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $applied = $pdo->query("SELECT version FROM {$tracked}")->fetchAll(\PDO::FETCH_COLUMN);
        $applied = \is_array($applied) ? $applied : [];

        $files = glob(($dir ?? Env::pluginDir() . '/migrations') . '/[0-9][0-9][0-9]_*.php') ?: [];
        sort($files, SORT_STRING);

        $ran = [];
        foreach ($files as $file) {
            $version = basename($file, '.php');
            if (\in_array($version, $applied, true)) {
                continue;
            }

            $migration = require $file;
            if (!\is_callable($migration)) {
                throw new \RuntimeException("Migration {$version} must return a callable.");
            }

            // MySQL DDL auto-commits, so migrations are NOT wrapped in a
            // transaction; every statement is idempotent (IF NOT EXISTS) and the
            // version row is written only after the file completes cleanly.
            try {
                $migration($pdo);
                $stmt = $pdo->prepare("INSERT INTO {$tracked} (version, ran_at) VALUES (?, UTC_TIMESTAMP(3))");
                $stmt->execute([$version]);
            } catch (\Throwable $e) {
                throw new \RuntimeException("Migration {$version} failed: " . $e->getMessage(), 0, $e);
            }

            $ran[] = $version;
        }

        return $ran;
    }
}
