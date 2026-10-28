<?php

declare(strict_types=1);

namespace AppCore;

/**
 * URL slug generation. Kept in one place so categories, coupons and any future
 * slugged entity produce identically shaped, database-unique-safe strings.
 */
final class Slug
{
    public static function make(string $value, int $max = 190): string
    {
        $value = strtolower(trim($value));
        // Transliterate accents where the intl extension is present so that
        // "Café" becomes "cafe" rather than an empty slug.
        if (\function_exists('iconv')) {
            $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            \assert(\is_string($translit) || $translit === false);
            if (\is_string($translit) && $translit !== '') {
                $value = $translit;
            }
        }
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        if ($value === '') {
            $value = 'item-' . substr(bin2hex(random_bytes(4)), 0, 6);
        }

        return substr($value, 0, $max);
    }

    /** Slug guaranteed unused against a uniqueness test, appending -2, -3 … */
    public static function unique(string $value, callable $exists, int $max = 190): string
    {
        $base = self::make($value, $max);
        $candidate = $base;
        for ($i = 2; $i < 500; $i++) {
            if (!$exists($candidate)) {
                return $candidate;
            }
            $suffix = '-' . $i;
            $candidate = substr($base, 0, $max - strlen($suffix)) . $suffix;
        }

        return substr($base, 0, $max - 7) . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    }
}
