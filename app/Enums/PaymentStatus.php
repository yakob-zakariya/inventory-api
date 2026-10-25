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
}
