<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CancelProformaRequest;
use App\Http\Requests\Api\V1\IndexProformaRequest;
use App\Http\Requests\Api\V1\PreviewProformaRequest;
use App\Http\Requests\Api\V1\StoreProformaRequest;
use App\Http\Requests\Api\V1\UpdateProformaRequest;
use App\Http\Resources\ProformaPreviewResource;
use App\Http\Resources\ProformaResource;
use App\Models\Proforma;
use App\Services\ProformaManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProformaController extends Controller
{
    public function __construct(private ProformaManager $proformas) {}

    public function index(IndexProformaRequest $request): AnonymousResourceCollection
    {
        $proformas = Proforma::query()
            ->with(['customer', 'sale:id,number', 'creator:id,name', 'canceller:id,name'])
            ->filter($request->validated())
            ->latest('proforma_date')
            ->latest('id')
            ->paginate($request->perPage());

        return ProformaResource::collection($proformas);
    }

    public function show(Proforma $proforma): ProformaResource
    {
        return new ProformaResource($this->loadDetails($proforma));
    }

    public function store(StoreProformaRequest $request): JsonResponse
    {
        $proforma = $this->proformas->create($request->validated());

        return (new ProformaResource($this->loadDetails($proforma)))->response()->setStatusCode(201);
    }

    /**
     * Price the items for the form without saving anything.
     */
    public function preview(PreviewProformaRequest $request): ProformaPreviewResource
    {
        return new ProformaPreviewResource($this->proformas->price($request->validated('items')));
    }

    public function update(UpdateProformaRequest $request, Proforma $proforma): ProformaResource
    {
        $proforma = $this->proformas->update($proforma, $request->validated());

        return new ProformaResource($this->loadDetails($proforma));
    }

    public function cancel(CancelProformaRequest $request, Proforma $proforma): ProformaResource
    {
        $proforma = $this->proformas->cancel($proforma, $request->validated('reason'));

        return new ProformaResource($this->loadDetails($proforma));
    }

    private function loadDetails(Proforma $proforma): Proforma
    {
        return $proforma->load(['customer', 'items.product', 'sale:id,number', 'creator:id,name', 'canceller:id,name']);
    }
}
