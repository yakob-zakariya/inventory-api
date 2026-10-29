<?php

use App\Models\Account;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Proforma;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\DocumentVoider;
use App\Services\ProformaManager;

beforeEach(function () {
    $this->travelTo('2026-10-27 10:00:00');
    actingAsOwner();
    $this->cement = Product::factory()->create(['sku' => 'CEM-50', 'selling_price' => '720.00', 'cost_price' => '650.00']);
    $this->paint = Product::factory()->create(['sku' => 'PNT-W4', 'selling_price' => '1100.00', 'cost_price' => '950.00']);
    StockMovement::factory()->for($this->cement)->create(['quantity' => 10]);
    StockMovement::factory()->for($this->paint)->create(['quantity' => 10]);
    $this->customer = Customer::factory()->create();
    $this->cash = Account::factory()->create();

    // Quoted: 4 cement at 700 (a discount) and 2 paint at the selling price = 2800 + 2200 = 5000.
    $this->proforma = app(ProformaManager::class)->create([
        'customer_id' => $this->customer->id,
        'valid_until' => '2026-11-03',
        'items' => [
            ['product_id' => $this->cement->id, 'quantity' => 4, 'unit_price' => '700.00'],
            ['product_id' => $this->paint->id, 'quantity' => 2],
        ],
    ]);
});

function convert(Proforma $proforma, array $body = [])
{
    return test()->postJson("/api/v1/proformas/{$proforma->id}/convert", $body);
}

it('turns a proforma into a sale at the quoted prices', function () {
    $this->cement->update(['selling_price' => '800.00', 'cost_price' => '660.00']);

    $response = convert($this->proforma, ['payments' => [['account_id' => $this->cash->id, 'amount' => '1000.00']]])
        ->assertCreated()
        ->assertJsonPath('data.number', 'SAL-000001')
        ->assertJsonPath('data.customer.id', $this->customer->id)
        ->assertJsonPath('data.total_amount', '5000.00')
        ->assertJsonPath('data.paid_amount', '1000.00')
        ->assertJsonPath('data.items.0.unit_price', '700.00')
        ->assertJsonPath('data.items.0.unit_cost', '660.00')
        ->assertJsonPath('data.proforma', ['id' => $this->proforma->id, 'number' => 'PRF-000001'])
        ->assertJsonPath('data.note', 'From PRF-000001');

    $sale = Sale::findOrFail($response->json('data.id'));

    expect($this->cement->currentStock())->toBe(6)
        ->and($this->paint->currentStock())->toBe(8);

    $this->getJson("/api/v1/proformas/{$this->proforma->id}")
        ->assertJsonPath('data.status', 'converted')
        ->assertJsonPath('data.sale', ['id' => $sale->id, 'number' => 'SAL-000001']);
    $this->getJson("/api/v1/sales/{$sale->id}")->assertJsonPath('data.proforma.number', 'PRF-000001');
});

it('sells less than quoted when the customer takes less', function () {
    convert($this->proforma, ['items' => [['product_id' => $this->cement->id, 'quantity' => 3]]])
        ->assertCreated()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.total_amount', '2100.00');

    expect($this->paint->currentStock())->toBe(10);
});

it('refuses to sell more or other products than quoted', function (Closure $items, string $message) {
    convert($this->proforma, ['items' => $items($this)])
        ->assertUnprocessable()
        ->assertJsonPath('message', fn (string $actual) => str_starts_with($actual, $message));

    expect(Sale::count())->toBe(0)->and($this->proforma->fresh()->status->value)->toBe('open');
})->with([
    'more than quoted' => [fn ($test) => [['product_id' => $test->cement->id, 'quantity' => 5]], 'Only 4 of CEM-50 were quoted; 5 requested.'],
    'not on the proforma' => [fn ($test) => [['product_id' => Product::factory()->create()->id, 'quantity' => 1]], 'Product '],
]);

it('refuses a different price when converting', function () {
    convert($this->proforma, ['items' => [['product_id' => $this->cement->id, 'quantity' => 1, 'unit_price' => '1.00']]])
        ->assertJsonValidationErrors(['items.0.unit_price' => 'Prices come from the proforma and cannot be changed when converting.']);
});

it('applies the sale rules: stock, and full payment for a typed-in customer', function () {
    $typed = app(ProformaManager::class)->create(['customer_name' => 'Hanna', 'items' => [['product_id' => $this->cement->id, 'quantity' => 2]]]);

    convert($typed)->assertUnprocessable()->assertJsonPath('message', 'A customer is required for a sale that is not fully paid.');

    convert($typed, ['payments' => [['account_id' => $this->cash->id, 'amount' => '1440.00']]])
        ->assertCreated()
        ->assertJsonPath('data.customer', null)
        ->assertJsonPath('data.note', "From {$typed->number} (Hanna)");

    $big = app(ProformaManager::class)->create(['customer_name' => 'Big order', 'items' => [['product_id' => $this->cement->id, 'quantity' => 50]]]);
    convert($big, ['customer_id' => $this->customer->id])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Not enough stock for CEM-50: 8 on hand, 50 requested.');
    expect($big->fresh()->status->value)->toBe('open');
});

it('lets a typed-in customer buy on credit once a registered customer is chosen', function () {
    $typed = app(ProformaManager::class)->create(['customer_name' => 'Hanna', 'items' => [['product_id' => $this->cement->id, 'quantity' => 1]]]);

    convert($typed, ['customer_id' => $this->customer->id])->assertCreated()->assertJsonPath('data.customer.id', $this->customer->id);
});

it('converts on the last valid day but not after', function () {
    $this->travelTo('2026-11-03 18:00:00');
    $lastDay = app(ProformaManager::class)->create(['customer_id' => $this->customer->id, 'valid_until' => '2026-11-03', 'items' => [['product_id' => $this->cement->id, 'quantity' => 1]]]);
    convert($lastDay)->assertCreated();

    $this->travelTo('2026-11-04 08:00:00');
    convert($this->proforma)
        ->assertUnprocessable()
        ->assertJsonPath('message', 'PRF-000001 expired on 2026-11-03. Create a new proforma with current prices.');
});

it('converts only once and never a cancelled proforma', function () {
    convert($this->proforma)->assertCreated();
    convert($this->proforma)->assertConflict()->assertJsonPath('message', 'PRF-000001 was already converted to a sale.');

    $cancelled = app(ProformaManager::class)->create(['customer_id' => $this->customer->id, 'items' => [['product_id' => $this->cement->id, 'quantity' => 1]]]);
    app(ProformaManager::class)->cancel($cancelled, 'Lost the deal');
    convert($cancelled)->assertConflict();

    expect(Sale::count())->toBe(1);
});

it('freezes a converted proforma, even if its sale is voided later', function () {
    $sale = Sale::findOrFail(convert($this->proforma)->json('data.id'));
    app(DocumentVoider::class)->void($sale, 'Customer returned');

    $this->patchJson("/api/v1/proformas/{$this->proforma->id}", ['note' => 'x'])
        ->assertConflict()
        ->assertJsonPath('message', "PRF-000001 was converted to {$sale->number} and can no longer change.");
    $this->getJson("/api/v1/proformas/{$this->proforma->id}")->assertJsonPath('data.status', 'converted');
});

it('needs sales.create', function () {
    $this->actingAs(User::factory()->create(), 'sanctum');

    convert($this->proforma)->assertForbidden();
});
