<?php

use App\Enums\Permission;

it('returns the owner with every permission name', function () {
    $user = actingAsOwner();

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.roles', ['owner'])
        ->assertJsonPath('data.permissions', collect(Permission::values())->sort()->values()->all());
});

it('returns staff with only their permissions', function () {
    actingAsStaff();

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.roles', ['staff'])
        ->assertJsonPath('data.permissions', ['payments.record', 'sales.create']);
});

it('requires authentication', function () {
    $this->getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});
