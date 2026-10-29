<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Models\Product;
use App\Services\ImageStore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
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
            'category_id' => $this->category_id,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'sku' => $this->sku,
            'name' => $this->name,
            'description' => $this->description,
            'unit' => $this->unit,
            $this->mergeWhen(self::showsCosts($request), fn () => [
                'cost_price' => $this->cost_price,
                'additional_cost' => $this->additional_cost,
            ]),
            'selling_price' => $this->selling_price,
            'reorder_level' => $this->reorder_level,
            'image_url' => ImageStore::url($this->image_path),
            'stock' => $this->when(
                array_key_exists('stock', $this->getAttributes()),
                fn () => (int) $this->stock,
            ),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Costs are shown to users who see profit and to those who enter costs on purchases.
     */
    public static function showsCosts(Request $request): bool
    {
        return (bool) $request->user()?->canAny([Permission::CostsView->value, Permission::PurchasesCreate->value]);
    }
}
