<?php

namespace App\Models\Concerns;

use App\Enums\DocumentStatus;
use App\Enums\PaymentStatus;
use App\Models\AccountTransaction;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

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

    /**
     * Total still owed on all active documents of this type, in cents, calculated in SQL.
     */
    public static function outstandingCents(): int
    {
        $active = static::query()->where('status', DocumentStatus::Active->value);

        $totalCents = (int) (clone $active)->sum(DB::raw('round(total_amount * 100)'));

        $paymentsCents = (int) AccountTransaction::query()
            ->where('reference_type', (new static)->getMorphClass())
            ->whereIn('reference_id', (clone $active)->select('id'))
            ->sum(DB::raw('round(amount * 100)'));

        return $totalCents - $paymentsCents * static::paymentSign();
    }

    /**
     * Subqueries for one party's active documents (the customer or supplier row in the outer query):
     * their total and the sum of their payment rows, both in cents.
     *
     * @return array{outstanding_total_cents: Builder<self>, outstanding_paid_cents: Builder<AccountTransaction>}
     */
    public static function outstandingSubqueries(string $partyForeignKey, string $partyKey): array
    {
        $documents = static::query()
            ->where('status', DocumentStatus::Active->value)
            ->whereColumn($partyForeignKey, $partyKey);

        return [
            'outstanding_total_cents' => (clone $documents)->selectRaw('coalesce(sum(round(total_amount * 100)), 0)'),
            'outstanding_paid_cents' => AccountTransaction::query()
                ->selectRaw('coalesce(sum(round(amount * 100)), 0)')
                ->where('reference_type', (new static)->getMorphClass())
                ->whereIn('reference_id', (clone $documents)->select('id')),
        ];
    }

    /**
     * Active documents that still have a balance due.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function owing(Builder $query): void
    {
        $query
            ->where('status', DocumentStatus::Active->value)
            ->where(DB::raw('round(total_amount * 100)'), '>', $this->paidCentsSubquery());
    }

    /**
     * Active documents with the given payment status, calculated in SQL like paymentStatus().
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withPaymentStatus(Builder $query, PaymentStatus $status): void
    {
        $paid = $this->paidCentsSubquery();
        $total = DB::raw('round(total_amount * 100)');

        $query->where('status', DocumentStatus::Active->value);

        match ($status) {
            PaymentStatus::Unpaid => $query->where($paid, '<=', 0),
            PaymentStatus::Partial => $query->where($paid, '>', 0)->where($paid, '<', $total),
            PaymentStatus::Paid => $query->where($paid, '>=', $total),
        };
    }

    /**
     * What was paid on the document row in the outer query, in cents (positive for payments).
     *
     * @return Builder<AccountTransaction>
     */
    private function paidCentsSubquery(): Builder
    {
        return AccountTransaction::query()
            ->selectRaw('coalesce(sum(round(amount * 100)), 0) * ?', [static::paymentSign()])
            ->where('reference_type', $this->getMorphClass())
            ->whereColumn('reference_id', $this->getQualifiedKeyName());
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
        return PaymentStatus::forCents($this->paidCents(), Money::toCents($this->total_amount));
    }
}
