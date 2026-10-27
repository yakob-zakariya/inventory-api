<?php

use App\Models\ShopSetting;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

it('returns the shop details for printed documents', function () {
    config(['shop.currency' => 'ETB']);
    ShopSetting::current()->update(['name' => 'Abebe Building Supplies', 'address' => 'Bole, Addis Ababa',
        'phone' => '0911 223344', 'email' => null, 'tin' => '0012345678']);
    actingAsStaff();

    $this->getJson('/api/v1/shop')->assertOk()
        ->assertJsonPath('data.name', 'Abebe Building Supplies')
        ->assertJsonPath('data.address', 'Bole, Addis Ababa')
        ->assertJsonPath('data.phone', '0911 223344')
        ->assertJsonPath('data.email', null)
        ->assertJsonPath('data.tin', '0012345678')
        ->assertJsonPath('data.currency', 'ETB');
});

it('needs a login', function () {
    $this->getJson('/api/v1/shop')->assertUnauthorized();
});

it('lets the owner change the details', function () {
    actingAsOwner();

    $this->patchJson('/api/v1/shop', ['name' => 'Abebe Supplies', 'phone' => '0922 000111', 'email' => null])
        ->assertOk()
        ->assertJsonPath('data.name', 'Abebe Supplies')
        ->assertJsonPath('data.phone', '0922 000111');

    expect(ShopSetting::count())->toBe(1)
        ->and(ShopSetting::current()->phone)->toBe('0922 000111');
});

it('keeps fields that are not sent', function () {
    ShopSetting::current()->update(['tin' => '0012345678']);
    actingAsOwner();

    $this->patchJson('/api/v1/shop', ['phone' => '0922 000111'])->assertOk()
        ->assertJsonPath('data.tin', '0012345678');
});

it('lets a custom role with settings.manage change the details', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('Manager', 'web')->syncPermissions(['settings.manage']));
    Sanctum::actingAs($user);

    $this->patchJson('/api/v1/shop', ['name' => 'Abebe Supplies'])->assertOk();
});

it('refuses users without settings.manage', function () {
    actingAsStaff();

    $this->patchJson('/api/v1/shop', ['name' => 'Abebe Supplies'])->assertForbidden();
});

it('requires a name and a valid email', function () {
    actingAsOwner();

    $this->patchJson('/api/v1/shop', ['name' => '', 'email' => 'not-an-email'])
        ->assertJsonValidationErrors(['name', 'email']);
});

it('refuses to change the currency', function () {
    actingAsOwner();

    $this->patchJson('/api/v1/shop', ['currency' => 'USD'])
        ->assertJsonValidationErrors(['currency' => 'The currency is set when the system is installed and cannot be changed here.']);
});

it('starts from the SHOP_* environment values', function () {
    expect(ShopSetting::current()->name)->toBe(config('shop.name'));
});
