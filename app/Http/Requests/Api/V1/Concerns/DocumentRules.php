<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\Account;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Validation\Rule;

/**
 * Validation shared by purchases and sales.
 */
trait DocumentRules
{
    /**
     * The document date: optional (defaults to today) and never in the future (rule 11).
     *
     * @return list<mixed>
     */
    protected function documentDateRules(): array
    {
        return ['sometimes', 'date_format:Y-m-d', 'before_or_equal:today'];
    }

    /**
     * Items: at least one, each product once (rule 8), only products that are not deleted (rule 12).
     *
     * @return array<string, list<mixed>>
     */
    protected function itemRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*' => ['required', 'array'],
            'items.*.product_id' => ['required', 'integer', 'distinct', Rule::exists(Product::class, 'id')->withoutTrashed()],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    /**
     * Initial payments into or out of active accounts.
     *
     * @return array<string, list<mixed>>
     */
    protected function paymentRules(): array
    {
        return [
            'payments' => ['sometimes', 'array', 'max:10'],
            'payments.*' => ['required', 'array'],
            'payments.*.account_id' => ['required', 'integer', Rule::exists(Account::class, 'id')->where('is_active', true)],
            'payments.*.amount' => ['required', ...Money::rules(), 'gt:0'],
            'payments.*.receipt_reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function documentMessages(): array
    {
        return [
            'items.*.product_id.distinct' => 'Each product may appear only once on a document.',
            'items.*.product_id.exists' => 'The selected product does not exist or has been deleted.',
            'payments.*.account_id.exists' => 'The selected account does not exist or is inactive.',
        ];
    }
}
