<?php

declare(strict_types=1);

namespace AppCore;

/**
 * Tax resolution, shipping rate calculation and coupon discounting.
 *
 * Every figure the customer sees is derived here, so checkout and the quote
 * endpoint can never disagree. Shipping is always charged on the pre-discount
 * subtotal — a coupon should not silently change what the carrier costs us —
 * while tax is charged on the discounted goods total, which is the common
 * consumer-tax model. Both rules are one-line changes here if a jurisdiction
 * wants the other behaviour.
 */
final class Pricing
{
    /**
     * Most-specific tax rate for a destination, or a zero-rate fallback.
     *
     * @return array{rate: string, name: string, code: string}
     */
    public static function taxFor(string $country, ?string $region = null): array
    {
        $country = strtoupper(substr(trim($country), 0, 2));
        $region = $region !== null && trim($region) !== '' ? strtoupper(trim($region)) : null;

        $stmt = Db::pdo()->prepare(
            'SELECT name, rate FROM ' . Db::table('tax_rates') . '
             WHERE is_active = TRUE AND country = ?
               AND (region IS NULL OR region = ?)
             ORDER BY (region IS NULL) ASC, id DESC
             LIMIT 1'
        );
        $stmt->execute([$country, $region]);
        $row = $stmt->fetch();

        if ($row === false) {
            return ['rate' => '0.0000', 'name' => 'Tax', 'code' => 'none'];
        }

        return [
            'rate' => number_format((float) $row['rate'], 4, '.', ''),
            'name' => (string) $row['name'],
            'code' => strtoupper($country) . ($region !== null ? '-' . $region : ''),
        ];
    }

    /** @return list<array<string, mixed>> active shipping methods servable to a country */
    public static function shippingMethods(string $country): array
    {
        $country = strtoupper(substr(trim($country), 0, 2));
        $stmt = Db::pdo()->query(
            'SELECT code, name, description, base_rate, per_item, free_over_subtotal,
                    estimated_days_min, estimated_days_max, countries
             FROM ' . Db::table('shipping_methods') . '
             WHERE is_active = TRUE
             ORDER BY sort_order ASC, id ASC'
        );

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $allowed = $row['countries'] !== null ? json_decode((string) $row['countries'], true) : null;
            if (\is_array($allowed) && $allowed !== [] && !\in_array($country, array_map('strtoupper', $allowed), true)) {
                continue;
            }
            $out[] = [
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'description' => $row['description'] !== null ? (string) $row['description'] : null,
                'base_rate' => Money::norm($row['base_rate']),
                'per_item' => Money::norm($row['per_item']),
                'free_over_subtotal' => $row['free_over_subtotal'] !== null ? Money::norm($row['free_over_subtotal']) : null,
                'estimated_days_min' => $row['estimated_days_min'] !== null ? (int) $row['estimated_days_min'] : null,
                'estimated_days_max' => $row['estimated_days_max'] !== null ? (int) $row['estimated_days_max'] : null,
            ];
        }

        return $out;
    }

    /**
     * Price one shipping method against a cart.
     *
     * @param array<string, mixed> $method row from shippingMethods()
     * @return array{code: string, name: string, price: string, free: bool}
     */
    public static function priceShipping(array $method, string $subtotal, int $itemCount): array
    {
        $price = Money::add(Money::norm($method['base_rate']), Money::mulRate(Money::norm($method['per_item']), (string) $itemCount));
        $free = false;

        $threshold = $method['free_over_subtotal'] ?? null;
        if ($threshold !== null && Money::gte($subtotal, (string) $threshold)) {
            $price = Money::ZERO;
            $free = true;
        }

        return [
            'code' => (string) $method['code'],
            'name' => (string) $method['name'],
            'price' => Money::round($price),
            'free' => $free,
        ];
    }

    /** Cheapest servable method, used as the checkout default. */
    public static function defaultShipping(string $country, string $subtotal, int $itemCount): ?array
    {
        $priced = [];
        foreach (self::shippingMethods($country) as $method) {
            $priced[] = self::priceShipping($method, $subtotal, $itemCount);
        }

        return $priced === [] ? null : self::cheapestOption($priced);
    }

    /**
     * Look up a coupon by code. Returns the row with `discount` unset — the
     * caller applies it, because the discount depends on the subtotal.
     *
     * @return array<string, mixed>|null
     */
    public static function findCoupon(string $code): ?array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }

        $stmt = Db::pdo()->prepare(
            'SELECT id, code, type, value, min_subtotal, max_uses, used_count, per_user_limit, starts_at, ends_at, is_active
             FROM ' . Db::table('coupons') . ' WHERE code = ? LIMIT 1'
        );
        $stmt->execute([$code]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Why a coupon cannot be used, or null when it can. Checks that do not
     * depend on the cart are done here so the storefront and checkout report
     * the same reason.
     *
     * @param array<string, mixed> $coupon row from findCoupon()
     * @return array{code: string, message: string}|null
     */
    public static function couponBlockedReason(array $coupon, string $subtotal, ?int $userId = null): ?array
    {
        if (!(bool) $coupon['is_active']) {
            return ['code' => 'COUPON_INACTIVE', 'message' => 'That code is no longer active.'];
        }

        $now = time();
        $starts = $coupon['starts_at'] !== null ? strtotime((string) $coupon['starts_at']) : null;
        $ends = $coupon['ends_at'] !== null ? strtotime((string) $coupon['ends_at']) : null;
        if ($starts !== false && $starts !== null && $now < $starts) {
            return ['code' => 'COUPON_NOT_STARTED', 'message' => 'That code is not active yet.'];
        }
        if ($ends !== false && $ends !== null && $now > $ends) {
            return ['code' => 'COUPON_EXPIRED', 'message' => 'That code has expired.'];
        }

        if ($coupon['max_uses'] !== null && (int) $coupon['used_count'] >= (int) $coupon['max_uses']) {
            return ['code' => 'COUPON_EXHAUSTED', 'message' => 'That code has reached its usage limit.'];
        }

        if (!Money::gte($subtotal, Money::norm($coupon['min_subtotal']))) {
            return [
                'code' => 'COUPON_MINIMUM_NOT_MET',
                'message' => 'Spend at least ' . Money::norm($coupon['min_subtotal']) . ' to use that code.',
            ];
        }

        if ($userId !== null && $coupon['per_user_limit'] !== null) {
            $stmt = Db::pdo()->prepare(
                'SELECT COUNT(*) FROM ' . Db::table('coupon_redemptions') . ' WHERE coupon_id = ? AND user_id = ?'
            );
            $stmt->execute([(int) $coupon['id'], $userId]);
            if ((int) $stmt->fetchColumn() >= (int) $coupon['per_user_limit']) {
                return ['code' => 'COUPON_ALREADY_USED', 'message' => 'You have already used that code.'];
            }
        }

        return null;
    }

    /** Discount amount a coupon grants against a subtotal, clamped at the subtotal. */
    public static function couponDiscount(array $coupon, string $subtotal): string
    {
        $subtotal = Money::norm($subtotal);
        $value = Money::norm($coupon['value']);

        $discount = $coupon['type'] === 'percent'
            ? Money::percentOf($subtotal, $value)
            : Money::min($value, $subtotal);

        if (!Money::gte($discount, Money::ZERO) || Money::isZero($discount)) {
            return Money::ZERO;
        }

        return Money::min($discount, $subtotal);
    }

    /**
     * Resolve a code to an applicable coupon and its discount.
     *
     * $lock is set by checkout: `FOR UPDATE` takes the coupon row lock so two
     * simultaneous checkouts of a one-use code serialise, and the loser sees
     * the incremented counter and is rejected — rather than both orders being
     * written against a usage limit that is only checked after the fact.
     *
     * @return array{coupon: array<string, mixed>|null, discount: string, error: array{code: string, message: string}|null}
     */
    public static function resolveCoupon(string $code, string $subtotal, ?int $userId = null, bool $lock = false): array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return ['coupon' => null, 'discount' => Money::ZERO, 'error' => null];
        }

        $sql = 'SELECT id, code, type, value, min_subtotal, max_uses, used_count, per_user_limit, starts_at, ends_at, is_active
                FROM ' . Db::table('coupons') . ' WHERE code = ? LIMIT 1' . ($lock ? ' FOR UPDATE' : '');
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute([$code]);
        $coupon = $stmt->fetch();

        if ($coupon === false) {
            return [
                'coupon' => null,
                'discount' => Money::ZERO,
                'error' => ['code' => 'COUPON_NOT_FOUND', 'message' => 'That code is not valid.'],
            ];
        }

        $reason = self::couponBlockedReason($coupon, $subtotal, $userId);
        if ($reason !== null) {
            return ['coupon' => null, 'discount' => Money::ZERO, 'error' => $reason];
        }

        return ['coupon' => $coupon, 'discount' => self::couponDiscount($coupon, $subtotal), 'error' => null];
    }

    /**
     * Full price breakdown for a cart. This is the single source of truth used
     * by both POST /api/quote and the order written by checkout, so the number
     * quoted and the number charged cannot drift apart.
     *
     * @param list<array{unit_price: string, quantity: int|float|string}> $lines
     * @param array{coupon: array<string, mixed>|null, discount: string, error: array<string, string>|null}|null $resolvedCoupon
     *        Pre-resolved coupon. Checkout passes this after locking the coupon
     *        row, so the figure it stores and the figure it quoted come from
     *        the same code path but only one of them pays for the lock.
     * @return array<string, mixed>
     */
    public static function quote(
        array $lines,
        string $country,
        ?string $region,
        ?string $couponCode,
        ?string $shippingCode,
        ?int $userId = null,
        ?array $resolvedCoupon = null
    ): array {
        $subtotal = Money::ZERO;
        $itemCount = 0.0;
        foreach ($lines as $line) {
            $qty = (string) $line['quantity'];
            $subtotal = Money::add($subtotal, Money::mul(Money::norm($line['unit_price']), $qty));
            $itemCount += (float) $qty;
        }

        $coupon = null;
        $discount = Money::ZERO;
        $couponError = null;
        if ($resolvedCoupon !== null) {
            $coupon = $resolvedCoupon['coupon'];
            $discount = $resolvedCoupon['discount'];
            $couponError = $resolvedCoupon['error'];
        } elseif ($couponCode !== null && trim($couponCode) !== '') {
            $resolved = self::resolveCoupon($couponCode, $subtotal, $userId);
            $coupon = $resolved['coupon'];
            $discount = $resolved['discount'];
            $couponError = $resolved['error'];
        }

        $taxable = Money::max(Money::sub($subtotal, $discount), Money::ZERO);
        $tax = self::taxFor($country, $region);
        $taxAmount = Money::mulRate($taxable, $tax['rate']);

        $methods = self::shippingMethods($country);
        $priced = [];
        $selected = null;
        foreach ($methods as $method) {
            $option = self::priceShipping($method, $subtotal, $itemCount);
            $priced[] = $option;
            if ($shippingCode !== null && strcasecmp($option['code'], $shippingCode) === 0) {
                $selected = $option;
            }
        }
        if ($selected === null) {
            $selected = $priced === [] ? null : self::cheapestOption($priced);
        }

        $shippingPrice = $selected['price'] ?? Money::ZERO;
        $total = Money::round(Money::add(Money::add($taxable, $taxAmount), $shippingPrice));

        return [
            'currency' => 'USD',
            'item_count' => $itemCount,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'taxable' => $taxable,
            'tax' => [
                'name' => $tax['name'],
                'rate' => $tax['rate'],
                'amount' => $taxAmount,
            ],
            'shipping' => [
                'code' => $selected['code'] ?? null,
                'name' => $selected['name'] ?? null,
                'amount' => $shippingPrice,
                'options' => $priced,
            ],
            'total' => $total,
            'coupon' => $coupon === null || $couponError !== null ? null : [
                'id' => (int) $coupon['id'],
                'code' => (string) $coupon['code'],
                'type' => (string) $coupon['type'],
                'value' => Money::norm($coupon['value']),
                'discount' => $discount,
            ],
            'coupon_error' => $couponError,
        ];
    }

    /**
     * @param list<array{code: string, name: string, price: string, free: bool}> $options
     * @return array{code: string, name: string, price: string, free: bool}
     */
    private static function cheapestOption(array $options): array
    {
        $best = $options[0];
        foreach ($options as $option) {
            if (bccomp($option['price'], $best['price'], 2) < 0) {
                $best = $option;
            }
        }

        return $best;
    }
}
