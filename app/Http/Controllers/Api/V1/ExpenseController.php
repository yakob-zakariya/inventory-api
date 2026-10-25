<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountTransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreExpenseRequest;
use App\Http\Resources\AccountTransactionResource;
use App\Models\Account;
use App\Services\MoneyLedger;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ExpenseController extends Controller
{
    /**
     * Record money spent from an account, with a reason.
     */
    public function __invoke(StoreExpenseRequest $request, MoneyLedger $ledger): JsonResponse
    {
        $transaction = DB::transaction(fn () => $ledger->record(
            Account::findOrFail($request->validated('account_id')),
            AccountTransactionType::Expense,
            -Money::toCents($request->validated('amount')),
            $request->validated('transaction_date') ?? now()->toDateString(),
            $request->validated('note'),
            receiptReference: $request->validated('receipt_reference'),
        ));

        return (new AccountTransactionResource($transaction->load('account')))->response()->setStatusCode(201);
    }
}
