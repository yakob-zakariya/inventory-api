<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Models\SaleItem;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SaleItem
 */
class SaleItemResource extends JsonResource
{
    /**
     * Transform the resource into an array. The cost (and so the margin) is shown
     * only to users who can view reports.
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
            'unit_price' => $this->unit_price,
            'unit_cost' => $this->when(
                (bool) $request->user()?->can(Permission::CostsView->value),
                fn () => $this->unit_cost,
            ),
            'line_total' => Money::fromCents($this->lineTotalCents()),
        ];
    }
}
