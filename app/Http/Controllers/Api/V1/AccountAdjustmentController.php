<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAccountAdjustmentRequest;
use App\Http\Resources\AccountTransactionResource;
use App\Models\Account;
use App\Services\MoneyLedger;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AccountAdjustmentController extends Controller
{
    /**
     * Reconcile an account with the counted amount; the difference is recorded with a reason.
     */
    public function __invoke(StoreAccountAdjustmentRequest $request, MoneyLedger $ledger): JsonResponse
    {
        $transaction = DB::transaction(fn () => $ledger->reconcile(
            Account::findOrFail($request->validated('account_id')),
            Money::toCents($request->validated('counted_balance')),
            $request->validated('transaction_date') ?? now()->toDateString(),
            $request->validated('note'),
        ));

        return (new AccountTransactionResource($transaction->load('account')))->response()->setStatusCode(201);
    }
}
