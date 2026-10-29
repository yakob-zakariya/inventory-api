<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreImageRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\ImageStore;
use Illuminate\Http\Response;

class ProductImageController extends Controller
{
    /**
     * Upload the product's photo, replacing the one it had.
     */
    public function store(StoreImageRequest $request, Product $product, ImageStore $images): ProductResource
    {
        $images->replace($product, 'image_path', $request->file('image'), 'products');

        return new ProductResource($product->load('category')->loadSum('stockMovements as stock', 'quantity'));
    }

    /**
     * Remove the product's photo.
     */
    public function destroy(Product $product, ImageStore $images): Response
    {
        $images->remove($product, 'image_path');

        return response()->noContent();
    }
}
