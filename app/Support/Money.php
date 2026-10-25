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
}
