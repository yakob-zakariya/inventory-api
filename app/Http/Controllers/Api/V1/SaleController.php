<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexSaleRequest;
use App\Http\Requests\Api\V1\StoreSaleRequest;
use App\Http\Requests\Api\V1\VoidDocumentRequest;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Services\DocumentVoider;
use App\Services\SaleRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SaleController extends Controller
{
    public function index(IndexSaleRequest $request): AnonymousResourceCollection
    {
        $sales = Sale::query()
            ->with('customer')
            ->withPaymentTotals()
            ->filter($request->validated())
            ->latest('sale_date')
            ->latest('id')
            ->paginate();

        return SaleResource::collection($sales);
    }

    public function store(StoreSaleRequest $request, SaleRecorder $recorder): JsonResponse
    {
        $sale = $recorder->record($request->validated());

        return (new SaleResource($this->loadDetails($sale)))->response()->setStatusCode(201);
    }

    public function show(Sale $sale): SaleResource
    {
        return new SaleResource($this->loadDetails($sale));
    }

    /**
     * Void the sale with a reason: its stock movements are reversed and it leaves all totals.
     */
    public function void(VoidDocumentRequest $request, Sale $sale, DocumentVoider $voider): SaleResource
    {
        $sale = $voider->void($sale, $request->validated('reason'));

        return new SaleResource($this->loadDetails($sale));
    }

    private function loadDetails(Sale $sale): Sale
    {
        return $sale
            ->load(['customer', 'items.product', 'payments.account'])
            ->loadSum('payments as payments_sum', 'amount');
    }
}
