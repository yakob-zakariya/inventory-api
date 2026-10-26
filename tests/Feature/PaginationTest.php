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
