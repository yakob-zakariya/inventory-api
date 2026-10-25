<?php

use App\Models\Customer;

it('lets staff register, update and delete customers', function () {
    $staff = actingAsStaff();

    $id = $this->postJson('/api/v1/customers', ['name' => 'Almaz', 'phone' => '+251911000000'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Almaz')
        ->assertJsonPath('data.phone', '+251911000000')
        ->assertJsonPath('data.created_by', $staff->id)
        ->json('data.id');

    $this->patchJson("/api/v1/customers/{$id}", ['address' => 'Bole'])
        ->assertOk()
        ->assertJsonPath('data.address', 'Bole');

    $this->deleteJson("/api/v1/customers/{$id}")->assertNoContent();

    expect(Customer::withTrashed()->find($id)->trashed())->toBeTrue();
    $this->getJson("/api/v1/customers/{$id}")->assertNotFound();
});

it('lets the owner manage customers too', function () {
    actingAsOwner();

    $this->postJson('/api/v1/customers', ['name' => 'Almaz'])->assertCreated();
});

it('lists and searches customers by name or phone', function () {
    actingAsStaff();
    Customer::factory()->create(['name' => 'Almaz', 'phone' => '+251911111111']);
    Customer::factory()->create(['name' => 'Bekele', 'phone' => '+251922222222']);

    $this->getJson('/api/v1/customers')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/customers?search=alm')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/customers?search=92222')->assertJsonPath('data.0.name', 'Bekele');
});

it('allows several customers without a phone', function () {
    actingAsStaff();

    $this->postJson('/api/v1/customers', ['name' => 'First'])->assertCreated();
    $this->postJson('/api/v1/customers', ['name' => 'Second', 'phone' => null])->assertCreated();
});

it('rejects a phone used by another customer, even a deleted one', function () {
    actingAsStaff();
    Customer::factory()->create(['phone' => '+251911000000'])->delete();

    $this->postJson('/api/v1/customers', ['name' => 'Almaz', 'phone' => '+251911000000'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('phone');
});

it('allows a customer to keep their own phone on update', function () {
    actingAsStaff();
    $customer = Customer::factory()->create(['phone' => '+251911000000']);

    $this->patchJson("/api/v1/customers/{$customer->id}", ['phone' => '+251911000000', 'name' => 'New'])
        ->assertOk();
});

it('requires a name', function () {
    actingAsStaff();

    $this->postJson('/api/v1/customers', ['phone' => '+251911000000'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

it('requires authentication', function () {
    $this->getJson('/api/v1/customers')->assertUnauthorized();
});
