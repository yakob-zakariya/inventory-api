<?php

use App\Models\Category;

it('lets any logged-in user list and show categories', function () {
    actingAsStaff();
    $category = Category::factory()->create(['name' => 'Cement']);
    Category::factory()->create(['name' => 'Paint']);

    $this->getJson('/api/v1/categories')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Cement')
        ->assertJsonStructure(['links', 'meta']);

    $this->getJson("/api/v1/categories/{$category->id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Cement');
});

it('filters categories by name', function () {
    actingAsStaff();
    Category::factory()->create(['name' => 'Cement']);
    Category::factory()->create(['name' => 'Paint']);

    $this->getJson('/api/v1/categories?search=cem')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Cement');
});

it('lets the owner create a category and records the creator', function () {
    $owner = actingAsOwner();

    $this->postJson('/api/v1/categories', ['name' => 'Cement', 'description' => 'Bags of cement'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Cement')
        ->assertJsonPath('data.description', 'Bags of cement')
        ->assertJsonPath('data.created_by', $owner->id);
});

it('lets the owner update a category', function () {
    actingAsOwner();
    $category = Category::factory()->create(['name' => 'Cement']);

    $this->patchJson("/api/v1/categories/{$category->id}", ['name' => 'Cement and lime'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Cement and lime');

    $this->putJson("/api/v1/categories/{$category->id}", ['name' => 'Cement and lime'])
        ->assertOk();
});

it('lets the owner delete an unused category', function () {
    actingAsOwner();
    $category = Category::factory()->create();

    $this->deleteJson("/api/v1/categories/{$category->id}")->assertNoContent();

    expect(Category::find($category->id))->toBeNull();
});

it('forbids staff from changing categories', function () {
    actingAsStaff();
    $category = Category::factory()->create();

    $this->postJson('/api/v1/categories', ['name' => 'Cement'])->assertForbidden();
    $this->patchJson("/api/v1/categories/{$category->id}", ['name' => 'Other'])->assertForbidden();
    $this->deleteJson("/api/v1/categories/{$category->id}")->assertForbidden();
});

it('requires authentication', function () {
    $this->getJson('/api/v1/categories')->assertUnauthorized();
});

it('returns 404 for an unknown category', function () {
    actingAsStaff();

    $this->getJson('/api/v1/categories/999')->assertNotFound();
});

it('validates a new category', function (array $payload, string $field) {
    actingAsOwner();
    Category::factory()->create(['name' => 'Cement']);

    $this->postJson('/api/v1/categories', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'duplicate name' => [['name' => 'Cement'], 'name'],
    'long name' => [['name' => str_repeat('a', 256)], 'name'],
]);

it('allows keeping the same name on update', function () {
    actingAsOwner();
    $category = Category::factory()->create(['name' => 'Cement']);

    $this->patchJson("/api/v1/categories/{$category->id}", ['name' => 'Cement', 'description' => 'New'])
        ->assertOk();
});
