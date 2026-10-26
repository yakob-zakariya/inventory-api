<?php

use App\Models\Account;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\PurchaseRecorder;
use App\Services\SaleRecorder;
use Illuminate\Support\Facades\DB;

/**
 * A purchase of 5 units and a paid credit sale of 2, so both ledgers reference both documents.
 */
function purchaseAndSale(): array
{
    $product = Product::factory()->create(['selling_price' => '50.00']);
    $account = Account::factory()->create(['opening_balance' => '1000.00']);

    $purchase = app(PurchaseRecorder::class)->record([
        'supplier_id' => Supplier::factory()->create()->id,
        'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => '30.00']],
        'payments' => [['account_id' => $account->id, 'amount' => '150.00']],
    ]);
    $sale = app(SaleRecorder::class)->record([
        'customer_id' => Customer::factory()->create()->id,
        'items' => [['product_id' => $product->id, 'quantity' => 2]],
        'payments' => [['account_id' => $account->id, 'amount' => '100.00']],
    ]);

    return [$product, $account, $purchase, $sale];
}

it('shows the document number on stock movements', function () {
    actingAsOwner();
    [$product, , $purchase, $sale] = purchaseAndSale();
    StockMovement::factory()->for($product)->create(['quantity' => 1, 'note' => 'Found']);

    $this->getJson("/api/v1/stock-movements?product_id={$product->id}")
        ->assertJsonPath('data.0.reference_number', null)
        ->assertJsonPath('data.1.reference_type', 'sale')
        ->assertJsonPath('data.1.reference_number', $sale->number)
        ->assertJsonPath('data.2.reference_number', $purchase->number);
});

it('shows the document number on account transactions', function () {
    actingAsOwner();
    [, $account, $purchase, $sale] = purchaseAndSale();

    $this->getJson("/api/v1/account-transactions?account_id={$account->id}")
        ->assertJsonPath('data.0.reference_number', $sale->number)
        ->assertJsonPath('data.1.reference_number', $purchase->number);

    $this->postJson('/api/v1/expenses', ['account_id' => $account->id, 'amount' => 5, 'note' => 'Tea'])
        ->assertJsonPath('data.reference_number', null);
});

it('loads the ledger lists in a fixed number of queries', function (string $path) {
    actingAsOwner();
    purchaseAndSale();
    purchaseAndSale();

    $this->getJson("/api/v1/{$path}");
    DB::enableQueryLog();
    $this->getJson("/api/v1/{$path}")->assertOk();
    $queries = count(DB::getQueryLog());

    purchaseAndSale();
    purchaseAndSale();
    DB::flushQueryLog();
    $this->getJson("/api/v1/{$path}")->assertOk();

    expect(count(DB::getQueryLog()))->toBe($queries);
})->with(['stock-movements', 'account-transactions']);

it('names who created and who voided a record', function () {
    $owner = actingAsOwner();
    $owner->update(['name' => 'Almaz']);
    [$product, $account, $purchase, $sale] = purchaseAndSale();
    $sale->payments()->each(fn ($payment) => $this->postJson("/api/v1/account-transactions/{$payment->id}/reverse", ['reason' => 'x']));
    $this->postJson("/api/v1/sales/{$sale->id}/void", ['reason' => 'Cancelled'])
        ->assertJsonPath('data.voided_by_user', ['id' => $owner->id, 'name' => 'Almaz']);

    $almaz = ['id' => $owner->id, 'name' => 'Almaz'];

    $this->getJson('/api/v1/sales')
        ->assertJsonPath('data.0.created_by_user', $almaz)
        ->assertJsonPath('data.0.voided_by_user', $almaz);
    $this->getJson('/api/v1/purchases')
        ->assertJsonPath('data.0.created_by_user', $almaz)
        ->assertJsonPath('data.0.voided_by_user', null);
    $this->getJson("/api/v1/stock-movements?product_id={$product->id}")->assertJsonPath('data.0.created_by_user', $almaz);
    $this->getJson("/api/v1/account-transactions?account_id={$account->id}")->assertJsonPath('data.0.created_by_user', $almaz);
});

it('lets staff see who created a record', function () {
    actingAsOwner();
    [$product] = purchaseAndSale();

    actingAsStaff();

    $this->getJson("/api/v1/stock-movements?product_id={$product->id}")
        ->assertJsonPath('data.0.created_by_user.name', fn ($name) => is_string($name));
});

it('loads the document lists in a fixed number of queries', function (string $path) {
    actingAsOwner();
    purchaseAndSale();

    $this->getJson("/api/v1/{$path}");
    DB::enableQueryLog();
    $this->getJson("/api/v1/{$path}")->assertOk();
    $queries = count(DB::getQueryLog());

    purchaseAndSale();
    purchaseAndSale();
    DB::flushQueryLog();
    $this->getJson("/api/v1/{$path}")->assertOk();

    expect(count(DB::getQueryLog()))->toBe($queries);
})->with(['sales', 'purchases']);

it('shows on a document which payments were reversed and by whom they were recorded', function () {
    $owner = actingAsOwner();
    [, , , $sale] = purchaseAndSale();
    $payment = $sale->payments()->sole();
    $reversalId = $this->postJson("/api/v1/account-transactions/{$payment->id}/reverse", ['reason' => 'Wrong'])->json('data.0.id');

    $this->getJson("/api/v1/sales/{$sale->id}")
        ->assertJsonPath('data.payments.0.id', $payment->id)
        ->assertJsonPath('data.payments.0.reverses_transaction_id', null)
        ->assertJsonPath('data.payments.0.reversed_by_transaction_id', $reversalId)
        ->assertJsonPath('data.payments.0.created_by_user', ['id' => $owner->id, 'name' => $owner->name])
        ->assertJsonPath('data.payments.1.id', $reversalId)
        ->assertJsonPath('data.payments.1.reverses_transaction_id', $payment->id)
        ->assertJsonPath('data.payments.1.reversed_by_transaction_id', null);
});

it('loads a document with many payments in a fixed number of queries', function () {
    actingAsOwner();
    [, $account, , $sale] = purchaseAndSale();
    $this->postJson("/api/v1/account-transactions/{$sale->payments()->sole()->id}/reverse", ['reason' => 'Wrong']);

    $this->getJson("/api/v1/sales/{$sale->id}");
    DB::enableQueryLog();
    $this->getJson("/api/v1/sales/{$sale->id}")->assertOk();
    $queries = count(DB::getQueryLog());

    $this->postJson('/api/v1/payments', ['document_type' => 'sale', 'document_id' => $sale->id, 'account_id' => $account->id, 'amount' => '0.50']);
    $this->postJson('/api/v1/payments', ['document_type' => 'sale', 'document_id' => $sale->id, 'account_id' => $account->id, 'amount' => '0.50']);
    DB::flushQueryLog();
    $this->getJson("/api/v1/sales/{$sale->id}")->assertOk()->assertJsonCount(4, 'data.payments');

    expect(count(DB::getQueryLog()))->toBe($queries);
});
