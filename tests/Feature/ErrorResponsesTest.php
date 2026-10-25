<?php

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::prefix('api/v1/_test')->middleware('api')->group(function () {
        Route::get('business-rule', fn () => throw new BusinessRuleException('Not enough stock.'));
        Route::get('conflict', fn () => throw new ConflictException('Category is in use.'));
        Route::get('missing-model', fn () => User::findOrFail(999));
        Route::get('needs-permission', fn () => 'ok')->middleware(['auth:sanctum', 'permission:users.manage']);
    });
});

it('returns 422 with a message for a business rule failure', function () {
    $this->getJson('/api/v1/_test/business-rule')
        ->assertStatus(422)
        ->assertExactJson(['message' => 'Not enough stock.']);
});

it('returns 409 with a message for a conflict', function () {
    $this->getJson('/api/v1/_test/conflict')
        ->assertStatus(409)
        ->assertExactJson(['message' => 'Category is in use.']);
});

it('returns a generic 404 for a missing model', function () {
    $this->getJson('/api/v1/_test/missing-model')
        ->assertNotFound()
        ->assertExactJson(['message' => 'Resource not found.']);
});

it('returns a generic 404 for an unknown api route', function () {
    $this->getJson('/api/v1/does-not-exist')
        ->assertNotFound()
        ->assertExactJson(['message' => 'Resource not found.']);
});

it('returns 401 json when not logged in', function () {
    $this->getJson('/api/v1/_test/needs-permission')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

it('returns 403 json when a permission is missing', function () {
    actingAsStaff();

    $this->getJson('/api/v1/_test/needs-permission')
        ->assertForbidden()
        ->assertExactJson(['message' => 'This action is unauthorized.']);
});

it('lets the permission middleware pass for a sanctum user who has the permission', function () {
    actingAsOwner();

    $this->getJson('/api/v1/_test/needs-permission')->assertOk();
});

it('answers api requests with json even without an accept header', function () {
    $this->get('/api/v1/_test/needs-permission')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);

    $this->get('/api/v1/_test/conflict')
        ->assertStatus(409)
        ->assertExactJson(['message' => 'Category is in use.']);
});
