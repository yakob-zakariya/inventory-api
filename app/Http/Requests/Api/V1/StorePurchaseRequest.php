<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\DocumentRules;
use App\Models\Supplier;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseRequest extends FormRequest
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
     * Get the validation rules that apply to the request. The total is computed by the server.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', Rule::exists(Supplier::class, 'id')->withoutTrashed()],
            'invoice_number' => ['nullable', 'string', 'max:100'],
            'purchase_date' => $this->documentDateRules(),
            'note' => ['nullable', 'string', 'max:2000'],
            ...$this->itemRules(),
            'items.*.unit_cost' => ['required', ...Money::rules()],
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
