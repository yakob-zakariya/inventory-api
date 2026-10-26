<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;

it('previews a sale without saving anything', function () {
    actingAsOwner();
    $cement = Product::factory()->create(['sku' => 'CEM-50', 'selling_price' => '720.00', 'cost_price' => '650.00']);
    $nails = Product::factory()->create(['selling_price' => '150.00']);
    StockMovement::factory()->for($cement)->create(['quantity' => 10]);
    StockMovement::factory()->for($nails)->create(['quantity' => 10]);
    $movements = StockMovement::count();

    $this->postJson('/api/v1/sales/preview', [
        'customer_id' => Customer::factory()->create()->id,
        'items' => [
            ['product_id' => $cement->id, 'quantity' => 2],
            ['product_id' => $nails->id, 'quantity' => 3, 'unit_price' => '140.00'],
        ],
        'payments' => [['account_id' => Account::factory()->create()->id, 'amount' => '1000.00']],
    ])
        ->assertOk()
        ->assertExactJson(['data' => [
            'items' => [
                ['product_id' => $cement->id, 'product' => ['id' => $cement->id, 'sku' => 'CEM-50', 'name' => $cement->name],
                    'quantity' => 2, 'unit_price' => '720.00', 'line_total' => '1440.00'],
                ['product_id' => $nails->id, 'product' => ['id' => $nails->id, 'sku' => $nails->sku, 'name' => $nails->name],
                    'quantity' => 3, 'unit_price' => '140.00', 'line_total' => '420.00'],
            ],
            'total_amount' => '1860.00',
            'paid_amount' => '1000.00',
            'balance_due' => '860.00',
            'payment_status' => 'partial',
        ]]);

    expect(Sale::count())->toBe(0)
        ->and(StockMovement::count())->toBe($movements)
        ->and(AccountTransaction::count())->toBe(0);
});

it('previews a purchase', function () {
    actingAsOwner();
    $product = Product::factory()->create();

    $this->postJson('/api/v1/purchases/preview', [
        'supplier_id' => Supplier::factory()->create()->id,
        'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_cost' => '0.10']],
    ])
        ->assertOk()
        ->assertJsonPath('data.items.0.unit_cost', '0.10')
        ->assertJsonPath('data.items.0.line_total', '0.30')
        ->assertJsonPath('data.total_amount', '0.30')
        ->assertJsonPath('data.payment_status', 'unpaid');

    expect(Purchase::count())->toBe(0)
        ->and($product->fresh()->cost_price)->toBe($product->cost_price);
});

it('reports the problems saving would report', function (Closure $body, string $message) {
    actingAsOwner();
    $product = Product::factory()->create(['sku' => 'NAIL-3', 'selling_price' => '10.00']);
    StockMovement::factory()->for($product)->create(['quantity' => 4]);

    $this->postJson('/api/v1/sales/preview', $body($product))
        ->assertUnprocessable()
        ->assertJsonPath('message', $message);
})->with([
    'not enough stock' => [
        fn (Product $product) => ['customer_id' => Customer::factory()->create()->id, 'items' => [['product_id' => $product->id, 'quantity' => 5]]],
        'Not enough stock for NAIL-3: 4 on hand, 5 requested.',
    ],
    'walk-in not paid in full' => [
        fn (Product $product) => ['items' => [['product_id' => $product->id, 'quantity' => 1]]],
        'A customer is required for a sale that is not fully paid.',
    ],
    'overpaid' => [
        fn (Product $product) => ['items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payments' => [['account_id' => Account::factory()->create()->id, 'amount' => '11.00']]],
        'Payments (11.00) exceed the document total (10.00).',
    ],
]);

it('validates like saving', function () {
    actingAsOwner();
    $product = Product::factory()->create();

    $this->postJson('/api/v1/sales/preview', [
        'items' => [['product_id' => $product->id, 'quantity' => 0], ['product_id' => $product->id, 'quantity' => 1]],
    ])->assertJsonValidationErrors(['items.0.quantity', 'items.1.product_id']);
});

it('needs the same permissions as saving', function () {
    actingAsStaff();
    $product = Product::factory()->create(['selling_price' => '10.00']);
    StockMovement::factory()->for($product)->create(['quantity' => 4]);

    $this->postJson('/api/v1/sales/preview', ['customer_id' => Customer::factory()->create()->id, 'items' => [['product_id' => $product->id, 'quantity' => 1]]])
        ->assertOk();
    $this->postJson('/api/v1/purchases/preview', ['supplier_id' => Supplier::factory()->create()->id, 'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 1]]])
        ->assertForbidden();
});
