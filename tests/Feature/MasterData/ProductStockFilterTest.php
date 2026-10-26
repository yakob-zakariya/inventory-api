<?php

use App\Models\Product;
use App\Models\StockMovement;

/**
 * A product with the given stock (via movements) and reorder level.
 */
function productWithStock(string $name, int $stock, int $reorderLevel = 5): Product
{
    $product = Product::factory()->create(['name' => $name, 'reorder_level' => $reorderLevel]);

    if ($stock !== 0) {
        StockMovement::factory()->for($product)->create(['quantity' => $stock + 3]);
        StockMovement::factory()->for($product)->create(['quantity' => -3]);
    }

    return $product;
}

beforeEach(function () {
    actingAsOwner();
    productWithStock('Never stocked', 0);
    productWithStock('Sold out', 0);
    StockMovement::factory()->for(Product::firstWhere('name', 'Sold out'))->createMany([['quantity' => 4], ['quantity' => -4]]);
    productWithStock('At reorder level', 5);
    productWithStock('One below', 4);
    productWithStock('One above', 6);
    productWithStock('No reorder level', 1, reorderLevel: 0);
});

it('filters products with low stock', function () {
    $this->getJson('/api/v1/products?stock=low')
        ->assertJsonPath('data.*.name', ['At reorder level', 'One below']);
});

it('filters products that are out of stock', function () {
    $this->getJson('/api/v1/products?stock=out')
        ->assertJsonPath('data.*.name', ['Never stocked', 'Sold out'])
        ->assertJsonPath('data.*.stock', [0, 0]);
});

it('sorts products by stock', function () {
    $this->getJson('/api/v1/products?sort=stock')
        ->assertJsonPath('data.*.name', ['Never stocked', 'Sold out', 'No reorder level', 'One below', 'At reorder level', 'One above']);

    $this->getJson('/api/v1/products?sort=-stock')
        ->assertJsonPath('data.*.name', ['One above', 'At reorder level', 'One below', 'No reorder level', 'Never stocked', 'Sold out']);
});

it('sorts by name by default', function () {
    $this->getJson('/api/v1/products')
        ->assertJsonPath('data.*.name', ['At reorder level', 'Never stocked', 'No reorder level', 'One above', 'One below', 'Sold out']);
});

it('rejects unknown filter values', function () {
    $this->getJson('/api/v1/products?stock=some&sort=price')->assertJsonValidationErrors(['stock', 'sort']);
});
