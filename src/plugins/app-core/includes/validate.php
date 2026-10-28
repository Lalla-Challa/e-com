<?php

declare(strict_types=1);

namespace AppCore;

final class ValidationException extends \RuntimeException
{
    /** @param array<string, string> $fields */
    public function __construct(public readonly array $fields, string $message = 'Validation failed.')
    {
        parent::__construct($message);
    }
}

/**
 * Field validators. Each throws ValidationException naming the offending field,
 * so endpoints can wrap input parsing once and return the standard error shape.
 */
final class Validate
{
    /** @return array<string, mixed> */
    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (!\is_string($raw) || trim($raw) === '') {
            return [];
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ValidationException(['body' => 'invalid JSON'], 'Request body is not valid JSON.');
        }
        if (!\is_array($data)) {
            throw new ValidationException(['body' => 'must be an object'], 'Request body must be a JSON object.');
        }

        return $data;
    }

    public static function email(mixed $value, string $field = 'email', bool $required = true): ?string
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw new ValidationException([$field => 'required']);
            }

            return null;
        }
        if (!\is_string($value)) {
            throw new ValidationException([$field => 'must be a string']);
        }
        $email = strtolower(trim($value));
        if (strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException([$field => 'invalid email']);
        }

        return $email;
    }

    public static function str(mixed $value, string $field, int $min, int $max, bool $required = true): ?string
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw new ValidationException([$field => 'required']);
            }

            return null;
        }
        if (!\is_string($value)) {
            throw new ValidationException([$field => 'must be a string']);
        }
        $trimmed = trim($value);
        $len = mb_strlen($trimmed);
        if ($len < $min || $len > $max) {
            throw new ValidationException([$field => "must be {$min}-{$max} characters"]);
        }

        return $trimmed;
    }

    public static function int(mixed $value, string $field, int $min, int $max, bool $required = true): ?int
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw new ValidationException([$field => 'required']);
            }

            return null;
        }
        if (\is_bool($value) || !\is_numeric($value) || (int) $value != $value) {
            throw new ValidationException([$field => 'must be an integer']);
        }
        $int = (int) $value;
        if ($int < $min || $int > $max) {
            throw new ValidationException([$field => "must be between {$min} and {$max}"]);
        }

        return $int;
    }

    /**
     * Line quantity for cart/checkout: whole units, or 0.5 m steps for metre goods.
     */
    public static function cartQuantity(mixed $value, string $field, bool $metreUnit): string
    {
        if ($metreUnit) {
            $str = self::decimal($value, $field, 0.5, 999.0);
            \assert($str !== null);
            $n = (float) $str;
            if (abs(round($n * 2) - $n * 2) > 0.001) {
                throw new ValidationException([$field => 'must be in 0.5 increments']);
            }

            return fmod($n, 1.0) === 0.0 ? (string) (int) $n : number_format($n, 1, '.', '');
        }

        $int = self::int($value, $field, 1, 999);
        \assert($int !== null);

        return (string) $int;
    }

    public static function decimal(mixed $value, string $field, float $min, float $max, bool $required = true): ?string
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw new ValidationException([$field => 'required']);
            }

            return null;
        }
        if (!\is_numeric($value)) {
            throw new ValidationException([$field => 'must be a number']);
        }
        $number = (float) $value;
        if ($number < $min || $number > $max) {
            throw new ValidationException([$field => "must be between {$min} and {$max}"]);
        }
        // Money travels as a fixed-precision string; never a float.
        return number_format($number, 2, '.', '');
    }

    public static function bool(mixed $value, string $field, bool $required = true): ?bool
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw new ValidationException([$field => 'required']);
            }

            return null;
        }
        if (\is_bool($value)) {
            return $value;
        }
        if (\in_array($value, [1, '1', 'true', 'on', 'yes'], true)) {
            return true;
        }
        if (\in_array($value, [0, '0', 'false', 'off', 'no'], true)) {
            return false;
        }
        throw new ValidationException([$field => 'must be a boolean']);
    }

    /** @param list<string> $allowed */
    public static function enum(mixed $value, string $field, array $allowed, bool $required = true): ?string
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw new ValidationException([$field => 'required']);
            }

            return null;
        }
        if (!\is_string($value) || !\in_array($value, $allowed, true)) {
            throw new ValidationException([$field => 'must be one of: ' . implode(', ', $allowed)]);
        }

        return $value;
    }

    /**
     * Address object used by checkout; returns a JSON-safe assoc array or null.
     * @return array<string, string>|null
     */
    public static function address(mixed $value, string $field, bool $required = true): ?array
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw new ValidationException([$field => 'required']);
            }

            return null;
        }
        if (!\is_array($value)) {
            throw new ValidationException([$field => 'must be an object']);
        }
        $out = [];
        foreach (['full_name' => [1, 120], 'line1' => [1, 200], 'line2' => [0, 200], 'city' => [1, 100], 'region' => [0, 100], 'postal_code' => [1, 20], 'country' => [2, 2], 'phone' => [0, 30]] as $key => [$min, $max]) {
            $parsed = self::str($value[$key] ?? null, "{$field}.{$key}", $min, $max, $min > 0);
            if ($parsed !== null) {
                $out[$key] = $parsed;
            }
        }
        $out['country'] = strtoupper($out['country'] ?? '');
        if (strlen($out['country']) !== 2) {
            throw new ValidationException(["{$field}.country" => 'must be a 2-letter ISO code']);
        }

        return $out;
    }
}
