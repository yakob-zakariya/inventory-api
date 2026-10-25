<?php

use App\Models\Supplier;

it('lets the owner create, update and delete a supplier', function () {
    $owner = actingAsOwner();

    $id = $this->postJson('/api/v1/suppliers', [
        'name' => 'Dangote Cement',
        'contact_person' => 'Abebe',
        'phone' => '+251911000000',
        'email' => 'sales@example.com',
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Dangote Cement')
        ->assertJsonPath('data.address', null)
        ->assertJsonPath('data.created_by', $owner->id)
        ->json('data.id');

    $this->patchJson("/api/v1/suppliers/{$id}", ['address' => 'Addis Ababa'])
        ->assertOk()
        ->assertJsonPath('data.address', 'Addis Ababa')
        ->assertJsonPath('data.name', 'Dangote Cement');

    $this->deleteJson("/api/v1/suppliers/{$id}")->assertNoContent();

    expect(Supplier::withTrashed()->find($id)->trashed())->toBeTrue();
    $this->getJson("/api/v1/suppliers/{$id}")->assertNotFound();
});

it('lets staff view suppliers but not change them', function () {
    actingAsStaff();
    $supplier = Supplier::factory()->create();

    $this->getJson('/api/v1/suppliers')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/suppliers/{$supplier->id}")->assertOk();

    $this->postJson('/api/v1/suppliers', ['name' => 'Other'])->assertForbidden();
    $this->patchJson("/api/v1/suppliers/{$supplier->id}", ['name' => 'Other'])->assertForbidden();
    $this->deleteJson("/api/v1/suppliers/{$supplier->id}")->assertForbidden();
});

it('searches suppliers by name or phone and hides deleted ones', function () {
    actingAsStaff();
    Supplier::factory()->create(['name' => 'Dangote Cement', 'phone' => '+251911111111']);
    Supplier::factory()->create(['name' => 'Paint House', 'phone' => '+251922222222']);
    Supplier::factory()->create(['name' => 'Old Cement Co'])->delete();

    $this->getJson('/api/v1/suppliers?search=cement')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/suppliers?search=92222')->assertJsonPath('data.0.name', 'Paint House');
    $this->getJson('/api/v1/suppliers')->assertJsonCount(2, 'data');
});

it('requires authentication', function () {
    $this->getJson('/api/v1/suppliers')->assertUnauthorized();
});

it('validates a supplier', function (array $payload, string $field) {
    actingAsOwner();

    $this->postJson('/api/v1/suppliers', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'invalid email' => [['name' => 'A', 'email' => 'not-an-email'], 'email'],
    'long phone' => [['name' => 'A', 'phone' => str_repeat('1', 33)], 'phone'],
]);
