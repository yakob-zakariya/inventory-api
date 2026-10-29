<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Services\ImageStore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A product as a customer sees it: no cost and no stock number.
 *
 * @mixin Product
 */
class PriceListItemResource extends JsonResource
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
            'unit' => $this->unit,
            'selling_price' => $this->selling_price,
            'image_url' => ImageStore::url($this->image_path),
            'available' => (int) $this->stock > 0,
        ];
    }
}
