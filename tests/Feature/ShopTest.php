<?php

use App\Models\ShopSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

it('uploads, replaces and removes the logo', function () {
    Storage::fake('public');
    actingAsOwner();

    $this->post('/api/v1/shop/logo', ['image' => UploadedFile::fake()->image('logo.png')], ['Accept' => 'application/json'])
        ->assertOk();
    $firstPath = ShopSetting::current()->logo_path;
    expect($firstPath)->toStartWith('shop/');
    $this->getJson('/api/v1/shop')->assertJsonPath('data.logo_url', Storage::disk('public')->url($firstPath));

    $this->post('/api/v1/shop/logo', ['image' => UploadedFile::fake()->image('logo.webp')], ['Accept' => 'application/json'])
        ->assertOk();
    Storage::disk('public')->assertMissing($firstPath);
    $secondPath = ShopSetting::current()->logo_path;

    $this->deleteJson('/api/v1/shop/logo')->assertNoContent();
    Storage::disk('public')->assertMissing($secondPath);
    $this->getJson('/api/v1/shop')->assertJsonPath('data.logo_url', null);
});

it('checks the logo file like any image', function () {
    Storage::fake('public');
    actingAsOwner();

    $this->post('/api/v1/shop/logo', ['image' => UploadedFile::fake()->image('logo.gif')], ['Accept' => 'application/json'])
        ->assertJsonValidationErrors(['image' => 'The image must be a JPG, PNG or WebP file.']);
});

it('needs settings.manage to change the logo', function () {
    Storage::fake('public');
    actingAsStaff();

    $this->post('/api/v1/shop/logo', ['image' => UploadedFile::fake()->image('logo.png')], ['Accept' => 'application/json'])
        ->assertForbidden();
    $this->deleteJson('/api/v1/shop/logo')->assertForbidden();
});

it('shows only the name and logo before login', function () {
    Storage::fake('public');
    ShopSetting::current()->forceFill(['name' => 'Horizon Metals', 'phone' => '0911 223344', 'tin' => '0012345678',
        'logo_path' => 'shop/logo.png'])->save();

    $this->getJson('/api/v1/shop/brand')->assertOk()->assertExactJson(['data' => [
        'name' => 'Horizon Metals',
        'logo_url' => Storage::disk('public')->url('shop/logo.png'),
    ]]);
});

it('shows a null logo before login when there is none', function () {
    $this->getJson('/api/v1/shop/brand')->assertOk()->assertJsonPath('data.logo_url', null);
});

it('limits how often the brand can be fetched', function () {
    foreach (range(1, 60) as $attempt) {
        $this->getJson('/api/v1/shop/brand')->assertOk();
    }

    $this->getJson('/api/v1/shop/brand')->assertTooManyRequests();
});
