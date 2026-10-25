<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\StockMovementType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreStockAdjustmentRequest;
use App\Http\Resources\StockMovementResource;
use App\Services\StockLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class StockAdjustmentController extends Controller
{
    /**
     * Record a signed stock adjustment with a reason (opening stock, damage, recount).
     */
    public function __invoke(StoreStockAdjustmentRequest $request, StockLedger $ledger): JsonResponse
    {
        $movement = DB::transaction(function () use ($request, $ledger) {
            $product = $ledger->lockProducts([$request->validated('product_id')])->first();

            return $ledger->record(
                $product,
                StockMovementType::Adjustment,
                (int) $request->validated('quantity'),
                $request->validated('note'),
            );
        });

        return (new StockMovementResource($movement->load('product')))
            ->response()
            ->setStatusCode(201);
    }
}
