<?php

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;

it('lets any logged-in user list movements, newest first', function () {
    actingAsStaff();
    $product = Product::factory()->create(['sku' => 'CEM-50']);
    $first = StockMovement::factory()->for($product)->create(['quantity' => 10]);
    $second = StockMovement::factory()->for($product)->create(['quantity' => -2]);

    $this->getJson('/api/v1/stock-movements')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $second->id)
        ->assertJsonPath('data.1.id', $first->id)
        ->assertJsonPath('data.0.product.sku', 'CEM-50')
        ->assertJsonStructure(['links', 'meta']);
});

it('filters movements by product and type', function () {
    actingAsStaff();
    $cement = Product::factory()->create();
    $paint = Product::factory()->create();
    StockMovement::factory()->for($cement)->create();
    StockMovement::factory()->for($cement)->create(['type' => StockMovementType::Purchase]);
    StockMovement::factory()->for($paint)->create();

    $this->getJson("/api/v1/stock-movements?product_id={$cement->id}")->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/stock-movements?type=purchase')->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/stock-movements?product_id={$cement->id}&type=adjustment")->assertJsonCount(1, 'data');
});

it('keeps showing movements of deleted products', function () {
    actingAsStaff();
    $product = Product::factory()->create(['sku' => 'OLD-1']);
    StockMovement::factory()->for($product)->create();
    $product->delete();

    $this->getJson('/api/v1/stock-movements')->assertJsonPath('data.0.product.sku', 'OLD-1');
});

it('rejects an unknown movement type filter', function () {
    actingAsStaff();

    $this->getJson('/api/v1/stock-movements?type=gift')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');
});

it('requires authentication', function () {
    $this->getJson('/api/v1/stock-movements')->assertUnauthorized();
});
