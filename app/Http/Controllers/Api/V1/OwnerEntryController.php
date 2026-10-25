<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountTransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreOwnerEntryRequest;
use App\Http\Resources\AccountTransactionResource;
use App\Models\Account;
use App\Services\MoneyLedger;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class OwnerEntryController extends Controller
{
    /**
     * Record the owner putting money into the business or taking it out.
     */
    public function __invoke(StoreOwnerEntryRequest $request, MoneyLedger $ledger): JsonResponse
    {
        $isDeposit = $request->validated('type') === 'deposit';
        $cents = Money::toCents($request->validated('amount'));

        $transaction = DB::transaction(fn () => $ledger->record(
            Account::findOrFail($request->validated('account_id')),
            $isDeposit ? AccountTransactionType::OwnerDeposit : AccountTransactionType::OwnerWithdrawal,
            $isDeposit ? $cents : -$cents,
            $request->validated('transaction_date') ?? now()->toDateString(),
            $request->validated('note'),
        ));

        return (new AccountTransactionResource($transaction->load('account')))->response()->setStatusCode(201);
    }
}
