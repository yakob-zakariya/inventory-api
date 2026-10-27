<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShopResource;

class ShopController extends Controller
{
    /**
     * The shop's name, address, phone, email, TIN and currency, for printed documents.
     */
    public function __invoke(): ShopResource
    {
        return new ShopResource(config('shop'));
    }
}
