<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Models\Concerns\RecordsCreator;
use App\Support\Money;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A place where the shop keeps money. Accounts are deactivated, never deleted,
 * and the opening balance is fixed once the account exists.
 */
#[Fillable(['name', 'type', 'opening_balance', 'is_active'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory, RecordsCreator;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'opening_balance' => '0.00',
        'is_active' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'opening_balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<AccountTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(AccountTransaction::class);
    }

    /**
     * Load the sum of each account's transactions with the query, for balanceCents().
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withBalance(Builder $query): void
    {
        $query->withSum('transactions as transactions_sum', 'amount');
    }

    /**
     * Current balance: the opening balance plus every transaction, always calculated.
     */
    public function balanceCents(): int
    {
        $sum = array_key_exists('transactions_sum', $this->attributes)
            ? $this->attributes['transactions_sum']
            : $this->transactions()->sum('amount');

        return Money::toCents($this->opening_balance) + Money::toCents($sum ?? 0);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Filter by a partial name match.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function search(Builder $query, ?string $term): void
    {
        $query->when($term, fn (Builder $query) => $query->whereLike('name', "%{$term}%"));
    }
}
