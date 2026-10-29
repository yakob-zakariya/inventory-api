<?php

use App\Models\Account;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->cement = Product::factory()->create(['cost_price' => '100.00', 'selling_price' => '150.00', 'additional_cost' => '20.00']);
    StockMovement::factory()->for($this->cement)->create(['quantity' => 50]);
});

/**
 * Preview a cash sale of the cement at the given price.
 */
function previewCement(string $price, int $quantity = 1): TestResponse
{
    $cement = test()->cement;

    return test()->postJson('/api/v1/sales/preview', [
        'items' => [['product_id' => $cement->id, 'quantity' => $quantity, 'unit_price' => $price]],
        'payments' => [['account_id' => Account::factory()->create()->id, 'amount' => number_format((float) $price * $quantity, 2, '.', '')]],
    ])->assertOk();
}

it('estimates the profit of each line and of the sale', function () {
    actingAsOwner();
    $nails = Product::factory()->create(['cost_price' => '50.00', 'additional_cost' => '0.00']);
    StockMovement::factory()->for($nails)->create(['quantity' => 10]);

    $this->postJson('/api/v1/sales/preview', [
        'items' => [
            ['product_id' => $this->cement->id, 'quantity' => 4, 'unit_price' => '150.00'],
            ['product_id' => $nails->id, 'quantity' => 2, 'unit_price' => '45.00'],
        ],
        'payments' => [['account_id' => Account::factory()->create()->id, 'amount' => '690.00']],
    ])->assertOk()
        ->assertJsonPath('data.items.0.unit_cost', '100.00')
        ->assertJsonPath('data.items.0.additional_cost', '20.00')
        ->assertJsonPath('data.items.0.estimated_profit', '120.00')
        ->assertJsonPath('data.items.1.estimated_profit', '-10.00')
        ->assertJsonPath('data.items.1.below_cost', true)
        ->assertJsonPath('data.estimated_profit', '110.00')
        ->assertJsonPath('data.problems', []);
});

it('marks a price below cost plus additional cost', function (string $price, bool $belowCost) {
    actingAsOwner();

    previewCement($price)->assertJsonPath('data.items.0.below_cost', $belowCost);
})->with([
    'one cent below' => ['119.99', true],
    'exactly at' => ['120.00', false],
    'one cent above' => ['120.01', false],
]);

it('still lets a sale below cost be saved', function () {
    actingAsOwner();

    $this->postJson('/api/v1/sales', [
        'items' => [['product_id' => $this->cement->id, 'quantity' => 1, 'unit_price' => '110.00']],
        'payments' => [['account_id' => Account::factory()->create()->id, 'amount' => '110.00']],
    ])->assertCreated();
});

it('warns staff without showing them any cost', function () {
    actingAsStaff();

    $response = previewCement('110.00')->assertJsonPath('data.items.0.below_cost', true);

    expect($response->json('data.items.0'))->not->toHaveKeys(['unit_cost', 'additional_cost', 'estimated_profit'])
        ->and($response->json('data'))->not->toHaveKey('estimated_profit');
});

it('shows the numbers to a role with costs.view', function () {
    actingWithPermissions(['sales.create', 'costs.view']);

    previewCement('150.00', 2)->assertJsonPath('data.items.0.estimated_profit', '60.00')
        ->assertJsonPath('data.estimated_profit', '60.00');
});

it('gives the same hints on the proforma preview', function () {
    actingAsOwner();

    $this->postJson('/api/v1/proformas/preview', [
        'items' => [['product_id' => $this->cement->id, 'quantity' => 3, 'unit_price' => '115.00']],
    ])->assertOk()
        ->assertJsonPath('data.items.0.below_cost', true)
        ->assertJsonPath('data.items.0.estimated_profit', '-15.00')
        ->assertJsonPath('data.estimated_profit', '-15.00');

    actingAsStaff();
    $response = $this->postJson('/api/v1/proformas/preview', [
        'items' => [['product_id' => $this->cement->id, 'quantity' => 3, 'unit_price' => '115.00']],
    ])->assertOk()->assertJsonPath('data.items.0.below_cost', true);
    expect($response->json('data.items.0'))->not->toHaveKey('estimated_profit');
});

it('leaves the profit report and the business summary unchanged', function () {
    actingAsOwner();
    $this->postJson('/api/v1/sales', [
        'items' => [['product_id' => $this->cement->id, 'quantity' => 2]],
        'payments' => [['account_id' => Account::factory()->create()->id, 'amount' => '300.00']],
    ])->assertCreated();
    $profit = $this->getJson('/api/v1/reports/profit')->assertOk()->json('data');
    $summary = $this->getJson('/api/v1/reports/summary')->assertOk()->json('data');

    $this->cement->update(['additional_cost' => '45.00']);

    expect($this->getJson('/api/v1/reports/profit')->json('data'))->toBe($profit);
    $after = $this->getJson('/api/v1/reports/summary')->json('data');
    expect(collect($after)->except('as_of')->all())->toBe(collect($summary)->except('as_of')->all());
});
