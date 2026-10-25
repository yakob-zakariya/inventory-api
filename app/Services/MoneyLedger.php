<?php

namespace App\Services;

use App\Enums\AccountTransactionType;
use App\Exceptions\BusinessRuleException;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The only writer of the money ledger. Must run inside DB::transaction.
 */
class MoneyLedger
{
    /**
     * Append a transaction. The amount is in cents and must have the sign its type expects.
     *
     * @throws BusinessRuleException when the account is inactive
     */
    public function record(
        Account $account,
        AccountTransactionType $type,
        int $amountCents,
        Carbon|string $date,
        ?string $note = null,
        ?Model $reference = null,
        ?string $receiptReference = null,
    ): AccountTransaction {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Money changes must run inside a database transaction.');
        }

        if ($amountCents === 0) {
            throw new LogicException('An account transaction needs a non-zero amount.');
        }

        if ($type->sign() !== null && ($amountCents <=> 0) !== $type->sign()) {
            throw new LogicException("A {$type->value} amount must be ".($type->sign() > 0 ? 'positive.' : 'negative.'));
        }

        if ($type->requiresNote() && blank($note)) {
            throw new LogicException("A {$type->value} needs a reason.");
        }

        if (! $account->is_active) {
            throw new BusinessRuleException("The account \"{$account->name}\" is inactive.");
        }

        return AccountTransaction::create([
            'account_id' => $account->id,
            'type' => $type,
            'amount' => Money::fromCents($amountCents),
            'transaction_date' => $date,
            'note' => $note,
            'receipt_reference' => $receiptReference,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
        ]);
    }
}
