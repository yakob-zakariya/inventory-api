<?php

use App\Enums\Permission;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseRecorder;
use App\Services\SaleRecorder;
use Illuminate\Database\UniqueConstraintViolationException;
use Laravel\Sanctum\Sanctum;

/**
 * A credit sale of 1000.00 with a 400.00 payment into the given account.
 */
function partlyPaidSale(Account $account): Sale
{
    $product = Product::factory()->create(['selling_price' => '500.00']);
    StockMovement::factory()->for($product)->create(['quantity' => 10]);

    return app(SaleRecorder::class)->record([
        'customer_id' => Customer::factory()->create()->id,
        'items' => [['product_id' => $product->id, 'quantity' => 2]],
        'payments' => [['account_id' => $account->id, 'amount' => '400.00']],
    ]);
}

function reverse(AccountTransaction $transaction, array $body = ['reason' => 'Entered by mistake'])
{
    return test()->postJson("/api/v1/account-transactions/{$transaction->id}/reverse", $body);
}

it('reverses a payment and the document owes the amount again', function () {
    $owner = actingAsOwner();
    $cash = Account::factory()->create(['opening_balance' => '0.00']);
    $sale = partlyPaidSale($cash);
    $payment = $sale->payments()->sole();

    reverse($payment)
        ->assertCreated()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'reversal')
        ->assertJsonPath('data.0.amount', '-400.00')
        ->assertJsonPath('data.0.note', 'Entered by mistake')
        ->assertJsonPath('data.0.reverses_transaction_id', $payment->id)
        ->assertJsonPath('data.0.reference_type', 'sale')
        ->assertJsonPath('data.0.reference_id', $sale->id)
        ->assertJsonPath('data.0.transaction_date', now()->toDateString())
        ->assertJsonPath('data.0.created_by', $owner->id);

    $sale = $sale->fresh();

    expect($cash->balanceCents())->toBe(0)
        ->and($sale->paidCents())->toBe(0)
        ->and($sale->paymentStatus()->value)->toBe('unpaid');

    $this->getJson("/api/v1/sales/{$sale->id}")->assertJsonPath('data.balance_due', '1000.00');
});

it('reverses a purchase payment as money back into the account', function () {
    actingAsOwner();
    $bank = Account::factory()->create(['opening_balance' => '1000.00']);
    $purchase = app(PurchaseRecorder::class)->record([
        'supplier_id' => Supplier::factory()->create()->id,
        'items' => [['product_id' => Product::factory()->create()->id, 'quantity' => 3, 'unit_cost' => '100.00']],
        'payments' => [['account_id' => $bank->id, 'amount' => '300.00']],
    ]);

    reverse($purchase->payments()->sole())->assertCreated()->assertJsonPath('data.0.amount', '300.00');

    expect($bank->balanceCents())->toBe(100000)
        ->and($purchase->fresh()->paidCents())->toBe(0);
});

it('reverses expenses, owner entries and adjustments', function (string $type, string $amount) {
    actingAsOwner();
    $cash = Account::factory()->create(['opening_balance' => '500.00']);
    $entry = AccountTransaction::factory()->for($cash)->create(['type' => $type, 'amount' => $amount, 'note' => 'x']);

    reverse($entry)->assertCreated()->assertJsonPath('data.0.amount', number_format(-(float) $amount, 2, '.', ''));

    expect($cash->balanceCents())->toBe(50000);
})->with([
    'expense' => ['expense', '-120.00'],
    'owner deposit' => ['owner_deposit', '1000.00'],
    'owner withdrawal' => ['owner_withdrawal', '-200.00'],
    'adjustment' => ['adjustment', '15.50'],
]);

it('reverses both sides of a transfer from either side', function (int $side) {
    actingAsOwner();
    $cash = Account::factory()->create(['opening_balance' => '500.00']);
    $bank = Account::factory()->create(['opening_balance' => '0.00']);

    $rows = $this->postJson('/api/v1/transfers', [
        'from_account_id' => $cash->id, 'to_account_id' => $bank->id, 'amount' => '300.00',
    ])->json('data');

    $response = reverse(AccountTransaction::findOrFail($rows[$side]['id']))
        ->assertCreated()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.reverses_transaction_id', $rows[0]['id'])
        ->assertJsonPath('data.0.amount', '300.00')
        ->assertJsonPath('data.1.reverses_transaction_id', $rows[1]['id'])
        ->assertJsonPath('data.1.amount', '-300.00');

    $group = $response->json('data.0.transfer_group');

    expect($group)->not->toBeNull()->not->toBe($rows[0]['transfer_group'])
        ->and($response->json('data.1.transfer_group'))->toBe($group)
        ->and(AccountTransaction::where('transfer_group', $group)->sum('amount'))->toEqual(0)
        ->and($cash->balanceCents())->toBe(50000)
        ->and($bank->balanceCents())->toBe(0);
})->with(['outgoing side' => 0, 'incoming side' => 1]);

it('refuses to reverse a reversal', function () {
    actingAsOwner();
    $entry = AccountTransaction::factory()->create();
    $reversalId = reverse($entry)->json('data.0.id');

    reverse(AccountTransaction::findOrFail($reversalId))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'A reversal cannot be reversed. Record the correct entry again instead.');
});

it('refuses to reverse an entry twice', function () {
    actingAsOwner();
    $entry = AccountTransaction::factory()->create();

    reverse($entry)->assertCreated();

    reverse($entry)
        ->assertConflict()
        ->assertJsonPath('message', "Transaction {$entry->id} is already reversed.");

    expect($entry->reversal()->count())->toBe(1);
});

it('lets the database refuse a second reversal row too', function () {
    $entry = AccountTransaction::factory()->create();
    AccountTransaction::factory()->create(['type' => 'reversal', 'amount' => '-100.00', 'note' => 'x', 'reverses_transaction_id' => $entry->id]);

    AccountTransaction::factory()->create(['type' => 'reversal', 'amount' => '-100.00', 'note' => 'x', 'reverses_transaction_id' => $entry->id]);
})->throws(UniqueConstraintViolationException::class);

it('refuses a second reversal of a transfer from its other side', function () {
    actingAsOwner();
    $cash = Account::factory()->create(['opening_balance' => '500.00']);
    $bank = Account::factory()->create();
    $rows = $this->postJson('/api/v1/transfers', [
        'from_account_id' => $cash->id, 'to_account_id' => $bank->id, 'amount' => '10.00',
    ])->json('data');

    reverse(AccountTransaction::findOrFail($rows[0]['id']))->assertCreated();
    reverse(AccountTransaction::findOrFail($rows[1]['id']))->assertConflict();
});

it('accepts an earlier date but not one before the original or in the future', function () {
    actingAsOwner();
    $entry = AccountTransaction::factory()->create(['transaction_date' => now()->subDays(5)->toDateString()]);

    reverse($entry, ['reason' => 'x', 'transaction_date' => now()->subDays(6)->toDateString()])
        ->assertJsonValidationErrors(['transaction_date' => 'A reversal cannot be dated before the entry it reverses.']);
    reverse($entry, ['reason' => 'x', 'transaction_date' => now()->addDay()->toDateString()])
        ->assertJsonValidationErrors('transaction_date');

    reverse($entry, ['reason' => 'x', 'transaction_date' => now()->subDays(2)->toDateString()])
        ->assertCreated()
        ->assertJsonPath('data.0.transaction_date', now()->subDays(2)->toDateString());
});

it('requires a reason', function () {
    actingAsOwner();

    reverse(AccountTransaction::factory()->create(), [])
        ->assertJsonValidationErrors(['reason' => 'A reason is required to reverse an entry.']);
});

it('refuses a reversal on an inactive account', function () {
    actingAsOwner();
    $account = Account::factory()->create();
    $entry = AccountTransaction::factory()->for($account)->create();
    $account->update(['is_active' => false]);

    reverse($entry)->assertUnprocessable()->assertJsonPath('message', "The account \"{$account->name}\" is inactive.");
});

it('needs payments.void for payments and accounts.manage for other entries', function () {
    actingAsOwner();
    $cash = Account::factory()->create();
    $payment = partlyPaidSale($cash)->payments()->sole();
    $expense = AccountTransaction::factory()->for($cash)->create(['type' => 'expense', 'amount' => '-5.00', 'note' => 'x']);

    Sanctum::actingAs(User::factory()->create()->givePermissionTo(Permission::PaymentsVoid->value));
    reverse($expense)->assertForbidden();
    reverse($payment)->assertCreated();

    Sanctum::actingAs(User::factory()->create()->givePermissionTo(Permission::AccountsManage->value));
    reverse($expense)->assertCreated();

    actingAsStaff();
    reverse(AccountTransaction::factory()->create())->assertForbidden();
});

it('shows which entry reversed each ledger row', function () {
    actingAsOwner();
    $entry = AccountTransaction::factory()->create();
    $reversalId = reverse($entry)->json('data.0.id');

    $this->getJson('/api/v1/account-transactions')
        ->assertJsonPath('data.0.id', $reversalId)
        ->assertJsonPath('data.0.reversed_by_transaction_id', null)
        ->assertJsonPath('data.1.id', $entry->id)
        ->assertJsonPath('data.1.reversed_by_transaction_id', $reversalId);
});
