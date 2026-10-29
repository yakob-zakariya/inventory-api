<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateShopRequest;
use App\Http\Resources\ShopBrandResource;
use App\Http\Resources\ShopResource;
use App\Models\ShopSetting;

class ShopController extends Controller
{
    /**
     * The shop's name, address, phone, email, TIN and currency, for printed documents.
     */
    public function show(): ShopResource
    {
        return new ShopResource(ShopSetting::current());
    }

    /**
     * The shop's name and logo for the login page. Needs no login, so it shows nothing else.
     */
    public function brand(): ShopBrandResource
    {
        return new ShopBrandResource(ShopSetting::current());
    }

    /**
     * Change the shop's details. The currency is fixed at installation.
     */
    public function update(UpdateShopRequest $request): ShopResource
    {
        $shop = ShopSetting::current();
        $shop->update($request->validated());

        return new ShopResource($shop);
    }
}
