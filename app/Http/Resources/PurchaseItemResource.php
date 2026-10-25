<?php

namespace App\Http\Resources;

use App\Models\PurchaseItem;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseItem
 */
class PurchaseItemResource extends JsonResource
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
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'sku' => $this->product->sku,
                'name' => $this->product->name,
                'unit' => $this->product->unit,
            ]),
            'quantity' => $this->quantity,
            'unit_cost' => $this->unit_cost,
            'line_total' => Money::fromCents($this->lineTotalCents()),
        ];
    }
}
