<?php

declare(strict_types=1);

namespace AppCore;

/**
 * Fixed-precision money arithmetic.
 *
 * Money never touches a float anywhere in this codebase: every amount is a
 * canonical "nn.nn" string and every operation goes through bcmath. This class
 * exists so that rounding is decided in exactly one place — half-up at 2dp —
 * instead of each endpoint quietly rounding its own way and the sum of the
 * parts no longer matching the total shown to the customer.
 */
final class Money
{
    public const SCALE = 2;

    /** Canonical zero. */
    public const ZERO = '0.00';

    public static function norm(mixed $amount): string
    {
        if ($amount === null || $amount === '' || !is_numeric($amount)) {
            return self::ZERO;
        }

        return number_format((float) $amount, self::SCALE, '.', '');
    }

    public static function add(string $a, string $b): string
    {
        return bcadd(self::norm($a), self::norm($b), self::SCALE);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub(self::norm($a), self::norm($b), self::SCALE);
    }

    public static function mul(string $a, string $b): string
    {
        return bcmul(self::norm($a), self::norm($b), self::SCALE);
    }

    /**
     * Divide with half-up rounding — used for percentage discounts and tax,
     * where truncating instead of rounding would silently under-charge.
     */
    public static function div(string $a, string $b, int $scale = 8): string
    {
        $b = self::norm($b);
        if (bccomp($b, self::ZERO, $scale) === 0) {
            return self::ZERO;
        }

        return bcdiv(self::norm($a), $b, $scale);
    }

    public static function mulRate(string $amount, string $rate): string
    {
        // rate arrives as a decimal fraction (0.0825) already normalised by Pricing.
        return self::round(bcmul(self::norm($amount), $rate, 6));
    }

    public static function round(string $amount): string
    {
        return number_format((float) self::norm($amount), self::SCALE, '.', '');
    }

    /**
     * Percentage of an amount, clamped to [0, $amount].
     *
     * @param string $percent 0-100
     */
    public static function percentOf(string $amount, string $percent): string
    {
        $amount = self::norm($amount);
        $percent = (float) $percent;
        if ($percent <= 0) {
            return self::ZERO;
        }
        if ($percent >= 100) {
            return $amount;
        }

        return self::round(bcdiv(bcmul($amount, (string) $percent, 8), '100', 8));
    }

    public static function min(string $a, string $b): string
    {
        return bccomp(self::norm($a), self::norm($b), self::SCALE) <= 0 ? self::norm($a) : self::norm($b);
    }

    public static function max(string $a, string $b): string
    {
        return bccomp(self::norm($a), self::norm($b), self::SCALE) >= 0 ? self::norm($a) : self::norm($b);
    }

    public static function gte(string $a, string $b): bool
    {
        return bccomp(self::norm($a), self::norm($b), self::SCALE) >= 0;
    }

    public static function isZero(string $a): bool
    {
        return bccomp(self::norm($a), self::ZERO, self::SCALE) === 0;
    }
}
