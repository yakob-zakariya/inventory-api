<?php

/*
|--------------------------------------------------------------------------
| Shop details
|--------------------------------------------------------------------------
|
| The starting values for the shop's details. The shop_settings migration
| copies them into the database, where the owner edits them (PATCH /shop);
| changing them here afterwards has no effect. The currency is read from
| here only and is fixed at installation.
|
*/

return [
    'name' => env('SHOP_NAME', env('APP_NAME', 'Shop')),
    'address' => env('SHOP_ADDRESS'),
    'phone' => env('SHOP_PHONE'),
    'email' => env('SHOP_EMAIL'),
    'tin' => env('SHOP_TIN'),
    'currency' => env('SHOP_CURRENCY', 'ETB'),
];
