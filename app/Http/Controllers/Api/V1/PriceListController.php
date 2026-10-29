<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PriceListRequest;
use App\Http\Resources\PriceListCategoryResource;
use App\Models\Category;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PriceListController extends Controller
{
    /**
     * Products grouped by category with their selling prices, to print for customers.
     * Never shows costs or stock numbers.
     */
    public function __invoke(PriceListRequest $request): AnonymousResourceCollection
    {
        $products = fn ($query) => $query->when($request->boolean('available'), fn ($query) => $query->available());

        $categories = Category::query()
            ->when($request->integer('category_id'), fn ($query, int $categoryId) => $query->whereKey($categoryId))
            ->whereHas('products', $products)
            ->with(['products' => fn ($query) => $products($query)->withStock()->orderBy('name')])
            ->orderBy('name')
            ->get();

        return PriceListCategoryResource::collection($categories);
    }
}
