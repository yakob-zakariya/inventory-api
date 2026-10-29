<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\ShowsProfitHints;
use App\Services\SaleDraft;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Priced proforma lines and the total, with profit hints, nothing saved.
 *
 * @property SaleDraft $resource
 */
class ProformaPreviewResource extends JsonResource
{
    use ShowsProfitHints;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'items' => array_map(fn (array $line) => [
                'product_id' => $line['product']->id,
                'product' => $line['product']->only(['id', 'sku', 'name', 'unit']),
                'quantity' => $line['quantity'],
                'unit_price' => Money::fromCents($line['unit_price_cents']),
                'line_total' => Money::fromCents($line['quantity'] * $line['unit_price_cents']),
                ...$this->profitHints($request, $line),
            ], $this->resource->lines),
            'total_amount' => Money::fromCents($this->resource->totalCents),
            ...$this->totalProfitHint($request, $this->resource),
        ];
    }
}
