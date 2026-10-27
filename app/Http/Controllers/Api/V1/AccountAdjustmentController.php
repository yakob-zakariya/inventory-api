<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PreviewAccountAdjustmentRequest;
use App\Http\Requests\Api\V1\StoreAccountAdjustmentRequest;
use App\Http\Resources\AccountTransactionResource;
use App\Http\Resources\ReconciliationPreviewResource;
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

    /**
     * Show what a reconciliation would record, without saving: the current balance, the counted
     * balance and the signed difference (counted minus balance; "0.00" when they already match).
     */
    public function preview(PreviewAccountAdjustmentRequest $request): ReconciliationPreviewResource
    {
        $account = Account::query()->withBalance()->findOrFail($request->validated('account_id'));

        return new ReconciliationPreviewResource([
            'account' => $account,
            'balance_cents' => $account->balanceCents(),
            'counted_cents' => Money::toCents($request->validated('counted_balance')),
        ]);
    }
}
