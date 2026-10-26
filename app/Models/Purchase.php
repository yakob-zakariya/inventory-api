<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Models\Concerns\HasPayments;
use App\Models\Concerns\RecordsCreator;
use App\Services\PurchaseRecorder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A purchase from a supplier. Created through {@see PurchaseRecorder}.
 */
#[Fillable(['number', 'supplier_id', 'invoice_number', 'purchase_date', 'total_amount', 'note', 'status'])]
class Purchase extends Model
{
    use HasPayments, RecordsCreator;

    /**
     * Rows per page in API lists.
     */
    protected $perPage = 10;

    /**
     * Purchase payments are money out of an account.
     */
    public static function paymentSign(): int
    {
        return -1;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'total_amount' => 'decimal:2',
            'status' => DocumentStatus::class,
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /**
     * @return HasMany<PurchaseItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    /**
     * @return MorphMany<StockMovement, $this>
     */
    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    /**
     * @param  Builder<self>  $query
     * @param  array{supplier_id?: int, status?: string, from?: string, to?: string}  $filters
     */
    #[Scope]
    protected function filter(Builder $query, array $filters): void
    {
        $query
            ->when($filters['supplier_id'] ?? null, fn (Builder $query, $id) => $query->where('supplier_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $query, $status) => $query->where('status', $status))
            ->when($filters['from'] ?? null, fn (Builder $query, $from) => $query->whereDate('purchase_date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, $to) => $query->whereDate('purchase_date', '<=', $to));
    }
}
