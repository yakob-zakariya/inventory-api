<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\DocumentRules;
use App\Models\Customer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConvertProformaRequest extends FormRequest
{
    use DocumentRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request. Without `items`, every quoted item is sold;
     * with them, quantities may be lowered and items left out. Prices always come from the proforma.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', Rule::exists(Customer::class, 'id')->withoutTrashed()],
            'sale_date' => $this->documentDateRules(),
            'note' => ['nullable', 'string', 'max:2000'],
            'items' => ['sometimes', 'array', 'min:1', 'max:200'],
            'items.*' => ['required', 'array'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['prohibited'],
            ...$this->paymentRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->documentMessages(),
            'items.*.unit_price.prohibited' => 'Prices come from the proforma and cannot be changed when converting.',
        ];
    }
}
