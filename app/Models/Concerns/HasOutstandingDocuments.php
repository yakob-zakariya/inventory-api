<?php

namespace App\Models\Concerns;

use App\Models\Purchase;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * What is still owed on a party's active documents: a customer's receivable (its sales)
 * or a supplier's payable (its purchases). Calculated from the ledger, never stored.
 * The model defines documentClass().
 */
trait HasOutstandingDocuments
{
    /**
     * @return class-string<Sale|Purchase>
     */
    abstract protected static function documentClass(): string;

    /**
     * Load the outstanding amount with the query, so lists avoid one query per row.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withOutstanding(Builder $query): void
    {
        if ($query->getQuery()->columns === null) {
            $query->select($this->qualifyColumn('*'));
        }

        $query->addSelect(static::documentClass()::outstandingSubqueries($this->getForeignKey(), $this->getQualifiedKeyName()));
    }

    /**
     * Only parties with something still owed on at least one active document.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function hasOutstanding(Builder $query): void
    {
        $query->whereExists(
            static::documentClass()::query()->owing()->whereColumn($this->getForeignKey(), $this->getQualifiedKeyName())->toBase(),
        );
    }

    /**
     * What is still owed, in cents: by the customer, or to the supplier.
     */
    public function outstandingCents(): int
    {
        $row = array_key_exists('outstanding_total_cents', $this->attributes)
            ? $this
            : $this->newQueryWithoutScopes()->withOutstanding()->whereKey($this->getKey())->first();

        return (int) $row->outstanding_total_cents
            - (int) $row->outstanding_paid_cents * static::documentClass()::paymentSign();
    }
}
