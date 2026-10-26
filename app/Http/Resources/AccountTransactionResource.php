<?php

namespace App\Http\Resources;

use App\Models\AccountTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A money ledger row with its signed amount: positive in, negative out.
 *
 * @mixin AccountTransaction
 */
class AccountTransactionResource extends JsonResource
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
            'account' => $this->whenLoaded('account', fn () => [
                'id' => $this->account->id,
                'name' => $this->account->name,
            ]),
            'account_id' => $this->account_id,
            'type' => $this->type,
            'amount' => $this->amount,
            'transaction_date' => $this->transaction_date->toDateString(),
            'note' => $this->note,
            'receipt_reference' => $this->receipt_reference,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'transfer_group' => $this->transfer_group,
            'reverses_transaction_id' => $this->reverses_transaction_id,
            'reversed_by_transaction_id' => $this->whenLoaded('reversal', fn () => $this->reversal?->id),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
        ];
    }
}
