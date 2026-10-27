<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{name: string, address: ?string, phone: ?string, email: ?string, tin: ?string, currency: string} $resource
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
            'name' => $this->resource['name'],
            'address' => $this->resource['address'],
            'phone' => $this->resource['phone'],
            'email' => $this->resource['email'],
            'tin' => $this->resource['tin'],
            'currency' => $this->resource['currency'],
        ];
    }
}
