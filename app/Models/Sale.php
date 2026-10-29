<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\PaymentStatus;
use App\Models\Concerns\HasPayments;
use App\Models\Concerns\RecordsCreator;
use App\Services\SaleRecorder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A sale, to a registered customer or a walk-in. Created through {@see SaleRecorder}.
 */
#[Fillable(['number', 'customer_id', 'sale_date', 'total_amount', 'note', 'status'])]
class Sale extends Model
{
    use HasPayments, RecordsCreator;

    /**
     * Rows per page in API lists.
     */
    protected $perPage = 10;

    /**
     * Sale payments are money into an account.
     */
    public static function paymentSign(): int
    {
        return 1;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sale_date' => 'date',
            'total_amount' => 'decimal:2',
            'status' => DocumentStatus::class,
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * The proforma this sale was converted from, if any.
     *
     * @return HasOne<Proforma, $this>
     */
    public function proforma(): HasOne
    {
        return $this->hasOne(Proforma::class);
    }

    /**
     * The user who voided the document, if it is voided.
     *
     * @return BelongsTo<User, $this>
     */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * @return HasMany<SaleItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
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
     * @param  array{customer_id?: int, search?: string, payment_status?: string, status?: string, from?: string, to?: string}  $filters
     */
    #[Scope]
    protected function filter(Builder $query, array $filters): void
    {
        $query
            ->when($filters['customer_id'] ?? null, fn (Builder $query, $id) => $query->where('customer_id', $id))
            ->when($filters['search'] ?? null, fn (Builder $query, string $term) => $query->where(
                fn (Builder $query) => $query
                    ->whereLike('number', "%{$term}%")
                    ->orWhereHas('customer', fn (Builder $query) => $query->whereLike('name', "%{$term}%")),
            ))
            ->when($filters['payment_status'] ?? null, fn (Builder $query, string $status) => $query->withPaymentStatus(PaymentStatus::from($status)))
            ->when($filters['status'] ?? null, fn (Builder $query, $status) => $query->where('status', $status))
            ->when($filters['from'] ?? null, fn (Builder $query, $from) => $query->whereDate('sale_date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, $to) => $query->whereDate('sale_date', '<=', $to));
    }
}
