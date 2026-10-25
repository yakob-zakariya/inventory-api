<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Support\Money;

it('records an expense as money out with its reason', function () {
    $owner = actingAsOwner();
    $cash = Account::factory()->create(['opening_balance' => '1000.00']);

    $this->postJson('/api/v1/expenses', [
        'account_id' => $cash->id, 'amount' => '250.50', 'note' => 'Salary for Abebe, October', 'receipt_reference' => 'PAY-10',
    ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'expense')
        ->assertJsonPath('data.amount', '-250.50')
        ->assertJsonPath('data.note', 'Salary for Abebe, October')
        ->assertJsonPath('data.receipt_reference', 'PAY-10')
        ->assertJsonPath('data.transaction_date', now()->toDateString())
        ->assertJsonPath('data.created_by', $owner->id);

    expect($cash->balanceCents())->toBe(74950);
});

it('requires a reason for an expense', function () {
    actingAsOwner();

    $this->postJson('/api/v1/expenses', ['account_id' => Account::factory()->create()->id, 'amount' => 10])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['note' => 'A reason is required for every expense.']);
});

it('moves money between accounts as a pair that sums to zero', function () {
    actingAsOwner();
    $cash = Account::factory()->create(['opening_balance' => '500.00']);
    $bank = Account::factory()->create(['opening_balance' => '0.00']);

    $this->postJson('/api/v1/transfers', [
        'from_account_id' => $cash->id, 'to_account_id' => $bank->id, 'amount' => '300.00', 'note' => 'Bank deposit',
    ])
        ->assertCreated()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.type', 'transfer_out')
        ->assertJsonPath('data.0.amount', '-300.00')
        ->assertJsonPath('data.1.type', 'transfer_in')
        ->assertJsonPath('data.1.amount', '300.00');

    $rows = AccountTransaction::all();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('transfer_group')->unique())->toHaveCount(1)
        ->and($rows->first()->transfer_group)->not->toBeNull()
        ->and($cash->balanceCents())->toBe(20000)
        ->and($bank->balanceCents())->toBe(30000);
});

it('validates a transfer', function (Closure $payload, string $field) {
    actingAsOwner();

    $this->postJson('/api/v1/transfers', $payload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'same account' => [function () {
        $id = Account::factory()->create()->id;

        return ['from_account_id' => $id, 'to_account_id' => $id, 'amount' => 10];
    }, 'to_account_id'],
    'inactive destination' => [fn () => [
        'from_account_id' => Account::factory()->create()->id,
        'to_account_id' => Account::factory()->inactive()->create()->id,
        'amount' => 10,
    ], 'to_account_id'],
    'negative amount' => [fn () => [
        'from_account_id' => Account::factory()->create()->id,
        'to_account_id' => Account::factory()->create()->id,
        'amount' => -10,
    ], 'amount'],
]);

it('records owner deposits and withdrawals', function () {
    actingAsOwner();
    $bank = Account::factory()->create(['opening_balance' => '0.00']);

    $this->postJson('/api/v1/owner-entries', ['type' => 'deposit', 'account_id' => $bank->id, 'amount' => '1000.00'])
        ->assertCreated()
        ->assertJsonPath('data.type', 'owner_deposit')
        ->assertJsonPath('data.amount', '1000.00');

    $this->postJson('/api/v1/owner-entries', ['type' => 'withdrawal', 'account_id' => $bank->id, 'amount' => '150.00', 'note' => 'School fees'])
        ->assertCreated()
        ->assertJsonPath('data.type', 'owner_withdrawal')
        ->assertJsonPath('data.amount', '-150.00');

    expect($bank->balanceCents())->toBe(85000);

    $this->postJson('/api/v1/owner-entries', ['type' => 'loan', 'account_id' => $bank->id, 'amount' => 1])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');
});

it('reconciles an account to the counted amount', function (string $counted, string $difference) {
    actingAsOwner();
    $cash = Account::factory()->create(['opening_balance' => '1000.00']);

    $this->postJson('/api/v1/account-adjustments', [
        'account_id' => $cash->id, 'counted_balance' => $counted, 'note' => 'Till count',
    ])
        ->assertCreated()
        ->assertJsonPath('data.type', 'adjustment')
        ->assertJsonPath('data.amount', $difference);

    expect($cash->balanceCents())->toBe(Money::toCents($counted));
})->with([
    'cash missing' => ['940.50', '-59.50'],
    'cash over' => ['1010.00', '10.00'],
    'overdrawn bank' => ['-20.00', '-1020.00'],
]);

it('refuses a reconciliation that changes nothing, or has no reason', function () {
    actingAsOwner();
    $cash = Account::factory()->create(['name' => 'Till', 'opening_balance' => '1000.00']);

    $this->postJson('/api/v1/account-adjustments', ['account_id' => $cash->id, 'counted_balance' => '1000.00', 'note' => 'Count'])
        ->assertUnprocessable()
        ->assertExactJson(['message' => 'The balance of "Till" already matches the counted amount.']);

    $this->postJson('/api/v1/account-adjustments', ['account_id' => $cash->id, 'counted_balance' => '900.00'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('note');
});

it('rejects future dates and inactive accounts', function () {
    actingAsOwner();

    $this->postJson('/api/v1/expenses', [
        'account_id' => Account::factory()->inactive()->create()->id,
        'amount' => 10,
        'note' => 'x',
        'transaction_date' => now()->addDay()->toDateString(),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'account_id' => 'The selected account does not exist or is inactive.',
            'transaction_date',
        ]);
});

it('keeps money entries from staff', function () {
    actingAsStaff();
    $cash = Account::factory()->create();

    $this->postJson('/api/v1/expenses', ['account_id' => $cash->id, 'amount' => 1, 'note' => 'x'])->assertForbidden();
    $this->postJson('/api/v1/transfers', [])->assertForbidden();
    $this->postJson('/api/v1/owner-entries', [])->assertForbidden();
    $this->postJson('/api/v1/account-adjustments', [])->assertForbidden();

    expect(AccountTransaction::count())->toBe(0);
});
