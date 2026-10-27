<?php

use App\Enums\Permission;

it('lists every permission with a label and a group', function () {
    actingAsOwner();

    $this->getJson('/api/v1/permissions')
        ->assertOk()
        ->assertJsonCount(count(Permission::cases()), 'data')
        ->assertJsonPath('data.5', ['name' => 'sales.void', 'label' => 'Void sales', 'group' => 'Sales']);
});

it('is for users who manage users', function () {
    actingAsStaff();

    $this->getJson('/api/v1/permissions')->assertForbidden();
});
