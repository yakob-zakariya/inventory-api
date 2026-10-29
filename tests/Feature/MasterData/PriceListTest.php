<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->tiles = Category::factory()->create(['name' => 'Tiles']);
    $this->cement = Category::factory()->create(['name' => 'Cement']);
    Category::factory()->create(['name' => 'Empty']);

    $this->dangote = Product::factory()->for($this->cement)->create(['name' => 'Dangote 50kg', 'unit' => 'bag', 'selling_price' => 720, 'cost_price' => 650]);
    StockMovement::factory()->for($this->dangote)->create(['quantity' => 12]);
    $this->derba = Product::factory()->for($this->cement)->create(['name' => 'Derba 50kg', 'selling_price' => 700]);
    StockMovement::factory()->for($this->derba)->createMany([['quantity' => 3], ['quantity' => -3]]);
    $this->ceramic = Product::factory()->for($this->tiles)->create(['name' => 'Ceramic 40x40']);
    StockMovement::factory()->for($this->ceramic)->create(['quantity' => 5]);
    Product::factory()->for($this->tiles)->create(['name' => 'Old tile'])->delete();
});

it('groups products by category with selling prices', function () {
    actingAsStaff();

    $this->getJson('/api/v1/price-list')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Cement')
        ->assertJsonPath('data.0.products.0', [
            'id' => $this->dangote->id, 'name' => 'Dangote 50kg', 'unit' => 'bag',
            'selling_price' => '720.00', 'image_url' => null, 'available' => true,
        ])
        ->assertJsonPath('data.0.products.1.name', 'Derba 50kg')
        ->assertJsonPath('data.0.products.1.available', false)
        ->assertJsonPath('data.1.name', 'Tiles')
        ->assertJsonCount(1, 'data.1.products');
});

it('never shows costs or stock numbers', function () {
    actingAsOwner();

    $product = $this->getJson('/api/v1/price-list')->json('data.0.products.0');

    expect($product)->not->toHaveKeys(['cost_price', 'stock', 'sku', 'reorder_level']);
});

it('lists only available products when asked', function () {
    actingAsStaff();

    $this->getJson('/api/v1/price-list?available=1')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonCount(1, 'data.0.products')
        ->assertJsonPath('data.0.products.0.name', 'Dangote 50kg');
});

it('filters by category', function () {
    actingAsStaff();

    $this->getJson("/api/v1/price-list?category_id={$this->tiles->id}")->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.products.0.name', 'Ceramic 40x40');
});

it('uses the same number of queries however many products there are', function () {
    actingAsStaff();
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/price-list')->assertOk();

        return count(DB::getQueryLog());
    };
    $before = $count();

    Product::factory()->count(5)->for($this->tiles)->create();

    expect($count())->toBe($before);
});

it('needs a login', function () {
    $this->getJson('/api/v1/price-list')->assertUnauthorized();
});
