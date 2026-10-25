<?php

namespace App\Models;

use App\Enums\StockMovementType;
use App\Models\Concerns\RecordsCreator;
use App\Services\StockLedger;
use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * One row of the append-only stock ledger. Quantity is signed: positive in, negative out.
 * Write rows through {@see StockLedger}; never update or delete them.
 */
#[Fillable(['product_id', 'type', 'quantity', 'reference_type', 'reference_id', 'note'])]
class StockMovement extends Model
{
    /** @use HasFactory<StockMovementFactory> */
    use HasFactory, RecordsCreator;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Stock movements are append-only and cannot be updated.'));
        static::deleting(fn () => throw new LogicException('Stock movements are append-only and cannot be deleted.'));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'quantity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * The purchase or sale that caused this movement, if any.
     *
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
