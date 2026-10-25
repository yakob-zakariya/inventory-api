<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\MoneyRules;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOwnerEntryRequest extends FormRequest
{
    use MoneyRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * A deposit puts the owner's money into an account; a withdrawal takes it out.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['deposit', 'withdrawal'])],
            'account_id' => $this->activeAccountRules(),
            'amount' => ['required', ...Money::rules(), 'gt:0'],
            'transaction_date' => $this->transactionDateRules(),
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...$this->accountMessages()];
    }
}
