<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Proforma;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Change an open proforma: any of the fields below. Items, when sent, replace the whole list.
 */
class UpdateProformaRequest extends StoreProformaRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Proforma $proforma */
        $proforma = $this->route('proforma');
        $itemRules = $this->itemRules();
        $itemRules['items'] = ['sometimes', 'array', 'min:1', 'max:200'];

        return [
            ...$this->customerRules(),
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'valid_until' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:'.$proforma->proforma_date->toDateString()],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ...$itemRules,
            'items.*.unit_price' => ['nullable', ...Money::rules()],
            'proforma_date' => ['prohibited'],
        ];
    }
}
