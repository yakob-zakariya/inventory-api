<?php

namespace App\Http\Resources;

use App\Models\Account;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a reconciliation would record. `difference` is counted minus balance: negative means
 * money is missing, "0.00" means the count already matches (saving would be refused).
 *
 * @property array{account: Account, balance_cents: int, counted_cents: int} $resource
 */
class ReconciliationPreviewResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'account_id' => $this->resource['account']->id,
            'balance' => Money::fromCents($this->resource['balance_cents']),
            'counted_balance' => Money::fromCents($this->resource['counted_cents']),
            'difference' => Money::fromCents($this->resource['counted_cents'] - $this->resource['balance_cents']),
        ];
    }
}
