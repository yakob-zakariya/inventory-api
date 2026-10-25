<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
{
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
     * The opening balance is financial history: it is corrected with an
     * adjustment transaction, never edited.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique(Account::class)->ignore($this->route('account'))],
            'type' => ['sometimes', 'required', Rule::enum(AccountType::class)],
            'is_active' => ['sometimes', 'boolean'],
            'opening_balance' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'opening_balance.prohibited' => 'The opening balance cannot be changed. Record an adjustment instead.',
        ];
    }
}
