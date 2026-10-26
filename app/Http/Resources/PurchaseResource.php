<?php

namespace App\Http\Resources;

use App\Models\Purchase;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Purchase
 */
class PurchaseResource extends JsonResource
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
            'number' => $this->number,
            'supplier' => $this->whenLoaded('supplier', fn () => [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
            ]),
            'invoice_number' => $this->invoice_number,
            'purchase_date' => $this->purchase_date->toDateString(),
            'total_amount' => $this->total_amount,
            'paid_amount' => Money::fromCents($this->paidCents()),
            'balance_due' => Money::fromCents($this->balanceDueCents()),
            'payment_status' => $this->paymentStatus(),
            'status' => $this->status,
            'note' => $this->note,
            'voided_at' => $this->voided_at,
            'voided_by' => $this->voided_by,
            'voided_by_user' => $this->voider?->only(['id', 'name']),
            'void_reason' => $this->void_reason,
            'items' => PurchaseItemResource::collection($this->whenLoaded('items')),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(
                fn ($payment) => new DocumentPaymentResource($payment, Purchase::paymentSign()),
            )),
            'created_by' => $this->created_by,
            'created_by_user' => $this->creator?->only(['id', 'name']),
            'created_at' => $this->created_at,
        ];
    }
}
