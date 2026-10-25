<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Permission;
use App\Models\Account;
use App\Models\Purchase;
use App\Models\Sale;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    /**
     * Paying a purchase also needs purchases.create: staff take payments for sales only.
     */
    public function authorize(): bool
    {
        return $this->input('document_type') !== 'purchase'
            || $this->user()->can(Permission::PurchasesCreate->value);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $documentClass = match ($this->input('document_type')) {
            'sale' => Sale::class,
            'purchase' => Purchase::class,
            default => null,
        };

        return [
            'document_type' => ['required', Rule::in(['sale', 'purchase'])],
            'document_id' => ['required', 'integer', ...($documentClass ? [Rule::exists($documentClass, 'id')] : [])],
            'account_id' => ['required', 'integer', Rule::exists(Account::class, 'id')->where('is_active', true)],
            'amount' => ['required', ...Money::rules(), 'gt:0'],
            'transaction_date' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today'],
            'receipt_reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'account_id.exists' => 'The selected account does not exist or is inactive.',
        ];
    }
}
