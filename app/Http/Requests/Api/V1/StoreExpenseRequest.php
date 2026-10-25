<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\MoneyRules;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseRequest extends FormRequest
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
     * Amount is what was spent (positive); the reason is required (salaries are expenses too).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'account_id' => $this->activeAccountRules(),
            'amount' => ['required', ...Money::rules(), 'gt:0'],
            'transaction_date' => $this->transactionDateRules(),
            'note' => ['required', 'string', 'max:1000'],
            'receipt_reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...$this->accountMessages(), 'note.required' => 'A reason is required for every expense.'];
    }
}
