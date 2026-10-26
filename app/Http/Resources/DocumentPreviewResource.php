<?php

namespace App\Http\Resources;

use App\Services\PurchaseDraft;
use App\Services\SaleDraft;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A priced, unsaved sale or purchase. Sale lines show unit_price, purchase lines unit_cost.
 *
 * @property SaleDraft|PurchaseDraft $resource
 */
class DocumentPreviewResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $draft = $this->resource;
        $priceKey = $draft instanceof SaleDraft ? 'unit_price' : 'unit_cost';

        return [
            'items' => array_map(fn (array $line) => [
                'product_id' => $line['product']->id,
                'product' => $line['product']->only(['id', 'sku', 'name']),
                'quantity' => $line['quantity'],
                $priceKey => Money::fromCents($line["{$priceKey}_cents"]),
                'line_total' => Money::fromCents($line['quantity'] * $line["{$priceKey}_cents"]),
            ], $draft->lines),
            'total_amount' => Money::fromCents($draft->totalCents),
            'paid_amount' => Money::fromCents($draft->payments->totalCents),
            'balance_due' => Money::fromCents($draft->totalCents - $draft->payments->totalCents),
            'payment_status' => $draft->paymentStatus(),
        ];
    }
}
