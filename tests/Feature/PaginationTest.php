<?php

use App\Models\Category;

it('lists ten rows per page', function (string $path) {
    actingAsOwner();

    $this->getJson("/api/v1/{$path}")
        ->assertOk()
        ->assertJsonPath('meta.per_page', 10);
})->with([
    'products', 'categories', 'suppliers', 'customers', 'accounts', 'users',
    'stock-movements', 'purchases', 'sales', 'account-transactions',
]);

it('splits a longer list into pages of ten', function () {
    actingAsOwner();
    Category::factory()->count(23)->create();

    $this->getJson('/api/v1/categories?page=3')
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.total', 23)
        ->assertJsonPath('meta.last_page', 3);
});

it('takes a page size between 1 and 100', function () {
    actingAsOwner();
    Category::factory()->count(5)->create();

    $this->getJson('/api/v1/categories?per_page=2')
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.last_page', 3);

    $this->getJson('/api/v1/categories?per_page=100')->assertJsonPath('meta.per_page', 100);
});

it('rejects a page size outside 1 to 100', function (string $perPage) {
    actingAsOwner();

    $this->getJson("/api/v1/products?per_page={$perPage}")->assertJsonValidationErrors('per_page');
})->with(['0', '101', 'ten']);

it('accepts a page size on every list', function (string $path) {
    actingAsOwner();

    $this->getJson("/api/v1/{$path}?per_page=25")->assertOk()->assertJsonPath('meta.per_page', 25);
})->with([
    'products', 'categories', 'suppliers', 'customers', 'accounts', 'users',
    'stock-movements', 'purchases', 'sales', 'account-transactions',
]);
