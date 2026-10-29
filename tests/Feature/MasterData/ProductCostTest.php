<?php

use App\Models\Product;

it('hides costs on products from staff', function () {
    actingAsStaff();
    $product = Product::factory()->create(['additional_cost' => '20.00']);

    $this->getJson("/api/v1/products/{$product->id}")->assertOk()
        ->assertJsonPath('data.selling_price', $product->selling_price)
        ->assertJsonMissingPath('data.cost_price')
        ->assertJsonMissingPath('data.additional_cost');
    $this->getJson('/api/v1/products')->assertOk()->assertJsonMissingPath('data.0.cost_price');
});

it('shows costs on products with costs.view or purchases.create', function (string $permission) {
    actingWithPermissions([$permission]);
    $product = Product::factory()->create(['cost_price' => '650.00', 'additional_cost' => '20.00']);

    $this->getJson("/api/v1/products/{$product->id}")->assertOk()
        ->assertJsonPath('data.cost_price', '650.00')
        ->assertJsonPath('data.additional_cost', '20.00');
})->with(['costs.view', 'purchases.create']);
