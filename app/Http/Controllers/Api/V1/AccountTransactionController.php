<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexAccountTransactionRequest;
use App\Http\Requests\Api\V1\ReverseAccountTransactionRequest;
use App\Http\Resources\AccountTransactionResource;
use App\Models\AccountTransaction;
use App\Services\MoneyLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AccountTransactionController extends Controller
{
    /**
     * The money ledger, newest first.
     */
    public function index(IndexAccountTransactionRequest $request): AnonymousResourceCollection
    {
        $transactions = AccountTransaction::query()
            ->with(['account', 'reference', 'creator:id,name', 'reversal:id,reverses_transaction_id'])
            ->filter($request->validated())
            ->latest('transaction_date')
            ->latest('id')
            ->paginate($request->perPage());

        return AccountTransactionResource::collection($transactions);
    }

    /**
     * Cancel a wrong entry with a reversal (both sides of a transfer). Returns the reversal rows.
     */
    public function reverse(ReverseAccountTransactionRequest $request, AccountTransaction $accountTransaction, MoneyLedger $ledger): JsonResponse
    {
        $rows = DB::transaction(fn () => $ledger->reverse(
            $accountTransaction,
            $request->validated('reason'),
            $request->validated('transaction_date') ?? now()->toDateString(),
        ));

        return AccountTransactionResource::collection(collect($rows)->each->load('account'))
            ->response()
            ->setStatusCode(201);
    }
}
