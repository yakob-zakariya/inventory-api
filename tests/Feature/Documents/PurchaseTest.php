<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Supplier;

function purchasePayload(array $overrides = []): array
{
    return [
        'supplier_id' => Supplier::factory()->create()->id,
        'invoice_number' => 'INV-77',
        'purchase_date' => '2026-10-20',
        'items' => [
            ['product_id' => Product::factory()->create(['cost_price' => '600.00'])->id, 'quantity' => 10, 'unit_cost' => '650.00'],
            ['product_id' => Product::factory()->create()->id, 'quantity' => 3, 'unit_cost' => '0.10'],
        ],
        ...$overrides,
    ];
}

it('records a purchase with items, stock and a computed total', function () {
    $owner = actingAsOwner();
    $payload = purchasePayload();

    $response = $this->postJson('/api/v1/purchases', $payload)
        ->assertCreated()
        ->assertJsonPath('data.number', 'PUR-000001')
        ->assertJsonPath('data.invoice_number', 'INV-77')
        ->assertJsonPath('data.purchase_date', '2026-10-20')
        ->assertJsonPath('data.total_amount', '6500.30')
        ->assertJsonPath('data.paid_amount', '0.00')
        ->assertJsonPath('data.balance_due', '6500.30')
        ->assertJsonPath('data.payment_status', 'unpaid')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.items.0.quantity', 10)
        ->assertJsonPath('data.items.0.unit_cost', '650.00')
        ->assertJsonPath('data.items.0.line_total', '6500.00')
        ->assertJsonPath('data.items.1.line_total', '0.30')
        ->assertJsonPath('data.created_by', $owner->id);

    $purchase = Purchase::findOrFail($response->json('data.id'));
    $first = Product::findOrFail($payload['items'][0]['product_id']);

    expect($first->currentStock())->toBe(10)
        ->and(Product::findOrFail($payload['items'][1]['product_id'])->currentStock())->toBe(3)
        ->and($purchase->stockMovements()->count())->toBe(2)
        ->and(StockMovement::first()->reference_type)->toBe('purchase')
        ->and($first->cost_price)->toBe('650.00')
        ->and($purchase->items()->where('product_id', $first->id)->value('previous_cost_price'))->toBe('600.00');
});

it('numbers purchases sequentially', function () {
    actingAsOwner();

    $this->postJson('/api/v1/purchases', purchasePayload())->assertJsonPath('data.number', 'PUR-000001');
    $this->postJson('/api/v1/purchases', purchasePayload())->assertJsonPath('data.number', 'PUR-000002');
});

it('defaults the purchase date to today', function () {
    actingAsOwner();
    $payload = purchasePayload();
    unset($payload['purchase_date']);

    $this->postJson('/api/v1/purchases', $payload)
        ->assertCreated()
        ->assertJsonPath('data.purchase_date', now()->toDateString());
});

it('records partial payments as money out of the accounts', function () {
    actingAsOwner();
    $cash = Account::factory()->create(['name' => 'Cash']);
    $bank = Account::factory()->create(['name' => 'Bank']);

    $response = $this->postJson('/api/v1/purchases', purchasePayload([
        'payments' => [
            ['account_id' => $cash->id, 'amount' => '500.30'],
            ['account_id' => $bank->id, 'amount' => 1000, 'receipt_reference' => 'TRX-1'],
        ],
    ]))
        ->assertCreated()
        ->assertJsonPath('data.paid_amount', '1500.30')
        ->assertJsonPath('data.balance_due', '5000.00')
        ->assertJsonPath('data.payment_status', 'partial')
        ->assertJsonPath('data.payments.0.amount', '500.30')
        ->assertJsonPath('data.payments.0.account.name', 'Cash')
        ->assertJsonPath('data.payments.1.receipt_reference', 'TRX-1');

    expect(AccountTransaction::where('account_id', $cash->id)->sole())
        ->type->value->toBe('purchase_payment')
        ->amount->toBe('-500.30')
        ->reference_type->toBe('purchase')
        ->reference_id->toBe($response->json('data.id'));
});

it('marks a fully paid purchase as paid', function () {
    actingAsOwner();
    $cash = Account::factory()->create();

    $this->postJson('/api/v1/purchases', purchasePayload(['payments' => [['account_id' => $cash->id, 'amount' => '6500.30']]]))
        ->assertCreated()
        ->assertJsonPath('data.payment_status', 'paid')
        ->assertJsonPath('data.balance_due', '0.00');
});

it('refuses payments above the total and records nothing', function () {
    actingAsOwner();
    $cash = Account::factory()->create();

    $this->postJson('/api/v1/purchases', purchasePayload(['payments' => [['account_id' => $cash->id, 'amount' => '6500.31']]]))
        ->assertUnprocessable()
        ->assertExactJson(['message' => 'Payments (6500.31) exceed the document total (6500.30).']);

    expect(Purchase::count())->toBe(0)
        ->and(StockMovement::count())->toBe(0)
        ->and(AccountTransaction::count())->toBe(0);
});

it('does not use up a document number when the purchase fails', function () {
    actingAsOwner();
    $cash = Account::factory()->create();

    $this->postJson('/api/v1/purchases', purchasePayload(['payments' => [['account_id' => $cash->id, 'amount' => 99999]]]))
        ->assertUnprocessable();

    $this->postJson('/api/v1/purchases', purchasePayload())->assertJsonPath('data.number', 'PUR-000001');
});

it('validates a purchase', function (Closure $overrides, string $field) {
    actingAsOwner();

    $this->postJson('/api/v1/purchases', purchasePayload($overrides()))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'missing supplier' => [fn () => ['supplier_id' => null], 'supplier_id'],
    'deleted supplier' => [fn () => ['supplier_id' => tap(Supplier::factory()->create())->delete()->id], 'supplier_id'],
    'future date' => [fn () => ['purchase_date' => now()->addDay()->toDateString()], 'purchase_date'],
    'bad date' => [fn () => ['purchase_date' => '20-10-2026'], 'purchase_date'],
    'no items' => [fn () => ['items' => []], 'items'],
    'duplicate product' => [function () {
        $id = Product::factory()->create()->id;

        return ['items' => [
            ['product_id' => $id, 'quantity' => 1, 'unit_cost' => 1],
            ['product_id' => $id, 'quantity' => 2, 'unit_cost' => 1],
        ]];
    }, 'items.0.product_id'],
    'deleted product' => [fn () => ['items' => [['product_id' => tap(Product::factory()->create())->delete()->id, 'quantity' => 1, 'unit_cost' => 1]]], 'items.0.product_id'],
    'zero quantity' => [fn () => ['items' => [['product_id' => Product::factory()->create()->id, 'quantity' => 0, 'unit_cost' => 1]]], 'items.0.quantity'],
    'fractional quantity' => [fn () => ['items' => [['product_id' => Product::factory()->create()->id, 'quantity' => 1.5, 'unit_cost' => 1]]], 'items.0.quantity'],
    'missing cost' => [fn () => ['items' => [['product_id' => Product::factory()->create()->id, 'quantity' => 1]]], 'items.0.unit_cost'],
    'three-decimal cost' => [fn () => ['items' => [['product_id' => Product::factory()->create()->id, 'quantity' => 1, 'unit_cost' => '1.005']]], 'items.0.unit_cost'],
    'inactive account' => [fn () => ['payments' => [['account_id' => Account::factory()->inactive()->create()->id, 'amount' => 1]]], 'payments.0.account_id'],
    'zero payment' => [fn () => ['payments' => [['account_id' => Account::factory()->create()->id, 'amount' => 0]]], 'payments.0.amount'],
]);

it('lists purchases with filters and payment totals', function () {
    actingAsOwner();
    $supplier = Supplier::factory()->create();
    $cash = Account::factory()->create();
    $this->postJson('/api/v1/purchases', purchasePayload(['supplier_id' => $supplier->id, 'purchase_date' => '2026-10-01']));
    $this->postJson('/api/v1/purchases', purchasePayload([
        'purchase_date' => '2026-10-15',
        'payments' => [['account_id' => $cash->id, 'amount' => '100.00']],
    ]));

    $this->getJson('/api/v1/purchases')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.number', 'PUR-000002')
        ->assertJsonPath('data.0.paid_amount', '100.00')
        ->assertJsonPath('data.1.paid_amount', '0.00')
        ->assertJsonMissingPath('data.0.items');

    $this->getJson("/api/v1/purchases?supplier_id={$supplier->id}")->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/purchases?from=2026-10-10&to=2026-10-31')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/purchases?status=voided')->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/purchases?from=2026-10-10&to=2026-10-01')->assertUnprocessable();
});

it('shows a purchase with items and payments', function () {
    actingAsOwner();
    $id = $this->postJson('/api/v1/purchases', purchasePayload())->json('data.id');

    $this->getJson("/api/v1/purchases/{$id}")
        ->assertOk()
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.supplier.id', Purchase::find($id)->supplier_id);
});

it('keeps purchases away from staff', function () {
    actingAsStaff();

    $this->getJson('/api/v1/purchases')->assertForbidden();
    $this->postJson('/api/v1/purchases', purchasePayload())->assertForbidden();
});

it('requires authentication', function () {
    $this->getJson('/api/v1/purchases')->assertUnauthorized();
});
