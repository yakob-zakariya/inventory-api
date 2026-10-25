<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\DocumentRules;
use App\Models\Customer;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleRequest extends FormRequest
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
     * Get the validation rules that apply to the request. The total is computed by the server;
     * a missing unit_price means the product's selling price.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', Rule::exists(Customer::class, 'id')->withoutTrashed()],
            'sale_date' => $this->documentDateRules(),
            'note' => ['nullable', 'string', 'max:2000'],
            ...$this->itemRules(),
            'items.*.unit_price' => ['nullable', ...Money::rules()],
            ...$this->paymentRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->documentMessages();
    }
}
