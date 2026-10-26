<?php

namespace App\Services;

use App\Enums\AccountTransactionType;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * The only writer of the money ledger. Must run inside DB::transaction.
 *
 * Every write locks its account row, so anything that reads a balance (reconciliation)
 * sees no concurrent change. Accounts are always the last rows a transaction locks.
 */
class MoneyLedger
{
    /**
     * Lock accounts for the rest of the transaction, in id order to avoid deadlocks.
     *
     * @param  array<int, int>  $accountIds
     * @return Collection<int, Account> keyed by account id
     */
    public function lockAccounts(array $accountIds): Collection
    {
        $this->ensureInTransaction();

        return Account::query()
            ->whereKey(array_unique($accountIds))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

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
        ?string $transferGroup = null,
        ?AccountTransaction $reverses = null,
    ): AccountTransaction {
        $this->ensureInTransaction();

        if ($amountCents === 0) {
            throw new LogicException('An account transaction needs a non-zero amount.');
        }

        if ($type->sign() !== null && ($amountCents <=> 0) !== $type->sign()) {
            throw new LogicException("A {$type->value} amount must be ".($type->sign() > 0 ? 'positive.' : 'negative.'));
        }

        if ($type->requiresNote() && blank($note)) {
            throw new LogicException("A {$type->value} needs a reason.");
        }

        $account = $this->lockAccounts([$account->id])->first();

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
            'transfer_group' => $transferGroup,
            'reverses_transaction_id' => $reverses?->id,
        ]);
    }

    /**
     * Move money between two accounts: both rows are written together and share a transfer group.
     *
     * @return array{AccountTransaction, AccountTransaction} the outgoing and incoming rows
     *
     * @throws BusinessRuleException when an account is inactive
     */
    public function recordTransfer(Account $from, Account $to, int $amountCents, Carbon|string $date, ?string $note = null): array
    {
        if ($from->is($to)) {
            throw new LogicException('A transfer needs two different accounts.');
        }

        if ($amountCents <= 0) {
            throw new LogicException('A transfer amount must be positive.');
        }

        $this->lockAccounts([$from->id, $to->id]);
        $group = (string) Str::uuid();

        return [
            $this->record($from, AccountTransactionType::TransferOut, -$amountCents, $date, $note, transferGroup: $group),
            $this->record($to, AccountTransactionType::TransferIn, $amountCents, $date, $note, transferGroup: $group),
        ];
    }

    /**
     * Reconcile an account with a counted balance by recording the difference as an adjustment.
     *
     * @throws BusinessRuleException when the balance already matches or the account is inactive
     */
    public function reconcile(Account $account, int $countedCents, Carbon|string $date, string $note): AccountTransaction
    {
        $account = $this->lockAccounts([$account->id])->first();
        $difference = $countedCents - $account->balanceCents();

        if ($difference === 0) {
            throw new BusinessRuleException("The balance of \"{$account->name}\" already matches the counted amount.");
        }

        return $this->record($account, AccountTransactionType::Adjustment, $difference, $date, $note);
    }

    /**
     * Cancel a wrong entry with a reversal row of the opposite amount that points at it.
     * A transfer is reversed as a pair: both sides, under a new transfer group.
     * The reversal keeps the original's document, so the document's paid amount follows.
     *
     * @return list<AccountTransaction> the reversal rows
     *
     * @throws BusinessRuleException when the entry is itself a reversal or an account is inactive
     * @throws ConflictException when the entry was already reversed
     */
    public function reverse(AccountTransaction $original, string $reason, Carbon|string $date): array
    {
        $this->ensureInTransaction();

        if ($original->type === AccountTransactionType::Reversal) {
            throw new BusinessRuleException('A reversal cannot be reversed. Record the correct entry again instead.');
        }

        $originals = $original->transfer_group === null
            ? new Collection([$original])
            : AccountTransaction::query()->where('transfer_group', $original->transfer_group)->orderBy('id')->get();

        // Lock order: the document first (a void checks its payments under this lock), then the accounts.
        $document = $original->reference_type === null
            ? null
            : Relation::getMorphedModel($original->reference_type)::query()->lockForUpdate()->find($original->reference_id);

        $accounts = $this->lockAccounts($originals->pluck('account_id')->all());

        if (AccountTransaction::query()->whereIn('reverses_transaction_id', $originals->modelKeys())->exists()) {
            throw new ConflictException("Transaction {$original->id} is already reversed.");
        }

        $group = $original->transfer_group === null ? null : (string) Str::uuid();

        return $originals->map(fn (AccountTransaction $row) => $this->record(
            $accounts[$row->account_id],
            AccountTransactionType::Reversal,
            -Money::toCents($row->amount),
            $date,
            $reason,
            $document,
            transferGroup: $group,
            reverses: $row,
        ))->all();
    }

    private function ensureInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Money changes must run inside a database transaction.');
        }
    }
}
