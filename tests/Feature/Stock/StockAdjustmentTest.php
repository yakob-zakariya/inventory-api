<?php

use App\Models\Product;
use App\Models\StockMovement;

it('lets the owner add opening stock', function () {
    $owner = actingAsOwner();
    $product = Product::factory()->create();

    $this->postJson('/api/v1/stock-adjustments', ['product_id' => $product->id, 'quantity' => 40, 'note' => 'Opening stock'])
        ->assertCreated()
        ->assertJsonPath('data.type', 'adjustment')
        ->assertJsonPath('data.quantity', 40)
        ->assertJsonPath('data.note', 'Opening stock')
        ->assertJsonPath('data.product.id', $product->id)
        ->assertJsonPath('data.created_by', $owner->id);

    expect($product->currentStock())->toBe(40);
});

it('reduces stock with a negative adjustment', function () {
    actingAsOwner();
    $product = Product::factory()->create();
    StockMovement::factory()->for($product)->create(['quantity' => 10]);

    $this->postJson('/api/v1/stock-adjustments', ['product_id' => $product->id, 'quantity' => '-3', 'note' => 'Damaged bags'])
        ->assertCreated()
        ->assertJsonPath('data.quantity', -3);

    expect($product->currentStock())->toBe(7);
});

it('refuses an adjustment that would make stock negative', function () {
    actingAsOwner();
    $product = Product::factory()->create(['sku' => 'CEM-50']);
    StockMovement::factory()->for($product)->create(['quantity' => 2]);

    $this->postJson('/api/v1/stock-adjustments', ['product_id' => $product->id, 'quantity' => -5, 'note' => 'Recount'])
        ->assertUnprocessable()
        ->assertExactJson(['message' => 'Not enough stock for CEM-50: 2 on hand, 5 requested.']);

    expect(StockMovement::count())->toBe(1)
        ->and($product->currentStock())->toBe(2);
});

it('validates an adjustment', function (Closure $payload, string $field) {
    actingAsOwner();

    $this->postJson('/api/v1/stock-adjustments', $payload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'missing reason' => [fn () => ['product_id' => Product::factory()->create()->id, 'quantity' => 5], 'note'],
    'zero quantity' => [fn () => ['product_id' => Product::factory()->create()->id, 'quantity' => 0, 'note' => 'x'], 'quantity'],
    'fractional quantity' => [fn () => ['product_id' => Product::factory()->create()->id, 'quantity' => 1.5, 'note' => 'x'], 'quantity'],
    'huge quantity' => [fn () => ['product_id' => Product::factory()->create()->id, 'quantity' => 2_000_000, 'note' => 'x'], 'quantity'],
    'unknown product' => [fn () => ['product_id' => 999, 'quantity' => 5, 'note' => 'x'], 'product_id'],
    'deleted product' => [function () {
        $product = Product::factory()->create();
        $product->delete();

        return ['product_id' => $product->id, 'quantity' => 5, 'note' => 'x'];
    }, 'product_id'],
]);

it('forbids staff from adjusting stock', function () {
    actingAsStaff();
    $product = Product::factory()->create();

    $this->postJson('/api/v1/stock-adjustments', ['product_id' => $product->id, 'quantity' => 5, 'note' => 'x'])
        ->assertForbidden();

    expect(StockMovement::count())->toBe(0);
});

it('requires authentication', function () {
    $this->postJson('/api/v1/stock-adjustments', [])->assertUnauthorized();
});
