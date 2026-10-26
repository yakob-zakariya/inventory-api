<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AccountTransactionType;
use App\Enums\Permission;
use App\Http\Requests\Api\V1\Concerns\MoneyRules;
use App\Models\AccountTransaction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReverseAccountTransactionRequest extends FormRequest
{
    use MoneyRules;

    /**
     * Reversing a document payment needs payments.void; any other entry needs accounts.manage.
     */
    public function authorize(): bool
    {
        $permission = in_array($this->original()->type, [AccountTransactionType::SalePayment, AccountTransactionType::PurchasePayment], true)
            ? Permission::PaymentsVoid
            : Permission::AccountsManage;

        return $this->user()->can($permission->value);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The reversal is dated today unless an earlier date is sent, never before the original entry.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
            'transaction_date' => [
                ...$this->transactionDateRules(),
                'after_or_equal:'.$this->original()->transaction_date->toDateString(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'A reason is required to reverse an entry.',
            'transaction_date.after_or_equal' => 'A reversal cannot be dated before the entry it reverses.',
        ];
    }

    private function original(): AccountTransaction
    {
        return $this->route('accountTransaction');
    }
}
