<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Services\DocumentNumberer;
use Illuminate\Database\Eloquent\Model;

/**
 * The last number handed out for a document type. Use {@see DocumentNumberer}.
 */
class DocumentSequence extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'last_number' => 'integer',
        ];
    }
}
