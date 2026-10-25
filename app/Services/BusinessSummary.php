<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * What the business owns minus what it owes, right now:
 * account balances + inventory at cost + customer receivables - supplier payables.
 */
final readonly class BusinessSummary
{
    /**
     * @param  Collection<int, Account>  $accounts  with balances loaded
     */
    private function __construct(
        public Collection $accounts,
        public int $accountsCents,
        public int $inventoryAtCostCents,
        public int $inventoryAtSellingPriceCents,
        public int $receivablesCents,
        public int $payablesCents,
        public Carbon $asOf,
    ) {}

    public static function now(): self
    {
        $accounts = Account::query()->withBalance()->orderBy('name')->get();
        $inventory = Product::inventoryValueCents();

        return new self(
            accounts: $accounts,
            accountsCents: $accounts->sum(fn (Account $account) => $account->balanceCents()),
            inventoryAtCostCents: $inventory['cost'],
            inventoryAtSellingPriceCents: $inventory['selling'],
            receivablesCents: Sale::outstandingCents(),
            payablesCents: Purchase::outstandingCents(),
            asOf: now(),
        );
    }

    public function netWorthCents(): int
    {
        return $this->accountsCents + $this->inventoryAtCostCents + $this->receivablesCents - $this->payablesCents;
    }
}
