<?php

namespace App\Http\Resources;

use App\Models\Proforma;
use App\Models\ProformaItem;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `status` is open, expired, converted or cancelled. `customer_name` is the registered
 * customer's name or the typed one; `customer` is set only for a registered customer.
 *
 * @mixin Proforma
 */
class ProformaResource extends JsonResource
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
            'number' => $this->number,
            'customer_id' => $this->customer_id,
            'customer' => $this->customer?->only(['id', 'name', 'phone']),
            'customer_name' => $this->customerDisplayName(),
            'customer_phone' => $this->customer?->phone ?? $this->customer_phone,
            'proforma_date' => $this->proforma_date->toDateString(),
            'valid_until' => $this->valid_until->toDateString(),
            'status' => $this->displayStatus(),
            'is_expired' => $this->isExpired(),
            'total_amount' => $this->total_amount,
            'note' => $this->note,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (ProformaItem $item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product' => $item->product->only(['id', 'sku', 'name', 'unit']),
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'line_total' => Money::fromCents($item->lineTotalCents()),
            ])),
            'sale' => $this->sale?->only(['id', 'number']),
            'converted_at' => $this->converted_at,
            'cancelled_at' => $this->cancelled_at,
            'cancel_reason' => $this->cancel_reason,
            'cancelled_by_user' => $this->canceller?->only(['id', 'name']),
            'created_by' => $this->created_by,
            'created_by_user' => $this->creator?->only(['id', 'name']),
            'created_at' => $this->created_at,
        ];
    }
}
