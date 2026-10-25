<?php

namespace App\Support;

/**
 * Money helpers. Amounts are stored as decimal(12,2); arithmetic is done in whole cents.
 */
final class Money
{
    /**
     * Validation rules for a non-negative amount with at most two decimal places.
     *
     * @return list<string>
     */
    public static function rules(): array
    {
        return ['numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'];
    }

    /**
     * Validation rules for an amount that may be negative, with at most two decimal places.
     *
     * @return list<string>
     */
    public static function signedRules(): array
    {
        return ['numeric', 'decimal:0,2', 'min:-9999999999.99', 'max:9999999999.99'];
    }

    /**
     * Convert an amount ("12.50", 12.5 or 12) to whole cents.
     *
     * Validation limits amounts to two decimals and |amount| < 10^10, where rounding
     * the float to the nearest cent is always exact.
     */
    public static function toCents(string|int|float $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    /**
     * Convert whole cents to the decimal(12,2) string used in storage and JSON.
     */
    public static function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($cents, 100), $cents % 100);
    }
}
