<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;

function productPayload(array $overrides = []): array
{
    return [
        'category_id' => Category::factory()->create()->id,
        'sku' => 'CEM-50',
        'name' => 'Cement 50kg',
        'unit' => 'bag',
        'cost_price' => 650,
        'selling_price' => '720.5',
        'reorder_level' => 10,
        ...$overrides,
    ];
}

it('lets the owner create a product with prices as two-decimal strings', function () {
    $owner = actingAsOwner();

    $this->postJson('/api/v1/products', productPayload())
        ->assertCreated()
        ->assertJsonPath('data.sku', 'CEM-50')
        ->assertJsonPath('data.cost_price', '650.00')
        ->assertJsonPath('data.selling_price', '720.50')
        ->assertJsonPath('data.reorder_level', 10)
        ->assertJsonPath('data.category.id', Product::first()->category_id)
        ->assertJsonPath('data.created_by', $owner->id);
});

it('defaults the reorder level to zero', function () {
    actingAsOwner();
    $payload = productPayload();
    unset($payload['reorder_level']);

    $this->postJson('/api/v1/products', $payload)
        ->assertCreated()
        ->assertJsonPath('data.reorder_level', 0);
});

it('lets any logged-in user list and show products with their category', function () {
    actingAsStaff();
    $product = Product::factory()->create(['name' => 'Cement 50kg']);
    Product::factory()->create(['name' => 'White paint']);

    $this->getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Cement 50kg')
        ->assertJsonPath('data.0.category.id', $product->category_id);

    $this->getJson("/api/v1/products/{$product->id}")
        ->assertOk()
        ->assertJsonPath('data.sku', $product->sku);
});

it('searches products by name or sku and filters by category', function () {
    actingAsStaff();
    $cement = Product::factory()->create(['name' => 'Cement 50kg', 'sku' => 'CEM-50']);
    Product::factory()->create(['name' => 'White paint', 'sku' => 'PNT-01']);
    Product::factory()->create(['name' => 'Red paint', 'sku' => 'PNT-02', 'category_id' => $cement->category_id]);

    $this->getJson('/api/v1/products?search=cement')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/products?search=PNT')->assertJsonCount(2, 'data');
    $this->getJson("/api/v1/products?category_id={$cement->category_id}")->assertJsonCount(2, 'data');
});

it('shows stock calculated from the stock movements', function () {
    actingAsStaff();
    $product = Product::factory()->create();
    $other = Product::factory()->create();
    StockMovement::factory()->for($product)->create(['quantity' => 10]);
    StockMovement::factory()->for($product)->create(['quantity' => -3]);

    $this->getJson("/api/v1/products/{$product->id}")->assertJsonPath('data.stock', 7);
    $this->getJson("/api/v1/products/{$other->id}")->assertJsonPath('data.stock', 0);
    $this->getJson("/api/v1/products?search={$product->sku}")->assertJsonPath('data.0.stock', 7);
});

it('lets the owner update a product', function () {
    actingAsOwner();
    $product = Product::factory()->create();

    $this->patchJson("/api/v1/products/{$product->id}", ['selling_price' => '99.9', 'name' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.selling_price', '99.90')
        ->assertJsonPath('data.name', 'Renamed');
});

it('soft-deletes a product and hides it afterwards', function () {
    actingAsOwner();
    $product = Product::factory()->create();

    $this->deleteJson("/api/v1/products/{$product->id}")->assertNoContent();

    expect(Product::withTrashed()->find($product->id)->trashed())->toBeTrue();

    $this->getJson("/api/v1/products/{$product->id}")->assertNotFound();
    $this->getJson('/api/v1/products')->assertJsonCount(0, 'data');
});

it('refuses to delete a product that still has stock', function () {
    actingAsOwner();
    $product = Product::factory()->create();
    StockMovement::factory()->for($product)->create(['quantity' => 4]);

    $this->deleteJson("/api/v1/products/{$product->id}")
        ->assertConflict()
        ->assertExactJson(['message' => 'This product still has 4 in stock. Adjust stock to zero before deleting it.']);

    expect($product->fresh()->trashed())->toBeFalse();
});

it('deletes a product whose movements net to zero', function () {
    actingAsOwner();
    $product = Product::factory()->create();
    StockMovement::factory()->for($product)->create(['quantity' => 4]);
    StockMovement::factory()->for($product)->create(['quantity' => -4]);

    $this->deleteJson("/api/v1/products/{$product->id}")->assertNoContent();

    expect($product->fresh()->trashed())->toBeTrue();
});

it('forbids staff from changing products', function () {
    actingAsStaff();
    $product = Product::factory()->create();

    $this->postJson('/api/v1/products', productPayload())->assertForbidden();
    $this->patchJson("/api/v1/products/{$product->id}", ['name' => 'Other'])->assertForbidden();
    $this->deleteJson("/api/v1/products/{$product->id}")->assertForbidden();
});

it('requires authentication', function () {
    $this->getJson('/api/v1/products')->assertUnauthorized();
});

it('validates a new product', function (array $overrides, string $field) {
    actingAsOwner();

    $this->postJson('/api/v1/products', productPayload($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'unknown category' => [['category_id' => 999], 'category_id'],
    'missing sku' => [['sku' => ''], 'sku'],
    'missing name' => [['name' => ''], 'name'],
    'missing unit' => [['unit' => ''], 'unit'],
    'negative cost' => [['cost_price' => -1], 'cost_price'],
    'three decimals' => [['selling_price' => '10.555'], 'selling_price'],
    'not a number' => [['selling_price' => 'ten'], 'selling_price'],
    'too large' => [['cost_price' => '10000000000.00'], 'cost_price'],
    'fractional reorder level' => [['reorder_level' => 1.5], 'reorder_level'],
    'negative reorder level' => [['reorder_level' => -1], 'reorder_level'],
]);

it('rejects a sku used by another product, even a deleted one', function () {
    actingAsOwner();
    Product::factory()->create(['sku' => 'CEM-50'])->delete();

    $this->postJson('/api/v1/products', productPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sku');
});

it('allows a product to keep its own sku on update', function () {
    actingAsOwner();
    $product = Product::factory()->create(['sku' => 'CEM-50']);

    $this->patchJson("/api/v1/products/{$product->id}", ['sku' => 'CEM-50'])->assertOk();
});

it('refuses to delete a category that has products, even deleted ones', function () {
    actingAsOwner();
    $product = Product::factory()->create();
    $product->delete();

    $this->deleteJson("/api/v1/categories/{$product->category_id}")
        ->assertConflict()
        ->assertExactJson(['message' => 'This category still has products and cannot be deleted.']);

    expect(Category::find($product->category_id))->not->toBeNull();
});
