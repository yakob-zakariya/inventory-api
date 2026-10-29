<?php

namespace App\Http\Resources;

use App\Models\ShopSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ShopSetting
 */
class ShopResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'address' => $this->address,
            'phone' => $this->phone,
            'email' => $this->email,
            'tin' => $this->tin,
            'currency' => config('shop.currency'),
            'updated_at' => $this->updated_at,
        ];
    }
}
