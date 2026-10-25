<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexPurchaseRequest;
use App\Http\Requests\Api\V1\StorePurchaseRequest;
use App\Http\Resources\PurchaseResource;
use App\Models\Purchase;
use App\Services\PurchaseRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PurchaseController extends Controller
{
    public function index(IndexPurchaseRequest $request): AnonymousResourceCollection
    {
        $purchases = Purchase::query()
            ->with('supplier')
            ->withPaymentTotals()
            ->filter($request->validated())
            ->latest('purchase_date')
            ->latest('id')
            ->paginate();

        return PurchaseResource::collection($purchases);
    }

    public function store(StorePurchaseRequest $request, PurchaseRecorder $recorder): JsonResponse
    {
        $purchase = $recorder->record($request->validated());

        return (new PurchaseResource($this->loadDetails($purchase)))->response()->setStatusCode(201);
    }

    public function show(Purchase $purchase): PurchaseResource
    {
        return new PurchaseResource($this->loadDetails($purchase));
    }

    private function loadDetails(Purchase $purchase): Purchase
    {
        return $purchase
            ->load(['supplier', 'items.product', 'payments.account'])
            ->loadSum('payments as payments_sum', 'amount');
    }
}
