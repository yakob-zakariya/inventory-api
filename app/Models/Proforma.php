<?php

namespace App\Models;

use App\Enums\ProformaStatus;
use App\Models\Concerns\RecordsCreator;
use App\Services\ProformaManager;
use Database\Factories\ProformaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A quote for a customer, which may later become a sale. Changed through {@see ProformaManager}.
 */
#[Fillable(['number', 'customer_id', 'customer_name', 'customer_phone', 'proforma_date', 'valid_until', 'total_amount', 'note', 'status'])]
class Proforma extends Model
{
    /** @use HasFactory<ProformaFactory> */
    use HasFactory, RecordsCreator;

    /**
     * Rows per page in API lists.
     */
    protected $perPage = 10;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'proforma_date' => 'date',
            'valid_until' => 'date',
            'total_amount' => 'decimal:2',
            'status' => ProformaStatus::class,
            'converted_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
     * @return HasMany<ProformaItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ProformaItem::class);
    }

    /**
     * The sale it was converted to.
     *
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Open, but past its valid-until date: it can no longer be edited or converted.
     */
    public function isExpired(): bool
    {
        return $this->status === ProformaStatus::Open && $this->valid_until->lt(today());
    }

    /**
     * The status to show: the stored one, or `expired` for an open proforma past its date.
     */
    public function displayStatus(): string
    {
        return $this->isExpired() ? 'expired' : $this->status->value;
    }

    /**
     * The customer's name: the registered customer's, or the name typed on the proforma.
     */
    public function customerDisplayName(): ?string
    {
        return $this->customer?->name ?? $this->customer_name;
    }

    /**
     * @param  Builder<self>  $query
     * @param  array{search?: string, status?: string, customer_id?: int, from?: string, to?: string}  $filters
     */
    #[Scope]
    protected function filter(Builder $query, array $filters): void
    {
        $query
            ->when($filters['search'] ?? null, fn (Builder $query, string $term) => $query->where(
                fn (Builder $query) => $query
                    ->whereLike('number', "%{$term}%")
                    ->orWhereLike('customer_name', "%{$term}%")
                    ->orWhereHas('customer', fn (Builder $query) => $query->whereLike('name', "%{$term}%")),
            ))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => match ($status) {
                'expired' => $query->where('status', ProformaStatus::Open->value)->whereDate('valid_until', '<', today()),
                'open' => $query->where('status', ProformaStatus::Open->value)->whereDate('valid_until', '>=', today()),
                default => $query->where('status', $status),
            })
            ->when($filters['customer_id'] ?? null, fn (Builder $query, $id) => $query->where('customer_id', $id))
            ->when($filters['from'] ?? null, fn (Builder $query, $from) => $query->whereDate('proforma_date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, $to) => $query->whereDate('proforma_date', '<=', $to));
    }
}
