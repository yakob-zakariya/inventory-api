<?php

use App\Models\Account;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\DocumentVoider;
use App\Services\PurchaseRecorder;
use App\Services\SaleRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Sell one unit at the given price to the customer (null for walk-in), paying the given amount.
 */
function sellFor(?Customer $customer, string $price, string $paid = '0'): Sale
{
    $product = Product::factory()->create(['selling_price' => $price]);
    StockMovement::factory()->for($product)->create(['quantity' => 1]);

    return app(SaleRecorder::class)->record([
        'customer_id' => $customer?->id,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'payments' => $paid === '0' ? [] : [['account_id' => Account::factory()->create()->id, 'amount' => $paid]],
    ]);
}

/**
 * Buy one unit at the given cost from the supplier, paying the given amount.
 */
function buyFor(Supplier $supplier, string $cost, string $paid = '0'): Purchase
{
    return app(PurchaseRecorder::class)->record([
        'supplier_id' => $supplier->id,
        'items' => [['product_id' => Product::factory()->create()->id, 'quantity' => 1, 'unit_cost' => $cost]],
        'payments' => $paid === '0' ? [] : [['account_id' => Account::factory()->create(['opening_balance' => '10000.00'])->id, 'amount' => $paid]],
    ]);
}

it('shows what each customer owes on active sales', function () {
    actingAsOwner();
    $abebe = Customer::factory()->create(['name' => 'Abebe']);
    $almaz = Customer::factory()->create(['name' => 'Almaz']);
    sellFor($abebe, '1000.00', '400.00');
    sellFor($abebe, '250.50');
    sellFor($almaz, '300.00', '300.00');
    app(DocumentVoider::class)->void(sellFor($abebe, '999.00'), 'Cancelled');
    sellFor(null, '80.00', '80.00');

    $this->getJson('/api/v1/customers')
        ->assertJsonPath('data.0.name', 'Abebe')
        ->assertJsonPath('data.0.receivable', '850.50')
        ->assertJsonPath('data.1.name', 'Almaz')
        ->assertJsonPath('data.1.receivable', '0.00');

    $this->getJson("/api/v1/customers/{$abebe->id}")->assertJsonPath('data.receivable', '850.50');
});

it('shows what the shop owes each supplier on active purchases', function () {
    actingAsOwner();
    $supplier = Supplier::factory()->create();
    buyFor($supplier, '1200.00', '200.00');
    buyFor($supplier, '35.25');
    app(DocumentVoider::class)->void(buyFor($supplier, '500.00'), 'Entered twice');

    $this->getJson('/api/v1/suppliers')->assertJsonPath('data.0.payable', '1035.25');
    $this->getJson("/api/v1/suppliers/{$supplier->id}")->assertJsonPath('data.payable', '1035.25');
});

it('filters to customers and suppliers with something owed', function () {
    actingAsOwner();
    $owing = Customer::factory()->create();
    $paidUp = Customer::factory()->create();
    Customer::factory()->create();
    sellFor($owing, '10.00', '9.99');
    sellFor($paidUp, '10.00', '10.00');
    $supplier = Supplier::factory()->create();
    buyFor($supplier, '5.00');
    buyFor(Supplier::factory()->create(), '5.00', '5.00');

    $this->getJson('/api/v1/customers?has_balance=1')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $owing->id)
        ->assertJsonPath('data.0.receivable', '0.01');

    $this->getJson('/api/v1/suppliers?has_balance=1')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $supplier->id);
});

it('lets staff see receivables but not payables', function () {
    actingAsOwner();
    sellFor(Customer::factory()->create(), '10.00');
    buyFor(Supplier::factory()->create(), '5.00');

    actingAsStaff();

    $this->getJson('/api/v1/customers')->assertJsonPath('data.0.receivable', '10.00');
    $this->getJson('/api/v1/suppliers')->assertJsonMissingPath('data.0.payable');
});

it('loads the amounts in a fixed number of queries', function (int $customers) {
    actingAsOwner();
    foreach (range(1, $customers) as $i) {
        sellFor(Customer::factory()->create(), '10.00', '4.00');
    }

    $this->getJson('/api/v1/customers'); // warm up the permission cache
    DB::enableQueryLog();
    $this->getJson('/api/v1/customers')->assertOk();

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(4);
})->with([1, 8]);
