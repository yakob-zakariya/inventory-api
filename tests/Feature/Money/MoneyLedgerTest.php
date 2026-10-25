<?php

use App\Enums\AccountTransactionType;
use App\Exceptions\BusinessRuleException;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Services\MoneyLedger;
use Illuminate\Support\Facades\DB;

it('records a signed transaction in cents', function () {
    $account = Account::factory()->create();

    $transaction = DB::transaction(fn () => app(MoneyLedger::class)
        ->record($account, AccountTransactionType::SalePayment, 1250, '2026-10-20'));

    expect($transaction->amount)->toBe('12.50')
        ->and($transaction->transaction_date->toDateString())->toBe('2026-10-20')
        ->and($transaction->type)->toBe(AccountTransactionType::SalePayment);
});

it('enforces the sign each type expects', function (AccountTransactionType $type, int $cents) {
    $account = Account::factory()->create();

    expect(fn () => DB::transaction(fn () => app(MoneyLedger::class)->record($account, $type, $cents, now(), 'reason')))
        ->toThrow(LogicException::class);
})->with([
    'negative sale payment' => [AccountTransactionType::SalePayment, -100],
    'positive purchase payment' => [AccountTransactionType::PurchasePayment, 100],
    'positive expense' => [AccountTransactionType::Expense, 100],
    'zero adjustment' => [AccountTransactionType::Adjustment, 0],
]);

it('requires a reason for expenses, adjustments and reversals', function () {
    $account = Account::factory()->create();

    expect(fn () => DB::transaction(fn () => app(MoneyLedger::class)
        ->record($account, AccountTransactionType::Expense, -500, now())))
        ->toThrow(LogicException::class, 'needs a reason');
});

it('refuses an inactive account', function () {
    $account = Account::factory()->inactive()->create(['name' => 'Old till']);

    expect(fn () => DB::transaction(fn () => app(MoneyLedger::class)
        ->record($account, AccountTransactionType::SalePayment, 100, now())))
        ->toThrow(BusinessRuleException::class, 'The account "Old till" is inactive.');
});

it('never lets a transaction be updated or deleted', function () {
    $transaction = AccountTransaction::factory()->create(['amount' => '100.00']);

    expect(fn () => $transaction->update(['amount' => '1.00']))->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $transaction->delete())->toThrow(LogicException::class, 'append-only');

    expect($transaction->fresh()->amount)->toBe('100.00');
});
