<?php

it('returns the shop details for printed documents', function () {
    config(['shop' => ['name' => 'Abebe Building Supplies', 'address' => 'Bole, Addis Ababa', 'phone' => '0911 223344',
        'email' => null, 'tin' => '0012345678', 'currency' => 'ETB']]);
    actingAsStaff();

    $this->getJson('/api/v1/shop')->assertOk()->assertExactJson(['data' => [
        'name' => 'Abebe Building Supplies', 'address' => 'Bole, Addis Ababa', 'phone' => '0911 223344',
        'email' => null, 'tin' => '0012345678', 'currency' => 'ETB',
    ]]);
});

it('needs a login', function () {
    $this->getJson('/api/v1/shop')->assertUnauthorized();
});
