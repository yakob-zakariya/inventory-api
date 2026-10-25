<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexAccountTransactionRequest;
use App\Http\Resources\AccountTransactionResource;
use App\Models\AccountTransaction;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountTransactionController extends Controller
{
    /**
     * The money ledger, newest first.
     */
    public function index(IndexAccountTransactionRequest $request): AnonymousResourceCollection
    {
        $transactions = AccountTransaction::query()
            ->with('account')
            ->filter($request->validated())
            ->latest('transaction_date')
            ->latest('id')
            ->paginate();

        return AccountTransactionResource::collection($transactions);
    }
}
