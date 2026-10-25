<?php

namespace App\Http\Resources;

use App\Models\Account;
use App\Services\BusinessSummary;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property BusinessSummary $resource
 */
class BusinessSummaryResource extends JsonResource
{
    /**
     * Transform the resource into an array. Net worth counts inventory at cost; the
     * selling-price value is shown for information only.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $summary = $this->resource;

        return [
            'accounts' => [
                'total' => Money::fromCents($summary->accountsCents),
                'items' => $summary->accounts->map(fn (Account $account) => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'type' => $account->type,
                    'is_active' => $account->is_active,
                    'balance' => Money::fromCents($account->balanceCents()),
                    'is_negative' => $account->balanceCents() < 0,
                ]),
            ],
            'inventory' => [
                'at_cost' => Money::fromCents($summary->inventoryAtCostCents),
                'at_selling_price' => Money::fromCents($summary->inventoryAtSellingPriceCents),
            ],
            'receivables' => Money::fromCents($summary->receivablesCents),
            'payables' => Money::fromCents($summary->payablesCents),
            'net_worth' => Money::fromCents($summary->netWorthCents()),
            'as_of' => $summary->asOf,
        ];
    }
}
