<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Models\Supplier;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `payable` is what the shop still owes the supplier on active purchases, shown only to
 * users who can see purchases (purchases.create or reports.view).
 *
 * @mixin Supplier
 */
class SupplierResource extends JsonResource
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
            'contact_person' => $this->contact_person,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'payable' => $this->when(
                $request->user()?->canAny([Permission::PurchasesCreate->value, Permission::ReportsView->value]),
                fn () => Money::fromCents($this->outstandingCents()),
            ),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
