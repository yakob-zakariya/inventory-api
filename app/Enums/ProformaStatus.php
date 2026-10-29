<?php

namespace App\Enums;

/**
 * The stored status of a proforma. "Expired" is not stored: it is an open proforma past its valid-until date.
 */
enum ProformaStatus: string
{
    case Open = 'open';
    case Converted = 'converted';
    case Cancelled = 'cancelled';
}
