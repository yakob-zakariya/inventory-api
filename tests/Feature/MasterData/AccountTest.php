<?php

use App\Models\Account;

it('lets the owner create an account', function () {
    $owner = actingAsOwner();

    $this->postJson('/api/v1/accounts', ['name' => 'Main cash', 'type' => 'cash', 'opening_balance' => '1500.5'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Main cash')
        ->assertJsonPath('data.type', 'cash')
        ->assertJsonPath('data.opening_balance', '1500.50')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.created_by', $owner->id);
});

it('defaults the opening balance to zero', function () {
    actingAsOwner();

    $this->postJson('/api/v1/accounts', ['name' => 'Telebirr', 'type' => 'mobile_money'])
        ->assertCreated()
        ->assertJsonPath('data.opening_balance', '0.00');
});

it('lets the owner rename and deactivate an account', function () {
    actingAsOwner();
    $account = Account::factory()->create();

    $this->patchJson("/api/v1/accounts/{$account->id}", ['name' => 'Old bank', 'is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.name', 'Old bank')
        ->assertJsonPath('data.is_active', false);
});

it('refuses to change the opening balance', function () {
    actingAsOwner();
    $account = Account::factory()->create(['opening_balance' => '100.00']);

    $this->patchJson("/api/v1/accounts/{$account->id}", ['opening_balance' => '200.00'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['opening_balance' => 'The opening balance cannot be changed. Record an adjustment instead.']);

    expect($account->fresh()->opening_balance)->toBe('100.00');
});

it('has no delete endpoint', function () {
    actingAsOwner();
    $account = Account::factory()->create();

    $this->deleteJson("/api/v1/accounts/{$account->id}")->assertMethodNotAllowed();

    expect(Account::find($account->id))->not->toBeNull();
});

it('lets staff view accounts but not change them', function () {
    actingAsStaff();
    $account = Account::factory()->create();

    $this->getJson('/api/v1/accounts')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/accounts/{$account->id}")->assertOk();

    $this->postJson('/api/v1/accounts', ['name' => 'X', 'type' => 'cash'])->assertForbidden();
    $this->patchJson("/api/v1/accounts/{$account->id}", ['is_active' => false])->assertForbidden();
});

it('filters to active accounts and searches by name', function () {
    actingAsStaff();
    Account::factory()->create(['name' => 'Main cash']);
    Account::factory()->create(['name' => 'CBE bank']);
    Account::factory()->inactive()->create(['name' => 'Closed bank']);

    $this->getJson('/api/v1/accounts')->assertJsonCount(3, 'data');
    $this->getJson('/api/v1/accounts?active=1')->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/accounts?search=bank')->assertJsonCount(2, 'data');
});

it('requires authentication', function () {
    $this->getJson('/api/v1/accounts')->assertUnauthorized();
});

it('validates a new account', function (array $payload, string $field) {
    actingAsOwner();
    Account::factory()->create(['name' => 'Main cash']);

    $this->postJson('/api/v1/accounts', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'missing name' => [['type' => 'cash'], 'name'],
    'duplicate name' => [['name' => 'Main cash', 'type' => 'cash'], 'name'],
    'unknown type' => [['name' => 'A', 'type' => 'crypto'], 'type'],
    'negative opening balance' => [['name' => 'A', 'type' => 'cash', 'opening_balance' => -5], 'opening_balance'],
    'three decimals' => [['name' => 'A', 'type' => 'cash', 'opening_balance' => '1.005'], 'opening_balance'],
]);
