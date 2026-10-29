<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\Proforma;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ProformaManager;

beforeEach(function () {
    $this->travelTo('2026-10-27 10:00:00');
    $this->cement = Product::factory()->create(['sku' => 'CEM-50', 'selling_price' => '720.00']);
    $this->paint = Product::factory()->create(['sku' => 'PNT-W4', 'selling_price' => '1100.00']);
});

/**
 * An open proforma for a typed-in customer: 2 cement at the selling price, 1 paint at 1050.
 */
function quote(array $overrides = []): Proforma
{
    return app(ProformaManager::class)->create([
        'customer_name' => 'Hanna Construction',
        'items' => [
            ['product_id' => test()->cement->id, 'quantity' => 2],
            ['product_id' => test()->paint->id, 'quantity' => 1, 'unit_price' => '1050.00'],
        ],
        ...$overrides,
    ]);
}

it('quotes prices for someone who is not a customer yet', function () {
    $owner = actingAsOwner();

    $this->postJson('/api/v1/proformas', [
        'customer_name' => 'Hanna Construction',
        'customer_phone' => '0911 223344',
        'note' => 'Delivery to Bole',
        'items' => [
            ['product_id' => $this->cement->id, 'quantity' => 2],
            ['product_id' => $this->paint->id, 'quantity' => 1, 'unit_price' => '1050.00'],
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.number', 'PRF-000001')
        ->assertJsonPath('data.customer', null)
        ->assertJsonPath('data.customer_name', 'Hanna Construction')
        ->assertJsonPath('data.customer_phone', '0911 223344')
        ->assertJsonPath('data.proforma_date', '2026-10-27')
        ->assertJsonPath('data.valid_until', '2026-11-03')
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.total_amount', '2490.00')
        ->assertJsonPath('data.items.0.unit_price', '720.00')
        ->assertJsonPath('data.items.0.line_total', '1440.00')
        ->assertJsonPath('data.items.1.unit_price', '1050.00')
        ->assertJsonPath('data.created_by_user.id', $owner->id);

    expect(StockMovement::count())->toBe(0)->and(Sale::count())->toBe(0);
});

it('quotes for a registered customer, with no stock check', function () {
    actingAsOwner();
    $customer = Customer::factory()->create(['name' => 'Abebe', 'phone' => '0922']);

    $this->postJson('/api/v1/proformas', [
        'customer_id' => $customer->id,
        'valid_until' => '2026-11-30',
        'items' => [['product_id' => $this->cement->id, 'quantity' => 500]],
    ])
        ->assertCreated()
        ->assertJsonPath('data.customer', ['id' => $customer->id, 'name' => 'Abebe', 'phone' => '0922'])
        ->assertJsonPath('data.customer_name', 'Abebe')
        ->assertJsonPath('data.valid_until', '2026-11-30')
        ->assertJsonPath('data.total_amount', '360000.00');
});

it('validates a proforma', function (Closure $body, string $field, ?string $message = null) {
    actingAsOwner();

    $response = $this->postJson('/api/v1/proformas', $body($this))->assertUnprocessable();
    $message ? $response->assertJsonValidationErrors([$field => $message]) : $response->assertJsonValidationErrors($field);
})->with([
    'no customer' => [fn ($test) => ['items' => [['product_id' => $test->cement->id, 'quantity' => 1]]], 'customer_name', 'Choose a customer or type a name.'],
    'both customers' => [fn ($test) => ['customer_id' => Customer::factory()->create()->id, 'customer_name' => 'X', 'items' => [['product_id' => $test->cement->id, 'quantity' => 1]]], 'customer_id', 'Choose a customer or type a name, not both.'],
    'valid until before the date' => [fn ($test) => ['customer_name' => 'X', 'valid_until' => '2026-10-26', 'items' => [['product_id' => $test->cement->id, 'quantity' => 1]]], 'valid_until', 'The valid-until date cannot be before the proforma date.'],
    'same product twice' => [fn ($test) => ['customer_name' => 'X', 'items' => [['product_id' => $test->cement->id, 'quantity' => 1], ['product_id' => $test->cement->id, 'quantity' => 1]]], 'items.1.product_id'],
    'deleted product' => [fn ($test) => ['customer_name' => 'X', 'items' => [['product_id' => tap($test->cement)->delete()->id, 'quantity' => 1]]], 'items.0.product_id'],
    'no items' => [fn ($test) => ['customer_name' => 'X', 'items' => []], 'items'],
]);

it('previews the totals without saving', function () {
    actingAsOwner();

    $this->postJson('/api/v1/proformas/preview', ['items' => [
        ['product_id' => $this->cement->id, 'quantity' => 3],
        ['product_id' => $this->paint->id, 'quantity' => 1, 'unit_price' => '999.99'],
    ]])
        ->assertOk()
        ->assertJsonPath('data.items.0.line_total', '2160.00')
        ->assertJsonPath('data.total_amount', '3159.99');

    expect(Proforma::count())->toBe(0);
});

it('edits an open proforma and replaces its items', function () {
    actingAsOwner();
    $proforma = quote();

    $this->patchJson("/api/v1/proformas/{$proforma->id}", [
        'customer_phone' => '0911 000000',
        'valid_until' => '2026-11-10',
        'items' => [['product_id' => $this->paint->id, 'quantity' => 4, 'unit_price' => '1000.00']],
    ])
        ->assertOk()
        ->assertJsonPath('data.customer_name', 'Hanna Construction')
        ->assertJsonPath('data.customer_phone', '0911 000000')
        ->assertJsonPath('data.valid_until', '2026-11-10')
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.total_amount', '4000.00');
});

it('switches between a typed name and a registered customer', function () {
    actingAsOwner();
    $proforma = quote(['customer_phone' => '0911']);
    $customer = Customer::factory()->create();

    $this->patchJson("/api/v1/proformas/{$proforma->id}", ['customer_id' => $customer->id])
        ->assertJsonPath('data.customer.id', $customer->id)
        ->assertJsonPath('data.customer_name', $customer->name);
    expect($proforma->fresh()->only(['customer_name', 'customer_phone']))->toBe(['customer_name' => null, 'customer_phone' => null]);

    $this->patchJson("/api/v1/proformas/{$proforma->id}", ['customer_id' => null])
        ->assertJsonValidationErrors(['customer_name' => 'Choose a customer or type a name.']);

    $this->patchJson("/api/v1/proformas/{$proforma->id}", ['customer_id' => null, 'customer_name' => 'Walk-in Tsehay'])
        ->assertJsonPath('data.customer', null)
        ->assertJsonPath('data.customer_name', 'Walk-in Tsehay');
});

it('cancels an open proforma with a reason', function () {
    $owner = actingAsOwner();
    $proforma = quote();

    $this->postJson("/api/v1/proformas/{$proforma->id}/cancel", [])
        ->assertJsonValidationErrors(['reason' => 'A reason is required to cancel a proforma.']);

    $this->postJson("/api/v1/proformas/{$proforma->id}/cancel", ['reason' => 'Customer bought elsewhere'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancel_reason', 'Customer bought elsewhere')
        ->assertJsonPath('data.cancelled_by_user.id', $owner->id);
});

it('freezes cancelled and expired proformas', function () {
    actingAsOwner();
    $cancelled = quote();
    app(ProformaManager::class)->cancel($cancelled, 'x');
    $expired = quote(['valid_until' => '2026-10-28']);

    $this->patchJson("/api/v1/proformas/{$cancelled->id}", ['note' => 'x'])
        ->assertConflict()
        ->assertJsonPath('message', "{$cancelled->number} is cancelled.");

    $this->patchJson("/api/v1/proformas/{$expired->id}", ['note' => 'still fine on its last day'])->assertOk();

    $this->travelTo('2026-10-29 08:00:00');
    $this->getJson("/api/v1/proformas/{$expired->id}")->assertJsonPath('data.status', 'expired')->assertJsonPath('data.is_expired', true);
    $this->patchJson("/api/v1/proformas/{$expired->id}", ['note' => 'x'])
        ->assertConflict()
        ->assertJsonPath('message', "{$expired->number} expired on 2026-10-28. Create a new proforma with current prices.");
    $this->postJson("/api/v1/proformas/{$expired->id}/cancel", ['reason' => 'x'])->assertConflict();
});

it('refuses to change the proforma date', function () {
    actingAsOwner();

    $this->patchJson('/api/v1/proformas/'.quote()->id, ['proforma_date' => '2026-10-20'])->assertJsonValidationErrors('proforma_date');
});

it('lists proformas by status, search and customer', function () {
    actingAsOwner();
    $open = quote(['customer_name' => 'Hanna Construction']);
    $expiring = quote(['customer_name' => 'Old Quote', 'valid_until' => '2026-10-27']);
    $cancelled = quote(['customer_name' => 'Gone']);
    app(ProformaManager::class)->cancel($cancelled, 'x');
    $this->travelTo('2026-10-28 08:00:00');

    $this->getJson('/api/v1/proformas?status=open')->assertJsonPath('data.*.id', [$open->id]);
    $this->getJson('/api/v1/proformas?status=expired')->assertJsonPath('data.*.id', [$expiring->id]);
    $this->getJson('/api/v1/proformas?status=cancelled')->assertJsonPath('data.*.id', [$cancelled->id]);
    $this->getJson('/api/v1/proformas?search=hanna')->assertJsonPath('data.*.id', [$open->id]);
    $this->getJson("/api/v1/proformas?search={$expiring->number}")->assertJsonPath('data.*.id', [$expiring->id]);
});

it('lets anyone logged in view proformas but only sellers change them', function () {
    actingAsOwner();
    $proforma = quote();

    $this->actingAs(User::factory()->create(), 'sanctum');
    $this->getJson('/api/v1/proformas')->assertOk();
    $this->getJson("/api/v1/proformas/{$proforma->id}")->assertOk();
    $this->postJson('/api/v1/proformas', ['customer_name' => 'X', 'items' => [['product_id' => $this->cement->id, 'quantity' => 1]]])->assertForbidden();
    $this->patchJson("/api/v1/proformas/{$proforma->id}", ['note' => 'x'])->assertForbidden();

    actingAsStaff();
    $this->postJson('/api/v1/proformas', ['customer_name' => 'X', 'items' => [['product_id' => $this->cement->id, 'quantity' => 1]]])->assertCreated();
});
