<?php

namespace Database\Factories;

use App\Enums\AccountTransactionType;
use App\Models\Account;
use App\Models\AccountTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test helper only: application code writes transactions through MoneyLedger.
 *
 * @extends Factory<AccountTransaction>
 */
class AccountTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'type' => AccountTransactionType::OwnerDeposit,
            'amount' => '100.00',
            'transaction_date' => now()->toDateString(),
        ];
    }
}
