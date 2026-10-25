<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTransferRequest;
use App\Http\Resources\AccountTransactionResource;
use App\Models\Account;
use App\Services\MoneyLedger;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class TransferController extends Controller
{
    /**
     * Move money between two accounts. Returns the outgoing and incoming rows.
     */
    public function __invoke(StoreTransferRequest $request, MoneyLedger $ledger): JsonResponse
    {
        $rows = DB::transaction(fn () => $ledger->recordTransfer(
            Account::findOrFail($request->validated('from_account_id')),
            Account::findOrFail($request->validated('to_account_id')),
            Money::toCents($request->validated('amount')),
            $request->validated('transaction_date') ?? now()->toDateString(),
            $request->validated('note'),
        ));

        return AccountTransactionResource::collection(collect($rows)->each->load('account'))
            ->response()
            ->setStatusCode(201);
    }
}
