<?php

declare(strict_types=1);

namespace AppCore;

/**
 * Activation/deactivation wiring. Activation runs the versioned migrations so
 * the schema always exists before the first API request.
 */
final class Activator
{
    public static function activate(): void
    {
        try {
            $ran = Migrations::run();
            update_option('app_core_migrations', $ran === [] ? 'up-to-date' : implode(', ', $ran), false);
            update_option('app_core_activated_at', gmdate('c'), false);
            // /app/**, /assets/** and /api/** rules registered on init need a flush.
            flush_rewrite_rules();
        } catch (\Throwable $e) {
            Http::logError('activation failed: ' . $e->getMessage());
            throw $e; // surface to the admin activation screen
        }
    }
}

final class Deactivator
{
    public static function deactivate(): void
    {
        // Data is intentionally preserved across deactivation.
        update_option('app_core_deactivated_at', gmdate('c'), false);
        flush_rewrite_rules();
    }
}
