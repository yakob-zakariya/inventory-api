<?php

namespace App\Http\Resources;

use App\Models\ShopSetting;
use App\Services\ImageStore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What anyone may see before logging in: the shop's name and logo, nothing else.
 *
 * @mixin ShopSetting
 */
class ShopBrandResource extends JsonResource
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
            'logo_url' => ImageStore::url($this->logo_path),
        ];
    }
}
