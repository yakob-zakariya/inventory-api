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
            'problems' => [],
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

it('prices a walk-in sale before any payment and says what is missing', function () {
    actingAsOwner();
    $product = Product::factory()->create(['selling_price' => '720.00']);
    StockMovement::factory()->for($product)->create(['quantity' => 10]);

    $this->postJson('/api/v1/sales/preview', ['items' => [['product_id' => $product->id, 'quantity' => 2]]])
        ->assertOk()
        ->assertJsonPath('data.total_amount', '1440.00')
        ->assertJsonPath('data.balance_due', '1440.00')
        ->assertJsonPath('data.problems', [[
            'rule' => 'walk_in_not_paid',
            'message' => 'A customer is required for a sale that is not fully paid.',
            'field' => null,
        ]]);
});

it('prices a sale with too little stock and points at the quantity', function () {
    actingAsOwner();
    $enough = Product::factory()->create(['selling_price' => '1.00']);
    $short = Product::factory()->create(['sku' => 'NAIL-3', 'selling_price' => '10.00']);
    StockMovement::factory()->for($enough)->create(['quantity' => 10]);
    StockMovement::factory()->for($short)->create(['quantity' => 4]);

    $this->postJson('/api/v1/sales/preview', [
        'customer_id' => Customer::factory()->create()->id,
        'items' => [['product_id' => $enough->id, 'quantity' => 1], ['product_id' => $short->id, 'quantity' => 99999]],
    ])
        ->assertOk()
        ->assertJsonPath('data.items.1.line_total', '999990.00')
        ->assertJsonPath('data.total_amount', '999991.00')
        ->assertJsonPath('data.problems', [[
            'rule' => 'insufficient_stock',
            'message' => 'Not enough stock for NAIL-3: 4 on hand, 99999 requested.',
            'field' => 'items.1.quantity',
        ]]);
});

it('lists every problem at once', function () {
    actingAsOwner();
    $product = Product::factory()->create(['selling_price' => '10.00']);

    $this->postJson('/api/v1/sales/preview', [
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'payments' => [['account_id' => Account::factory()->create()->id, 'amount' => '11.00']],
    ])
        ->assertOk()
        ->assertJsonPath('data.problems.*.rule', ['overpaid', 'insufficient_stock'])
        ->assertJsonPath('data.problems.0', [
            'rule' => 'overpaid',
            'message' => 'Payments (11.00) exceed the document total (10.00).',
            'field' => 'payments',
        ]);
});

it('reports an overpaid purchase', function () {
    actingAsOwner();

    $this->postJson('/api/v1/purchases/preview', [
        'supplier_id' => Supplier::factory()->create()->id,
        'items' => [['product_id' => Product::factory()->create()->id, 'quantity' => 1, 'unit_cost' => '5.00']],
        'payments' => [['account_id' => Account::factory()->create(['opening_balance' => '100.00'])->id, 'amount' => '6.00']],
    ])
        ->assertOk()
        ->assertJsonPath('data.total_amount', '5.00')
        ->assertJsonPath('data.problems.0.rule', 'overpaid');
});

it('still refuses to save what the preview flags', function (Closure $body, string $message) {
    actingAsOwner();
    $product = Product::factory()->create(['sku' => 'NAIL-3', 'selling_price' => '10.00']);
    StockMovement::factory()->for($product)->create(['quantity' => 4]);
    $body = $body($product);

    $this->postJson('/api/v1/sales/preview', $body)->assertOk()->assertJsonPath('data.problems.0.message', $message);
    $this->postJson('/api/v1/sales', $body)->assertUnprocessable()->assertExactJson(['message' => $message]);

    expect(Sale::count())->toBe(0);
})->with([
    'walk-in not paid in full' => [
        fn (Product $product) => ['items' => [['product_id' => $product->id, 'quantity' => 1]]],
        'A customer is required for a sale that is not fully paid.',
    ],
    'not enough stock' => [
        fn (Product $product) => ['customer_id' => Customer::factory()->create()->id, 'items' => [['product_id' => $product->id, 'quantity' => 5]]],
        'Not enough stock for NAIL-3: 4 on hand, 5 requested.',
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
