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
