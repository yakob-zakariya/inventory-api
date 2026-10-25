<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Hands out sequential document numbers such as SAL-000204.
 */
class DocumentNumberer
{
    /**
     * Lock the sequence row, increment it and format the number. Must run inside the
     * transaction that creates the document, so a rollback also gives the number back.
     */
    public function next(DocumentType $type): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Document numbers must be taken inside a database transaction.');
        }

        $sequence = DocumentSequence::query()
            ->where('type', $type->value)
            ->lockForUpdate()
            ->firstOrFail();

        $sequence->increment('last_number');

        return sprintf('%s-%06d', $type->prefix(), $sequence->last_number);
    }
}
