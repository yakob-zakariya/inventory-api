<?php

namespace App\Enums;

/**
 * Numbered document types. The value is the `document_sequences.type` row.
 */
enum DocumentType: string
{
    case Purchase = 'purchase';
    case Sale = 'sale';
    case Proforma = 'proforma';

    public function prefix(): string
    {
        return match ($this) {
            self::Purchase => 'PUR',
            self::Sale => 'SAL',
            self::Proforma => 'PRF',
        };
    }
}
