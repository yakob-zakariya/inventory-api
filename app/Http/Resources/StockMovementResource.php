<?php

namespace App\Http\Resources;

use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockMovement
 */
class StockMovementResource extends JsonResource
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
            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'sku' => $this->product->sku,
                'name' => $this->product->name,
            ]),
            'type' => $this->type,
            'quantity' => $this->quantity,
            'note' => $this->note,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
        ];
    }
}
