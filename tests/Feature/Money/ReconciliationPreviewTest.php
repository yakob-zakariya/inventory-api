<?php

use App\Enums\AccountTransactionType;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    actingAsOwner();
    $this->cash = Account::factory()->create(['opening_balance' => '5000.00']);
    AccountTransaction::factory()->for($this->cash)->create(['type' => AccountTransactionType::Expense, 'amount' => '-1200.50', 'note' => 'Rent']);
});

it('shows the difference a reconciliation would record without saving it', function (string $counted, string $difference) {
    $this->postJson('/api/v1/account-adjustments/preview', ['account_id' => $this->cash->id, 'counted_balance' => $counted])
        ->assertOk()
        ->assertExactJson(['data' => [
            'account_id' => $this->cash->id,
            'balance' => '3799.50',
            'counted_balance' => number_format((float) $counted, 2, '.', ''),
            'difference' => $difference,
        ]]);

    expect(AccountTransaction::count())->toBe(1);
})->with([
    'money missing' => ['3649.50', '-150.00'],
    'money found' => ['3800', '0.50'],
    'already matches' => ['3799.50', '0.00'],
]);

it('accepts a body without a reason yet but validates the rest like saving', function () {
    $this->postJson('/api/v1/account-adjustments/preview', ['account_id' => $this->cash->id, 'counted_balance' => '1.005'])
        ->assertJsonValidationErrors('counted_balance')
        ->assertJsonMissingValidationErrors('note');

    $this->cash->update(['is_active' => false]);
    $this->postJson('/api/v1/account-adjustments/preview', ['account_id' => $this->cash->id, 'counted_balance' => '1.00'])
        ->assertJsonValidationErrors(['account_id' => 'The selected account does not exist or is inactive.']);
});

it('needs accounts.manage', function () {
    Sanctum::actingAs(User::factory()->create()->givePermissionTo('reports.view'));

    $this->postJson('/api/v1/account-adjustments/preview', ['account_id' => $this->cash->id, 'counted_balance' => '1.00'])
        ->assertForbidden();
});
