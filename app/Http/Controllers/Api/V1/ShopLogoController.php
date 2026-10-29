<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreImageRequest;
use App\Http\Resources\ShopResource;
use App\Models\ShopSetting;
use App\Services\ImageStore;
use Illuminate\Http\Response;

class ShopLogoController extends Controller
{
    /**
     * Upload the shop's logo for printed documents, replacing the one it had.
     */
    public function store(StoreImageRequest $request, ImageStore $images): ShopResource
    {
        $shop = ShopSetting::current();
        $images->replace($shop, 'logo_path', $request->file('image'), 'shop');

        return new ShopResource($shop);
    }

    /**
     * Remove the shop's logo.
     */
    public function destroy(ImageStore $images): Response
    {
        $images->remove(ShopSetting::current(), 'logo_path');

        return response()->noContent();
    }
}
