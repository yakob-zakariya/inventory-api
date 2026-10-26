<?php

namespace App\Enums;

/**
 * Derived from a document's payments, never stored.
 */
enum PaymentStatus: string
{
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';

    /**
     * The status of a document of the given total with the given amount paid, both in cents.
     */
    public static function forCents(int $paidCents, int $totalCents): self
    {
        return match (true) {
            $paidCents <= 0 => self::Unpaid,
            $paidCents < $totalCents => self::Partial,
            default => self::Paid,
        };
    }
}
