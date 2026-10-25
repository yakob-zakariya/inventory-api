<?php

use App\Enums\AccountTransactionType;
use App\Models\Account;
use App\Models\AccountTransaction;
use Illuminate\Support\Facades\DB;

it('calculates the balance from the opening balance and every transaction', function () {
    actingAsOwner();
    $account = Account::factory()->create(['opening_balance' => '1000.00']);
    AccountTransaction::factory()->for($account)->create(['type' => AccountTransactionType::SalePayment, 'amount' => '250.50']);
    AccountTransaction::factory()->for($account)->create(['type' => AccountTransactionType::Expense, 'amount' => '-100.25', 'note' => 'Rent']);

    $this->getJson("/api/v1/accounts/{$account->id}")
        ->assertOk()
        ->assertJsonPath('data.balance', '1150.25')
        ->assertJsonPath('data.is_negative', false);
});

it('flags a negative balance instead of hiding it', function () {
    actingAsOwner();
    $account = Account::factory()->create(['opening_balance' => '50.00']);
    AccountTransaction::factory()->for($account)->create(['type' => AccountTransactionType::Expense, 'amount' => '-80.00', 'note' => 'Fuel']);

    $this->getJson("/api/v1/accounts/{$account->id}")
        ->assertJsonPath('data.balance', '-30.00')
        ->assertJsonPath('data.is_negative', true);
});

it('lists balances without one query per account', function () {
    actingAsOwner();
    Account::factory()->count(5)->create()->each(
        fn (Account $account) => AccountTransaction::factory()->for($account)->create(),
    );

    DB::enableQueryLog();
    $this->getJson('/api/v1/accounts')->assertOk()->assertJsonCount(5, 'data');
    $ledgerQueries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'account_transactions'));

    expect($ledgerQueries)->toHaveCount(1);
});

it('hides balances from staff but still lists the accounts', function () {
    actingAsStaff();
    Account::factory()->create();

    $this->getJson('/api/v1/accounts')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonMissingPath('data.0.balance')
        ->assertJsonMissingPath('data.0.is_negative');
});

it('lists the money ledger with filters for report viewers', function () {
    actingAsOwner();
    $cash = Account::factory()->create();
    $bank = Account::factory()->create();
    AccountTransaction::factory()->for($cash)->create(['transaction_date' => '2026-10-01']);
    AccountTransaction::factory()->for($cash)->create(['type' => AccountTransactionType::Expense, 'amount' => '-5.00', 'note' => 'Tea', 'transaction_date' => '2026-10-10']);
    AccountTransaction::factory()->for($bank)->create(['transaction_date' => '2026-10-20']);

    $this->getJson('/api/v1/account-transactions')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.transaction_date', '2026-10-20')
        ->assertJsonPath('data.1.amount', '-5.00')
        ->assertJsonPath('data.1.account.id', $cash->id);

    $this->getJson("/api/v1/account-transactions?account_id={$cash->id}")->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/account-transactions?type=expense')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/account-transactions?from=2026-10-05&to=2026-10-15')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/account-transactions?type=gift')->assertUnprocessable();
});

it('keeps the money ledger from staff', function () {
    actingAsStaff();

    $this->getJson('/api/v1/account-transactions')->assertForbidden();
});
