<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreProductRequest;
use App\Http\Requests\Api\V1\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $products = Product::query()
            ->with('category')
            ->withStock()
            ->search($request->query('search'))
            ->when($request->integer('category_id'), fn ($query, int $categoryId) => $query->where('category_id', $categoryId))
            ->orderBy('name')
            ->paginate();

        return ProductResource::collection($products);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = Product::create($request->validated());

        return (new ProductResource($this->loadDetails($product)))->response()->setStatusCode(201);
    }

    public function show(Product $product): ProductResource
    {
        return new ProductResource($this->loadDetails($product));
    }

    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $product->update($request->validated());

        return new ProductResource($this->loadDetails($product));
    }

    /**
     * Soft-delete the product so it can no longer be used on new documents.
     */
    public function destroy(Product $product): Response
    {
        $product->delete();

        return response()->noContent();
    }

    /**
     * Load what a single-product response shows: its category and current stock.
     */
    private function loadDetails(Product $product): Product
    {
        return $product->load('category')->loadSum('stockMovements as stock', 'quantity');
    }
}
