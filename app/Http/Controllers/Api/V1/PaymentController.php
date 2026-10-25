<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePaymentRequest;
use App\Http\Resources\PurchaseResource;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Services\PaymentRecorder;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    /**
     * Record a payment against a purchase or sale and return the updated document.
     */
    public function __invoke(StorePaymentRequest $request, PaymentRecorder $recorder): JsonResponse
    {
        $document = $recorder->record($request->validated())
            ->load(['items.product', 'payments.account'])
            ->loadSum('payments as payments_sum', 'amount');

        $resource = $document instanceof Sale
            ? new SaleResource($document->load('customer'))
            : new PurchaseResource($document->load('supplier'));

        return $resource->response()->setStatusCode(201);
    }
}
