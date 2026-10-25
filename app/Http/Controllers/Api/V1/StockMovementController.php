<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexStockMovementRequest;
use App\Http\Resources\StockMovementResource;
use App\Models\StockMovement;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockMovementController extends Controller
{
    /**
     * The stock ledger, newest first.
     */
    public function index(IndexStockMovementRequest $request): AnonymousResourceCollection
    {
        $movements = StockMovement::query()
            ->with('product')
            ->when($request->validated('product_id'), fn ($query, $productId) => $query->where('product_id', $productId))
            ->when($request->validated('type'), fn ($query, $type) => $query->where('type', $type))
            ->latest('id')
            ->paginate();

        return StockMovementResource::collection($movements);
    }
}
