<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\DocumentRules;
use App\Models\Customer;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProformaRequest extends FormRequest
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
     * Get the validation rules that apply to the request. A registered customer or a typed name;
     * a missing unit_price means the product's selling price. No stock check: it is only a quote.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->customerRules(),
            'customer_name' => ['required_without:customer_id', 'nullable', 'string', 'max:255'],
            'proforma_date' => $this->documentDateRules(),
            'valid_until' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:'.($this->input('proforma_date') ?? today()->toDateString())],
            'note' => ['nullable', 'string', 'max:2000'],
            ...$this->itemRules(),
            'items.*.unit_price' => ['nullable', ...Money::rules()],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function customerRules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', Rule::exists(Customer::class, 'id')->withoutTrashed(), 'prohibits:customer_name,customer_phone'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->documentMessages(),
            'customer_name.required_without' => 'Choose a customer or type a name.',
            'customer_id.prohibits' => 'Choose a customer or type a name, not both.',
            'valid_until.after_or_equal' => 'The valid-until date cannot be before the proforma date.',
        ];
    }
}
