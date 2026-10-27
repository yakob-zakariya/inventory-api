<?php

/*
|--------------------------------------------------------------------------
| Shop details
|--------------------------------------------------------------------------
|
| Printed on proformas and other documents by the clients. One shop per
| deployment, so they live in the environment. A settings screen may move
| them to the database later.
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
