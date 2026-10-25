<?php

namespace App\Models\Concerns;

use App\Enums\PaymentStatus;
use App\Models\AccountTransaction;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Paid amount, balance due and payment status of a document, calculated from the
 * money ledger rows that reference it. The model defines paymentSign(): 1 when its
 * payments are money in (sales), -1 when they are money out (purchases).
 */
trait HasPayments
{
    abstract public static function paymentSign(): int;

    /**
     * @return MorphMany<AccountTransaction, $this>
     */
    public function payments(): MorphMany
    {
        return $this->morphMany(AccountTransaction::class, 'reference');
    }

    /**
     * Load the payment total with the query, so lists avoid one query per document.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withPaymentTotals(Builder $query): void
    {
        $query->withSum('payments as payments_sum', 'amount');
    }

    public function paidCents(): int
    {
        $sum = array_key_exists('payments_sum', $this->attributes)
            ? $this->attributes['payments_sum']
            : $this->payments()->sum('amount');

        return Money::toCents($sum ?? 0) * static::paymentSign();
    }

    public function balanceDueCents(): int
    {
        return Money::toCents($this->total_amount) - $this->paidCents();
    }

    public function paymentStatus(): PaymentStatus
    {
        $paid = $this->paidCents();

        return match (true) {
            $paid <= 0 => PaymentStatus::Unpaid,
            $paid < Money::toCents($this->total_amount) => PaymentStatus::Partial,
            default => PaymentStatus::Paid,
        };
    }
}
