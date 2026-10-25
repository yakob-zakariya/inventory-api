<?php

use App\Models\Account;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Supplier;
use App\Services\PurchaseRecorder;
use App\Services\SaleRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Money 1,500 (cash) + inventory at cost + receivables - payables, with known numbers:
 *
 * - Bought 10 x A at 10.00 from a supplier, paid 40.00 from the bank  -> payable 60.00
 * - Sold 2 x A at 15.00 to a customer on credit, paid 5.00 in cash    -> receivable 25.00
 * - Sold 1 x A at 15.00 to a walk-in, paid in full in cash            -> receivable 0
 */
function summaryScenario(string $label = ''): array
{
    $cash = Account::factory()->create(['name' => "Cash{$label}", 'opening_balance' => '1000.00']);
    $bank = Account::factory()->create(['name' => "Bank{$label}", 'opening_balance' => '500.00']);
    $product = Product::factory()->create(['cost_price' => '8.00', 'selling_price' => '15.00']);

    app(PurchaseRecorder::class)->record([
        'supplier_id' => Supplier::factory()->create()->id,
        'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => '10.00']],
        'payments' => [['account_id' => $bank->id, 'amount' => '40.00']],
    ]);
    $credit = app(SaleRecorder::class)->record([
        'customer_id' => Customer::factory()->create()->id,
        'items' => [['product_id' => $product->id, 'quantity' => 2]],
        'payments' => [['account_id' => $cash->id, 'amount' => '5.00']],
    ]);
    app(SaleRecorder::class)->record([
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'payments' => [['account_id' => $cash->id, 'amount' => '15.00']],
    ]);

    return compact('cash', 'bank', 'product', 'credit');
}

it('adds money, inventory and receivables and subtracts payables', function () {
    actingAsOwner();
    summaryScenario();

    // Cash 1000 + 5 + 15 = 1020; bank 500 - 40 = 460. Stock 7 x cost 10 (updated by the purchase).
    $this->getJson('/api/v1/reports/summary')
        ->assertOk()
        ->assertJsonPath('data.accounts.total', '1480.00')
        ->assertJsonPath('data.accounts.items.0.name', 'Bank')
        ->assertJsonPath('data.accounts.items.0.balance', '460.00')
        ->assertJsonPath('data.accounts.items.1.balance', '1020.00')
        ->assertJsonPath('data.inventory.at_cost', '70.00')
        ->assertJsonPath('data.inventory.at_selling_price', '105.00')
        ->assertJsonPath('data.receivables', '25.00')
        ->assertJsonPath('data.payables', '60.00')
        ->assertJsonPath('data.net_worth', '1515.00');
});

it('leaves voided documents out of receivables', function () {
    actingAsOwner();
    ['credit' => $credit] = summaryScenario();
    // Voiding arrives in phase 6; mark the sale voided directly for now.
    Sale::whereKey($credit->id)->update(['status' => 'voided']);

    $this->getJson('/api/v1/reports/summary')->assertJsonPath('data.receivables', '0.00');
});

it('flags a negative account and still counts it', function () {
    actingAsOwner();
    Account::factory()->create(['opening_balance' => '-30.00']);
    Account::factory()->create(['opening_balance' => '100.00']);

    $this->getJson('/api/v1/reports/summary')
        ->assertJsonPath('data.accounts.total', '70.00')
        ->assertJsonPath('data.net_worth', '70.00');
});

it('is zero for an empty shop', function () {
    actingAsOwner();

    $this->getJson('/api/v1/reports/summary')
        ->assertOk()
        ->assertJsonPath('data.accounts.items', [])
        ->assertJsonPath('data.inventory.at_cost', '0.00')
        ->assertJsonPath('data.net_worth', '0.00');
});

it('uses a fixed number of queries however many documents exist', function () {
    actingAsOwner();
    summaryScenario('A');
    summaryScenario('B');
    // The first request also loads the permission cache; measure after it.
    $this->getJson('/api/v1/reports/summary')->assertOk();

    DB::enableQueryLog();
    $this->getJson('/api/v1/reports/summary')->assertOk();
    $withTwo = count(DB::getQueryLog());

    summaryScenario('C');
    DB::flushQueryLog();
    $this->getJson('/api/v1/reports/summary')->assertOk();

    expect(count(DB::getQueryLog()))->toBe($withTwo);
});

it('is only for users who can view reports', function () {
    $this->getJson('/api/v1/reports/summary')->assertUnauthorized();

    actingAsStaff();
    $this->getJson('/api/v1/reports/summary')->assertForbidden();
});
