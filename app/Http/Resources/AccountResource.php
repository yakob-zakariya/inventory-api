<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Models\Account;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Account
 */
class AccountResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'opening_balance' => $this->opening_balance,
            'is_active' => $this->is_active,
            $this->mergeWhen($this->canSeeBalances($request), function () {
                $balance = $this->balanceCents();

                return ['balance' => Money::fromCents($balance), 'is_negative' => $balance < 0];
            }),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Staff may pick an account for a payment without seeing how much money is in it.
     */
    private function canSeeBalances(Request $request): bool
    {
        return (bool) $request->user()?->canAny([
            Permission::AccountsManage->value,
            Permission::ReportsView->value,
        ]);
    }
}
