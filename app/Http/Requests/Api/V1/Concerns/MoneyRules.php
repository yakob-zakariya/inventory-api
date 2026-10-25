<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\Account;
use Illuminate\Validation\Rule;

/**
 * Validation shared by requests that write to the money ledger.
 */
trait MoneyRules
{
    /**
     * An existing, active account.
     *
     * @return list<mixed>
     */
    protected function activeAccountRules(): array
    {
        return ['required', 'integer', Rule::exists(Account::class, 'id')->where('is_active', true)];
    }

    /**
     * The transaction date: optional (defaults to today) and never in the future.
     *
     * @return list<mixed>
     */
    protected function transactionDateRules(): array
    {
        return ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today'];
    }

    /**
     * @return array<string, string>
     */
    protected function accountMessages(): array
    {
        return [
            '*account_id.exists' => 'The selected account does not exist or is inactive.',
        ];
    }
}
