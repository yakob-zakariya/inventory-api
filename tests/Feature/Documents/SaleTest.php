<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * A product with stock on hand.
 */
function stockedProduct(int $stock = 20, array $attributes = []): Product
{
    $product = Product::factory()->create(['cost_price' => '650.00', 'selling_price' => '720.00', ...$attributes]);
    StockMovement::factory()->for($product)->create(['quantity' => $stock]);

    return $product;
}

it('records a paid walk-in sale at the selling price', function () {
    $owner = actingAsOwner();
    $cash = Account::factory()->create();
    $product = stockedProduct();

    $response = $this->postJson('/api/v1/sales', [
        'items' => [['product_id' => $product->id, 'quantity' => 2]],
        'payments' => [['account_id' => $cash->id, 'amount' => '1440.00']],
    ])
        ->assertCreated()
        ->assertJsonPath('data.number', 'SAL-000001')
        ->assertJsonPath('data.customer', null)
        ->assertJsonPath('data.sale_date', now()->toDateString())
        ->assertJsonPath('data.total_amount', '1440.00')
        ->assertJsonPath('data.payment_status', 'paid')
        ->assertJsonPath('data.items.0.unit_price', '720.00')
        ->assertJsonPath('data.items.0.unit_cost', '650.00')
        ->assertJsonPath('data.items.0.line_total', '1440.00')
        ->assertJsonPath('data.payments.0.amount', '1440.00')
        ->assertJsonPath('data.created_by', $owner->id);

    $sale = Sale::findOrFail($response->json('data.id'));

    expect($product->currentStock())->toBe(18)
        ->and($sale->stockMovements()->sole()->quantity)->toBe(-2)
        ->and(AccountTransaction::sole())
        ->type->value->toBe('sale_payment')
        ->amount->toBe('1440.00')
        ->reference_type->toBe('sale');
});

it('uses an overridden price and keeps the cost copy', function () {
    actingAsOwner();
    $product = stockedProduct();
    $customer = Customer::factory()->create();

    $this->postJson('/api/v1/sales', [
        'customer_id' => $customer->id,
        'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => '700.10']],
    ])
        ->assertCreated()
        ->assertJsonPath('data.items.0.unit_price', '700.10')
        ->assertJsonPath('data.total_amount', '2100.30');

    $product->update(['selling_price' => '999.00', 'cost_price' => '800.00']);

    expect(Sale::sole()->items()->sole())
        ->unit_price->toBe('700.10')
        ->unit_cost->toBe('650.00');
});

it('lets a registered customer buy on credit', function () {
    actingAsOwner();
    $cash = Account::factory()->create();
    $product = stockedProduct();
    $customer = Customer::factory()->create(['name' => 'Almaz']);

    $this->postJson('/api/v1/sales', [
        'customer_id' => $customer->id,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'payments' => [['account_id' => $cash->id, 'amount' => '200.00']],
    ])
        ->assertCreated()
        ->assertJsonPath('data.customer.name', 'Almaz')
        ->assertJsonPath('data.paid_amount', '200.00')
        ->assertJsonPath('data.balance_due', '520.00')
        ->assertJsonPath('data.payment_status', 'partial');
});

it('requires a customer for a sale that is not fully paid', function (array $payments) {
    actingAsOwner();
    $cash = Account::factory()->create();
    $product = stockedProduct();

    $this->postJson('/api/v1/sales', [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'payments' => array_map(fn ($amount) => ['account_id' => $cash->id, 'amount' => $amount], $payments),
    ])
        ->assertUnprocessable()
        ->assertExactJson(['message' => 'A customer is required for a sale that is not fully paid.']);

    expect(Sale::count())->toBe(0)
        ->and($product->currentStock())->toBe(20);
})->with([
    'no payment' => [[]],
    'part payment' => [['719.99']],
]);

it('refuses to sell more than is in stock and records nothing', function () {
    actingAsOwner();
    $cash = Account::factory()->create();
    $plenty = stockedProduct(50);
    $scarce = stockedProduct(2, ['sku' => 'CEM-50']);

    $this->postJson('/api/v1/sales', [
        'items' => [
            ['product_id' => $plenty->id, 'quantity' => 5],
            ['product_id' => $scarce->id, 'quantity' => 3],
        ],
        'payments' => [['account_id' => $cash->id, 'amount' => '5760.00']],
    ])
        ->assertUnprocessable()
        ->assertExactJson(['message' => 'Not enough stock for CEM-50: 2 on hand, 3 requested.']);

    expect(Sale::count())->toBe(0)
        ->and($plenty->currentStock())->toBe(50)
        ->and(AccountTransaction::count())->toBe(0);

    $this->postJson('/api/v1/sales', [
        'items' => [['product_id' => $scarce->id, 'quantity' => 2]],
        'payments' => [['account_id' => $cash->id, 'amount' => '1440.00']],
    ])->assertJsonPath('data.number', 'SAL-000001');
});

it('refuses payments above the total', function () {
    actingAsOwner();
    $cash = Account::factory()->create();
    $product = stockedProduct();

    $this->postJson('/api/v1/sales', [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'payments' => [['account_id' => $cash->id, 'amount' => '720.01']],
    ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Payments (720.01) exceed the document total (720.00).');
});

it('validates a sale', function (Closure $overrides, string $field) {
    actingAsOwner();
    $product = stockedProduct();

    $this->postJson('/api/v1/sales', [
        'customer_id' => Customer::factory()->create()->id,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ...$overrides($product),
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'deleted customer' => [fn () => ['customer_id' => tap(Customer::factory()->create())->delete()->id], 'customer_id'],
    'future date' => [fn () => ['sale_date' => now()->addDay()->toDateString()], 'sale_date'],
    'duplicate product' => [fn (Product $product) => ['items' => [
        ['product_id' => $product->id, 'quantity' => 1],
        ['product_id' => $product->id, 'quantity' => 1],
    ]], 'items.1.product_id'],
    'negative price' => [fn (Product $product) => ['items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => -1]]], 'items.0.unit_price'],
    'zero quantity' => [fn (Product $product) => ['items' => [['product_id' => $product->id, 'quantity' => 0]]], 'items.0.quantity'],
    'no items' => [fn () => ['items' => []], 'items'],
]);

it('lets staff sell and view sales without seeing costs', function () {
    actingAsStaff();
    $cash = Account::factory()->create();
    $product = stockedProduct();

    $id = $this->postJson('/api/v1/sales', [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'payments' => [['account_id' => $cash->id, 'amount' => '720.00']],
    ])
        ->assertCreated()
        ->assertJsonMissingPath('data.items.0.unit_cost')
        ->json('data.id');

    $this->getJson("/api/v1/sales/{$id}")
        ->assertOk()
        ->assertJsonPath('data.items.0.unit_price', '720.00')
        ->assertJsonMissingPath('data.items.0.unit_cost');

    $this->getJson('/api/v1/sales')->assertOk()->assertJsonCount(1, 'data');
});

it('lists sales with filters and payment totals', function () {
    actingAsOwner();
    $cash = Account::factory()->create();
    $customer = Customer::factory()->create();
    $product = stockedProduct();
    $this->postJson('/api/v1/sales', [
        'customer_id' => $customer->id, 'sale_date' => '2026-10-01',
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
    ]);
    $this->postJson('/api/v1/sales', [
        'sale_date' => '2026-10-15',
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'payments' => [['account_id' => $cash->id, 'amount' => '720.00']],
    ]);

    $this->getJson('/api/v1/sales')
        ->assertOk()
        ->assertJsonPath('data.0.number', 'SAL-000002')
        ->assertJsonPath('data.0.payment_status', 'paid')
        ->assertJsonPath('data.1.payment_status', 'unpaid')
        ->assertJsonMissingPath('data.0.items');

    $this->getJson("/api/v1/sales?customer_id={$customer->id}")->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/sales?from=2026-10-10')->assertJsonCount(1, 'data');
});

it('lists sales without one payment query per sale', function () {
    actingAsOwner();
    $cash = Account::factory()->create();
    $product = stockedProduct(100);

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['account_id' => $cash->id, 'amount' => '720.00']],
        ]);
    }

    DB::enableQueryLog();
    $this->getJson('/api/v1/sales')->assertOk();
    $paymentQueries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'account_transactions'));

    expect($paymentQueries)->toHaveCount(1);
});

it('forbids selling without sales.create and requires authentication', function () {
    $this->getJson('/api/v1/sales')->assertUnauthorized();

    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/sales')->assertOk();
    $this->postJson('/api/v1/sales', [])->assertForbidden();
});

it('shows the cost of sale items with costs.view, not with reports.view alone', function () {
    actingAsOwner();
    $cash = Account::factory()->create();
    $id = $this->postJson('/api/v1/sales', [
        'items' => [['product_id' => stockedProduct()->id, 'quantity' => 1]],
        'payments' => [['account_id' => $cash->id, 'amount' => '720.00']],
    ])->json('data.id');

    actingWithPermissions(['costs.view']);
    $this->getJson("/api/v1/sales/{$id}")->assertJsonPath('data.items.0.unit_cost', '650.00');

    actingWithPermissions(['reports.view']);
    $this->getJson("/api/v1/sales/{$id}")->assertJsonMissingPath('data.items.0.unit_cost');
});
