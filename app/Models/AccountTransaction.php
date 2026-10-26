<?php

namespace App\Models;

use App\Enums\AccountTransactionType;
use App\Models\Concerns\RecordsCreator;
use App\Services\MoneyLedger;
use Database\Factories\AccountTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * One row of the append-only money ledger. Amount is signed: positive in, negative out.
 * Write rows through {@see MoneyLedger}; never update or delete them.
 */
#[Fillable([
    'account_id', 'type', 'amount', 'transaction_date', 'note', 'receipt_reference',
    'reference_type', 'reference_id', 'transfer_group', 'reverses_transaction_id',
])]
class AccountTransaction extends Model
{
    /** @use HasFactory<AccountTransactionFactory> */
    use HasFactory, RecordsCreator;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Account transactions are append-only and cannot be updated.'));
        static::deleting(fn () => throw new LogicException('Account transactions are append-only and cannot be deleted.'));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountTransactionType::class,
            'amount' => 'decimal:2',
            'transaction_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * The reversal row that cancelled this entry, if any.
     *
     * @return HasOne<AccountTransaction, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(AccountTransaction::class, 'reverses_transaction_id');
    }

    /**
     * The purchase or sale this payment belongs to, if any.
     *
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<self>  $query
     * @param  array{account_id?: int, type?: string, from?: string, to?: string}  $filters
     */
    #[Scope]
    protected function filter(Builder $query, array $filters): void
    {
        $query
            ->when($filters['account_id'] ?? null, fn (Builder $query, $id) => $query->where('account_id', $id))
            ->when($filters['type'] ?? null, fn (Builder $query, $type) => $query->where('type', $type))
            ->when($filters['from'] ?? null, fn (Builder $query, $from) => $query->whereDate('transaction_date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, $to) => $query->whereDate('transaction_date', '<=', $to));
    }
}
