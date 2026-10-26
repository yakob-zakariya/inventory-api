<?php

namespace App\Http\Resources;

use App\Models\AccountTransaction;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A ledger row shown on its document. The amount is from the document's point of view:
 * positive is a payment, negative a reversal, whatever direction the money moved.
 *
 * @mixin AccountTransaction
 */
class DocumentPaymentResource extends JsonResource
{
    public function __construct(AccountTransaction $resource, private int $sign = 1)
    {
        parent::__construct($resource);
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account' => $this->whenLoaded('account', fn () => [
                'id' => $this->account->id,
                'name' => $this->account->name,
            ]),
            'type' => $this->type,
            'amount' => Money::fromCents(Money::toCents($this->amount) * $this->sign),
            'transaction_date' => $this->transaction_date->toDateString(),
            'receipt_reference' => $this->receipt_reference,
            'note' => $this->note,
            'reverses_transaction_id' => $this->reverses_transaction_id,
            'reversed_by_transaction_id' => $this->whenLoaded('reversal', fn () => $this->reversal?->id),
            'created_by' => $this->created_by,
            'created_by_user' => $this->creator?->only(['id', 'name']),
            'created_at' => $this->created_at,
        ];
    }
}
